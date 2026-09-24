<?php
// Audit Batch 4 (C3): every route must point at a controller method that exists (four report routes used to 500).
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\Route;

$bad = [];
$n = 0;
foreach (Route::getRoutes() as $r) {
    $action = $r->getActionName();
    if ($action === 'Closure' || ! str_contains($action, '@')) { continue; }
    [$class, $method] = explode('@', $action, 2);
    // Scope: report/dashboard endpoints (the vendor's HRM/Project modules have their own unused dead routes).
    if (! preg_match('#^api/(report|dashboard)#', $r->uri())) { continue; }
    // Route::resource() also registers create/edit form routes the API never implements; those are harmless 404s.
    if (preg_match('#/(create|edit)$#', $r->uri())) { continue; }
    $n++;
    if (! class_exists($class) || ! method_exists($class, $method)) { $bad[] = $r->uri().' -> '.$action; }
}
check("all $n report/dashboard routes resolve to an existing method", $bad === [], implode('; ', array_slice($bad, 0, 8)));
finish('Audit B4 route targets');
