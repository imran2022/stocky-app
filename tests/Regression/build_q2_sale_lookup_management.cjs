'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const routes = read('routes/api.php');
const controller = read('app/Http/Controllers/SaleMetaController.php');
const service = read('app/Services/SaleLookupService.php');
const page = read('resources/src/pages/sales/SaleLookupManager.vue');
const router = read('resources/src/router/index.js');
const menu = read('resources/src/config/menu.js');
const saleForm = read('resources/src/pages/sales/SaleForm.vue');
const salesController = read('app/Http/Controllers/SalesController.php');
const zoneModel = read('app/Models/SaleZone.php');
const courierModel = read('app/Models/SaleCourier.php');
const sw = read('public/sw.js');

// CRUD contracts: inline create remains intact; management adds list + update.
for (const contract of [
  "Route::get('sale_zones', 'SaleMetaController@zones')",
  "Route::post('sale_zones', 'SaleMetaController@storeZone')",
  "Route::put('sale_zones/{zone}', 'SaleMetaController@updateZone')",
  "Route::get('sale_couriers', 'SaleMetaController@couriers')",
  "Route::post('sale_couriers', 'SaleMetaController@storeCourier')",
  "Route::put('sale_couriers/{courier}', 'SaleMetaController@updateCourier')",
]) assert.ok(routes.includes(contract), `Missing API route: ${contract}`);

assert.match(controller, /__construct\(SaleLookupService \$lookups\)/);
assert.match(controller, /authorizeUpdateAccess/);
assert.match(controller, /can\('update', Sale::class\)/);

// One service owns normalization/duplicates/restores for both entry points.
assert.match(service, /preg_replace\('\/\\s\+\/u', ' ', trim\(\$name\)\)/);
assert.match(service, /withTrashed\(\)/);
assert.match(service, /LOWER\(TRIM\(name\)\) = LOWER\(\?\)/);
assert.match(service, /if \(\$lookup->trashed\(\)\)/);
assert.match(service, /Another active or archived record already uses this name/);
assert.match(service, /\$allowedSorts = \['id', 'name', 'sales_count', 'created_at', 'updated_at'\]/);
assert.match(zoneModel, /whereNull\('sales\.deleted_at'\)/);
assert.match(courierModel, /whereNull\('sales\.deleted_at'\)/);

// Responsive shared UI is exposed as two Sales routes/menu entries.
assert.match(router, /path: 'sales\/zones'.*lookupType: 'zone'/);
assert.match(router, /path: 'sales\/couriers'.*lookupType: 'courier'/);
assert.ok(menu.includes("to: '/app/sales/zones'"));
assert.ok(menu.includes("to: '/app/sales/couriers'"));
assert.match(page, /<DataTable :crud="crud" :columns="columns">/);
assert.match(page, /Sales Used/);
assert.match(page, /http\.put\(`\$\{endpoint\.value\}\/\$\{editId\.value\}`/);

// Create Sale client lookup receives and indexes all requested identifiers.
const contactProjectionCount = (salesController.match(/get\(\['id', 'name', 'phone', 'email', 'code'\]\)/g) || []).length;
assert.ok(contactProjectionCount >= 3, 'Create, edit and quotation-convert payloads must include searchable customer contact fields.');
assert.match(saleForm, /:filter-option="filterClient"/);
assert.match(saleForm, /Search customer by name, phone or email/);
assert.match(saleForm, /\[c\.name, c\.phone, c\.email, c\.code\]/);

const filterMatch = saleForm.match(/function filterClient\(input, option\) \{([\s\S]*?)\n\}/);
assert.ok(filterMatch, 'filterClient implementation not found.');
const filterClient = new Function('input', 'option', filterMatch[1]);
const option = { label: 'Imran Hossain', searchText: 'imran hossain 01710000000 imran@example.com 1042' };
assert.equal(filterClient('01710', option), true);
assert.equal(filterClient('IMRAN@EXAMPLE.COM', option), true);
assert.equal(filterClient('1042', option), true);
assert.equal(filterClient('not-present', option), false);

// This task does not touch POS; its previously reconciled cache version stays.
assert.match(sw, /stocky-pwa-v14/);

console.log('Build Q2 regression: PASS (Zone/Area + Courier management, shared safe lookup logic, and customer contact search).');
