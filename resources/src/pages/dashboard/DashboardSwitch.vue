<template>
  <div>
    <div v-if="style === 'classic' && prefs.loaded && prefs.canSwitch" class="dm-classic-switchrow"><DashboardStyleSwitch /></div>
    <component :is="style === 'modern' ? Modern : Classic" />
  </div>
</template>
<script setup>
import { computed, onMounted, defineAsyncComponent, h } from 'vue';
import { useDashboardPrefsStore, cachedDashboardStyle } from '../../stores/dashboardPrefs';
import { prefetchModernDashboard } from './modern/useModernDashboard';
import DashboardStyleSwitch from './DashboardStyleSwitch.vue';
import Classic from '../Dashboard.vue';

const loadModern = () => import('./modern/DashboardModern.vue');
const Modern = defineAsyncComponent({ loader: loadModern, loadingComponent: { render: () => h('div', { class: 'dm-skel', style: 'height:220px;border-radius:20px;background:rgba(128,128,128,.12)' }) }, delay: 0 });
const prefs = useDashboardPrefsStore();

// Until the server answers, use the choice remembered in this browser, so the right dashboard starts loading straight away.
const style = computed(() => (prefs.loaded ? prefs.style : (cachedDashboardStyle() || 'classic')));

if (!prefs.loaded && cachedDashboardStyle() === 'modern') prefetchModernDashboard();   // data requests overlap with the page download

onMounted(() => {
  prefs.load();
  // Classic users who may switch: download the Modern page quietly once the browser is idle, so switching is instant.
  const idle = window.requestIdleCallback || (fn => setTimeout(fn, 1500));
  idle(() => { if (prefs.canSwitch && style.value === 'classic') loadModern().catch(() => {}); });
});
</script>
<style>
.dm-classic-switchrow { display: flex; justify-content: flex-end; margin: 0 0 4px; min-height: 30px; }
</style>
