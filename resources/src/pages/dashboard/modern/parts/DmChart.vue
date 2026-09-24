<template>
  <div ref="host" class="dm-chart" @pointerleave="hover = -1">
    <svg v-if="w > 0 && n" :width="w" :height="height" role="img" :aria-label="ariaLabel" @pointermove="onMove" @pointerdown="onMove">
      <g>
        <template v-for="(t, i) in yTicks" :key="'y' + i">
          <line :x1="padL" :x2="w - padR" :y1="y(t)" :y2="y(t)" stroke="var(--dm-grid)" stroke-width="1" />
          <text :x="padL - 8" :y="y(t) + 4" text-anchor="end" font-size="11" fill="var(--dm-muted)">{{ compact(t) }}</text>
        </template>
        <template v-for="i in xTickIdx" :key="'x' + i">
          <text :x="cx(i)" :y="height - 6" :text-anchor="xAnchor(i)" font-size="11" fill="var(--dm-muted)">{{ labels[i] }}</text>
        </template>
      </g>
      <template v-if="type === 'bar'">
        <g v-for="(s, si) in series" :key="si">
          <rect v-for="(v, i) in s.values" :key="i" :x="barX(i, si)" :y="barY(v)" :width="barW" :height="Math.max(0, y(0) - barY(v))" :rx="Math.min(4, barW / 2)" :fill="s.color" :opacity="hover === -1 || hover === i ? 1 : 0.45" />
        </g>
      </template>
      <template v-else>
        <defs>
          <linearGradient v-for="(s, si) in series" :id="uid + si" :key="'g' + si" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" :stop-color="s.color" stop-opacity="0.22" /><stop offset="1" :stop-color="s.color" stop-opacity="0" />
          </linearGradient>
        </defs>
        <g v-for="(s, si) in series" :key="si">
          <path v-if="area && n > 1" :d="areaPath(s.values)" :fill="`url(#${uid + si})`" />
          <path v-if="n > 1" :d="linePath(s.values)" fill="none" :stroke="s.color" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round" />
          <circle v-if="n === 1 || hover === -1 && n <= 2" :cx="cx(0)" :cy="y(s.values[0])" r="4" :fill="s.color" />
        </g>
      </template>
      <g v-if="hover >= 0">
        <line :x1="cx(hover)" :x2="cx(hover)" :y1="padT" :y2="y(0)" stroke="var(--dm-muted)" stroke-dasharray="3 3" opacity="0.6" />
        <template v-if="type !== 'bar'">
          <circle v-for="(s, si) in series" :key="si" :cx="cx(hover)" :cy="y(s.values[hover])" r="4.5" :fill="s.color" stroke="var(--dm-surface)" stroke-width="2" />
        </template>
      </g>
    </svg>
    <div v-if="hover >= 0" class="dm-map-tip" :style="tipStyle">
      <b>{{ labels[hover] }}</b>
      <div v-for="(s, si) in series" :key="si" class="dm-dot"><i :style="{ background: s.color }" />{{ s.name }}: <b style="display:inline">{{ fmt(s.values[hover]) }}</b></div>
    </div>
    <div v-if="series.length > 1 || legend" class="dm-legend" style="margin-top:6px">
      <span v-for="(s, si) in series" :key="si" class="dm-dot"><i :style="{ background: s.color }" />{{ s.name }}</span>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { compactNumber } from '../format';

const props = defineProps({
  labels: { type: Array, default: () => [] },
  series: { type: Array, default: () => [] },      // [{name,color,values:[]}]
  type: { type: String, default: 'line' },         // line | bar
  height: { type: Number, default: 240 },
  area: { type: Boolean, default: true },
  legend: { type: Boolean, default: false },
  fmt: { type: Function, default: v => String(v) },
  ariaLabel: { type: String, default: 'Chart' },
});
const host = ref(null); const w = ref(0); const hover = ref(-1);
const uid = `dmg${Math.random().toString(36).slice(2, 8)}`;
let ro;
onMounted(() => {
  w.value = Math.floor(host.value.clientWidth);
  ro = new ResizeObserver(() => { w.value = Math.floor(host.value?.clientWidth || 0); });
  ro.observe(host.value);
});
onBeforeUnmount(() => ro?.disconnect());

const compact = compactNumber;
const n = computed(() => props.labels.length);
const padL = 44, padR = 10, padT = 10;
const padB = 24;
const all = computed(() => props.series.flatMap(s => s.values.map(Number)));
const lo = computed(() => Math.min(0, ...all.value));
const hi = computed(() => {
  const m = Math.max(0, ...all.value);
  return m === 0 ? 1 : m;
});
const step = computed(() => {
  const span = hi.value - lo.value, raw = span / 4, mag = 10 ** Math.floor(Math.log10(raw || 1));
  const f = raw / mag; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * mag;
});
const top = computed(() => Math.ceil(hi.value / step.value) * step.value);
const bottom = computed(() => Math.floor(lo.value / step.value) * step.value);
const yTicks = computed(() => { const t = []; for (let v = bottom.value; v <= top.value + 1e-9; v += step.value) t.push(v); return t; });
const y = v => padT + (1 - (Number(v) - bottom.value) / ((top.value - bottom.value) || 1)) * (props.height - padT - padB);
const plotW = computed(() => Math.max(10, w.value - padL - padR));
const isBar = computed(() => props.type === 'bar');
const cx = i => isBar.value ? padL + (plotW.value / n.value) * (i + 0.5) : (n.value === 1 ? padL + plotW.value / 2 : padL + (plotW.value / (n.value - 1)) * i);
const groupW = computed(() => plotW.value / Math.max(1, n.value));
const barW = computed(() => Math.max(2, Math.min(28, (groupW.value * 0.7) / Math.max(1, props.series.length))));
const barX = (i, si) => cx(i) - (barW.value * props.series.length) / 2 + si * barW.value;
const barY = v => Math.min(y(v), y(0));
const xTickIdx = computed(() => {
  const maxLabels = Math.max(2, Math.floor(plotW.value / 64));
  const every = Math.max(1, Math.ceil(n.value / maxLabels));
  const out = []; for (let i = 0; i < n.value; i += every) out.push(i); return out;
});
const xAnchor = i => (i === 0 && !isBar.value && n.value > 1 ? 'start' : (i === n.value - 1 && !isBar.value && n.value > 1 ? 'end' : 'middle'));
const pts = vals => vals.map((v, i) => [cx(i), y(v)]);
function linePath(vals) {
  const p = pts(vals);
  if (p.length < 3) return p.map((q, i) => `${i ? 'L' : 'M'}${q[0]} ${q[1]}`).join('');
  let d = `M${p[0][0]} ${p[0][1]}`;
  for (let i = 0; i < p.length - 1; i++) {
    const a = p[i - 1] || p[i], b = p[i], c = p[i + 1], e = p[i + 2] || c, k = 0.18;
    const c1 = [b[0] + (c[0] - a[0]) * k, b[1] + (c[1] - a[1]) * k], c2 = [c[0] - (e[0] - b[0]) * k, c[1] - (e[1] - b[1]) * k];
    // keep the curve inside the value range so smoothing never draws a dip below zero that is not in the data
    const cl = v => Math.min(y(bottom.value), Math.max(y(top.value), v));
    d += `C${c1[0]} ${cl(c1[1])} ${c2[0]} ${cl(c2[1])} ${c[0]} ${c[1]}`;
  }
  return d;
}
const areaPath = vals => `${linePath(vals)}L${cx(vals.length - 1)} ${y(0)}L${cx(0)} ${y(0)}Z`;
function onMove(e) {
  const r = host.value.getBoundingClientRect();
  const x = e.clientX - r.left;
  let i = isBar.value ? Math.floor((x - padL) / groupW.value) : Math.round(((x - padL) / plotW.value) * (n.value - 1));
  if (n.value === 1) i = 0;
  hover.value = Math.min(n.value - 1, Math.max(0, i));
}
const tipStyle = computed(() => {
  const x = cx(hover.value);
  const right = x > w.value / 2;
  return right ? { right: `${w.value - x + 12}px`, top: '8px' } : { left: `${x + 12}px`, top: '8px' };
});
</script>
<style>
.dm-chart { position: relative; width: 100%; min-width: 0; }
.dm-chart svg { display: block; touch-action: pan-y; }
</style>
