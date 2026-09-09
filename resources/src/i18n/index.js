import { createI18n } from 'vue-i18n';

/**
 * Translations come from the SAME source as the legacy admin:
 *   GET /api/translations/{locale}  ->  flat { key: value } map (public route)
 * and the active language lives in localStorage under 'language' — the same key
 * the Vue 2 app uses, so switching language in either app carries over.
 *
 * Keys are the legacy underscore style: $t('Search_this_table').
 */

export const SUPPORTED_LOCALES = [
    { value: 'en', label: 'English', flag: '🇬🇧' },
    { value: 'fr', label: 'Français', flag: '🇫🇷' },
    { value: 'es', label: 'Español', flag: '🇪🇸' },
    { value: 'ar', label: 'العربية', flag: '🇸🇦' },
];

const RTL_LOCALES = ['ar'];

export function isRtlLocale(locale) {
    return RTL_LOCALES.includes(locale);
}

export function storedLocale() {
    try {
        return window.localStorage.getItem('language') || 'en';
    } catch (e) {
        return 'en';
    }
}

// Case-insensitive rescue index: locale -> { lowercased key: value }. The
// legacy key set mixes casings ('warehouse' vs 'Warehouse'); when a lookup
// misses only because of casing, serve the other casing's translation instead
// of leaking the raw key.
const lowerIndex = new Map();

function indexLocale(locale, messages) {
    const idx = Object.create(null);
    for (const k of Object.keys(messages || {})) idx[k.toLowerCase()] = messages[k];
    lowerIndex.set(locale, idx);
}

export const i18n = createI18n({
    legacy: false,          // Composition API mode
    globalInjection: true,  // keep $t() available in templates
    locale: storedLocale(),
    fallbackLocale: 'en',
    messages: {},
    missingWarn: false,
    fallbackWarn: false,
    // Runs only on a miss; active locale's other casing first, then English's.
    missing: (locale, key) => {
        const lk = String(key).toLowerCase();
        for (const loc of [locale, 'en']) {
            const idx = lowerIndex.get(loc);
            if (idx && idx[lk] !== undefined) return idx[lk];
        }
        return undefined;
    },
});

const loaded = new Set();

/**
 * Fetches a locale's messages once and activates it. Never throws: on failure
 * the app keeps running and $t() falls back to echoing the key, which stays
 * readable because the legacy keys are English-ish (e.g. 'Search_this_table').
 */
export async function loadLocale(locale) {
    if (!loaded.has(locale)) {
        try {
            const res = await fetch(`/api/translations/${locale}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                // Translations are edited at runtime (admin UI, seeder); a stale
                // HTTP cache would keep showing old values after a fix.
                cache: 'no-store',
            });
            if (res.ok) {
                const messages = await res.json();
                i18n.global.setLocaleMessage(locale, messages || {});
                indexLocale(locale, messages);
                loaded.add(locale);
            }
        } catch (e) {
            console.warn(`[stocky-next] could not load translations for "${locale}"`);
        }
    }

    i18n.global.locale.value = locale;
    applyDirection(locale);
    try {
        window.localStorage.setItem('language', locale);
    } catch (e) { /* ignore storage errors */ }
}

export function applyDirection(locale) {
    const dir = isRtlLocale(locale) ? 'rtl' : 'ltr';
    document.documentElement.setAttribute('dir', dir);
    document.documentElement.setAttribute('lang', locale);
}

/** Translate with a human-readable fallback when a key is missing. */
export function t(key, fallback) {
    const out = i18n.global.t(key);
    return out === key && fallback ? fallback : out;
}
