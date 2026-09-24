<template>
  <ModernCard :title="tt('Top_Customers', 'Top customers')" :sub="mode === 'invoices' ? tt('This_month_by_invoices', 'This month · by invoices') : tt('Selected_period_by_amount', 'Selected period · by amount')" :to="'/customers'">
    <template #extra>
      <div class="dm-toggle" role="group">
        <button type="button" :class="{ on: mode === 'invoices' }" @click="mode = 'invoices'">{{ tt('Invoices', 'Invoices') }}</button>
        <button type="button" :class="{ on: mode === 'amount' }" @click="mode = 'amount'">{{ tt('Amount', 'Amount') }}</button>
      </div>
    </template>
    <template v-if="mode === 'invoices' ? ctx.data : ctx.insights">
      <div v-if="rows.length" class="dm-list">
        <div v-for="(c, i) in rows" :key="i" class="dm-row">
          <span class="dm-avatar">{{ initials(c.name) }}</span>
          <div class="dm-row-main"><div class="dm-row-name">{{ c.name || tt('Walk_in', 'Walk-in / unnamed') }}</div><div class="dm-track"><i :style="{ width: c.w + '%', background: 'var(--dm-s1)' }" /></div></div>
          <div class="dm-row-end"><b>{{ c.main }}</b><small v-if="c.sub">{{ c.sub }}</small></div>
        </div>
      </div>
      <div v-else class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
    </template>
    <div v-else-if="mode === 'amount' && ctx.insightsError" class="dm-empty"><b>{{ tt('Insights_failed', 'Could not load business insights.') }}</b></div>
    <div v-else class="dm-skel" style="height:220px" />
  </ModernCard>
</template>
<script setup>
import { ref, computed } from 'vue';
import ModernCard from '../parts/ModernCard.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num, initials } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const mode = ref('invoices');
const rows = computed(() => {
  if (mode.value === 'invoices') {
    const l = (props.ctx.data?.customers?.original || []).map(c => ({ name: c.name, v: num(c.value) }));
    const max = Math.max(1, ...l.map(x => x.v));
    return l.map(x => ({ name: x.name, main: `${x.v} ${tt('Invoices', 'invoices').toLowerCase()}`, sub: '', w: Math.round((x.v / max) * 100) }));
  }
  const l = props.ctx.insights?.customer_mix?.top || [];
  const max = Math.max(1, ...l.map(x => num(x.amount)));
  return l.map(x => ({ name: x.name, main: money(x.amount), sub: `${x.share_pct === null ? '-' : x.share_pct + '%'} · ${x.invoices} ${tt('Invoices', 'invoices').toLowerCase()}`, w: Math.round((num(x.amount) / max) * 100) }));
});
</script>
