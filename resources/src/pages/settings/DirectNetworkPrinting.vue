<template>
  <div class="page">
    <PageHeader
      :title="$t('Direct_Network_Printing') || 'Direct Network Printing'"
      :breadcrumb="[$t('Settings'), 'Direct Network Printing']"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-alert
          type="info" show-icon style="margin-bottom: 16px"
          message="Send receipts directly to a network thermal printer over RAW/JetDirect (default port 9100). Leave this OFF to keep using the existing browser/OS print flow."
        />
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Enable Direct Network Printing"
              extra="When disabled, existing printing behavior is unchanged."
            >
              <a-switch v-model:checked="s.direct_network_printing" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Network Printer IP Address"
              extra="IPv4/IPv6 address or hostname of the printer on your local network."
            >
              <a-input
                v-model:value="s.network_printer_ip"
                placeholder="192.168.1.50"
                :disabled="!s.direct_network_printing"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Network Printer Port"
              extra="Default RAW / JetDirect port is 9100."
            >
              <a-input-number
                v-model:value="s.network_printer_port"
                style="width: 100%" :min="1" :max="65535"
                :disabled="!s.direct_network_printing"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <!-- Label printer (raw TSPL, Print Barcode page) -->
      <a-card size="small" style="margin-bottom: 16px" :title="$t('Label_Printer')">
        <a-alert
          type="info" show-icon style="margin-bottom: 16px"
          :message="$t('Label_Printer_Hint')"
        />
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Enable_Direct_Label_Printing')">
              <a-switch v-model:checked="s.label_printer_enabled" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Label_Printer_Connection')">
              <a-select
                v-model:value="s.label_printer_connection"
                :disabled="!s.label_printer_enabled"
                :options="[
                  { value: 'windows', label: $t('Windows_USB_Printer') },
                  { value: 'network', label: $t('Network_IP_Printer') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8" v-if="s.label_printer_connection !== 'network'">
            <a-form-item
              :label="$t('Windows_Printer_Name')"
              :extra="$t('Windows_Printer_Name_Hint')"
            >
              <a-input
                v-model:value="s.label_printer_name"
                placeholder="4BARCODE 4B-2054L"
                :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <template v-if="s.label_printer_connection === 'network'">
            <a-col :xs="24" :md="5">
              <a-form-item label="IP">
                <a-input
                  v-model:value="s.label_printer_ip" placeholder="192.168.1.60"
                  :disabled="!s.label_printer_enabled"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="3">
              <a-form-item label="Port">
                <a-input-number
                  v-model:value="s.label_printer_port" style="width: 100%"
                  :min="1" :max="65535" :disabled="!s.label_printer_enabled"
                />
              </a-form-item>
            </a-col>
          </template>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Label_Gap')">
              <a-input-number
                v-model:value="s.label_printer_gap_mm" style="width: 100%"
                :min="0" :max="10" :step="0.5" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Print_Darkness')" :extra="'0–15'">
              <a-input-number
                v-model:value="s.label_printer_density" style="width: 100%"
                :min="0" :max="15" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Print_Speed')" :extra="'ips'">
              <a-input-number
                v-model:value="s.label_printer_speed" style="width: 100%"
                :min="1" :max="8" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Print_Direction')">
              <a-select
                v-model:value="s.label_printer_direction"
                :disabled="!s.label_printer_enabled"
                :options="[{ value: 0, label: '0' }, { value: 1, label: '1 (180°)' }]"
              />
            </a-form-item>
          </a-col>
        </a-row>
        <a-button :loading="testing" :disabled="!s.label_printer_enabled" @click="testLabelPrint">
          {{ $t('Test_Print') }}
        </a-button>
      </a-card>

      <a-button type="primary" size="large" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Direct Network Printing — own tab like legacy. Reads/writes only its three
 * pos_settings fields (update_pos_settings applies just the posted fields, so
 * this page can't clobber anything owned by POS Settings / POS Receipt).
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const s = ref({});

function labelPrinterPayload() {
  return {
    label_printer_connection: s.value.label_printer_connection === 'network' ? 'network' : 'windows',
    label_printer_name: (s.value.label_printer_name || '').toString().trim() || null,
    label_printer_ip: (s.value.label_printer_ip || '').toString().trim() || null,
    label_printer_port: s.value.label_printer_port ? Number(s.value.label_printer_port) : null,
    label_printer_gap_mm: Number(s.value.label_printer_gap_mm ?? 2),
    label_printer_density: Number(s.value.label_printer_density ?? 8),
    label_printer_speed: Number(s.value.label_printer_speed ?? 3),
    label_printer_direction: Number(s.value.label_printer_direction ?? 0),
  };
}

async function save() {
  saving.value = true;
  try {
    // Same payload shape as legacy.
    await http.put(`pos_settings/${s.value.id}`, {
      direct_network_printing: s.value.direct_network_printing ? 1 : 0,
      network_printer_ip: (s.value.network_printer_ip || '').toString().trim() || null,
      network_printer_port: s.value.network_printer_port ? Number(s.value.network_printer_port) : null,
      label_printer_enabled: s.value.label_printer_enabled ? 1 : 0,
      ...labelPrinterPayload(),
    });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

// Prints a sample label using the current (possibly unsaved) form values.
async function testLabelPrint() {
  testing.value = true;
  try {
    const res = await http.post('label_printer_test', labelPrinterPayload());
    message.success((res && res.message) || t('Sent_to_label_printer'));
  } catch (e) {
    message.error(e?.data?.message || t('Label_print_failed'));
  } finally {
    testing.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_pos_Settings_api');
    const ps = data.pos_settings || {};
    ps.direct_network_printing = ps.direct_network_printing === true || !!Number(ps.direct_network_printing ?? 0);
    ps.network_printer_port = ps.network_printer_port || 9100;
    ps.label_printer_enabled = ps.label_printer_enabled === true || !!Number(ps.label_printer_enabled ?? 0);
    ps.label_printer_connection = ps.label_printer_connection === 'network' ? 'network' : 'windows';
    ps.label_printer_port = ps.label_printer_port || 9100;
    ps.label_printer_gap_mm = Number(ps.label_printer_gap_mm ?? 2);
    ps.label_printer_density = Number(ps.label_printer_density ?? 8);
    ps.label_printer_speed = Number(ps.label_printer_speed ?? 3);
    ps.label_printer_direction = Number(ps.label_printer_direction ?? 0);
    s.value = ps;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>
