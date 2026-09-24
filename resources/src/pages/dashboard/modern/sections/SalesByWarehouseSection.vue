<template>
  <ModernCard :title="tt('Sales_by_Warehouse', 'Sales by warehouse')" :sub="tt('Selected_period', 'Selected period')">
    <template v-if="ctx.data">
      <div v-if="rows.length" class="dm-list">
        <div v-for="(w, i) in rows" :key="i" class="dm-row">
          <span class="dm-row-ic" style="background:var(--dm-chip);color:var(--dm-ink2)"><ShopOutlined /></span>
          <div class="dm-row-main"><div class="dm-row-name">{{ w.name }}</div><div class="dm-track"><i :style="{ width: w.w + '%', background: 'var(--dm-s4)' }" /></div></div>
          <div class="dm-row-end"><b>{{ money(w.amount) }}</b><small>{{ w.total_invoice }} {{ tt('Invoices', 'invoices').toLowerCase() }}</small></div>
        </div>
      </div>
      <div v-else class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
    </template>
    <div v-else class="dm-skel" style="height:200px" />
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import { ShopOutlined } from '@ant-design/icons-vue';
import ModernCard from '../parts/ModernCard.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const rows = computed(() => {
  const l = Array.isArray(props.ctx.data?.sales_by_warehouse) ? props.ctx.data.sales_by_warehouse : [];
  const max = Math.max(1, ...l.map(x => num(x.amount)));
  return l.map(x => ({ ...x, amount: num(x.amount), w: Math.round((num(x.amount) / max) * 100) }));
});
</script>
