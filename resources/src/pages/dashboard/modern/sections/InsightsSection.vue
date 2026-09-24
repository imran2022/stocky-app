<template>
  <div>
    <div class="dm-sec-label">{{ tt('Business_insights', 'Business insights') }}</div>
    <div v-if="ctx.insightsError" class="dm-err" role="alert">
      <span>{{ tt('Insights_failed', 'Could not load business insights.') }}</span>
      <button type="button" class="dm-btn" @click="ctx.load()">{{ tt('Retry', 'Retry') }}</button>
    </div>
    <div v-else class="dm-insights">
      <!-- 1. Profit margin -->
      <div class="dm-ins">
        <div class="dm-ins-title">{{ tt('Profit_margin', 'Profit margin') }}</div>
        <template v-if="ctx.data">
          <div class="dm-ins-big" :class="{ 'dm-bad': margin !== null && margin < 0 }">{{ margin === null ? '-' : fmtPct(margin) }}<small>{{ tt('of_net_sales', 'of net sales') }}</small></div>
          <div class="dm-ins-body">
            <div v-if="parts.length" class="dm-stack" role="img" :aria-label="tt('Profit_margin', 'Profit margin')">
              <i v-for="s in parts" :key="s.label" :style="{ width: s.w + '%', background: s.color }" />
            </div>
            <div class="dm-rows">
              <div v-for="s in rows" :key="s.label"><span><i class="dm-dotm" :style="{ background: s.color }" /><b>{{ s.label }}</b></span><span :class="s.cls">{{ money(s.value) }}</span></div>
            </div>
          </div>
          <div class="dm-ins-note">{{ margin === null ? tt('Margin_unavailable', 'No net sales in this period, so there is no margin to show.') : tt('Margin_note', 'Net sales exclude tax and delivery charges.') }}</div>
        </template>
        <div v-else class="dm-skel" style="height:150px" />
      </div>

      <!-- 2. Money to collect -->
      <div class="dm-ins">
        <div class="dm-ins-title">{{ tt('Money_to_collect', 'Money to collect') }}</div>
        <template v-if="ins">
          <div class="dm-ins-big">{{ ins.collected.pct === null ? '-' : fmtPct(ins.collected.pct) }}<small>{{ tt('collected', 'collected') }}</small></div>
          <div class="dm-ins-body">
            <div class="dm-track"><i :style="{ width: (ins.collected.pct ?? 0) + '%', background: 'var(--dm-s3)' }" /></div>
            <div class="dm-card-sub" style="margin:0">{{ tt('Unpaid_overall', 'Unpaid overall') }}: <b class="dm-num">{{ money(ins.receivables.total) }}</b> · {{ ins.receivables.count }} {{ tt('invoices', 'invoices') }}</div>
            <div v-if="ins.receivables.total > 0" class="dm-stack">
              <i v-for="b in aging" v-show="b.amount > 0" :key="b.key" :style="{ width: b.w + '%', background: b.color }" />
            </div>
            <div class="dm-rows">
              <div v-for="b in aging" :key="b.key"><span><i class="dm-dotm" :style="{ background: b.color }" /><b>{{ b.label }}</b></span><span>{{ money(b.amount) }} <small class="dm-muted">({{ b.count }})</small></span></div>
            </div>
          </div>
          <div class="dm-ins-note">{{ tt('Collected_note', '% of this period\'s invoices already paid. Unpaid overall covers all dates, aged from the invoice date as of today.') }}</div>
        </template>
        <div v-else class="dm-skel" style="height:150px" />
      </div>

      <!-- 3. Stock health -->
      <div class="dm-ins">
        <div class="dm-ins-title">{{ tt('Stock_health', 'Stock health') }}</div>
        <template v-if="ins">
          <div class="dm-ins-big">{{ money(ins.stock_health.dead_value) }}<small>{{ tt('tied_in_dead_stock', 'in slow stock') }}</small></div>
          <div class="dm-ins-body">
            <div class="dm-mini">
              <div><small>{{ tt('Slow_products', 'Slow products') }}</small><b>{{ ins.stock_health.dead_products }}</b></div>
              <div><small>{{ tt('Share_of_stock', 'Share of stock') }}</small><b>{{ slowShare === null ? '-' : fmtPct(slowShare) }}</b></div>
            </div>
          </div>
          <div class="dm-ins-note">{{ tt('no_sale_in', 'No sale in') }} {{ ins.stock_health.dead_days }} {{ tt('days', 'days') }} · {{ tt('at_cost', 'valued at cost') }}. {{ tt('Low_out_in_attention', 'Low and out-of-stock items are under Needs attention.') }}</div>
        </template>
        <div v-else class="dm-skel" style="height:150px" />
      </div>

      <!-- 4. Customer mix -->
      <div class="dm-ins">
        <div class="dm-ins-title">{{ tt('Customer_mix', 'Customer mix') }}</div>
        <template v-if="ins">
          <template v-if="topShare !== null">
            <div class="dm-ins-big">{{ fmtPct(topShare) }}<small>{{ tt('from_top_customer', 'from your top customer') }}</small></div>
            <div class="dm-ins-body">
              <div class="dm-rows">
                <div v-for="c in ins.customer_mix.top.slice(0, 3)" :key="c.id ?? c.name"><span><b>{{ c.name || tt('Walk_in', 'Walk-in / unnamed') }}</b></span><span>{{ c.share_pct === null ? '-' : fmtPct(c.share_pct) }}</span></div>
              </div>
              <div class="dm-card-sub" style="margin:0">{{ tt('Top_5_share', 'Top 5 customers') }}: <b class="dm-num">{{ top5 === null ? '-' : fmtPct(top5) }}</b></div>
            </div>
          </template>
          <template v-else>
            <div class="dm-ins-big">-</div>
            <div class="dm-ins-body"><div class="dm-card-sub" style="margin:0">{{ tt('No_sales_period', 'No completed sales in this period.') }}</div></div>
          </template>
          <div class="dm-ins-note">{{ avgNote }}</div>
        </template>
        <div v-else class="dm-skel" style="height:150px" />
      </div>
    </div>
  </div>
</template>
<script setup>
import { computed } from 'vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num, fmtPct } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const r = computed(() => props.ctx.report || {});
const ins = computed(() => props.ctx.insights);
const profit = computed(() => num(r.value.today_profit));
const cogs = computed(() => num(r.value.today_cogs));
const exp = computed(() => num(r.value.today_expenses));
const margin = computed(() => (num(r.value.today_net_revenue) > 0.005 ? (profit.value / num(r.value.today_net_revenue)) * 100 : null));
const rows = computed(() => [
  { label: tt('Cost_of_goods', 'Cost of goods sold'), value: cogs.value, color: 'var(--dm-s2)' },
  { label: tt('Expenses', 'Expenses'), value: exp.value, color: 'var(--dm-s1)' },
  { label: profit.value < 0 ? tt('Loss', 'Loss') : tt('Net_profit', 'Net profit'), value: profit.value, color: profit.value < 0 ? 'var(--dm-bad)' : 'var(--dm-s3)', cls: profit.value < 0 ? 'dm-bad' : '' },
]);
// The bar compares the three parts to each other; a loss is shown in the number, not as a negative bar.
const parts = computed(() => {
  const v = [Math.max(0, cogs.value), Math.max(0, exp.value), Math.max(0, profit.value)];
  const sum = v.reduce((a, b) => a + b, 0);
  if (sum <= 0) return [];
  return rows.value.map((s, i) => ({ ...s, w: (v[i] / sum) * 100 })).filter(s => s.w > 0);
});
const aging = computed(() => {
  const b = ins.value?.receivables?.buckets || {};
  const total = num(ins.value?.receivables?.total) || 1;
  const def = [['d0_7', tt('Days_0_7', '0–7 days'), 'var(--dm-s3)'], ['d8_30', tt('Days_8_30', '8–30 days'), 'var(--dm-warn)'], ['d31_plus', tt('Days_31_plus', '31+ days'), 'var(--dm-bad)']];
  return def.map(([key, label, color]) => ({ key, label, color, amount: num(b[key]?.amount), count: num(b[key]?.count), w: (num(b[key]?.amount) / total) * 100 }));
});
// Slow stock as a share of all stock, both at cost and for the same warehouse filter. "-" when there is no stock value.
const slowShare = computed(() => {
  const cost = num(props.ctx.data?.stock_value?.by_cost);
  return props.ctx.data && cost > 0.005 ? (num(ins.value?.stock_health?.dead_value) / cost) * 100 : null;
});
const topShare = computed(() => ins.value?.customer_mix?.top?.[0]?.share_pct ?? null);
const top5 = computed(() => {
  const t = ins.value?.customer_mix?.top || [];
  return t.length && t.every(c => c.share_pct !== null) ? t.reduce((a, c) => a + num(c.share_pct), 0) : null;
});
// Average ticket now vs the previous period, from the same invoice counts as the headline numbers.
const avgNote = computed(() => {
  const inv = num(r.value.today_invoices), p = ins.value?.previous;
  if (!props.ctx.data || inv <= 0) return tt('Avg_ticket_none', 'Average ticket: - (no invoices in this period)');
  const cur = num(r.value.today_sales) / inv;
  const prev = p && p.invoices > 0 ? p.sales / p.invoices : null;
  return `${tt('Avg_ticket', 'Avg ticket')} ${money(cur)}${prev === null ? '' : ` ${tt('vs', 'vs')} ${money(prev)} ${tt('previous_period', 'previous period')}`}`;
});
</script>
<style>
.dm-dotm { width: 9px; height: 9px; border-radius: 5px; display: inline-block; flex: none; }
.dm-sec-label { font-size: 11px; font-weight: 700; letter-spacing: 0.08em; color: var(--dm-muted); text-transform: uppercase; margin: 0 0 8px 2px; }
</style>
