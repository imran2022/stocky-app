<template>
  <section class="dm-card" :aria-label="title">
    <header v-if="title || $slots.extra || to" class="dm-card-head">
      <div style="min-width:0">
        <div class="dm-card-title">{{ title }}</div>
        <div v-if="sub" class="dm-card-sub">{{ sub }}</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex:none">
        <slot name="extra" />
        <button v-if="to" type="button" class="dm-link" @click="$router.push(to)">{{ tt('View_all', 'View all') }}</button>
      </div>
    </header>
    <div class="dm-card-body"><slot /></div>
  </section>
</template>
<script setup>
import { useTt } from '../useTt';
defineProps({ title: { type: String, default: '' }, sub: { type: String, default: '' }, to: { type: String, default: '' } });
const tt = useTt();
</script>
