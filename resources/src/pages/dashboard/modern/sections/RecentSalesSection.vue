<template>
  <ModernCard :title="tt('Recent_Sales', 'Recent sales')" to="/sales">
    <template v-if="ctx.data">
      <template v-if="rows.length">
        <div class="dm-table-wrap has-cards">
          <table class="dm-table">
            <thead><tr><th>{{ tt('Reference', 'Reference') }}</th><th>{{ tt('Customer', 'Customer') }}</th><th>{{ tt('Warehouse', 'Warehouse') }}</th><th>{{ tt('Status', 'Status') }}</th><th class="r">{{ tt('Total', 'Total') }}</th><th class="r">{{ tt('Paid', 'Paid') }}</th><th class="r">{{ tt('Due', 'Due') }}</th><th>{{ tt('Payment', 'Payment') }}</th></tr></thead>
            <tbody>
              <tr v-for="s in rows" :key="s.Ref">
                <td><span class="dm-ref">{{ s.Ref }}</span></td><td>{{ s.client_name }}</td><td>{{ s.warehouse_name }}</td>
                <td><span class="dm-pill" :class="cls(s.statut)">{{ s.statut }}</span></td>
                <td class="r">{{ money(s.GrandTotal) }}</td><td class="r">{{ money(s.paid_amount) }}</td><td class="r">{{ money(s.due) }}</td>
                <td><span class="dm-pill" :class="cls(s.payment_status)">{{ s.payment_status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="dm-sales-cards">
          <div v-for="s in rows" :key="s.Ref">
            <div style="min-width:0"><div class="dm-row-name"><span class="dm-ref" style="color:var(--dm-accent);font-weight:700">{{ s.Ref }}</span></div><div class="dm-row-meta">{{ s.client_name }} · {{ s.warehouse_name }}</div>
              <div style="display:flex;gap:4px;margin-top:6px"><span class="dm-pill" :class="cls(s.statut)">{{ s.statut }}</span><span class="dm-pill" :class="cls(s.payment_status)">{{ s.payment_status }}</span></div></div>
            <div class="dm-row-end"><b>{{ money(s.GrandTotal) }}</b><small>{{ tt('Due', 'Due') }} {{ money(s.due) }}</small></div>
          </div>
        </div>
      </template>
      <div v-else class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
    </template>
    <div v-else class="dm-skel" style="height:220px" />
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import ModernCard from '../parts/ModernCard.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const rows = computed(() => (props.ctx.data?.report_dashboard?.original?.last_sales || []).slice(0, 8));
const cls = st => { const s = String(st || '').toLowerCase(); return ['completed', 'received', 'paid'].includes(s) ? 'good' : ['pending', 'partial'].includes(s) ? 'warn' : ['cancelled', 'canceled', 'unpaid'].includes(s) ? 'bad' : ''; };
</script>
