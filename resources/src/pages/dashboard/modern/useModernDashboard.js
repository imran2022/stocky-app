import { ref, reactive, computed } from 'vue';
import http from '../../../lib/http';
import { useAuthStore } from '../../../stores/auth';
import { localDate, daysInRange } from './format';

const configuredPeriod = v => ({ today: 'today', week: '7d', month: '30d' }[v] || '7d');

function rangeFor(key, customRange) {
  if (key === 'custom' && customRange?.length === 2) return { from: customRange[0], to: customRange[1] };
  const end = new Date();
  const start = new Date();
  if (key === '7d') start.setDate(end.getDate() - 6);
  else if (key === '30d') start.setDate(end.getDate() - 29);
  else if (key === 'mtd') start.setDate(1);
  else if (key === 'ytd') { start.setMonth(0); start.setDate(1); }
  return { from: localDate(start), to: localDate(end) };
}

/**
 * The three requests behind the page, started together:
 *  - dashboard_data with skip=products: every figure except the two slow product rankings,
 *  - dashboard_insights: the extra business numbers,
 *  - dashboard_data with only=products: the two rankings, which are the slowest part on a big database.
 * Each section fills in as its own request answers.
 */
function startRequests(params, withProducts = true) {
  return {
    main: http.get('dashboard_data', { ...params, skip: 'products' }),
    extra: http.get('dashboard_insights', params),
    // The two product rankings are the slowest query. When both of their sections are hidden they are not requested at all.
    products: withProducts ? http.get('dashboard_data', { ...params, only: 'products' }) : Promise.resolve(null),
  };
}

let prefetched = null; // { key, at, req }
const paramsKey = p => JSON.stringify(p);
const PREFETCH_TTL_MS = 20000;

/**
 * Start the requests for the default view before the Modern page has even been downloaded (called while the page
 * chunk loads, e.g. on a click on "Modern" or when the saved choice is Modern). load() then reuses them if they are
 * still fresh and for exactly the same range and warehouse, so nothing shown can be stale or for another filter.
 */
export function prefetchModernDashboard() {
  if (prefetched && Date.now() - prefetched.at < PREFETCH_TTL_MS / 2) return;   // already on its way
  const auth = useAuthStore();
  const r = rangeFor(configuredPeriod(auth.user?.default_dashboard_date_range));
  const params = { warehouse_id: '', from: r.from, to: r.to };
  const req = startRequests(params);
  Object.values(req).forEach(p => p.catch(() => {}));   // an unused prefetch must never raise an unhandled rejection
  prefetched = { key: paramsKey(params), at: Date.now(), req };
}

/**
 * State + loading for the Modern Dashboard. It reads the SAME endpoint as the Classic dashboard (dashboard_data) for every
 * figure the Classic one shows, plus dashboard_insights for the extra ones. Failure is shown as failure: there is no
 * demo data and no zero-filled fallback, so a broken request can never look like a real number.
 */
export function useModernDashboard(opts = {}) {
  const needProducts = () => (typeof opts.needProducts === 'function' ? !!opts.needProducts() : true);
  const auth = useAuthStore();
  const period = ref(configuredPeriod(auth.user?.default_dashboard_date_range));
  const customRange = ref([]);
  const warehouseId = ref('');
  const warehouses = ref([]);

  const loading = ref(true);
  const error = ref(null);
  const data = ref(null);          // dashboard_data response (without the product rankings)
  const insights = ref(null);      // dashboard_insights response (as is)
  const insightsLoading = ref(true);
  const insightsError = ref(null);
  const products = ref(null);      // { product_report, top_products }
  const productsLoading = ref(true);
  const productsError = ref(null);
  const loadCount = ref(0);
  const updatedAt = ref(null);
  const range = reactive({ from: '', to: '' });
  let ticket = 0;

  async function load() {
    const my = ++ticket;
    if (period.value === 'custom' && customRange.value?.length !== 2) {
      const end = new Date(); const start = new Date(); start.setDate(end.getDate() - 6);
      customRange.value = [localDate(start), localDate(end)];
    }
    const r = rangeFor(period.value, customRange.value);
    range.from = r.from; range.to = r.to;
    loading.value = true; insightsLoading.value = true; productsLoading.value = true;
    error.value = null; insightsError.value = null; productsError.value = null;
    const params = { warehouse_id: warehouseId.value, from: r.from, to: r.to };

    let req;
    if (prefetched && prefetched.key === paramsKey(params) && Date.now() - prefetched.at < PREFETCH_TTL_MS) req = prefetched.req;
    else req = startRequests(params, needProducts());
    prefetched = null;

    req.main.then(d => {
      if (my !== ticket) return;
      data.value = d;
      warehouses.value = d.warehouses || [];
      updatedAt.value = new Date();
    }).catch(e => {
      if (my !== ticket) return;
      error.value = e;
    }).finally(() => {
      if (my !== ticket) return;
      loading.value = false;
      loadCount.value++;
    });

    req.extra.then(d => {
      if (my !== ticket) return;
      insights.value = d;
    }).catch(e => {
      if (my !== ticket) return;
      insights.value = null;
      insightsError.value = e;
    }).finally(() => {
      if (my !== ticket) return;
      insightsLoading.value = false;
    });

    req.products.then(d => {
      if (my !== ticket) return;
      products.value = d;   // null = not requested (both sections hidden)
    }).catch(e => {
      if (my !== ticket) return;
      products.value = null;
      productsError.value = e;
    }).finally(() => {
      if (my !== ticket) return;
      productsLoading.value = false;
    });

    await Promise.allSettled([req.main, req.extra, req.products]);
  }

  /** A hidden ranking section was turned on: fetch the rankings for the range on screen (only if they were skipped). */
  async function ensureProducts() {
    if (products.value || productsLoading.value || !data.value) return;
    const my = ticket;
    productsLoading.value = true; productsError.value = null;
    try {
      const d = await http.get('dashboard_data', { warehouse_id: warehouseId.value, from: range.from, to: range.to, only: 'products' });
      if (my === ticket) products.value = d;
    } catch (e) { if (my === ticket) productsError.value = e; }
    finally { if (my === ticket) productsLoading.value = false; }
  }

  // ---- single-source derived values used by several sections ----
  const report = computed(() => data.value?.report_dashboard?.original?.report || {});
  const isToday = computed(() => range.from && range.from === range.to && range.to === localDate(new Date()));
  const isSingleDay = computed(() => range.from && range.from === range.to);
  const dayCount = computed(() => (range.from && range.to ? daysInRange(range.from, range.to) : 1));

  return reactive({
    period, customRange, warehouseId, warehouses, loading, error, data, insights, insightsLoading, insightsError,
    products, productsLoading, productsError, loadCount, ensureProducts,
    updatedAt, range, report, isToday, isSingleDay, dayCount, load,
    hourly: computed(() => data.value?.hourly || null),
    hourlyToday: computed(() => (Array.isArray(data.value?.hourly_sales_today) ? data.value.hourly_sales_today : [])),
  });
}
