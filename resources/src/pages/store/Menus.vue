<template>
  <div class="page">
    <PageHeader :title="$t('Menus')" :breadcrumb="[$t('Store'), $t('Menus')]">
      <template #extra>
        <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-alert type="info" show-icon :message="$t('Menus_Help')" style="margin-bottom: 16px" />

      <a-row :gutter="[16, 16]">
        <a-col v-for="loc in LOCATIONS" :key="loc.key" :xs="24" :lg="8">
          <a-card size="small" style="height: 100%">
            <template #title>
              {{ $t(loc.label) }}
              <a-tag style="margin-left: 8px">{{ menus[loc.key].length }}</a-tag>
            </template>
            <a-empty v-if="!menus[loc.key].length" :description="$t('No_items')" />
            <div v-for="(item, idx) in menus[loc.key]" :key="loc.key + '-' + idx" class="menu-item">
              <div style="display: flex; gap: 6px; margin-bottom: 6px">
                <a-input v-model:value="item.label" size="small" :placeholder="$t('Label')" />
                <a-button size="small" :disabled="idx === 0" @click="move(loc.key, idx, -1)">↑</a-button>
                <a-button size="small" :disabled="idx === menus[loc.key].length - 1" @click="move(loc.key, idx, 1)">↓</a-button>
                <a-button size="small" danger @click="menus[loc.key].splice(idx, 1)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </div>
              <div style="display: flex; gap: 6px">
                <a-select v-model:value="item.type" size="small" style="width: 44%" @change="item.value = ''">
                  <a-select-option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}</a-select-option>
                </a-select>
                <a-select v-if="item.type === 'page'" v-model:value="item.value" size="small" style="flex: 1">
                  <a-select-option value="">— {{ $t('Select') }} —</a-select-option>
                  <a-select-option v-for="p in pages" :key="p.slug" :value="p.slug">{{ p.title }}</a-select-option>
                </a-select>
                <a-select v-else-if="item.type === 'collection'" v-model:value="item.value" size="small" style="flex: 1">
                  <a-select-option value="">— {{ $t('Select') }} —</a-select-option>
                  <a-select-option v-for="c in collections" :key="c.slug" :value="c.slug">{{ c.title }}</a-select-option>
                </a-select>
                <a-input v-else-if="item.type === 'url'" v-model:value="item.value" size="small" placeholder="https://…" style="flex: 1" />
                <span v-else style="flex: 1; font-size: 12px; color: #999; align-self: center">{{ $t('Built_in_link') }}</span>
              </div>
            </div>
            <a-button size="small" style="margin-top: 10px" @click="menus[loc.key].push({ label: '', type: 'url', value: '' })">
              <template #icon><PlusOutlined /></template>
              {{ $t('Add_Item') }}
            </a-button>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Store menus — GET admin/store/menus → {menus{header, footer_shop,
 * footer_support}, pages, collections}; save POST admin/store/menus with
 * {menus: JSON string} after dropping items with empty labels (legacy).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const LOCATIONS = [
  { key: 'header', label: 'Main_Navigation' },
  { key: 'footer_shop', label: 'Footer_Shop' },
  { key: 'footer_support', label: 'Footer_Support' },
];

const isLoading = ref(true);
const saving = ref(false);
const menus = ref({ header: [], footer_shop: [], footer_support: [] });
const pages = ref([]);
const collections = ref([]);

const typeOptions = computed(() => [
  { value: 'home', label: t('Home') },
  { value: 'shop', label: t('Shop') },
  { value: 'flash_sales', label: t('Flash_Sales') },
  { value: 'contact', label: t('Contact') },
  { value: 'wishlist', label: t('MyWishlist') },
  { value: 'compare', label: t('Compare') },
  { value: 'page', label: t('Page') },
  { value: 'collection', label: t('Collection') },
  { value: 'url', label: t('Custom_URL') },
]);

function normalizeLoc(arr) {
  return (Array.isArray(arr) ? arr : []).map(it => ({
    label: (it && it.label) || '',
    type: (it && it.type) || 'url',
    value: (it && it.value) || '',
  }));
}
function move(key, idx, dir) {
  const j = idx + dir;
  if (j < 0 || j >= menus.value[key].length) return;
  const arr = menus.value[key];
  const [it] = arr.splice(idx, 1);
  arr.splice(j, 0, it);
}

async function fetch() {
  try {
    const r = await http.get('admin/store/menus');
    const m = (r && r.menus) || {};
    menus.value = {
      header: normalizeLoc(m.header),
      footer_shop: normalizeLoc(m.footer_shop),
      footer_support: normalizeLoc(m.footer_support),
    };
    pages.value = (r && r.pages) || [];
    collections.value = (r && r.collections) || [];
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
async function save() {
  saving.value = true;
  try {
    const clean = {};
    ['header', 'footer_shop', 'footer_support'].forEach(k => {
      clean[k] = menus.value[k].filter(it => (it.label || '').trim() !== '');
    });
    await http.post('admin/store/menus', { menus: JSON.stringify(clean) });
    message.success(t('Successfully_Updated'));
    fetch();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(fetch);
</script>

<style scoped>
.menu-item {
  background: rgba(0, 0, 0, 0.02);
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 8px;
  padding: 8px;
  margin-bottom: 8px;
}
</style>
