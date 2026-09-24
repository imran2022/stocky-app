<template>
  <svg v-if="path" class="dm-spark" :width="w" :height="h" :viewBox="`0 0 ${w} ${h}`" aria-hidden="true">
    <path :d="area" :fill="color" opacity="0.12" />
    <path :d="path" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
  </svg>
</template>
<script setup>
import { computed } from 'vue';
/** Tiny trend line. Draws nothing unless there are at least 2 points and they are not all the same (a flat line says nothing). */
const props = defineProps({ values: { type: Array, default: () => [] }, color: { type: String, default: 'var(--dm-s1)' }, w: { type: Number, default: 76 }, h: { type: Number, default: 30 } });
const pts = computed(() => {
  const v = (props.values || []).map(Number).filter(Number.isFinite);
  if (v.length < 2) return null;
  const lo = Math.min(...v), hi = Math.max(...v);
  if (hi === lo) return null;
  return v.map((x, i) => [(i / (v.length - 1)) * props.w, props.h - 3 - ((x - lo) / (hi - lo)) * (props.h - 6)]);
});
const path = computed(() => (pts.value ? pts.value.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('') : ''));
const area = computed(() => `${path.value}L${props.w} ${props.h}L0 ${props.h}Z`);
</script>
