const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const guard = fs.readFileSync(path.join(root, 'app/Support/SaleTotalsGuard.php'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/SalesController.php'), 'utf8');

const failures = [];
const expect = (condition, message) => { if (!condition) failures.push(message); };

expect(
  guard.includes('$afterDiscount + $orderTax + $shipping'),
  'Grand-total validation must include order tax.'
);
expect(
  guard.includes('$remaining - $pointsDiscount'),
  'Grand-total validation must include the loyalty-points discount.'
);

const calls = controller.match(/SaleTotalsGuard::checkGrandTotal\([\s\S]*?\);/g) || [];
expect(calls.length === 2, 'Both sale create and update must validate the grand total.');
for (const [index, call] of calls.entries()) {
  expect(call.includes('$request->TaxNet ?? 0'), `Grand-total call ${index + 1} must pass order tax.`);
  expect(call.includes('$request->discount_from_points ?? 0'), `Grand-total call ${index + 1} must pass points discount.`);
}

if (failures.length) {
  console.error(`Q3 sale totals regression failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Q3 sale totals regression passed.');
