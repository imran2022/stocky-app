'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const saleForm = fs.readFileSync(path.join(root, 'resources/src/pages/sales/SaleForm.vue'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/SalesController.php'), 'utf8');

assert.match(saleForm, /:md="enablePaymentTerms \? 4 : 8"/);
assert.match(saleForm, /Previous Dues: \{\{ moneyBase\(selectedClientNetBalance\) \}\}/);
assert.match(saleForm, /v-if="selectedClientNetBalance > 0"/);
assert.match(saleForm, /systemDefaultPaymentTermDays = ref\(7\)/);
assert.match(saleForm, /System default \(\$\{paymentTermLabel\(systemDefaultPaymentTermDays\)\}\)/);
assert.match(saleForm, /Number\(systemDefaultPaymentTermDays\.value\)/);

const topPaymentTerm = saleForm.indexOf('<a-col v-if="enablePaymentTerms" :xs="24" :md="5">');
const productSearch = saleForm.indexOf('<!-- Product search + lines -->');
assert.ok(topPaymentTerm > 0 && topPaymentTerm < productSearch, 'Payment Term must be in the top header card.');

const dueDateOccurrences = (saleForm.match(/<a-form-item label="Due Date">/g) || []).length;
assert.equal(dueDateOccurrences, 1, 'Due Date must not be duplicated lower in the form.');

assert.match(controller, /'default_payment_term_days'\s*=>\s*max\(0, \(int\)/);

console.log('Build Q1 regression: PASS (compact conditional header, accurate system default and positive previous-due badge).');
