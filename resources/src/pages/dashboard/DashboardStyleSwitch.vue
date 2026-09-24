<template>
  <div v-if="prefs.loaded && prefs.canSwitch" class="dm-styleswitch" :class="{ 'is-dark': ui.dark }">
    <div class="dm-seg dm-seg-sm" role="group" :aria-label="tt('Dashboard_style', 'Dashboard style')">
      <button type="button" :class="{ on: prefs.style === 'classic' }" @click="choose('classic')">{{ tt('Classic', 'Classic') }}</button>
      <button type="button" :class="{ on: prefs.style === 'modern' }" @pointerenter="warm" @focus="warm" @click="choose('modern')">{{ tt('Modern', 'Modern') }}</button>
    </div>
    <a-dropdown v-if="prefs.canSetDefault || prefs.myStyle" :trigger="['click']" placement="bottomRight">
      <button type="button" class="dm-iconbtn dm-iconbtn-sm" :aria-label="tt('More', 'More')"><EllipsisOutlined /></button>
      <template #overlay>
        <a-menu>
          <a-menu-item v-if="prefs.canSetDefault && prefs.style !== prefs.defaultStyle" key="def" @click="makeDefault">{{ tt('Make_default_everyone', 'Make this the default for everyone') }}</a-menu-item>
          <a-menu-item v-if="prefs.myStyle" key="org" @click="useDefault">{{ tt('Use_org_default', 'Use the organisation default') }}</a-menu-item>
        </a-menu>
      </template>
    </a-dropdown>
    <span v-if="failed" class="dm-bad" style="font-size:12px" role="alert">{{ tt('Save_failed_short', 'Could not save your choice.') }}</span>
  </div>
</template>
<script setup>
import { ref } from 'vue';
import { EllipsisOutlined } from '@ant-design/icons-vue';
import { useDashboardPrefsStore } from '../../stores/dashboardPrefs';
import { useUiStore } from '../../stores/ui';
import { useTt } from './modern/useTt';
import { prefetchModernDashboard } from './modern/useModernDashboard';
import './modern/modern.css';

const prefs = useDashboardPrefsStore();
const ui = useUiStore();
const tt = useTt();
const failed = ref(false);
async function run(fn) { failed.value = false; try { await fn(); } catch (e) { failed.value = true; } }
// Start the Modern data requests as soon as the pointer is over the button, so they overlap with the click.
const warm = () => { if (prefs.style !== 'modern') prefetchModernDashboard(); };
function choose(s) {
  if (s === prefs.style) return;
  if (s === 'modern') prefetchModernDashboard();
  run(() => prefs.setStyle(s));
}
const makeDefault = () => run(() => prefs.save({ style: prefs.style }, { asDefault: true }));
const useDefault = () => run(() => prefs.save({ style: null }));
</script>
<style>
.dm-styleswitch { display: inline-flex; align-items: center; gap: 6px; }
/* Outside the Modern page (above Classic) the colour tokens are not inherited from .dm-root, so they are set here. */
.dm-classic-switchrow .dm-styleswitch { --dm-chip: #f0f1fa; --dm-surface: #fff; --dm-ink: #14152b; --dm-muted: #7d8099; --dm-line: #e8e9f4; --dm-ink2: #5a5d7a; --dm-accent: var(--ant-color-primary, #6d28d9); }
.dm-classic-switchrow .dm-styleswitch.is-dark { --dm-chip: #26262d; --dm-surface: #1b1b1f; --dm-ink: #f2f2f6; --dm-muted: #8f8fa0; --dm-line: #2d2d34; --dm-ink2: #babac6; }
.dm-seg-sm { padding: 2px; border-radius: 10px; }
.dm-seg-sm button { min-height: 26px; padding: 4px 10px; font-size: 12px; }
.dm-iconbtn-sm { width: 28px; height: 28px; border-radius: 8px; }
</style>
