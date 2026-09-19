<template>
  <a-card size="small" :body-style="{ padding: 0 }" style="margin-top: 16px">
    <template #title>
      Movement History
      <a-tag v-if="hasMismatch" color="red" style="margin-left: 8px">Mismatch found</a-tag>
    </template>
    <template #extra>
      <a-space>
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

    <div v-if="!errorMessage && reconciliation.length" style="padding: 12px 16px; border-bottom: 1px solid rgba(5,5,5,0.06)">
      <a-space wrap>
        <a-tag
          v-for="r in reconciliation" :key="r.warehouse_id"
          :color="r.row_missing ? 'red' : (r.reconciled ? 'green' : 'orange')"
        >
          {{ r.warehouse_name }}: Ledger {{ r.ledger_balance }}
          <template v-if="r.row_missing"> — No stock row on record</template>
          <template v-else-if="!r.reconciled"> — Actual {{ r.actual_qte }} ({{ r.difference > 0 ? '+' : '' }}{{ r.difference }})</template>
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
        <template v-else-if="column.key === 'qty_in'">
          <span v-if="record.qty_in" style="color: #16a34a">+{{ record.qty_in }}</span>
        </template>
        <template v-else-if="column.key === 'qty_out'">
          <span v-if="record.qty_out" style="color: #dc2626">-{{ record.qty_out }}</span>
        </template>
        <template v-else-if="column.key === 'running_balance'">
          <b>{{ record.running_balance }}</b>
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
 * badges from the backend (see ProductMovementLedgerService's scope note —
 * a mismatch here for a Units/Multi-Pack product isn't necessarily the
 * stock-integrity bug; it's a real discrepancy either way, worth checking).
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
import http from '../../lib/http';

const props = defineProps({
  productId: { type: [Number, String], required: true },
  productVariantId: { type: [Number, String], default: null },
});

const movements = ref([]);
const reconciliation = ref([]);
const warehouseOptionsCache = ref([]); // populated from the unfiltered load's reconciliation block
const loading = ref(false);
const warehouseFilter = ref(null);
const dateRange = ref([]);
const errorMessage = ref('');

const hasMismatch = computed(() => reconciliation.value.some(r => r.row_missing || !r.reconciled));

const columns = [
  { title: 'Date', dataIndex: 'date', key: 'date' },
  { title: 'Type', key: 'type' },
  { title: 'Reference', dataIndex: 'reference_id', key: 'reference_id' },
  { title: 'Warehouse', dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: 'In', key: 'qty_in', align: 'right' },
  { title: 'Out', key: 'qty_out', align: 'right' },
  { title: 'Balance', key: 'running_balance', align: 'right' },
];

const typeMeta = {
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

async function load() {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params = { product_id: props.productId };
    if (props.productVariantId) params.product_variant_id = props.productVariantId;
    if (warehouseFilter.value) params.warehouse_id = warehouseFilter.value;
    if (dateRange.value?.[0]) params.date_from = dateRange.value[0].format('YYYY-MM-DD');
    if (dateRange.value?.[1]) params.date_to = dateRange.value[1].format('YYYY-MM-DD');

    const data = await http.get('products/movement-ledger', params);
    movements.value = data.movements || [];
    reconciliation.value = data.reconciliation || [];
    if (!warehouseFilter.value) {
      // Only refresh the filter's own option list from an unfiltered load —
      // a filtered load's reconciliation block only covers the one selected
      // warehouse, which would otherwise collapse the dropdown to one option.
      warehouseOptionsCache.value = reconciliation.value.map(r => ({ id: r.warehouse_id, name: r.warehouse_name }));
    }
  } catch (e) {
    movements.value = [];
    reconciliation.value = [];
    const status = e?.status;
    const serverMessage = e?.data?.message || (e?.data?.errors ? Object.values(e.data.errors).flat().join(' ') : '');
    errorMessage.value = status
      ? `Server responded with ${status}${serverMessage ? ': ' + serverMessage : ''}`
      : (e?.message || 'Unknown error — check the browser console/Network tab for details.');
  } finally {
    loading.value = false;
  }
}

watch(() => [props.productId, props.productVariantId], load, { immediate: true });

defineExpose({ load });
</script>
