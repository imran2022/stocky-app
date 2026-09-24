<template>
  <ModernCard :title="tt('Payment_Sent_Received', 'Payments sent & received')" :sub="h ? tt('By_hour', 'By hour') : tt('By_day', 'By day')">
    <template v-if="ctx.data">
      <DmChart v-if="labels.length" :type="h ? 'bar' : 'line'" :labels="labels" :series="series" :fmt="money" :height="260" :aria-label="tt('Payment_Sent_Received', 'Payments sent & received')" />
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
const h = computed(() => (props.ctx.hourly?.hours?.length ? props.ctx.hourly : null));
const pay = computed(() => props.ctx.data?.payments?.original || {});
const labels = computed(() => (h.value ? h.value.hours.map(x => `${String(x).padStart(2, '0')}:00`) : pay.value.days || []));
const series = computed(() => [
  { name: tt('Received', 'Received'), color: 'var(--dm-s3)', values: (h.value ? h.value.received : pay.value.payment_received || []).map(num) },
  { name: tt('Sent', 'Sent'), color: 'var(--dm-s2)', values: (h.value ? h.value.sent : pay.value.payment_sent || []).map(num) },
]);
</script>
