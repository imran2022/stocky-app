<template>
  <div class="page">
    <PageHeader title="Purchase Order Detail" :breadcrumb="['Purchase Orders', 'Detail']">
      <template #actions>
        <a-space v-if="!loading" wrap>
          <a-button @click="$router.push('/purchase-orders')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button
            v-if="auth.can('purchase_orders') && po.is_editable"
            @click="$router.push(`/purchase-orders/${po.id}`)"
          >
            <template #icon><EditOutlined /></template>
            {{ $t('Edit') || 'Edit' }}
          </a-button>
          <a-button
            v-if="auth.can('purchase_orders') && ['ordered', 'partially_received'].includes(po.status)"
            type="primary"
            @click="$router.push(`/purchases/create?po_id=${po.id}`)"
          >
            <template #icon><InboxOutlined /></template>
            Receive (Create GRN)
          </a-button>
          <a-button :loading="downloadingPdf" @click="downloadPdf">
            <template #icon><FilePdfOutlined /></template>
            PDF
          </a-button>
          <a-button :loading="sendingEmail" @click="sendEmail">
            <template #icon><MailOutlined /></template>
            Email to Supplier
          </a-button>
          <a-button @click="printPo">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else id="po-detail-card">
      <div class="inv-head">
        <div>
          <img
            v-if="company.logo"
            :src="'/images/' + company.logo"
            :style="{
              maxWidth: (company.invoice_logo_width || 180) + 'px',
              maxHeight: (company.invoice_logo_height || 60) + 'px',
            }"
            alt=""
          />
        </div>
        <div>
          <div class="inv-ref-badge">{{ po.Ref }}</div>
          <table class="inv-meta">
            <tr><td>{{ $t('date') }}</td><td>{{ date(po.date) }}</td></tr>
            <tr><td>Expected Delivery</td><td>{{ po.expected_delivery_date ? date(po.expected_delivery_date) : '—' }}</td></tr>
            <tr>
              <td>{{ $t('Status') }}</td>
              <td><a-tag :color="docStatusColor(po.status)">{{ statusLabel(po.status) }}</a-tag></td>
            </tr>
          </table>
        </div>
      </div>

      <a-row :gutter="[16, 16]" style="margin: 16px 0">
        <a-col :xs="24" :md="12">
          <div class="inv-box">
            <div class="inv-box-title">Supplier</div>
            <div class="inv-box-name">{{ po.provider_name }}</div>
            <div v-if="po.provider_phone">{{ $t('Phone') }}: {{ po.provider_phone }}</div>
            <div v-if="po.provider_email">{{ $t('Email') }}: {{ po.provider_email }}</div>
            <div v-if="po.provider_address">{{ $t('Adress') }}: {{ po.provider_address }}</div>
          </div>
        </a-col>
        <a-col :xs="24" :md="12">
          <div class="inv-box">
            <div class="inv-box-title">Deliver To</div>
            <div class="inv-box-name">{{ po.warehouse_name }}</div>
          </div>
        </a-col>
      </a-row>

      <a-table
        :columns="itemColumns"
        :data-source="details"
        :pagination="false"
        size="middle"
        :row-key="(r) => r.id"
        :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'product'">
            <div style="font-weight: 500">{{ record.name }}</div>
            <div class="muted">{{ record.code }}</div>
          </template>
          <template v-else-if="column.key === 'cost'">{{ money(record.cost) }}</template>
          <template v-else-if="column.key === 'quantity'">{{ record.quantity }}</template>
          <template v-else-if="column.key === 'received'">
            {{ record.received_quantity }} / {{ record.quantity }}
            <a-tag v-if="record.remaining_quantity <= 0" color="success" style="margin-left: 4px">Complete</a-tag>
          </template>
          <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
        </template>
      </a-table>

      <div class="inv-summary">
        <table>
          <tr><td>Subtotal:</td><td>{{ money(subtotal) }}</td></tr>
          <tr><td>Order Tax:</td><td>{{ money(po.TaxNet) }}</td></tr>
          <tr v-if="Number(po.discount) > 0">
            <td>Discount:</td>
            <td class="neg">- {{ money(po.discount) }}</td>
          </tr>
          <tr><td>Shipping:</td><td>{{ money(po.shipping) }}</td></tr>
          <tr class="grand"><td>{{ $t('Total') }}:</td><td>{{ money(po.GrandTotal) }}</td></tr>
        </table>
      </div>

      <div v-if="po.notes" class="inv-footer" style="text-align: left">
        <strong>Notes:</strong> {{ po.notes }}
      </div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Read-only View for a Purchase Order — mirrors PurchaseDetails.vue's
 * structure/styling exactly (see that file) so POs and GRNs have a
 * visually consistent detail page. Deliberately separate from
 * PurchaseOrderForm.vue (edit): before this page existed, the list's
 * "View" action incorrectly routed to the edit form, since there was no
 * dedicated read-only page — this file is that missing page, not a
 * variant of the edit form.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, EditOutlined, InboxOutlined, FilePdfOutlined,
  MailOutlined, PrinterOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { docStatusColor } from '../../lib/statusColors';
import http from '../../lib/http';

const { t } = useI18n();
const { money, date } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const po = ref({});
const details = ref([]);
const company = ref({});
const downloadingPdf = ref(false);
const sendingEmail = ref(false);

const PO_STATUS_LABELS = {
  draft: 'Draft',
  ordered: 'Ordered',
  partially_received: 'Partially Received',
  received: 'Received',
  cancelled: 'Cancelled',
};
function statusLabel(value) {
  return PO_STATUS_LABELS[value] || value;
}

const subtotal = computed(() =>
  details.value.reduce((sum, d) => sum + (Number(d?.total) || 0), 0)
);

const itemColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Net_Unit_Cost') || 'Unit Cost', key: 'cost', align: 'right' },
  { title: t('Quantity'), key: 'quantity', align: 'right' },
  { title: 'Received', key: 'received', align: 'center' },
  { title: t('Total'), key: 'total', align: 'right' },
]);

function printPo() {
  window.print();
}

async function downloadPdf() {
  downloadingPdf.value = true;
  try {
    await http.download(`purchase_orders/${po.value.id}/pdf`, `${po.value.Ref}.pdf`);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    downloadingPdf.value = false;
  }
}

async function sendEmail() {
  sendingEmail.value = true;
  try {
    await http.post(`purchase_orders/${po.value.id}/send_email`);
    message.success('Email sent successfully');
  } catch (e) {
    message.error(e?.response?.data?.message || 'Something went wrong');
  } finally {
    sendingEmail.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get(`purchase_orders/${route.params.id}`);
    po.value = data.purchase_order || {};
    details.value = data.details || [];
    company.value = data.company || {};
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/purchase-orders');
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.inv-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
}
.inv-ref-badge {
  display: inline-block;
  background: #6d28d9;
  color: #fff;
  font-weight: 600;
  padding: 4px 14px;
  border-radius: 6px;
  margin-bottom: 8px;
}
.inv-meta td {
  padding: 2px 8px 2px 0;
  color: rgba(0, 0, 0, 0.65);
  font-size: 13px;
}
.inv-meta td:first-child {
  font-weight: 500;
}
.inv-box {
  border: 1px solid rgba(5, 5, 5, 0.08);
  border-radius: 8px;
  padding: 12px 16px;
  height: 100%;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.65);
}
.inv-box-title {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 4px;
}
.inv-box-name {
  font-weight: 600;
  font-size: 15px;
  color: rgba(0, 0, 0, 0.88);
  margin-bottom: 4px;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.inv-summary {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
.inv-summary table {
  min-width: 320px;
  border-collapse: collapse;
}
.inv-summary td {
  padding: 6px 8px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
  font-size: 13px;
}
.inv-summary td:last-child {
  text-align: right;
  font-weight: 500;
}
.inv-summary .neg {
  color: #ff4d4f;
}
.inv-summary .grand td {
  font-size: 15px;
  font-weight: 700;
  border-top: 2px solid rgba(5, 5, 5, 0.15);
}
.inv-footer {
  margin-top: 24px;
  padding-top: 12px;
  border-top: 1px dashed rgba(5, 5, 5, 0.15);
  color: rgba(0, 0, 0, 0.45);
  font-size: 13px;
}

@media print {
  :global(.ant-layout-sider),
  :global(.ant-layout-header),
  :global(.page-header) {
    display: none !important;
  }
  #po-detail-card {
    box-shadow: none !important;
    border: none !important;
  }
}
</style>
