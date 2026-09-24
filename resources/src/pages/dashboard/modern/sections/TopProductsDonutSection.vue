<template>
  <ModernCard :title="tt('Top_products_chart', 'Top selling products')" :sub="tt('This_year_times_sold', 'This year · times sold')">
    <template v-if="ctx.products">
      <div v-if="items.length" class="dm-donut">
        <svg viewBox="0 0 120 120" role="img" :aria-label="tt('Top_products_chart', 'Top selling products')">
          <circle cx="60" cy="60" r="44" fill="none" stroke="var(--dm-chip)" stroke-width="16" />
          <circle v-for="s in arcs" :key="s.i" cx="60" cy="60" r="44" fill="none" :stroke="s.color" stroke-width="16" :stroke-dasharray="`${s.len} ${C - s.len}`" :stroke-dashoffset="-s.off" transform="rotate(-90 60 60)" />
          <text x="60" y="58" text-anchor="middle" font-size="18" font-weight="800" :fill="'var(--dm-ink)'">{{ total }}</text>
          <text x="60" y="73" text-anchor="middle" font-size="8" :fill="'var(--dm-muted)'">{{ tt('times_sold', 'times sold') }}</text>
        </svg>
        <div class="dm-rows">
          <div v-for="s in arcs" :key="s.i"><span><i class="dm-dotm" :style="{ background: s.color }" /><b>{{ s.name }}</b></span><span>{{ s.value }}</span></div>
        </div>
      </div>
      <div v-else class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
    </template>
    <div v-else-if="ctx.productsError" class="dm-empty"><b>{{ tt('Section_failed', 'Could not load this section.') }}</b><button type="button" class="dm-btn" @click="ctx.load()">{{ tt('Retry', 'Retry') }}</button></div>
    <div v-else class="dm-skel" style="height:260px" />
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import ModernCard from '../parts/ModernCard.vue';
import { useTt } from '../useTt';
import { num } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const tt = useTt();
const C = 2 * Math.PI * 44;
const COLORS = ['var(--dm-s1)', 'var(--dm-s2)', 'var(--dm-s3)', 'var(--dm-s4)', 'var(--dm-gray)'];
const items = computed(() => (props.ctx.products?.product_report?.original || []).map(p => ({ name: p.name, value: num(p.value) })).filter(p => p.value > 0));
const total = computed(() => items.value.reduce((a, b) => a + b.value, 0));
const arcs = computed(() => {
  let off = 0;
  return items.value.map((p, i) => {
    const len = (p.value / total.value) * C, seg = { ...p, i, len: Math.max(0, len - 2), off, color: COLORS[Math.min(i, COLORS.length - 1)] };
    off += len; return seg;
  });
});
</script>
