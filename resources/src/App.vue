<template>
  <a-config-provider :theme="theme" :direction="ui.direction" :locale="antLocale">
    <router-view />
  </a-config-provider>
</template>

<script setup>
import { computed } from 'vue';
import { theme as antTheme } from 'ant-design-vue';
import enUS from 'ant-design-vue/es/locale/en_US';
import frFR from 'ant-design-vue/es/locale/fr_FR';
import esES from 'ant-design-vue/es/locale/es_ES';
import arEG from 'ant-design-vue/es/locale/ar_EG';
import { useUiStore } from './stores/ui';

const ui = useUiStore();
ui.initTheme();

const ANT_LOCALES = { en: enUS, fr: frFR, es: esES, ar: arEG };
const antLocale = computed(() => ANT_LOCALES[ui.locale] || enUS);

// Ant's own dark algorithm drives every component; no custom dark CSS needed.
const theme = computed(() => ({
  algorithm: ui.dark ? antTheme.darkAlgorithm : antTheme.defaultAlgorithm,
  token: {
    // Driven by the customizer (persisted in the ui store); defaults to brand.
    colorPrimary: ui.primaryColor,
    borderRadius: 10,
    // Light app background behind the content; dark mode keeps its own.
    ...(ui.dark ? {} : { colorBgLayout: '#fafafa' }),
    // No fontFamily override: `inherit` made every component (tables included)
    // pick up the page font instead of Ant's own stack. Leaving the token unset
    // means tables/forms/menus use Ant Design's default typography.
  },
}));
</script>
