<template>
  <a-select
    :value="value"
    show-search
    allow-clear
    option-filter-prop="label"
    :placeholder="placeholder"
    :options="options"
    :loading="creating"
    style="width: 100%"
    @update:value="v => emit('update:value', v)"
    @search="v => (searchText = v)"
    @dropdownVisibleChange="open => { if (!open) searchText = '' }"
  >
    <template #dropdownRender="{ menuNode }">
      <VNodes :vnodes="menuNode" />
      <a-divider style="margin: 4px 0" />
      <div style="display: flex; gap: 6px; padding: 4px 8px" @mousedown="e => e.preventDefault()">
        <a-input
          :value="searchText"
          size="small"
          :placeholder="addPlaceholder"
          @update:value="v => (searchText = v)"
          @pressEnter="createOption"
        />
        <a-button size="small" type="primary" :loading="creating" @click="createOption">
          <template #icon><PlusOutlined /></template>
        </a-button>
      </div>
    </template>
  </a-select>
</template>

<script setup>
/**
 * Searchable <a-select> for a small user-managed lookup list (Sale Zone,
 * Sale Courier, …). Values already saved in the DB show up as normal
 * options; typing a new name and pressing Enter / the + button creates it
 * via `createEndpoint` (POST {name}) and selects it immediately, so the
 * user never has to leave the Create/Edit Sale form to manage the list.
 *
 * Props:
 * - value          selected id (v-model:value)
 * - options        [{value, label}] (v-model:options — updated in place
 *                   after a create, so the parent's list stays current)
 * - createEndpoint API path the new name is POSTed to, e.g. 'sale_zones'
 * - responseKey    key of the created record in the response, e.g. 'zone'
 */
import { ref, h } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined } from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import http from '../lib/http';

const { t } = useI18n();

const props = defineProps({
  value: { type: [Number, String], default: undefined },
  options: { type: Array, default: () => [] },
  createEndpoint: { type: String, required: true },
  responseKey: { type: String, required: true },
  placeholder: { type: String, default: '' },
  addPlaceholder: { type: String, default: 'Type a new name and press Enter' },
});
const emit = defineEmits(['update:value', 'update:options']);

// Renders the ant-design-vue-supplied menu vnode(s) inside our custom dropdown.
const VNodes = (_, { attrs }) => h(attrs.vnodes);

const searchText = ref('');
const creating = ref(false);

async function createOption() {
  const name = searchText.value.trim();
  if (!name) return;

  // Already exists locally (case-insensitive) — just select it.
  const existing = props.options.find(o => String(o.label).toLowerCase() === name.toLowerCase());
  if (existing) {
    emit('update:value', existing.value);
    searchText.value = '';
    return;
  }

  creating.value = true;
  try {
    const data = await http.post(props.createEndpoint, { name });
    const record = data?.[props.responseKey];
    if (record?.id) {
      emit('update:options', [...props.options, { value: record.id, label: record.name }]);
      emit('update:value', record.id);
      searchText.value = '';
    }
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    creating.value = false;
  }
}

defineExpose({});
</script>
