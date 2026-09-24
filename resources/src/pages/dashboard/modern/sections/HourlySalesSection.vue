<template>
  <ModernCard :title="tt('Hourly_Sales_Today', 'Today\'s sales by hour')" :sub="tt('Always_today', 'Always today, whatever period is selected')">
    <template #extra>
      <div class="dm-toggle" role="group">
        <button type="button" :class="{ on: mode === 'total' }" @click="mode = 'total'">{{ tt('Amount', 'Amount') }}</button>
        <button type="button" :class="{ on: mode === 'count' }" @click="mode = 'count'">{{ tt('Invoices', 'Invoices') }}</button>
      </div>
    </template>
    <template v-if="ctx.data">
      <DmChart v-if="hasSales" type="bar" :labels="labels" :series="series" :fmt="fmt" :height="260" :aria-label="tt('Hourly_Sales_Today', 'Today\'s sales by hour')" />
      <div v-else class="dm-empty"><b>{{ tt('No_sales_today', 'No sales today') }}</b></div>
    </template>
    <div v-else class="dm-skel" style="height:260px" />
  </ModernCard>
</template>
<script setup>
import { ref, computed } from 'vue';
import ModernCard from '../parts/ModernCard.vue';
import DmChart from '../parts/DmChart.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const mode = ref('total');
const hours = computed(() => {
  const by = new Map((props.ctx.hourlyToday || []).map(h => [num(h.hour), h]));
  return Array.from({ length: 24 }, (_, i) => ({ hour: i, count: num(by.get(i)?.count), total: num(by.get(i)?.total) }));
});
const hasSales = computed(() => hours.value.some(h => h.count > 0));
const labels = computed(() => hours.value.map(h => `${String(h.hour).padStart(2, '0')}h`));
const series = computed(() => [{ name: mode.value === 'total' ? tt('Amount', 'Amount') : tt('Invoices', 'Invoices'), color: 'var(--dm-s1)', values: hours.value.map(h => (mode.value === 'total' ? h.total : h.count)) }]);
const fmt = v => (mode.value === 'total' ? money(v) : String(Math.round(v)));
</script>
