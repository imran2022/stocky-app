<template>
  <ModernCard :title="tt('Sales_map', 'Sales map')" :sub="tt('Sales_map_sub', 'Completed sales by customer city · selected period')">
    <template v-if="ctx.insightsError"><div class="dm-empty"><b>{{ tt('Insights_failed', 'Could not load business insights.') }}</b></div></template>
    <template v-else-if="ins && map">
      <div v-if="!ins.geo.places.length && !ins.geo.unlocated.invoices" class="dm-empty"><b>{{ tt('No_data_available', 'No data available') }}</b></div>
      <div v-else class="dm-map">
        <figure class="dm-map-fig" @pointerleave="tip = null">
          <svg ref="svgEl" :viewBox="vb" :class="{ 'is-zoomed': zoom > 1, 'is-panning': panning }" role="img" :aria-label="tt('Sales_map', 'Sales map')"
            @pointerdown="panStart" @pointermove="panMove" @pointerup="panEnd" @pointercancel="panEnd" @wheel="onWheel">
            <path v-for="d in map.districts" :key="d.id" :d="d.d" :fill="fill(d.id)" tabindex="0" :aria-label="label(d)"
              @pointerenter="show($event, d)" @pointermove="show($event, d)" @pointerdown="show($event, d)" @focus="showFocus(d)" @blur="tip = null" />
            <g class="dm-map-marks">
              <circle v-for="m in marks" :key="'m' + m.id" :cx="m.cx" :cy="m.cy" :r="m.r / zoom" class="dm-map-dot"
                @pointerenter="show($event, m.d)" @pointermove="show($event, m.d)" @pointerdown="show($event, m.d)" />
            </g>
            <g class="dm-map-labels" aria-hidden="true">
              <text v-for="l in labels" :key="'l' + l.id" :x="l.x" :y="l.y" text-anchor="middle" :class="l.sales ? 'has-sales' : 'no-sales'" :font-size="l.size">{{ l.text }}</text>
            </g>
          </svg>
          <div v-if="tip" class="dm-map-tip" :style="{ left: tip.x + 'px', top: tip.y + 'px' }">
            <b>{{ tip.name }}</b>
            <template v-if="tip.amount !== null">{{ money(tip.amount) }} · {{ tip.invoices }} {{ tt('Invoices', 'invoices').toLowerCase() }}</template>
            <template v-else><span class="dm-muted">{{ tt('No_sales', 'No sales in this period') }}</span></template>
          </div>
          <div class="dm-zoom" role="group" :aria-label="tt('Map_zoom', 'Map zoom')">
            <button type="button" :disabled="zoom >= ZMAX" :aria-label="tt('Zoom_in', 'Zoom in')" @click="zoomBy(1.6)">+</button>
            <button type="button" :disabled="zoom <= 1" :aria-label="tt('Zoom_out', 'Zoom out')" @click="zoomBy(1 / 1.6)">−</button>
            <button v-if="zoom > 1" type="button" class="dm-zoom-reset" @click="resetZoom">{{ tt('Reset', 'Reset') }}</button>
          </div>
          <label class="dm-mapnames"><input v-model="showAll" type="checkbox" /> {{ tt('Show_all_names', 'Show all district names') }}</label>
          <div class="dm-scale"><span>{{ tt('Less', 'Less') }}</span><i :style="{ background: 'linear-gradient(90deg, color-mix(in srgb, var(--dm-accent) 12%, var(--dm-chip)), var(--dm-accent))' }" /><span>{{ tt('More', 'More') }}</span></div>
          <div class="dm-attrib">{{ map.attribution }}</div>
        </figure>
        <div>
          <div v-if="ranked.length" class="dm-list" style="gap:11px">
            <div v-for="(r, i) in ranked" :key="r.id" class="dm-row" style="grid-template-columns:28px minmax(0,1fr) auto">
              <span class="dm-rank" style="width:28px;height:28px;font-size:12px">{{ i + 1 }}</span>
              <div class="dm-row-main"><div class="dm-row-name">{{ r.name }}</div><div class="dm-track"><i :style="{ width: r.w + '%', background: 'var(--dm-accent)' }" /></div></div>
              <div class="dm-row-end"><b>{{ money(r.amount) }}</b><small>{{ r.invoices }} {{ tt('Invoices', 'invoices').toLowerCase() }}</small></div>
            </div>
          </div>
          <div v-else class="dm-empty"><b>{{ tt('No_places_on_map', 'None of the customer cities could be placed on the map.') }}</b></div>
          <div class="dm-ins-note" style="margin-top:14px">{{ tt('Map_location_rule', 'A sale is placed by its Zone; if it has no Zone, by the customer\'s city.') }}</div>
          <div v-if="notes.length" class="dm-ins-note" style="margin-top:10px">
            <div v-for="n in notes" :key="n.k">{{ n.text }}</div>
          </div>
        </div>
      </div>
    </template>
    <div v-else class="dm-skel" style="height:320px" />
  </ModernCard>
</template>
<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import ModernCard from '../parts/ModernCard.vue';
import { useFormat } from '../../../../composables/useFormat';
import { useTt } from '../useTt';
import { num } from '../format';

const props = defineProps({ ctx: { type: Object, required: true } });
const { money } = useFormat();
const tt = useTt();
const map = ref(null);
const tip = ref(null);
const showAll = ref(false);
const svgEl = ref(null);
const ZMAX = 8;
const zoom = ref(1);
const cx = ref(0), cy = ref(0); // top-left of the visible window, in map units
const panning = ref(false);
const aspect = ref(0.8);       // width / height of the map box on screen (read from the page, so the view always fills the box)
let drag = null, ro = null;
const W = computed(() => map.value?.viewBox[0] || 1), H = computed(() => map.value?.viewBox[1] || 1);
const VH = computed(() => H.value / zoom.value), VW = computed(() => VH.value * aspect.value);
function clampView() {
  // Wider/taller window than the map: keep it centred. Otherwise keep the window inside the map.
  cx.value = VW.value >= W.value ? (W.value - VW.value) / 2 : Math.min(Math.max(0, cx.value), W.value - VW.value);
  cy.value = VH.value >= H.value ? (H.value - VH.value) / 2 : Math.min(Math.max(0, cy.value), H.value - VH.value);
}
const vb = computed(() => (map.value ? `${cx.value} ${cy.value} ${VW.value} ${VH.value}` : '0 0 1 1'));
function measure() { const r = svgEl.value?.getBoundingClientRect(); if (r && r.width && r.height) { aspect.value = r.width / r.height; clampView(); } }
watch(svgEl, el => { ro?.disconnect(); if (el && typeof ResizeObserver !== 'undefined') { ro = new ResizeObserver(measure); ro.observe(el); } measure(); });
watch(map, () => nextTick(() => { measure(); clampView(); }));
onBeforeUnmount(() => ro?.disconnect());
// Zoom around a point (in map units) so what is under the pointer / centre stays put.
function zoomAt(f, px, py) {
  const nz = Math.min(ZMAX, Math.max(1, zoom.value * f));
  if (nz === zoom.value) return;
  const rx = (px - cx.value) / VW.value, ry = (py - cy.value) / VH.value;
  zoom.value = nz;
  cx.value = px - rx * VW.value; cy.value = py - ry * VH.value;
  clampView();
}
const zoomBy = f => zoomAt(f, cx.value + VW.value / 2, cy.value + VH.value / 2);
function resetZoom() { zoom.value = 1; cx.value = 0; cy.value = 0; clampView(); tip.value = null; }
// Ctrl/Cmd + wheel zooms (a plain wheel keeps scrolling the page).
function onWheel(e) {
  if (!(e.ctrlKey || e.metaKey) || !svgEl.value) return;
  e.preventDefault();
  const r = svgEl.value.getBoundingClientRect();
  zoomAt(e.deltaY < 0 ? 1.25 : 0.8, cx.value + ((e.clientX - r.left) / r.width) * VW.value, cy.value + ((e.clientY - r.top) / r.height) * VH.value);
}
function panStart(e) { if (zoom.value > 1) drag = { x: e.clientX, y: e.clientY, cx: cx.value, cy: cy.value, id: e.pointerId, moved: false }; }
function panMove(e) {
  if (!drag || drag.id !== e.pointerId || !svgEl.value) return;
  const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
  if (!drag.moved && Math.hypot(dx, dy) < 5) return;
  if (!drag.moved) { drag.moved = true; panning.value = true; tip.value = null; try { svgEl.value.setPointerCapture(e.pointerId); } catch (_) {} }
  const r = svgEl.value.getBoundingClientRect();
  cx.value = drag.cx - (dx / r.width) * VW.value;
  cy.value = drag.cy - (dy / r.height) * VH.value;
  clampView();
}
function panEnd() { drag = null; panning.value = false; }
onMounted(async () => { map.value = (await import('../data/bd-districts-map.json')).default; });

const ins = computed(() => props.ctx.insights);
const norm = s => String(s || '').normalize('NFC').toLowerCase().replace(/[^\p{L}\p{M}]/gu, '');
const BD = new Set(['', 'bd', 'bgd', 'bangladesh', 'বাংলাদেশ']);

// City name -> district by the exact alias table only. No fuzzy matching: an unrecognised or ambiguous name is listed as
// "not on the map" instead of being guessed onto the wrong district.
const grouped = computed(() => {
  const byDistrict = {}; const unmatched = []; let foreign = { amount: 0, invoices: 0 };
  if (!ins.value || !map.value) return { byDistrict, unmatched, foreign };
  for (const p of ins.value.geo.places) {
    if (!BD.has(norm(p.country))) { foreign = { amount: foreign.amount + num(p.amount), invoices: foreign.invoices + num(p.invoices) }; continue; }
    const id = map.value.aliases[norm(p.city)];
    if (!id) { unmatched.push(p); continue; }
    const g = byDistrict[id] || (byDistrict[id] = { amount: 0, invoices: 0 });
    g.amount += num(p.amount); g.invoices += num(p.invoices);
  }
  return { byDistrict, unmatched, foreign };
});
const max = computed(() => Math.max(0, ...Object.values(grouped.value.byDistrict).map(g => g.amount)));
function fill(id) {
  const g = grouped.value.byDistrict[id];
  if (!g || g.amount <= 0) return 'var(--dm-chip)';
  const k = Math.round(12 + 88 * Math.sqrt(g.amount / (max.value || 1)));
  return `color-mix(in srgb, var(--dm-accent) ${k}%, var(--dm-chip))`;
}
const label = d => { const g = grouped.value.byDistrict[d.id]; return g ? `${d.name}: ${money(g.amount)}` : `${d.name}: ${tt('No_sales', 'No sales in this period')}`; };
function place(el, d, x, y) {
  const g = grouped.value.byDistrict[d.id];
  const box = el.closest('figure').getBoundingClientRect();
  tip.value = { name: d.name, amount: g ? g.amount : null, invoices: g ? g.invoices : 0, x: Math.min(Math.max(4, x - box.left + 12), Math.max(4, box.width - 200)), y: Math.max(4, y - box.top - 8) };
}
const show = (e, d) => place(e.target, d, e.clientX, e.clientY);
function showFocus(d) {
  const el = document.activeElement; const r = el.getBoundingClientRect();
  place(el, d, r.left + r.width / 2, r.top + r.height / 2);
}
// A dot on every district that has sales (bigger = more sales) and its name always visible; the other names on request.
// Names are placed largest-sales-first and any name that would overlap one already placed is left out (it is still in the list).
const marks = computed(() => {
  if (!map.value) return [];
  const g = grouped.value.byDistrict, top = max.value || 1;
  return map.value.districts.filter(d => g[d.id]?.amount > 0).map(d => ({ id: d.id, cx: d.cx, cy: d.cy, r: 3.5 + 6.5 * Math.sqrt(g[d.id].amount / top), d }));
});
const labels = computed(() => {
  if (!map.value) return [];
  const g = grouped.value.byDistrict, placed = [], out = [], z = zoom.value;
  const cand = map.value.districts.map(d => ({ d, sales: !!(g[d.id]?.amount > 0), amt: g[d.id]?.amount || 0 }))
    .filter(c => c.sales || showAll.value).sort((a, b) => (b.sales - a.sales) || (b.amt - a.amt));
  const hit = (a, b) => a.l < b.r && a.r > b.l && a.t < b.b && a.b > b.t;
  for (const c of cand) {
    const size = (c.sales ? 11 : 7.5) / z, w = c.d.name.length * size * 0.56, h = size * 1.15;
    const mk = marks.value.find(m => m.id === c.d.id);
    const rad = c.sales ? (mk ? mk.r / z : 4 / z) : 0, gap = 2 / z;
    // Where the name can sit: below the dot, above, right, left. A district WITH sales always gets a name (the first spot that does
    // not overlap another name, else below the dot); the "show all names" extras are skipped when they would overlap.
    const spots = c.sales
      ? [[0, rad + size], [0, -rad - gap], [rad + gap + w / 2, size * 0.35], [-rad - gap - w / 2, size * 0.35], [0, rad + size * 2.1], [0, -rad - size * 1.2]]
      : [[0, size * 0.35]];
    let pick = null;
    for (const [dx, dy] of spots) {
      const x = c.d.cx + dx, y = c.d.cy + dy;
      const box = { l: x - w / 2, r: x + w / 2, t: y - h, b: y + gap };
      if (!placed.some(pb => hit(box, pb))) { pick = { x, y, box }; break; }
    }
    if (!pick) {
      if (!c.sales) continue;
      const x = c.d.cx, y = c.d.cy + rad + size;
      pick = { x, y, box: { l: x - w / 2, r: x + w / 2, t: y - h, b: y + gap } };
    }
    placed.push(pick.box);
    out.push({ id: c.d.id, x: pick.x, y: pick.y, size, text: c.d.name, sales: c.sales });
  }
  return out;
});
const ranked = computed(() => {
  const l = Object.entries(grouped.value.byDistrict).map(([id, g]) => ({ id, name: map.value.districts.find(d => String(d.id) === String(id))?.name || id, ...g }))
    .sort((a, b) => b.amount - a.amount).slice(0, 8);
  const top = l[0]?.amount || 1;
  return l.map(r => ({ ...r, w: Math.round((r.amount / top) * 100) }));
});
const notes = computed(() => {
  const g = grouped.value, out = [];
  if (g.unmatched.length) out.push({ k: 'u', text: `${tt('Not_on_map', 'Not on the map')}: ${g.unmatched.slice(0, 6).map(p => `${p.city} (${money(p.amount)})`).join(', ')}${g.unmatched.length > 6 ? ` +${g.unmatched.length - 6}` : ''}` });
  if (g.foreign.invoices) out.push({ k: 'f', text: `${tt('Outside_Bangladesh', 'Outside Bangladesh')}: ${money(g.foreign.amount)} · ${g.foreign.invoices} ${tt('Invoices', 'invoices').toLowerCase()}` });
  const un = ins.value?.geo?.unlocated;
  if (un?.invoices) out.push({ k: 'n', text: `${tt('No_city_set', 'Customers without a city')}: ${money(un.amount)} · ${un.invoices} ${tt('Invoices', 'invoices').toLowerCase()}` });
  return out;
});
</script>
<style>
.dm-map-fig { position: relative; }
.dm-map-fig svg.is-zoomed { cursor: grab; touch-action: none; }
.dm-map-fig svg.is-panning { cursor: grabbing; }
.dm-map-fig svg path { vector-effect: non-scaling-stroke; }
.dm-zoom { position: absolute; top: 8px; right: 8px; display: flex; flex-direction: column; gap: 4px; align-items: stretch; z-index: 2; }
.dm-zoom button { min-width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--dm-line); background: var(--dm-surface); color: var(--dm-ink); font-size: 20px; line-height: 1; font-weight: 600; cursor: pointer; box-shadow: var(--dm-shadow); padding: 0 8px; }
.dm-zoom button:disabled { opacity: 0.4; cursor: default; }
.dm-zoom button:focus-visible { outline: 2px solid var(--dm-accent); outline-offset: 2px; }
.dm-zoom .dm-zoom-reset { font-size: 12px; height: 30px; }
.dm-map-dot { fill: var(--dm-accent); stroke: var(--dm-surface); stroke-width: 1.5px; vector-effect: non-scaling-stroke; opacity: 0.92; cursor: pointer; }
.dm-map-labels text { pointer-events: none; fill: var(--dm-ink2); paint-order: stroke; stroke: var(--dm-surface); stroke-width: 2.6px; stroke-linejoin: round; vector-effect: non-scaling-stroke; }
.dm-map-labels text.has-sales { fill: var(--dm-ink); font-weight: 700; }
.dm-map-labels text.no-sales { opacity: 0.75; }
.dm-mapnames { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--dm-ink2); margin-top: 8px; cursor: pointer; }
</style>
