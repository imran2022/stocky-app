<template>
  <ReportPage
    title="Invoice Receivables Report"
    :breadcrumb="[$t('Reports'), 'Invoice Receivables Report']"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/invoice_receivables"
    :export-params="filterParams"
    export-rows-key="invoices"
  >
    <template #actions>
      <ViewCurrencySelect />
    </template>

    <!-- Summary over the whole filtered set (backend `summary`), not just the page on screen. -->
    <template #chart>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="k in kpiTiles" :key="k.key" :xs="12" :sm="8" :md="4">
          <a-card size="small" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: k.tint, color: k.color }">
                <component :is="k.icon" />
              </div>
              <div class="kpi-text">
                <a-typography-text type="secondary" class="kpi-label">{{ k.label }}</a-typography-text>
                <div class="kpi-value">
                  <a-spin v-if="crud.loading.value" size="small" />
                  <template v-else>{{ k.value }}</template>
                </div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
      <a-select
        v-model:value="clientId" style="width: 200px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Customer')" :options="opts('customers')" @change="crud.reload()" />
      <a-select
        v-model:value="status" style="width: 160px" allow-clear placeholder="Status"
        :options="statusOptions" @change="crud.reload()" />
      <a-checkbox v-model:checked="outstandingOnly" @change="crud.reload()">
        Show Outstanding Only
      </a-checkbox>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'due_date'">
        <template v-if="record.due_date">
          {{ date(record.due_date) }}
          <a-tag v-if="record.is_overdue" color="red" style="margin-left: 4px">Overdue</a-tag>
        </template>
        <span v-else>—</span>
      </template>
      <template v-else-if="column.key === 'overdue_days'">
        <span v-if="record.overdue_days > 0">{{ record.overdue_days }}</span>
        <span v-else>—</span>
      </template>
      <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="receivableStatusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import {
  FileTextOutlined, RollbackOutlined, DollarOutlined,
  CheckCircleOutlined, ClockCircleOutlined, WarningOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { receivableStatusColor } from '../../lib/statusColors';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { money, date } = useFormat();

const MONEY_KEYS = ['invoice_total', 'return_amount', 'net_invoice', 'paid_amount', 'remaining'];

const clientId = ref(undefined);
const status = ref(undefined);
const outstandingOnly = ref(false);
// No default range = all time; the backend only date-filters when bounds are sent.
const range = ref(null);

const filterParams = () => ({
  client_id: clientId.value || '',
  status: status.value || '',
  outstanding_only: outstandingOnly.value ? 1 : 0,
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
});

// Payload: { invoices, totalRows, summary, customers }
const crud = useCrudTable('report/invoice_receivables', {
  rowsKey: 'invoices',
  sortField: 'date',
  sortType: 'desc',
  params: filterParams,
});

const opts = (key, labelOf = x => x.name) =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: labelOf(x) }));

const statusOptions = [
  { value: 'paid', label: 'Paid' },
  { value: 'partial', label: 'Partial' },
  { value: 'due', label: 'Due' },
  { value: 'overdue', label: 'Overdue' },
];
const statusLabel = s => statusOptions.find(o => o.value === s)?.label || s;

const kpiTiles = computed(() => {
  const s = crud.payload.value?.summary || {};
  const n = k => Number(s[k]) || 0;
  return [
    { key: 'total_invoice', label: 'Total Invoice', value: money(n('total_invoice')), icon: FileTextOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { key: 'total_return', label: 'Total Return', value: money(n('total_return')), icon: RollbackOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.12)' },
    { key: 'net_invoice', label: 'Net Invoice', value: money(n('net_invoice')), icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { key: 'total_paid', label: 'Total Paid', value: money(n('total_paid')), icon: CheckCircleOutlined, color: '#22c55e', tint: 'rgba(34, 197, 94, 0.12)' },
    { key: 'total_remaining', label: 'Total Remaining', value: money(n('total_remaining')), icon: ClockCircleOutlined, color: '#0ea5e9', tint: 'rgba(14, 165, 233, 0.12)' },
    { key: 'total_overdue', label: 'Total Overdue', value: money(n('total_overdue')), icon: WarningOutlined, color: '#f43f5e', tint: 'rgba(244, 63, 94, 0.12)' },
  ];
});

const columns = computed(() => [
  { title: 'Date', key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: 'Reference', dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: 'Customer', dataIndex: 'client_name', key: 'client_name' },
  { title: 'Invoice Total', key: 'invoice_total', dataIndex: 'invoice_total', align: 'right', sum: 'money', exportValue: r => money(r.invoice_total) },
  { title: 'Return Amount', key: 'return_amount', dataIndex: 'return_amount', align: 'right', sum: 'money', exportValue: r => money(r.return_amount) },
  { title: 'Net Invoice', key: 'net_invoice', dataIndex: 'net_invoice', align: 'right', sum: 'money', exportValue: r => money(r.net_invoice) },
  { title: 'Paid Amount', key: 'paid_amount', dataIndex: 'paid_amount', align: 'right', sum: 'money', exportValue: r => money(r.paid_amount) },
  { title: 'Remaining', key: 'remaining', dataIndex: 'remaining', align: 'right', sum: 'money', exportValue: r => money(r.remaining) },
  { title: 'Due Date', key: 'due_date', dataIndex: 'due_date', exportValue: r => r.due_date || '' },
  { title: 'Overdue Days', key: 'overdue_days', dataIndex: 'overdue_days', align: 'right', exportValue: r => r.overdue_days || 0 },
  { title: 'Status', key: 'status', dataIndex: 'status', exportValue: r => statusLabel(r.status) },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
/* Same tile anatomy as the other reports' KPI cards. */
.kpi-card { border-radius: 10px; }
.kpi-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.kpi-icon {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex: 0 0 auto;
}
.kpi-text { min-width: 0; flex: 1 1 auto; }
.kpi-label {
  display: block;
  font-size: 12px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.kpi-value {
  font-size: 18px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
@media (max-width: 575px) {
  .kpi-value { font-size: 15px; }
}
</style>
