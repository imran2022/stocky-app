import { ref, nextTick, onBeforeUnmount } from 'vue';

/**
 * Drag-to-reorder for the Modern Dashboard grid. No dependency: pointer events only, so it works the same with a mouse,
 * a finger or a pen. Dragging starts ONLY from the grip button ([data-handle]) with `touch-action: none`, so scrolling
 * the page with a finger over a card never moves anything by accident. The page auto-scrolls while you drag near the
 * top or bottom edge. Keyboard: focus a grip, then Arrow keys move the section one place.
 *
 * `order` is a ref(string[]) of the section ids in display order; each section element carries data-id="<id>".
 */
export function useSortableGrid({ gridEl, order, enabled, onChange }) {
  const dragging = ref(null);
  const announcement = ref('');
  let el = null, grab = { x: 0, y: 0 }, last = { x: 0, y: 0 }, raf = 0, pointerId = null, handleEl = null;

  const sections = () => Array.from(gridEl.value?.querySelectorAll(':scope > [data-id]') || []);

  function place() {
    if (!el) return;
    el.style.transform = '';
    const base = el.getBoundingClientRect();
    el.style.transform = `translate(${last.x - grab.x - base.left}px, ${last.y - grab.y - base.top}px)`;
  }

  function retarget() {
    const id = dragging.value;
    const hit = document.elementsFromPoint(last.x, last.y).find(n => n.matches?.('[data-id]') && n.parentElement === gridEl.value && n.dataset.id !== id);
    if (!hit) return;
    const r = hit.getBoundingClientRect();
    const dr = el.getBoundingClientRect();
    const sameRow = Math.min(dr.bottom, r.bottom) - Math.max(dr.top, r.top) > Math.min(dr.height, r.height) * 0.4;
    const before = sameRow ? last.x < r.left + r.width / 2 : last.y < r.top + r.height / 2;
    const next = order.value.filter(x => x !== id);
    const at = next.indexOf(hit.dataset.id);
    next.splice(before ? at : at + 1, 0, id);
    if (next.join('|') !== order.value.join('|')) {
      order.value = next;
      nextTick(place);
    }
  }

  function edgeScroll() {
    if (!el) return;
    const zone = 90;
    let v = 0;
    if (last.y < zone) v = -Math.ceil((zone - last.y) / 6);
    else if (last.y > window.innerHeight - zone) v = Math.ceil((last.y - (window.innerHeight - zone)) / 6);
    if (v) { window.scrollBy(0, v); place(); retarget(); }
    raf = requestAnimationFrame(edgeScroll);
  }

  function onMove(e) {
    if (!el || e.pointerId !== pointerId) return;
    last = { x: e.clientX, y: e.clientY };
    place();
    retarget();
  }

  function stop(commit = true) {
    if (!el) return;
    cancelAnimationFrame(raf);
    el.style.transform = '';
    el.classList.remove('is-dragging');
    gridEl.value?.classList.remove('is-sorting');
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', onCancel);
    try { handleEl?.releasePointerCapture?.(pointerId); } catch (e) { /* already released */ }
    const id = dragging.value;
    el = null; dragging.value = null; handleEl = null;
    if (commit) {
      announcement.value = `Moved to position ${order.value.indexOf(id) + 1} of ${order.value.length}`;
      onChange?.(order.value);
    }
  }
  const onUp = () => stop(true);
  const onCancel = () => stop(true);

  function onDown(e) {
    if (!enabled.value || (e.pointerType === 'mouse' && e.button !== 0)) return;
    const handle = e.target.closest?.('[data-handle]');
    if (!handle || !gridEl.value?.contains(handle)) return;
    const section = handle.closest('[data-id]');
    if (!section) return;
    e.preventDefault();
    el = section; handleEl = handle; pointerId = e.pointerId;
    dragging.value = section.dataset.id;
    const r = section.getBoundingClientRect();
    grab = { x: e.clientX - r.left, y: e.clientY - r.top };
    last = { x: e.clientX, y: e.clientY };
    section.classList.add('is-dragging');
    gridEl.value.classList.add('is-sorting');
    try { handle.setPointerCapture(e.pointerId); } catch (err) { /* not capturable: window listeners still work */ }
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    window.addEventListener('pointercancel', onCancel);
    raf = requestAnimationFrame(edgeScroll);
    place();
  }

  /** Keyboard: move one place earlier (-1) or later (+1). */
  function moveBy(id, delta) {
    const i = order.value.indexOf(id);
    const j = i + delta;
    if (i < 0 || j < 0 || j >= order.value.length) return;
    const next = order.value.slice();
    next.splice(j, 0, next.splice(i, 1)[0]);
    order.value = next;
    announcement.value = `Moved to position ${j + 1} of ${next.length}`;
    onChange?.(next);
    nextTick(() => gridEl.value?.querySelector(`[data-id="${id}"] [data-handle]`)?.focus());
  }

  function onKey(e) {
    if (!enabled.value) return;
    const handle = e.target.closest?.('[data-handle]');
    if (!handle) return;
    const id = handle.closest('[data-id]')?.dataset.id;
    const k = e.key;
    if (k === 'ArrowUp' || k === 'ArrowLeft') { e.preventDefault(); moveBy(id, -1); }
    else if (k === 'ArrowDown' || k === 'ArrowRight') { e.preventDefault(); moveBy(id, 1); }
  }

  function attach() {
    gridEl.value?.addEventListener('pointerdown', onDown);
    gridEl.value?.addEventListener('keydown', onKey);
  }
  function detach() {
    gridEl.value?.removeEventListener('pointerdown', onDown);
    gridEl.value?.removeEventListener('keydown', onKey);
    stop(false);
  }
  onBeforeUnmount(detach);

  return { dragging, announcement, attach, detach, moveBy };
}
