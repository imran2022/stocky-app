#!/usr/bin/env node
/**
 * Build Q1 — Sale Form header Payment Terms + previous-due indicator.
 *
 * Protects the requested UI contract without duplicating financial logic:
 * - when enabled, Payment Term and Due Date live in the top metadata card;
 * - both fields remain gated by the existing enable_payment_terms switch;
 * - when disabled, Date/Customer/Warehouse return to the three-column row;
 * - the customer brief's canonical netBalance is shown only when positive;
 * - Due Date preview uses the actual system default returned by the backend,
 *   not a hard-coded seven-day fallback;
 * - the old lower Payment Term/Due Date controls are not duplicated.
 *
 * Run: node tests/Regression/build_q1_sale_form_payment_terms_header.cjs
 */

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const saleForm = fs.readFileSync(path.join(root, 'resources/src/pages/sales/SaleForm.vue'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/SalesController.php'), 'utf8');
const failures = [];
const assert = (condition, message) => { if (!condition) failures.push(message); };

const topCardEnd = saleForm.indexOf('<!-- Product search + lines -->');
const topCard = saleForm.slice(0, topCardEnd);

assert(topCardEnd > 0, 'Could not isolate the Sale Form top metadata card.');
assert(
  topCard.includes('<a-form-item label="Payment Term">') && topCard.includes('<a-form-item label="Due Date">'),
  'Payment Term and Due Date must both live in the top metadata card.'
);
assert(
  topCard.indexOf('label="Payment Term"') < topCard.indexOf("$t('warehouse')"),
  'Payment Term must appear before Warehouse in the desktop metadata row.'
);
assert(
  topCard.indexOf('label="Due Date"') < topCard.indexOf("$t('warehouse')"),
  'Due Date must appear before Warehouse in the desktop metadata row.'
);
assert(
  (saleForm.match(/<a-form-item label="Payment Term">/g) || []).length === 1 &&
    (saleForm.match(/<a-form-item label="Due Date">/g) || []).length === 1,
  'Payment Term and Due Date must each render from one place only (no old lower-form duplicates).'
);
assert(
  (topCard.match(/v-if="enablePaymentTerms"/g) || []).length >= 2,
  'Payment Term and Due Date must both be hidden by the existing feature toggle.'
);
assert(
  topCard.includes(':xl="enablePaymentTerms ? 4 : 8"') &&
    topCard.includes(':xl="enablePaymentTerms ? 6 : 8"') &&
    topCard.includes(':xl="enablePaymentTerms ? 5 : 8"'),
  'Desktop columns must collapse back to the original three equal fields when Payment Terms is off.'
);
assert(
  saleForm.includes('v-if="previousCustomerDue > 0"') &&
    saleForm.includes("$t('Previous_Dues')") &&
    saleForm.includes('moneyBase(previousCustomerDue)'),
  'Previous Dues must be a positive-only customer badge formatted in base currency.'
);
assert(
  saleForm.includes('Math.max(0, Number(selectedClientNetBalance.value) || 0)'),
  'Previous Dues must reuse the canonical client brief netBalance and suppress credits/zero balances.'
);
assert(
  saleForm.includes('Number(systemDefaultPaymentTermDays.value)') &&
    saleForm.includes('default_payment_term_days ?? 7'),
  'Due Date preview must consume the system default returned by the Sales bootstrap endpoint.'
);
assert(
  (controller.match(/'default_payment_term_days'\s*=>/g) || []).length >= 2 &&
    controller.includes('PaymentTerms::FALLBACK_SYSTEM_DEFAULT_DAYS'),
  'Sales create/edit bootstrap payloads must expose the configured default payment-term days with the canonical fallback.'
);
assert(
  (topCard.match(/<a-select-option value="7">7 Days<\/a-select-option>/g) || []).length === 1,
  'The Payment Term preset list must contain exactly one 7 Days option.'
);

if (failures.length) {
  console.error('Build Q1 regression FAILED:');
  failures.forEach(failure => console.error(` - ${failure}`));
  process.exit(1);
}

console.log('Build Q1 regression: PASS (responsive top-row Payment Term/Due Date, toggle collapse, accurate system-default preview, and positive customer Previous Dues badge).');
