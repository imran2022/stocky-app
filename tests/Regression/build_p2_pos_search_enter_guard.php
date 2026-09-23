<?php

/**
 * Build P2 — POS product search: Enter must never add a STALE suggestion
 * (2026-09-24). Static source gate, same convention as the other build_*.php.
 *
 * Bug: after the keyboard-navigation change, Enter picked product_filter[0]
 * whenever a suggestion list was on screen, even when the cashier had typed or
 * scanned since that list was built (the list refreshes on an 800ms debounce).
 * A fast typist / barcode scanner could therefore add the wrong product.
 *
 * Fix: only a list that is "fresh" (productSearchActiveIndex >= 0, set when
 * results arrive or the cashier arrows through them; reset to -1 on every
 * keystroke) may be selected with Enter; otherwise the current input is
 * searched immediately.
 *
 * Also asserts the POS service worker cache version was bumped past v13
 * (PosPage.vue changed, and the POS is an installable PWA app shell).
 *
 * Run with: php tests/Regression/build_p2_pos_search_enter_guard.php
 */

$root = dirname(__DIR__, 2);
$errors = [];
$read = function (string $rel) use ($root, &$errors): string {
    $path = $root.'/'.$rel;
    if (! is_file($path)) {
        $errors[] = "Missing file: {$rel}";

        return '';
    }

    return (string) file_get_contents($path);
};

$pos = $read('resources/src/pages/pos/PosPage.vue');
$sw = $read('public/sw.js');

if (preg_match('/selectHighlightedProduct\(\)\s*\{(.*?)\n    \},/s', $pos, $m)) {
    $body = $m[1];
    if (! preg_match('/product_filter\.length\s*&&\s*this\.productSearchActiveIndex\s*>=\s*0/', $body)) {
        $errors[] = 'selectHighlightedProduct() must only select from the list when productSearchActiveIndex >= 0 (fresh list).';
    }
    if (preg_match('/productSearchActiveIndex\s*>=\s*0\s*\?\s*this\.productSearchActiveIndex\s*:\s*0/', $body)) {
        $errors[] = 'selectHighlightedProduct() must not fall back to index 0 of a stale list.';
    }
    if (! str_contains($body, 'this.search(true)')) {
        $errors[] = 'selectHighlightedProduct() must fall back to an immediate search(true) for stale/empty lists.';
    }
} else {
    $errors[] = 'Could not find selectHighlightedProduct() in PosPage.vue.';
}

if (! str_contains($pos, 'this.productSearchActiveIndex = -1;')
    || ! preg_match('/onProductSearchInput\(event\)\s*\{[^}]*this\.productSearchActiveIndex\s*=\s*-1/s', $pos)) {
    $errors[] = 'onProductSearchInput() must reset productSearchActiveIndex to -1 on every keystroke.';
}

if (preg_match("/const VERSION = 'stocky-pwa-v(\d+)'/", $sw, $v)) {
    if ((int) $v[1] < 14) {
        $errors[] = "public/sw.js VERSION must be >= stocky-pwa-v14 (found v{$v[1]}).";
    }
} else {
    $errors[] = 'Could not read the service worker VERSION.';
}

if ($errors !== []) {
    fwrite(STDERR, "Build P2 regression gate FAILED:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, " - {$e}\n");
    }
    exit(1);
}

echo "Build P2 regression gate: PASS (Enter can only select a fresh suggestion list; stale input is searched immediately; POS service worker version bumped).\n";
