<template>
  <div class="page">
    <PageHeader :title="$t('Customer_RFM_Report')" :breadcrumb="[$t('Reports'), $t('Customer_RFM_Report')]">
      <template #actions>
        <a-space wrap>
          <a-button :loading="exporting === 'pdf'" @click="exportList('pdf')">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('PDF') }}
          </a-button>
          <a-button :loading="exporting === 'xlsx'" @click="exportList('xlsx')">
            <template #icon><FileExcelOutlined /></template>
            {{ $t('EXCEL') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Filters -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]" align="bottom">
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Lookback_Window') }}</div>
          <a-select v-model:value="lookbackDays" style="width: 100%" :options="lookbackOptions" @change="crud.reload()" />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="warehouseId" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :options="warehouseOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Segment') }}</div>
          <a-select v-model:value="segment" style="width: 100%" allow-clear :options="segmentOptions" @change="crud.reload()" />
        </a-col>
      </a-row>
    </a-card>

    <!-- KPI tiles -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="k in kpiTiles" :key="k.label" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="kpi-card">
          <div class="kpi-inner">
            <div class="kpi-icon" :style="{ background: k.tint, color: k.color }">
              <component :is="k.icon" />
            </div>
            <div class="kpi-text">
              <div class="kpi-label">{{ k.label }}</div>
              <div class="kpi-value">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ k.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Segment distribution -->
    <a-card size="small" class="chart-card" style="margin-bottom: 16px" :title="$t('Segment_Distribution')">
      <ReportChart :data="chartData" :fields="chartFields" :title="$t('Customer_RFM_Report')" type="bar" x-key="label" :height="260" />
    </a-card>

    <!-- Table -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div style="font-weight: 500">{{ record.name }}</div>
          <div style="font-size: 12px; color: rgba(0,0,0,.45)">{{ record.phone }}</div>
        </template>
        <template v-else-if="column.key === 'last_date'">{{ record.last_date ? date(record.last_date) : '—' }}</template>
        <template v-else-if="column.key === 'monetary'">{{ money(record.monetary) }}</template>
        <template v-else-if="column.key === 'avg_order'">{{ money(record.avg_order) }}</template>
        <template v-else-if="column.key === 'due'">
          <span :style="{ color: Number(record.due) > 0 ? '#f59e0b' : 'inherit' }">{{ money(record.due) }}</span>
        </template>
        <template v-else-if="column.key === 'segment'">
          <a-tag :color="SEGMENT_COLOR[record.segment] || 'default'">{{ $t(record.segment.replace(' ', '_')) }}</a-tag>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Customer RFM Report (Build — 2026-09-25) — GET report/customer_rfm
 * {rows, totalRows, kpis, chart, lookback_days, warehouses}. Recency is
 * ALWAYS all-time (days since the client's last completed sale);
 * Frequency/Monetary are scoped to the chosen lookback window only.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { FilePdfOutlined, FileExcelOutlined, TeamOutlined, DollarOutlined, CrownOutlined, WarningOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';

const { t } = useI18n();
const { money, date } = useFormat();

const lookbackDays = ref(90);
const warehouseId = ref(undefined);
const segment = ref(undefined);

const lookbackOptions = [30, 60, 90, 180, 365].map(d => ({ value: d, label: `${d} ${t('Days')}` }));
const SEGMENT_COLOR = { New: 'blue', Champion: 'gold', Loyal: 'green', 'At Risk': 'orange', Lost: 'red' };
const segmentOptions = ['New', 'Champion', 'Loyal', 'At Risk', 'Lost'].map(v => ({ value: v, label: t(v.replace(' ', '_')) }));

const filterParams = () => ({
  lookback_days: lookbackDays.value,
  warehouse_id: warehouseId.value || '',
  segment: segment.value || '',
});

// Payload: { rows, totalRows, kpis, chart, lookback_days, warehouses }
const crud = useCrudTable('report/customer_rfm', {
  rowsKey: 'rows',
  sortField: 'monetary',
  sortType: 'desc',
  params: filterParams,
});

const warehouseOptions = computed(() => (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name })));

const kpiTiles = computed(() => {
  const k = crud.payload.value?.kpis || {};
  return [
    { label: t('Customers'), value: k.customers ?? 0, icon: TeamOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Revenue'), value: money(k.monetary ?? 0), icon: DollarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { label: t('Champion'), value: k.champions ?? 0, icon: CrownOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
    { label: t('At_Risk'), value: k.at_risk ?? 0, icon: WarningOutlined, color: '#dc2626', tint: 'rgba(220, 38, 38, 0.12)' },
  ];
});

const chartData = computed(() => crud.payload.value?.chart || []);
const chartFields = computed(() => [{ key: 'value', label: t('Customers') }]);

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Last_Purchase'), dataIndex: 'last_date', key: 'last_date', exportValue: r => r.last_date ? date(r.last_date) : '—' },
  { title: t('Recency_Days'), dataIndex: 'recency_days', key: 'recency_days', sorter: true, align: 'right' },
  { title: t('Frequency'), dataIndex: 'frequency', key: 'frequency', sorter: true, align: 'right', sum: true },
  { title: t('Monetary'), dataIndex: 'monetary', key: 'monetary', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.monetary) },
  { title: t('Avg_Order_Value'), dataIndex: 'avg_order', key: 'avg_order', align: 'right', exportValue: r => money(r.avg_order) },
  { title: t('Due'), dataIndex: 'due', key: 'due', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.due) },
  { title: t('Segment'), dataIndex: 'segment', key: 'segment' },
]);

const exporting = ref(null);
async function allRows() {
  const data = await http.get('report/customer_rfm', { ...filterParams(), page: 1, limit: -1, search: crud.search.value });
  return data.rows || [];
}
async function exportList(kind) {
  exporting.value = kind;
  try {
    const rows = await allRows();
    if (kind === 'xlsx') await exportExcel('Customer_RFM_Report', columns.value, rows);
    else await exportPdf(t('Customer_RFM_Report'), columns.value, rows);
  } finally {
    exporting.value = null;
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label { margin-bottom: 4px; color: rgba(0, 0, 0, 0.55); font-size: 13px; }
.kpi-card { border-radius: 10px; }
.kpi-inner { display: flex; align-items: center; gap: 12px; }
.kpi-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex: 0 0 auto; }
.kpi-text { min-width: 0; flex: 1 1 auto; }
.kpi-label { font-size: 12px; color: rgba(0, 0, 0, 0.45); margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kpi-value { font-size: 20px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
@media (max-width: 575px) { .kpi-value { font-size: 16px; } }
</style>
