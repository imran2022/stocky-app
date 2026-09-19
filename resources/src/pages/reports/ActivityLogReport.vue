<template>
  <ReportPage
    title="Activity Log Report"
    :breadcrumb="['Reports', 'Activity Log Report']"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="reports/activity-log"
    :export-params="filterParams"
    export-rows-key="rows"
  >
    <template #filters>
      <DateRangePicker v-model:value="range" @change="crud.reload()" />
      <a-select
        v-model:value="userId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="'All Users'" :options="opts('users')" @change="crud.reload()" />
      <a-select
        v-model:value="module" style="width: 180px" allow-clear
        :placeholder="'All Modules'" :options="moduleOptions" @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
      <template v-else-if="column.key === 'action'">
        <a-tag :color="actionColor(record.action)">{{ actionLabel(record.action) }}</a-tag>
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="record.status === 'success' ? 'success' : 'default'">{{ record.status }}</a-tag>
      </template>
      <template v-else-if="column.key === 'view'">
        <a-button
          v-if="record.old_values || record.new_values" size="small" type="text"
          @click="viewingRecord = record"
        >
          <template #icon><EyeOutlined /></template>
        </a-button>
      </template>
    </template>
  </ReportPage>

  <a-modal v-model:open="viewingModalOpen" title="Activity Details" :footer="null" width="600px">
    <p><strong>{{ viewingRecord?.description }}</strong></p>
    <a-table
      v-if="changedFieldRows.length"
      :columns="diffColumns" :data-source="changedFieldRows" :pagination="false" size="small" row-key="field"
    />
    <a-empty v-else description="No additional details" />
  </a-modal>
</template>

<script setup>
/**
 * Build I1 — Activity Log Report (admin-facing, all users).
 *
 * Backend: GET reports/activity-log — merges the new `activity_logs` table
 * (Sale/Purchase/Product/Customer create/update/delete) with the existing
 * `user_login_sessions` table (all users, unlike the self-service Login
 * Activity Report) into one chronological feed. See
 * app/Http/Controllers/ActivityLogController.php for the merge logic and
 * its scope notes.
 *
 * Plain English fallback labels are used for action tags — this project's
 * own documented lesson (an un-added translation key renders as its raw
 * key) applies the same way it did for the Movement Ledger card.
 */
import { ref, computed, onMounted } from 'vue';
import { EyeOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { dateTime } = useFormat();

const range = ref([]);
const userId = ref(undefined);
const module = ref(undefined);
const viewingRecord = ref(null);

const viewingModalOpen = computed({
  get: () => !!viewingRecord.value,
  set: v => { if (!v) viewingRecord.value = null; },
});

const filterParams = () => ({
  date_from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  date_to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  user_id: userId.value || '',
  module: module.value || '',
});

// Payload: { rows, totalRows, users }
const crud = useCrudTable('reports/activity-log', {
  rowsKey: 'rows',
  sortField: 'date',
  sortType: 'desc',
  params: filterParams,
});

const opts = key => (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name }));

const moduleOptions = [
  { value: 'Sale', label: 'Sales' },
  { value: 'Purchase', label: 'Purchases' },
  { value: 'Product', label: 'Products' },
  { value: 'Customer', label: 'Customers' },
  { value: 'Adjustment', label: 'Stock Adjustments' },
  { value: 'Transfer', label: 'Stock Transfers' },
  { value: 'User', label: 'Users' },
  { value: 'Role', label: 'Roles & Permissions' },
  { value: 'Auth', label: 'Login' },
  // Build I3 — audit trail coverage extension (2026-09-19)
  { value: 'Sale Return', label: 'Sale Returns' },
  { value: 'Purchase Return', label: 'Purchase Returns' },
  { value: 'Damage', label: 'Damages' },
  { value: 'Quotation', label: 'Quotations' },
  { value: 'Purchase Order', label: 'Purchase Orders' },
  { value: 'Warehouse', label: 'Warehouses' },
  { value: 'Shipment', label: 'Shipments' },
  { value: 'Settings', label: 'System Settings' },
  { value: 'Payment (Sale)', label: 'Payments (Sale)' },
  { value: 'Payment (Purchase)', label: 'Payments (Purchase)' },
  { value: 'Payment (Sale Return)', label: 'Payments (Sale Return)' },
  { value: 'Payment (Purchase Return)', label: 'Payments (Purchase Return)' },
];

const actionMeta = {
  created: { label: 'Created', color: 'green' },
  updated: { label: 'Updated', color: 'blue' },
  deleted: { label: 'Deleted', color: 'red' },
  login: { label: 'Login', color: 'purple' },
};
function actionLabel(action) { return actionMeta[action]?.label || action; }
function actionColor(action) { return actionMeta[action]?.color || 'default'; }

// Old/new value diff shown in the detail modal — one row per changed field.
const changedFieldRows = computed(() => {
  const old = viewingRecord.value?.old_values || {};
  const val = viewingRecord.value?.new_values || {};
  const fields = new Set([...Object.keys(old), ...Object.keys(val)]);
  return [...fields].map(field => ({
    field,
    old: old[field] ?? '—',
    new: val[field] ?? '—',
  }));
});

const diffColumns = [
  { title: 'Field', dataIndex: 'field', key: 'field' },
  { title: 'Old value', dataIndex: 'old', key: 'old' },
  { title: 'New value', dataIndex: 'new', key: 'new' },
];

const columns = computed(() => [
  { title: 'Date', key: 'date', dataIndex: 'date', exportValue: r => dateTime(r.date) },
  { title: 'User', dataIndex: 'user', key: 'user' },
  { title: 'Module', dataIndex: 'module', key: 'module' },
  { title: 'Activity Type', key: 'action', dataIndex: 'action', exportValue: r => actionLabel(r.action) },
  { title: 'Description', dataIndex: 'description', key: 'description' },
  { title: 'IP Address', dataIndex: 'ip_address', key: 'ip_address' },
  { title: 'Device', dataIndex: 'device', key: 'device' },
  { title: 'Status', key: 'status', dataIndex: 'status' },
  { title: '', key: 'view' },
]);

onMounted(crud.fetchRows);
</script>
