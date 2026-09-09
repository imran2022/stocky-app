<template>
  <div class="page">
    <PageHeader :title="$t('Settings')" :breadcrumb="[$t('Store'), $t('Settings')]">
      <template #extra>
        <a-button v-if="!isLoading" type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-tabs tab-position="left" class="settings-tabs">
        <!-- ===== Store Basics ===== -->
        <a-tab-pane key="basics">
          <template #tab><span class="stab"><ShopOutlined /> {{ $t('Store_Basics') }}</span></template>
          <a-card size="small" :bordered="false">
        <a-row :gutter="[16, 8]">
          <a-col :xs="24" :md="8">
            <a-form-item label="Online Store URL" :extra="'When disabled, the /online_store pages will be inaccessible.'">
              <a-switch v-model:checked="form.enabled" />
              <span style="margin-left: 8px">{{ form.enabled ? 'Enabled' : 'Disabled' }}</span>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Store_Name')">
              <a-input v-model:value="form.store_name" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Store_Theme')" :extra="$t('Store_Theme_Hint')">
              <a-select v-model:value="form.theme">
                <a-select-option value="default">{{ $t('Default_Store_Theme') }}</a-select-option>
                <a-select-option value="real_estate">{{ $t('Real_Estate_Theme') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Currency')" extra="⚠️ Changing currency will affect both system and online store">
              <a-select
                v-model:value="form.default_currency_id" :placeholder="$t('Choose_Currency')"
                :options="currencies.map(c => ({ label: `${c.name} (${c.symbol})`, value: Number(c.id) }))"
                show-search option-filter-prop="label"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Warehouses')">
              <a-checkbox v-model:checked="form.store_all_warehouses" style="margin-bottom: 6px">
                {{ $t('All_Warehouses') }}
              </a-checkbox>
              <a-select
                v-if="!form.store_all_warehouses"
                v-model:value="form.warehouse_ids" mode="multiple" :placeholder="$t('Choose_Warehouse')"
                :options="warehouses.map(w => ({ label: w.name, value: Number(w.id) }))"
                show-search option-filter-prop="label" :max-tag-count="3" style="width: 100%"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Font_Family')">
              <a-input v-model:value="form.font_family" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Primary_Color')">
              <input v-model="form.primary_color" type="color" class="color-input" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Secondary_Color')">
              <input v-model="form.secondary_color" type="color" class="color-input" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-divider orientation="left">{{ $t('Registration_Access_Control') }}</a-divider>
        <a-row :gutter="[16, 8]">
          <a-col :xs="24" :md="6">
            <a-form-item :label="$t('Public_Registration')" :extra="$t('Public_Registration_Help')">
              <a-switch v-model:checked="form.registration_enabled" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="6">
            <a-form-item :label="$t('Require_Invite_Code')" :extra="$t('Require_Invite_Code_Help')">
              <a-switch v-model:checked="form.require_invite_code" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="6">
            <a-form-item :label="$t('Require_Admin_Approval')" :extra="$t('Require_Admin_Approval_Help')">
              <a-switch v-model:checked="form.require_admin_approval" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="6">
            <a-form-item :label="$t('Require_Email_Verification')" :extra="$t('Require_Email_Verification_Help')">
              <a-switch v-model:checked="form.require_email_verification" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-alert
          v-if="pendingCustomersCount > 0"
          type="warning" show-icon style="margin-bottom: 12px"
          :message="`${pendingCustomersCount} ${$t('Pending_Customers_Awaiting_Approval')}`"
        >
          <template #action>
            <a-button size="small" @click="$router.push('/store/pending-customers')">{{ $t('Review') }}</a-button>
          </template>
        </a-alert>
        <div v-if="form.require_invite_code" class="quick-link">
          <span class="muted">{{ $t('Manage_invite_codes_from_dedicated_page') }}</span>
          <a-button size="small" @click="$router.push('/store/invite-codes')">{{ $t('Manage_Invite_Codes') }}</a-button>
        </div>

        <a-divider orientation="left">{{ $t('Shipping_and_Tax') }}</a-divider>
        <a-row :gutter="[16, 12]">
          <a-col v-for="link in QUICK_LINKS" :key="link.to" :xs="24" :md="12">
            <div class="quick-link">
              <span class="muted">{{ $t(link.label) }}</span>
              <a-button size="small" @click="$router.push(link.to)">{{ $t('Manage') }}</a-button>
            </div>
          </a-col>
        </a-row>

        <a-divider orientation="left">{{ $t('Product_Reviews') }}</a-divider>
        <a-row :gutter="[16, 8]">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Auto_Approve_Reviews')" :extra="$t('Auto_Approve_Reviews_Help')">
              <a-switch v-model:checked="form.auto_approve_reviews" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Cookie_Consent_Banner')" :extra="$t('Cookie_Consent_Banner_Help')">
              <a-switch v-model:checked="form.cookie_consent_enabled" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-divider orientation="left">{{ $t('Returns_and_Cancellations') }}</a-divider>
        <a-row :gutter="[16, 8]">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Allow_Cancellations')" :extra="$t('Allow_Cancellations_Help')">
              <a-switch v-model:checked="form.allow_cancellations" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Return_Window_Days')" :extra="$t('Return_Window_Days_Help')">
              <a-input-number v-model:value="form.return_window_days" :min="0" :max="365" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-divider orientation="left">{{ $t('Stock_behavior') }}</a-divider>
        <a-row :gutter="[16, 8]">
          <a-col :xs="24" :md="6">
            <a-form-item :label="$t('Stock_behavior')" :extra="$t('Allow_overselling_help')">
              <a-switch v-model:checked="form.allow_overselling" />
              <span style="margin-left: 8px">{{ form.allow_overselling ? $t('Allow_overselling') : $t('Prevent_overselling') }}</span>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="6">
            <a-form-item :label="$t('Hide_out_of_stock')" :extra="$t('Hide_out_of_stock_help')">
              <a-switch v-model:checked="form.hide_out_of_stock" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="6">
            <a-form-item :label="$t('Hide_prices_for_guests')" :extra="$t('Hide_prices_for_guests_help')">
              <a-switch v-model:checked="form.hide_prices_for_guests" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="6">
            <a-form-item :label="$t('Show_stock')" :extra="$t('Show_stock_help')">
              <a-switch v-model:checked="form.show_stock" />
            </a-form-item>
          </a-col>
        </a-row>
          </a-card>
        </a-tab-pane>

        <!-- ===== Contact ===== -->
        <a-tab-pane key="contact">
          <template #tab><span class="stab"><PhoneOutlined /> {{ $t('Contact') }}</span></template>
          <a-card size="small" :bordered="false">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Contact_Email')">
              <a-input v-model:value="form.contact_email" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Contact_Phone')">
              <a-input v-model:value="form.contact_phone" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Contact_Address')">
              <a-input v-model:value="form.contact_address" />
            </a-form-item>
          </a-col>
        </a-row>
          </a-card>
        </a-tab-pane>

        <!-- ===== Branding ===== -->
        <a-tab-pane key="branding">
          <template #tab><span class="stab"><BgColorsOutlined /> {{ $t('Branding') }}</span></template>
          <a-card size="small" :bordered="false">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Logo')">
              <a-upload :file-list="[]" :before-upload="() => false" :max-count="1" accept="image/*" @change="e => pick('logo', e)">
                <a-button>
                  <template #icon><UploadOutlined /></template>
                  {{ $t('Choose_a_file') }}
                </a-button>
              </a-upload>
              <img v-if="settings.logo_path" :src="asset(settings.logo_path)" style="height: 40px; margin-top: 8px; border-radius: 6px" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Favicon')">
              <a-upload :file-list="[]" :before-upload="() => false" :max-count="1" accept="image/*" @change="e => pick('favicon', e)">
                <a-button>
                  <template #icon><UploadOutlined /></template>
                  {{ $t('Choose_a_file') }}
                </a-button>
              </a-upload>
              <img v-if="settings.favicon_path" :src="asset(settings.favicon_path)" style="height: 24px; margin-top: 8px; border-radius: 4px" />
            </a-form-item>
          </a-col>
        </a-row>
          </a-card>
        </a-tab-pane>

        <!-- ===== Hero ===== -->
        <a-tab-pane key="hero">
          <template #tab><span class="stab"><PictureOutlined /> {{ $t('Hero_Header') }}</span></template>
          <a-card size="small" :bordered="false">
        <a-form-item :label="$t('Hero_Title')">
          <a-input v-model:value="form.hero_title" />
        </a-form-item>
        <a-form-item :label="$t('Hero_Subtitle')">
          <a-textarea v-model:value="form.hero_subtitle" :rows="2" />
        </a-form-item>
        <a-form-item :label="$t('Hero_Image')" style="margin-bottom: 0">
          <a-upload :file-list="[]" :before-upload="() => false" :max-count="1" accept="image/*" @change="e => pick('hero_image', e)">
            <a-button>
              <template #icon><UploadOutlined /></template>
              {{ $t('Choose_a_file') }}
            </a-button>
          </a-upload>
          <img v-if="settings.hero_image_path" :src="asset(settings.hero_image_path)" style="height: 64px; margin-top: 8px; border-radius: 6px" />
        </a-form-item>
          </a-card>
        </a-tab-pane>

        <!-- ===== SEO ===== -->
        <a-tab-pane key="seo">
          <template #tab><span class="stab"><SearchOutlined /> SEO</span></template>
          <a-card size="small" :bordered="false">
        <a-form-item :label="$t('SEO_Title')">
          <a-input v-model:value="form.seo_meta_title" />
        </a-form-item>
        <a-form-item :label="$t('SEO_Description')">
          <a-textarea v-model:value="form.seo_meta_description" :rows="2" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('SEO_Title_Template')" :extra="$t('SEO_Title_Template_Help')" style="margin-bottom: 0">
              <a-input v-model:value="form.seo_title_template" placeholder="{page} — {store}" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Store_Domain')" :extra="$t('Store_Domain_Help')" style="margin-bottom: 0">
              <a-input v-model:value="form.store_domain" placeholder="https://shop.example.com" />
            </a-form-item>
          </a-col>
        </a-row>
          </a-card>
        </a-tab-pane>

        <!-- ===== Topbar & Footer ===== -->
        <a-tab-pane key="topbar">
          <template #tab><span class="stab"><LayoutOutlined /> {{ $t('Topbar_and_Footer') }}</span></template>
          <a-card size="small" :bordered="false">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Topbar_Text_Left')">
              <a-input v-model:value="form.topbar_text_left" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Topbar_Text_Right')">
              <a-input v-model:value="form.topbar_text_right" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Footer_Text')" style="margin-bottom: 0">
          <a-textarea v-model:value="form.footer_text" :rows="2" />
        </a-form-item>
          </a-card>
        </a-tab-pane>

        <!-- ===== Social links ===== -->
        <a-tab-pane key="social">
          <template #tab><span class="stab"><ShareAltOutlined /> {{ $t('Social_Links') }}</span></template>
          <a-card size="small" :bordered="false">
        <template #extra>
          <a-button size="small" @click="form.social_links.push({ platform: '', url: '' })">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add_Link') }}
          </a-button>
        </template>
        <a-empty v-if="!form.social_links.length" :description="$t('No_items')" />
        <div v-for="(link, i) in form.social_links" :key="'soc-' + i" class="social-row">
          <a-input v-model:value="link.platform" placeholder="Platform (e.g. facebook)" style="flex: 1" />
          <a-input v-model:value="link.url" placeholder="URL (https://…)" style="flex: 2" />
          <a-button danger @click="form.social_links.splice(i, 1)">
            <template #icon><DeleteOutlined /></template>
          </a-button>
        </div>
          </a-card>
        </a-tab-pane>

        <!-- ===== Homepage blocks ===== -->
        <a-tab-pane key="homepage">
          <template #tab><span class="stab"><AppstoreOutlined /> {{ $t('Homepage_Blocks') }}</span></template>
          <a-card size="small" :bordered="false">
            <template #title>
          {{ $t('Homepage_Blocks') }}
          <span style="font-size: 12px; color: #999; font-weight: normal; margin-left: 8px">
            {{ $t('Toggle_to_show_on_home_and_use_arrows_to_reorder') }}
          </span>
        </template>
        <a-empty v-if="!homeRows.length" :description="$t('No_items')" />
        <div v-for="(row, idx) in homeRows" :key="row.key" class="home-block">
          <div class="block-head">
            <a-space wrap size="small">
              <strong>{{ row.title }}</strong>
              <a-tag :color="kindColor(row.kind)">{{ labelFor(row.kind) }}</a-tag>
              <a-tag v-if="row.kind === 'collection' && row.products_count != null">
                {{ $t('Products') }}: {{ row.products_count }}
              </a-tag>
              <a-tag v-if="row.kind === 'you_may_like' && row.product_ids">
                {{ $t('Products') }}: {{ row.product_ids.length }}
              </a-tag>
              <a-tag
                v-if="row.kind !== 'collection' && row.kind !== 'best_sellers' && row.kind !== 'you_may_like' && row.warning"
                color="warning"
              >
                ⚠︎ {{ $t('Incomplete') }}
              </a-tag>
            </a-space>
            <a-space>
              <a-switch v-model:checked="row.active" />
              <span :style="{ color: row.active ? undefined : '#999' }">{{ row.active ? $t('Active') : $t('Inactive') }}</span>
              <a-button size="small" :disabled="idx === 0" @click="move(idx, -1)">↑</a-button>
              <a-button size="small" :disabled="idx === homeRows.length - 1" @click="move(idx, 1)">↓</a-button>
            </a-space>
          </div>

          <div v-if="row.active && (row.kind === 'best_sellers' || row.kind === 'you_may_like')" class="block-config">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Section_Heading')">
                  <a-input v-model:value="row.heading" :placeholder="labelFor(row.kind)" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Items_to_show')">
                  <a-input-number v-model:value="row.limit" :min="1" :max="24" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item
              v-if="row.kind === 'you_may_like'"
              :label="$t('Pick_Products')" :extra="$t('You_May_Also_Like_Help')" style="margin-bottom: 0"
            >
              <a-select
                v-model:value="row.product_ids" mode="multiple" :placeholder="$t('Search_products')"
                :options="productOptions.map(p => ({ label: p.name, value: p.id }))"
                :filter-option="false" @search="onProductSearch"
              />
            </a-form-item>
            <div v-else style="font-size: 12px; color: #999">{{ $t('Best_Sellers_Help') }}</div>
          </div>
            </div>
          </a-card>
        </a-tab-pane>
      </a-tabs>

      <div class="save-bar">
        <a-button type="primary" size="large" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * Store settings — GET admin/store/settings → {settings, warehouses,
 * currencies, pending_customers_count, curated_products}. All legacy
 * normalizers kept: bools accept 1/'1'/'true'/'on'; menus/social_links/
 * homepage_lineup may arrive as JSON strings; warehouse/currency ids
 * coerced to Number with first-item fallback. Homepage blocks = unified
 * rows (hero, newsletter, best_sellers, you_may_like, every collection),
 * ordered by the saved homepage_lineup with unused rows appended; save
 * rebuilds the lineup from ACTIVE rows only, then posts the whole form as
 * MULTIPART (booleans 1/0, JSON fields stringified, null → '', logo/
 * favicon/hero_image files attached) to POST admin/store/settings.
 * Collections come from settings.collections or the
 * admin/store/collections?include_counts=1 fallback.
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, DeleteOutlined, UploadOutlined, ShopOutlined, PhoneOutlined,
  BgColorsOutlined, PictureOutlined, SearchOutlined, LayoutOutlined,
  ShareAltOutlined, AppstoreOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';

const { t } = useI18n();

const QUICK_LINKS = [
  { to: '/store/shipping-methods', label: 'Shipping_Methods' },
  { to: '/store/tax-rates', label: 'Tax_Rates' },
  { to: '/store/flash-sales', label: 'Flash_Sales' },
  { to: '/store/reviews', label: 'Manage_Reviews' },
  { to: '/store/pages', label: 'Pages' },
  { to: '/store/menus', label: 'Menus' },
  { to: '/store/popups', label: 'Popup_Messages' },
  { to: '/store/quote-requests', label: 'Quote_Requests' },
  { to: '/store/coupons', label: 'Coupons' },
  { to: '/store/returns', label: 'Returns_Requests' },
];

const isLoading = ref(true);
const saving = ref(false);
const settings = ref({});
const collections = ref([]);
const homeRows = ref([]);
const warehouses = ref([]);
const currencies = ref([]);
const productOptions = ref([]);
const pendingCustomersCount = ref(0);
const files = ref({});
let searchTimer = null;

const form = ref({
  enabled: true,
  registration_enabled: true,
  require_invite_code: false,
  require_admin_approval: false,
  require_email_verification: true,
  allow_cancellations: true,
  return_window_days: 14,
  auto_approve_reviews: false,
  cookie_consent_enabled: true,
  store_name: '',
  theme: 'default',
  primary_color: '#6c5ce7',
  secondary_color: '#00c2ff',
  font_family: 'Arial, sans-serif',
  language: 'en',
  default_warehouse_id: null,
  warehouse_ids: [],
  store_all_warehouses: true,
  default_currency_id: null,
  contact_email: '',
  contact_phone: '',
  contact_address: '',
  hero_title: '',
  hero_subtitle: '',
  seo_meta_title: '',
  seo_meta_description: '',
  seo_title_template: '',
  store_domain: '',
  topbar_text_left: '',
  topbar_text_right: '',
  footer_text: '',
  homepage_lineup: [],
  homepage_layout: 'default',
  allow_overselling: true,
  hide_out_of_stock: false,
  hide_prices_for_guests: false,
  show_stock: true,
  menus: { header: [], footer_shop: [], footer_support: [] },
  social_links: [],
  custom_css: '',
  custom_js: '',
  store_slug: 'online_store',
});

function asset(p) {
  if (!p) return '';
  if (p.startsWith('images/')) return `/${p}`;
  if (p.startsWith('/')) return p;
  return `/storage/${p}`;
}
function pick(key, e) {
  const f = e.file && (e.file.originFileObj || e.file);
  if (f) files.value[key] = f;
}
function kindColor(kind) {
  return {
    collection: 'cyan', hero: 'blue', newsletter: 'success',
    best_sellers: 'warning', you_may_like: 'purple',
  }[kind] || 'default';
}
function labelFor(kind) {
  if (kind === 'collection') return t('Collection');
  if (kind === 'hero') return t('Hero');
  if (kind === 'newsletter') return t('Newsletter');
  if (kind === 'best_sellers') return t('Best_Sellers');
  if (kind === 'you_may_like') return t('You_May_Also_Like');
  return kind;
}
function move(idx, dir) {
  const j = idx + dir;
  if (j < 0 || j >= homeRows.value.length) return;
  const [item] = homeRows.value.splice(idx, 1);
  homeRows.value.splice(j, 0, item);
}
function onProductSearch(search) {
  clearTimeout(searchTimer);
  if (!search) return;
  searchTimer = setTimeout(async () => {
    try {
      const res = await http.get('admin/store/products', { q: search, limit: 20 });
      const list = Array.isArray(res) ? res : (Array.isArray(res?.data) ? res.data : []);
      const map = new Map(productOptions.value.map(o => [o.id, o]));
      list.forEach(p => map.set(p.id, { id: p.id, name: p.name }));
      productOptions.value = Array.from(map.values());
    } catch (e) { /* ignore search errors */ }
  }, 300);
}

/* ---------- Normalizers (legacy verbatim) ---------- */
function tryParseJson(v) {
  if (!v || typeof v !== 'string') return null;
  try { return JSON.parse(v); } catch (e) { return null; }
}
function normalizeBool(v) {
  if (typeof v === 'boolean') return v;
  return v === 1 || v === '1' || v === 'true' || v === 'on';
}
function normalizeMenus(v) {
  const x = typeof v === 'string' ? tryParseJson(v) : v;
  if (!x || typeof x !== 'object' || Array.isArray(x)) {
    return { header: [], footer_shop: [], footer_support: [] };
  }
  return {
    header: Array.isArray(x.header) ? x.header : [],
    footer_shop: Array.isArray(x.footer_shop) ? x.footer_shop : [],
    footer_support: Array.isArray(x.footer_support) ? x.footer_support : [],
  };
}
function normalizeSocialLinks(v) {
  const x = typeof v === 'string' ? tryParseJson(v) : v;
  if (!x) return [];
  if (Array.isArray(x)) return x.map(o => ({ platform: o.platform || '', url: o.url || '' }));
  if (typeof x === 'object') return Object.entries(x).map(([platform, url]) => ({ platform, url }));
  return [];
}
function normalizeCollectionsArray(cols) {
  return (cols || []).map(c => ({
    id: c.id ?? null,
    key: `collection:${String(c.slug || c.handle || '').trim()}`,
    kind: 'collection',
    slug: String(c.slug || c.handle || '').trim(),
    title: c.title || c.slug || t('Untitled'),
    products_count: c.products_count ?? null,
    limit: Number(c.limit ?? 8),
    is_active: normalizeBool(c.is_active ?? false),
    sort_order: Number(c.sort_order ?? 9999),
  })).filter(c => !!c.slug);
}

/* ---------- Unified home rows (legacy verbatim) ---------- */
function buildHomeRows() {
  const rows = [];
  const lineup = Array.isArray(form.value.homepage_lineup) ? form.value.homepage_lineup : [];

  const heroConfigured = !!(form.value.hero_title || settings.value.hero_image_path);
  const heroRow = { key: 'hero', kind: 'hero', title: form.value.hero_title || t('Hero'), active: false, warning: !heroConfigured };
  const newsletterRow = { key: 'newsletter', kind: 'newsletter', title: t('Newsletter'), active: false, warning: false };
  const bestSellersRow = { key: 'best_sellers', kind: 'best_sellers', title: t('Best_Sellers'), active: false, warning: false, heading: '', limit: 8 };
  const makeYouMayLikeRow = () => ({
    key: 'you_may_like', kind: 'you_may_like', title: t('You_May_Also_Like'),
    active: false, warning: false, heading: '', limit: 8, product_ids: [],
  });

  const collectionBySlug = new Map(collections.value.map(c => [c.slug, c]));
  const used = new Set();

  if (lineup.length) {
    lineup.forEach(item => {
      if (!item || !item.type) return;
      if (item.type === 'hero') { rows.push({ ...heroRow, active: true }); used.add('hero'); }
      else if (item.type === 'newsletter') { rows.push({ ...newsletterRow, active: true }); used.add('newsletter'); }
      else if (item.type === 'best_sellers') {
        rows.push({ ...bestSellersRow, active: true, heading: item.title || '', limit: Number(item.limit || 8) });
        used.add('best_sellers');
      } else if (item.type === 'you_may_like') {
        rows.push({
          ...makeYouMayLikeRow(), active: true, heading: item.title || '', limit: Number(item.limit || 8),
          product_ids: Array.isArray(item.product_ids) ? item.product_ids.map(Number) : [],
        });
        used.add('you_may_like');
      } else if (item.type === 'collection' && item.slug) {
        const c = collectionBySlug.get(String(item.slug));
        if (c) {
          rows.push({ ...c, key: `collection:${c.slug}`, kind: 'collection', active: true });
          used.add(`collection:${c.slug}`);
        }
      }
    });
    if (!used.has('hero')) rows.push(heroRow);
    if (!used.has('newsletter')) rows.push(newsletterRow);
    if (!used.has('best_sellers')) rows.push({ ...bestSellersRow });
    if (!used.has('you_may_like')) rows.push(makeYouMayLikeRow());
    collections.value
      .filter(c => !used.has(`collection:${c.slug}`))
      .sort((a, b) => (a.sort_order - b.sort_order) || a.title.localeCompare(b.title))
      .forEach(c => rows.push({ ...c, key: `collection:${c.slug}`, kind: 'collection', active: normalizeBool(c.is_active) }));
  } else {
    rows.push({ ...heroRow, active: false });
    collections.value
      .slice()
      .sort((a, b) => (a.sort_order - b.sort_order) || a.title.localeCompare(b.title))
      .forEach(c => rows.push({ ...c, key: `collection:${c.slug}`, kind: 'collection', active: normalizeBool(c.is_active) }));
    rows.push({ ...bestSellersRow });
    rows.push(makeYouMayLikeRow());
    rows.push({ ...newsletterRow, active: false });
  }

  homeRows.value = rows;
}

/* ---------- IO ---------- */
async function fetch() {
  try {
    isLoading.value = true;
    const payload = (await http.get('admin/store/settings')) || {};
    const s = payload.settings ? payload.settings : payload;
    settings.value = s || {};
    warehouses.value = Array.isArray(payload.warehouses) ? payload.warehouses : [];
    currencies.value = Array.isArray(payload.currencies) ? payload.currencies : [];
    pendingCustomersCount.value = payload.pending_customers_count || 0;

    const curated = Array.isArray(payload.curated_products) ? payload.curated_products : [];
    productOptions.value = curated.map(p => ({ id: p.id, name: p.name }));

    const merged = Object.assign({}, form.value, s);
    merged.enabled = normalizeBool(s && s.enabled);
    merged.registration_enabled = normalizeBool(s && (s.registration_enabled ?? true));
    merged.require_invite_code = normalizeBool(s && (s.require_invite_code ?? false));
    merged.require_admin_approval = normalizeBool(s && (s.require_admin_approval ?? false));
    merged.require_email_verification = normalizeBool(s && (s.require_email_verification ?? true));
    merged.allow_cancellations = normalizeBool(s && (s.allow_cancellations ?? true));
    merged.return_window_days = Number((s && s.return_window_days) ?? 14);
    merged.auto_approve_reviews = normalizeBool(s && (s.auto_approve_reviews ?? false));
    merged.cookie_consent_enabled = normalizeBool(s && (s.cookie_consent_enabled ?? true));
    merged.menus = normalizeMenus(s && s.menus);
    merged.social_links = normalizeSocialLinks(s && s.social_links);
    merged.store_slug = (s && s.store_slug) ? s.store_slug : form.value.store_slug;
    merged.theme = (s && s.theme) ? s.theme : 'default';

    const lineupRaw = s && s.homepage_lineup;
    merged.homepage_lineup = Array.isArray(lineupRaw) ? lineupRaw : (tryParseJson(lineupRaw) || []);
    merged.homepage_layout = 'default';
    merged.allow_overselling = normalizeBool(s && s.allow_overselling);
    merged.hide_out_of_stock = normalizeBool(s && s.hide_out_of_stock);
    merged.hide_prices_for_guests = normalizeBool(s && s.hide_prices_for_guests);
    merged.show_stock = normalizeBool(s && (s.show_stock ?? true));

    if (merged.default_warehouse_id != null) merged.default_warehouse_id = Number(merged.default_warehouse_id);
    else if (warehouses.value.length) merged.default_warehouse_id = Number(warehouses.value[0].id);
    else merged.default_warehouse_id = null;

    // warehouse_ids: null/empty = sell from all warehouses; otherwise the list.
    const whIdsRaw = s && s.warehouse_ids;
    const whIds = Array.isArray(whIdsRaw) ? whIdsRaw : (tryParseJson(whIdsRaw) || []);
    merged.store_all_warehouses = !(Array.isArray(whIds) && whIds.length);
    merged.warehouse_ids = (Array.isArray(whIds) && whIds.length)
      ? whIds.map(Number)
      : (merged.default_warehouse_id != null ? [merged.default_warehouse_id] : []);

    if (merged.default_currency_id != null) merged.default_currency_id = Number(merged.default_currency_id);
    else if (currencies.value.length) merged.default_currency_id = Number(currencies.value[0].id);
    else merged.default_currency_id = null;

    form.value = merged;

    let cols = [];
    const dataCollections = s && s.collections;
    if (Array.isArray(dataCollections) && dataCollections.length) {
      cols = dataCollections;
    } else {
      try {
        const res = await http.get('admin/store/collections', { include_counts: 1 });
        cols = Array.isArray(res) ? res : (Array.isArray(res?.data) ? res.data : []);
      } catch (e) {
        cols = [];
      }
    }
    collections.value = normalizeCollectionsArray(cols);

    buildHomeRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function save() {
  if (!form.value.store_all_warehouses && !(form.value.warehouse_ids || []).length) {
    message.error(t('Choose_Warehouse'));
    return;
  }
  saving.value = true;
  try {
    // 1) Rebuild homepage_lineup from active rows, keeping order
    const lineup = [];
    homeRows.value.forEach(r => {
      if (!r || !r.active) return;
      if (r.kind === 'hero') lineup.push({ type: 'hero' });
      else if (r.kind === 'newsletter') lineup.push({ type: 'newsletter' });
      else if (r.kind === 'best_sellers') {
        lineup.push({ type: 'best_sellers', title: (r.heading || '').trim(), limit: r.limit ? Number(r.limit) : 8 });
      } else if (r.kind === 'you_may_like') {
        lineup.push({
          type: 'you_may_like', title: (r.heading || '').trim(), limit: r.limit ? Number(r.limit) : 8,
          product_ids: Array.isArray(r.product_ids) ? r.product_ids.map(Number) : [],
        });
      } else if (r.kind === 'collection') {
        lineup.push({ type: 'collection', slug: r.slug, limit: r.limit ? Number(r.limit) : 8, layout: 'grid', title_override: '' });
      }
    });
    form.value.homepage_lineup = lineup;

    // 2) Numeric coercion
    form.value.default_warehouse_id = (form.value.default_warehouse_id != null && form.value.default_warehouse_id !== '')
      ? Number(form.value.default_warehouse_id) : null;
    form.value.warehouse_ids = (form.value.warehouse_ids || []).map(Number);
    form.value.default_currency_id = (form.value.default_currency_id != null && form.value.default_currency_id !== '')
      ? Number(form.value.default_currency_id) : null;

    // 3) FormData: booleans 1/0, JSON fields stringified, null → ''
    const fd = new FormData();
    const jsonFields = ['menus', 'social_links', 'homepage_lineup', 'warehouse_ids'];
    Object.keys(form.value).forEach(k => {
      const v = form.value[k];
      if (typeof v === 'boolean') {
        fd.append(k, v ? 1 : 0);
      } else if (jsonFields.includes(k)) {
        try { fd.append(k, JSON.stringify(v || [])); } catch (e) { fd.append(k, '[]'); }
      } else if (v === null || v === undefined) {
        fd.append(k, '');
      } else {
        fd.append(k, v);
      }
    });

    // 4) Files
    Object.keys(files.value).forEach(fk => {
      if (files.value[fk]) fd.append(fk, files.value[fk]);
    });

    // 5) POST multipart
    const { status } = await uploadForm('admin/store/settings', fd);
    if (status >= 200 && status < 300) {
      message.success(t('Successfully_Updated'));
      await fetch();
      files.value = {};
    } else {
      message.error(t('InvalidData'));
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(fetch);
</script>

<style scoped>
.color-input {
  width: 100%;
  height: 32px;
  border: 1px solid rgba(5, 5, 5, 0.15);
  border-radius: 6px;
  padding: 2px;
  background: transparent;
  cursor: pointer;
}
.quick-link {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 8px;
  padding: 8px 12px;
}
.muted {
  font-size: 13px;
  color: rgba(0, 0, 0, 0.55);
}
.social-row {
  display: flex;
  gap: 8px;
  margin-bottom: 8px;
}
.home-block {
  margin-bottom: 10px;
}
.block-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  border: 1px solid rgba(5, 5, 5, 0.08);
  border-radius: 10px;
  padding: 10px 12px;
}
.block-config {
  border: 1px solid rgba(5, 5, 5, 0.08);
  border-top: 0;
  border-radius: 0 0 10px 10px;
  background: rgba(0, 0, 0, 0.02);
  padding: 12px 12px 4px;
}

/* ---------------- Tabbed settings shell ---------------- */
.settings-tabs {
  background: transparent;
}
/* The left tab-list becomes a card-like nav rail; the content panel gets room. */
.settings-tabs :deep(.ant-tabs-nav) {
  min-width: 210px;
}
.settings-tabs :deep(.ant-tabs-tab) {
  padding: 10px 14px !important;
  margin: 2px 0 !important;
  border-radius: 8px;
}
.settings-tabs :deep(.ant-tabs-tab-active) {
  background: rgba(109, 40, 217, 0.08);
}
.stab {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
}
/* Drop the card's own frame — the tab panel already frames the section. */
.settings-tabs :deep(.ant-card-body) {
  padding-top: 4px;
}

/* Save bar under the active section (each tab is short, so no stickiness). */
.save-bar {
  display: flex;
  justify-content: flex-end;
  padding-top: 16px;
  margin-top: 8px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
}

/* Stack the tabs vertically on small screens (Ant handles the switch, but keep
   the nav from getting too narrow). */
@media (max-width: 767px) {
  .settings-tabs :deep(.ant-tabs-nav) {
    min-width: 0;
  }
}
</style>
