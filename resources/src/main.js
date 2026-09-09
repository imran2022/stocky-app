import { createApp, defineAsyncComponent } from 'vue';
import { createPinia } from 'pinia';
import Antd from 'ant-design-vue';

import 'ant-design-vue/dist/reset.css';
// Icon font only (.bi-* classes) — category icons are stored as these classes.
import 'bootstrap-icons/font/bootstrap-icons.css';
import './assets/theme.css';

import App from './App.vue';
import router from './router';
import { i18n, loadLocale, storedLocale } from './i18n';

// Renderer-level crashes never reach app.config.errorHandler, so catch them here.
// "ResizeObserver loop ..." is a benign browser notice (Ant's table/layout use
// ResizeObserver); it breaks nothing and would otherwise drown real errors.
const BENIGN = /^ResizeObserver loop/;
window.addEventListener('error', e => {
    if (BENIGN.test(e.message || '')) return;
    console.error('[stocky-next] uncaught:', e.message, '\n', e.error && e.error.stack);
});
window.addEventListener('unhandledrejection', e => {
    console.error('[stocky-next] unhandled promise rejection:', e.reason);
});

// After a rebuild the old hashed chunks are deleted, so a tab loaded before the
// deploy 404s on its first lazy import (typically an Export button or a route
// change) and the action just dies. Reload once to pick up the new manifest;
// the guard flag stops a reload loop if the new page still can't fetch chunks.
window.addEventListener('vite:preloadError', e => {
    e.preventDefault();
    if (sessionStorage.getItem('stocky-chunk-reload') === '1') return;
    sessionStorage.setItem('stocky-chunk-reload', '1');
    window.location.reload();
});

const app = createApp(App);

// Name the failing component and print the message/stack as strings — object
// logs collapse in the console and hide exactly what we need.
app.config.errorHandler = (err, instance, info) => {
    const type = instance?.$?.type || instance?.$options || {};
    const name = type.name || type.__name || type.__file || 'unknown component';
    const chain = [];
    for (let p = instance?.$?.parent; p; p = p.parent) {
        const pt = p.type || {};
        chain.push(pt.name || pt.__name || pt.__file || '?');
    }
    console.error(
        `[stocky-next] error in <${name}> (${info})`
        + `\n  message: ${err?.message}`
        + `\n  parents: ${chain.join(' < ') || '(root)'}`
        + `\n  stack:\n${err?.stack || '(none)'}`
    );
};

app.use(createPinia());
app.use(i18n);
app.use(router);
app.use(Antd);
// Same global <apexchart> tag the plugin would register, but async: apexcharts
// (~550 kB min) stays out of the entry and loads as its own cached chunk only
// when a chart page renders.
app.component('apexchart', defineAsyncComponent(() => import('vue3-apexcharts')));

// Mount only when the first paint can be complete: translations loaded AND the
// initial route fully resolved (auth check + page chunk). Until then the boot
// loader in next.blade.php stays on screen — mounting replaces it. Without
// router.isReady() the app mounts into a blank shell while the guard awaits
// auth, which reads as a broken refresh.
Promise.allSettled([loadLocale(storedLocale()), router.isReady()]).then(() => {
    // Booted fine — arm the chunk-failure reload again for the next deploy.
    sessionStorage.removeItem('stocky-chunk-reload');
    app.mount('#vue3-app');
});
