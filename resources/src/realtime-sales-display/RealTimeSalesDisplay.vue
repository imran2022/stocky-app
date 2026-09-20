<template>
  <div class="display-shell" :class="{ dark: theme === 'dark' }">
    <header>
      <div class="brand">
        <span class="logo"><img :src="logo" alt=""></span>
        <div><h1>{{ company }}</h1><p>Live sales overview · {{ warehouseName }}</p></div>
      </div>
      <div class="header-meta">
        <span class="live" :class="{ offline: hasError }"><i></i>{{ hasError ? 'CONNECTION LOST' : 'LIVE' }}</span>
        <button class="theme-toggle" type="button" :title="theme === 'dark' ? 'Use light theme' : 'Use dark theme'" @click="toggleTheme">
          <svg v-if="theme === 'dark'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"/></svg>
          <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
        </button>
        <div class="clock"><span>{{ dateLabel }}</span><strong>{{ timeLabel }}</strong></div>
      </div>
    </header>

    <main>
      <section v-if="accessExpired" class="access-expired">
        <strong>Display access expired</strong>
        <span>Generate a new link from the Real-time Sales Counter page.</span>
      </section>
      <section class="kpis">
        <article :class="{ bump: bumpCount }">
          <div><p>Sales today</p><strong>{{ todayCount }}</strong></div>
        </article>
        <article :class="{ bump: bumpTotal }">
          <div><p>Total today</p><strong>{{ money(todayTotal) }}</strong><small :class="{ down: trend < 0 }">{{ trendText }} vs yesterday</small></div>
        </article>
        <article>
          <div><p>Average sale</p><strong>{{ money(averageSale) }}</strong><small>{{ money(todayPaid) }} paid</small></div>
        </article>
        <article>
          <div><p>Last sale</p><strong>{{ lastSaleRelative }}</strong><small>{{ lastSaleAbsolute }}</small></div>
        </article>
      </section>

      <section class="payment-strip">
        <div><i class="paid"></i><span>Paid</span><strong>{{ statuses.paid }}</strong></div>
        <div><i class="partial"></i><span>Partial</span><strong>{{ statuses.partial }}</strong></div>
        <div><i class="unpaid"></i><span>Unpaid</span><strong>{{ statuses.unpaid }}</strong></div>
        <div class="due"><span>Sales due</span><strong>{{ money(todayDue) }}</strong></div>
        <div class="updated">{{ hasError ? 'Retrying…' : `Updated ${updatedRelative}` }}</div>
      </section>

      <section v-if="loading" class="loading"><i></i><span>Loading live sales…</span></section>
      <template v-else>
        <section class="grid top-grid">
          <article class="panel chart-panel">
            <div class="panel-title"><h2>Hourly sales today</h2><span>24 hours</span></div>
            <div class="chart">
              <svg viewBox="0 0 1000 250" role="img" aria-label="Hourly sales chart">
                <line v-for="line in [30,80,130,180]" :key="line" x1="34" :y1="line" x2="985" :y2="line" class="grid-line"/>
                <g v-for="point in hourly" :key="point.hour">
                  <title>{{ hourName(point.hour) }} — {{ point.count }} sales · {{ money(point.total) }}</title>
                  <rect :x="46 + point.hour * 39" :y="svgBarY(point.count)" width="20" :height="svgBarHeight(point.count)" rx="5" class="chart-bar"/>
                  <text v-if="point.count" :x="56 + point.hour * 39" :y="svgBarY(point.count) - 7" text-anchor="middle" class="bar-label">{{ point.count }}</text>
                  <text v-if="point.hour % 3 === 0" :x="56 + point.hour * 39" y="225" text-anchor="middle" class="axis-label">{{ String(point.hour).padStart(2, '0') }}</text>
                </g>
              </svg>
            </div>
          </article>

          <article class="panel products">
            <div class="panel-title"><h2>Top products today</h2><span>By quantity</span></div>
            <div v-if="!topProducts.length" class="empty">No sales yet</div>
            <div v-for="(product,index) in topProducts" :key="product.product_id || index" class="product">
              <b>{{ String(index + 1).padStart(2, '0') }}</b>
              <div><strong>{{ product.product_name }}</strong><span><i :style="{ width: `${productWidth(product)}%` }"></i></span></div>
              <p><strong>{{ quantity(product.quantity) }}</strong><small>{{ money(product.total) }}</small></p>
            </div>
          </article>
        </section>

        <section class="grid bottom-grid">
          <article class="panel recent" :class="{ 'show-customers': showCustomerNames }">
            <div class="panel-title"><h2>Recent sales</h2><span>Latest {{ recentSales.length }}</span></div>
            <div class="table">
              <div class="tr th"><span>Reference</span><span v-if="showCustomerNames">Customer</span><span>Warehouse</span><span>Status</span><span>Total</span><span>Time</span></div>
              <div v-if="!recentSales.length" class="empty">No sales yet</div>
              <div v-for="sale in recentSales" :key="sale.id" class="tr" :class="{ fresh: newSaleIds.has(sale.id) }">
                <strong>{{ sale.Ref }}</strong><span v-if="showCustomerNames">{{ sale.client_name || '—' }}</span>
                <span>{{ sale.warehouse_name || '—' }}</span><span><b class="status" :class="sale.payment_status">{{ sale.payment_status }}</b></span>
                <strong>{{ money(sale.grand_total) }}</strong><span>{{ saleTime(sale.date) }}</span>
              </div>
            </div>
          </article>

          <article class="panel locations">
            <div class="panel-title"><h2>Sales by warehouse</h2></div>
            <div v-if="!locations.length" class="empty">No sales yet</div>
            <div v-for="(location,index) in locations" :key="location.warehouse_id || index" class="location">
              <span>{{ index + 1 }}</span><div><strong>{{ location.name }}</strong><small>{{ location.total_invoice }} invoices</small></div>
              <b>{{ money(location.amount) }}</b>
            </div>
          </article>
        </section>
      </template>
    </main>
  </div>
</template>

<script>
export default {
  name: 'RealTimeSalesDisplay',
  data() {
    return {
      token: window.__RTSD_TOKEN__ || '', logo: window.__RTSD_LOGO__ || '', company: window.__RTSD_COMPANY__ || 'Stocky',
      theme: localStorage.getItem('rtsd_theme') || 'light',
      loading: true, fetching: false, hasError: false, accessExpired: false, now: Date.now(), refreshSeconds: 30,
      todayCount: 0, todayTotal: 0, todayPaid: 0, todayDue: 0, yesterdayTotal: 0, lastSaleAt: null,
      statuses: { paid: 0, partial: 0, unpaid: 0 }, hourly: [], recentSales: [], topProducts: [], locations: [],
      warehouseName: 'All Warehouses', currency: '', showCustomerNames: false, lastUpdatedAt: null,
      knownIds: new Set(), newSaleIds: new Set(), bumpCount: false, bumpTotal: false, timer: null, clockTimer: null, freshTimer: null,
    };
  },
  computed: {
    averageSale() { return this.todayCount ? this.todayTotal / this.todayCount : 0; },
    trend() { return this.yesterdayTotal ? (this.todayTotal - this.yesterdayTotal) / this.yesterdayTotal * 100 : (this.todayTotal > 0 ? 100 : 0); },
    trendText() { const value = Math.abs(this.trend); return `${this.trend >= 0 ? '+' : '-'}${value >= 100 ? value.toFixed(0) : value.toFixed(1)}%`; },
    dateLabel() { return new Date(this.now).toLocaleDateString([], { weekday:'short', day:'2-digit', month:'short', year:'numeric' }); },
    timeLabel() { return new Date(this.now).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit', second:'2-digit' }); },
    lastSaleRelative() { return this.relative(this.lastSaleAt); },
    lastSaleAbsolute() { return this.lastSaleAt ? new Date(this.lastSaleAt).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) : 'No sales yet'; },
    updatedRelative() { return this.relative(this.lastUpdatedAt); },
  },
  methods: {
    money(value) {
      const formatted = new Intl.NumberFormat(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 }).format(Number(value || 0));
      return this.currency ? `${this.currency} ${formatted}` : formatted;
    },
    quantity(value) { const n = Number(value || 0); return Number.isInteger(n) ? n : n.toFixed(2); },
    relative(value) {
      if (!value) return '—';
      const seconds = Math.max(0, Math.floor((this.now - new Date(value).getTime()) / 1000));
      if (seconds < 60) return `${seconds}s ago`;
      if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
      return `${Math.floor(seconds / 3600)}h ago`;
    },
    hourName(hour) { return `${String(hour).padStart(2, '0')}:00`; },
    svgBarHeight(count) { const max = Math.max(1, ...this.hourly.map(item => Number(item.count || 0))); return count ? Math.max(6, Number(count) / max * 165) : 3; },
    svgBarY(count) { return 198 - this.svgBarHeight(count); },
    productWidth(product) { const max = Math.max(1, ...this.topProducts.map(item => Number(item.quantity || 0))); return Math.max(5, Number(product.quantity || 0) / max * 100); },
    saleTime(date) { return date ? new Date(date).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) : '—'; },
    toggleTheme() {
      this.theme = this.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('rtsd_theme', this.theme); } catch (e) { /* storage may be blocked */ }
    },
    async fetchData() {
      if (this.fetching) return;
      this.fetching = true;
      try {
        const response = await fetch(`/api/real-time-sales-display/data?token=${encodeURIComponent(this.token)}`, { headers:{ Accept:'application/json' } });
        if (response.status === 403) {
          this.accessExpired = true;
          this.hasError = true;
          clearTimeout(this.timer);
          return;
        }
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        const previousCount = this.todayCount, previousTotal = this.todayTotal, previousIds = this.knownIds;
        this.todayCount = Number(data.today_count || 0); this.todayTotal = Number(data.today_total || 0);
        this.todayPaid = Number(data.today_paid || 0); this.todayDue = Number(data.today_due || 0);
        this.yesterdayTotal = Number(data.yesterday_total || 0); this.lastSaleAt = data.last_sale_at || null;
        this.statuses = data.payment_status_counts || { paid:0, partial:0, unpaid:0 };
        this.hourly = Array.isArray(data.hourly) ? data.hourly : []; this.recentSales = Array.isArray(data.recent_sales) ? data.recent_sales : [];
        this.topProducts = Array.isArray(data.top_products) ? data.top_products : []; this.locations = Array.isArray(data.sales_by_location) ? data.sales_by_location : [];
        this.warehouseName = data.selected_warehouse_name || 'All Warehouses'; this.currency = data.currency || '';
        this.showCustomerNames = !!data.show_customer_names; this.refreshSeconds = Math.max(10, Number(data.refresh_seconds || 30));
        const ids = new Set(this.recentSales.map(sale => sale.id));
        const fresh = previousIds.size ? this.recentSales.filter(sale => !previousIds.has(sale.id)).map(sale => sale.id) : [];
        this.knownIds = ids;
        if (fresh.length || (previousCount && this.todayCount > previousCount)) this.pulse(fresh, previousTotal);
        this.lastUpdatedAt = new Date().toISOString(); this.hasError = false; this.accessExpired = false; this.schedule();
      } catch (error) { this.hasError = true; if (!this.accessExpired) this.schedule(); }
      finally { this.loading = false; this.fetching = false; }
    },
    pulse(ids, previousTotal) {
      this.newSaleIds = new Set(ids); this.bumpCount = true; this.bumpTotal = this.todayTotal > previousTotal;
      clearTimeout(this.freshTimer); this.freshTimer = setTimeout(() => { this.newSaleIds = new Set(); this.bumpCount = false; this.bumpTotal = false; }, 5000);
    },
    schedule() { clearTimeout(this.timer); this.timer = setTimeout(this.fetchData, this.refreshSeconds * 1000); },
  },
  mounted() { this.fetchData(); this.clockTimer = setInterval(() => { this.now = Date.now(); }, 1000); },
  beforeUnmount() { clearTimeout(this.timer); clearTimeout(this.freshTimer); clearInterval(this.clockTimer); },
};
</script>

<style scoped>
:global(*){box-sizing:border-box}.display-shell{--bg:#060b16;--panel:rgba(15,29,49,.92);--line:rgba(148,163,184,.15);--text:#f8fafc;--muted:#8fa1b8;--orange:#fb923c;
min-height:100vh;color:var(--text);background:radial-gradient(circle at 8% 0%,rgba(37,99,235,.2),transparent 29%),radial-gradient(circle at 82% 5%,rgba(124,58,237,.13),transparent 26%),radial-gradient(circle at 100% 100%,rgba(249,115,22,.1),transparent 28%),linear-gradient(145deg,#07101f,#081424 48%,#07101b);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
header{height:86px;padding:14px clamp(22px,2.4vw,44px);display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);background:rgba(5,13,25,.5);backdrop-filter:blur(18px)}
.brand,.header-meta{display:flex;align-items:center}.brand{gap:14px}.logo{width:52px;height:52px;padding:5px;display:grid;place-items:center;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.06)}.logo img{max-width:100%;max-height:100%}
.brand h1{margin:0;font-size:23px;letter-spacing:-.03em}.brand p{margin:4px 0 0;color:var(--muted);font-size:12px}.header-meta{gap:26px}.live{padding:7px 11px;border:1px solid rgba(52,211,153,.2);border-radius:99px;color:#6ee7b7;background:rgba(16,185,129,.08);font-size:10px;font-weight:800;letter-spacing:.08em}.live i{width:7px;height:7px;margin-right:7px;display:inline-block;border-radius:50%;background:#34d399;box-shadow:0 0 0 5px rgba(52,211,153,.1)}.live.offline{color:#fca5a5;border-color:rgba(248,113,113,.2);background:rgba(239,68,68,.08)}.live.offline i{background:#f87171;box-shadow:none}.clock{display:flex;flex-direction:column;align-items:flex-end}.clock span{color:var(--muted);font-size:11px}.clock strong{font-size:21px;font-variant-numeric:tabular-nums}
main{padding:clamp(16px,1.6vw,28px) clamp(22px,2.4vw,44px) 32px}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.kpis article,.panel,.payment-strip{border:1px solid var(--line);border-radius:17px;background:var(--panel);box-shadow:0 18px 45px rgba(0,0,0,.16)}
.kpis article{min-width:0;padding:18px;display:flex;align-items:center;gap:15px}.kpi-icon{width:42px;height:42px;display:grid;place-items:center;flex:none;border-radius:12px;font-weight:900}.blue{color:#60a5fa;background:rgba(59,130,246,.12)}.orange{color:#fdba74;background:rgba(249,115,22,.12)}.violet{color:#c4b5fd;background:rgba(139,92,246,.12)}.green{color:#6ee7b7;background:rgba(16,185,129,.12)}
.kpis p{margin:0 0 5px;color:var(--muted);font-size:12px}.kpis strong{display:block;overflow:hidden;font-size:clamp(20px,1.55vw,28px);line-height:1.1;text-overflow:ellipsis;white-space:nowrap;font-variant-numeric:tabular-nums}.kpis small{display:block;margin-top:5px;color:#34d399;font-size:10px}.kpis small.down{color:#f87171}
.payment-strip{margin-top:16px;padding:12px 17px;display:flex;align-items:center;gap:24px}.payment-strip>div{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:12px}.payment-strip i{width:7px;height:7px;border-radius:50%}.payment-strip .paid{background:#34d399}.payment-strip .partial{background:#fbbf24}.payment-strip .unpaid{background:#f87171}.payment-strip strong{color:var(--text);font-size:13px}.payment-strip .due{padding-left:24px;border-left:1px solid var(--line)}.payment-strip .due strong{color:var(--orange)}.payment-strip .updated{margin-left:auto;font-size:10px}
.grid{display:grid;gap:16px;margin-top:16px}.top-grid{grid-template-columns:minmax(0,1.65fr) minmax(310px,.85fr)}.bottom-grid{grid-template-columns:minmax(0,1.65fr) minmax(310px,.85fr)}.panel{min-width:0;padding:18px;overflow:hidden}.panel-title{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px}.panel-title p{margin:0 0 3px;color:var(--orange);font-size:9px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.panel-title h2{margin:0;font-size:17px;letter-spacing:-.02em}.panel-title>span{color:var(--muted);font-size:10px}
.access-expired{margin-bottom:16px;padding:13px 16px;display:flex;justify-content:space-between;gap:20px;border:1px solid rgba(248,113,113,.25);border-radius:12px;color:#fecaca;background:rgba(127,29,29,.2);font-size:12px}.access-expired span{color:#fca5a5}
.chart{height:190px;padding-top:10px;display:flex;align-items:flex-end;gap:clamp(3px,.5vw,8px);border-bottom:1px solid var(--line)}.bar-slot{height:100%;min-width:0;display:flex;flex:1;flex-direction:column;justify-content:flex-end;align-items:center}.bar{width:min(20px,75%);min-height:2px;border-radius:5px 5px 1px 1px;background:linear-gradient(180deg,#8b5cf6,#3b82f6);transition:height .4s ease}.bar-value{height:15px;color:var(--muted);font-size:8px}.bar-slot>span{height:19px;padding-top:6px;color:var(--muted);font-size:8px}
.product{padding:9px 0;display:grid;grid-template-columns:25px minmax(0,1fr) auto;align-items:center;gap:10px;border-bottom:1px solid var(--line)}.product:last-child{border:0}.product>b{color:var(--muted);font-size:10px}.product div>strong{display:block;overflow:hidden;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.product div>span{height:4px;margin-top:7px;display:block;overflow:hidden;border-radius:99px;background:rgba(148,163,184,.12)}.product div i{height:100%;display:block;border-radius:inherit;background:linear-gradient(90deg,#3b82f6,#8b5cf6)}.product p{margin:0;text-align:right}.product p strong{font-size:12px}.product p small{display:block;margin-top:3px;color:var(--muted);font-size:9px}
.table{overflow-x:auto}.tr{min-width:650px;min-height:39px;padding:7px 4px;display:grid;grid-template-columns:1.15fr 1fr .8fr .9fr .65fr;align-items:center;gap:10px;border-bottom:1px solid var(--line);font-size:10px}.recent.show-customers .tr{grid-template-columns:1.15fr 1fr 1fr .8fr .9fr .65fr}.tr.th{min-height:30px;color:var(--muted);font-size:8px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.tr>strong:nth-last-child(2){text-align:right;font-variant-numeric:tabular-nums}.tr.fresh{border-radius:8px;background:rgba(139,92,246,.12)}.status{padding:4px 7px;border-radius:99px;color:#fca5a5;background:rgba(239,68,68,.1);font-size:8px;text-transform:capitalize}.status.paid{color:#6ee7b7;background:rgba(16,185,129,.1)}.status.partial{color:#fde68a;background:rgba(245,158,11,.1)}
.location{padding:11px 0;display:grid;grid-template-columns:24px minmax(0,1fr) auto;align-items:center;gap:10px;border-bottom:1px solid var(--line)}.location:last-child{border:0}.location>span{color:var(--muted);font-size:10px}.location div strong{display:block;font-size:12px}.location small{display:block;margin-top:3px;color:var(--muted);font-size:9px}.location>b{font-size:12px;font-variant-numeric:tabular-nums}.empty{padding:36px;color:var(--muted);text-align:center;font-size:12px}.loading{height:55vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:var(--muted)}.loading i{width:28px;height:28px;border:3px solid rgba(148,163,184,.18);border-top-color:#8b5cf6;border-radius:50%;animation:spin .8s linear infinite}.bump{animation:bump .6s ease}
@keyframes spin{to{transform:rotate(360deg)}}@keyframes bump{30%{transform:scale(1.025)}}
@media(max-width:1000px){.kpis{grid-template-columns:repeat(2,1fr)}.top-grid,.bottom-grid{grid-template-columns:1fr}.chart{height:220px}}
@media(max-width:620px){header{height:76px;padding:11px 14px}.logo{width:44px;height:44px}.brand h1{font-size:17px}.brand p,.live,.clock span{display:none}.clock strong{font-size:17px}main{padding:12px}.kpis{gap:9px}.kpis article{padding:13px;gap:10px}.kpi-icon{width:34px;height:34px}.kpis strong{font-size:17px}.payment-strip{gap:12px;overflow-x:auto}.payment-strip .due{padding-left:12px}.payment-strip .updated{display:none}.panel{padding:14px}.chart{height:170px}}
@media(prefers-reduced-motion:reduce){.bar,.bump,.loading i{animation:none;transition:none}}

/* Match the authenticated Real-time Sales Counter: light Ant-style cards by
   default, with the same purple chart accent. Dark is an explicit choice. */
.display-shell {
  --bg:#f5f5f7; --panel:#fff; --solid:#fff; --line:#ededf2;
  --text:#1f1f2c; --muted:#8c8c9c; --orange:#6d28d9;
  color:var(--text);
  background:var(--bg);
}
.display-shell.dark {
  --bg:#0b1220; --panel:#111c2e; --solid:#111c2e; --line:rgba(148,163,184,.17);
  --text:#f5f7fb; --muted:#98a6ba; --orange:#8b6cf0;
  background:#0b1220;
}
header {
  height:76px;
  border-bottom-color:var(--line);
  background:rgba(255,255,255,.96);
  box-shadow:0 1px 3px rgba(15,23,42,.04);
}
.dark header { background:rgba(12,20,34,.96); }
.logo { width:46px;height:46px;border-color:var(--line);border-radius:10px;background:#fafafa; }
.dark .logo { background:rgba(255,255,255,.06); }
.brand h1 { font-size:20px;font-weight:650; }
.brand p { color:var(--muted);font-size:12px; }
.clock strong { color:var(--text);font-size:19px; }
.theme-toggle {
  width:34px;height:34px;padding:0;display:grid;place-items:center;border:1px solid var(--line);
  border-radius:7px;color:#595969;background:var(--panel);cursor:pointer;
}
.dark .theme-toggle { color:#d8deea; }
.theme-toggle:hover { border-color:#6d28d9;color:#6d28d9; }
.theme-toggle svg { width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round; }
.live { color:#389e0d;border-color:#b7eb8f;background:#f6ffed; }
.live i { background:#52c41a;box-shadow:0 0 0 4px rgba(82,196,26,.12); }
.dark .live { color:#6ee7b7;border-color:rgba(52,211,153,.24);background:rgba(16,185,129,.08); }
main { padding:20px clamp(20px,2vw,32px) 28px; }
.kpis { gap:16px; }
.kpis article,.panel,.payment-strip {
  border-color:var(--line);border-radius:8px;background:var(--panel);
  box-shadow:0 1px 2px rgba(15,23,42,.025);
}
.kpis article { min-height:104px;padding:18px 20px; }
.kpis article>div { min-width:0;width:100%; }
.kpis p { margin-bottom:9px;color:rgba(0,0,0,.45);font-size:13px; }
.dark .kpis p { color:var(--muted); }
.kpis strong { color:var(--text);font-size:clamp(22px,1.7vw,30px);font-weight:600; }
.kpis small { margin-top:7px;color:#52c41a;font-size:12px; }
.kpis small.down { color:#ff4d4f; }
.payment-strip { min-height:52px;padding:12px 18px; }
.payment-strip>div { font-size:13px; }
.payment-strip strong { font-size:14px; }
.payment-strip .due strong { color:#6d28d9; }
.payment-strip .updated { font-size:12px; }
.top-grid,.bottom-grid { grid-template-columns:minmax(0,14fr) minmax(340px,10fr); }
.panel { padding:20px; }
.panel-title { min-height:28px;margin-bottom:16px;align-items:center; }
.panel-title h2 { color:var(--text);font-size:16px;font-weight:600;letter-spacing:0; }
.panel-title>span { color:var(--muted);font-size:12px; }
.chart {
  height:260px;padding:0;border:0;display:block;
  background-image:linear-gradient(to bottom,transparent calc(100% - 1px),var(--line) 1px);
}
.chart svg { width:100%;height:100%;overflow:visible; }
.grid-line { stroke:var(--line);stroke-width:1;stroke-dasharray:4 5; }
.chart-bar { fill:#6d28d9; }
.dark .chart-bar { fill:#8b6cf0; }
.bar-label { fill:var(--muted);font-size:10px;font-weight:600; }
.axis-label { fill:var(--muted);font-size:10px; }
.product { min-height:47px;padding:10px 2px; }
.product>b { font-size:12px; }
.product div>strong { color:var(--text);font-size:13px;font-weight:500; }
.product div>span { height:6px;margin-top:8px; }
.product div i { background:#6d28d9; }
.product p strong { color:var(--text);font-size:13px; }
.product p small { font-size:11px; }
.tr {
  min-width:720px;min-height:48px;padding:9px 4px;gap:14px;
  color:var(--text);font-size:13px;
  transition:background-color .18s ease,box-shadow .18s ease;
}
.tr.th {
  min-height:38px;color:var(--muted);font-size:11px;font-weight:600;
  letter-spacing:.025em;text-transform:none;
}
.tr>strong { font-weight:600; }
.status { padding:4px 9px;font-size:11px;font-weight:600; }
.status.paid { color:#389e0d;background:#f6ffed; }
.status.partial { color:#d48806;background:#fffbe6; }
.status.unpaid { color:#cf1322;background:#fff1f0; }
.dark .status.paid { color:#6ee7b7;background:rgba(16,185,129,.12); }
.dark .status.partial { color:#fde68a;background:rgba(245,158,11,.12); }
.dark .status.unpaid { color:#fca5a5;background:rgba(239,68,68,.12); }
.location {
  min-height:62px;padding:12px 3px;grid-template-columns:28px minmax(0,1fr) auto;
  transition:background-color .18s ease,box-shadow .18s ease;
}
.location>span { color:#6d28d9;font-size:12px; }
.location div strong { color:var(--text);font-size:14px;font-weight:600; }
.location small { margin-top:5px;font-size:12px; }
.location>b { color:var(--text);font-size:14px;font-weight:600; }
.empty { font-size:13px; }
@media(hover:hover) and (pointer:fine){
  .tr:not(.th):hover,.location:hover {
    background:rgba(109,40,217,.055);
    box-shadow:inset 3px 0 0 #6d28d9;
  }
  .dark .tr:not(.th):hover,.dark .location:hover {
    background:rgba(139,108,240,.11);
    box-shadow:inset 3px 0 0 #8b6cf0;
  }
}
@media(max-width:1000px){
  .top-grid,.bottom-grid{grid-template-columns:1fr}
  .chart{height:250px}
}
@media(max-width:620px){
  header{height:68px}
  main{padding:12px}
  .kpis article{min-height:90px;padding:14px}
  .kpis strong{font-size:19px}
  .panel{padding:15px}
  .chart{height:210px;overflow-x:auto}
  .chart svg{min-width:760px}
  .theme-toggle{width:32px;height:32px}
}
</style>
