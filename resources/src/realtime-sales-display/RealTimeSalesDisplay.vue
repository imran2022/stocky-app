<template>
  <div class="display-shell" :class="{ dark: theme === 'dark' }">
    <header>
      <div class="brand">
        <span class="logo"><img :src="logo" alt=""></span>
        <div><h1>{{ company }}</h1><p>{{ displayName }} · {{ warehouseName }}</p></div>
      </div>
      <div class="header-meta">
        <span class="live" :class="{ offline: hasError }"><i></i>{{ hasError ? 'CONNECTION LOST' : 'LIVE' }}</span>
        <span v-if="accessExpiresAt" class="expiry" :class="{ warning: tokenExpiresSoon }">{{ tokenExpiryText }}</span>
        <button class="theme-toggle" :class="{ active: soundEnabled }" type="button" :title="soundEnabled ? 'Mute new sale sound' : 'Enable new sale sound'" @click="toggleSound">
          <svg v-if="soundEnabled" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18 6a8.5 8.5 0 0 1 0 12"/></svg>
          <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="m16 10 5 5M21 10l-5 5"/></svg>
        </button>
        <button class="theme-toggle" type="button" :title="theme === 'dark' ? 'Use light theme' : 'Use dark theme'" @click="toggleTheme">
          <svg v-if="theme === 'dark'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"/></svg>
          <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
        </button>
        <button class="theme-toggle" type="button" :title="isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'" @click="toggleFullscreen">
          <svg v-if="isFullscreen" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>
          <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M15 4h5v5M4 15v5h5M20 15v5h-5"/></svg>
        </button>
        <div class="clock"><span>{{ dateLabel }}</span><strong>{{ timeLabel }}</strong></div>
      </div>
    </header>

    <main>
      <section v-if="accessExpired" class="access-expired">
        <strong>Display access expired</strong>
        <span>Generate a new link from the Real-time Sales Counter page.</span>
      </section>
      <section v-else-if="hasError" class="connection-warning" role="status">
        <div><strong>Connection interrupted</strong><span>Showing the last successfully synced data.</span></div>
        <div><b>{{ consecutiveFailures }}</b><span>failed attempt{{ consecutiveFailures === 1 ? '' : 's' }} · retrying in {{ nextRefreshSeconds }}s</span></div>
        <small>Last successful sync: {{ lastSuccessfulSyncLabel }}</small>
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
        <div class="updated">Last sync {{ updatedRelative }} · {{ hasError ? `Retry ${nextRefreshSeconds}s` : `Next ${nextRefreshSeconds}s` }}</div>
      </section>

      <section v-if="recentSales.length" class="sales-ticker" aria-label="Latest sales">
        <strong>Latest sales</strong>
        <div class="ticker-window"><div class="ticker-track">
          <template v-for="copy in 2" :key="copy">
            <span v-for="sale in recentSales" :key="`${copy}-${sale.id}`">
              <b>{{ sale.Ref || 'Sale' }}</b><i>{{ sale.warehouse_name || 'Warehouse' }}</i><em>{{ money(sale.grand_total) }}</em><small>{{ saleTime(sale.date) }}</small>
            </span>
          </template>
        </div></div>
      </section>

      <transition name="notice">
        <div v-if="newSaleNotice" class="sale-notice" role="status" aria-live="polite">
          <span>New sale received</span><strong>{{ newSaleNotice.Ref || 'Sale recorded' }}</strong><b>{{ money(newSaleNotice.grand_total) }}</b>
          <small>{{ newSaleNotice.warehouse_name || warehouseName }} · {{ saleTime(newSaleNotice.date) }}</small>
        </div>
      </transition>

      <section v-if="loading" class="loading"><i></i><span>Loading live sales…</span></section>
      <template v-else>
        <section v-if="isManager" class="manager-summary">
          <article><span>Top warehouse</span><strong>{{ topWarehouse?.name || '—' }}</strong><small>{{ topWarehouse ? money(topWarehouse.amount) : 'No sales yet' }}</small></article>
          <article><span>Reporting warehouses</span><strong>{{ locations.length }}</strong><small>{{ locations.reduce((sum, item) => sum + Number(item.total_invoice || 0), 0) }} invoices today</small></article>
          <article><span>Average / warehouse</span><strong>{{ money(averageWarehouseSales) }}</strong><small>Based on today’s sales</small></article>
          <article><span>Top warehouse share</span><strong>{{ topWarehouseShare }}%</strong><small>Of total sales today</small></article>
          <article><span>Sales velocity</span><strong>{{ salesVelocity.toFixed(1) }} / hour</strong><small>Since the first sale today</small></article>
          <article><span>Peak sales hour</span><strong>{{ peakSalesHour.label }}</strong><small>{{ peakSalesHour.count ? `${money(peakSalesHour.total)} · ${peakSalesHour.count} sales` : 'No sales yet' }}</small></article>
        </section>

        <section class="dashboard-grid" :class="{ 'manager-layout': isManager }">
          <article class="panel chart-panel">
            <div class="panel-title">
              <h2>Hourly sales today</h2>
              <div class="chart-actions">
                <div class="metric-switch" aria-label="Chart metric">
                  <button type="button" :class="{ active: chartMetric === 'count' }" @click="chartMetric = 'count'">Count</button>
                  <button type="button" :class="{ active: chartMetric === 'amount' }" @click="chartMetric = 'amount'">Amount</button>
                </div>
                <button class="refresh-button" :class="{ spinning: fetching }" type="button" title="Refresh now" :disabled="fetching" @click="fetchData">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                </button>
              </div>
            </div>
            <div class="chart"><apexchart type="bar" height="250" :options="hourlyChartOptions" :series="hourlyChartSeries" /></div>
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
            <div class="panel-title"><h2>{{ isManager ? 'Warehouse performance' : 'Sales by warehouse' }}</h2><span v-if="isManager">Ranked by today’s sales</span></div>
            <div class="location-table">
              <div class="location-row location-head"><span>S/N</span><span>Name</span><span>Total invoice</span><span>Amount</span><span>Last sale</span></div>
              <div v-if="!locations.length" class="empty">No sales yet</div>
              <div v-for="(location,index) in locations" :key="location.warehouse_id || index" class="location-row">
                <span>{{ index + 1 }}</span><strong>{{ location.name }}</strong><span>{{ location.total_invoice }}</span>
                <b>{{ money(location.amount) }}</b><time>{{ locationDateTime(location.last_sale) }}</time>
              </div>
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
      theme: localStorage.getItem('rtsd_theme') || 'light', soundEnabled: localStorage.getItem('rtsd_sound') === 'on',
      loading: true, fetching: false, hasError: false, accessExpired: false, now: Date.now(), refreshSeconds: 30,
      todayCount: 0, todayTotal: 0, todayPaid: 0, todayDue: 0, yesterdayTotal: 0, lastSaleAt: null,
      statuses: { paid: 0, partial: 0, unpaid: 0 }, hourly: [], recentSales: [], topProducts: [], locations: [],
      warehouseName: 'All Warehouses', displayName: 'Live Sales Display', layoutProfile: 'standard',
      currency: '', showCustomerNames: false, lastUpdatedAt: null, lastSuccessfulSyncAt: null,
      consecutiveFailures: 0, chartMetric: 'count', accessExpiresAt: null,
      knownIds: new Set(), newSaleIds: new Set(), bumpCount: false, bumpTotal: false,
      timer: null, clockTimer: null, freshTimer: null, noticeTimer: null, nextRefreshAt: null,
      isFullscreen: false, newSaleNotice: null, audioContext: null,
    };
  },
  computed: {
    averageSale() { return this.todayCount ? this.todayTotal / this.todayCount : 0; },
    isManager() { return this.layoutProfile === 'manager'; },
    topWarehouse() { return this.locations.length ? this.locations[0] : null; },
    averageWarehouseSales() { return this.locations.length ? this.todayTotal / this.locations.length : 0; },
    topWarehouseShare() { return this.todayTotal && this.topWarehouse ? Math.round(Number(this.topWarehouse.amount || 0) / this.todayTotal * 100) : 0; },
    salesVelocity() {
      const activeHours = this.hourly.filter(item => Number(item.count || 0) > 0).map(item => Number(item.hour));
      if (!activeHours.length) return 0;
      const currentHour = new Date(this.lastSuccessfulSyncAt || this.now).getHours();
      return this.todayCount / Math.max(1, Math.max(currentHour, activeHours[activeHours.length - 1]) - activeHours[0] + 1);
    },
    peakSalesHour() {
      const peak = this.hourly.reduce((best, item) => Number(item.total || 0) > Number(best.total || 0) ? item : best, {});
      const count = Number(peak.count || 0), hour = Number(peak.hour || 0);
      if (!count) return { label:'—', total:0, count:0 };
      const time = value => `${String(value % 12 || 12).padStart(2, '0')}:00 ${value < 12 || value === 24 ? 'AM' : 'PM'}`;
      return { label:`${time(hour)} – ${time(hour + 1)}`, total:Number(peak.total || 0), count };
    },
    trend() { return this.yesterdayTotal ? (this.todayTotal - this.yesterdayTotal) / this.yesterdayTotal * 100 : (this.todayTotal > 0 ? 100 : 0); },
    trendText() { const value = Math.abs(this.trend); return `${this.trend >= 0 ? '+' : '-'}${value >= 100 ? value.toFixed(0) : value.toFixed(1)}%`; },
    dateLabel() { return new Date(this.now).toLocaleDateString([], { weekday:'short', day:'2-digit', month:'short', year:'numeric' }); },
    timeLabel() { return new Date(this.now).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit', second:'2-digit' }); },
    lastSaleRelative() { return this.relative(this.lastSaleAt); },
    lastSaleAbsolute() { return this.lastSaleAt ? new Date(this.lastSaleAt).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) : 'No sales yet'; },
    updatedRelative() { return this.relative(this.lastUpdatedAt); },
    lastSuccessfulSyncLabel() { return this.lastSuccessfulSyncAt ? new Date(this.lastSuccessfulSyncAt).toLocaleString() : 'No successful sync yet'; },
    nextRefreshSeconds() { return this.nextRefreshAt ? Math.max(0, Math.ceil((this.nextRefreshAt - this.now) / 1000)) : 0; },
    tokenExpiresSoon() { return this.accessExpiresAt && (new Date(this.accessExpiresAt).getTime() - this.now) <= 3600000; },
    tokenExpiryText() {
      if (!this.accessExpiresAt) return '';
      const seconds = Math.max(0, Math.floor((new Date(this.accessExpiresAt).getTime() - this.now) / 1000));
      if (seconds < 60) return `Link expires in ${seconds}s`;
      if (seconds < 3600) return `Link expires in ${Math.floor(seconds / 60)}m`;
      return `Link expires in ${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
    },
    hourlyChartSeries() {
      const key = this.chartMetric === 'amount' ? 'total' : 'count';
      return [{ name: this.chartMetric === 'amount' ? 'Sales amount' : 'Sales count', data: this.hourly.map(item => Number(item[key] || 0)) }];
    },
    hourlyChartOptions() {
      const isAmount = this.chartMetric === 'amount', dark = this.theme === 'dark';
      const totals = this.hourly.map(item => Number(item.total || 0)), counts = this.hourly.map(item => Number(item.count || 0));
      return {
        chart: { id:'live-hourly-sales', toolbar:{ show:false }, zoom:{ enabled:false }, animations:{ enabled:true, speed:300 }, fontFamily:'inherit', background:'transparent' },
        theme: { mode: dark ? 'dark' : 'light' },
        plotOptions: { bar:{ columnWidth:'55%', borderRadius:6, borderRadiusApplication:'end' } },
        dataLabels: { enabled:false }, colors:['#6d28d9'],
        grid: { borderColor:dark ? 'rgba(148,163,184,.17)' : '#ededf2', strokeDashArray:4, padding:{ left:4, right:8 } },
        xaxis: {
          categories:Array.from({ length:24 }, (_, hour) => `${String(hour).padStart(2, '0')}h`), tickAmount:8,
          labels:{ rotate:0, hideOverlappingLabels:true, style:{ colors:dark ? '#98a6ba' : '#8c8c9c', fontSize:'11px' } },
          axisBorder:{ show:false }, axisTicks:{ show:false }, tooltip:{ enabled:false },
        },
        yaxis: { min:0, forceNiceScale:true, labels:{ style:{ colors:dark ? '#98a6ba' : '#8c8c9c', fontSize:'11px' }, formatter:value => isAmount ? this.compactMoney(value) : Math.round(value) } },
        tooltip: {
          theme:dark ? 'dark' : 'light',
          y:{ formatter:(value, context) => isAmount
            ? `${this.money(value)} · ${counts[context.dataPointIndex] || 0} sales`
            : `${Math.round(value)} sales · ${this.money(totals[context.dataPointIndex] || 0)}` },
        },
        states:{ hover:{ filter:{ type:'lighten', value:.06 } }, active:{ filter:{ type:'none' } } },
        responsive:[{ breakpoint:620, options:{ chart:{ height:220 }, plotOptions:{ bar:{ columnWidth:'68%', borderRadius:4 } }, xaxis:{ tickAmount:6 } } }],
      };
    },
  },
  methods: {
    money(value) {
      const formatted = new Intl.NumberFormat(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 }).format(Number(value || 0));
      return this.currency ? `${this.currency} ${formatted}` : formatted;
    },
    quantity(value) { const n = Number(value || 0); return Number.isInteger(n) ? n : n.toFixed(2); },
    compactMoney(value) {
      const formatted = new Intl.NumberFormat(undefined, { notation:'compact', maximumFractionDigits:1 }).format(Number(value || 0));
      return this.currency ? `${this.currency} ${formatted}` : formatted;
    },
    relative(value) {
      if (!value) return '—';
      const seconds = Math.max(0, Math.floor((this.now - new Date(value).getTime()) / 1000));
      if (seconds < 60) return `${seconds}s ago`;
      if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
      return `${Math.floor(seconds / 3600)}h ago`;
    },
    productWidth(product) { const max = Math.max(1, ...this.topProducts.map(item => Number(item.quantity || 0))); return Math.max(5, Number(product.quantity || 0) / max * 100); },
    saleTime(date) { return date ? new Date(date).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) : '—'; },
    locationDateTime(date) {
      if (!date) return '—';
      return new Date(date).toLocaleString([], { year:'numeric', month:'numeric', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit' });
    },
    toggleTheme() {
      this.theme = this.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('rtsd_theme', this.theme); } catch (e) { /* storage may be blocked */ }
    },
    toggleSound() {
      this.soundEnabled = !this.soundEnabled;
      try { localStorage.setItem('rtsd_sound', this.soundEnabled ? 'on' : 'off'); } catch (e) { /* storage may be blocked */ }
      if (this.soundEnabled) this.prepareAudio();
    },
    prepareAudio() {
      if (!this.audioContext) {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (AudioContext) this.audioContext = new AudioContext();
      }
      if (this.audioContext?.state === 'suspended') this.audioContext.resume().catch(() => {});
    },
    playSaleSound() {
      if (!this.soundEnabled) return;
      this.prepareAudio();
      if (!this.audioContext) return;
      const oscillator = this.audioContext.createOscillator(), gain = this.audioContext.createGain(), start = this.audioContext.currentTime;
      oscillator.type = 'sine'; oscillator.frequency.setValueAtTime(740, start); oscillator.frequency.exponentialRampToValueAtTime(980, start + .16);
      gain.gain.setValueAtTime(.0001, start); gain.gain.exponentialRampToValueAtTime(.12, start + .025); gain.gain.exponentialRampToValueAtTime(.0001, start + .24);
      oscillator.connect(gain); gain.connect(this.audioContext.destination); oscillator.start(start); oscillator.stop(start + .25);
    },
    async toggleFullscreen() {
      try {
        if (!document.fullscreenElement) await document.documentElement.requestFullscreen();
        else await document.exitFullscreen();
      } catch (e) { /* browser or embedding may block fullscreen */ }
    },
    syncFullscreen() { this.isFullscreen = !!document.fullscreenElement; },
    async fetchData() {
      if (this.fetching) return;
      this.fetching = true;
      try {
        const failedAttempts = this.consecutiveFailures;
        const url = `/api/real-time-sales-display/data?token=${encodeURIComponent(this.token)}&failed_attempts=${failedAttempts}`;
        const response = await fetch(url, { headers:{ Accept:'application/json' } });
        if (response.status === 403) {
          this.accessExpired = true;
          this.hasError = true;
          clearTimeout(this.timer);
          this.nextRefreshAt = null;
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
        this.displayName = data.display_name || 'Live Sales Display';
        this.layoutProfile = data.layout_profile === 'manager' ? 'manager' : 'standard';
        this.accessExpiresAt = data.expires_at || this.accessExpiresAt;
        this.showCustomerNames = !!data.show_customer_names; this.refreshSeconds = Math.max(10, Number(data.refresh_seconds || 30));
        const ids = new Set(this.recentSales.map(sale => sale.id));
        const fresh = previousIds.size ? this.recentSales.filter(sale => !previousIds.has(sale.id)).map(sale => sale.id) : [];
        this.knownIds = ids;
        if (fresh.length || (previousCount && this.todayCount > previousCount)) {
          this.pulse(fresh, previousTotal, this.recentSales.find(sale => fresh.includes(sale.id)) || null);
        }
        this.lastUpdatedAt = data.server_time || new Date().toISOString();
        this.lastSuccessfulSyncAt = this.lastUpdatedAt;
        this.consecutiveFailures = 0; this.hasError = false; this.accessExpired = false; this.schedule();
      } catch (error) {
        this.consecutiveFailures += 1;
        this.hasError = true;
        if (!this.accessExpired) this.schedule(this.retryDelay());
      }
      finally { this.loading = false; this.fetching = false; }
    },
    pulse(ids, previousTotal, newestSale) {
      this.newSaleIds = new Set(ids); this.bumpCount = true; this.bumpTotal = this.todayTotal > previousTotal;
      clearTimeout(this.freshTimer); this.freshTimer = setTimeout(() => { this.newSaleIds = new Set(); this.bumpCount = false; this.bumpTotal = false; }, 5000);
      if (newestSale) {
        this.newSaleNotice = newestSale; this.playSaleSound(); clearTimeout(this.noticeTimer);
        this.noticeTimer = setTimeout(() => { this.newSaleNotice = null; }, 4500);
      }
    },
    retryDelay() {
      return [5, 10, 30, 60][Math.min(this.consecutiveFailures - 1, 3)];
    },
    schedule(delaySeconds = this.refreshSeconds) {
      clearTimeout(this.timer); this.nextRefreshAt = Date.now() + delaySeconds * 1000;
      this.timer = setTimeout(this.fetchData, delaySeconds * 1000);
    },
  },
  mounted() {
    this.fetchData(); this.clockTimer = setInterval(() => { this.now = Date.now(); }, 1000);
    document.addEventListener('fullscreenchange', this.syncFullscreen);
  },
  beforeUnmount() {
    clearTimeout(this.timer); clearTimeout(this.freshTimer); clearTimeout(this.noticeTimer); clearInterval(this.clockTimer);
    document.removeEventListener('fullscreenchange', this.syncFullscreen);
    if (this.audioContext) this.audioContext.close().catch(() => {});
  },
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
.theme-toggle.active { border-color:rgba(109,40,217,.35);color:#6d28d9;background:rgba(109,40,217,.07); }
.dark .theme-toggle.active { border-color:rgba(139,108,240,.45);color:#b9a5ff;background:rgba(139,108,240,.12); }
.theme-toggle svg { width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round; }
.header-meta { gap:10px; }
.clock { margin-left:8px; }
.live { color:#389e0d;border-color:#b7eb8f;background:#f6ffed; }
.live i { background:#52c41a;box-shadow:0 0 0 4px rgba(82,196,26,.12); }
.dark .live { color:#6ee7b7;border-color:rgba(52,211,153,.24);background:rgba(16,185,129,.08); }
.expiry { color:var(--muted);font-size:11px;font-variant-numeric:tabular-nums;white-space:nowrap; }
.expiry.warning { padding:5px 8px;border:1px solid #ffe58f;border-radius:6px;color:#d48806;background:#fffbe6; }
.dark .expiry.warning { border-color:rgba(245,158,11,.3);color:#fde68a;background:rgba(245,158,11,.1); }
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
.dashboard-grid {
  margin-top:16px;display:grid;grid-template-columns:minmax(0,14fr) minmax(340px,10fr);
  grid-template-areas:"chart products" "recent locations";gap:16px;
}
.dashboard-grid.manager-layout { grid-template-areas:"locations chart" "recent products"; }
.chart-panel { grid-area:chart; }.products { grid-area:products; }.recent { grid-area:recent; }.locations { grid-area:locations; }
.manager-summary { margin-top:16px;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px; }
.manager-summary article { min-width:0;padding:13px 15px;border:1px solid var(--line);border-radius:8px;background:var(--panel); }
.manager-summary span,.manager-summary small { display:block;color:var(--muted);font-size:11px; }
.manager-summary strong { display:block;margin:5px 0 3px;overflow:hidden;color:var(--text);font-size:17px;text-overflow:ellipsis;white-space:nowrap; }
.connection-warning {
  margin-bottom:16px;padding:12px 15px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:3px 20px;
  border:1px solid #ffe58f;border-radius:8px;color:#874d00;background:#fffbe6;
}
.dark .connection-warning { border-color:rgba(245,158,11,.3);color:#fde68a;background:rgba(245,158,11,.1); }
.connection-warning>div { display:flex;align-items:center;gap:8px; }
.connection-warning>div:nth-child(2) { justify-content:flex-end; }
.connection-warning span,.connection-warning small { color:var(--muted);font-size:11px; }
.connection-warning small { grid-column:1/-1; }
.sales-ticker { margin-top:10px;height:38px;display:flex;align-items:center;overflow:hidden;border:1px solid var(--line);border-radius:8px;background:var(--panel); }
.sales-ticker>strong { height:100%;padding:0 14px;display:flex;align-items:center;flex:none;border-right:1px solid var(--line);color:#6d28d9;font-size:11px;text-transform:uppercase;letter-spacing:.04em; }
.ticker-window { min-width:0;overflow:hidden; }
.ticker-track { width:max-content;display:flex;align-items:center;animation:ticker-scroll 34s linear infinite; }
.ticker-track>span { padding:0 22px;display:flex;align-items:center;gap:8px;border-right:1px solid var(--line);white-space:nowrap;font-size:12px; }
.ticker-track>span>* { color:var(--text);font:inherit;font-size:12px;font-style:normal;font-weight:500; }
.ticker-track>span>*+*::before { margin-right:8px;color:var(--text);content:'·'; }
@keyframes ticker-scroll { to { transform:translateX(-50%); } }
.panel { padding:20px; }
.panel-title { min-height:28px;margin-bottom:16px;align-items:center; }
.panel-title h2 { color:var(--text);font-size:16px;font-weight:600;letter-spacing:0; }
.panel-title>span { color:var(--muted);font-size:12px; }
.chart {
  height:260px;padding:0;border:0;display:block;background:none;
}
.chart :deep(.apexcharts-canvas),.chart :deep(.apexcharts-svg) { max-width:100%; }
.chart-actions,.metric-switch { display:flex;align-items:center; }
.chart-actions { gap:8px; }
.metric-switch { padding:2px;border:1px solid var(--line);border-radius:7px;background:var(--bg); }
.metric-switch button {
  min-width:58px;padding:4px 9px;border:0;border-radius:5px;color:var(--muted);background:transparent;
  font:inherit;font-size:11px;cursor:pointer;
}
.metric-switch button.active { color:#fff;background:#6d28d9;box-shadow:0 1px 2px rgba(109,40,217,.22); }
.refresh-button {
  width:30px;height:30px;padding:0;display:grid;place-items:center;border:1px solid var(--line);border-radius:7px;
  color:var(--muted);background:var(--panel);cursor:pointer;
}
.refresh-button:hover { border-color:#6d28d9;color:#6d28d9; }
.refresh-button:disabled { cursor:default;opacity:.6; }
.refresh-button svg { width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round; }
.refresh-button.spinning svg { animation:spin .8s linear infinite; }
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
  padding-right:10px;padding-left:10px;border-bottom-color:var(--line);border-radius:8px 8px 0 0;
  background:#fafafa;letter-spacing:.025em;text-transform:none;
}
.dark .tr.th { background:rgba(255,255,255,.045); }
.tr>strong { font-weight:600; }
.status { padding:4px 9px;font-size:11px;font-weight:600; }
.status.paid { color:#389e0d;background:#f6ffed; }
.status.partial { color:#d48806;background:#fffbe6; }
.status.unpaid { color:#cf1322;background:#fff1f0; }
.dark .status.paid { color:#6ee7b7;background:rgba(16,185,129,.12); }
.dark .status.partial { color:#fde68a;background:rgba(245,158,11,.12); }
.dark .status.unpaid { color:#fca5a5;background:rgba(239,68,68,.12); }
.location-table { overflow-x:auto; }
.location-row {
  min-width:610px;min-height:62px;padding:11px 10px;display:grid;
  grid-template-columns:40px minmax(130px,1fr) 100px 120px 170px;align-items:center;gap:10px;
  border-bottom:1px solid var(--line);color:var(--text);font-size:13px;
  transition:background-color .18s ease,box-shadow .18s ease;
}
.location-row:last-child { border-bottom:0; }
.location-row>span:first-child { color:#6d28d9; }
.location-row>strong { overflow:hidden;color:var(--text);font-size:13px;font-weight:600;text-overflow:ellipsis;white-space:nowrap; }
.location-row>b { color:var(--text);font-size:13px;font-weight:600;font-variant-numeric:tabular-nums; }
.location-row>time { color:var(--text);font-size:12px;font-variant-numeric:tabular-nums; }
.location-row>:nth-child(n+3) { text-align:right; }
.location-head {
  min-height:38px;border-radius:8px 8px 0 0;background:#fafafa;
  color:var(--muted);font-size:11px;font-weight:600;letter-spacing:.025em;
}
.location-head>span:first-child { color:var(--muted); }
.dark .location-head { background:rgba(255,255,255,.045); }
.sale-notice {
  position:fixed;z-index:20;top:88px;right:24px;min-width:280px;padding:13px 15px;
  display:grid;grid-template-columns:1fr auto;gap:3px 18px;border:1px solid rgba(109,40,217,.22);
  border-radius:9px;background:var(--panel);box-shadow:0 12px 32px rgba(15,23,42,.14);
}
.sale-notice span { grid-column:1/-1;color:#6d28d9;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em; }
.sale-notice strong,.sale-notice b { color:var(--text);font-size:14px; }
.sale-notice b { text-align:right;font-variant-numeric:tabular-nums; }
.sale-notice small { grid-column:1/-1;color:var(--muted);font-size:11px; }
.notice-enter-active,.notice-leave-active { transition:opacity .2s ease,transform .2s ease; }
.notice-enter-from,.notice-leave-to { opacity:0;transform:translateY(-8px); }
.empty { font-size:13px; }
@media(hover:hover) and (pointer:fine){
  .tr:not(.th):hover,.location-row:not(.location-head):hover {
    background:rgba(109,40,217,.055);
    box-shadow:inset 3px 0 0 #6d28d9;
  }
  .dark .tr:not(.th):hover,.dark .location-row:not(.location-head):hover {
    background:rgba(139,108,240,.11);
    box-shadow:inset 3px 0 0 #8b6cf0;
  }
}
@media(max-width:1000px){
  .dashboard-grid{grid-template-columns:1fr;grid-template-areas:"chart" "products" "recent" "locations"}
  .dashboard-grid.manager-layout{grid-template-areas:"locations" "chart" "recent" "products"}
  .manager-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
  .chart{height:250px}
}
@media(min-width:1001px) and (max-width:1350px){.manager-summary{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:620px){
  header{height:68px}
  main{padding:12px}
  .kpis article{min-height:90px;padding:14px}
  .kpis strong{font-size:19px}
  .panel{padding:15px}
  .chart{height:220px;overflow:hidden}
  .chart :deep(svg){min-width:0}
  .theme-toggle{width:32px;height:32px}
  .header-meta{gap:6px}
  .clock{margin-left:2px}
  .expiry{display:none}
  .metric-switch button{min-width:52px;padding:4px 7px}
  .sale-notice{top:78px;right:12px;left:12px;min-width:0}
  .connection-warning{grid-template-columns:1fr;gap:5px}.connection-warning>div:nth-child(2){justify-content:flex-start}.connection-warning small{grid-column:auto}
  .sales-ticker>strong{padding:0 10px}.ticker-track>span{padding:0 14px}
  .manager-summary{gap:8px}.manager-summary article{padding:11px}.manager-summary strong{font-size:15px}
}
@media(prefers-reduced-motion:reduce){.ticker-track{animation:none}}
</style>
