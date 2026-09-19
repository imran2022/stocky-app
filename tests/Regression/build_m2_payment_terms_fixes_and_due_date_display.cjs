#!/usr/bin/env node
/**
 * Build M2 — Payment Terms fixes + on/off toggle + Due Date display
 * (2026-09-19).
 *
 * The user reported, after Build M1 shipped:
 *   1. On Settings > Features, clicking "Custom" for the Default Payment
 *      Term did nothing visible — the custom-days input never appeared.
 *      Confirmed working on the Customer form's own Payment Term field.
 *   2. Because of (1), a per-invoice custom due date couldn't be set when
 *      creating a sale either.
 *   3. Asked whether the whole feature can be switched on/off.
 *   4. Asked for a Due Date column on the Sales list (kept hidden by
 *      default is fine) and Sale Detail page, and a toggle for showing
 *      Due Date on the invoice PDF (like the existing "Previous Dues"
 *      toggle).
 *
 * Root cause of (1)/(2): the three "switch to Custom" handlers
 * (SystemSettings.vue, CustomerForm.vue, SaleForm.vue) reused the
 * CURRENTLY STORED value as the starting point for the custom input. When
 * that stored value already happened to be one of the presets (0, 7, 15,
 * or 30 — which is the common case, e.g. a fresh install's default of 7,
 * or "Immediate" = 0), the getter immediately re-classified it back to
 * that preset instead of "custom", so the radio silently snapped back and
 * the custom input never rendered. CustomerForm.vue and SaleForm.vue
 * happened to avoid the WORST case (an initial null becoming 45 via
 * `value || 45`) only because 0 is falsy in JS — but they had the exact
 * same latent bug for 7/15/30. All three are now fixed the same way:
 * never reuse a value that IS one of the presets; fall back to 45.
 *
 * This is a real Node test — it extracts the actual setter function
 * bodies from the three .vue files with a regex and executes them with a
 * minimal Vue-less harness (a plain object standing in for the Vue ref),
 * so it exercises the REAL shipped code, not a re-implementation of it.
 *
 * Run with: node tests/Regression/build_m2_payment_terms_fixes_and_due_date_display.js
 */

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const failures = [];
const assert = (cond, msg) => { if (!cond) failures.push(msg); };

/**
 * Pulls out a `computed({ get() {...}, set(val) {...} })` block that
 * immediately follows a `const NAME = computed({` declaration, and
 * evaluates the `set` function body in a sandbox where `PAYMENT_TERM_PRESETS`
 * and a mutable "state" object (standing in for the ref/reactive value the
 * real code closes over) are available.
 */
function extractSetter(fileContent, constName, stateAccessorRegex) {
  const declIdx = fileContent.indexOf(`const ${constName} = computed({`);
  if (declIdx === -1) throw new Error(`Could not find "const ${constName} = computed({" `);
  const setIdx = fileContent.indexOf('set(val) {', declIdx);
  if (setIdx === -1) throw new Error(`Could not find a set(val) block for ${constName}`);
  // Find the matching closing brace for this function body by brace counting.
  let i = fileContent.indexOf('{', setIdx);
  let depth = 0;
  let end = -1;
  for (; i < fileContent.length; i++) {
    if (fileContent[i] === '{') depth++;
    if (fileContent[i] === '}') {
      depth--;
      if (depth === 0) { end = i; break; }
    }
  }
  if (end === -1) throw new Error(`Could not find end of set(val) body for ${constName}`);
  const body = fileContent.slice(fileContent.indexOf('{', setIdx) + 1, end);
  if (!stateAccessorRegex.test(body)) {
    throw new Error(`Extracted setter for ${constName} doesn't reference the expected state path — extraction likely picked up the wrong block.`);
  }
  return body;
}

function runSystemSettingsCase(startValue, clickCustom) {
  const src = fs.readFileSync(path.join(root, 'resources/src/pages/settings/SystemSettings.vue'), 'utf8');
  const body = extractSetter(src, 'paymentTermPreset', /setting\.value\.default_payment_term_days/);
  const PAYMENT_TERM_PRESETS = [0, 7, 15, 30];
  const setting = { value: { default_payment_term_days: startValue } };
  const paymentTermCustom = { value: null };
  // eslint-disable-next-line no-new-func
  const fn = new Function('val', 'PAYMENT_TERM_PRESETS', 'setting', 'paymentTermCustom', body);
  fn(clickCustom ? 'custom' : String(startValue), PAYMENT_TERM_PRESETS, setting, paymentTermCustom);
  const resultValue = setting.value.default_payment_term_days;
  const resultIsCustom = !PAYMENT_TERM_PRESETS.includes(Number(resultValue));
  return { resultValue, resultIsCustom };
}

function runSimpleFormCase(fileRel, constDecl, statePathRegex, valueSetExpr, startValue) {
  const src = fs.readFileSync(path.join(root, fileRel), 'utf8');
  const body = extractSetter(src, constDecl, statePathRegex);
  const PAYMENT_TERM_PRESETS = [0, 7, 15, 30];
  const state = { value: { payment_term_days: startValue } };
  // eslint-disable-next-line no-new-func
  const fn = new Function('val', 'PAYMENT_TERM_PRESETS', valueSetExpr, body);
  fn('custom', PAYMENT_TERM_PRESETS, state);
  const resultValue = state.value.payment_term_days;
  const resultIsCustom = resultValue !== null && !PAYMENT_TERM_PRESETS.includes(Number(resultValue));
  return { resultValue, resultIsCustom };
}

// ==================== 1. SystemSettings.vue: Custom from every preset ====================
for (const start of [0, 7, 15, 30]) {
  const { resultIsCustom, resultValue } = runSystemSettingsCase(start, true);
  assert(
    resultIsCustom,
    `SystemSettings.vue: clicking "Custom" while the system default was ${start} must land on a real custom value (not silently snap back to a preset). Got ${resultValue}.`
  );
}
// Also: clicking custom from an already-custom value (e.g. 45) should keep it.
{
  const { resultValue } = runSystemSettingsCase(45, true);
  assert(resultValue === 45, `SystemSettings.vue: clicking "Custom" while already at a custom value (45) should keep it, got ${resultValue}.`);
}

// ==================== 2. CustomerForm.vue: same fix ====================
for (const start of [0, 7, 15, 30]) {
  const src = fs.readFileSync(path.join(root, 'resources/src/pages/people/CustomerForm.vue'), 'utf8');
  const body = extractSetter(src, 'paymentTermPreset', /form\.value\.payment_term_days/);
  const PAYMENT_TERM_PRESETS = [0, 7, 15, 30];
  const form = { value: { payment_term_days: start } };
  // eslint-disable-next-line no-new-func
  const fn = new Function('val', 'PAYMENT_TERM_PRESETS', 'form', body);
  fn('custom', PAYMENT_TERM_PRESETS, form);
  const resultValue = form.value.payment_term_days;
  const resultIsCustom = !PAYMENT_TERM_PRESETS.includes(Number(resultValue));
  assert(resultIsCustom, `CustomerForm.vue: clicking "Custom" while the customer's term was ${start} must land on a real custom value, got ${resultValue}.`);
}

// ==================== 3. SaleForm.vue: same fix ====================
for (const start of [0, 7, 15, 30]) {
  const src = fs.readFileSync(path.join(root, 'resources/src/pages/sales/SaleForm.vue'), 'utf8');
  const body = extractSetter(src, 'paymentTermPreset', /sale\.value\.payment_term_days/);
  const PAYMENT_TERM_PRESETS = [0, 7, 15, 30];
  const sale = { value: { payment_term_days: start } };
  // eslint-disable-next-line no-new-func
  const fn = new Function('val', 'PAYMENT_TERM_PRESETS', 'sale', body);
  fn('custom', PAYMENT_TERM_PRESETS, sale);
  const resultValue = sale.value.payment_term_days;
  const resultIsCustom = !PAYMENT_TERM_PRESETS.includes(Number(resultValue));
  assert(resultIsCustom, `SaleForm.vue: clicking "Custom" while the invoice's term was ${start} must land on a real custom value, got ${resultValue}.`);
}

// ==================== 4. Feature toggle + Due Date UI wiring (static) ====================
const checks = [
  {
    file: 'resources/src/pages/settings/SystemSettings.vue',
    needle: 'Enable Payment Terms',
    msg: 'SystemSettings.vue must have the master on/off switch for Payment Terms & Due Dates.',
  },
  {
    file: 'resources/src/pages/settings/SystemSettings.vue',
    needle: "fd.append('enable_payment_terms'",
    msg: 'SystemSettings.vue must submit enable_payment_terms on save.',
  },
  {
    file: 'app/Models/Setting.php',
    needle: "'enable_payment_terms'",
    msg: 'Setting model must have enable_payment_terms in fillable/casts.',
  },
  {
    file: 'app/Http/Controllers/SettingsController.php',
    needle: "'enable_payment_terms'",
    msg: 'SettingsController must read/write enable_payment_terms.',
  },
  {
    file: 'app/Http/Controllers/SalesController.php',
    needle: 'enable_payment_terms ?? true',
    msg: 'SalesController must gate term resolution on the enable_payment_terms toggle.',
  },
  {
    file: 'resources/src/pages/sales/Sales.vue',
    needle: "key: 'due_date', align: 'center', defaultHidden: true",
    msg: 'Sales.vue must have a Due Date column that starts hidden (user asked to keep it off the list by default).',
  },
  {
    file: 'resources/src/pages/sales/SaleDetails.vue',
    needle: 'enablePaymentTerms && sale.due_date',
    msg: 'SaleDetails.vue must show Due Date gated on the feature toggle.',
  },
  {
    file: 'app/Models/PdfTemplate.php',
    needle: "'show_due_date' => true",
    msg: 'PdfTemplate::DEFAULTS must have a show_due_date toggle (mirrors show_previous_dues).',
  },
  {
    file: 'resources/src/pages/settings/InvoicePdfSettings.vue',
    needle: "key: 'show_due_date'",
    msg: 'InvoicePdfSettings.vue must expose the Due Date line toggle in the Sections panel.',
  },
  {
    file: 'resources/views/pdf/sale_pdf.blade.php',
    needle: "!empty(\$sale['due_date']) && !empty(\$pdfT['show_due_date'])",
    msg: 'sale_pdf.blade.php (Classic) must render the Due Date row gated on both the sale having one and the toggle.',
  },
  {
    file: 'resources/views/pdf/sale_pdf_modern.blade.php',
    needle: "!empty(\$sale['due_date']) && !empty(\$pdfT['show_due_date'])",
    msg: 'sale_pdf_modern.blade.php (Modern) must render the Due Date row gated the same way.',
  },
];
for (const c of checks) {
  const content = fs.readFileSync(path.join(root, c.file), 'utf8');
  assert(content.includes(c.needle), c.msg);
}

// ==================== Report ====================
if (failures.length) {
  console.error('Build M2 regression FAILED:');
  for (const f of failures) console.error(' - ' + f);
  process.exit(1);
}
console.log('Build M2 regression: PASS (Custom-preset bug fixed on all 3 forms — verified by executing the real shipped setter code — plus the on/off toggle and Due Date UI wiring all present).');
