<template>
  <ReportPage
    :title="'Price Variance Report'"
    :breadcrumb="[$t('Reports'), 'Price Variance Report']"
    :crud="crud"
    :columns="columns"
    row-key="line_id"
  >
    <template #filters>
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ $t('Supplier') }}</div>
          <a-select
            v-model:value="filters.provider_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :options="supplierOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ 'Date From' }}</div>
          <a-date-picker v-model:value="filters.date_from" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ 'Date To' }}</div>
          <a-date-picker v-model:value="filters.date_to" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ 'Min Variance %' }}</div>
          <a-input-number v-model:value="filters.min_variance_percent" style="width: 100%" :min="0" placeholder="e.g. 5" @change="crud.reload()" />
        </a-col>
      </a-row>
    </template>

    <template #chart>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="k in kpiTiles" :key="k.label" :xs="12" :sm="8">
          <a-card size="small" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: k.tint, color: k.color }">
                <component :is="k.icon" />
              </div>
              <div class="kpi-text">
                <div class="kpi-label">{{ k.label }}</div>
                <div class="kpi-value" :style="k.style">{{ k.value }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'po_cost' || column.key === 'grn_cost'">{{ money(record[column.key]) }}</template>
      <template v-else-if="column.key === 'variance'">
        <strong :style="{ color: record.variance > 0 ? '#cf1322' : (record.variance < 0 ? '#3f8600' : undefined) }">
          {{ money(record.variance) }}
        </strong>
      </template>
      <template v-else-if="column.key === 'variance_percent'">
        <a-tag v-if="record.variance_percent !== null" :color="Math.abs(record.variance_percent) >= 10 ? 'error' : (Math.abs(record.variance_percent) >= 1 ? 'warning' : 'success')">
          {{ record.variance_percent > 0 ? '+' : '' }}{{ record.variance_percent }}%
        </a-tag>
        <span v-else class="muted">—</span>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { DollarOutlined, RiseOutlined, WarningOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();

const filters = ref({ provider_id: undefined, date_from: undefined, date_to: undefined, min_variance_percent: undefined });
const filterParams = () => ({ ...filters.value });

// Payload: { rows, totalRows, summary }
const crud = useCrudTable('purchase_orders_reports/price_variance', {
  rowsKey: 'rows',
  params: filterParams,
});

const supplierOptions = ref([]);
onMounted(async () => {
  crud.fetchRows();
  try {
    const data = await http.get('purchase_orders', { limit: 1 });
    supplierOptions.value = (data.suppliers || []).map(s => ({ value: s.id, label: s.name }));
  } catch (e) {
    supplierOptions.value = [];
  }
});

const summary = computed(() => crud.payload.value?.summary || { total_lines: 0, total_variance: 0, overcharged_count: 0 });

const kpiTiles = computed(() => [
  { label: 'Lines Compared', value: summary.value.total_lines, icon: DollarOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
  {
    label: 'Net Variance', value: money(summary.value.total_variance), icon: RiseOutlined,
    color: summary.value.total_variance > 0 ? '#cf1322' : '#10b981',
    tint: summary.value.total_variance > 0 ? 'rgba(207, 18, 34, 0.12)' : 'rgba(16, 185, 129, 0.12)',
    style: { color: summary.value.total_variance > 0 ? '#cf1322' : '#10b981' },
  },
  { label: 'Overcharged Lines', value: summary.value.overcharged_count, icon: WarningOutlined, color: '#f43f5e', tint: 'rgba(244, 63, 94, 0.12)' },
]);

const columns = computed(() => [
  { title: 'PO Ref', dataIndex: 'po_ref', key: 'po_ref' },
  { title: 'GRN Ref', dataIndex: 'grn_ref', key: 'grn_ref' },
  { title: t('date'), dataIndex: 'grn_date', key: 'grn_date', sorter: true },
  { title: t('Supplier'), dataIndex: 'supplier_name', key: 'supplier_name' },
  { title: t('ProductName'), dataIndex: 'product_name', key: 'product_name' },
  { title: 'PO Cost', dataIndex: 'po_cost', key: 'po_cost', align: 'right', exportValue: r => money(r.po_cost) },
  { title: 'GRN Cost', dataIndex: 'grn_cost', key: 'grn_cost', align: 'right', exportValue: r => money(r.grn_cost) },
  { title: t('Variance') || 'Variance', dataIndex: 'variance', key: 'variance', align: 'right', sorter: true, exportValue: r => money(r.variance) },
  { title: 'Variance %', dataIndex: 'variance_percent', key: 'variance_percent', align: 'right', sorter: true },
]);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: var(--text-secondary, #8c8c8c);
  margin-bottom: 4px;
}
.muted {
  color: var(--text-secondary, #8c8c8c);
}
.kpi-card { border-radius: 10px; }
.kpi-inner { display: flex; align-items: center; gap: 12px; }
.kpi-icon {
  width: 44px; height: 44px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex: 0 0 auto;
}
.kpi-text { min-width: 0; flex: 1 1 auto; }
.kpi-label {
  font-size: 12px; color: rgba(0, 0, 0, 0.45); margin-bottom: 2px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kpi-value {
  font-size: 20px; font-weight: 700;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
</style>
