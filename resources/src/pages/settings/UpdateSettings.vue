<template>
  <div class="page">
    <PageHeader :title="$t('update_settings')" :breadcrumb="[$t('Settings'), $t('Update_Log')]" />

    <a-alert type="error" show-icon :message="$t('Note_update')" style="margin-bottom: 16px" />

    <a-card>
      <a-tabs>
        <!-- ============================ Automatic update ============================ -->
        <a-tab-pane v-if="SHOW_AUTO_UPDATE" key="auto" :tab="$t('Automatic_Update')">
          <!-- Status banner -->
          <a-alert
            :type="updating ? 'info' : updateFailed ? 'error' : hasUpdate ? 'warning' : 'success'"
            show-icon style="margin-bottom: 16px"
            :message="statusTitle"
            :description="statusDescription"
          />

          <a-row :gutter="[16, 16]">
            <!-- Versions + actions -->
            <a-col :xs="24" :lg="14">
              <a-card size="small" style="height: 100%">
                <a-row :gutter="16">
                  <a-col :span="12">
                    <a-statistic :title="$t('Installed_Version')" :value="currentVersion || '—'" />
                    <div style="font-size: 12px; color: #8c8c8c">{{ $t('Currently_Running') }}</div>
                  </a-col>
                  <a-col :span="12">
                    <a-statistic :title="$t('Latest_Version')" :value="latestVersion || '—'" />
                    <div style="font-size: 12px" :style="{ color: hasUpdate ? '#faad14' : '#52c41a' }">
                      {{ hasUpdate ? $t('New_Version_Available') : $t('You_are_up_to_date') }}
                    </div>
                  </a-col>
                </a-row>
                <a-space style="margin-top: 20px" wrap>
                  <a-button :loading="checking" @click="checkForUpdates">
                    <SyncOutlined v-if="!checking" /> {{ $t('Check_for_Updates') }}
                  </a-button>
                  <a-button
                    v-if="hasUpdate" type="primary" danger
                    :loading="updating" :disabled="updating"
                    @click="confirmUpdate"
                  >
                    <DownloadOutlined /> {{ $t('Update_Now') }} — v{{ latestVersion }}
                  </a-button>
                  <span v-if="lastCheckedText" style="font-size: 12px; color: #8c8c8c">{{ lastCheckedText }}</span>
                </a-space>
              </a-card>
            </a-col>

            <!-- Preflight -->
            <a-col :xs="24" :lg="10">
              <a-card size="small" style="height: 100%">
                <template #title>{{ $t('System_Checks') }}</template>
                <template #extra>
                  <a-button size="small" :loading="preflightLoading" @click="runPreflight">
                    {{ $t('Refresh') }}
                  </a-button>
                </template>
                <template v-if="preflight">
                  <div v-for="(check, i) in preflightItems" :key="i" style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px">
                    <CheckCircleFilled v-if="check.ok" style="color: #52c41a" />
                    <CloseCircleFilled v-else style="color: #ff4d4f" />
                    <span style="text-transform: capitalize">{{ check.label }}</span>
                  </div>
                  <a-divider style="margin: 12px 0" />
                  <a-tag :color="canUpdate ? 'success' : 'error'">
                    {{ canUpdate ? $t('All_checks_passed') : $t('Some_checks_failed') }}
                  </a-tag>
                </template>
                <div v-else style="color: #8c8c8c; font-size: 13px">{{ $t('Run_checks_to_verify_readiness') }}</div>
              </a-card>
            </a-col>

            <!-- Progress -->
            <a-col :span="24" v-if="updating || updatePercent > 0">
              <a-card size="small" :title="$t('Update_Progress')">
                <a-progress :percent="updatePercent" :status="updateFailed ? 'exception' : (updatePercent >= 100 ? 'success' : 'active')" />
                <a-steps
                  :current="currentStepIndex" size="small" style="margin-top: 16px"
                  :status="updateFailed ? 'error' : undefined"
                  :items="updateSteps.map(s => ({ title: s.label }))"
                />
              </a-card>
            </a-col>

            <!-- Changelog -->
            <a-col :xs="24" :lg="changelog.length ? 14 : 24" v-if="changelog.length">
              <a-card size="small" :title="$t('Release_Notes')">
                <div v-for="(entry, idx) in changelog" :key="idx" :style="idx < changelog.length - 1 ? 'border-bottom: 1px solid #f0f0f0; padding-bottom: 12px; margin-bottom: 12px' : ''">
                  <div style="font-weight: 600">v{{ entry.version }} <span style="font-weight: 400; color: #8c8c8c; font-size: 12px">{{ entry.date }}</span></div>
                  <ul style="margin: 8px 0 0 0; padding-left: 18px">
                    <li v-for="(item, j) in entry.items || []" :key="j" style="font-size: 13px">
                      <a-tag size="small" :color="{ feature: 'green', fix: 'blue', improvement: 'purple' }[item.type] || 'default'">{{ item.type || 'misc' }}</a-tag>
                      {{ item.text || item }}
                    </li>
                  </ul>
                </div>
              </a-card>
            </a-col>

            <!-- History -->
            <a-col :xs="24" :lg="changelog.length ? 10 : 24" v-if="updateHistory.length">
              <a-card size="small" :title="$t('Update_History')">
                <a-table
                  :columns="historyColumns" :data-source="updateHistory"
                  size="small" :pagination="false" :row-key="(_r, i) => i"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'status'">
                      <a-tag :color="record.status === 'success' ? 'success' : 'error'">{{ record.status }}</a-tag>
                    </template>
                  </template>
                </a-table>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ============================ Manual update ============================ -->
        <a-tab-pane key="manual" :tab="$t('Manual_Update')">
          <h4>Please follow these steps, To Update your application</h4>
          <a-alert type="error" style="margin: 8px 0" message="Note 1: If you have made any changes in the code manually then your changes will be lost." />
          <a-alert type="error" style="margin-bottom: 16px" message='Note 2: only admin or user who has permission "system_setting" he can upgrade the system' />
          <ol class="manual-steps">
            <li>
              Take back up of your database. Go to <router-link to="/settings/backup">Backup</router-link>, click Generate Backup —
              you will find it in <strong>/storage/app/public/backup</strong> — or export your database from PhpMyAdmin and save it to your PC.
            </li>
            <li>Take back up of your files before updating.</li>
            <li>Download the latest version from your CodeCanyon account and extract it.</li>
            <li>
              Remove the previous files, <strong>except</strong>:
              <ul>
                <li>file: <strong>.env</strong></li>
                <li>folder: <strong>storage</strong></li>
                <li>folder: <strong>/public/images</strong></li>
              </ul>
            </li>
            <li>
              Re-upload the files and folders from the new update, <strong>except</strong> the same three paths above.
            </li>
            <li>Visit <strong>http://your_app/update</strong> to update your database.</li>
            <li>Hard-clear your browser cache.</li>
            <li>You are done! Enjoy the updated application.</li>
          </ol>
          <a-alert type="error" message="Note: If any pages are not loading or blank, make sure you cleared your browser cache." />
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Update settings — endpoints from legacy update_settings.vue:
 * GET get_version_info → {current_version, latest_version, latest_info,
 * changelog, update_history} (or a bare version string); GET
 * update/preflight → {ok, permissions{k:{writable}}, network{ok},
 * disk{storage_free}} (disk pass = >200MB free); POST one_click_update with
 * GET update/progress polled every 1.5s → {percent, step}; step keys map to
 * the 7-stage progress rail. Update failure auto-clears banners after 5s.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  SyncOutlined, DownloadOutlined, CheckCircleFilled, CloseCircleFilled,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

// Automatic update tab hidden on request — flip to true to bring it back
// (the whole auto-update flow below stays intact).
const SHOW_AUTO_UPDATE = false;

const checking = ref(false);
const currentVersion = ref('');
const latestVersion = ref('');
const changelog = ref([]);
const updateHistory = ref([]);
const lastChecked = ref(null);
const nowTick = ref(Date.now());

const preflightLoading = ref(false);
const preflight = ref(null);
const canUpdate = ref(false);

const updating = ref(false);
const updateFailed = ref(false);
const updatePercent = ref(0);
const updateStep = ref('idle');
let progressTimer = null;
let tickTimer = null;

function versionCompare(a, b) {
  const pa = String(a).split('.').map(Number);
  const pb = String(b).split('.').map(Number);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const na = pa[i] || 0; const nb = pb[i] || 0;
    if (na > nb) return 1;
    if (na < nb) return -1;
  }
  return 0;
}

const hasUpdate = computed(() =>
  !!latestVersion.value && !!currentVersion.value && versionCompare(latestVersion.value, currentVersion.value) > 0);

const statusTitle = computed(() => {
  if (updating.value) return t('Updating_Application');
  if (updateFailed.value) return t('Update_Failed');
  if (hasUpdate.value) return t('Update_Available');
  return t('System_Up_to_Date');
});
const statusDescription = computed(() => {
  if (updating.value) return t('Please_wait_update_in_progress');
  if (updateFailed.value) return t('Update_failed_rolled_back');
  if (hasUpdate.value) return t('Version') + ' ' + latestVersion.value + ' ' + t('is_ready_to_install');
  return t('You_already_have_the_latest_version');
});

const lastCheckedText = computed(() => {
  if (!lastChecked.value) return '';
  const diff = Math.floor((nowTick.value - lastChecked.value) / 1000);
  if (diff < 10) return t('Last_checked_just_now');
  if (diff < 60) return t('Last_checked') + ' ' + diff + 's ' + t('ago');
  if (diff < 3600) return t('Last_checked') + ' ' + Math.floor(diff / 60) + 'm ' + t('ago');
  return t('Last_checked') + ' ' + Math.floor(diff / 3600) + 'h ' + t('ago');
});

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const k = 1024; const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

const preflightItems = computed(() => {
  if (!preflight.value) return [];
  const items = [];
  const p = preflight.value;
  if (p.permissions) {
    Object.keys(p.permissions).forEach(k => {
      items.push({ label: k.replace(/_/g, ' ') + ' writable', ok: p.permissions[k].writable });
    });
  }
  if (p.network) items.push({ label: 'Update server reachable', ok: p.network.ok });
  if (p.disk) {
    const free = p.disk.storage_free || 0;
    items.push({ label: 'Disk space (' + formatBytes(free) + ' free)', ok: free > 200 * 1024 * 1024 });
  }
  return items;
});

const updateSteps = computed(() => [
  { key: 'starting', label: t('Preparing') },
  { key: 'database_backup_created', label: t('Backup') },
  { key: 'download_complete', label: t('Download') },
  { key: 'extracted', label: t('Verify') },
  { key: 'deployment_completed', label: t('Install') },
  { key: 'migrations_completed', label: t('Migrate') },
  { key: 'completed', label: t('Done') },
]);
const currentStepIndex = computed(() => {
  const idx = updateSteps.value.findIndex(s => s.key === updateStep.value);
  return idx >= 0 ? idx : 0;
});

const historyColumns = computed(() => [
  { title: t('Version'), dataIndex: 'version', key: 'version' },
  { title: t('Status'), key: 'status', dataIndex: 'status' },
  { title: t('Date'), dataIndex: 'date', key: 'date' },
]);

async function checkForUpdates() {
  checking.value = true;
  try {
    const data = await http.get('get_version_info');
    if (data && typeof data === 'object') {
      currentVersion.value = data.current_version || currentVersion.value;
      latestVersion.value = data.latest_version || '';
      changelog.value = data.changelog || [];
      updateHistory.value = data.update_history || [];
    } else if (typeof data === 'string' && data) {
      latestVersion.value = data;
    }
    lastChecked.value = Date.now();
  } catch (e) {
    message.error(t('Failed_to_check_updates'));
  } finally {
    checking.value = false;
  }
}

async function runPreflight() {
  preflightLoading.value = true;
  try {
    const data = await http.get('update/preflight');
    preflight.value = data;
    canUpdate.value = !!(data && data.ok);
  } catch (e) {
    preflight.value = null;
    canUpdate.value = false;
  } finally {
    preflightLoading.value = false;
  }
}

function confirmUpdate() {
  Modal.confirm({
    title: t('Are_you_sure'),
    content: t('Update_confirmation_text'),
    okText: t('Yes_update'),
    cancelText: t('Cancel'),
    okType: 'danger',
    onOk: updateSystem,
  });
}

function startProgressPolling() {
  if (progressTimer) return;
  progressTimer = setInterval(async () => {
    try {
      const data = await http.get('update/progress');
      if (data) {
        updatePercent.value = data.percent || 0;
        updateStep.value = data.step || 'running';
        if (updatePercent.value >= 100) stopProgressPolling();
      }
    } catch (e) { /* keep polling */ }
  }, 1500);
}
function stopProgressPolling() {
  if (progressTimer) { clearInterval(progressTimer); progressTimer = null; }
}

async function updateSystem() {
  updating.value = true;
  updateFailed.value = false;
  updatePercent.value = 0;
  updateStep.value = 'starting';
  startProgressPolling();
  try {
    await http.post('one_click_update');
    updating.value = false;
    stopProgressPolling();
    updatePercent.value = 100;
    updateStep.value = 'completed';
    message.success(t('Successfully_Updated'));
    setTimeout(checkForUpdates, 2000);
  } catch (e) {
    updateFailed.value = true;
    stopProgressPolling();
    message.error(e?.data?.message || t('InvalidData'));
    setTimeout(() => {
      updating.value = false;
      updateFailed.value = false;
    }, 5000);
  }
}

onMounted(() => {
  if (!SHOW_AUTO_UPDATE) return; // tab hidden — skip its version/preflight calls
  checkForUpdates();
  runPreflight();
  tickTimer = setInterval(() => { nowTick.value = Date.now(); }, 30000);
});
onBeforeUnmount(() => {
  stopProgressPolling();
  if (tickTimer) clearInterval(tickTimer);
});
</script>

<style scoped>
.manual-steps li {
  margin-bottom: 10px;
}
.manual-steps ul {
  margin-top: 6px;
}
</style>
