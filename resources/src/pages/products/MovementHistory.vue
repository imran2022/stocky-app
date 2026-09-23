<template>
  <div class="movement-page">
    <PageHeader title="Movement History" :breadcrumb="['Products', 'Movement History']" />

    <section class="movement-intro">
      <div class="movement-intro-icon"><HistoryOutlined /></div>
      <div>
        <h2>Product Stock Movement</h2>
        <p>Trace every stock in and out with warehouse, document reference, customer or supplier, and running balance.</p>
      </div>
    </section>

    <a-card :bordered="false" class="filter-card">
      <div class="filter-grid">
        <div class="filter-field product-field">
          <label>Product</label>
          <a-select
            v-model:value="selectedProductId"
            show-search
            allow-clear
            :filter-option="false"
            :loading="searchingProducts"
            placeholder="Search item name, SKU or variant SKU"
            style="width: 100%"
            @search="queueProductSearch"
            @change="handleProductChange"
          >
            <a-select-option v-for="product in productOptions" :key="product.id" :value="product.id">
              <div class="product-option">
                <span>{{ product.name }}</span>
                <small>{{ product.code || 'No SKU' }}<template v-if="product.is_variant"> · Variable</template></small>
              </div>
            </a-select-option>
          </a-select>
        </div>

        <div class="filter-field">
          <label>Warehouse</label>
          <a-select
            v-model:value="selectedWarehouseId"
            allow-clear
            placeholder="All Warehouses"
            style="width: 100%"
          >
            <a-select-option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
              {{ warehouse.name }}
            </a-select-option>
          </a-select>
        </div>

        <div class="filter-field">
          <label>Variation</label>
          <a-select
            v-model:value="selectedVariantId"
            allow-clear
            :disabled="!selectedProduct?.is_variant"
            :placeholder="selectedProduct?.is_variant ? 'All Variations' : 'Not applicable'"
            style="width: 100%"
          >
            <a-select-option v-for="variant in selectedVariants" :key="variant.id" :value="variant.id">
              {{ variant.name }}<template v-if="variant.code"> — {{ variant.code }}</template>
            </a-select-option>
          </a-select>
        </div>

        <div class="filter-field date-field">
          <label>Date Range</label>
          <a-range-picker v-model:value="dateRange" style="width: 100%" />
        </div>

        <div class="filter-actions">
          <a-button @click="resetFilters">Reset</a-button>
          <a-button type="primary" :disabled="!selectedProductId" @click="applyFilters">
            View History
          </a-button>
        </div>
      </div>
    </a-card>

    <a-alert v-if="pageError" type="error" :message="pageError" show-icon style="margin-bottom: 16px" />

    <MovementHistoryCard
      v-if="applied.productId"
      :key="ledgerKey"
      :product-id="applied.productId"
      :product-variant-id="applied.variantId"
      :warehouse-id="applied.warehouseId"
      :date-from="applied.dateFrom"
      :date-to="applied.dateTo"
      :title="ledgerTitle"
      :show-filters="false"
      :show-summary="true"
      :show-export="true"
      class="ledger-card"
    />

    <a-card v-else :bordered="false" class="empty-card">
      <a-empty description="Select a product to view its complete movement history" />
    </a-card>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { HistoryOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import MovementHistoryCard from './MovementHistoryCard.vue';

const warehouses = ref([]);
const productOptions = ref([]);
const selectedProduct = ref(null);
const selectedProductId = ref(null);
const selectedWarehouseId = ref(null);
const selectedVariantId = ref(null);
const dateRange = ref([]);
const searchingProducts = ref(false);
const pageError = ref('');
const applied = reactive({
  productId: null,
  variantId: null,
  warehouseId: null,
  dateFrom: null,
  dateTo: null,
  productName: '',
  productCode: '',
  variantName: '',
});

let searchTimer = null;
let searchSequence = 0;

const selectedVariants = computed(() => selectedProduct.value?.variants || []);
const ledgerKey = computed(() => [
  applied.productId,
  applied.variantId || 'all',
  applied.warehouseId || 'all',
  applied.dateFrom || 'start',
  applied.dateTo || 'end',
].join('-'));
const ledgerTitle = computed(() => {
  if (!applied.productName) return 'Movement History';
  const variant = applied.variantName ? ` · ${applied.variantName}` : '';
  const code = applied.productCode ? ` (${applied.productCode})` : '';
  return `${applied.productName}${variant}${code}`;
});

async function fetchProducts(query = '') {
  const sequence = ++searchSequence;
  searchingProducts.value = true;
  try {
    const data = await http.get('products/search-basic', { q: query });
    if (sequence !== searchSequence) return;
    const options = data.products || [];
    if (selectedProduct.value && !options.some(product => product.id === selectedProduct.value.id)) {
      options.unshift(selectedProduct.value);
    }
    productOptions.value = options;
  } catch (error) {
    if (sequence === searchSequence) pageError.value = 'Could not search products.';
  } finally {
    if (sequence === searchSequence) searchingProducts.value = false;
  }
}

function queueProductSearch(value) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => fetchProducts((value || '').trim()), 280);
}

function handleProductChange(productId) {
  selectedProduct.value = productOptions.value.find(product => product.id === productId) || null;
  selectedVariantId.value = null;
}

function applyFilters() {
  if (!selectedProductId.value || !selectedProduct.value) return;
  const variant = selectedVariants.value.find(item => item.id === selectedVariantId.value);
  applied.productId = selectedProductId.value;
  applied.variantId = selectedVariantId.value;
  applied.warehouseId = selectedWarehouseId.value;
  applied.dateFrom = dateRange.value?.[0]?.format('YYYY-MM-DD') || null;
  applied.dateTo = dateRange.value?.[1]?.format('YYYY-MM-DD') || null;
  applied.productName = selectedProduct.value.name;
  applied.productCode = variant?.code || selectedProduct.value.code || '';
  applied.variantName = variant?.name || (selectedProduct.value.is_variant ? 'All Variations' : '');
}

function resetFilters() {
  selectedProductId.value = null;
  selectedProduct.value = null;
  selectedWarehouseId.value = null;
  selectedVariantId.value = null;
  dateRange.value = [];
  Object.assign(applied, {
    productId: null,
    variantId: null,
    warehouseId: null,
    dateFrom: null,
    dateTo: null,
    productName: '',
    productCode: '',
    variantName: '',
  });
  fetchProducts();
}

onMounted(async () => {
  try {
    const [meta] = await Promise.all([
      http.get('products/movement-history/meta'),
      fetchProducts(),
    ]);
    warehouses.value = meta.warehouses || [];
  } catch (error) {
    pageError.value = 'Could not load Movement History filters.';
  }
});

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<style scoped>
.movement-page {
  padding-bottom: 24px;
}
.movement-intro {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 20px;
  margin-bottom: 16px;
  color: #fff;
  border-radius: 12px;
  background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 55%, #8b5cf6 100%);
}
.movement-intro-icon {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  flex: 0 0 42px;
  font-size: 21px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.16);
}
.movement-intro h2 {
  margin: 0;
  color: inherit;
  font-size: 18px;
  font-weight: 700;
}
.movement-intro p {
  margin: 3px 0 0;
  font-size: 13px;
  opacity: 0.9;
}
.filter-card,
.empty-card,
.ledger-card {
  border-radius: 12px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 6px 18px rgba(15, 23, 42, 0.04);
}
.filter-card {
  margin-bottom: 16px;
}
.filter-grid {
  display: grid;
  grid-template-columns: minmax(260px, 1.45fr) minmax(180px, 0.85fr) minmax(200px, 1fr) minmax(260px, 1.15fr) auto;
  gap: 14px;
  align-items: end;
}
.filter-field label {
  display: block;
  margin-bottom: 7px;
  color: inherit;
  opacity: 0.72;
  font-size: 12px;
  font-weight: 650;
}
.product-option {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}
.product-option small {
  margin-top: 2px;
  opacity: 0.58;
}
.filter-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
.empty-card {
  padding: 34px 0;
}
.ledger-card {
  overflow: hidden;
}
@media (max-width: 1280px) {
  .filter-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .product-field,
  .filter-actions {
    grid-column: span 2;
  }
}
@media (max-width: 640px) {
  .movement-intro {
    align-items: flex-start;
    padding: 14px 16px;
  }
  .movement-intro p {
    line-height: 1.45;
  }
  .filter-grid {
    grid-template-columns: 1fr;
  }
  .product-field,
  .filter-actions {
    grid-column: auto;
  }
  .filter-actions > * {
    flex: 1;
  }
}
</style>
