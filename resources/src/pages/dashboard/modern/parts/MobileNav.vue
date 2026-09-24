<template>
  <nav class="dm-bnav" :aria-label="tt('Quick_navigation', 'Quick navigation')">
    <button type="button" class="dm-bnav-i" :class="{ on: route.path === '/dashboard' }" @click="home"><HomeOutlined /><span>{{ tt('Home', 'Home') }}</span></button>
    <button v-if="can('Sales_view')" type="button" class="dm-bnav-i" :class="{ on: route.path.startsWith('/sales') }" @click="go('/sales')"><ShoppingCartOutlined /><span>{{ t('Sales') }}</span></button>
    <button v-if="can('Pos_view')" type="button" class="dm-bnav-pos" :aria-label="t('Pos')" @click="go('/pos')"><CalculatorOutlined /><span>{{ t('Pos') }}</span></button>
    <button v-if="can('products_view')" type="button" class="dm-bnav-i" :class="{ on: route.path.startsWith('/products') }" @click="go('/products')"><AppstoreOutlined /><span>{{ t('Products') }}</span></button>
    <button type="button" class="dm-bnav-i" :aria-label="tt('Menu', 'Menu')" @click="openMenu"><MenuOutlined /><span>{{ tt('Menu', 'Menu') }}</span></button>
  </nav>
</template>
<script setup>
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { HomeOutlined, ShoppingCartOutlined, CalculatorOutlined, AppstoreOutlined, MenuOutlined } from '@ant-design/icons-vue';
import { useAuthStore } from '../../../../stores/auth';
import { useTt } from '../useTt';

const route = useRoute(), router = useRouter(), auth = useAuthStore();
const { t } = useI18n();
const tt = useTt();
const can = p => auth.can(p);
function go(to) { router.push(to); }
function home() { if (route.path === '/dashboard') window.scrollTo({ top: 0, behavior: 'smooth' }); else router.push('/dashboard'); }
// "Menu" opens the app's own sidebar drawer: the same action as the hamburger button in the top bar (no vendor file is touched).
function openMenu() { document.querySelector('.topbar button[aria-label="Toggle menu"]')?.click(); }
</script>
