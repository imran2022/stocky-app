<?php

/**
 * Regression gate — Public Invoice URL.
 *
 * Static/source checks only (no database), matching the existing
 * build_*.php convention. Does not verify the actual PDF renders or that
 * the link truly works unauthenticated on a live server — do that
 * manually (see the delivery's README "How to try it").
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "Public Invoice URL regression gate FAILED:\n - Missing file: {$relative}\n");
        exit(1);
    }

    return file_get_contents($path);
};

$errors = [];
$contains = function (string $haystack, string $needle, string $message) use (&$errors) {
    if (! str_contains($haystack, $needle)) {
        $errors[] = $message;
    }
};

$migration = $source('database/migrations/2026_09_18_000004_add_public_token_to_sales.php');
$service = $source('app/Services/Custom/PublicInvoiceLinkService.php');
$controller = $source('app/Http/Controllers/PublicInvoiceController.php');
$salesController = $source('app/Http/Controllers/SalesController.php');
$routes = $source('routes/api.php');
$page = $source('resources/src/pages/sales/SaleDetails.vue');
$invoicePage = $source('resources/src/pages/public/PublicInvoice.vue');
$router = $source('resources/src/router/index.js');

$contains($migration, "'public_token'", 'Migration must add the public_token column.');
$contains($migration, '->unique()', 'public_token must be unique — a collision would let one link open a different sale.');

$contains($service, 'class PublicInvoiceLinkService', 'Service must exist.');
$contains($service, 'public static function getOrCreateToken(', 'Service must expose getOrCreateToken().');
$contains($service, 'public static function regenerateToken(', 'Service must expose regenerateToken().');
$contains($service, 'saveQuietly()', 'Token generation must use saveQuietly() so it never appears as a spurious "Sale updated" Activity Log row.');
$contains($service, 'Sale::where(\'public_token\', $token)->exists()', 'Token generation must check for collisions before accepting a random token.');

$contains($controller, 'class PublicInvoiceController', 'Controller must exist.');
$contains($controller, "'view', Sale::class", 'link() must be permission-gated (authenticated staff action).');
$contains($controller, "'update', Sale::class", 'regenerate() must be permission-gated.');
$contains($controller, 'whereNotNull(\'public_token\')', 'Public pdf() lookup must require a non-null token — an empty-string match must not resolve to an arbitrary sale.');
$contains($controller, "app(SalesController::class)->Sale_PDF(", 'pdf() must delegate to the existing, already-tested Sale_PDF rather than reimplementing PDF generation.');
$contains($controller, "public function show(string \$token)", 'Controller must expose the public JSON data endpoint for the HTML page.');
$contains($controller, "'/next/invoice/'", "Copied link must point at the branded HTML page (SPA's /next/ base), not straight at the PDF.");
$contains($controller, "'/api/public/invoice/'", 'PDF url helper must include the /api prefix routes/api.php is registered under.');
$contains($controller, "'/pdf'", 'PDF url helper must include the /pdf suffix matching the actual route.');
$contains($controller, "'pdf_url' =>", "The page's JSON response must include the PDF download url.");

$contains($salesController, "if (\$request->boolean('inline'))", 'Sale_PDF must support the inline flag for the public link (view in browser) without changing default download behavior for existing callers.');

$contains($routes, "'PublicInvoiceController@link'", 'Authenticated link route must be registered.');
$contains($routes, "'PublicInvoiceController@regenerate'", 'Authenticated regenerate route must be registered.');
$contains($routes, "'PublicInvoiceController@show'", 'Public JSON data route must be registered.');
// Both the JSON and pdf routes must sit in the no-auth block, not inside
// an authenticated group.
if (preg_match('/Public minimal endpoints for customer display \(no auth\).*?public\/invoice\/\{token\}.*?public\/invoice\/\{token\}\/pdf/s', $routes) !== 1) {
    $errors[] = 'Both public/invoice/{token} and public/invoice/{token}/pdf must sit in the no-auth block (with the customer-display routes), not inside an authenticated group.';
}

$contains($page, 'copyPublicLink', 'Sale Detail page must have a copy-link action.');
$contains($page, 'regeneratePublicLink', 'Sale Detail page must have a regenerate action.');
$contains($page, "sales/\${sale.value.id}/public-link", 'Page must call the correct link endpoint.');

// --- The branded HTML page itself ---
$contains($invoicePage, 'http.get(`public/invoice/${route.params.token}`)', 'Page must fetch its data from the public JSON endpoint.');
$contains($invoicePage, ':href="data.pdf_url"', 'Page must have a Download PDF button pointing at the server-provided pdf_url.');
if (str_contains($invoicePage, '$t(')) {
    $errors[] = 'PublicInvoice.vue must use plain English text, not $t() translation keys (this page has no admin session to ever have loaded translations, and per the standing "always human style" preference).';
}
$contains($invoicePage, '@media (max-width', 'Page must have a mobile-responsive breakpoint, not a fixed desktop-only layout.');
$contains($invoicePage, '@media print', 'Page must hide the download button when printed (a person printing already has the content in front of them).');

$contains($router, 'PublicInvoice', 'Router must register the page.');
$contains($router, "path: '/invoice/:token'", 'Route path must match what the backend generates.');
$contains($router, 'skipAuth: true', 'Route must skip the auth guard — checked broadly since /ping already uses it too; the important thing is this new route entry itself also carries it (see the exact line above).');

if (! empty($errors)) {
    fwrite(STDERR, "Public Invoice URL regression gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "Public Invoice URL regression gate passed.\n";
