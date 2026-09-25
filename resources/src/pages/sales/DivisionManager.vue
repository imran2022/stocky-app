<template>
  <div class="page">
    <PageHeader title="Divisions / States" :breadcrumb="[$t('Sales'), 'Divisions / States']">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }} Division
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      type="info"
      show-icon
      message="Zones/Areas link to a Division here for reporting. Bangladesh's 8 Divisions come pre-loaded (with known districts, for auto-suggesting a Zone's Division); you can rename them or add your own custom Divisions too."
      style="margin-bottom: 16px"
    />

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'districts_count'">
          <a-tag>{{ record.districts_count || 0 }}</a-tag>
        </template>
        <template v-else-if="column.key === 'zones_count'">
          <a-tag color="blue">{{ record.zones_count || 0 }}</a-tag>
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
          <a-tooltip
            v-if="canEdit"
            :title="record.zones_count > 0 ? 'Cannot delete: Zones/Areas are still linked to this Division' : $t('Delete')"
          >
            <a-button
              size="small"
              danger
              :disabled="!!record.zones_count"
              style="margin-left: 4px"
              @click="crud.remove(record, { label: record.name })"
            >
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-tooltip>
          <span v-if="!canEdit">—</span>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editId ? 'Edit Division' : 'Add Division'"
      :confirm-loading="saving"
      :ok-text="editId ? $t('Update') : $t('Save')"
      centered
      @ok="save"
      @cancel="resetForm"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item label="Division Name" name="name">
          <a-input v-model:value="form.name" :maxlength="191" show-count autofocus @pressEnter="save" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Divisions used to be a fixed, hardcoded 8-row Bangladesh reference list (seeded by migration, no admin UI) --
 * this page turns them into a manageable list, same pattern as Zones/Areas and Couriers: create, rename, and
 * delete (blocked while any Zone/Area is still linked, so a Division actually in use can never disappear out from
 * under existing data). Custom (non-Bangladesh) Divisions can be added here too, then picked directly on the
 * Zone/Area form -- they simply won't get an auto-suggested match since there's no district list behind them.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime } = useFormat();
const auth = useAuthStore();

const canEdit = computed(() => auth.can('Sales_edit'));

const crud = useCrudTable('bd_divisions', {
  rowsKey: 'divisions',
  sortField: 'sort_order',
  sortType: 'asc',
});

const columns = computed(() => [
  { title: 'Name', dataIndex: 'name', key: 'name', sorter: true },
  { title: 'Districts', key: 'districts_count', align: 'center', width: 110 },
  { title: 'Zones Used', key: 'zones_count', align: 'center', width: 120 },
  { title: 'Last Updated', dataIndex: 'updated_at', key: 'updated_at', width: 190 },
  { title: t('Action'), key: 'actions', align: 'center', width: 110 },
]);

const modalOpen = ref(false);
const saving = ref(false);
const editId = ref(null);
const formRef = ref();
const form = reactive({ name: '' });
const rules = computed(() => ({
  name: [
    { required: true, whitespace: true, message: 'Division name is required.' },
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
      await http.put(`bd_divisions/${editId.value}`, { name: form.name });
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('bd_divisions', { name: form.name });
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

onMounted(crud.fetchRows);
</script>
