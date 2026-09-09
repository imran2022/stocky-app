import { computed } from 'vue';
import dayjs from 'dayjs';
import { useAuthStore } from '../stores/auth';
import { formatPriceDisplay, resolvePriceDecimals, roundAmount } from '../lib/priceFormat';

/**
 * Money/date/number formatting driven by the authenticated user's settings
 * (currency symbol, price_format, price_decimals, date_format) — the same
 * values the legacy admin reads from get_user_auth.
 *
 *   const { money, date, number } = useFormat();
 *   money(1234.5)  // "$ 1,234.50"
 */
export function useFormat() {
    const auth = useAuthStore();

    const decimals = computed(() => resolvePriceDecimals(auth.user?.price_decimals));
    const formatKey = computed(() => auth.user?.price_format || null);
    const currency = computed(() => auth.currency);
    const dateFormat = computed(() => auth.user?.date_format || 'YYYY-MM-DD');

    /** Number with the configured separators/precision, no currency symbol. */
    function number(value, dec) {
        return formatPriceDisplay(value, dec ?? decimals.value, formatKey.value);
    }

    /** Number prefixed with the user's currency symbol. */
    function money(value, dec) {
        const formatted = number(value, dec);
        return currency.value ? `${currency.value} ${formatted}` : formatted;
    }

    /**
     * Round to the configured monetary precision, returning a Number. Use for
     * amounts bound to inputs (:max, prefills) so raw float sums like
     * 121.99999999999994 never surface in the UI.
     */
    function roundMoney(value) {
        return roundAmount(value, decimals.value);
    }

    /** Date in the user's configured format; empty string for empty input. */
    function date(value, format) {
        if (!value) return '';
        const d = dayjs(value);
        return d.isValid() ? d.format(format || dateFormat.value) : String(value);
    }

    function dateTime(value) {
        return date(value, `${dateFormat.value} HH:mm`);
    }

    return { money, number, roundMoney, date, dateTime, currency, decimals, dateFormat };
}
