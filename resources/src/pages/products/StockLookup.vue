<template>
  <div class="page">
    <PageHeader title="Stock Lookup" :breadcrumb="['Products', 'Stock Lookup']" />

    <div class="lookup-hero">
      <div class="lookup-hero-icon"><InboxOutlined /></div>
      <div style="flex: 1">
        <div class="lookup-hero-title">Where is it?</div>
        <div class="lookup-hero-sub">Search by SKU, barcode, or product name to see stock across every warehouse.</div>
      </div>
    </div>

    <a-input-search
      v-model:value="query"
      placeholder="e.g. BB10 or Bag"
      size="large"
      :loading="searching"
      enter-button="Search"
      allow-clear
      style="margin-bottom: 20px"
      @search="runSearch"
    />

    <a-alert v-if="error" type="error" :message="error" show-icon style="margin-bottom: 16px" />

    <!-- Multiple matches: pick one -->
    <a-card v-if="!detail && results.length > 1" size="small" title="Matching products" :bordered="false" class="lookup-card">
      <div
        v-for="r in results" :key="r.id"
        class="lookup-row"
        @click="selectProduct(r.id)"
      >
        <div>
          <div style="font-weight: 600">{{ r.name }}</div>
          <div class="muted">SKU: {{ r.code }}</div>
        </div>
        <div style="font-weight: 600; color: #6d28d9">{{ priceLabel(r) }}</div>
      </div>
    </a-card>

    <a-empty
      v-if="!detail && !searching && searched && results.length === 0"
      description="No matching products"
      style="padding: 48px 0"
    />

    <!-- Selected product: per-warehouse breakdown -->
    <a-card v-if="loadingDetail" :bordered="false" class="lookup-card" style="min-height: 160px" :loading="true" />
    <a-card v-else-if="detail" :bordered="false" class="lookup-card">
      <div class="lookup-product-head">
        <img
          v-if="detail.product.image"
          :src="'/images/' + detail.product.image"
          class="lookup-product-img"
        />
        <div v-else class="lookup-product-img lookup-product-img-placeholder"><PictureOutlined /></div>
        <div>
          <div style="font-weight: 700; font-size: 19px">{{ detail.product.name }}</div>
          <a-space style="margin-top: 8px">
            <a-tag>SKU: {{ detail.product.code }}</a-tag>
            <a-tag color="purple">{{ priceLabel(detail.product) }}</a-tag>
            <a-tag v-if="detail.product.is_variant" color="blue">{{ detail.warehouses[0]?.variants?.length || 0 }} variants</a-tag>
          </a-space>
        </div>
        <div class="lookup-total-badge">
          <div class="lookup-total-badge-num">{{ detail.total_qty }}</div>
          <div class="lookup-total-badge-label">Total {{ detail.product.unit_label }}</div>
        </div>
      </div>

      <div style="font-weight: 600; margin: 20px 0 10px">Stock by Warehouse</div>
      <a-table
        :columns="warehouseColumns"
        :data-source="detail.warehouses"
        :pagination="false"
        row-key="id"
        size="small"
        :expandable="detail.product.is_variant ? { rowExpandable: () => true } : undefined"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'location'">
            <div style="font-weight: 500">{{ record.name }}</div>
            <div v-if="record.location" class="muted">{{ record.location }}</div>
          </template>
          <template v-else-if="column.key === 'qty'">
            <a-tag :color="record.qty > 0 ? 'green' : 'red'">{{ record.qty }} {{ detail.product.unit_label }}</a-tag>
          </template>
        </template>
        <template v-if="detail.product.is_variant" #expandedRowRender="{ record }">
          <a-table
            :columns="variantColumns"
            :data-source="record.variants"
            :pagination="false"
            row-key="id"
            size="small"
          >
            <template #bodyCell="{ column, record: v }">
              <template v-if="column.key === 'price'">{{ money(v.price) }}</template>
              <template v-else-if="column.key === 'qty'">
                <a-tag :color="v.qty > 0 ? 'green' : 'red'">{{ v.qty }} {{ detail.product.unit_label }}</a-tag>
              </template>
            </template>
          </a-table>
        </template>
        <template #summary>
          <a-table-summary-row>
            <a-table-summary-cell>
              <div style="font-weight: 700">Total Stock</div>
              <div class="muted">Across all warehouses</div>
            </a-table-summary-cell>
            <a-table-summary-cell>
              <a-tag color="purple">{{ detail.total_qty }} {{ detail.product.unit_label }}</a-tag>
            </a-table-summary-cell>
          </a-table-summary-row>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Stock Lookup — search a product by SKU/barcode/name, see how much is in
 * stock at every warehouse in one view. For a multi-warehouse business this
 * answers "where is it" without opening the product record and hunting for
 * the warehouse-stock table there.
 *
 * Variant products: the backend returns each warehouse row's OWN total qty
 * (summed across that product's variants at that warehouse) plus a
 * `variants` array for that row — rendered here as an a-table expandable
 * row, so the base view stays one-row-per-warehouse and the variant detail
 * is opt-in per warehouse rather than a huge warehouse×variant grid.
 *
 * Backend: GET stock_lookup/search?search=... -> {results}, then
 * GET stock_lookup/{id} -> {product, warehouses, total_qty}.
 */
import { ref } from 'vue';
import { InboxOutlined, PictureOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { money } = useFormat();

const query = ref('');
const results = ref([]);
const searching = ref(false);
const searched = ref(false);
const error = ref('');

const detail = ref(null);
const loadingDetail = ref(false);

const warehouseColumns = [
  { title: 'Warehouse / Location', key: 'location' },
  { title: 'Available Stock', key: 'qty', align: 'right' },
];
const variantColumns = [
  { title: 'Variant', dataIndex: 'name', key: 'name' },
  { title: 'Code', dataIndex: 'code', key: 'code' },
  { title: 'Price', key: 'price', align: 'right' },
  { title: 'Stock', key: 'qty', align: 'right' },
];

function priceLabel(product) {
  if (!product?.is_variant) return money(product?.price ?? 0);
  if (product.price_min == null || product.price_max == null) return 'Varies';
  if (Number(product.price_min) === Number(product.price_max)) return money(product.price_min);
  return `${money(product.price_min)} – ${money(product.price_max)}`;
}

async function runSearch() {
  const q = query.value.trim();
  detail.value = null;
  error.value = '';
  searched.value = true;
  if (!q) {
    results.value = [];
    return;
  }
  searching.value = true;
  try {
    const data = await http.get('stock_lookup/search', { search: q });
    results.value = data.results || [];
    const exact = results.value.find(r => r.code === q || r.gtin === q);
    if (results.value.length === 1 || exact) {
      await selectProduct((exact || results.value[0]).id);
    }
  } catch (e) {
    error.value = 'Could not search products.';
  } finally {
    searching.value = false;
  }
}

async function selectProduct(id) {
  loadingDetail.value = true;
  error.value = '';
  try {
    detail.value = await http.get(`stock_lookup/${id}`);
  } catch (e) {
    error.value = 'Could not load stock detail.';
  } finally {
    loadingDetail.value = false;
  }
}
</script>

<style scoped>
.lookup-hero {
  display: flex;
  align-items: center;
  gap: 16px;
  background: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 100%);
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
  color: #fff;
}
.lookup-hero-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.lookup-hero-title {
  font-size: 18px;
  font-weight: 700;
}
.lookup-hero-sub {
  font-size: 13px;
  opacity: 0.9;
  margin-top: 2px;
}
.lookup-card {
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03), 0 1px 6px rgba(0, 0, 0, 0.04);
  border-radius: 12px;
}
.lookup-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 12px;
  border: 1px solid rgba(5, 5, 5, 0.08);
  border-radius: 8px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: all 120ms ease;
}
.lookup-row:hover {
  border-color: #6d28d9;
  background: rgba(109, 40, 217, 0.03);
}
.lookup-product-head {
  display: flex;
  gap: 16px;
  align-items: center;
}
.lookup-product-img {
  width: 68px;
  height: 68px;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid rgba(5, 5, 5, 0.08);
}
.lookup-product-img-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  color: rgba(0, 0, 0, 0.25);
  background: #fafafa;
}
.lookup-total-badge {
  margin-left: auto;
  text-align: center;
  background: #ede9fe;
  border-radius: 10px;
  padding: 8px 18px;
}
.lookup-total-badge-num {
  font-size: 20px;
  font-weight: 700;
  color: #6d28d9;
  line-height: 1.1;
}
.lookup-total-badge-label {
  font-size: 11px;
  color: #6d28d9;
  opacity: 0.8;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
