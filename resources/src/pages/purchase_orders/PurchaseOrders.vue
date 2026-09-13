<template>
  <div class="page">
    <PageHeader :title="$t('PurchaseOrders') || 'Purchase Orders'" :breadcrumb="[$t('Purchases'), $t('PurchaseOrders') || 'Purchase Orders']">
      <template #actions>
        <a-space wrap>
          <a-button v-if="auth.can('purchase_orders')" type="primary" @click="$router.push('/purchase-orders/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('AddPurchaseOrder') || 'Add Purchase Order' }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Supplier') }}</div>
          <a-select
            v-model:value="filters.provider_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Supplier')" :options="supplierOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="6">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select
            v-model:value="filters.status" style="width: 100%" allow-clear
            :placeholder="$t('Choose_Status')" :options="statusOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Search') || 'Search' }}</div>
          <a-input
            v-model:value="filters.search" allow-clear
            :placeholder="$t('SearchByReferenceSupplier') || 'PO number or supplier'"
            @press-enter="crud.reload()" @change="onSearchChange"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'Ref'">
          <a @click="$router.push(`/purchase-orders/${record.id}`)">{{ record.Ref }}</a>
        </template>
        <template v-else-if="column.key === 'date'">{{ date(record.date) }}</template>
        <template v-else-if="column.key === 'expected_delivery_date'">
          {{ record.expected_delivery_date ? date(record.expected_delivery_date) : '—' }}
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="docStatusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'currency_code'">
          <a-tag v-if="record.currency_code" color="blue">{{ record.currency_code }}</a-tag>
        </template>
        <template v-else-if="column.key === 'GrandTotal'">{{ money(record.GrandTotal) }}</template>
        <template v-else-if="column.key === 'received_percent'">
          <a-progress
            v-if="record.received_percent !== null"
            :percent="record.received_percent"
            :size="'small'"
            :status="record.received_percent >= 100 ? 'success' : 'active'"
          />
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-dropdown :trigger="['click']">
            <a-button type="text" size="small">
              <template #icon><MoreOutlined style="font-size: 18px" /></template>
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => onAction(key, record)">
                <a-menu-item key="detail"><EyeOutlined /> {{ $t('View') || 'View' }}</a-menu-item>
                <a-menu-item v-if="auth.can('purchase_orders') && record.status !== 'received' && record.status !== 'cancelled'" key="edit">
                  <EditOutlined /> {{ $t('Edit') || 'Edit' }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('purchase_orders') && ['ordered', 'partially_received'].includes(record.status)" key="receive">
                  <InboxOutlined /> {{ $t('ReceiveGRN') || 'Receive (Create GRN)' }}
                </a-menu-item>
                <a-menu-item key="pdf"><FilePdfOutlined /> {{ $t('DownloadPdf') || 'Download PDF' }}</a-menu-item>
                <a-menu-item v-if="auth.can('purchase_orders')" key="email"><MailOutlined /> {{ $t('EmailToSupplier') || 'Email to Supplier' }}</a-menu-item>
                <a-menu-divider v-if="auth.can('purchase_orders')" />
                <a-menu-item v-if="auth.can('purchase_orders')" key="delete" danger>
                  <DeleteOutlined /> {{ $t('Delete') || 'Delete' }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import { ref, computed, createVNode } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, MoreOutlined, EyeOutlined, EditOutlined, DeleteOutlined,
  InboxOutlined, ExclamationCircleOutlined, FilePdfOutlined, MailOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { docStatusColor } from '../../lib/statusColors';
import http from '../../lib/http';

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const { money, date } = useFormat();

const filters = ref({ provider_id: undefined, warehouse_id: undefined, status: undefined, search: '' });
const filterParams = computed(() => ({ ...filters.value }));

let searchDebounce = null;
function onSearchChange() {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => crud.reload(), 400);
}

const crud = useCrudTable('purchase_orders', {
  rowsKey: 'purchase_orders',
  params: filterParams,
});
crud.fetchRows();

const supplierOptions = computed(() =>
  (crud.payload.value?.suppliers || []).map(s => ({ value: s.id, label: s.name }))
);
const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

// Kept local rather than a shared vocab file (purchaseVocab.js's
// PURCHASE_STATUSES is Purchase/GRN's own draft/pending/ordered/received/
// cancelled set — a PO's status values are a distinct, smaller set that
// includes the two auto-computed ones, so reusing that file would mean
// filtering it down anyway).
const PO_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'ordered', label: 'Ordered' },
  { value: 'partially_received', label: 'Partially Received' },
  { value: 'received', label: 'Received' },
  { value: 'cancelled', label: 'Cancelled' },
];
const statusOptions = computed(() => PO_STATUSES.map(s => ({ value: s.value, label: s.label })));
function statusLabel(value) {
  return PO_STATUSES.find(s => s.value === value)?.label || value;
}

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('ExpectedDelivery') || 'Expected Delivery', dataIndex: 'expected_delivery_date', key: 'expected_delivery_date' },
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Status'), dataIndex: 'status', key: 'status', sorter: true, exportValue: r => statusLabel(r.status) },
  ...(auth.multiCurrencyEnabled
    ? [{ title: t('Currency'), dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', defaultHidden: true }]
    : []),
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', sorter: true, align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Received') || 'Received', dataIndex: 'received_percent', key: 'received_percent', width: 160 },
]);

function onAction(key, record) {
  if (key === 'detail' || key === 'edit') {
    router.push(`/purchase-orders/${record.id}`);
    return;
  }
  if (key === 'receive') {
    // Hands off to the existing Create Purchase (GRN) form with the PO
    // pre-selected via query param — see PurchaseForm.vue's PO-select
    // integration, which reads this and auto-loads the PO's lines.
    router.push(`/purchases/create?po_id=${record.id}`);
    return;
  }
  if (key === 'pdf') {
    http.download(`purchase_orders/${record.id}/pdf`, `${record.Ref}.pdf`)
      .catch(() => message.error(t('InvalidData')));
    return;
  }
  if (key === 'email') {
    Modal.confirm({
      title: t('EmailToSupplier') || 'Email to Supplier',
      content: t('ConfirmEmailToSupplier') || `Send this Purchase Order to ${record.provider_name}?`,
      onOk: async () => {
        try {
          await http.post(`purchase_orders/${record.id}/send_email`);
          message.success(t('EmailSent') || 'Email sent successfully');
        } catch (e) {
          message.error(e?.response?.data?.message || t('SomethingWentWrong') || 'Something went wrong');
        }
      },
    });
    return;
  }
  if (key === 'delete') {
    Modal.confirm({
      title: t('AreYouSure') || 'Are you sure?',
      icon: createVNode(ExclamationCircleOutlined),
      okType: 'danger',
      onOk: async () => {
        try {
          await http.delete(`purchase_orders/${record.id}`);
          message.success(t('DeletedSuccessfully') || 'Deleted successfully');
          crud.fetchRows();
        } catch (e) {
          message.error(e?.response?.data?.message || t('SomethingWentWrong') || 'Something went wrong');
        }
      },
    });
  }
}
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
</style>
