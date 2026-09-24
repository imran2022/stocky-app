<?php
// Audit Batch 4: report endpoints that used to crash on ordinary requests must answer 200.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\DB;

const CT_R = App\Http\Controllers\ReportController::class;
$r = fn ($m, $q = []) => call(CT_R, $m, $q + ['from' => '2020-01-01', 'to' => date('Y-m-d'), 'limit' => 10, 'page' => 1], 'GET');
[$c, $b] = $r('warrantyGuaranteeReport');
check('warranty report without SortType answers 200', $c == 200, "$c ".substr($b, 0, 150));
[$c, $b] = $r('warrantyGuaranteeReport', ['SortType' => 'asc', 'SortField' => 'Ref']);
check('warranty report with asc answers 200', $c == 200, "$c ".substr($b, 0, 150));
finish('Audit B4 report smoke');
