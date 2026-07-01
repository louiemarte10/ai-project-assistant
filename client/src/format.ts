/**
 * Server timestamps are stored in UTC ("YYYY-MM-DD HH:MM:SS"). Render them in
 * UTC+8 (Asia/Manila) as e.g. "June 23, 2026 14:22:23".
 */
export function formatPHT(ts?: string | null): string {
  if (!ts) return '';
  const iso = ts.includes('T') ? ts : ts.replace(' ', 'T');
  const d = new Date(iso.endsWith('Z') ? iso : iso + 'Z'); // interpret as UTC
  if (isNaN(d.getTime())) return ts;
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    month: 'long', day: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  }).formatToParts(d);
  const get = (t: string) => parts.find((p) => p.type === t)?.value || '';
  return `${get('month')} ${get('day')}, ${get('year')} ${get('hour')}:${get('minute')}:${get('second')}`;
}
