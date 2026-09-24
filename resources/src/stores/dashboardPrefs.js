import { defineStore } from 'pinia';
import http from '../lib/http';
import { normaliseLayout } from '../pages/dashboard/modern/sections';
import { useAuthStore } from './auth';

// The last resolved style is remembered in this browser so the right dashboard can start loading before the server answers.
const cacheKey = () => `stocky_dash_style_${useAuthStore().user?.id ?? 'x'}`;
export function cachedDashboardStyle() {
  try { return localStorage.getItem(cacheKey()) === 'modern' ? 'modern' : null; } catch (e) { return null; }
}
function rememberStyle(style) {
  try { localStorage.setItem(cacheKey(), style); } catch (e) { /* private mode: only the head start is lost */ }
}

/**
 * Which dashboard the user sees (Classic / Modern) and how the Modern sections are arranged.
 * The server resolves user choice -> organisation default -> Classic. If the call fails the app simply shows Classic.
 */
export const useDashboardPrefsStore = defineStore('dashboardPrefs', {
  state: () => ({
    loaded: false,
    loading: null,
    style: 'classic',
    myStyle: null,
    defaultStyle: 'classic',
    layout: normaliseLayout(null),
    myLayout: null,
    defaultLayout: normaliseLayout(null),
    canSetDefault: false,
    canSwitch: true,
    allowUserSwitch: true,
    error: false,
  }),
  actions: {
    apply(d) {
      this.style = d.style === 'modern' ? 'modern' : 'classic';
      this.myStyle = d.my_style || null;
      this.defaultStyle = d.default_style === 'modern' ? 'modern' : 'classic';
      this.layout = normaliseLayout(d.layout);
      this.myLayout = d.my_layout ? normaliseLayout(d.my_layout) : null;
      this.defaultLayout = normaliseLayout(d.default_layout);
      this.canSetDefault = !!d.can_set_default;
      this.canSwitch = d.can_switch !== false;
      this.allowUserSwitch = d.allow_user_switch !== false;
      rememberStyle(this.style);
      this.loaded = true;
      this.error = false;
    },
    async load(force = false) {
      if (this.loaded && !force) return;
      if (this.loading) return this.loading;
      this.loading = http.get('dashboard_preferences')
        .then(d => this.apply(d))
        .catch(() => { this.error = true; this.loaded = true; })   // fall back to Classic, never break the dashboard
        .finally(() => { this.loading = null; });
      return this.loading;
    },
    async save(body, { asDefault = false } = {}) {
      const d = await http.put(asDefault ? 'dashboard_preferences/default' : 'dashboard_preferences', body);
      this.apply(d);
    },
    // Optimistic: the dashboard switches at once; if the server refuses, it switches back.
    async setStyle(style) {
      const before = { style: this.style, myStyle: this.myStyle };
      this.style = style; this.myStyle = style;
      try { await this.save({ style }); } catch (e) { Object.assign(this, before); throw e; }
    },
    saveLayout(layout) { return this.save({ layout }); },
    resetLayout() { return this.save({ layout: null }); },
  },
});
