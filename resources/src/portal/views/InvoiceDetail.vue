<template>
  <div>
    <PageActions>
      <span v-if="invoice" :class="badge(invoiceTone(invoice.payment_status))">{{ statusLabel(invoice.payment_status) }}</span>
      <router-link to="/invoices" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_invoices') }}</router-link>
      <a v-if="invoice" :href="`/api/portal/invoices/${invoice.id}/pdf`" target="_blank" rel="noopener" class="btn btn-primary">
        <i class="ti ti-download me-1"></i>{{ $t('download_pdf') }}
      </a>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading_invoice') }}</div>
    </div>

    <template v-else-if="invoice">
      <div class="pc-invoice-detail-intro d-md-none">
        <div>
          <div class="pc-eyebrow">{{ $t('invoice') }}</div>
          <div class="h1 mb-1 font-monospace">{{ invoice.Ref }}</div>
          <div class="text-secondary">{{ invoice.date }}</div>
        </div>
        <span :class="badge(invoiceTone(invoice.payment_status))">{{ statusLabel(invoice.payment_status) }}</span>
      </div>

      <div class="row row-deck row-cards mb-3 pc-invoice-stats">
        <div class="col-4 col-md-6 col-xl-3"><StatCard :label="$t('total')" :value="money(invoice.GrandTotal)" icon="receipt" tone="blue" :sub="invoice.date" /></div>
        <div class="col-4 col-md-6 col-xl-3"><StatCard :label="$t('paid')" :value="money(invoice.paid_amount)" icon="check" tone="green" /></div>
        <div class="col-4 col-md-6 col-xl-3"><StatCard :label="$t('due')" :value="money(invoice.due)" icon="alert-circle" :tone="Number(invoice.due) > 0 ? 'red' : 'teal'" /></div>
        <div class="col-md-6 col-xl-3 d-none d-md-block"><StatCard :label="$t('date')" :value="invoice.date" icon="calendar" tone="purple" :sub="invoice.warehouse_name || ''" /></div>
      </div>

      <a :href="`/api/portal/invoices/${invoice.id}/pdf`" target="_blank" rel="noopener" class="btn btn-primary btn-lg w-100 mb-3 d-md-none">
        <i class="ti ti-download me-1"></i>{{ $t('download_pdf') }}
      </a>

      <div class="row row-cards align-items-start pc-invoice-detail-grid">
        <div class="col-xl-8">
          <section class="card pc-invoice-items">
            <div class="card-header">
              <div>
                <h3 class="card-title">{{ $t('items') }}</h3>
                <p class="card-subtitle">{{ $t('items_count', (invoice.details || []).length) }}</p>
              </div>
            </div>
            <div class="pc-invoice-items-desktop table-responsive">
              <table class="table table-vcenter card-table">
                <thead><tr><th>{{ $t('product') }}</th><th class="text-end">{{ $t('qty') }}</th><th class="text-end">{{ $t('price') }}</th><th class="text-end">{{ $t('discount') }}</th><th class="text-end">{{ $t('tax') }}</th><th class="text-end">{{ $t('total') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(line, i) in invoice.details" :key="i">
                    <td class="fw-medium">{{ line.product_name }}</td><td class="text-end font-monospace">{{ line.quantity }}</td><td class="text-end font-monospace">{{ money(line.price) }}</td><td class="text-end font-monospace">{{ money(line.DiscountNet) }}</td><td class="text-end font-monospace">{{ money(line.taxe) }}</td><td class="text-end font-monospace fw-bold">{{ money(line.total) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="pc-invoice-items-mobile">
              <article v-for="(line, i) in invoice.details" :key="`mobile-${i}`" class="pc-line-item">
                <h4>{{ line.product_name }}</h4>
                <dl><div><dt>{{ $t('qty') }}</dt><dd>{{ line.quantity }}</dd></div><div><dt>{{ $t('price') }}</dt><dd>{{ money(line.price) }}</dd></div><div><dt>{{ $t('discount') }}</dt><dd>{{ money(line.DiscountNet) }}</dd></div><div><dt>{{ $t('tax') }}</dt><dd>{{ money(line.taxe) }}</dd></div><div class="pc-line-total"><dt>{{ $t('total') }}</dt><dd>{{ money(line.total) }}</dd></div></dl>
              </article>
            </div>
          </section>
        </div>

        <div class="col-xl-4">
          <aside class="card pc-payment-summary">
            <div class="card-header"><h3 class="card-title">{{ tr('payment_summary', 'Payment summary') }}</h3></div>
            <div class="card-body">
              <dl class="pc-summary-list">
                <div><dt>{{ $t('subtotal') }}</dt><dd>{{ money(invoice.subtotal != null ? invoice.subtotal : invoice.GrandTotal) }}</dd></div>
                <div v-if="Number(invoice.TaxNet) > 0"><dt>{{ $t('order_tax') }}</dt><dd>{{ money(invoice.TaxNet) }}</dd></div>
                <div v-if="Number(invoice.discount) > 0"><dt>{{ $t('discount') }}</dt><dd class="text-danger"><template v-if="String(invoice.discount_Method || '2') === '1'">− {{ Number(invoice.discount).toFixed(2) }}%</template><template v-else>− {{ money(invoice.discount) }}</template></dd></div>
                <div v-if="Number(invoice.discount_from_points) > 0"><dt>{{ $t('discount_from_points') }}</dt><dd class="text-danger">− {{ money(invoice.discount_from_points) }}</dd></div>
                <div v-if="Number(invoice.shipping) > 0"><dt>{{ $t('shipping') }}</dt><dd>{{ money(invoice.shipping) }}</dd></div>
                <div class="pc-summary-total"><dt>{{ $t('total') }}</dt><dd>{{ money(invoice.GrandTotal) }}</dd></div>
                <div><dt>{{ $t('paid') }}</dt><dd class="text-success">{{ money(invoice.paid_amount) }}</dd></div>
                <div class="pc-summary-due" :class="{ 'has-due': Number(invoice.due) > 0 }"><dt>{{ $t('amount_due') }}</dt><dd :class="Number(invoice.due) > 0 ? 'text-danger' : ''">{{ money(invoice.due) }}</dd></div>
              </dl>
            </div>
          </aside>
        </div>
      </div>
    </template>

    <div v-else class="card">
      <EmptyState icon="file-off" :title="$t('invoice_not_found')">
        <router-link to="/invoices" class="btn btn-primary">{{ $t('back_to_invoices') }}</router-link>
      </EmptyState>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { money, badge, invoiceTone } from '../lib/ui';
import StatCard from '../components/StatCard.vue';
import EmptyState from '../components/EmptyState.vue';

export default {
  components: { PageActions, StatCard, EmptyState },
  data() { return { invoice: null, loading: true }; },
  mounted() { this.fetch(); },
  methods: {
    money, badge, invoiceTone,
    pageMeta() {
      return {
        title: this.invoice ? this.invoice.Ref : this.$t('invoice'),
        pretitle: this.$t('invoice'),
        crumbs: [{ label: this.$t('invoices'), to: '/invoices' }],
      };
    },
    async fetch() {
      try {
        const { data } = await http.get(`/portal/invoices/${this.$route.params.id}`);
        this.invoice = data;
      } catch (_) {
        this.invoice = null;
      }
      this.loading = false;
      this.applyPage();
    },
  },
};
</script>
