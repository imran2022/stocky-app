<template>
  <div class="page">
    <PageHeader
      :title="pageTitle"
      :subtitle="pageSubtitle"
      :breadcrumb="[$t('Sales'), pageTitle]"
    >
      <template #actions>
        <a-button v-if="canCreate" type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }} {{ singularLabel }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      type="info"
      show-icon
      :message="`${singularLabel} names saved here appear automatically on Create Sale and shipment workflows.`"
      style="margin-bottom: 16px"
    />

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span class="lookup-name">{{ record.name }}</span>
        </template>
        <template v-else-if="column.key === 'sales_count'">
          <a-tag color="blue">{{ record.sales_count || 0 }}</a-tag>
        </template>
        <template v-else-if="column.key === 'updated_at'">
          {{ dateTime(record.updated_at) || '—' }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-tooltip v-if="canEdit" :title="$t('Edit')">
            <a-button size="small" @click="openEdit(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
          </a-tooltip>
          <span v-else>—</span>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editId ? `Edit ${singularLabel}` : `Add ${singularLabel}`"
      :confirm-loading="saving"
      :ok-text="editId ? $t('Update') : $t('Save')"
      centered
      @ok="save"
      @cancel="resetForm"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="`${singularLabel} Name`" name="name">
          <a-input
            v-model:value="form.name"
            :maxlength="191"
            show-count
            autofocus
            @pressEnter="save"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Shared management screen for the two small Sale metadata lists. Route meta
 * selects Zone/Area or Courier; both use the same backend normalization as the
 * inline CreatableSelect, so there is one source of truth.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const route = useRoute();
const auth = useAuthStore();
const { t } = useI18n();
const { dateTime } = useFormat();

const kind = computed(() => route.meta.lookupType === 'courier' ? 'courier' : 'zone');
const isZone = computed(() => kind.value === 'zone');
const endpoint = computed(() => isZone.value ? 'sale_zones' : 'sale_couriers');
const rowsKey = computed(() => isZone.value ? 'zones' : 'couriers');
const singularLabel = computed(() => isZone.value ? 'Zone / Area' : 'Courier');
const pageTitle = computed(() => isZone.value ? 'Zones / Areas' : 'Couriers');
const pageSubtitle = computed(() => isZone.value
  ? 'Maintain delivery zones and areas used for sales tracking.'
  : 'Maintain courier names used for sales and shipment tracking.');

const canCreate = computed(() => ['Sales_add', 'Sales_edit', 'Pos_view', 'shipment'].some(p => auth.can(p)));
const canEdit = computed(() => auth.can('Sales_edit'));

const crud = useCrudTable(() => endpoint.value, {
  select: payload => ({
    rows: payload?.[rowsKey.value] || [],
    total: payload?.totalRows || 0,
  }),
  sortField: 'name',
  sortType: 'asc',
});

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: 'Sales Used', dataIndex: 'sales_count', key: 'sales_count', sorter: true, align: 'center', width: 130 },
  { title: 'Last Updated', dataIndex: 'updated_at', key: 'updated_at', sorter: true, width: 190 },
  { title: t('Action'), key: 'actions', align: 'center', width: 90 },
]);

const modalOpen = ref(false);
const saving = ref(false);
const editId = ref(null);
const formRef = ref();
const form = reactive({ name: '' });
const rules = computed(() => ({
  name: [
    { required: true, whitespace: true, message: `${singularLabel.value} name is required.` },
    { max: 191, message: 'Maximum 191 characters.' },
  ],
}));

function resetForm() {
  editId.value = null;
  form.name = '';
  formRef.value?.clearValidate();
}

function openCreate() {
  resetForm();
  modalOpen.value = true;
}

function openEdit(record) {
  editId.value = record.id;
  form.name = record.name || '';
  formRef.value?.clearValidate();
  modalOpen.value = true;
}

function firstError(error) {
  const errors = error?.data?.errors;
  if (errors) {
    const first = Object.values(errors).flat()[0];
    if (first) return String(first);
  }
  return error?.data?.message || t('InvalidData');
}

async function save() {
  if (saving.value) return;
  try {
    await formRef.value?.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editId.value) {
      await http.put(`${endpoint.value}/${editId.value}`, { name: form.name });
      message.success(t('Successfully_Updated'));
    } else {
      await http.post(endpoint.value, { name: form.name });
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    resetForm();
    await crud.fetchRows();
  } catch (e) {
    message.error(firstError(e));
  } finally {
    saving.value = false;
  }
}

watch(kind, async () => {
  crud.page.value = 1;
  crud.search.value = '';
  resetForm();
  modalOpen.value = false;
  await crud.fetchRows();
});

onMounted(crud.fetchRows);
</script>

<style scoped>
.lookup-name {
  font-weight: 600;
}
</style>
