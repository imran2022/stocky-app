<template>
  <div :class="{ light: theme === 'light' }" class="customer-display-container">
    <header class="cd-header">
      <div class="brand">
        <span class="logo-box"><img :src="logo" :alt="$t('Store_Logo')" /></span>
        <div><h1>{{ $t('Welcome') }}</h1><p>{{ $t('Thank_you_for_shopping_with_us') }}</p></div>
      </div>
      <div class="clock"><span>{{ formattedDate }}</span><strong>{{ formattedTime }}</strong></div>
    </header>

    <main class="cd-content">
      <section class="items-panel">
        <div class="panel-heading">
          <div><small>{{ $t('pos.Current_Order') }}</small><h2>{{ $t('Items') }}</h2></div>
          <span class="item-count">{{ itemCount }} {{ $t('Items') }}</span>
        </div>

        <div v-if="!hasItems" class="empty-state">
          <span class="empty-icon">
            <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
              <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
          </span>
          <h3>{{ $t('Waiting_for_Items') }}</h3>
          <p>{{ $t('Items_will_appear_here_as_added_to_cart') }}</p>
        </div>

        <div v-else class="table-shell">
          <div class="table-head">
            <span>#</span><span>{{ $t('Product') }}</span><span>{{ $t('Quantity') }}</span>
            <span class="unit">{{ $t('Unit_Price') }}</span><span class="money">{{ $t('Total') }}</span>
          </div>
          <div class="items-scroll">
            <div v-for="(row, idx) in items" :key="`${row.name}-${idx}`" class="item-row"
              :style="{ animationDelay: `${Math.min(idx, 8) * .035}s` }">
              <span class="row-number">{{ String(idx + 1).padStart(2, '0') }}</span>
              <h3>{{ row.name }}</h3>
              <span><b class="qty">{{ formatQuantity(row.quantity) }}</b></span>
              <span class="unit muted money">{{ currency }} {{ format(row.unitPrice) }}</span>
              <strong class="money">{{ currency }} {{ format(row.subtotal) }}</strong>
            </div>
          </div>
        </div>
      </section>

      <aside class="summary-panel">
        <div class="summary-heading"><small>{{ $t('Total_Payable') }}</small><h2>{{ $t('pos.Current_Order') }}</h2></div>
        <div class="breakdown">
          <div><span>{{ $t('Subtotal') }}</span><strong>{{ currency }} {{ format(subtotal) }}</strong></div>
          <div><span>{{ $t('Tax') }}</span><strong>{{ currency }} {{ format(tax) }}</strong></div>
          <div><span>{{ $t('Discount') }}</span><strong class="discount">-{{ currency }} {{ format(discount) }}</strong></div>
          <div><span>{{ $t('Shipping') }}</span><strong>{{ currency }} {{ format(shipping) }}</strong></div>
        </div>
        <div class="total"><span>{{ $t('Total') }}</span><strong>{{ currency }} {{ format(total) }}</strong></div>
        <div class="payment-card">
          <div><span>{{ $t('Paid') }}</span><strong>{{ currency }} {{ format(paidAmount) }}</strong></div>
          <div class="due"><span>{{ $t('Due') }}</span><strong>{{ currency }} {{ format(amountDue) }}</strong></div>
          <div class="progress" role="progressbar" :aria-valuenow="paymentProgress" aria-valuemin="0" aria-valuemax="100">
            <i :style="{ width: `${paymentProgress}%` }"></i>
          </div>
          <p><span>{{ paymentProgress }}%</span><span>100%</span></p>
        </div>
        <footer>{{ footerMessage }}</footer>
      </aside>
    </main>
  </div>
</template>

<script>
import http from '../lib/http';

export default {
  name: 'CustomerDisplay',
  data() {
    const params = window.location.search ? new URLSearchParams(window.location.search) : null;
    return {
      screenId: (params && params.get('screen')) || '1',
      logo: window.__APP_LOGO__ || '/images/logo.png',
      currency: '', items: [], discount: 0, tax: 0, shipping: 0, total: 0,
      theme: localStorage.getItem('cd_theme') || 'dark',
      footerMessage: this.$t('Thank_you_for_your_purchase'),
      now: new Date(), clockTimer: null, pollTimer: null,
    };
  },
  computed: {
    hasItems() { return Array.isArray(this.items) && this.items.length > 0; },
    itemCount() { return this.items.reduce((sum, row) => sum + Number(row.quantity || 0), 0); },
    subtotal() { return this.items.reduce((sum, row) => sum + Number(row.subtotal || 0), 0); },
    paidAmount() { return 0; },
    amountDue() { return Math.max(Number(this.total || 0) - this.paidAmount, 0); },
    paymentProgress() {
      return this.total > 0 ? Math.min(100, Math.max(0, Math.round(this.paidAmount / this.total * 100))) : 0;
    },
    activeLocale() {
      const locale = this.$i18n && this.$i18n.locale;
      return (locale && locale.value) || locale || navigator.language || 'en-US';
    },
    formattedDate() {
      try { return new Intl.DateTimeFormat(this.activeLocale, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }).format(this.now); }
      catch (e) { return this.now.toLocaleDateString(); }
    },
    formattedTime() {
      try { return new Intl.DateTimeFormat(this.activeLocale, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(this.now); }
      catch (e) { return this.now.toLocaleTimeString(); }
    },
  },
  methods: {
    format(value) {
      const number = Number(value || 0);
      try { return new Intl.NumberFormat(this.activeLocale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(number); }
      catch (e) { return number.toFixed(2); }
    },
    formatQuantity(value) {
      const number = Number(value || 0);
      return Number.isInteger(number) ? String(number) : this.format(number);
    },
    applyCart(cart) {
      if (!cart) {
        this.items = []; this.discount = 0; this.tax = 0; this.shipping = 0; this.total = 0; this.currency = '';
        return;
      }
      this.currency = cart.currency || '';
      this.items = (cart.details || cart.items || []).map(item => {
        const quantity = Number(item.quantity || item.Qty || item.qte || 0);
        const unitPrice = Number(item.unit_price || item.Net_price || item.price || 0);
        const lineTotal = Number(item.line_total || item.subtotal || item.total || 0);
        return {
          name: item.name || item.product_name || '',
          quantity,
          unitPrice: Number(isFinite(unitPrice) ? unitPrice : 0),
          subtotal: Number(isFinite(lineTotal > 0 ? lineTotal : unitPrice * quantity) ? (lineTotal > 0 ? lineTotal : unitPrice * quantity) : 0),
        };
      });
      this.discount = Number(cart.discount || (cart.sale && cart.sale.discount) || 0);
      this.tax = Number(cart.TaxNet || (cart.sale && cart.sale.TaxNet) || 0);
      this.shipping = Number(cart.shipping || (cart.sale && cart.sale.shipping) || 0);
      this.total = Number(cart.GrandTotal || cart.total || 0);
    },
    async poll() {
      try {
        const data = await http.get('pos/customer-display/last-cart', { screen: this.screenId });
        if (data && data.cart) this.applyCart(data.cart);
        if (data && data.completed) { this.items = []; this.footerMessage = this.$t('Sale_Completed_Thank_You'); }
      } catch (e) { /* best-effort polling */ }
    },
    setupRealtime() {
      if (!(window.Echo && window.Pusher)) return false;
      try {
        window.Echo.channel(`pos-cart.${this.screenId}`)
          .listen('.CartUpdated', event => {
            if (event && event.cart) this.applyCart(event.cart);
            if (event && event.completed) { this.items = []; this.footerMessage = this.$t('Sale_Completed_Thank_You'); }
          })
          .listenForWhisper('cart-updated', cart => this.applyCart(cart))
          .listenForWhisper('sale-completed', () => {
            this.items = []; this.footerMessage = this.$t('Sale_Completed_Thank_You');
          });
        return true;
      } catch (e) { return false; }
    },
  },
  mounted() {
    this.clockTimer = setInterval(() => { this.now = new Date(); }, 1000);
    if (!this.setupRealtime()) { this.poll(); this.pollTimer = setInterval(this.poll, 2500); }
  },
  beforeUnmount() {
    if (this.clockTimer) clearInterval(this.clockTimer);
    if (this.pollTimer) clearInterval(this.pollTimer);
  },
};
</script>

<style scoped>
:global(*) { box-sizing: border-box; }
.customer-display-container {
  --bg:#060b16; --panel:rgba(13,28,48,.91); --solid:rgba(16,31,52,.96); --line:rgba(148,163,184,.15);
  --muted:#8ea0b8; --text:#f8fafc; --accent:#fb923c; --green:#34d399;
  height:100vh; height:100dvh; min-height:540px; overflow:hidden; display:grid;
  grid-template-rows:auto minmax(0,1fr); color:var(--text);
  background:
    radial-gradient(circle at 5% 8%,rgba(37,99,235,.22),transparent 30%),
    radial-gradient(circle at 78% 8%,rgba(124,58,237,.12),transparent 28%),
    radial-gradient(circle at 98% 96%,rgba(249,115,22,.12),transparent 32%),
    linear-gradient(145deg,#07101f 0%,#081424 48%,#07101b 100%);
  font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
.customer-display-container.light {
  --bg:#eef3f8; --panel:rgba(255,255,255,.95); --solid:#fff; --line:rgba(15,23,42,.11);
  --muted:#64748b; --text:#172033;
}
.cd-header { min-height:92px; padding:16px clamp(20px,2.25vw,42px); display:flex; align-items:center;
  justify-content:space-between; gap:24px; border-bottom:1px solid var(--line); background:rgba(5,13,25,.48); backdrop-filter:blur(18px); }
.light .cd-header { background:rgba(255,255,255,.56); }
.brand { min-width:0; display:flex; align-items:center; gap:16px; }
.logo-box { width:54px; height:54px; padding:5px; display:grid; place-items:center; flex:none; overflow:hidden;
  border:1px solid var(--line); border-radius:14px; background:rgba(255,255,255,.06); }
.logo-box img { max-width:100%; max-height:100%; object-fit:contain; }
.brand h1 { margin:0; font-size:clamp(22px,1.7vw,30px); line-height:1.1; letter-spacing:-.025em; }
.brand p { margin:5px 0 0; color:var(--muted); font-size:clamp(12px,.85vw,15px); }
.clock { flex:none; display:flex; flex-direction:column; align-items:flex-end; gap:3px; }
.clock span { color:var(--muted); font-size:13px; }
.clock strong { font-size:clamp(20px,1.65vw,28px); font-variant-numeric:tabular-nums; letter-spacing:.02em; }
.cd-content { min-height:0; padding:clamp(16px,1.65vw,30px) clamp(20px,2.25vw,42px) clamp(18px,1.8vw,34px);
  display:grid; grid-template-columns:minmax(0,2.35fr) minmax(300px,.95fr); gap:clamp(16px,1.5vw,28px); overflow:hidden; }
.items-panel,.summary-panel { min-width:0; min-height:0; border:1px solid var(--line); border-radius:22px;
  background:var(--panel); box-shadow:0 24px 60px rgba(0,0,0,.18); }
.items-panel { display:grid; grid-template-rows:auto minmax(0,1fr); overflow:hidden; }
.panel-heading { min-height:82px; padding:18px 22px; display:flex; align-items:center; justify-content:space-between;
  gap:18px; border-bottom:1px solid var(--line); }
.panel-heading small,.summary-heading small { display:block; margin-bottom:4px; color:var(--accent); font-size:11px;
  font-weight:800; letter-spacing:.11em; text-transform:uppercase; }
.panel-heading h2,.summary-heading h2 { margin:0; font-size:clamp(20px,1.45vw,26px); letter-spacing:-.025em; }
.item-count { padding:7px 11px; border:1px solid rgba(251,146,60,.2); border-radius:99px; color:#fdba74;
  background:rgba(249,115,22,.1); font-size:12px; font-weight:700; white-space:nowrap; }
.table-shell { min-height:0; display:grid; grid-template-rows:auto minmax(0,1fr); overflow:hidden; }
.table-head,.item-row { display:grid; grid-template-columns:48px minmax(180px,1fr) minmax(72px,.42fr) minmax(116px,.68fr) minmax(130px,.72fr);
  align-items:center; column-gap:12px; }
.table-head { min-height:46px; padding:0 20px; border-bottom:1px solid var(--line); color:var(--muted); background:rgba(0,0,0,.08);
  font-size:11px; font-weight:800; letter-spacing:.075em; text-transform:uppercase; }
.table-head .unit { text-align:right; }
.items-scroll { min-height:0; padding:8px 10px 12px; overflow-y:auto; overscroll-behavior:contain; scrollbar-color:rgba(148,163,184,.35) transparent; scrollbar-width:thin; }
.items-scroll::-webkit-scrollbar { width:6px; } .items-scroll::-webkit-scrollbar-thumb { border-radius:99px; background:rgba(148,163,184,.35); }
.item-row { min-height:68px; padding:9px 10px; border-bottom:1px solid var(--line); animation:row-in .36s ease both; }
.item-row:last-child { border-bottom:0; } .item-row:hover { border-radius:12px; background:rgba(96,165,250,.055); }
.row-number { color:var(--muted); font-size:12px; font-weight:800; font-variant-numeric:tabular-nums; }
.item-row h3 { margin:0; overflow:hidden; color:var(--text); font-size:clamp(14px,1.05vw,18px); font-weight:650;
  line-height:1.35; text-overflow:ellipsis; white-space:nowrap; }
.qty { min-width:42px; padding:6px 10px; display:inline-flex; justify-content:center; border:1px solid var(--line);
  border-radius:9px; background:rgba(148,163,184,.08); font-variant-numeric:tabular-nums; }
.money { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.muted { color:var(--muted); font-size:14px; }
.summary-panel { position:sticky; top:0; padding:clamp(20px,1.8vw,32px); display:flex; flex-direction:column; overflow:auto;
  background:linear-gradient(145deg,rgba(255,255,255,.025),transparent 44%),var(--solid); }
.summary-heading { padding-bottom:20px; border-bottom:1px solid var(--line); }
.breakdown { padding:clamp(20px,2.2vh,28px) 0; display:grid; gap:clamp(14px,1.8vh,22px); border-bottom:1px solid var(--line); }
.breakdown>div,.payment-card>div { display:flex; align-items:center; justify-content:space-between; gap:18px;
  color:var(--muted); font-size:clamp(13px,.9vw,16px); }
.breakdown strong,.payment-card strong { color:var(--text); font-size:clamp(14px,1vw,17px); font-variant-numeric:tabular-nums; white-space:nowrap; }
.breakdown .discount { color:var(--green); }
.total { padding:clamp(22px,3vh,38px) 0; display:flex; flex-direction:column; gap:7px; }
.total span { color:var(--muted); font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
.total strong { color:var(--accent); font-size:clamp(36px,3.25vw,58px); font-weight:850; line-height:1;
  letter-spacing:-.045em; font-variant-numeric:tabular-nums; overflow-wrap:anywhere; }
.payment-card { padding:clamp(16px,1.45vw,23px); border:1px solid rgba(148,163,184,.16); border-radius:16px; background:rgba(4,12,24,.32); }
.light .payment-card { background:rgba(226,232,240,.45); }
.payment-card>div+div { margin-top:13px; } .payment-card .due strong { color:var(--accent); }
.progress { height:6px; margin-top:18px!important; overflow:hidden; border-radius:99px; background:rgba(148,163,184,.18); }
.progress i { height:100%; display:block; border-radius:inherit; background:linear-gradient(90deg,var(--accent),#f97316); transition:width .35s ease; }
.payment-card p { margin:7px 0 0; display:flex; justify-content:space-between; color:var(--muted); font-size:10px; }
.summary-panel footer { margin:auto 0 0; padding-top:clamp(16px,2.6vh,30px); color:var(--muted); font-size:12px; text-align:center; }
.empty-state { min-height:0; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:30px; text-align:center; }
.empty-icon { width:86px; height:86px; display:grid; place-items:center; border:1px solid var(--line); border-radius:50%;
  color:#7090b5; background:rgba(96,165,250,.06); }
.empty-state h3 { margin:20px 0 7px; font-size:22px; } .empty-state p { margin:0; color:var(--muted); font-size:14px; }
@keyframes row-in { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
@media(max-width:1050px) {
  .cd-content { grid-template-columns:minmax(0,1.75fr) minmax(290px,1fr); }
  .table-head,.item-row { grid-template-columns:38px minmax(150px,1fr) 64px minmax(105px,.65fr) minmax(115px,.7fr); column-gap:8px; }
}
@media(max-width:760px) {
  .customer-display-container { min-height:680px; height:auto; overflow:auto; }
  .cd-header { min-height:78px; padding:12px 16px; } .logo-box { width:46px; height:46px; } .brand p,.clock span { display:none; }
  .clock strong { font-size:18px; } .cd-content { padding:14px; grid-template-columns:1fr; overflow:visible; }
  .items-panel { height:min(54vh,520px); min-height:360px; } .summary-panel { position:static; overflow:visible; }
  .table-head,.item-row { grid-template-columns:34px minmax(130px,1fr) 58px minmax(105px,.7fr); } .unit { display:none; }
}
@media(max-width:480px) {
  .brand h1 { font-size:18px; } .panel-heading { min-height:72px; padding:14px 16px; } .table-head { padding:0 12px; }
  .items-scroll { padding-inline:6px; } .table-head,.item-row { grid-template-columns:28px minmax(112px,1fr) 48px minmax(92px,.72fr); column-gap:6px; }
  .item-row { min-height:62px; padding-inline:6px; } .qty { min-width:36px; padding-inline:7px; } .summary-panel { padding:20px; }
}
@media(max-height:700px) and (min-width:761px) {
  .cd-header { min-height:74px; padding-block:10px; } .logo-box { width:46px; height:46px; } .panel-heading { min-height:70px; padding-block:12px; }
  .summary-panel { padding-block:18px; } .summary-heading { padding-bottom:14px; } .breakdown { padding-block:15px; gap:11px; }
  .total { padding-block:18px; } .summary-panel footer { padding-top:12px; }
}
@media(prefers-reduced-motion:reduce) { .item-row,.progress i { animation:none; transition:none; } }
</style>
