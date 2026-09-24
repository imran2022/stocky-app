<template>
  <div class="dm-settings" style="max-width: 680px; margin-bottom: 20px">
    <div style="font-weight: 600; margin-bottom: 4px">{{ tt('Dashboard_style', 'Dashboard style') }}</div>
    <div style="opacity: 0.7; font-size: 12px; margin-bottom: 12px">{{ tt('Dashboard_style_help', 'Choose the dashboard people see by default, and whether they may switch between Classic and Modern.') }}</div>
    <a-form layout="vertical">
      <a-form-item :label="tt('Default_dashboard_everyone', 'Default dashboard for everyone')">
        <a-radio-group :value="prefs.defaultStyle" :disabled="busy" button-style="solid" @change="e => save({ style: e.target.value })">
          <a-radio-button value="classic">{{ tt('Classic', 'Classic') }}</a-radio-button>
          <a-radio-button value="modern">{{ tt('Modern', 'Modern') }}</a-radio-button>
        </a-radio-group>
      </a-form-item>
      <a-form-item>
        <div style="display: flex; align-items: center; gap: 12px">
          <a-switch :checked="prefs.allowUserSwitch" :disabled="busy" @change="v => save({ allow_user_switch: !!v })" />
          <div>
            <div>{{ tt('Allow_user_switch', 'Let users switch between Classic and Modern') }}</div>
            <div style="opacity: 0.7; font-size: 12px">{{ tt('Allow_user_switch_help', 'When off, everyone sees the default above and the switch is hidden. Administrators can still switch.') }}</div>
          </div>
        </div>
      </a-form-item>
    </a-form>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useDashboardPrefsStore } from '../../stores/dashboardPrefs';
import { useTt } from './modern/useTt';

const prefs = useDashboardPrefsStore();
const tt = useTt();
const busy = ref(false);
// Saved at once on its own (not with the big System Settings form), and only for people allowed to change settings.
onMounted(() => prefs.load(true));
async function save(body) {
  busy.value = true;
  try { await prefs.save(body, { asDefault: true }); message.success(tt('Saved', 'Saved')); }
  catch (e) { message.error(tt('Save_failed_short', 'Could not save.')); }
  finally { busy.value = false; }
}
</script>
