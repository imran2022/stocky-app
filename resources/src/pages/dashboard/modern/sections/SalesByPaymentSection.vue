<template>
  <ModernCard :title="tt('Sales_by_Payment', 'Sales by payment method')" :sub="tt('Selected_period', 'Selected period')">
    <template v-if="ctx.data">
      <div v-if="rows.length" class="dm-list">
        <div v-for="p in rows" :key="p.name" class="dm-pay">
          <span class="dm-row-ic" :style="{ background: `color-mix(in srgb, ${p.color} 14%, transparent)`, color: p.color }"><component :is="p.icon" /></span>
          <div class="dm-row-main"><div class="dm-row-name">{{ p.name }}</div><div class="dm-track"><i :style="{ width: p.pct + '%', background: p.color }" /></div></div>
          <b style="font-size:13px;white-space:nowrap">{{ money(p.amount) }}</b>
          <span class="dm-pay-pct">{{ Math.round(p.pct) }}%</span>
        </div>
      </div>
      <div v-else class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
    </template>
    <div v-else class="dm-skel" style="height:200px" />
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import { CreditCardOutlined, BankOutlined, FileTextOutlined, WalletOutlined } from '@ant-design/icons-vue';
import ModernCard from '../parts/ModernCard.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num } from '../format';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const icon = name => { const s = String(name || '').toLowerCase(); return s.includes('card') ? CreditCardOutlined : (s.includes('bank') || s.includes('transfer')) ? BankOutlined : (s.includes('cheque') || s.includes('check')) ? FileTextOutlined : WalletOutlined; };
const COLORS = ['var(--dm-s2)', 'var(--dm-s1)', 'var(--dm-s3)', 'var(--dm-s4)', 'var(--dm-gray)'];
const rows = computed(() => (props.ctx.data?.sales_by_payment || []).map((p, i) => ({ name: p.name, amount: num(p.amount), pct: num(p.percentage), color: COLORS[Math.min(i, COLORS.length - 1)], icon: icon(p.name) })));
</script>
