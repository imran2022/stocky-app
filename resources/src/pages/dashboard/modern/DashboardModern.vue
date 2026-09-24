<template>
  <div class="dm-root" :class="{ 'is-dark': ui.dark, 'is-editing': editing, 'is-refreshing': ctx.loading && !!ctx.data }" :style="rootStyle">
    <header class="dm-head">
      <div style="min-width:0">
        <h1>{{ greeting }}</h1>
        <div class="dm-sub">{{ ctx.updatedAt ? `${tt('Updated', 'Updated')} ${ctx.updatedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : tt('Dashboard_Live_Subtitle', 'Live figures from your business') }}</div>
      </div>
      <div class="dm-controls">
        <DashboardStyleSwitch />
        <div class="dm-seg" role="group" :aria-label="tt('Period', 'Period')">
          <button v-for="o in periods" :key="o.value" type="button" :class="{ on: ctx.period === o.value }" @click="setPeriod(o.value)">{{ o.label }}</button>
        </div>
        <a-range-picker v-if="ctx.period === 'custom'" v-model:value="ctx.customRange" value-format="YYYY-MM-DD" :allow-clear="false" @change="ctx.load()" />
        <a-select v-model:value="ctx.warehouseId" :options="warehouseOptions" @change="ctx.load()" />
        <ViewCurrencySelect />
        <button type="button" class="dm-btn" :disabled="ctx.loading" @click="ctx.load()"><ReloadOutlined :spin="ctx.loading" />{{ tt('Refresh', 'Refresh') }}</button>
        <button v-if="!editing" type="button" class="dm-btn dm-customize" @click="startEdit"><LayoutOutlined />{{ tt('Customize', 'Customize') }}</button>
      </div>
    </header>

    <div v-if="editing" class="dm-toolbar" role="region" :aria-label="tt('Customize', 'Customize')">
      <p>{{ tt('Customize_help', 'Drag the purple grip (or focus it and use the arrow keys) to reorder. Use the eye to hide a section.') }}</p>
      <button type="button" class="dm-btn primary" :disabled="saving" @click="save(false)">{{ saving ? tt('Saving', 'Saving…') : tt('Save', 'Save') }}</button>
      <button v-if="prefs.canSetDefault" type="button" class="dm-btn" :disabled="saving" @click="save(true)">{{ tt('Save_for_everyone', 'Save as default for everyone') }}</button>
      <button type="button" class="dm-btn" :disabled="saving" @click="reset">{{ tt('Reset_layout', 'Reset layout') }}</button>
      <button type="button" class="dm-btn ghost" :disabled="saving" @click="cancel">{{ tt('Cancel', 'Cancel') }}</button>
    </div>
    <div v-if="saveError" class="dm-err" role="alert" style="margin-bottom:12px"><span>{{ tt('Save_failed', 'Could not save the layout. Nothing was changed.') }}</span></div>

    <div v-if="ctx.error && !ctx.data" class="dm-card" role="alert">
      <div class="dm-empty">
        <b>{{ tt('Dashboard_load_failed', 'The dashboard could not be loaded.') }}</b>
        <span>{{ tt('Dashboard_load_failed_help', 'No numbers are shown rather than wrong ones. Please try again.') }}</span>
        <button type="button" class="dm-btn primary" @click="ctx.load()">{{ tt('Retry', 'Retry') }}</button>
      </div>
    </div>
    <template v-else>
      <div v-if="ctx.error" class="dm-err" role="alert" style="margin-bottom:12px">
        <span>{{ tt('Refresh_failed', 'The latest refresh failed, so the numbers below may be out of date.') }}</span>
        <button type="button" class="dm-btn" @click="ctx.load()">{{ tt('Retry', 'Retry') }}</button>
      </div>
      <div ref="gridEl" class="dm-grid">
        <div v-for="id in visible" :key="id" class="dm-sec" :class="'dm-w-' + SECTION_BY_ID[id].span" :data-id="id">
          <div v-if="editing" class="dm-editbar">
            <button type="button" class="dm-handle" data-handle :aria-label="`${tt('Move', 'Move')}: ${title(id)}`" :title="tt('Drag_to_move', 'Drag to move')"><HolderOutlined /></button>
            <span class="name">{{ title(id) }}</span>
            <button type="button" class="dm-iconbtn" :aria-label="`${tt('Hide', 'Hide')}: ${title(id)}`" :title="tt('Hide', 'Hide')" @click="hide(id)"><EyeInvisibleOutlined /></button>
          </div>
          <component :is="COMPONENTS[id]" :ctx="ctx" :class="PLAIN.has(id) ? 'dm-plain' : ''" />
        </div>
      </div>
      <div v-if="editing && hiddenIds.length" class="dm-tray">
        <b>{{ tt('Hidden_sections', 'Hidden sections') }}</b>
        <button v-for="id in hiddenIds" :key="id" type="button" class="dm-btn" @click="show(id)"><EyeOutlined />{{ title(id) }}</button>
      </div>
    </template>
    <div class="dm-sr" aria-live="polite">{{ sorter.announcement.value }}</div>
    <MobileNav v-if="!editing" />
</div>
</template>

<script setup>
import MobileNav from './parts/MobileNav.vue';
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick, defineAsyncComponent } from 'vue';
import { ReloadOutlined, LayoutOutlined, HolderOutlined, EyeOutlined, EyeInvisibleOutlined } from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../../../stores/auth';
import { useUiStore } from '../../../stores/ui';
import { useDashboardPrefsStore } from '../../../stores/dashboardPrefs';
import ViewCurrencySelect from '../../../components/ViewCurrencySelect.vue';
import DashboardStyleSwitch from '../DashboardStyleSwitch.vue';
import { useTt } from './useTt';
import { useModernDashboard } from './useModernDashboard';
import { useSortableGrid } from './useSortableGrid';
import { SECTION_BY_ID, normaliseLayout } from './sections';
import './modern.css';

import KpisSection from './sections/KpisSection.vue';
import InsightsSection from './sections/InsightsSection.vue';
import AttentionSection from './sections/AttentionSection.vue';
import SalesPurchasesSection from './sections/SalesPurchasesSection.vue';
import TopProductsDonutSection from './sections/TopProductsDonutSection.vue';
import SalesByPaymentSection from './sections/SalesByPaymentSection.vue';
import StockValueSection from './sections/StockValueSection.vue';
import QuickActionsSection from './sections/QuickActionsSection.vue';
import PaymentsChartSection from './sections/PaymentsChartSection.vue';
import TopCustomersSection from './sections/TopCustomersSection.vue';
import StockAlertSection from './sections/StockAlertSection.vue';
import TopProductsListSection from './sections/TopProductsListSection.vue';
import RecentActivitySection from './sections/RecentActivitySection.vue';
import HourlySalesSection from './sections/HourlySalesSection.vue';
import SalesByWarehouseSection from './sections/SalesByWarehouseSection.vue';
import RecentSalesSection from './sections/RecentSalesSection.vue';
const SalesMapSection = defineAsyncComponent(() => import('./sections/SalesMapSection.vue'));

const COMPONENTS = {
  kpis: KpisSection, insights: InsightsSection, attention: AttentionSection, sales_purchases: SalesPurchasesSection,
  top_products_donut: TopProductsDonutSection, sales_by_payment: SalesByPaymentSection, stock_value: StockValueSection,
  quick_actions: QuickActionsSection, payments_chart: PaymentsChartSection, top_customers: TopCustomersSection,
  stock_alert: StockAlertSection, top_products_list: TopProductsListSection, recent_activity: RecentActivitySection,
  hourly_sales: HourlySalesSection, sales_by_warehouse: SalesByWarehouseSection, sales_map: SalesMapSection, recent_sales: RecentSalesSection,
};
const PLAIN = new Set(['kpis', 'insights', 'attention']);

const { t } = useI18n();
const tt = useTt();
const auth = useAuthStore();
const ui = useUiStore();
const prefs = useDashboardPrefsStore();
const ctx = useModernDashboard({ needProducts: () => ['top_products_donut', 'top_products_list'].some(id => !hidden.value.includes(id)) });

const rootStyle = computed(() => ({ '--dm-accent': ui.primaryColor || '#6d28d9', maxWidth: '1680px', marginInline: 'auto' }));
const greeting = computed(() => {
  const h = new Date().getHours();
  const part = h < 12 ? t('Good_Morning') : h < 18 ? t('Good_Afternoon') : t('Good_Evening');
  return auth.username ? `${part}, ${auth.username}` : part;
});
const periods = computed(() => [
  { value: 'today', label: t('Today') }, { value: '7d', label: '7D' }, { value: '30d', label: '30D' },
  { value: 'mtd', label: 'MTD' }, { value: 'ytd', label: 'YTD' }, { value: 'custom', label: t('Custom') },
]);
const warehouseOptions = computed(() => [{ value: '', label: t('All_Warehouses') }, ...ctx.warehouses.map(w => ({ value: w.id, label: w.name }))]);
function setPeriod(v) { ctx.period = v; ctx.load(); }
const title = id => tt(SECTION_BY_ID[id].key, SECTION_BY_ID[id].title);

/* ---------------------------------------------------------- layout editing */
const editing = ref(false);
const saving = ref(false);
const saveError = ref(false);
const order = ref(normaliseLayout(prefs.layout).order);
const hidden = ref(normaliseLayout(prefs.layout).hidden);
const visible = computed(() => order.value.filter(id => editing.value || !hidden.value.includes(id)).filter(id => !hidden.value.includes(id)));
const hiddenIds = computed(() => order.value.filter(id => hidden.value.includes(id)));
watch(() => prefs.layout, l => { if (!editing.value) { const n = normaliseLayout(l); order.value = n.order; hidden.value = n.hidden; } }, { deep: true });

const needsProducts = computed(() => ['top_products_donut', 'top_products_list'].some(id => !hidden.value.includes(id)));
watch(needsProducts, v => { if (v) ctx.ensureProducts(); });
const gridEl = ref(null);
const sorter = useSortableGrid({ gridEl, order, enabled: editing });
watch(gridEl, (el, old) => { if (old) sorter.detach(); if (el) sorter.attach(); });

function startEdit() { saveError.value = false; editing.value = true; }
function cancel() { const n = normaliseLayout(prefs.layout); order.value = n.order; hidden.value = n.hidden; editing.value = false; saveError.value = false; }
function hide(id) { if (!hidden.value.includes(id)) hidden.value = [...hidden.value, id]; }
function show(id) { hidden.value = hidden.value.filter(x => x !== id); }
async function save(asDefault) {
  saving.value = true; saveError.value = false;
  try { await prefs.save({ layout: { order: order.value, hidden: hidden.value } }, { asDefault }); editing.value = false; }
  catch (e) { saveError.value = true; }
  finally { saving.value = false; }
}
async function reset() {
  saving.value = true; saveError.value = false;
  try { await prefs.resetLayout(); const n = normaliseLayout(prefs.layout); order.value = n.order; hidden.value = n.hidden; editing.value = false; }
  catch (e) { saveError.value = true; }
  finally { saving.value = false; }
}

onMounted(() => { ctx.load(); nextTick(() => gridEl.value && sorter.attach()); });
onBeforeUnmount(() => sorter.detach());
</script>
<style>
.dm-root.is-refreshing .dm-grid { opacity: 0.6; transition: opacity 0.15s; }
</style>
