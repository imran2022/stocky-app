<template>
  <div class="page">
    <PageHeader :title="$t('Pos_Settings')" :breadcrumb="[$t('Settings'), $t('Pos_Settings')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" :title="$t('Receipt') || 'Receipt'" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item label="Receipt paper size">
              <a-select
                v-model:value="s.receipt_paper_size"
                :options="['80mm', '58mm', 'A4'].map(v => ({ value: v, label: v }))"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Receipt layout"
              extra="Preview each layout live in Settings → POS Receipt."
            >
              <a-select
                v-model:value="s.receipt_layout"
                :options="[
                  { value: 1, label: $t('Layout_1_Standard') },
                  { value: 2, label: $t('Layout_2_Compact') },
                  { value: 3, label: $t('Layout_3_Detailed') },
                  { value: 4, label: $t('Layout_4_Bilingual') },
                  { value: 5, label: $t('Layout_5_Minimal') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Logo size (px)">
              <a-input-number v-model:value="s.logo_size" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-alert
              type="info" show-icon
              message="The receipt content — note and show/hide toggles (logo, reference, taxes, payments…) — is configured in Settings → POS Receipt, with a live preview."
            />
          </a-col>
        </a-row>
      </a-card>

      <a-card size="small" title="POS" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item label="Products per page">
              <a-input-number v-model:value="s.products_per_page" style="width: 100%" :min="1" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Invoice format"
              extra="Format of the invoice printed after a sale — thermal receipt or full A4 page. Colors and layout of the A4 PDF are configured in System Settings → Invoice PDF."
            >
              <a-select
                v-model:value="invoiceFormat"
                :options="[
                  { value: 'thermal', label: 'Thermal receipt' },
                  { value: 'a4', label: 'A4 page' },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Cash drawer printer name">
              <a-input v-model:value="s.cash_drawer_printer_name" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-row :gutter="[8, 8]">
              <a-col v-for="f in POS_FLAGS" :key="f.key" :xs="12" :md="8">
                <a-checkbox v-model:checked="s[f.key]">{{ f.label }}</a-checkbox>
              </a-col>
            </a-row>
          </a-col>
        </a-row>
      </a-card>

      <a-button type="primary" size="large" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
    </a-form>
  </div>
</template>

<script setup>
/**
 * GET get_pos_Settings_api → {pos_settings}; PUT pos_settings/{id} with the
 * POS flags only (cash_drawer_auto_open goes as 1/0, the rest as booleans).
 * invoice_format is sent as a separate top-level field like legacy.
 * Receipt content (note + show/hide toggles) is owned by the POS Receipt page
 * and deliberately NOT sent from here, so the two pages can't overwrite each
 * other with stale values. allow_overselling lives in System Settings >
 * Features now.
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const POS_FLAGS = [
  { key: 'quick_add_customer', label: 'Quick add customer' },
  { key: 'barcode_scanning_sound', label: 'Barcode scanning sound' },
  { key: 'show_product_images', label: 'Show product images' },
  { key: 'show_stock_quantity', label: 'Show stock quantity' },
  { key: 'enable_hold_sales', label: 'Enable hold sales' },
  { key: 'enable_customer_points', label: 'Enable customer points' },
  { key: 'show_categories', label: 'Show categories' },
  { key: 'show_brands', label: 'Show brands' },
  // allow_overselling moved to System Settings > Features (global switch).
  { key: 'cash_drawer_auto_open', label: 'Cash drawer auto open' },
];

const ALL_FLAGS = POS_FLAGS.map(f => f.key);

const loading = ref(true);
const saving = ref(false);
const s = ref({});
const invoiceFormat = ref('');

async function save() {
  saving.value = true;
  const v = s.value;
  const body = {
    logo_size: v.logo_size,
    receipt_paper_size: v.receipt_paper_size,
    receipt_layout: v.receipt_layout,
    products_per_page: v.products_per_page,
    cash_drawer_printer_name: v.cash_drawer_printer_name || null,
    invoice_format: invoiceFormat.value,
  };
  ALL_FLAGS.forEach(k => {
    body[k] = k === 'cash_drawer_auto_open' ? (v[k] ? 1 : 0) : v[k];
  });
  try {
    await http.put(`pos_settings/${v.id}`, body);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_pos_Settings_api');
    const ps = data.pos_settings || {};
    ALL_FLAGS.forEach(k => { ps[k] = !!Number(ps[k] ?? 0) || ps[k] === true; });
    s.value = ps;
    // Global invoice format comes as a top-level field on the response
    // (it lives on the `settings` row, not `pos_settings`).
    invoiceFormat.value = data.invoice_format || ps.invoice_format || 'thermal';
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>
