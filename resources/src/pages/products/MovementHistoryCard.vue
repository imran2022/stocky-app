<template>
  <a-card size="small" :body-style="{ padding: 0 }" style="margin-top: 16px">
    <template #title>
      {{ title }}
      <a-tag v-if="hasMismatch" color="red" style="margin-left: 8px">Mismatch found</a-tag>
    </template>
    <template v-if="showFilters || showExport" #extra>
      <a-space wrap class="ledger-actions">
        <template v-if="showFilters">
        <a-select
          v-model:value="warehouseFilter"
          size="small"
          style="width: 160px"
          placeholder="All Warehouses"
          allow-clear
          @change="load"
        >
          <a-select-option v-for="w in warehouseOptionsCache" :key="w.id" :value="w.id">{{ w.name }}</a-select-option>
        </a-select>
        <a-range-picker v-model:value="dateRange" size="small" @change="load" />
        </template>
        <a-button v-if="showExport" size="small" :loading="exporting" @click="downloadExcel">
          <template #icon><FileExcelOutlined /></template>
          Excel
        </a-button>
        <a-button v-if="showExport" size="small" :loading="exporting" @click="downloadPdf">
          <template #icon><FilePdfOutlined /></template>
          PDF
        </a-button>
      </a-space>
    </template>

    <a-alert
      v-if="errorMessage"
      type="error"
      show-icon
      style="margin: 12px 16px"
      :message="'Could not load movement history'"
      :description="errorMessage"
    />

    <section v-if="!errorMessage && showSummary && summary" class="movement-summary">
      <div v-for="group in summaryGroups" :key="group.title" class="summary-group" :class="group.tone">
        <div class="summary-heading">
          <span class="summary-icon"><component :is="group.icon" /></span>
          <span>{{ group.title }}</span>
        </div>
        <div v-for="item in group.items" :key="item.label" class="summary-line" :class="{ primary: item.primary }">
          <span>{{ item.label }}</span>
          <strong>{{ formatQty(item.value) }}</strong>
        </div>
      </div>
    </section>

    <div v-if="!errorMessage && reconciliation.length" class="reconciliation-row">
      <a-space wrap>
        <a-tag
          v-for="r in reconciliation" :key="r.warehouse_id"
          :color="!r.comparison_available ? 'blue' : (r.row_missing ? 'red' : (r.reconciled ? 'green' : 'orange'))"
        >
          {{ r.warehouse_name }}:
          <template v-if="!r.comparison_available">Calculated closing {{ formatQty(r.ledger_balance) }} — historical range</template>
          <template v-else>
            Calculated stock {{ formatQty(r.ledger_balance) }}
            <template v-if="r.row_missing"> — No current stock row</template>
            <template v-else-if="r.reconciled"> — matches current stock</template>
            <template v-else> — Current stock {{ formatQty(r.actual_qte) }} (difference {{ r.difference > 0 ? '+' : '' }}{{ formatQty(r.difference) }})</template>
          </template>
        </a-tag>
      </a-space>
    </div>

    <a-table
      v-if="!errorMessage"
      :columns="columns" :data-source="movements" :loading="loading"
      :pagination="{ pageSize: 20 }" size="small" :row-key="r => r.id" :scroll="{ x: 'max-content' }"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'type'">
          <a-tag :color="typeColor(record.type)">{{ typeLabel(record.type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'occurred_at'">
          <span>{{ record.date }}</span>
          <small style="display: block; opacity: 0.65">{{ record.time }}</small>
        </template>
        <template v-else-if="column.key === 'reference'">
          <span style="font-weight: 600">{{ record.reference || ('#' + record.reference_id) }}</span>
        </template>
        <template v-else-if="column.key === 'variant'">
          <div v-if="record.variant_name || record.variant_code">
            <span style="font-weight: 500">{{ record.variant_name || 'Variant' }}</span>
            <small v-if="record.variant_code" style="display: block; opacity: 0.58">{{ record.variant_code }}</small>
          </div>
          <span v-else style="opacity: 0.42">—</span>
        </template>
        <template v-else-if="column.key === 'party_name'">
          <div v-if="record.party_name">
            <span style="font-weight: 500">{{ record.party_name }}</span>
            <small style="display: block; opacity: 0.58; text-transform: capitalize">{{ record.party_type }}</small>
          </div>
          <span v-else style="opacity: 0.42">—</span>
        </template>
        <template v-else-if="column.key === 'qty_in'">
          <span v-if="record.qty_in" style="color: #16a34a">+{{ formatQty(record.qty_in) }}</span>
        </template>
        <template v-else-if="column.key === 'qty_out'">
          <span v-if="record.qty_out" style="color: #dc2626">-{{ formatQty(record.qty_out) }}</span>
        </template>
        <template v-else-if="column.key === 'running_balance'">
          <b>{{ formatQty(record.running_balance) }}</b>
        </template>
      </template>
      <template #emptyText>
        <a-empty description="No movement found for this product yet" style="padding: 24px 0" />
      </template>
    </a-table>
  </a-card>
</template>

<script setup>
/**
 * Movement History — Build H2 (frontend for Build H1's backend).
 *
 * Shows one product's lifetime stock movement (Purchase, Sale, Transfer,
 * Adjustment, Sale Return, Purchase Return, Damage) as a single chronological
 * table with a running balance, plus the ledger-vs-actual reconciliation
 * badges from the backend. Quantities arrive in the product's base unit and
 * only stock-affecting transaction statuses are included.
 *
 * Plain English labels on purpose (no i18n "t()" calls) — this project's own documented
 * lesson is that an un-added translation key renders as its raw key text,
 * and adding these to Settings → Translations is optional/cosmetic, not
 * required for the feature to work correctly.
 *
 * Errors are shown inline (not swallowed) on purpose: a silent empty state
 * here looks identical to "no movement yet," which made a real backend
 * error impossible to tell apart from an empty product during testing.
 *
 * Backend: GET products/movement-ledger?product_id=...&warehouse_id=...
 *          &date_from=...&date_to=... -> {movements, reconciliation}
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import {
  ArrowDownOutlined,
  ArrowUpOutlined,
  CalculatorOutlined,
  FileExcelOutlined,
  FilePdfOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { exportExcel, exportPdf } from '../../lib/exporters';

const props = defineProps({
  productId: { type: [Number, String], required: true },
  title: { type: String, default: 'Movement History' },
  productVariantId: { type: [Number, String], default: null },
  warehouseId: { type: [Number, String], default: null },
  dateFrom: { type: String, default: null },
  dateTo: { type: String, default: null },
  showFilters: { type: Boolean, default: true },
  showSummary: { type: Boolean, default: false },
  showExport: { type: Boolean, default: false },
});

const movements = ref([]);
const reconciliation = ref([]);
const summary = ref(null);
const warehouseOptionsCache = ref([]); // populated from the unfiltered load's reconciliation block
const loading = ref(false);
const warehouseFilter = ref(null);
const dateRange = ref([]);
const errorMessage = ref('');
const exporting = ref(false);

const hasMismatch = computed(() => reconciliation.value.some(
  r => r.comparison_available && (r.row_missing || r.reconciled === false),
));

const columns = [
  { title: 'Date & Time', dataIndex: 'occurred_at', key: 'occurred_at' },
  { title: 'Type', key: 'type' },
  { title: 'Variant', key: 'variant', exportValue: row => variantLabel(row) },
  { title: 'Reference', dataIndex: 'reference', key: 'reference' },
  { title: 'Warehouse', dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: 'In', key: 'qty_in', align: 'right' },
  { title: 'Out', key: 'qty_out', align: 'right' },
  { title: 'Balance', key: 'running_balance', align: 'right' },
  { title: 'Customer / Supplier', dataIndex: 'party_name', key: 'party_name' },
];

const typeMeta = {
  opening_stock: { label: 'Opening Stock', color: 'lime' },
  purchase: { label: 'Purchase', color: 'green' },
  sale: { label: 'Sale', color: 'blue' },
  transfer_in: { label: 'Transfer In', color: 'cyan' },
  transfer_out: { label: 'Transfer Out', color: 'purple' },
  adjustment: { label: 'Adjustment', color: 'gold' },
  sale_return: { label: 'Sale Return', color: 'geekblue' },
  purchase_return: { label: 'Purchase Return', color: 'volcano' },
  damage: { label: 'Damage', color: 'red' },
};
function typeLabel(type) { return typeMeta[type]?.label || type; }
function typeColor(type) { return typeMeta[type]?.color || 'default'; }
function variantLabel(row) {
  if (!row.variant_name && !row.variant_code) return '';
  return [row.variant_name, row.variant_code].filter(Boolean).join(' — ');
}
function formatQty(value) {
  const number = Number(value || 0);
  return Number.isInteger(number) ? String(number) : number.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
}

const summaryGroups = computed(() => {
  if (!summary.value) return [];
  const s = summary.value;
  const totalIn = Number(s.purchase) + Number(s.opening_stock) + Number(s.sale_return)
    + Number(s.transfer_in) + Number(s.adjustment_in);
  const totalOut = Number(s.sale) + Number(s.purchase_return) + Number(s.transfer_out)
    + Number(s.damage) + Number(s.adjustment_out);
  return [
    {
      title: 'Quantities In', tone: 'in', icon: ArrowDownOutlined,
      items: [
        { label: 'Total Purchase', value: s.purchase },
        { label: 'Opening Stock', value: s.opening_stock },
        { label: 'Sale Returns', value: s.sale_return },
        { label: 'Transfers In', value: s.transfer_in },
        { label: 'Adjustments In', value: s.adjustment_in },
        { label: 'Total In', value: totalIn, primary: true },
      ],
    },
    {
      title: 'Quantities Out', tone: 'out', icon: ArrowUpOutlined,
      items: [
        { label: 'Total Sold', value: s.sale },
        { label: 'Purchase Returns', value: s.purchase_return },
        { label: 'Transfers Out', value: s.transfer_out },
        { label: 'Damages', value: s.damage },
        { label: 'Adjustments Out', value: s.adjustment_out },
        { label: 'Total Out', value: totalOut, primary: true },
      ],
    },
    {
      title: 'Totals', tone: 'total', icon: CalculatorOutlined,
      items: [
        { label: 'Opening Balance', value: s.opening_balance },
        { label: 'Net Movement', value: s.net_movement },
        { label: 'Calculated Closing', value: s.calculated_closing },
        { label: s.stock_label, value: s.current_stock ?? s.calculated_closing, primary: true },
      ],
    },
  ];
});

const exportColumns = [
  { title: 'Date & Time', dataIndex: 'occurred_at' },
  { title: 'Type', exportValue: row => typeLabel(row.type) },
  { title: 'Variant', exportValue: variantLabel },
  { title: 'Reference', exportValue: row => row.reference || `#${row.reference_id}` },
  { title: 'Warehouse', dataIndex: 'warehouse_name' },
  { title: 'Quantity In', dataIndex: 'qty_in' },
  { title: 'Quantity Out', dataIndex: 'qty_out' },
  { title: 'Running Balance', dataIndex: 'running_balance' },
  { title: 'Customer / Supplier', dataIndex: 'party_name' },
];

function exportFoot() {
  if (!summary.value) return null;
  const totalIn = movements.value.reduce((sum, row) => sum + Number(row.qty_in || 0), 0);
  const totalOut = movements.value.reduce((sum, row) => sum + Number(row.qty_out || 0), 0);
  return ['Summary', '', '', '', '', '', '',
    `Closing ${formatQty(summary.value.calculated_closing)}`, ''].map((value, index) => {
      if (index === 5) return `In ${formatQty(totalIn)}`;
      if (index === 6) return `Out ${formatQty(totalOut)}`;
      return value;
    });
}

async function downloadExcel() {
  exporting.value = true;
  try {
    await exportExcel('product_movement_history', exportColumns, movements.value, { foot: exportFoot() });
  } catch (error) {
    message.error('Could not create the Excel report.');
  } finally {
    exporting.value = false;
  }
}

async function downloadPdf() {
  exporting.value = true;
  try {
    await exportPdf(props.title, exportColumns, movements.value, { landscape: true, foot: exportFoot() });
  } catch (error) {
    message.error('Could not create the PDF report.');
  } finally {
    exporting.value = false;
  }
}

async function load() {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params = { product_id: props.productId };
    if (props.productVariantId) params.product_variant_id = props.productVariantId;
    const selectedWarehouse = props.showFilters ? warehouseFilter.value : props.warehouseId;
    const selectedDateFrom = props.showFilters
      ? (dateRange.value?.[0] ? dateRange.value[0].format('YYYY-MM-DD') : null)
      : props.dateFrom;
    const selectedDateTo = props.showFilters
      ? (dateRange.value?.[1] ? dateRange.value[1].format('YYYY-MM-DD') : null)
      : props.dateTo;
    if (selectedWarehouse) params.warehouse_id = selectedWarehouse;
    if (selectedDateFrom) params.date_from = selectedDateFrom;
    if (selectedDateTo) params.date_to = selectedDateTo;

    const data = await http.get('products/movement-ledger', params);
    if (!data || !Array.isArray(data.movements) || !Array.isArray(data.reconciliation)) {
      throw new Error('The server returned an invalid movement-history response.');
    }
    movements.value = data.movements || [];
    reconciliation.value = data.reconciliation || [];
    summary.value = data.summary || null;
    if (props.showFilters && !warehouseFilter.value) {
      // Only refresh the filter's own option list from an unfiltered load —
      // a filtered load's reconciliation block only covers the one selected
      // warehouse, which would otherwise collapse the dropdown to one option.
      warehouseOptionsCache.value = reconciliation.value.map(r => ({ id: r.warehouse_id, name: r.warehouse_name }));
    }
  } catch (e) {
    movements.value = [];
    reconciliation.value = [];
    summary.value = null;
    const status = e?.status;
    const serverMessage = e?.data?.message || (e?.data?.errors ? Object.values(e.data.errors).flat().join(' ') : '');
    errorMessage.value = status
      ? `Server responded with ${status}${serverMessage ? ': ' + serverMessage : ''}`
      : (e?.message || 'Unknown error — check the browser console/Network tab for details.');
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.productId, props.productVariantId, props.warehouseId, props.dateFrom, props.dateTo],
  load,
  { immediate: true },
);

defineExpose({ load });
</script>

<style scoped>
.movement-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  padding: 16px;
  background: rgba(148, 163, 184, 0.07);
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.summary-group {
  min-width: 0;
  padding: 14px 16px;
  color: inherit;
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 10px;
  background: var(--ant-color-bg-container, #fff);
}
.summary-heading {
  display: flex;
  align-items: center;
  gap: 9px;
  margin-bottom: 9px;
  font-size: 13px;
  font-weight: 700;
}
.summary-icon {
  display: grid;
  place-items: center;
  width: 27px;
  height: 27px;
  border-radius: 7px;
}
.summary-group.in .summary-icon { color: #15803d; background: rgba(34, 197, 94, 0.12); }
.summary-group.out .summary-icon { color: #b91c1c; background: rgba(239, 68, 68, 0.11); }
.summary-group.total .summary-icon { color: #6d28d9; background: rgba(124, 58, 237, 0.11); }
.summary-line {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  min-height: 27px;
  padding: 4px 0;
  font-size: 12px;
  border-bottom: 1px dashed rgba(148, 163, 184, 0.18);
}
.summary-line:last-child { border-bottom: 0; }
.summary-line span { opacity: 0.68; }
.summary-line strong { font-size: 13px; font-variant-numeric: tabular-nums; }
.summary-line.primary {
  margin-top: 5px;
  padding-top: 9px;
  font-weight: 700;
  border-top: 1px solid rgba(148, 163, 184, 0.25);
  border-bottom: 0;
}
.summary-line.primary span { opacity: 0.9; }
.summary-line.primary strong { font-size: 16px; }
.reconciliation-row {
  padding: 12px 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
@media (max-width: 900px) {
  .movement-summary { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
  .movement-summary { gap: 9px; padding: 10px; }
  .summary-group { padding: 12px 13px; }
  .ledger-actions { justify-content: flex-end; }
}
</style>
