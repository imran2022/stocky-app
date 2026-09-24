<template>
  <div class="dm-kpis">
    <div class="dm-hero" :class="{ 'is-skel': ctx.loading }">
      <div class="dm-hero-top">
        <span class="dm-hero-label">{{ heroLabel }}</span>
        <span v-if="heroDelta" class="dm-hero-delta">{{ heroDelta }}</span>
      </div>
      <div>
        <div class="dm-hero-value">{{ ready ? money(r.today_sales) : '-' }}</div>
        <div class="dm-hero-note">{{ tt('Completed_sales_only', 'Completed sales only') }} · {{ rangeText }}</div>
      </div>
      <svg v-if="spark.path" viewBox="0 0 200 64" preserveAspectRatio="none" aria-hidden="true">
        <path :d="spark.area" fill="rgba(255,255,255,.16)" /><path :d="spark.path" fill="none" stroke="#fff" stroke-width="2.2" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      <div class="dm-hero-stats">
        <div><span>{{ tt('Invoices', 'Invoices') }}</span><b>{{ ready ? num0(r.today_invoices) : '-' }}</b></div>
        <div><span>{{ tt('Avg_ticket', 'Avg ticket') }}</span><b>{{ avgTicket }}</b></div>
        <div><span>{{ tt('Net_profit', 'Net profit') }}</span><b>{{ ready ? money(r.today_profit) : '-' }}</b></div>
      </div>
    </div>

    <div class="dm-kpi-grid">
      <button v-for="k in kpis" :key="k.key" type="button" class="dm-kpi" @click="$router.push(k.to)">
        <div class="dm-kpi-top">
          <span class="dm-kpi-ic" :style="{ background: k.tint, color: k.color }"><component :is="k.icon" /></span>
          <span class="dm-kpi-label">{{ k.label }}</span>
        </div>
        <div v-if="k.lines" class="dm-kpi-lines">
          <div v-for="l in k.lines" :key="l.label"><span>{{ l.label }}</span><b>{{ l.value }}</b></div>
        </div>
        <div v-else>
          <div class="dm-kpi-row"><div class="dm-kpi-val">{{ k.value }}</div><Spark :values="k.spark" :color="k.color" /></div>
          <div class="dm-kpi-sub">
            <span v-if="k.delta" class="dm-pill" :class="k.delta.cls" :title="`${k.delta.text} ${k.delta.suffix}`"><span>{{ k.delta.text }}</span><span class="dm-pill-sfx">{{ k.delta.suffix }}</span></span>
            <span v-if="k.note" class="dm-card-sub" style="margin:0">{{ k.note }}</span>
            <span v-if="k.io" class="dm-kpi-io"><span>{{ k.io[0].label }} <b>{{ k.io[0].value }}</b></span><span>{{ k.io[1].label }} <b>{{ k.io[1].value }}</b></span></span>
          </div>
        </div>
      </button>
    </div>
  </div>
</template>
<script setup>
import { computed } from 'vue';
import { ShoppingOutlined, WalletOutlined, RollbackOutlined, DollarOutlined, SwapOutlined, FallOutlined } from '@ant-design/icons-vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num, pctChange, fmtPct } from '../format';
import Spark from '../parts/Spark.vue';

const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const num0 = v => new Intl.NumberFormat().format(num(v));

const r = computed(() => props.ctx.report || {});
const ready = computed(() => !!props.ctx.data);
const ins = computed(() => props.ctx.insights);
const prevLabel = computed(() => (props.ctx.isSingleDay ? tt('vs_yesterday', 'vs yesterday') : `${tt('vs_previous', 'vs previous')} ${props.ctx.dayCount} ${tt('days', 'days')}`));
const heroLabel = computed(() => (props.ctx.isToday ? tt('Todays_sales', "Today's sales") : tt('Sales', 'Sales')));
const rangeText = computed(() => (props.ctx.range.from === props.ctx.range.to ? props.ctx.range.from : `${props.ctx.range.from} → ${props.ctx.range.to}`));

function deltaOf(cur, prev, goodWhenUp = true, neutral = false) {
  const p = pctChange(cur, prev);
  if (p === null) return null;
  const up = p >= 0;
  return { text: `${up ? '▲' : '▼'} ${fmtPct(Math.abs(p))}`, suffix: prevLabel.value, cls: neutral ? '' : (up === goodWhenUp ? 'good' : 'bad') };
}
const heroDelta = computed(() => {
  const p = ins.value?.previous;
  const d = p ? pctChange(r.value.today_sales, p.sales) : null;
  return d === null ? '' : `${d >= 0 ? '▲' : '▼'} ${fmtPct(Math.abs(d))} ${prevLabel.value}`;
});
const avgTicket = computed(() => (ready.value && num(r.value.today_invoices) > 0 ? money(num(r.value.today_sales) / num(r.value.today_invoices)) : '-'));

const spark = computed(() => {
  const d = props.ctx.data;
  const vals = props.ctx.hourly?.sales || d?.sales?.original?.data;
  if (!Array.isArray(vals) || vals.length < 2) return {};
  const v = vals.map(num), max = Math.max(...v, 1), n = v.length;
  const pts = v.map((x, i) => [(i / (n - 1)) * 200, 58 - (x / max) * 52]);
  const path = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('');
  return { path, area: `${path}L200 64L0 64Z` };
});

const kpis = computed(() => {
  const x = r.value, i = ins.value, ok = ready.value;
  const dash = '-';
  return [
    { key: 'purchases', label: tt('Purchases', 'Purchases'), icon: ShoppingOutlined, color: 'var(--dm-s1)', tint: 'color-mix(in srgb, var(--dm-s1) 14%, transparent)', to: '/purchases',
      spark: props.ctx.hourly?.purchases || props.ctx.data?.purchases?.original?.data,
      value: ok ? money(x.today_purchases) : dash, delta: ok && i?.previous ? deltaOf(x.today_purchases, i.previous.purchases, true, true) : null },
    { key: 'expenses', label: tt('Expenses', 'Expenses'), icon: FallOutlined, color: 'var(--dm-s2)', tint: 'color-mix(in srgb, var(--dm-s2) 14%, transparent)', to: '/expenses',
      spark: i?.daily?.expenses,
      value: i ? money(i.expenses.value) : dash, delta: i ? deltaOf(i.expenses.value, i.expenses.prev, false) : null },
    { key: 'returns', label: tt('Returns', 'Returns'), icon: RollbackOutlined, color: 'var(--dm-warn)', tint: 'color-mix(in srgb, var(--dm-warn) 15%, transparent)', to: '/sale-returns',
      lines: ok ? [{ label: tt('SalesReturn', 'Sales returns'), value: money(x.return_sales) }, { label: tt('PurchasesReturn', 'Purchase returns'), value: money(x.return_purchases_amount ?? 0) }] : [{ label: tt('SalesReturn', 'Sales returns'), value: dash }, { label: tt('PurchasesReturn', 'Purchase returns'), value: dash }] },
    { key: 'sales_due', label: tt('Sales_Due', 'Sales due'), icon: DollarOutlined, color: 'var(--dm-s3)', tint: 'color-mix(in srgb, var(--dm-s3) 14%, transparent)', to: '/sales',
      value: ok ? money(x.sales_due) : dash, note: tt('Customers_owe_you', 'Customers owe you') },
    { key: 'purchase_due', label: tt('Purchase_Due', 'Purchase due'), icon: WalletOutlined, color: 'var(--dm-s4)', tint: 'color-mix(in srgb, var(--dm-s4) 14%, transparent)', to: '/purchases',
      value: ok ? money(x.purchase_due) : dash, note: tt('You_owe_suppliers', 'You owe suppliers') },
    { key: 'cash', label: tt('Net_cash_flow', 'Net cash flow'), icon: SwapOutlined, color: 'var(--dm-good)', tint: 'color-mix(in srgb, var(--dm-good) 14%, transparent)', to: '/reports/cash-flow',
      spark: i?.daily?.net_cash,
      value: i ? money(i.cash_flow.net) : dash, io: i ? [{ label: tt('In', 'In'), value: money(i.cash_flow.inflow) }, { label: tt('Out', 'Out'), value: money(i.cash_flow.outflow) }] : null, delta: i ? deltaOf(i.cash_flow.net, i.cash_flow.prev_net, true) : null },
  ];
});
</script>
