<template>
  <div class="invoice-page">
    <div v-if="loading" class="state-screen">
      <a-spin size="large" />
    </div>

    <div v-else-if="error" class="state-screen">
      <div class="state-card">
        <ExclamationCircleOutlined class="state-icon" />
        <h1>This invoice link isn't valid</h1>
        <p>It may have been regenerated, or the link was mistyped. Contact the sender for a current link.</p>
      </div>
    </div>

    <div v-else class="sheet">
      <header class="sheet-header">
        <div class="brand">
          <img v-if="data.company.logo_url" :src="data.company.logo_url" alt="" class="brand-logo" />
          <div class="brand-text">
            <div class="brand-name">{{ data.company.name || 'Invoice' }}</div>
            <div class="brand-detail" v-if="data.company.address">{{ data.company.address }}</div>
            <div class="brand-detail" v-if="data.company.vat_number">VAT/BIN: {{ data.company.vat_number }}</div>
            <div class="brand-detail" v-if="data.company.phone">Phone: {{ data.company.phone }}</div>
            <div class="brand-detail" v-if="data.company.email">Mail: {{ data.company.email }}</div>
            <div class="brand-detail" v-if="data.company.website">Website: {{ data.company.website }}</div>
          </div>
        </div>
        <div class="invoice-meta">
          <div class="invoice-label">Invoice</div>
          <div class="invoice-ref">{{ data.invoice.ref }}</div>
          <div class="invoice-date">{{ formatDate(data.invoice.date) }}</div>
          <div class="invoice-date" v-if="data.invoice.warehouse">{{ data.invoice.warehouse }}</div>
          <span class="status-pill" :class="statusClass">{{ statusLabel }}</span>
        </div>
      </header>

      <section class="bill-to">
        <div class="bill-to-label">Billed to</div>
        <div class="bill-to-name">{{ data.client.name }}</div>
        <div class="bill-to-detail" v-if="data.client.address">{{ data.client.address }}</div>
        <div class="bill-to-detail" v-if="data.client.phone">{{ data.client.phone }}</div>
        <div class="bill-to-detail" v-if="data.client.tax_number">Tax #: {{ data.client.tax_number }}</div>
      </section>

      <section class="items">
        <table>
          <thead>
            <tr>
              <th class="col-item">Item</th>
              <th class="col-num">Price</th>
              <th class="col-num" v-if="data.enable_box_qty">Box</th>
              <th class="col-num">Qty</th>
              <th class="col-num">Discount</th>
              <th class="col-num">Tax</th>
              <th class="col-num">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, i) in data.items" :key="i">
              <td class="col-item">
                <div class="item-name">{{ item.name }}</div>
                <div class="item-code" v-if="item.code">{{ item.code }}</div>
                <div class="item-code" v-if="item.is_imei && item.imei_number">SN: {{ item.imei_number }}</div>
                <div class="item-code item-batches" v-if="item.is_batch_tracked && item.batches?.length">
                  <span v-for="(b, bi) in item.batches" :key="bi" class="batch-tag">{{ b.batch_number || b.batch_no || b }}</span>
                </div>
              </td>
              <td class="col-num">{{ formatMoney(item.price) }}</td>
              <td class="col-num" v-if="data.enable_box_qty">{{ item.box_qty !== null && item.box_qty !== undefined ? formatQty(item.box_qty) : '—' }}</td>
              <td class="col-num">
                {{ formatQty(item.quantity) }} {{ item.pack_name || item.unit }}
                <div class="item-code" v-if="item.pack_name && item.pack_multiplier > 1">
                  (×{{ item.pack_multiplier }}) = {{ formatQty(item.quantity * item.pack_multiplier) }} {{ item.unit }}
                </div>
              </td>
              <td class="col-num">{{ formatMoney(item.discount) }}</td>
              <td class="col-num">{{ formatMoney(item.tax) }}</td>
              <td class="col-num">{{ formatMoney(item.total) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="totals">
        <div class="totals-box">
          <div class="totals-row"><span>Subtotal</span><span>{{ formatMoney(data.totals.subtotal) }}</span></div>
          <div class="totals-row"><span>Order Tax</span><span>{{ formatMoney(data.totals.tax) }}</span></div>
          <div class="totals-row" v-if="data.totals.discount">
            <span>Discount</span>
            <span>
              −{{ data.totals.discount_method === '1' ? `${formatQty(data.totals.discount_percent)}% (${formatMoney(data.totals.discount)})` : formatMoney(data.totals.discount) }}
            </span>
          </div>
          <div class="totals-row" v-if="data.totals.discount_from_points"><span>Discount from Points</span><span>−{{ formatMoney(data.totals.discount_from_points) }}</span></div>
          <div class="totals-row" v-if="data.totals.shipping"><span>Shipping</span><span>{{ formatMoney(data.totals.shipping) }}</span></div>
          <div class="totals-row totals-grand"><span>Total</span><span>{{ formatMoney(data.totals.grand_total) }}</span></div>
          <div class="totals-row"><span>Paid</span><span>{{ formatMoney(data.totals.paid) }}</span></div>
          <div class="totals-row totals-due"><span>Balance due</span><span>{{ formatMoney(data.totals.due) }}</span></div>
          <div class="totals-row" v-if="data.totals.previous_dues && data.totals.show_previous_dues !== false"><span>Previous Dues</span><span>{{ formatMoney(data.totals.previous_dues) }}</span></div>
          <div class="totals-row totals-net" v-if="data.totals.previous_dues && data.totals.show_net_balance !== false"><span>Net Balance</span><span>{{ formatMoney(data.totals.net_balance) }}</span></div>
        </div>
      </section>

      <div class="download-bar">
        <a-button type="primary" size="large" :href="data.pdf_url" target="_blank">
          <template #icon><DownloadOutlined /></template>
          Download PDF
        </a-button>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Public Invoice URL — branded, no-login invoice view.
 *
 * Route: /invoice/:token, meta: { skipAuth: true } (see router/index.js) —
 * completely outside AdminLayout and the auth guard, matching the
 * existing /ping page's own pattern for a standalone public route.
 *
 * Data: GET /api/public/invoice/{token} — see PublicInvoiceController::
 * show() for the response shape and its own scope note (amounts here are
 * in the sale's stored currency; the "Download PDF" button's PDF is the
 * authoritative, fully currency-converted document via the existing
 * Sale_PDF, unchanged from before this page existed).
 *
 * Build L1 (2026-09-19) — brought this page's fields up to parity with
 * the authenticated Sale Detail page: Box Qty column (shown only when
 * the company's "enable_box_qty" setting is on, same as SaleDetails.vue),
 * per-line Discount/Tax, IMEI/batch numbers, pack quantity breakdown,
 * order-level Discount from Points, Previous Dues and Net Balance.
 * Customer email is intentionally not shown (see controller note).
 *
 * Plain English labels throughout (no i18n "t()" calls) — this project's own
 * documented lesson: an unadded translation key renders as its raw key
 * text, and this page has no admin session to have ever loaded
 * translations into in the first place.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { ExclamationCircleOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';

const route = useRoute();
const loading = ref(true);
const error = ref(false);
const data = ref(null);

const statusLabel = computed(() => {
  const s = (data.value?.invoice.payment_status || '').toLowerCase();
  if (s === 'paid') return 'Paid';
  if (s === 'partial') return 'Partially paid';
  return 'Unpaid';
});

const statusClass = computed(() => {
  const s = (data.value?.invoice.payment_status || '').toLowerCase();
  if (s === 'paid') return 'status-paid';
  if (s === 'partial') return 'status-partial';
  return 'status-unpaid';
});

function formatDate(d) {
  if (!d) return '';
  const parsed = new Date(d);
  if (Number.isNaN(parsed.getTime())) return d;
  return parsed.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
}

function formatMoney(n) {
  const num = Number(n) || 0;
  return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatQty(n) {
  const num = Number(n) || 0;
  return num % 1 === 0 ? String(num) : num.toFixed(2);
}

onMounted(async () => {
  try {
    data.value = await http.get(`public/invoice/${route.params.token}`);
  } catch (e) {
    error.value = true;
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.invoice-page {
  min-height: 100vh;
  background: #F1F5F9;
  padding: 40px 16px;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  color: #0F172A;
}

.state-screen {
  min-height: 60vh;
  display: flex;
  align-items: center;
  justify-content: center;
}

.state-card {
  text-align: center;
  max-width: 360px;
}

.state-icon {
  font-size: 40px;
  color: #94A3B8;
  margin-bottom: 12px;
}

.state-card h1 {
  font-size: 18px;
  font-weight: 600;
  margin: 0 0 8px;
}

.state-card p {
  color: #64748B;
  font-size: 14px;
  line-height: 1.5;
  margin: 0;
}

.sheet {
  max-width: 800px;
  margin: 0 auto;
  background: #FFFFFF;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08), 0 8px 24px rgba(15, 23, 42, 0.06);
  overflow: hidden;
}

.sheet-header {
  background: #0F172A;
  color: #F8FAFC;
  padding: 32px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 24px;
  flex-wrap: wrap;
}

.brand {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  min-width: 0;
}

.brand-logo {
  width: 44px;
  height: 44px;
  object-fit: contain;
  background: #fff;
  border-radius: 6px;
  padding: 4px;
  flex-shrink: 0;
}

.brand-name {
  font-size: 18px;
  font-weight: 600;
  letter-spacing: -0.01em;
}

.brand-detail {
  font-size: 13px;
  color: #94A3B8;
  margin-top: 3px;
  line-height: 1.5;
}

.invoice-meta {
  text-align: right;
}

.invoice-label {
  font-size: 12px;
  color: #94A3B8;
  letter-spacing: 0.04em;
}

.invoice-ref {
  font-size: 20px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  margin-top: 2px;
}

.invoice-date {
  font-size: 13px;
  color: #94A3B8;
  margin-top: 2px;
}

.status-pill {
  display: inline-block;
  margin-top: 10px;
  padding: 3px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}

.status-paid { background: #16653433; color: #4ADE80; }
.status-partial { background: #78350F55; color: #FBBF24; }
.status-unpaid { background: #7F1D1D55; color: #FCA5A5; }

.bill-to {
  padding: 24px 32px 0;
}

.bill-to-label {
  font-size: 11px;
  color: #94A3B8;
  letter-spacing: 0.04em;
  margin-bottom: 6px;
}

.bill-to-name {
  font-size: 15px;
  font-weight: 600;
}

.bill-to-detail {
  font-size: 13px;
  color: #64748B;
  margin-top: 2px;
}

.items {
  padding: 20px 32px 0;
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 460px;
}

thead th {
  text-align: left;
  font-size: 11px;
  color: #94A3B8;
  letter-spacing: 0.04em;
  padding: 0 0 8px;
  border-bottom: 1px solid #E2E8F0;
}

.col-num { text-align: right; }

tbody td {
  padding: 12px 0;
  border-bottom: 1px solid #F1F5F9;
  font-size: 14px;
  vertical-align: top;
}

tbody td.col-num {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.item-name { font-weight: 500; }
.item-code { font-size: 12px; color: #94A3B8; margin-top: 2px; }
.item-batches { display: flex; gap: 4px; flex-wrap: wrap; }
.batch-tag {
  display: inline-block;
  border: 1px solid #E2E8F0;
  border-radius: 4px;
  padding: 0 6px;
  font-size: 11px;
  color: #64748B;
}

.totals {
  padding: 20px 32px 0;
  display: flex;
  justify-content: flex-end;
}

.totals-box {
  width: 100%;
  max-width: 280px;
}

.totals-row {
  display: flex;
  justify-content: space-between;
  font-size: 14px;
  padding: 6px 0;
  color: #475569;
  font-variant-numeric: tabular-nums;
}

.totals-grand {
  border-top: 1px solid #E2E8F0;
  margin-top: 4px;
  padding-top: 10px;
  font-size: 16px;
  font-weight: 700;
  color: #0F172A;
}

.totals-due {
  font-weight: 700;
  color: #DC2626;
}

.totals-net {
  border-top: 1px solid #E2E8F0;
  margin-top: 4px;
  padding-top: 10px;
  font-weight: 700;
  color: #0F172A;
}

.download-bar {
  padding: 28px 32px 32px;
  display: flex;
  justify-content: center;
  border-top: 1px solid #F1F5F9;
  margin-top: 20px;
}

@media (max-width: 560px) {
  .sheet-header { flex-direction: column; }
  .invoice-meta { text-align: left; }
  .bill-to, .items, .totals, .download-bar { padding-left: 20px; padding-right: 20px; }
}

@media print {
  .invoice-page { background: #fff; padding: 0; }
  .sheet { box-shadow: none; border-radius: 0; }
  .download-bar { display: none; }
}
</style>
