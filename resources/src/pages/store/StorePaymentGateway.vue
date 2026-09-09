<template>
  <div class="page">
    <PageHeader
      :title="$t('Payment_Gateway')"
      :breadcrumb="context === 'settings' ? [$t('Settings'), $t('Payment_Gateway')] : [$t('Store'), $t('Payment_Gateway')]"
    />

    <a-alert
      type="info" show-icon style="margin-bottom: 16px"
      message="PayPal, Paystack, Flutterwave and Razorpay are used by the ONLINE STORE checkout only."
      description="Stripe keys are shared globally (admin and online store use the same Stripe integration). The payment methods list at the bottom controls what customers see at the online-store checkout."
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
    <!-- Gateways — one card, one tab per provider -->
    <a-card :bordered="false" class="pg-card" style="margin-bottom: 16px">
      <a-tabs v-model:active-key="gatewayTab" class="pg-tabs">
        <!-- ============================== Stripe ============================== -->
        <a-tab-pane key="stripe">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': enabled }"></span>
              <CreditCardOutlined /> Stripe
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #635bff; flex: 0 0 auto">
                <template #icon><CreditCardOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Stripe</div>
                <div class="pg-head__sub">{{ $t('Store') }} — {{ $t('Payment_Gateway') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="enabled ? 'success' : 'default'">
                <template #icon><component :is="enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <!-- Keys (only when enabled) -->
          <a-form v-if="enabled" layout="vertical" class="pg-form">
            <!-- Field labels literal like legacy's .env-style naming. -->
            <a-form-item label="STRIPE KEY">
              <a-input v-model:value="gateway.stripe_key" :placeholder="$t('LeaveBlank')">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item label="STRIPE SECRET" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="gateway.stripe_secret" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="Stripe is disabled"
            description="Turn Stripe on to enter your API keys and start accepting card payments in the online store. Turning it off clears the stored keys."
          />

          <a-button type="primary" :loading="saving" @click="save">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== PayPal ============================== -->
        <a-tab-pane key="paypal">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': paypal.enabled }"></span>
              <DollarCircleOutlined /> PayPal
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #003087; flex: 0 0 auto">
                <template #icon><DollarCircleOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">PayPal <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="paypal.enabled ? 'success' : 'default'">
                <template #icon><component :is="paypal.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ paypal.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="paypal.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="paypal.enabled" layout="vertical" class="pg-form">
            <a-form-item label="PAYPAL CLIENT ID">
              <a-input v-model:value="paypal.client_id" placeholder="AXxxxxxxxxxxxxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="PAYPAL CLIENT SECRET"
              :extra="paypal.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="paypal.client_secret" :placeholder="paypal.secret_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              label="Sandbox (test mode)"
              extra="ON uses api-m.sandbox.paypal.com with sandbox credentials; turn OFF to charge real accounts on api-m.paypal.com."
            >
              <a-switch v-model:checked="paypal.test_mode" />
            </a-form-item>
            <a-form-item
              label="PAYPAL WEBHOOK ID (optional)"
              extra="Backstop for payments whose browser redirect never lands, plus refunds/chargebacks. Register a webhook on developer.paypal.com pointing to the URL below (events: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.REFUNDED) and paste its Webhook ID here."
            >
              <a-input v-model:value="paypal.webhook_id" placeholder="8XL79430PL0733531">
                <template #prefix><ApiOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                Webhook URL: <a-typography-text code copyable>{{ webhookUrl('paypal') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="PayPal is disabled"
            description="Turn PayPal on and enter your REST app Client ID and Secret (from developer.paypal.com) to let customers pay with PayPal at the online-store checkout."
          />

          <a-button type="primary" :loading="paypalSaving" @click="savePaypal">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== Paystack ============================== -->
        <a-tab-pane key="paystack">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': paystack.enabled }"></span>
              <BankOutlined /> Paystack
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #00c3f7; flex: 0 0 auto">
                <template #icon><BankOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Paystack <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="paystack.enabled ? 'success' : 'default'">
                <template #icon><component :is="paystack.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ paystack.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="paystack.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="paystack.enabled" layout="vertical" class="pg-form">
            <a-form-item label="PAYSTACK PUBLIC KEY">
              <a-input v-model:value="paystack.public_key" placeholder="pk_test_xxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="PAYSTACK SECRET KEY"
              :extra="paystack.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="paystack.secret_key" :placeholder="paystack.secret_set ? $t('LeaveBlank') : 'sk_test_xxxxxxxx'" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              message="Test vs live is decided by the key pair: pk_test_/sk_test_ keys run in test mode, pk_live_/sk_live_ keys charge real cards — there is no separate sandbox switch."
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                Webhook URL (optional backstop — set it under Settings → API Keys &amp; Webhooks on the Paystack dashboard; requests are HMAC-verified with your secret key):
                <a-typography-text code copyable>{{ webhookUrl('paystack') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="Paystack is disabled"
            description="Turn Paystack on and enter your Public and Secret keys (from dashboard.paystack.com → Settings → API Keys) to accept cards, bank transfers and mobile money at the online-store checkout."
          />

          <a-button type="primary" :loading="paystackSaving" @click="savePaystack">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== Flutterwave ============================== -->
        <a-tab-pane key="flutterwave">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': flutterwave.enabled }"></span>
              <GlobalOutlined /> Flutterwave
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #f5a623; flex: 0 0 auto">
                <template #icon><GlobalOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Flutterwave <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="flutterwave.enabled ? 'success' : 'default'">
                <template #icon><component :is="flutterwave.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ flutterwave.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="flutterwave.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="flutterwave.enabled" layout="vertical" class="pg-form">
            <a-form-item label="FLUTTERWAVE PUBLIC KEY">
              <a-input v-model:value="flutterwave.public_key" placeholder="FLWPUBK_TEST-xxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="FLUTTERWAVE SECRET KEY"
              :extra="flutterwave.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="flutterwave.secret_key" :placeholder="flutterwave.secret_set ? $t('LeaveBlank') : 'FLWSECK_TEST-xxxxxxxx'" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              label="SECRET HASH (webhooks, optional)"
              :extra="flutterwave.hash_set ? $t('LeaveBlank') : 'The Secret Hash you set under Settings → Webhooks on the Flutterwave dashboard; incoming webhooks are rejected unless their verif-hash header matches.'"
            >
              <a-input-password v-model:value="flutterwave.secret_hash" :placeholder="flutterwave.hash_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><ApiOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              message="Uses the Flutterwave v3 API with classic keys. Test vs live is decided by the key pair (FLWPUBK_TEST/FLWSECK_TEST vs live keys) — there is no separate sandbox switch. The store currency must be enabled on your Flutterwave account."
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                Webhook URL (optional backstop — set it with the Secret Hash above on the Flutterwave dashboard):
                <a-typography-text code copyable>{{ webhookUrl('flutterwave') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="Flutterwave is disabled"
            description="Turn Flutterwave on and enter your v3 Public and Secret keys (from dashboard.flutterwave.com → Settings → API) to accept cards, mobile money and bank transfers at the online-store checkout."
          />

          <a-button type="primary" :loading="flutterwaveSaving" @click="saveFlutterwave">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== Razorpay ============================== -->
        <a-tab-pane key="razorpay">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': razorpay.enabled }"></span>
              <ThunderboltOutlined /> Razorpay
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #3395ff; flex: 0 0 auto">
                <template #icon><ThunderboltOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Razorpay <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="razorpay.enabled ? 'success' : 'default'">
                <template #icon><component :is="razorpay.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ razorpay.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="razorpay.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="razorpay.enabled" layout="vertical" class="pg-form">
            <a-form-item label="RAZORPAY KEY ID">
              <a-input v-model:value="razorpay.key_id" placeholder="rzp_test_xxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="RAZORPAY KEY SECRET"
              :extra="razorpay.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="razorpay.key_secret" :placeholder="razorpay.secret_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              label="WEBHOOK SECRET (optional)"
              :extra="razorpay.webhook_secret_set ? $t('LeaveBlank') : 'The secret you define when creating the webhook on the Razorpay dashboard; incoming events are HMAC-verified against it and rejected otherwise.'"
            >
              <a-input-password v-model:value="razorpay.webhook_secret" :placeholder="razorpay.webhook_secret_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><ApiOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              message="Uses Razorpay Payment Links (cards, UPI, netbanking, wallets). Test vs live is decided by the key pair (rzp_test_/rzp_live_) — there is no separate sandbox switch."
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                Webhook URL (optional backstop — register it with the secret above under Settings → Webhooks; events: payment_link.paid, payment.failed, refund.processed):
                <a-typography-text code copyable>{{ webhookUrl('razorpay') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="Razorpay is disabled"
            description="Turn Razorpay on and enter your Key ID and Key Secret (from dashboard.razorpay.com → Settings → API Keys) to accept cards, UPI, netbanking and wallets at the online-store checkout."
          />

          <a-button type="primary" :loading="razorpaySaving" @click="saveRazorpay">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>
      </a-tabs>
    </a-card>

    <!-- Payment methods offered to customers at online-store checkout -->
    <a-card :bordered="false" class="pg-card">
      <div class="pm-title">Available payment methods</div>
      <div class="pm-sub">Turn each option on or off for the online-store checkout</div>
      <div class="pm-list">
        <div v-for="m in methods" :key="m.key" class="pm-row">
          <div class="pm-icon" :style="{ background: m.tint, color: m.color }">
            <component :is="m.icon" />
          </div>
          <div class="pm-text">
            <div class="pm-name">{{ m.name }}</div>
            <div class="pm-desc">{{ m.desc }}</div>
          </div>
          <!-- Credit card / PayPal are toggled from their gateway tabs above. -->
          <a-tag v-if="m.key === 'credit_card'" :color="enabled ? 'success' : 'default'">
            {{ enabled ? 'Enabled' : 'Disabled' }}
          </a-tag>
          <a-tag v-else-if="m.key === 'paypal'" :color="paypal.enabled ? 'success' : 'default'">
            {{ paypal.enabled ? 'Enabled' : 'Disabled' }}
          </a-tag>
          <a-tag v-else-if="m.key === 'paystack'" :color="paystack.enabled ? 'success' : 'default'">
            {{ paystack.enabled ? 'Enabled' : 'Disabled' }}
          </a-tag>
          <a-tag v-else-if="m.key === 'flutterwave'" :color="flutterwave.enabled ? 'success' : 'default'">
            {{ flutterwave.enabled ? 'Enabled' : 'Disabled' }}
          </a-tag>
          <a-tag v-else-if="m.key === 'razorpay'" :color="razorpay.enabled ? 'success' : 'default'">
            {{ razorpay.enabled ? 'Enabled' : 'Disabled' }}
          </a-tag>
          <a-switch v-else v-model:checked="flags[m.model]" />
        </div>
      </div>
      <a-button type="primary" :loading="methodsSaving" style="margin-top: 16px" @click="saveMethods">
        <template #icon><SaveOutlined /></template>
        {{ $t('submit') }}
      </a-button>
    </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Online-store payment gateway — the storefront checkout uses the same global
 * Stripe keys as the admin (there is one Stripe integration). GET
 * get_payment_gateway → {gateway:{stripe_key, stripe_secret:'', deleted}};
 * POST payment_gateway {stripe_key, stripe_secret, deleted} — `deleted:true`
 * wipes the stored pair, which the Enable/Disable toggle maps to.
 */
import { ref, reactive, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  CreditCardOutlined, CheckCircleFilled, StopOutlined, KeyOutlined, LockOutlined, SaveOutlined,
  DollarOutlined, MobileOutlined, WalletOutlined, DollarCircleOutlined, BankOutlined, ApiOutlined,
  GlobalOutlined, ThunderboltOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

// Rendered from two routes: /store/payment-gateway ('store', default) and
// /settings/payment-gateway ('settings' — only the breadcrumb differs).
defineProps({ context: { type: String, default: 'store' } });

const loading = ref(true);
const gatewayTab = ref('stripe');
const saving = ref(false);
const enabled = ref(false);
const gateway = ref({ stripe_key: '', stripe_secret: '' });

// The methods the storefront checkout offers (see checkout.blade.php). Credit
// card is toggled by the Stripe section above; the rest are on/off flags on the
// store settings (Wallet reuses the E-Wallet `wallet_enabled` flag).
const methods = [
  { key: 'credit_card', name: 'Credit Card (Stripe)', desc: 'Pay by card, powered by Stripe', icon: CreditCardOutlined, color: '#635bff', tint: 'rgba(99, 91, 255, 0.12)' },
  { key: 'paypal', name: 'PayPal', desc: 'Pay with a PayPal account or card via PayPal', icon: DollarCircleOutlined, color: '#003087', tint: 'rgba(0, 48, 135, 0.10)' },
  { key: 'paystack', name: 'Paystack', desc: 'Cards, bank transfers and mobile money via Paystack', icon: BankOutlined, color: '#00a5d4', tint: 'rgba(0, 195, 247, 0.12)' },
  { key: 'flutterwave', name: 'Flutterwave', desc: 'Cards, mobile money and bank transfers via Flutterwave', icon: GlobalOutlined, color: '#c77f00', tint: 'rgba(245, 166, 35, 0.14)' },
  { key: 'razorpay', name: 'Razorpay', desc: 'Cards, UPI, netbanking and wallets via Razorpay', icon: ThunderboltOutlined, color: '#3395ff', tint: 'rgba(51, 149, 255, 0.12)' },
  { key: 'cod', model: 'cod', name: 'Cash on Delivery', desc: 'Pay in cash when the order is delivered', icon: DollarOutlined, color: '#52c41a', tint: 'rgba(82, 196, 26, 0.12)' },
  { key: 'mobile_money', model: 'mobile_money', name: 'Mobile Money', desc: 'Pay via a mobile-money transfer', icon: MobileOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
  { key: 'wallet', model: 'wallet', name: 'Wallet', desc: 'Pay from the customer’s store wallet balance', icon: WalletOutlined, color: '#722ed1', tint: 'rgba(114, 46, 209, 0.12)' },
];

// Method on/off flags + the two ids the store-settings update requires.
const flags = reactive({ cod: true, mobile_money: true, wallet: false });
const storeMeta = ref({ default_currency_id: null, default_warehouse_id: null });
const methodsSaving = ref(false);

// PayPal config lives on the store settings row (client_secret is write-only:
// the API never echoes it back, `secret_set` says whether one is stored).
const paypal = reactive({ enabled: false, client_id: '', client_secret: '', secret_set: false, test_mode: true, webhook_id: '' });
const paypalSaving = ref(false);

// Server-to-server webhook endpoints (routes/api.php → Api/Store/WebhookController).
const webhookUrl = gateway => `${window.location.origin}/api/store/webhooks/${gateway}`;

// Paystack config — same storage/leave-blank rules; test vs live is decided
// by the pk_test_/pk_live_ key pair, so there is no sandbox switch.
const paystack = reactive({ enabled: false, public_key: '', secret_key: '', secret_set: false });
const paystackSaving = ref(false);

// Flutterwave config — v3 classic keys + webhook Secret Hash; same rules.
const flutterwave = reactive({
  enabled: false, public_key: '', secret_key: '', secret_set: false,
  secret_hash: '', hash_set: false,
});
const flutterwaveSaving = ref(false);

// Razorpay config — Payment Links; key pair decides test vs live.
const razorpay = reactive({
  enabled: false, key_id: '', key_secret: '', secret_set: false,
  webhook_secret: '', webhook_secret_set: false,
});
const razorpaySaving = ref(false);

async function saveRazorpay() {
  if (razorpay.enabled && !razorpay.key_id.trim()) {
    message.warning('Razorpay Key ID is required');
    return;
  }
  razorpaySaving.value = true;
  try {
    const body = {
      razorpay_enabled: razorpay.enabled ? 1 : 0,
      razorpay_key_id: razorpay.key_id.trim(),
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secrets → omitted → backend keeps the stored ones.
    if (razorpay.key_secret.trim()) body.razorpay_key_secret = razorpay.key_secret.trim();
    if (razorpay.webhook_secret.trim()) body.razorpay_webhook_secret = razorpay.webhook_secret.trim();
    await http.post('admin/store/settings', body);
    if (razorpay.key_secret.trim()) razorpay.secret_set = true;
    if (razorpay.webhook_secret.trim()) razorpay.webhook_secret_set = true;
    razorpay.key_secret = '';
    razorpay.webhook_secret = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    razorpaySaving.value = false;
  }
}

async function saveFlutterwave() {
  if (flutterwave.enabled && !flutterwave.public_key.trim()) {
    message.warning('Flutterwave Public Key is required');
    return;
  }
  flutterwaveSaving.value = true;
  try {
    const body = {
      flutterwave_enabled: flutterwave.enabled ? 1 : 0,
      flutterwave_public_key: flutterwave.public_key.trim(),
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secrets → omitted → backend keeps the stored ones.
    if (flutterwave.secret_key.trim()) body.flutterwave_secret_key = flutterwave.secret_key.trim();
    if (flutterwave.secret_hash.trim()) body.flutterwave_secret_hash = flutterwave.secret_hash.trim();
    await http.post('admin/store/settings', body);
    if (flutterwave.secret_key.trim()) flutterwave.secret_set = true;
    if (flutterwave.secret_hash.trim()) flutterwave.hash_set = true;
    flutterwave.secret_key = '';
    flutterwave.secret_hash = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    flutterwaveSaving.value = false;
  }
}

async function savePaystack() {
  if (paystack.enabled && !paystack.public_key.trim()) {
    message.warning('Paystack Public Key is required');
    return;
  }
  paystackSaving.value = true;
  try {
    const body = {
      paystack_enabled: paystack.enabled ? 1 : 0,
      paystack_public_key: paystack.public_key.trim(),
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secret → omitted → backend keeps the stored one.
    if (paystack.secret_key.trim()) body.paystack_secret_key = paystack.secret_key.trim();
    await http.post('admin/store/settings', body);
    if (paystack.secret_key.trim()) paystack.secret_set = true;
    paystack.secret_key = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paystackSaving.value = false;
  }
}

async function savePaypal() {
  if (paypal.enabled && !paypal.client_id.trim()) {
    message.warning('PayPal Client ID is required');
    return;
  }
  paypalSaving.value = true;
  try {
    const body = {
      paypal_enabled: paypal.enabled ? 1 : 0,
      paypal_client_id: paypal.client_id.trim(),
      paypal_test_mode: paypal.test_mode ? 1 : 0,
      paypal_webhook_id: paypal.webhook_id.trim(),
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secret → omitted → backend keeps the stored one.
    if (paypal.client_secret.trim()) body.paypal_client_secret = paypal.client_secret.trim();
    await http.post('admin/store/settings', body);
    if (paypal.client_secret.trim()) paypal.secret_set = true;
    paypal.client_secret = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paypalSaving.value = false;
  }
}

async function saveMethods() {
  methodsSaving.value = true;
  try {
    await http.post('admin/store/settings', {
      payment_cod_enabled: flags.cod ? 1 : 0,
      payment_mobile_money_enabled: flags.mobile_money ? 1 : 0,
      wallet_enabled: flags.wallet ? 1 : 0,
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    methodsSaving.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    if (enabled.value) {
      await http.post('payment_gateway', {
        stripe_key: gateway.value.stripe_key,
        // blank secret → null keeps the stored one (leave-blank behaviour).
        stripe_secret: gateway.value.stripe_secret ? gateway.value.stripe_secret : null,
        deleted: false,
      });
    } else {
      // Disabling clears the stored keys via the backend's `deleted` path.
      await http.post('payment_gateway', { deleted: true });
      gateway.value.stripe_key = '';
      gateway.value.stripe_secret = '';
    }
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const [gw, store] = await Promise.all([
      http.get('get_payment_gateway'),
      http.get('admin/store/settings').catch(() => null),
    ]);
    const g = gw?.gateway || {};
    gateway.value = { stripe_key: g.stripe_key || '', stripe_secret: '' };
    enabled.value = !!gateway.value.stripe_key;

    const s = store?.settings || {};
    flags.cod = s.payment_cod_enabled == null ? true : !!Number(s.payment_cod_enabled);
    flags.mobile_money = s.payment_mobile_money_enabled == null ? true : !!Number(s.payment_mobile_money_enabled);
    flags.wallet = !!Number(s.wallet_enabled);
    paypal.enabled = !!Number(s.paypal_enabled);
    paypal.client_id = s.paypal_client_id || '';
    paypal.secret_set = !!s.paypal_secret_set;
    paypal.test_mode = s.paypal_test_mode == null ? true : !!Number(s.paypal_test_mode);
    paypal.webhook_id = s.paypal_webhook_id || '';
    paystack.enabled = !!Number(s.paystack_enabled);
    paystack.public_key = s.paystack_public_key || '';
    paystack.secret_set = !!s.paystack_secret_set;
    flutterwave.enabled = !!Number(s.flutterwave_enabled);
    flutterwave.public_key = s.flutterwave_public_key || '';
    flutterwave.secret_set = !!s.flutterwave_secret_set;
    flutterwave.hash_set = !!s.flutterwave_hash_set;
    razorpay.enabled = !!Number(s.razorpay_enabled);
    razorpay.key_id = s.razorpay_key_id || '';
    razorpay.secret_set = !!s.razorpay_secret_set;
    razorpay.webhook_secret_set = !!s.razorpay_webhook_secret_set;
    storeMeta.value = {
      default_currency_id: store?.default_currency_id ?? s.currency_code ?? null,
      default_warehouse_id: s.default_warehouse_id ?? null,
    };
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.pg-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.pg-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.pg-head__left {
  display: flex;
  align-items: center;
  gap: 14px;
}
.pg-head__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
}
.pg-head__sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
.pg-head__right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.pg-form {
  max-width: 520px;
  margin-bottom: 4px;
}

/* Gateway tabs: provider icon + name + a small status dot that shows at a
   glance which gateways are live without opening their tab. */
.pg-tabs :deep(.ant-tabs-tab) {
  padding-top: 4px;
}
.pg-tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-weight: 600;
}
.pg-tab__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #d9d9d9;
  flex: 0 0 auto;
}
.pg-tab__dot--on {
  background: #52c41a;
  box-shadow: 0 0 0 3px rgba(82, 196, 26, 0.18);
}

/* Available payment methods list */
.pm-title {
  font-size: 16px;
  font-weight: 600;
}
.pm-sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 16px;
}
.pm-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.pm-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid #f0f0f0;
  border-radius: 10px;
}
.pm-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex: 0 0 auto;
}
.pm-text {
  flex: 1 1 auto;
  min-width: 0;
}
.pm-name {
  font-weight: 600;
  line-height: 1.2;
}
.pm-desc {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
</style>
