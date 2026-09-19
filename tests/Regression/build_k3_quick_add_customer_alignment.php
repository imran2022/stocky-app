<?php

/**
 * Build K.3 no-dependency regression gate.
 *
 * Pure CSS/layout refinement of the POS "Quick Add Customer" modal
 * (PosPage.vue, the .qac-* block) — alignment and spacing only, no
 * color, font, or JS logic changes.
 *
 * Run with: php tests/Regression/build_k3_quick_add_customer_alignment.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message);
};
$notContains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(! str_contains($haystack, $needle), $message);
};

$root = dirname(__DIR__, 2);
$body = file_get_contents($root.'/resources/src/pages/pos/PosPage.vue');

// --- Essentials (Customer Name + Phone) now a real 2-column grid, not a stacked column ---
$contains(
    $body,
    ".qac-essentials {\n  display: grid;\n  grid-template-columns: repeat(2, minmax(0, 1fr));",
    'Quick Add Customer essentials row must be a 2-column grid (Name + Phone side by side).'
);
$notContains(
    $body,
    ".qac-essentials {\n  display: flex;\n  flex-direction: column;",
    'Essentials must no longer be a single stacked column.'
);
$contains(
    $body,
    '.qac-essentials { grid-template-columns: 1fr; }',
    'Essentials must collapse to a single column on phone-size screens.'
);

// --- Footer buttons: real gap + minimum width so they never crowd together ---
$contains($body, 'gap: 12px;', 'Footer button gap must be widened for clear separation.');
$contains($body, 'min-width: 110px;', 'Footer buttons must have a minimum width so Cancel/Save never look cramped.');
$contains($body, '.qac-btn { flex: 1 1 auto; min-width: 0; }', 'On phones, both footer buttons must share width evenly (not just the primary one).');

// --- Close button: consistent offset with matching header clearance ---
$contains($body, 'padding: 22px 64px 20px 24px;', 'Header right padding must clear the repositioned close button.');
$contains($body, 'top: 16px;', 'Close button must sit at the refined offset from the header edge.');

// --- No color values were touched (spot-check the brand gradient stops) ---
$contains($body, 'linear-gradient(135deg, #be185d 0%, #f43f5e 50%, #fb7185 100%);', 'Header gradient colors must be unchanged.');
$contains($body, 'background: linear-gradient(135deg, #f43f5e 0%, #be185d 100%);', 'Primary button gradient colors must be unchanged.');

// --- Submit logic untouched ---
$contains($body, 'Submit_Quick_Add_Customer() {', 'Submit handler name/logic must be untouched.');

// --- PWA cache version must be bumped, or the POS app-shell will keep
// serving the pre-fix bundle to already-installed clients ---
$sw = file_get_contents($root.'/public/sw.js');
$versionOk = false;
if (preg_match("/const VERSION = 'stocky-pwa-v(\d+)';/", $sw, $m)) {
    $versionOk = ((int) $m[1]) >= 13;
}
$assert(
    $versionOk,
    'public/sw.js VERSION must be bumped to v13 or later so the POS app-shell cache picks up this build.'
);

if ($failures) {
    fwrite(STDERR, "Build K.3 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build K.3 regression gate: PASS (Quick Add Customer modal realigned; colors and logic untouched).\n";
