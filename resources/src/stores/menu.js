import { defineStore } from 'pinia';
import { MENU } from '../config/menu';
import { applyMenuOrder } from '../lib/menuOrder';
import { useAuthStore } from './auth';

/**
 * The navigation actually rendered by the sidebars: the default MENU arranged
 * per the admin-saved order (Settings → Sidebar Menu). The saved order arrives
 * with get_user_auth (auth.user.sidebar_menu_order), so this recomputes both at
 * boot and live when the manager saves a new arrangement.
 */
export const useMenuStore = defineStore('menu', {
    getters: {
        menu() {
            const auth = useAuthStore();
            const order = auth.user && auth.user.sidebar_menu_order;
            let arranged = MENU;
            if (Array.isArray(order) && order.length) {
                try {
                    arranged = applyMenuOrder(order);
                } catch (e) {
                    // A malformed saved order must never take the navigation down.
                    arranged = MENU;
                }
            }
            // Drop top-level groups whose module the admin switched off
            // (Settings → Modules). Recomputes live when the toggles save.
            return arranged.filter(entry => auth.moduleEnabled(entry.key));
        },
    },
});
