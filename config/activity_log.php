<?php

/**
 * Build I1 — Activity Log config.
 *
 * Mirrors AccountingV2ServiceProvider's own enable/disable convention
 * (config/accounting_v2.php) — set to false to stop new log rows being
 * written without removing any code, e.g. while diagnosing an unrelated
 * issue and wanting one less moving part.
 */
return [
    'enabled' => env('ACTIVITY_LOG_ENABLED', true),
];
