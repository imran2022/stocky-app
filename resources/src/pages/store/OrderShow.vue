<template>
  <div class="page">
    <PageHeader :title="code || $t('Order')" :breadcrumb="[$t('Store'), $t('Online_Orders'), code || '']">
      <template #extra>
        <a-space wrap>
          <a-tooltip v-if="order.is_flagged" :title="order.flag_reason">
            <a-tag color="error"><FlagOutlined /> {{ $t('Flagged') }}</a-tag>
          </a-tooltip>
          <a-tag :color="statusColor(order.status)" style="text-transform: uppercase">{{ order.status }}</a-tag>
          <a-tag v-if="order.has_preorder_items" color="warning">{{ $t('HasPreorderItems') }}</a-tag>
          <a-button v-if="order.status === 'confirmed'" :loading="actionBusy" @click="changeStatus('shipped')">
            {{ $t('Mark_Shipped') }}
          </a-button>
          <a-button v-if="order.status === 'shipped'" :loading="actionBusy" @click="changeStatus('delivered')">
            {{ $t('Mark_Delivered') }}
          </a-button>
          <a-button @click="downloadInvoice">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('Download_Invoice') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="[16, 16]">
      <a-col :xs="24" :lg="14">
        <a-card size="small" :title="$t('Items')" :body-style="{ padding: 0 }">
          <a-table
            :columns="itemColumns" :data-source="order.items"
            :pagination="false" size="small" row-key="id"
            :scroll="{ x: 'max-content' }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                {{ record.name }}
                <a-tag v-if="record.is_preorder" color="warning">{{ $t('PreOrder') }}</a-tag>
              </template>
              <template v-else-if="column.key === 'price'">{{ currency(record.price) }}</template>
              <template v-else-if="column.key === 'total'">{{ currency(record.price * record.qty) }}</template>
            </template>
          </a-table>
        </a-card>
      </a-col>

      <a-col :xs="24" :lg="10">
        <a-card size="small" :title="$t('Customer')" style="margin-bottom: 16px">
          <div class="muted">{{ order.customer_name }}</div>
          <div class="muted">{{ order.customer_email }}</div>
          <div class="muted">{{ order.customer_phone }}</div>
        </a-card>

        <a-card size="small" :title="$t('Shipping')" style="margin-bottom: 16px">
          <div class="muted">{{ order.customer_phone || '-' }}</div>
          <div class="muted">{{ order.customer_address || '-' }}</div>
        </a-card>

        <a-card size="small" :title="$t('warehouse')" style="margin-bottom: 16px">
          <div class="muted">{{ order.warehouse_name || '-' }}</div>
        </a-card>

        <a-card size="small" :title="$t('Payment')" style="margin-bottom: 16px">
          <div class="line">
            <span class="muted">{{ $t('Method') }}</span>
            <a-tag :color="paymentMethodColor(order.payment_method)">{{ paymentMethodLabel(order.payment_method) }}</a-tag>
          </div>
          <div class="line">
            <span class="muted">{{ $t('Status') }}</span>
            <a-tag :color="order.payment_status === 'paid' ? 'success' : 'warning'">{{ order.payment_status || 'pending' }}</a-tag>
          </div>
        </a-card>

        <a-card size="small" title="Summary">
          <div class="line"><span>{{ $t('Subtotal') }}</span><strong>{{ currency(order.subtotal) }}</strong></div>
          <div v-if="Number(order.shipping || 0) > 0" class="line">
            <span>{{ $t('Shipping') }}</span><strong>{{ currency(order.shipping || 0) }}</strong>
          </div>
          <div v-if="Number(order.discount || 0) > 0" class="line">
            <span>{{ $t('Discount') }}</span><strong>-{{ currency(order.discount || 0) }}</strong>
          </div>
          <a-divider style="margin: 8px 0" />
          <div class="line"><span>{{ $t('Total') }}</span><strong>{{ currency(order.total) }}</strong></div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Online order details — GET store/orders/{id} (flat order object with
 * items). Fulfilment transitions PATCH store/orders/{id} {status}; invoice
 * blob GET store/orders/{id}/invoice.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { FlagOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const auth = useAuthStore();

const id = route.params.id;
const loading = ref(true);
const actionBusy = ref(false);
const order = ref({ items: [], status: 'pending' });
const code = ref('');

const itemColumns = computed(() => [
  { title: t('ProductName'), key: 'name' },
  { title: t('Qty'), dataIndex: 'qty', key: 'qty', align: 'center' },
  { title: t('Price'), key: 'price', align: 'right' },
  { title: t('Total'), key: 'total', align: 'right' },
]);

function currency(n) {
  const c = auth.currency;
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).format(n || 0);
  } catch (e) {
    return `${c} ${Number(n || 0).toFixed(2)}`;
  }
}
function statusColor(s) {
  return { pending: 'warning', confirmed: 'success', shipped: 'cyan', delivered: 'blue', cancelled: 'error' }[s] || 'default';
}
function paymentMethodLabel(m) {
  return { credit_card: t('CreditCard'), paypal: 'PayPal', paystack: 'Paystack', flutterwave: 'Flutterwave', razorpay: 'Razorpay', mobile_money: t('MobileMoney'), cod: t('CashOnDelivery') }[m] || m || 'N/A';
}
function paymentMethodColor(m) {
  return { credit_card: 'blue', paypal: 'geekblue', paystack: 'cyan', flutterwave: 'orange', razorpay: 'blue', mobile_money: 'cyan', cod: 'default' }[m] || 'default';
}

async function changeStatus(status) {
  actionBusy.value = true;
  try {
    await http.patch(`store/orders/${id}`, { status });
    order.value.status = status;
    message.success(t('Status_updated'));
  } catch (e) {
    message.error(e?.data?.error || t('Failed'));
  } finally {
    actionBusy.value = false;
  }
}
async function downloadInvoice() {
  try {
    await http.download(`store/orders/${id}/invoice`, `invoice-${code.value || id}.pdf`);
  } catch (e) {
    message.error(t('Failed'));
  }
}

onMounted(async () => {
  try {
    const data = await http.get(`store/orders/${id}`);
    order.value = data || { items: [], status: 'pending' };
    code.value = data && (data.code || `#${data.id}`);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.55);
}
.line {
  display: flex;
  justify-content: space-between;
  margin-bottom: 6px;
}
</style>
