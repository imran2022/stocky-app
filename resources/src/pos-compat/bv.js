/**
 * Bootstrap-Vue runtime shims for the POS port: the $bvModal show/hide
 * registry, a $bvToast.toast() that renders Bootstrap toast markup, and the
 * legacy global `Fire` event bus. Kept dependency-free.
 */

// ---------------- modal registry ($bvModal) ----------------

const modals = new Map();

export function registerModal(id, api) {
    if (id) modals.set(id, api);
}

export function unregisterModal(id) {
    modals.delete(id);
}

export const bvModal = {
    show(id) { modals.get(id)?.open(); },
    hide(id) { modals.get(id)?.close(); },
};

// ---------------- toasts ($bvToast) ----------------

let toastHost = null;

function ensureToastHost() {
    if (toastHost && document.body.contains(toastHost)) return toastHost;
    toastHost = document.createElement('div');
    toastHost.className = 'pos-scope';
    Object.assign(toastHost.style, {
        position: 'fixed',
        top: '16px',
        right: '16px',
        zIndex: '11000',
        display: 'flex',
        flexDirection: 'column',
        gap: '8px',
        maxWidth: '340px',
    });
    document.body.appendChild(toastHost);
    return toastHost;
}

export const bvToast = {
    toast(message, { title = '', variant = 'default' } = {}) {
        const host = ensureToastHost();
        const el = document.createElement('div');
        el.className = 'toast show';
        el.setAttribute('role', 'alert');
        const border = { success: '#28a745', danger: '#dc3545', warning: '#ffc107', info: '#17a2b8' }[variant];
        if (border) el.style.borderLeft = `4px solid ${border}`;
        el.innerHTML = `
            ${title ? `<div class="toast-header"><strong class="mr-auto">${title}</strong></div>` : ''}
            <div class="toast-body"></div>`;
        el.querySelector('.toast-body').textContent = String(message ?? '');
        host.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    },
};

// ---------------- legacy Fire bus ----------------

const listeners = new Map();

export const Fire = {
    $on(event, fn) {
        if (!listeners.has(event)) listeners.set(event, new Set());
        listeners.get(event).add(fn);
    },
    $off(event, fn) {
        if (!listeners.has(event)) return;
        if (fn) listeners.get(event).delete(fn);
        else listeners.delete(event);
    },
    $emit(event, ...args) {
        listeners.get(event)?.forEach(fn => fn(...args));
    },
};
