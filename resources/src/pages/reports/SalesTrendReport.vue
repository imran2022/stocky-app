<template>
  <div class="page">
    <PageHeader :title="$t('Sales_Trend_Report')" :breadcrumb="[$t('Reports'), $t('Sales_Trend_Report')]" />

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
          <div class="filter-label">{{ $t('Granularity') }}</div>
          <a-segmented v-model:value="granularity" :options="granularityOptions" @change="crud.reload()" />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Heatmap_Metric') }}</div>
          <a-segmented v-model:value="heatMetric" :options="heatMetricOptions" />
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

    <!-- Trend chart -->
    <a-card size="small" class="chart-card" style="margin-bottom: 16px" :title="$t('Net_Sales_Trend')">
      <ReportChart :data="chartData" :fields="chartFields" :title="$t('Sales_Trend_Report')" type="area" x-key="period" :format="money" :height="300" />
    </a-card>

    <!-- Heatmap: weekday x 2-hour block -->
    <a-card size="small" style="margin-bottom: 16px" :title="$t('Peak_Hours_Heatmap')">
      <div class="heatmap" v-if="!crud.loading.value">
        <div class="heat-row heat-header">
          <div class="heat-corner"></div>
          <div v-for="b in hourBlocks" :key="b" class="heat-cell heat-label">{{ b }}–{{ b + 2 }}</div>
        </div>
        <div v-for="(wd, wi) in weekdayLabels" :key="wi" class="heat-row">
          <div class="heat-cell heat-label heat-row-label">{{ wd }}</div>
          <a-tooltip v-for="b in hourBlocks" :key="b" :title="cellTooltip(wi, b)">
            <div class="heat-cell heat-value" :style="{ background: cellColor(wi, b) }"></div>
          </a-tooltip>
        </div>
      </div>
      <a-spin v-else />
    </a-card>
  </div>
</template>

<script setup>
/**
 * Sales Trend Report (Build — 2026-09-25) — GET report/sales_trend
 * {trend, heatmap, kpis, warehouses}. Trend rows are already bucketed
 * server-side (day/week/month); the heatmap is weekday × 2-hour block
 * over the same window, colored by a single sequential hue (purple, the
 * app's own accent) — never a multi-hue "rainbow" scale.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import { FileTextOutlined, DollarOutlined, ShoppingCartOutlined, PercentageOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { money, date } = useFormat();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);
const granularity = ref('day');
const heatMetric = ref('net_sales');

const granularityOptions = [
  { value: 'day', label: t('Day') },
  { value: 'week', label: t('Week') },
  { value: 'month', label: t('Month') },
];
const heatMetricOptions = [
  { value: 'net_sales', label: t('Revenue') },
  { value: 'invoices', label: t('Invoices') },
];

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  warehouse_id: warehouseId.value || '',
  granularity: granularity.value,
});

// Payload: { trend, heatmap, kpis, warehouses }
const crud = useCrudTable('report/sales_trend', {
  rowsKey: 'trend',
  params: filterParams,
});

const warehouseOptions = computed(() => (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name })));

const kpiTiles = computed(() => {
  const k = crud.payload.value?.kpis || {};
  return [
    { label: t('Invoices'), value: k.invoices ?? 0, icon: FileTextOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Net_Sales'), value: money(k.net_sales ?? 0), icon: DollarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { label: t('Avg_Ticket'), value: money(k.avg_ticket ?? 0), icon: ShoppingCartOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
    { label: t('Discount'), value: money(k.discount ?? 0), icon: PercentageOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
  ];
});

/* ------------------------------------------------------------- trend chart */
const formatPeriod = p => (granularity.value === 'day' || granularity.value === 'week' ? date(p) : p);
const chartData = computed(() => (crud.payload.value?.trend || []).map(r => ({ ...r, period: formatPeriod(r.period) })));
const chartFields = computed(() => [{ key: 'net_sales', label: t('Net_Sales') }]);

/* --------------------------------------------------------------- heatmap */
const weekdayLabels = [t('Sun'), t('Mon'), t('Tue'), t('Wed'), t('Thu'), t('Fri'), t('Sat')];
const hourBlocks = [0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22];

const heatIndex = computed(() => {
  const idx = {};
  for (const c of crud.payload.value?.heatmap || []) idx[`${c.weekday}:${c.hour_block}`] = c;
  return idx;
});
const maxHeat = computed(() => {
  let max = 0;
  for (const c of crud.payload.value?.heatmap || []) max = Math.max(max, Number(c[heatMetric.value]) || 0);
  return max || 1;
});
function cellValue(weekday, block) {
  return Number(heatIndex.value[`${weekday}:${block}`]?.[heatMetric.value]) || 0;
}
function cellColor(weekday, block) {
  const v = cellValue(weekday, block);
  const t = Math.min(v / maxHeat.value, 1);
  if (t <= 0) return '#F1F5F9';
  // Single hue (purple, #6d28d9), light -> dark by intensity — sequential, never a rainbow.
  const light = 96 - t * 60; // 96% (near-white) down to 36%
  return `hsl(262, 60%, ${light}%)`;
}
function cellTooltip(weekday, block) {
  const c = heatIndex.value[`${weekday}:${block}`];
  if (!c) return `${weekdayLabels[weekday]} ${block}-${block + 2}h: ${t('No_Data')}`;
  return `${weekdayLabels[weekday]} ${block}-${block + 2}h — ${t('Invoices')}: ${c.invoices}, ${t('Net_Sales')}: ${money(c.net_sales)}`;
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

/* Heatmap grid */
.heatmap { overflow-x: auto; }
.heat-row { display: flex; }
.heat-cell {
  flex: 1 1 0;
  min-width: 44px;
  height: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  border-radius: 4px;
  margin: 2px;
}
.heat-corner { width: 46px; flex: none; }
.heat-label { color: rgba(0, 0, 0, 0.55); font-weight: 600; }
.heat-row-label { width: 46px; flex: none; justify-content: flex-start; }
.heat-value { cursor: default; }
</style>
