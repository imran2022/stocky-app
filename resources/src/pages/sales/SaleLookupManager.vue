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
        <template v-if="column.key === 'division'">
          <span v-if="record.division">{{ record.division.name }}</span>
          <span v-else class="text-muted">—</span>
        </template>
        <template v-else-if="column.key === 'name'">
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
          <a-tooltip
            v-if="canEdit && isZone"
            :title="record.sales_count > 0 ? 'Cannot delete: sales are still linked to this zone' : $t('Delete')"
          >
            <a-popconfirm
              v-if="!record.sales_count"
              title="Delete this Zone/Area? This cannot be undone."
              ok-text="Delete"
              cancel-text="Cancel"
              @confirm="destroyZone(record)"
            >
              <a-button size="small" danger style="margin-left: 4px">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-popconfirm>
            <a-button v-else size="small" danger disabled style="margin-left: 4px">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-tooltip>
          <span v-if="!canEdit">—</span>
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
            @change="onNameChange"
          />
        </a-form-item>
        <a-form-item
          v-if="isZone"
          label="Division / State"
          name="division_id"
          extra="Auto-detected from the name when it matches a known district. Type a name that doesn't exist yet to create a new Division; use the icons to rename or delete the selected one."
        >
          <div style="display: flex; gap: 6px">
            <CreatableSelect
              v-model:value="form.division_id"
              v-model:options="divisionOptions"
              create-endpoint="bd_divisions"
              response-key="division"
              placeholder="— Not linked —"
              add-placeholder="Type a new Division name and press Enter"
              style="flex: 1"
              @update:value="userTouchedDivision = true"
            />
            <a-tooltip title="Rename this Division">
              <a-button size="small" :disabled="!form.division_id" @click="openRenameDivision">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-popconfirm
              v-if="form.division_id"
              title="Delete this Division? It must not have any Zone/Area still linked to it."
              ok-text="Delete"
              cancel-text="Cancel"
              @confirm="deleteDivision"
            >
              <a-tooltip title="Delete this Division">
                <a-button size="small" danger>
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-popconfirm>
            <a-button v-else size="small" danger disabled>
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </div>
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="renameDivisionOpen"
      title="Rename Division"
      :confirm-loading="renamingDivision"
      ok-text="Update"
      @ok="submitRenameDivision"
    >
      <a-input
        v-model:value="renameDivisionName"
        :maxlength="191"
        show-count
        autofocus
        @pressEnter="submitRenameDivision"
      />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Shared management screen for the two small Sale metadata lists. Route meta
 * selects Zone/Area or Courier; both use the same backend normalization as the
 * inline CreatableSelect, so there is one source of truth.
 *
 * Divisions have no management page of their own -- they're managed right here,
 * inline in the Zone/Area modal: the Division field is itself a CreatableSelect
 * (type an existing name to select it, type a new one to create it, same
 * behavior the Zone/Area name field already has), plus a small rename/delete
 * pair of icons next to it for the currently-selected Division.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import CreatableSelect from '../../components/CreatableSelect.vue';
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

const columns = computed(() => {
  const cols = [{ title: t('Name'), dataIndex: 'name', key: 'name', sorter: true }];
  if (isZone.value) {
    cols.unshift({ title: 'Division / State', dataIndex: 'division', key: 'division', width: 160 });
  }
  cols.push(
    { title: 'Sales Used', dataIndex: 'sales_count', key: 'sales_count', sorter: true, align: 'center', width: 130 },
    { title: 'Last Updated', dataIndex: 'updated_at', key: 'updated_at', sorter: true, width: 190 },
    { title: t('Action'), key: 'actions', align: 'center', width: 110 },
  );
  return cols;
});

const modalOpen = ref(false);
const saving = ref(false);
const editId = ref(null);
const formRef = ref();
const form = reactive({ name: '', division_id: null });
const rules = computed(() => ({
  name: [
    { required: true, whitespace: true, message: `${singularLabel.value} name is required.` },
    { max: 191, message: 'Maximum 191 characters.' },
  ],
}));

// Zone/Area Division field: auto-suggested from the name while typing, but a manual pick always wins and stops
// further auto-suggestions from overwriting it (until the form is reset for a fresh create/edit). It's also a
// CreatableSelect (see template) so a Division can be created inline the same way a Zone/Area name can; the
// rename/delete icons next to it operate on whichever Division is currently selected.
const divisionOptions = ref([]); // [{value, label}] -- shape CreatableSelect expects/maintains
const userTouchedDivision = ref(false);
let suggestTimer = null;

async function loadDivisions() {
  if (divisionOptions.value.length || !isZone.value) return;
  try {
    const data = await http.get('sale_divisions');
    divisionOptions.value = (data?.divisions || []).map(d => ({ value: d.id, label: d.name }));
  } catch (e) {
    // Non-fatal: the Division field just stays empty; the rest of the page still works.
  }
}

const renameDivisionOpen = ref(false);
const renamingDivision = ref(false);
const renameDivisionName = ref('');

function openRenameDivision() {
  if (!form.division_id) return;
  const current = divisionOptions.value.find(o => o.value === form.division_id);
  renameDivisionName.value = current?.label || '';
  renameDivisionOpen.value = true;
}

async function submitRenameDivision() {
  const name = renameDivisionName.value.trim();
  if (!name || !form.division_id || renamingDivision.value) return;

  renamingDivision.value = true;
  try {
    const data = await http.put(`bd_divisions/${form.division_id}`, { name });
    const updated = data?.division;
    const entry = divisionOptions.value.find(o => o.value === form.division_id);
    if (entry && updated) entry.label = updated.name;
    renameDivisionOpen.value = false;
    message.success(t('Successfully_Updated'));
    await crud.fetchRows(); // other rows' Division column may show this same, now-renamed, Division
  } catch (e) {
    message.error(firstError(e));
  } finally {
    renamingDivision.value = false;
  }
}

async function deleteDivision() {
  if (!form.division_id) return;
  const divisionId = form.division_id;
  try {
    await http.delete(`bd_divisions/${divisionId}`);
    divisionOptions.value = divisionOptions.value.filter(o => o.value !== divisionId);
    form.division_id = null;
    userTouchedDivision.value = true;
    message.success(t('Successfully_Deleted') || 'Deleted successfully.');
    await crud.fetchRows();
  } catch (e) {
    message.error(firstError(e));
  }
}

function onNameChange() {
  if (!isZone.value || userTouchedDivision.value) return;
  clearTimeout(suggestTimer);
  suggestTimer = setTimeout(async () => {
    const name = form.name?.trim();
    if (!name || userTouchedDivision.value) return;
    try {
      const data = await http.get('sale_zones/suggest_division', { name });
      if (!userTouchedDivision.value) {
        form.division_id = data?.division_id ?? null;
      }
    } catch (e) {
      // Suggestion is a convenience only — ignore failures silently.
    }
  }, 400);
}

function resetForm() {
  editId.value = null;
  form.name = '';
  form.division_id = null;
  userTouchedDivision.value = false;
  clearTimeout(suggestTimer);
  formRef.value?.clearValidate();
}

function openCreate() {
  resetForm();
  loadDivisions();
  modalOpen.value = true;
}

function openEdit(record) {
  editId.value = record.id;
  form.name = record.name || '';
  form.division_id = record.division_id ?? record.division?.id ?? null;
  // Editing an existing, already-linked zone: treat its current division as a deliberate choice, not something
  // a name-only auto-suggestion should silently overwrite while the user is just fixing a typo in the name.
  userTouchedDivision.value = true;
  loadDivisions();
  formRef.value?.clearValidate();
  modalOpen.value = true;
}

async function destroyZone(record) {
  try {
    await http.delete(`sale_zones/${record.id}`);
    message.success(t('Successfully_Deleted') || 'Deleted successfully.');
    await crud.fetchRows();
  } catch (e) {
    message.error(firstError(e));
  }
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
    const payload = { name: form.name };
    if (isZone.value) {
      // Always send division_id explicitly (even null) so the backend treats it as the user's deliberate choice
      // rather than auto-resolving from the name again — the frontend's suggest-while-typing already did that.
      payload.division_id = form.division_id ?? null;
    }
    if (editId.value) {
      await http.put(`${endpoint.value}/${editId.value}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post(endpoint.value, payload);
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
