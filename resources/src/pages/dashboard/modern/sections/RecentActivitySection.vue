<template>
  <ModernCard :title="tt('Recent_activity', 'Recent activity')" :sub="tt('Recent_activity_sub', 'Latest changes in your business')" :to="ins?.activity_allowed ? '/reports/activity-log' : ''">
    <template v-if="ctx.insightsError"><div class="dm-empty"><b>{{ tt('Insights_failed', 'Could not load business insights.') }}</b></div></template>
    <template v-else-if="ins">
      <div v-if="!ins.activity_allowed" class="dm-empty"><b>{{ tt('Activity_not_allowed', 'Recent activity is not available for your role.') }}</b></div>
      <div v-else-if="!ins.activity?.length" class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
      <template v-else>
        <div class="dm-actabs" role="tablist">
          <button v-for="t in tabs" :key="t.k" type="button" role="tab" :aria-selected="tab === t.k" :class="{ on: tab === t.k }" @click="tab = t.k">{{ t.label }}<i v-if="t.n">{{ t.n }}</i></button>
        </div>
        <div v-if="!groups.length" class="dm-empty" style="padding:18px 0"><b>{{ tt('Nothing_here', 'Nothing here yet') }}</b></div>
        <div v-for="g in groups" :key="g.day" class="dm-actgrp">
          <div class="dm-actday">{{ g.label }}</div>
          <component :is="a.link ? 'button' : 'div'" v-for="a in g.items" :key="a.id" :type="a.link ? 'button' : undefined" class="dm-act" :class="{ 'is-link': a.link }" @click="a.link && $router.push(a.link)">
            <span class="dm-act-ic" :style="{ background: `color-mix(in srgb, ${a.color} 14%, transparent)`, color: a.color }"><component :is="a.icon" /></span>
            <div class="dm-act-body">
              <div class="dm-act-text">{{ a.text }}<span v-if="a.count > 1" class="dm-act-x">×{{ a.count }}</span><span v-if="a.deleted" class="dm-pill bad" style="margin-left:8px">{{ tt('Deleted', 'Deleted') }}</span></div>
              <div class="dm-act-meta">{{ a.user }} · {{ a.when }}</div>
            </div>
          </component>
        </div>
      </template>
    </template>
    <div v-else class="dm-skel" style="height:220px" />
  </ModernCard>
</template>
<script setup>
import { computed, ref } from 'vue';
import { ShoppingCartOutlined, ShoppingOutlined, AppstoreOutlined, TeamOutlined, EditOutlined, DollarOutlined, SwapOutlined, SettingOutlined } from '@ant-design/icons-vue';
import ModernCard from '../parts/ModernCard.vue';
import { useTt } from '../useTt';
import { timeAgo } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const tt = useTt();
const ins = computed(() => props.ctx.insights);
const tab = ref('all');
const SHOW = 5;   // the card stays short: the full list is one click away (View all)

// One kind per row, from the module name the Activity Log already records.
function kindOf(module) {
  const s = String(module || '').toLowerCase();
  if (s.startsWith('payment')) return 'payments';
  if (s.includes('purchase')) return 'purchases';
  if (s.includes('sale') || s.includes('quotation') || s.includes('pos')) return 'sales';
  if (s.includes('adjust') || s.includes('transfer') || s.includes('product') || s.includes('stock')) return 'stock';
  return 'other';
}
const ICON = { sales: ShoppingCartOutlined, purchases: ShoppingOutlined, payments: DollarOutlined, stock: AppstoreOutlined, other: EditOutlined };
const COLOR = { sales: 'var(--dm-accent)', purchases: 'var(--dm-s1)', payments: 'var(--dm-good)', stock: 'var(--dm-s4)', other: 'var(--dm-muted)' };
function iconFor(a, kind) {
  const m = String(a.module || '').toLowerCase();
  if (m.includes('customer') || m.includes('client') || m.includes('supplier')) return TeamOutlined;
  if (m.includes('setting')) return SettingOutlined;
  if (m.includes('transfer')) return SwapOutlined;
  return ICON[kind];
}
function linkFor(a) {
  if (String(a.action).toLowerCase() === 'deleted' || !a.subject_id) return '';
  const t = String(a.subject_type || '');
  if (t.endsWith('\\Sale')) return `/sales/${a.subject_id}`;
  if (t.endsWith('\\Purchase')) return `/purchases/${a.subject_id}`;
  return '';
}
const all = computed(() => {
  const rows = (ins.value?.activity || []).map(a => {
    const kind = kindOf(a.module), d = a.at ? new Date(a.at) : null;
    return { id: a.id, kind, day: d ? d.toDateString() : '?', d, text: a.description || `${a.module} ${a.action}`, user: a.user || tt('System', 'System'), when: timeAgo(a.at, tt), deleted: String(a.action).toLowerCase() === 'deleted', link: linkFor(a), icon: iconFor(a, kind), color: COLOR[kind], count: 1 };
  });
  // Folds a run of identical lines ("Sale SL_0047 updated" x3) into one row so one busy record does not fill the list.
  const out = [];
  for (const r of rows) { const p = out[out.length - 1]; if (p && p.text === r.text && p.user === r.user && p.day === r.day) p.count++; else out.push({ ...r }); }
  return out;
});
const tabs = computed(() => {
  const n = k => all.value.filter(r => r.kind === k).length;
  return [{ k: 'all', label: tt('All', 'All') }, { k: 'sales', label: tt('Sales', 'Sales'), n: n('sales') }, { k: 'purchases', label: tt('Purchases', 'Purchases'), n: n('purchases') }, { k: 'payments', label: tt('Payments', 'Payments'), n: n('payments') }, { k: 'stock', label: tt('Stock', 'Stock'), n: n('stock') }].filter(t => t.k === 'all' || t.n);
});
const dayLabel = d => {
  const t = new Date(), y = new Date(); y.setDate(t.getDate() - 1);
  return !d ? '' : d.toDateString() === t.toDateString() ? tt('Today', 'Today') : d.toDateString() === y.toDateString() ? tt('Yesterday', 'Yesterday') : d.toLocaleDateString([], { day: 'numeric', month: 'short' });
};
const groups = computed(() => {
  const rows = all.value.filter(r => tab.value === 'all' || r.kind === tab.value).slice(0, SHOW), out = [];
  for (const r of rows) { let g = out[out.length - 1]; if (!g || g.day !== r.day) { g = { day: r.day, label: dayLabel(r.d), items: [] }; out.push(g); } g.items.push(r); }
  return out;
});
</script>
