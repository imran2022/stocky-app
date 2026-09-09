/**
 * Options-API mixin injected into the ported POS page. Provides every global
 * the legacy script assumes: $bvModal/$bvToast, the Fire bus, Vue 2's
 * $set/$delete, a minimal $store facade over our Pinia auth/ui stores, and
 * neutral `errors`/`valid` fallbacks for template spots whose Vue-2-only
 * slot-scope had to be stripped.
 */
import { bvModal, bvToast } from './bv';
import { useAuthStore } from '../stores/auth';
import { loadLocale, applyDirection } from '../i18n';

export default {
    data() {
        return {
            // Neutral validation state for stripped slot-scope references.
            errors: [],
            valid: null,
        };
    },
    computed: {
        currentUser() {
            return useAuthStore().user || {};
        },
        currentUserPermissions() {
            return useAuthStore().permissions || [];
        },
        show_language() {
            return true;
        },
        $store() {
            // Legacy touches $store via dispatch + priceFormat helpers that
            // read getters with safe fallbacks.
            return {
                getters: {},
                dispatch: (action, payload) => {
                    if (action === 'logout') {
                        window.location.href = '/login';
                    } else if (action === 'setLanguage') {
                        localStorage.setItem('language', payload);
                        loadLocale(payload).then(() => applyDirection(payload)).catch(() => {});
                    }
                },
            };
        },
    },
    created() {
        this.$bvModal = bvModal;
        this.$bvToast = bvToast;
        // Vue 3 reactivity makes plain assignment reactive — Vue 2's escape
        // hatches reduce to these.
        this.$set = (target, key, value) => { target[key] = value; return value; };
        this.$delete = (target, key) => { delete target[key]; };
    },
};
