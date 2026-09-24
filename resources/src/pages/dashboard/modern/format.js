/** Small pure helpers shared by the Modern Dashboard sections. Nothing here invents a number. */

export const num = v => {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
};

/** Percentage change from `prev` to `cur`. null when there is nothing to compare with (prev is 0) - never Infinity. */
export function pctChange(cur, prev) {
  const c = num(cur), p = num(prev);
  if (Math.abs(p) < 0.005) return null;
  return ((c - p) / Math.abs(p)) * 100;
}

export function fmtPct(v, digits = 1) {
  if (v === null || v === undefined || !Number.isFinite(Number(v))) return '-';
  return `${Number(v).toFixed(digits).replace(/\.0+$/, '')}%`;
}

export function compactNumber(value) {
  const n = num(value);
  const sign = n < 0 ? '-' : '';
  const a = Math.abs(n);
  if (a >= 1e7) return `${sign}${(a / 1e6).toFixed(0)}M`;
  if (a >= 1e6) return `${sign}${(a / 1e6).toFixed(1).replace(/\.0$/, '')}M`;
  if (a >= 1e4) return `${sign}${(a / 1e3).toFixed(0)}K`;
  if (a >= 1e3) return `${sign}${(a / 1e3).toFixed(1).replace(/\.0$/, '')}K`;
  return `${sign}${Math.round(a)}`;
}

export function localDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/** Days between two YYYY-MM-DD strings, inclusive. */
export function daysInRange(from, to) {
  const a = new Date(`${from}T00:00:00`), b = new Date(`${to}T00:00:00`);
  return Math.max(1, Math.round((b - a) / 86400000) + 1);
}

export function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  return (parts[0][0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
}

/** "5 min ago", "2 h ago", "3 d ago", else the date. */
export function timeAgo(iso, t = (k, f) => f) {
  const ms = Date.now() - new Date(iso).getTime();
  if (!Number.isFinite(ms)) return '';
  const m = Math.floor(ms / 60000);
  if (m < 1) return t('Just_now', 'Just now');
  if (m < 60) return `${m} ${t('min_ago', 'min ago')}`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} ${t('h_ago', 'h ago')}`;
  const d = Math.floor(h / 24);
  if (d < 8) return `${d} ${t('d_ago', 'd ago')}`;
  return localDate(new Date(iso));
}
