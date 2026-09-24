<template>
  <ModernCard :title="tt('StockAlert', 'Stock alert')" to="/reports/quantity-alerts">
    <template v-if="ctx.data">
      <div v-if="rows.length" class="dm-list">
        <div v-for="(a, i) in rows" :key="a.code + i" class="dm-row">
          <span class="dm-row-ic" :style="{ background: `color-mix(in srgb, ${a.color} 14%, transparent)`, color: a.color }"><WarningOutlined /></span>
          <div class="dm-row-main"><div class="dm-row-name">{{ a.name }}</div><div class="dm-row-meta">{{ a.code }}<template v-if="a.warehouse"> · {{ a.warehouse }}</template></div><div class="dm-track"><i :style="{ width: a.w + '%', background: a.color }" /></div></div>
          <div class="dm-row-end"><b>{{ a.quantity }}</b><small>/ {{ a.stock_alert }}</small></div>
        </div>
      </div>
      <div v-else class="dm-empty"><b>{{ tt('All_stock_ok', 'Nothing is running low') }}</b></div>
    </template>
    <div v-else class="dm-skel" style="height:220px" />
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import { WarningOutlined } from '@ant-design/icons-vue';
import ModernCard from '../parts/ModernCard.vue';
import { useTt } from '../useTt';
import { num } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const tt = useTt();
const rows = computed(() => (props.ctx.data?.report_dashboard?.original?.stock_alert || []).map(a => {
  const q = num(a.quantity), th = num(a.stock_alert), ratio = th > 0 ? q / th : 0;
  return { ...a, w: Math.min(100, Math.round(ratio * 100)), color: ratio <= 0.34 ? 'var(--dm-bad)' : 'var(--dm-warn)' };
}));
</script>
