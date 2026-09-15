<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? ('Edit Purchase Order') : ('Add Purchase Order')"
      :breadcrumb="[$t('Purchases'), 'Purchase Orders', isEdit ? ($t('Edit') || 'Edit') : ($t('Add') || 'Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-alert
      v-else-if="isEdit && !po.is_editable"
      type="warning" show-icon style="margin-bottom: 16px"
      :message="'This Purchase Order can no longer be edited — it already has GRN receipts against it, or is cancelled.'"
    />

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="po.date" value-format="YYYY-MM-DD" style="width: 100%" :disabled="!editable" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="'Expected Delivery Date'">
              <a-date-picker v-model:value="po.expected_delivery_date" value-format="YYYY-MM-DD" style="width: 100%" :disabled="!editable" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Supplier')" required>
              <div style="display: flex; gap: 8px">
                <a-select
                  v-model:value="po.provider_id"
                  show-search option-filter-prop="label"
                  :placeholder="$t('Choose_Supplier')"
                  :options="supplierOptions"
                  :disabled="!editable"
                  style="flex: 1"
                />
                <a-tooltip :title="$t('Quick_Add_Supplier')">
                  <a-button :disabled="!editable" @click="quickAddSupplierOpen = true">
                    <template #icon><PlusOutlined /></template>
                  </a-button>
                </a-tooltip>
              </div>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('warehouse')" required>
              <a-select
                v-model:value="po.warehouse_id"
                show-search option-filter-prop="label"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouseOptions"
                :disabled="!editable || isEdit"
                @change="onWarehouseChange"
              />
            </a-form-item>
          </a-col>
          <a-col v-if="mcEnabled" :xs="24" :md="8">
            <a-form-item :label="$t('Currency')">
              <a-select v-model:value="docCurrencyId" show-search option-filter-prop="label" :options="currencyOptions" :disabled="!editable" />
            </a-form-item>
          </a-col>
          <a-col v-if="isEdit" :xs="24" :md="8">
            <a-form-item :label="$t('Status')">
              <a-select
                v-model:value="po.status"
                :options="manualStatusOptions"
                :disabled="!editable"
              />
              <div class="muted" style="margin-top: 4px">
                Partially Received / Received are set automatically once a GRN is received against this PO.
              </div>
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; gap: 8px; margin-bottom: 16px">
          <a-select
            v-model:value="searchValue"
            show-search
            :filter-option="filterProduct"
            :placeholder="$t('Scan_Search_Product_by_Code_Name')"
            style="flex: 1"
            :options="productOptions"
            :disabled="!editable || !po.warehouse_id"
            @select="onProductPicked"
          />
        </div>

        <a-table
          :columns="lineColumns"
          :data-source="lines"
          :pagination="false"
          size="middle"
          :row-key="(r) => r.detail_id"
          :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record, index }">
            <template v-if="column.key === 'product'">
              <div style="font-weight: 500">{{ record.name }}</div>
              <div class="muted">{{ record.code }}</div>
              <div v-if="record.last_purchase" class="muted" style="font-size: 12px">
                Last Purchase: {{ docMoney(record.last_purchase.cost) }} ({{ record.last_purchase.date }}<template v-if="record.last_purchase.supplier_name">, {{ record.last_purchase.supplier_name }}</template>)
              </div>
              <div v-if="record.received_quantity" class="muted" style="font-size: 12px">
                {{ 'Already received' }}: {{ record.received_quantity }} / {{ record.quantity }}
              </div>
            </template>
            <template v-else-if="column.key === 'cost'">
              <a-input-number
                :value="toDocAmt(record.cost)"
                :min="0" :precision="2" style="width: 110px"
                :disabled="!editable"
                @update:value="v => setCost(record, v)"
              />
            </template>
            <template v-else-if="column.key === 'quantity'">
              <a-input-number
                :value="record.quantity"
                :min="record.received_quantity || 0"
                style="width: 100px"
                :disabled="!editable"
                @update:value="v => setQty(record, v)"
              />
              <div v-if="record.received_quantity" class="muted" style="font-size: 11px">
                {{ 'Cannot go below already-received quantity' }}
              </div>
            </template>
            <template v-else-if="column.key === 'subtotal'">{{ docMoney(record.total) }}</template>
            <template v-else-if="column.key === 'actions'">
              <a-button v-if="editable" type="text" danger size="small" @click="removeLine(index)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </template>
          </template>
        </a-table>
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :md="12">
          <a-card size="small" style="margin-bottom: 16px">
            <a-form-item :label="$t('Notes')">
              <a-textarea v-model:value="po.notes" :rows="4" :disabled="!editable" :placeholder="'A few words...'" />
            </a-form-item>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-card size="small" style="margin-bottom: 16px">
            <a-descriptions :column="1" size="small">
              <a-descriptions-item :label="$t('Total')">
                <strong>{{ docMoney(grandTotal) }}</strong>
              </a-descriptions-item>
            </a-descriptions>
          </a-card>
        </a-col>
      </a-row>

      <a-space>
        <a-button v-if="editable" type="primary" :loading="saving" @click="submit">
          {{ isEdit ? ($t('Update') || 'Update') : ($t('Save') || 'Save') }}
        </a-button>
        <a-button @click="$router.push('/purchase-orders')">{{ $t('Cancel') || 'Cancel' }}</a-button>
      </a-space>
    </a-form>

    <QuickAddParty v-model:open="quickAddSupplierOpen" type="supplier" @created="onSupplierCreated" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import QuickAddParty from '../../components/QuickAddParty.vue';
import http from '../../lib/http';
import { useDocCurrency } from '../../composables/useDocCurrency';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const loadingRecord = ref(false);
const saving = ref(false);

const {
  enabled: mcEnabled,
  currencyId: docCurrencyId,
  options: currencyOptions,
  toDoc: toDocAmt,
  toBase: toBaseAmt,
  docMoney,
  loadCurrencies,
  setFromDocument: setDocCurrency,
  payloadFields: currencyPayload,
} = useDocCurrency();

const po = ref({
  date: dayjs().format('YYYY-MM-DD'),
  expected_delivery_date: null,
  provider_id: undefined,
  warehouse_id: undefined,
  status: 'ordered',
  notes: '',
  is_editable: true,
});

// Only draft/ordered are user-settable directly — partially_received/
// received are computed server-side from GRN receipts (see
// PurchaseOrderReceiptService) and intentionally excluded from this
// dropdown so a user can't set them by hand and have the next receipt
// silently overwrite their choice anyway.
const manualStatusOptions = [
  { value: 'draft', label: 'Draft' },
  { value: 'ordered', label: 'Ordered' },
  { value: 'cancelled', label: 'Cancelled' },
];

const editable = computed(() => !isEdit.value || po.value.is_editable);

const suppliers = ref([]);
const warehouses = ref([]);
const products = ref([]);
const lines = ref([]);
const searchValue = ref(null);
let nextDetailId = 1;

const supplierOptions = computed(() => suppliers.value.map(s => ({ value: s.id, label: s.name })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const productOptions = computed(() => products.value.map((p, i) => ({ value: i, label: `${p.code} — ${p.name}` })));

const quickAddSupplierOpen = ref(false);
function onSupplierCreated(supplier) {
  suppliers.value.push(supplier);
  po.value.provider_id = supplier.id;
}

function filterProduct(input, option) {
  return option.label.toLowerCase().includes(String(input).toLowerCase());
}

async function loadProducts() {
  if (!po.value.warehouse_id) return;
  try {
    products.value = await http.get(`get_Products_by_warehouse/${po.value.warehouse_id}`, { stock: 0, product_service: 0 }) || [];
  } catch (e) {
    products.value = [];
    message.error(t('InvalidData'));
  }
}

function onWarehouseChange() {
  loadProducts();
}

async function onProductPicked(idx) {
  searchValue.value = null;
  const p = products.value[idx];
  if (!p) return;
  const variantId = p.product_variant_id ?? 0;

  const existing = lines.value.find(l => l.product_id === p.id && (l.product_variant_id ?? 0) === (variantId || 0));
  if (existing) {
    setQty(existing, Number(existing.quantity) + 1);
    return;
  }

  try {
    const d = await http.get(`show_product_data/${p.id}/${variantId}/${po.value.warehouse_id}`);
    const line = {
      detail_id: nextDetailId++,
      product_id: d.id,
      product_variant_id: p.product_variant_id ?? null,
      purchase_order_detail_id: null,
      code: d.code || p.code,
      name: d.name,
      cost: d.Unit_cost ?? d.cost ?? 0,
      quantity: 1,
      received_quantity: 0,
      total: 0,
      last_purchase: d.last_purchase || null,
    };
    recomputeLine(line);
    lines.value.push(line);
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

function recomputeLine(line) {
  line.total = Number(line.cost || 0) * Number(line.quantity || 0);
}

function setCost(line, value) {
  let docCost = Number(value);
  if (Number.isNaN(docCost) || docCost < 0) docCost = 0;
  line.cost = toBaseAmt(docCost);
  recomputeLine(line);
}

function setQty(line, value) {
  let qty = Number(value);
  if (Number.isNaN(qty)) qty = 0;
  // Never allow shrinking below what's already been received against this
  // line (only relevant when editing — new lines have received_quantity 0).
  const floor = Number(line.received_quantity || 0);
  if (qty < floor) qty = floor;
  line.quantity = qty;
  recomputeLine(line);
}

function removeLine(index) {
  lines.value.splice(index, 1);
}

const grandTotal = computed(() => lines.value.reduce((sum, l) => sum + Number(l.total || 0), 0));

const lineColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Net_Unit_Cost') || 'Unit Cost', key: 'cost', align: 'right' },
  { title: t('Quantity'), key: 'quantity', align: 'center' },
  { title: t('SubTotal'), key: 'subtotal', align: 'right' },
  { title: t('Action'), key: 'actions', width: 60, align: 'center' },
]);

async function loadSuppliersAndWarehouses() {
  const data = await http.get('purchase_orders', { limit: 1 });
  suppliers.value = data.suppliers || [];
  warehouses.value = data.warehouses || [];
}

async function loadRecord() {
  loadingRecord.value = true;
  try {
    const data = await http.get(`purchase_orders/${route.params.id}`);
    po.value = { ...data.purchase_order };
    setDocCurrency(data.purchase_order.currency_id, data.purchase_order.exchange_rate);
    lines.value = (data.details || []).map(d => ({
      detail_id: nextDetailId++,
      product_id: d.product_id,
      product_variant_id: d.product_variant_id,
      purchase_order_detail_id: d.id,
      code: d.code,
      name: d.name,
      cost: d.cost,
      quantity: d.quantity,
      received_quantity: d.received_quantity,
      total: d.total,
      last_purchase: null,
    }));
    if (po.value.warehouse_id) await loadProducts();
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/purchase-orders');
  } finally {
    loadingRecord.value = false;
  }
}

function buildPayload() {
  return {
    provider_id: po.value.provider_id,
    warehouse_id: po.value.warehouse_id,
    date: po.value.date,
    expected_delivery_date: po.value.expected_delivery_date,
    status: po.value.status,
    notes: po.value.notes,
    GrandTotal: grandTotal.value,
    ...currencyPayload.value,
    details: lines.value.map(l => ({
      product_id: l.product_id,
      product_variant_id: l.product_variant_id,
      cost: l.cost,
      quantity: l.quantity,
      subtotal: l.total,
    })),
  };
}

async function submit() {
  if (!po.value.provider_id || !po.value.warehouse_id) {
    message.warning('Please fill in all required fields');
    return;
  }
  if (lines.value.length === 0) {
    message.warning('Add at least one product');
    return;
  }

  saving.value = true;
  try {
    if (isEdit.value) {
      await http.put(`purchase_orders/${route.params.id}`, buildPayload());
      message.success('Updated successfully');
    } else {
      await http.post('purchase_orders', buildPayload());
      message.success('Saved successfully');
    }
    router.push('/purchase-orders');
  } catch (e) {
    message.error(e?.response?.data?.message || 'Something went wrong');
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  await loadCurrencies();
  await loadSuppliersAndWarehouses();
  if (isEdit.value) {
    await loadRecord();
  }
});
</script>

<style scoped>
.muted {
  color: var(--text-secondary, #8c8c8c);
}
</style>
