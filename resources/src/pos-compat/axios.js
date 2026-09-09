/**
 * Axios-shaped adapter over lib/http for the POS port: the 6k-line legacy
 * script calls `axios.get(url).then(r => r.data)` everywhere, so responses
 * are wrapped back into `{ data }`. Supports the config subset POS uses:
 * { params }, { responseType: 'blob' }, { timeout } (ignored — fetch has no
 * trivial timeout and the legacy value was advisory).
 */
import http from '../lib/http';

function csrfHeaders() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

async function blobGet(url) {
    const res = await fetch('/api/' + url.replace(/^\//, ''), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
    });
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`);
        err.response = { status: res.status };
        throw err;
    }
    return { data: await res.blob() };
}

function wrapError(e) {
    // Legacy reads error.response.data.* — mirror that shape.
    e.response = e.response || { status: e.status, data: e.data };
    throw e;
}

export default {
    async get(url, config = {}) {
        if (config.responseType === 'blob') return blobGet(url);
        try {
            return { data: await http.get(url, config.params) };
        } catch (e) { wrapError(e); }
    },
    async post(url, body, config = {}) {
        try {
            const data = body instanceof FormData
                ? await http.postForm(url, body)
                : await http.post(url, body);
            return { data };
        } catch (e) { wrapError(e); }
    },
    async put(url, body) {
        try {
            return { data: await http.put(url, body) };
        } catch (e) { wrapError(e); }
    },
    async delete(url) {
        try {
            return { data: await http.delete(url) };
        } catch (e) { wrapError(e); }
    },
};
