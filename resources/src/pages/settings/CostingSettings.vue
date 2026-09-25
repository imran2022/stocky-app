<template>
  <a-card :loading="loading">
    <a-alert
      type="info"
      show-icon
      :message="$t('Costing_method')"
      :description="$t('Costing_method_help')"
      style="margin-bottom: 16px"
    />

    <template v-if="!tablesReady">
      <a-alert
        type="warning"
        show-icon
        message="Not installed yet"
        :description="$t('Costing_tables_missing')"
      />
    </template>

    <template v-else>
      <div class="setting-row">
        <div>
          <div class="setting-label">{{ $t('Costing_method') }}</div>
          <div class="setting-help">{{ $t('Costing_method_help') }}</div>
        </div>
        <a-select v-model:value="method" style="width: 260px" :disabled="saving">
          <a-select-option value="legacy">{{ $t('Legacy_master_cost') }}</a-select-option>
          <a-select-option value="moving_average">{{ $t('Moving_average') }}</a-select-option>
        </a-select>
      </div>

      <div v-if="stats" class="setting-row">
        <div>
          <div class="setting-label">Status</div>
          <div class="setting-help">
            {{ stats.productsCosted }} / {{ stats.totalNonServiceProducts }} {{ $t('Costing_products_costed') }}
            <span v-if="active"> — Moving Average is live</span>
            <span v-else> — Legacy (master cost) is live</span>
          </div>
        </div>
      </div>

      <div class="ft-toolbar">
        <a-checkbox v-if="method === 'moving_average'" v-model:checked="resync">
          Re-cost every product now (normally only needed once)
        </a-checkbox>
        <a-button type="primary" :loading="saving" :disabled="method === appliedMethod && !resync" @click="apply">
          <template #icon><SaveOutlined /></template>
          {{ $t('submit') }}
        </a-button>
      </div>
    </template>
  </a-card>
</template>

<script setup>
/**
 * Costing Method — a small web-UI wrapper around `php artisan costing:rebuild --apply --enable`
 * (see app/Console/Commands/CostingRebuild.php and app/Services/Costing/InventoryCostingService.php),
 * so switching between Legacy (master cost) and Moving Average doesn't require CLI access. It does not
 * add any new costing logic — see App\Http\Controllers\Settings\CostingSettingsController.
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SaveOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(false);
const tablesReady = ref(false);
const method = ref('legacy');
const appliedMethod = ref('legacy');
const active = ref(false);
const resync = ref(false);
const stats = ref(null);

function applyState(data) {
  tablesReady.value = !!data.tablesReady;
  method.value = data.method || 'legacy';
  appliedMethod.value = data.method || 'legacy';
  active.value = !!data.active;
  if (data.tablesReady) {
    stats.value = { productsCosted: data.productsCosted ?? 0, totalNonServiceProducts: data.totalNonServiceProducts ?? 0 };
  }
}

async function apply() {
  saving.value = true;
  try {
    const data = await http.post('costing_settings', { method: method.value, resync: resync.value });
    applyState(data);
    resync.value = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || e?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('costing_settings');
    applyState(data);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.ft-toolbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 16px;
  margin-top: 8px;
}
.setting-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 14px 0;
  border-top: 1px solid rgba(128, 128, 128, 0.12);
}
.setting-row:first-of-type {
  border-top: none;
}
.setting-label {
  font-weight: 500;
}
.setting-help {
  font-size: 12px;
  color: #8c8c8c;
  max-width: 520px;
}
</style>
