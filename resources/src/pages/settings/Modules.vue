<template>
  <div class="page">
    <PageHeader :title="$t('Modules')" :breadcrumb="[$t('Settings'), $t('Modules')]" />
    <ModuleToggles />
  </div>
</template>

<script setup>
/**
 * Standalone sidebar page for module toggles — same component the "Modules"
 * section of System Settings embeds, so both surfaces stay in sync.
 */
import PageHeader from '../../components/PageHeader.vue';
import ModuleToggles from './ModuleToggles.vue';
</script>
