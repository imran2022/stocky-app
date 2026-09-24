<template>
  <ModernCard :title="tt('Quick_Actions', 'Quick actions')">
    <div v-if="actions.length" class="dm-tiles">
      <button v-for="a in actions" :key="a.to" type="button" class="dm-tile" @click="$router.push(a.to)">
        <span class="dm-kpi-ic" :style="{ background: `color-mix(in srgb, ${a.color} 14%, transparent)`, color: a.color }"><component :is="a.icon" /></span>
        <span>{{ a.label }}</span>
      </button>
    </div>
    <div v-else class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
  </ModernCard>
</template>
<script setup>
import { computed } from 'vue';
import { CalculatorOutlined, ShoppingCartOutlined, ShoppingOutlined, AppstoreOutlined, TeamOutlined, RiseOutlined } from '@ant-design/icons-vue';
import ModernCard from '../parts/ModernCard.vue';
import { useAuthStore } from '../../../../stores/auth';
import { useI18n } from 'vue-i18n';
import { useTt } from '../useTt';
defineProps({ ctx: { type: Object, required: true } });
const { t } = useI18n();
const tt = useTt();
const auth = useAuthStore();
// Same actions and permissions as the Classic dashboard.
const actions = computed(() => [
  { label: t('Pos'), perm: 'Pos_view', to: '/pos', icon: CalculatorOutlined, color: 'var(--dm-accent)' },
  { label: t('New_Sale'), perm: 'Sales_add', to: '/sales/create', icon: ShoppingCartOutlined, color: 'var(--dm-s1)' },
  { label: t('New_Purchase'), perm: 'Purchases_add', to: '/purchases/create', icon: ShoppingOutlined, color: 'var(--dm-s2)' },
  { label: t('Add_Product'), perm: 'products_add', to: '/products/create', icon: AppstoreOutlined, color: 'var(--dm-s3)' },
  { label: t('Add_Customer'), perm: 'Customers_add', to: '/customers/create', icon: TeamOutlined, color: 'var(--dm-s4)' },
  { label: t('Reports'), perm: 'Reports_profit', to: '/reports/profit-and-loss', icon: RiseOutlined, color: 'var(--dm-good)' },
].filter(a => auth.can(a.perm)));
</script>
