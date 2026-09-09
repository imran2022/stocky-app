/**
 * Client Portal — Vue 3 port of the legacy portal.js entry.
 * Mounts the portal SPA into #portal-app. Auth/CSRF/redirect behaviour lives in
 * lib/http (the axios-shaped fetch wrapper). No i18n: the portal's labels are
 * plain English, exactly as the Vue 2 app had them.
 */
import { createApp } from 'vue';
import App from './App.vue';
import router from './router';

createApp(App).use(router).mount('#portal-app');
