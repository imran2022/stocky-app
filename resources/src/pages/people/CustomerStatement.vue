<template>
  <div class="page">
    <PageHeader :title="$t('Customer_Statement')" :breadcrumb="[$t('Customers'), $t('Customer_Statement')]">
      <template #actions>
        <a-button @click="$router.push('/customers')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button :loading="downloading === 'excel'" @click="downloadExcel">
          <template #icon><FileExcelOutlined /></template>
          {{ $t('Download_Excel') }}
        </a-button>
        <a-button type="primary" :loading="downloading === 'pdf'" @click="downloadPdf">
          <template #icon><FilePdfOutlined /></template>
          {{ $t('Download_PDF') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Customer banner -->
      <a-card :bordered="false" class="stmt-hero" style="margin-bottom: 16px">
        <div class="hero-main">
          <a-avatar :size="60" class="hero-avatar">{{ initials }}</a-avatar>
          <div class="hero-info">
            <div class="hero-name-row">
              <span class="hero-name">{{ client.name || '-' }}</span>
            </div>
            <div class="hero-meta">
              <span v-if="client.email" class="meta-chip"><MailOutlined /> {{ client.email }}</span>
            </div>
          </div>
        </div>
      </a-card>

      <!-- KPI cards -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :sm="8">
          <a-card :bordered="false" class="kpi-card">
            <a-statistic
              :title="$t('Opening_Balance')" :value="money(data.opening_balance)"
              :value-style="{ color: Number(data.opening_balance) > 0 ? '#e11d48' : '#166534' }"
            />
          </a-card>
        </a-col>
        <a-col :xs="24" :sm="8">
          <a-card :bordered="false" class="kpi-card">
            <a-statistic
              :title="$t('Total_Debit')" :value="money(totalDebit)"
              :value-style="{ color: '#e11d48' }"
            />
          </a-card>
        </a-col>
        <a-col :xs="24" :sm="8">
          <a-card :bordered="false" class="kpi-card">
            <a-statistic
              :title="$t('Closing_Balance')" :value="money(data.closing_balance)"
              :value-style="{ color: Number(data.closing_balance) > 0 ? '#e11d48' : '#166534' }"
            />
          </a-card>
        </a-col>
      </a-row>

      <a-card size="small">
        <div class="toolbar">
          <a-space wrap>
            <a-date-picker v-model:value="fromDate" :placeholder="$t('From_Date')" style="width: 150px" />
            <a-date-picker v-model:value="toDate" :placeholder="$t('To_Date')" style="width: 150px" />
            <a-button type="primary" :loading="loadingTable" @click="() => fetchStatement()">{{ $t('Apply') }}</a-button>
            <a-button v-if="fromDate || toDate" @click="resetFilters">{{ $t('Reset') }}</a-button>
          </a-space>
        </div>

        <a-table
          :columns="columns" :data-source="data.entries" :loading="loadingTable"
          :pagination="false" size="middle" row-key="__row" :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'type'">
              <a-tag :color="typeColor(record.type)">{{ typeLabel(record.type) }}</a-tag>
            </template>
            <template v-else-if="column.key === 'debit'">
              <span v-if="record.debit" class="amt-debit">{{ money(record.debit) }}</span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'credit'">
              <span v-if="record.credit" class="amt-credit">{{ money(record.credit) }}</span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'balance'">
              <strong>{{ money(record.balance) }}</strong>
            </template>
          </template>
          <template #emptyText>
            <a-empty :description="$t('No_transactions_in_this_period')" style="padding: 24px 0" />
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Customer Statement (Build M4) — a new, additional admin page: the same
 * unified running-balance ledger (Date / Type / Ref / Description / Debit /
 * Credit / Balance) the customer portal already shows the client, now
 * available to admins too, with a date-range filter and PDF/Excel export.
 *
 * Deliberately separate from the existing "Customer Ledger" page
 * (CustomerLedger.vue — hero + 8 KPI tiles + 4 tabbed lists), which stays as
 * it is; this page answers a different question ("what does the running
 * balance look like, entry by entry") the same way the portal's own
 * statement does, built from the same App\Services\ClientStatementService
 * on the backend so the numbers can never drift from what the customer sees
 * in their own portal.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import {
  LeftOutlined, MailOutlined, FilePdfOutlined, FileExcelOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const route = useRoute();
const id = route.params.id;

const loading = ref(true);
const loadingTable = ref(false);
const downloading = ref(null);
const client = ref({});
const data = reactive({ opening_balance: 0, closing_balance: 0, entries: [] });
const fromDate = ref(null);
const toDate = ref(null);

const initials = computed(() =>
  (client.value.name || '?')
    .split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?'
);

const totalDebit = computed(() =>
  (data.entries || []).reduce((acc, e) => acc + (Number(e.debit) || 0), 0)
);

const TYPE_COLORS = {
  invoice: 'blue', payment: 'green', opening: 'purple', opening_payment: 'green',
  return: 'orange', refund: 'red', service: 'cyan', service_payment: 'green',
};
const TYPE_LABELS = {
  invoice: 'Invoice', payment: 'Payment', opening: 'Opening_Balance',
  opening_payment: 'Opening_Balance_Payment', return: 'Sale_Return', refund: 'Refund',
  service: 'Service_Job', service_payment: 'Service_Payment',
};
function typeColor(type) { return TYPE_COLORS[type] || 'default'; }
function typeLabel(type) { return TYPE_LABELS[type] ? t(TYPE_LABELS[type]) : type; }

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Type'), dataIndex: 'type', key: 'type' },
  { title: t('Ref'), dataIndex: 'ref', key: 'ref' },
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  { title: t('Debit'), dataIndex: 'debit', key: 'debit', align: 'right' },
  { title: t('Credit'), dataIndex: 'credit', key: 'credit', align: 'right' },
  { title: t('Balance'), dataIndex: 'balance', key: 'balance', align: 'right' },
]);

function dateParam(d) {
  return d ? dayjs(d).format('YYYY-MM-DD') : undefined;
}

async function fetchStatement() {
  loadingTable.value = true;
  try {
    const res = await http.get(`clients/${id}/statement`, {
      from_date: dateParam(fromDate.value),
      to_date: dateParam(toDate.value),
    });
    client.value = res?.client || {};
    data.opening_balance = res?.opening_balance ?? 0;
    data.closing_balance = res?.closing_balance ?? 0;
    data.entries = (res?.entries || []).map((e, i) => ({ ...e, __row: i }));
  } catch (e) {
    message.error(t('Failed_to_load_statement'));
  } finally {
    loadingTable.value = false;
  }
}

function resetFilters() {
  fromDate.value = null;
  toDate.value = null;
  fetchStatement();
}

function statementQuery() {
  const params = new URLSearchParams();
  const f = dateParam(fromDate.value);
  const tt = dateParam(toDate.value);
  if (f) params.set('from_date', f);
  if (tt) params.set('to_date', tt);
  const qs = params.toString();
  return qs ? `?${qs}` : '';
}

async function downloadPdf() {
  downloading.value = 'pdf';
  try {
    await http.download(`clients/${id}/statement/pdf${statementQuery()}`, `statement_${id}.pdf`);
  } catch (e) {
    message.error(t('Failed_to_export'));
  } finally {
    downloading.value = null;
  }
}

async function downloadExcel() {
  downloading.value = 'excel';
  try {
    await http.download(`clients/${id}/statement/excel${statementQuery()}`, `statement_${id}.xlsx`);
  } catch (e) {
    message.error(t('Failed_to_export'));
  } finally {
    downloading.value = null;
  }
}

onMounted(async () => {
  await fetchStatement();
  loading.value = false;
});
</script>

<style scoped>
.stmt-hero {
  border: 1px solid rgba(22, 119, 255, 0.18);
  background: linear-gradient(120deg, rgba(22, 119, 255, 0.08), rgba(114, 46, 209, 0.05));
}
.hero-main {
  display: flex;
  align-items: center;
  gap: 18px;
}
.hero-avatar {
  flex: 0 0 auto;
  background: #1677ff;
  color: #fff;
  font-size: 22px;
  font-weight: 600;
}
.hero-info { min-width: 0; }
.hero-name-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.hero-name {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
}
.hero-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  margin-top: 8px;
}
.meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.6);
}
.meta-chip :deep(svg) { color: rgba(0, 0, 0, 0.35); }

.kpi-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.muted { color: rgba(0, 0, 0, 0.45); }
.amt-debit { color: #e11d48; font-weight: 600; }
.amt-credit { color: #166534; font-weight: 600; }
</style>
