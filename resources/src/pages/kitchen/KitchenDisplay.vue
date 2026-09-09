<template>
  <div class="page kitchen">
    <PageHeader :title="$t('KitchenDisplay')" :breadcrumb="[$t('Kitchen'), $t('KitchenDisplay')]">
      <template #actions>
        <a-tag :color="autoRefresh ? 'success' : 'default'">
          <span class="live-dot" :class="{ on: autoRefresh }"></span>
          {{ autoRefresh ? $t('Live') : $t('Paused') }}
        </a-tag>
        <a-button @click="toggleAutoRefresh">
          <template #icon>
            <PauseOutlined v-if="autoRefresh" />
            <CaretRightOutlined v-else />
          </template>
        </a-button>
        <a-button :loading="refreshing" @click="fetchBoard(true)">
          <template #icon><ReloadOutlined /></template>
          {{ $t('Refresh') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Counts — same stat-card design as the Sales list -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in statTiles" :key="tile.label" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" :style="{ background: tile.tint, color: tile.color }">
              <component :is="tile.icon" />
            </div>
            <div class="stat-meta">
              <div class="stat-label">{{ tile.label }}</div>
              <div class="stat-value">
                <a-spin v-if="loading && !firstLoadDone" size="small" />
                <template v-else>{{ tile.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Filters -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap :size="12">
        <a-input-search
          v-model:value="search" :placeholder="$t('Search')"
          allow-clear style="width: 260px" @search="reload"
        />
        <a-range-picker v-model:value="range" value-format="YYYY-MM-DD" @change="reload" />
        <a-button @click="clearFilters">
          <template #icon><CloseOutlined /></template>
          {{ $t('Clear') }}
        </a-button>
      </a-space>
    </a-card>

    <!-- Board -->
    <a-row :gutter="[16, 16]">
      <a-col v-for="col in COLUMNS" :key="col.key" :xs="24" :md="12" :xl="6">
        <div class="lane">
          <div class="lane-head" :style="{ background: col.color }">
            <span>{{ $t(col.label) }}</span>
            <span class="lane-count">{{ (grouped[col.key] || []).length }}</span>
          </div>

          <div class="lane-body">
            <a-skeleton v-if="loading && !firstLoadDone" active :paragraph="{ rows: 3 }" />

            <a-empty
              v-else-if="!(grouped[col.key] || []).length"
              :description="$t('NoOrders')" style="padding: 24px 0"
            />

            <a-card
              v-for="order in grouped[col.key] || []" :key="order.id"
              size="small" class="ticket" :style="{ borderLeftColor: col.color }"
            >
              <a-spin :spinning="busyId === order.id">
                <div class="ticket-top">
                  <strong>{{ order.ref || `#${order.sale_id}` }}</strong>
                  <a-tooltip :title="order.created_at">
                    <span class="muted"><ClockCircleOutlined /> {{ elapsed(order) }}</span>
                  </a-tooltip>
                </div>

                <div class="muted">
                  <UserOutlined /> {{ order.customer_name || $t('Walkin_Customer') }}
                </div>

                <ul class="items">
                  <li v-for="item in order.items" :key="item.id">
                    <span class="qty">{{ formatQty(item.quantity) }}<small v-if="item.unit"> {{ item.unit }}</small></span>
                    <span>{{ item.name }}</span>
                  </li>
                </ul>

                <a-alert
                  v-if="order.instructions" type="warning" :message="order.instructions"
                  style="margin-bottom: 8px"
                />

                <a-tag v-if="order.assigned_name" color="purple">
                  {{ order.assigned_name }}
                </a-tag>

                <!-- Dispatch is only offered once the ticket is complete. -->
                <template v-if="col.key === 'completed'">
                  <a-tag v-if="order.dispatched_warehouse_id" color="success">
                    {{ $t('SentToWarehouse') }} {{ order.dispatched_warehouse_name }}
                  </a-tag>
                  <a-select
                    v-if="canManage"
                    :value="order.dispatched_warehouse_id || undefined"
                    :placeholder="order.dispatched_warehouse_id ? $t('ChangeWarehouse') : $t('SendToWarehouse')"
                    :disabled="busyId === order.id"
                    size="small" style="width: 100%; margin-top: 6px"
                    :options="warehouses.map(w => ({ value: w.id, label: w.name }))"
                    @change="v => dispatch(order, v)"
                  />
                </template>

                <div class="ticket-actions">
                  <template v-if="canManage">
                    <a-button
                      v-if="col.key === 'pending' || col.key === 'on_hold'"
                      type="primary" size="small" :disabled="busyId === order.id"
                      @click="setStatus(order, 'preparing')"
                    >{{ $t('StartPreparation') }}</a-button>

                    <a-button
                      v-if="col.key === 'preparing'"
                      type="primary" size="small" :disabled="busyId === order.id"
                      style="background: #22c55e; border-color: #22c55e"
                      @click="setStatus(order, 'completed')"
                    >{{ $t('MarkCompleted') }}</a-button>

                    <a-button
                      v-if="col.key === 'pending' || col.key === 'preparing'"
                      size="small" :disabled="busyId === order.id"
                      @click="setStatus(order, 'on_hold')"
                    >{{ $t('PutOnHold') }}</a-button>

                    <a-button
                      v-if="col.key === 'completed'"
                      size="small" :disabled="busyId === order.id"
                      @click="setStatus(order, 'preparing')"
                    >{{ $t('Reopen') }}</a-button>
                  </template>

                  <a-tooltip :title="$t('Details')">
                    <a-button size="small" type="text" style="margin-left: auto" @click="openDetails(order)">
                      <template #icon><EyeOutlined /></template>
                    </a-button>
                  </a-tooltip>
                </div>
              </a-spin>
            </a-card>
          </div>
        </div>
      </a-col>
    </a-row>

    <!-- Details -->
    <a-modal v-model:open="detailsOpen" :title="$t('OrderDetails')" :footer="null" width="720px">
      <template v-if="selected">
        <a-descriptions :column="{ xs: 1, md: 2 }" size="small" bordered>
          <a-descriptions-item :label="$t('Order')">
            {{ selected.ref || `#${selected.sale_id}` }}
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Status')">
            <a-tag :color="statusColor(selected.status)">{{ statusLabel(selected.status) }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Customer')">
            {{ selected.customer_name || $t('Walkin_Customer') }}
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Date')">{{ selected.created_at }}</a-descriptions-item>
          <a-descriptions-item v-if="selected.dispatched_warehouse_id" :label="$t('SentToWarehouse')">
            {{ selected.dispatched_warehouse_name }}
          </a-descriptions-item>
        </a-descriptions>

        <a-table
          :columns="itemColumns" :data-source="selected.items || []"
          :pagination="false" size="small" :row-key="r => r.id" style="margin-top: 16px"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'quantity'">
              {{ formatQty(record.quantity) }}<span v-if="record.unit"> {{ record.unit }}</span>
            </template>
          </template>
        </a-table>

        <template v-if="selected.instructions">
          <h4 style="margin-top: 16px">{{ $t('SpecialInstructions') }}</h4>
          <p>{{ selected.instructions }}</p>
        </template>

        <template v-if="canManage">
          <h4 style="margin-top: 16px">{{ $t('AssignedStaff') }}</h4>
          <a-space>
            <a-select
              v-model:value="assignChoice" style="width: 260px"
              :options="staffOptions" allow-clear :placeholder="$t('Unassigned')"
            />
            <a-button type="primary" :loading="assignBusy" @click="saveAssignment">{{ $t('Save') }}</a-button>
          </a-space>
        </template>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Kitchen display board — legacy KitchenDisplay.vue. Four status lanes
 * (pending / preparing / completed / on hold) fed by GET kitchen/orders,
 * with a 4-second poll and a beep when new tickets land.
 *
 * Endpoints: GET kitchen/orders?q&from&to → {grouped, warehouses};
 * GET kitchen/orders/poll → {latest_id}; PATCH kitchen/orders/{id}/status
 * {status}; .../assign {assigned_to}; .../dispatch {dispatched_warehouse_id};
 * GET users_list_for_select; GET kitchen/warehouses.
 *
 * Things legacy got wrong that are fixed here:
 * - polling kept running in a hidden tab; it now pauses on visibilitychange
 *   and refreshes on return, which matters for a board left open all day.
 * - a single AudioContext was constructed per beep and never closed, leaking
 *   one context every time a ticket arrived; one lazily-created context now
 *   serves them all.
 * - the details modal held a reference into the replaced-every-4s board array,
 *   so it froze at open-time state; `selected` is re-resolved by id after each
 *   fetch.
 * Kept as-is: the new-order count is an id delta, so it can overcount when
 * unrelated rows consume ids — matching legacy rather than inventing a count.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { message, notification } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PauseOutlined, CaretRightOutlined, ReloadOutlined, CloseOutlined,
  ClockCircleOutlined, UserOutlined, EyeOutlined, FireOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

const COLUMNS = [
  { key: 'pending', label: 'PendingOrders', color: '#64748b' },
  { key: 'preparing', label: 'PreparingOrders', color: '#3b82f6' },
  { key: 'completed', label: 'CompletedOrders', color: '#22c55e' },
  { key: 'on_hold', label: 'OnHoldOrders', color: '#f59e0b' },
];

// Summary tiles mirror the Sales list's stat cards: lane color + soft tint + icon.
const TILE_META = {
  pending: { icon: ClockCircleOutlined, tint: 'rgba(100, 116, 139, 0.14)' },
  preparing: { icon: FireOutlined, tint: 'rgba(59, 130, 246, 0.12)' },
  completed: { icon: CheckCircleOutlined, tint: 'rgba(34, 197, 94, 0.12)' },
  on_hold: { icon: PauseOutlined, tint: 'rgba(245, 158, 11, 0.14)' },
};

const statTiles = computed(() => COLUMNS.map(c => ({
  label: t(c.label),
  value: (grouped.value[c.key] || []).length,
  color: c.color,
  icon: TILE_META[c.key].icon,
  tint: TILE_META[c.key].tint,
})));

const loading = ref(true);
const refreshing = ref(false);
const firstLoadDone = ref(false);
const grouped = ref({});
const warehouses = ref([]);
const staff = ref([]);
const busyId = ref(null);

const search = ref('');
const range = ref([]);
const autoRefresh = ref(true);

const canManage = computed(() => auth.can('kitchen_display_manage'));

const itemColumns = computed(() => [
  { title: t('Product'), dataIndex: 'name', key: 'name' },
  { title: t('Quantity'), key: 'quantity', align: 'right' },
]);

const staffOptions = computed(() =>
  staff.value.map(u => ({
    value: u.id,
    label: [u.firstname, u.lastname].filter(Boolean).join(' ') || u.username || u.email,
  })));

function statusLabel(s) {
  const col = COLUMNS.find(c => c.key === s);
  return col ? t(col.label) : s;
}
function statusColor(s) {
  return { pending: 'default', preparing: 'processing', completed: 'success', on_hold: 'warning' }[s] || 'default';
}

/** Legacy formatQty: integers bare, otherwise 3dp with trailing zeros trimmed. */
function formatQty(n) {
  const num = Number(n || 0);
  return Number.isInteger(num) ? String(num) : String(parseFloat(num.toFixed(3)));
}

const nowTs = ref(Date.now());

/** Minutes since the ticket was sent (or created): "now", "45m", "2h 15m". */
function elapsed(order) {
  const raw = order.sent_at || order.created_at;
  if (!raw) return '';
  const dt = new Date(String(raw).replace(' ', 'T'));
  if (Number.isNaN(dt.getTime())) return '';
  const mins = Math.max(0, Math.floor((nowTs.value - dt.getTime()) / 60000));
  if (mins < 1) return t('Now');
  if (mins < 60) return `${mins}m`;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return `${h}h${m ? ` ${m}m` : ''}`;
}

// ---------------- data ----------------

function params() {
  return { q: search.value || '', from: range.value?.[0] || '', to: range.value?.[1] || '' };
}

async function fetchBoard(showSpinner = false) {
  if (showSpinner) refreshing.value = true;
  if (!firstLoadDone.value) loading.value = true;
  try {
    const data = await http.get('kitchen/orders', params());
    if (data?.grouped) grouped.value = data.grouped;
    if (Array.isArray(data?.warehouses) && data.warehouses.length) warehouses.value = data.warehouses;
    // Keep the open modal live rather than frozen at open-time state.
    if (selected.value) {
      const all = Object.values(grouped.value).flat();
      const fresh = all.find(o => o.id === selected.value.id);
      if (fresh) selected.value = fresh;
    }
  } catch (e) { /* keep the last good board */ } finally {
    loading.value = false;
    refreshing.value = false;
    firstLoadDone.value = true;
  }
}

function reload() {
  fetchBoard(true);
}
function clearFilters() {
  search.value = '';
  range.value = [];
  fetchBoard(true);
}

// ---------------- actions ----------------

async function setStatus(order, status) {
  busyId.value = order.id;
  try {
    await http.patch(`kitchen/orders/${order.id}/status`, { status });
    await fetchBoard(false);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    busyId.value = null;
  }
}

async function dispatch(order, warehouseId) {
  if (!warehouseId) return;
  busyId.value = order.id;
  try {
    await http.patch(`kitchen/orders/${order.id}/dispatch`, { dispatched_warehouse_id: warehouseId });
    await fetchBoard(false);
    const wh = warehouses.value.find(w => String(w.id) === String(warehouseId));
    message.success(`${t('SentToWarehouse')} ${wh ? wh.name : ''}`);
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    busyId.value = null;
  }
}

// ---------------- details ----------------

const detailsOpen = ref(false);
const selected = ref(null);
const assignChoice = ref(null);
const assignBusy = ref(false);

function openDetails(order) {
  selected.value = order;
  assignChoice.value = order.assigned_to || null;
  detailsOpen.value = true;
}

async function saveAssignment() {
  assignBusy.value = true;
  try {
    const data = await http.patch(`kitchen/orders/${selected.value.id}/assign`, {
      assigned_to: assignChoice.value ?? null,
    });
    await fetchBoard(false);
    if (data?.order) selected.value = data.order;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    assignBusy.value = false;
  }
}

// ---------------- polling ----------------

let pollTimer = null;
let clockTimer = null;
let lastLatestId = 0;
let audioCtx = null;

/** 880 Hz, 180 ms. One context reused — legacy leaked one per beep. */
function beep() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    if (!audioCtx) audioCtx = new Ctx();
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.frequency.value = 880;
    gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
    osc.start();
    osc.stop(audioCtx.currentTime + 0.18);
  } catch (e) { /* autoplay policy or no WebAudio */ }
}

async function poll() {
  try {
    const data = await http.get('kitchen/orders/poll');
    const latest = Number(data?.latest_id || 0);
    await fetchBoard(false);
    // lastLatestId starts at 0, so the first tick only seeds — no false alarm.
    if (lastLatestId && latest > lastLatestId) {
      const count = latest - lastLatestId;
      notification.info({
        message: t('Kitchen'),
        description: `${t('NewKitchenOrders')}${count > 1 ? ` (${count})` : ''}`,
        duration: 4,
      });
      beep();
    }
    lastLatestId = latest || lastLatestId;
  } catch (e) { /* transient; next tick retries */ }
}

function startPolling() {
  stopPolling();
  if (autoRefresh.value) pollTimer = setInterval(poll, 4000);
}
function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}
function toggleAutoRefresh() {
  autoRefresh.value = !autoRefresh.value;
  startPolling();
}

/** A board left open all day should not poll while nobody is looking. */
function onVisibility() {
  if (document.hidden) {
    stopPolling();
  } else if (autoRefresh.value) {
    fetchBoard(false);
    startPolling();
  }
}

onMounted(async () => {
  fetchBoard(false);
  startPolling();
  clockTimer = setInterval(() => { nowTs.value = Date.now(); }, 30000);
  document.addEventListener('visibilitychange', onVisibility);

  try {
    const data = await http.get('users_list_for_select');
    staff.value = data?.users || [];
  } catch (e) { /* assignment select just stays empty */ }
  try {
    const data = await http.get('kitchen/warehouses');
    warehouses.value = data?.warehouses || [];
  } catch (e) { /* dispatch select stays empty */ }
});

onBeforeUnmount(() => {
  stopPolling();
  if (clockTimer) clearInterval(clockTimer);
  document.removeEventListener('visibilitychange', onVisibility);
  if (audioCtx) audioCtx.close().catch(() => {});
});
</script>

<style scoped>
/* Stat tiles — same design as the Sales list summary cards. */
.stat-card {
  border-radius: 10px;
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
.stat-label {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
}
@media (max-width: 575px) {
  .stat-inner {
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-value {
    font-size: 16px;
  }
}

.live-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
  margin-right: 6px;
  vertical-align: middle;
}
.live-dot.on {
  animation: pulse 1.4s infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}
.lane {
  background: rgba(128, 128, 128, 0.06);
  border-radius: 12px;
  padding: 10px;
  min-height: 220px;
}
.lane-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: #fff;
  border-radius: 8px;
  padding: 8px 12px;
  font-weight: 600;
  margin-bottom: 10px;
}
.lane-count {
  background: rgba(255, 255, 255, 0.28);
  border-radius: 10px;
  padding: 0 8px;
}
.lane-body {
  max-height: calc(100vh - 380px);
  overflow-y: auto;
}
.ticket {
  border-left: 4px solid #cbd5e1;
  margin-bottom: 10px;
}
.ticket-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}
.items {
  list-style: none;
  padding: 0;
  margin: 8px 0;
}
.items li {
  display: flex;
  gap: 8px;
  padding: 2px 0;
  font-size: 13px;
}
.qty {
  min-width: 46px;
  font-weight: 700;
  color: #6d28d9;
}
.ticket-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  margin-top: 8px;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
