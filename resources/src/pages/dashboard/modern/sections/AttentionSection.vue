<template>
  <div>
    <div class="dm-sec-label">{{ tt('Needs_attention', 'Needs attention') }}</div>
    <div v-if="ctx.insightsError" class="dm-err" role="alert"><span>{{ tt('Insights_failed', 'Could not load business insights.') }}</span><button type="button" class="dm-btn" @click="ctx.load()">{{ tt('Retry', 'Retry') }}</button></div>
    <div v-else class="dm-attn">
      <template v-if="ins">
        <button v-for="c in chips" :key="c.key" type="button" class="dm-chip" :class="{ clear: c.n === 0 }" @click="$router.push(c.to)">
          <span class="dm-kpi-ic" :style="{ background: c.n === 0 ? 'var(--dm-chip)' : `color-mix(in srgb, ${c.color} 15%, transparent)`, color: c.n === 0 ? 'var(--dm-muted)' : c.color }"><component :is="c.icon" /></span>
          <span style="min-width:0"><b>{{ c.n }}</b><small>{{ c.label }}</small><small v-if="c.sub" style="color:var(--dm-ink2)">{{ c.sub }}</small></span>
        </button>
      </template>
      <template v-else><div v-for="i in 5" :key="i" class="dm-skel" style="height:64px;border-radius:16px" /></template>
    </div>
  </div>
</template>
<script setup>
import { computed } from 'vue';
import { WarningOutlined, ClockCircleOutlined, InboxOutlined, FileSearchOutlined, DesktopOutlined } from '@ant-design/icons-vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const ins = computed(() => props.ctx.insights);
const chips = computed(() => {
  const a = ins.value.attention;
  return [
    { key: 'low', n: a.low_stock.count, sub: a.low_stock.out ? `${a.low_stock.out} ${tt('out_of_stock', 'out of stock')}` : '', label: tt('Low_stock_products', 'Low-stock products'), icon: WarningOutlined, color: 'var(--dm-bad)', to: '/reports/quantity-alerts' },
    { key: 'overdue', n: a.overdue_30.count, sub: a.overdue_30.count ? money(a.overdue_30.amount) : '', label: tt('Overdue_31_plus', 'Invoices unpaid 31+ days'), icon: ClockCircleOutlined, color: 'var(--dm-warn)', to: '/sales' },
    { key: 'receive', n: a.purchases_to_receive.count, sub: a.purchases_to_receive.count ? money(a.purchases_to_receive.amount) : '', label: tt('Purchases_to_receive', 'Purchases to receive'), icon: InboxOutlined, color: 'var(--dm-s1)', to: '/purchases' },
    { key: 'pending', n: a.pending_sales.count, sub: a.pending_sales.count ? money(a.pending_sales.amount) : '', label: tt('Pending_sales', 'Pending sales'), icon: FileSearchOutlined, color: 'var(--dm-s4)', to: '/sales' },
    { key: 'reg', n: a.open_registers, sub: '', label: tt('Open_registers', 'Open cash registers'), icon: DesktopOutlined, color: 'var(--dm-s3)', to: '/reports/cash-registers' },
  ];
});
</script>
