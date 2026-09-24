import { useI18n } from 'vue-i18n';

/**
 * tt(key, english) - the app's translation when the key exists, otherwise the English text.
 * The Modern Dashboard adds a few labels that are not in the translation tables yet; they must never show as a raw key.
 */
export function useTt() {
  const { t, te } = useI18n();
  return (key, fallback) => {
    if (te(key)) return t(key);
    const v = t(key);
    return v && v !== key ? v : fallback;
  };
}
