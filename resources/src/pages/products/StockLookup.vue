<template>
  <div class="page">
    <PageHeader title="Stock Lookup" :breadcrumb="['Products', 'Stock Lookup']" />

    <a-card size="small" style="margin-bottom: 16px">
      <div style="color: rgba(0, 0, 0, 0.45); font-size: 13px; margin-bottom: 8px">
        Search by SKU, barcode, or product name
      </div>
      <a-input-search
        v-model:value="query"
        placeholder="e.g. BB10 or Bag"
        size="large"
        :loading="searching"
        enter-button="Search"
        allow-clear
        @search="runSearch"
      />
    </a-card>

    <a-alert v-if="error" type="error" :message="error" show-icon style="margin-bottom: 16px" />

    <!-- Multiple matches: pick one -->
    <a-card v-if="!detail && results.length > 1" size="small" title="Matching products">
      <div
        v-for="r in results" :key="r.id"
        class="lookup-row"
        @click="selectProduct(r.id)"
      >
        <div>
          <div style="font-weight: 600">{{ r.name }}</div>
          <div class="muted">SKU: {{ r.code }}</div>
        </div>
        <div style="font-weight: 600; color: #6d28d9">{{ money(r.price) }}</div>
      </div>
    </a-card>

    <a-empty
      v-if="!detail && !searching && searched && results.length === 0"
      description="No matching products"
      style="padding: 48px 0"
    />

    <!-- Selected product: per-warehouse breakdown -->
    <a-card v-if="loadingDetail" size="small" :loading="true" style="min-height: 160px" />
    <a-card v-else-if="detail" size="small">
      <div style="display: flex; gap: 16px; align-items: center; margin-bottom: 20px">
        <img
          v-if="detail.product.image"
          :src="'/images/' + detail.product.image"
          style="width: 64px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(5,5,5,0.08)"
        />
        <div>
          <div style="font-weight: 700; font-size: 18px">{{ detail.product.name }}</div>
          <a-space style="margin-top: 6px">
            <a-tag>SKU: {{ detail.product.code }}</a-tag>
            <a-tag color="purple">Price: {{ money(detail.product.price) }}</a-tag>
          </a-space>
        </div>
      </div>

      <div style="font-weight: 600; margin-bottom: 8px">Stock by Warehouse</div>
      <a-table
        :columns="warehouseColumns"
        :data-source="detail.warehouses"
        :pagination="false"
        row-key="id"
        size="small"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'location'">
            <div style="font-weight: 500">{{ record.name }}</div>
            <div v-if="record.location" class="muted">{{ record.location }}</div>
          </template>
          <template v-else-if="column.key === 'qty'">
            <a-tag :color="record.qty > 0 ? 'green' : 'red'">{{ record.qty }} Pcs</a-tag>
          </template>
        </template>
        <template #summary>
          <a-table-summary-row>
            <a-table-summary-cell>
              <div style="font-weight: 700">Total Stock</div>
              <div class="muted">Across all warehouses</div>
            </a-table-summary-cell>
            <a-table-summary-cell>
              <a-tag color="purple">{{ detail.total_qty }} Pcs</a-tag>
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
 * Backend: GET stock_lookup/search?search=... -> {results}, then
 * GET stock_lookup/{id} -> {product, warehouses, total_qty}.
 */
import { ref } from 'vue';
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
.lookup-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 12px;
  border: 1px solid rgba(5, 5, 5, 0.08);
  border-radius: 8px;
  margin-bottom: 8px;
  cursor: pointer;
}
.lookup-row:hover {
  border-color: #6d28d9;
  background: rgba(109, 40, 217, 0.03);
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
