<template>
  <div class="page">
    <PageHeader title="Zone / Courier Report" :breadcrumb="['Reports', 'Zone / Courier Report']" />

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap>
        <DateRangePicker v-model:value="range" allow-clear @change="load" />
        <a-select
          v-model:value="warehouseId" style="width: 200px" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('warehouse')" :options="warehouseOptions" @change="load"
        />
      </a-space>
    </a-card>

    <a-row :gutter="[16, 16]">
      <a-col :xs="24" :xl="12">
        <a-card size="small" title="By Zone" :loading="loading">
          <template #extra>
            <a-space>
              <a-button size="small" :loading="exporting === 'zone-xlsx'" @click="exportOne('zone', 'xlsx')">
                <template #icon><FileExcelOutlined /></template>
                Excel
              </a-button>
              <a-button size="small" :loading="exporting === 'zone-pdf'" @click="exportOne('zone', 'pdf')">
                <template #icon><FilePdfOutlined /></template>
                PDF
              </a-button>
            </a-space>
          </template>
          <a-table
            :columns="zoneColumns" :data-source="zones" :pagination="false" row-key="zone_id" size="small"
            :scroll="{ x: 'max-content' }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="['total', 'paid', 'due'].includes(column.key)">{{ money(record[column.key]) }}</template>
            </template>
            <template #emptyText><a-empty description="No data" style="padding: 24px 0" /></template>
          </a-table>
        </a-card>
      </a-col>

      <a-col :xs="24" :xl="12">
        <a-card size="small" title="By Courier" :loading="loading">
          <template #extra>
            <a-space>
              <a-button size="small" :loading="exporting === 'courier-xlsx'" @click="exportOne('courier', 'xlsx')">
                <template #icon><FileExcelOutlined /></template>
                Excel
              </a-button>
              <a-button size="small" :loading="exporting === 'courier-pdf'" @click="exportOne('courier', 'pdf')">
                <template #icon><FilePdfOutlined /></template>
                PDF
              </a-button>
            </a-space>
          </template>
          <a-table
            :columns="courierColumns" :data-source="couriers" :pagination="false" row-key="courier_id" size="small"
            :scroll="{ x: 'max-content' }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="['total', 'paid', 'due'].includes(column.key)">{{ money(record[column.key]) }}</template>
            </template>
            <template #emptyText><a-empty description="No data" style="padding: 24px 0" /></template>
          </a-table>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Zone / Courier Report — a variant of the Sales Report grouped by the
 * custom "Zone" and "Courier" fields (see Sales.vue / SaleForm.vue) instead
 * of listing individual sales. Backend: GET report/zone_wise (see
 * ReportController@zoneWiseReport) → { zones: [...], couriers: [...],
 * warehouses: [...] }, each row already totalled server-side.
 * Same permission as the Sales Report (Reports_sales).
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';

const { money } = useFormat();

const range = ref(null);
const warehouseId = ref(undefined);
const warehouseOptions = ref([]);

const zones = ref([]);
const couriers = ref([]);
const loading = ref(false);
const exporting = ref(null);

const zoneColumns = [
  { title: 'Zone', dataIndex: 'zone_name', key: 'zone_name', exportValue: r => r.zone_name },
  { title: 'Orders', dataIndex: 'orders', key: 'orders', align: 'right', exportValue: r => r.orders },
  { title: 'Total', dataIndex: 'total', key: 'total', align: 'right', exportValue: r => money(r.total) },
  { title: 'Paid', dataIndex: 'paid', key: 'paid', align: 'right', exportValue: r => money(r.paid) },
  { title: 'Due', dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
];

const courierColumns = [
  { title: 'Courier', dataIndex: 'courier_name', key: 'courier_name', exportValue: r => r.courier_name },
  { title: 'Orders', dataIndex: 'orders', key: 'orders', align: 'right', exportValue: r => r.orders },
  { title: 'Total', dataIndex: 'total', key: 'total', align: 'right', exportValue: r => money(r.total) },
  { title: 'Paid', dataIndex: 'paid', key: 'paid', align: 'right', exportValue: r => money(r.paid) },
  { title: 'Due', dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
];

async function load() {
  loading.value = true;
  try {
    const params = {
      warehouse_id: warehouseId.value || '',
      ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
      ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
    };
    const data = await http.get('report/zone_wise', params);
    zones.value = data.zones || [];
    couriers.value = data.couriers || [];
    if (!warehouseOptions.value.length) {
      warehouseOptions.value = (data.warehouses || []).map(w => ({ value: w.id, label: w.name }));
    }
  } catch (e) {
    message.error('Could not load the report.');
  } finally {
    loading.value = false;
  }
}

async function exportOne(which, kind) {
  exporting.value = `${which}-${kind}`;
  try {
    const cols = which === 'zone' ? zoneColumns : courierColumns;
    const rows = which === 'zone' ? zones.value : couriers.value;
    const title = which === 'zone' ? 'Sales by Zone' : 'Sales by Courier';
    if (kind === 'xlsx') await exportExcel(which === 'zone' ? 'sales_by_zone' : 'sales_by_courier', cols, rows);
    else await exportPdf(title, cols, rows);
  } catch (e) {
    message.error('Export failed.');
  } finally {
    exporting.value = null;
  }
}

onMounted(load);
</script>
