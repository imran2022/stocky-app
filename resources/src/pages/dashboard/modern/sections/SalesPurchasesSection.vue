<template>
  <ModernCard :title="tt('Sales_And_Purchases', 'Sales & Purchases')" :sub="sub">
    <template v-if="ctx.data">
      <DmChart v-if="labels.length" :labels="labels" :series="series" :fmt="money" :height="260" :aria-label="tt('Sales_And_Purchases', 'Sales & Purchases')" />
      <div v-else class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
    </template>
    <div v-else class="dm-skel" style="height:260px" />
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import ModernCard from '../parts/ModernCard.vue';
import DmChart from '../parts/DmChart.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const h = computed(() => props.ctx.hourly);
const hourLabels = a => a.map(x => `${String(x).padStart(2, '0')}:00`);
const labels = computed(() => (h.value?.hours?.length ? hourLabels(h.value.hours) : props.ctx.data?.sales?.original?.days || []));
const series = computed(() => {
  const s = h.value?.hours?.length ? h.value.sales : props.ctx.data?.sales?.original?.data;
  const p = h.value?.hours?.length ? h.value.purchases : props.ctx.data?.purchases?.original?.data;
  return [
    { name: tt('Sales', 'Sales'), color: 'var(--dm-s1)', values: (s || []).map(num) },
    { name: tt('Purchases', 'Purchases'), color: 'var(--dm-s3)', values: (p || []).map(num) },
  ];
});
const sub = computed(() => (h.value?.hours?.length ? tt('By_hour', 'By hour') : tt('By_day', 'By day')));
</script>
