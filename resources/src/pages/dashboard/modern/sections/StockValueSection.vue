<template>
  <ModernCard :title="tt('Stock_Value', 'Stock value')" :sub="tt('Now_current_stock', 'Right now · current stock on hand')">
    <template v-if="ctx.data && sv">
      <div class="dm-list" style="gap:10px">
        <div v-for="t in tiles" :key="t.label" class="dm-value-tile" :style="{ background: `color-mix(in srgb, ${t.color} 9%, transparent)` }">
          <span class="dm-row-ic" :style="{ background: t.color }"><component :is="t.icon" /></span>
          <div><small>{{ t.label }}</small><b>{{ money(t.value) }}</b></div>
          <div class="dm-track"><i :style="{ width: t.w + '%', background: t.color }" /></div>
        </div>
      </div>
    </template>
    <div v-else-if="ctx.data" class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
    <div v-else class="dm-skel" style="height:200px" />
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import { DollarOutlined, TagOutlined, AppstoreOutlined } from '@ant-design/icons-vue';
import ModernCard from '../parts/ModernCard.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const sv = computed(() => props.ctx.data?.stock_value || null);
const tiles = computed(() => {
  const v = sv.value || {};
  const rows = [
    { label: tt('By_Cost', 'By cost'), value: num(v.by_cost), icon: DollarOutlined, color: 'var(--dm-accent)' },
    { label: tt('By_Retail', 'By retail'), value: num(v.by_retail), icon: TagOutlined, color: 'var(--dm-s1)' },
    { label: tt('By_Wholesale', 'By wholesale'), value: num(v.by_wholesale), icon: AppstoreOutlined, color: 'var(--dm-s3)' },
  ];
  const max = Math.max(1, ...rows.map(r => r.value));
  return rows.map(r => ({ ...r, w: Math.round((r.value / max) * 100) }));
});
</script>
