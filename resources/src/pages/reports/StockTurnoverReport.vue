<template>
  <div class="page">
    <PageHeader :title="$t('Stock_Turnover_Report')" :breadcrumb="[$t('Reports'), $t('Stock_Turnover_Report')]">
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
          <div class="filter-label">{{ $t('date') }}</div>
          <DateRangePicker v-model:value="range" style="width: 100%" :allow-clear="false" @change="crud.reload()" />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="warehouseId" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :options="warehouseOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Categorie') }}</div>
          <a-select
            v-model:value="categoryId" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :options="categoryOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Movement') }}</div>
          <a-select v-model:value="movement" style="width: 100%" allow-clear :options="movementOptions" @change="crud.reload()" />
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

    <!-- Table -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div style="font-weight: 500">{{ record.name }}</div>
          <div style="font-size: 12px; color: rgba(0,0,0,.45)">{{ record.code }}</div>
        </template>
        <template v-else-if="column.key === 'stock_value'">{{ money(record.stock_value) }}</template>
        <template v-else-if="column.key === 'cogs'">{{ money(record.cogs) }}</template>
        <template v-else-if="column.key === 'days_of_inventory'">
          {{ record.days_of_inventory === null ? '—' : record.days_of_inventory }}
        </template>
        <template v-else-if="column.key === 'movement'">
          <a-tag :color="MOVEMENT_COLOR[record.movement] || 'default'">{{ $t(record.movement.replace(' ', '_')) }}</a-tag>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Stock Turnover Report (Build — 2026-09-25) — GET report/stock_turnover
 * {rows, totalRows, kpis, warehouses, categories}. Turnover = period COGS
 * ÷ CURRENT stock value (an accepted estimate — see the controller's own
 * doc note); "Days of Inventory" is null when there's no COGS in the
 * window (nothing to divide by) or no stock at all.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import { FilePdfOutlined, FileExcelOutlined, InboxOutlined, DollarOutlined, HourglassOutlined, ThunderboltOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';
import DateRangePicker from '../../components/DateRangePicker.vue';

const { t } = useI18n();
const { money } = useFormat();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);
const categoryId = ref(undefined);
const movement = ref(undefined);

const MOVEMENT_COLOR = { Fast: 'green', Normal: 'blue', Slow: 'orange', Dead: 'red', 'Out of stock': 'default' };
const movementOptions = ['Fast', 'Normal', 'Slow', 'Dead', 'Out of stock'].map(v => ({ value: v, label: t(v.replace(' ', '_')) }));

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  warehouse_id: warehouseId.value || '',
  category_id: categoryId.value || '',
  movement: movement.value || '',
});

// Payload: { rows, totalRows, kpis, warehouses, categories }
const crud = useCrudTable('report/stock_turnover', {
  rowsKey: 'rows',
  sortField: 'turnover',
  sortType: 'desc',
  params: filterParams,
});

const warehouseOptions = computed(() => (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name })));
const categoryOptions = computed(() => (crud.payload.value?.categories || []).map(c => ({ value: c.id, label: c.name })));

const kpiTiles = computed(() => {
  const k = crud.payload.value?.kpis || {};
  return [
    { label: t('Products'), value: k.products ?? 0, icon: InboxOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Stock_Value'), value: money(k.stock_value ?? 0), icon: DollarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { label: t('Fast'), value: k.fast ?? 0, icon: ThunderboltOutlined, color: '#16a34a', tint: 'rgba(22, 163, 74, 0.12)' },
    { label: t('Dead'), value: k.dead ?? 0, icon: HourglassOutlined, color: '#dc2626', tint: 'rgba(220, 38, 38, 0.12)' },
  ];
});

const columns = computed(() => [
  { title: t('ProductName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Categorie'), dataIndex: 'category', key: 'category' },
  { title: t('Stock_Qty'), dataIndex: 'qty', key: 'qty', align: 'right', sum: true },
  { title: t('Stock_Value'), dataIndex: 'stock_value', key: 'stock_value', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.stock_value) },
  { title: t('Qty_Sold'), dataIndex: 'qty_sold', key: 'qty_sold', align: 'right', sum: true },
  { title: t('COGS'), dataIndex: 'cogs', key: 'cogs', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.cogs) },
  { title: t('Turnover_Ratio'), dataIndex: 'turnover', key: 'turnover', sorter: true, align: 'right' },
  { title: t('Days_Of_Inventory'), dataIndex: 'days_of_inventory', key: 'days_of_inventory', sorter: true, align: 'right', exportValue: r => r.days_of_inventory === null ? '—' : r.days_of_inventory },
  { title: t('Movement'), dataIndex: 'movement', key: 'movement' },
]);

const exporting = ref(null);
async function allRows() {
  const data = await http.get('report/stock_turnover', { ...filterParams(), page: 1, limit: -1, search: crud.search.value });
  return data.rows || [];
}
async function exportList(kind) {
  exporting.value = kind;
  try {
    const rows = await allRows();
    if (kind === 'xlsx') await exportExcel('Stock_Turnover_Report', columns.value, rows);
    else await exportPdf(t('Stock_Turnover_Report'), columns.value, rows);
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
