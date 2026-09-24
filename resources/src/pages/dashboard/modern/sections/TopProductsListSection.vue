<template>
  <ModernCard :title="tt('Top_Selling_Products', 'Top selling products')" :sub="tt('This_month_by_amount', 'This month · by amount')" to="/products">
    <template v-if="ctx.products">
      <div v-if="rows.length" class="dm-list">
        <div v-for="(p, i) in rows" :key="i" class="dm-row">
          <span class="dm-rank" :class="'r' + (i + 1)">{{ i + 1 }}</span>
          <div class="dm-row-main"><div class="dm-row-name">{{ p.name }}</div><div class="dm-track"><i :style="{ width: p.w + '%', background: 'var(--dm-accent)' }" /></div></div>
          <div class="dm-row-end"><b>{{ money(p.total) }}</b><small>{{ p.total_sales }} {{ tt('n_sales', 'sales') }}</small></div>
        </div>
      </div>
      <div v-else class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
    </template>
    <div v-else-if="ctx.productsError" class="dm-empty"><b>{{ tt('Section_failed', 'Could not load this section.') }}</b><button type="button" class="dm-btn" @click="ctx.load()">{{ tt('Retry', 'Retry') }}</button></div>
    <div v-else class="dm-skel" style="height:220px" />
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import ModernCard from '../parts/ModernCard.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const rows = computed(() => {
  const l = props.ctx.products?.top_products || [];
  const max = Math.max(1, ...l.map(p => num(p.total)));
  return l.map(p => ({ ...p, w: Math.round((num(p.total) / max) * 100) }));
});
</script>
