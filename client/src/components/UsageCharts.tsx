// Lightweight dependency-free SVG charts (with hover tooltips) for the dashboard.
import { useRef, useState } from 'react';

const PALETTE = ['#3b82f6', '#22c55e', '#f59e0b', '#a855f7', '#ef4444'];

function fmtK(v: number) {
  if (v >= 1000) return `${(v / 1000).toFixed(2)}K`;
  return `${Math.round(v * 100) / 100}`;
}

function niceMax(v: number) {
  if (v <= 0) return 1;
  const pow = Math.pow(10, Math.floor(Math.log10(v)));
  const n = v / pow;
  const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
  return step * pow;
}

function xTicks(labels: string[]) {
  const n = labels.length;
  if (n <= 1) return [0];
  const count = Math.min(5, n);
  const idxs: number[] = [];
  for (let i = 0; i < count; i++) idxs.push(Math.round((i * (n - 1)) / (count - 1)));
  return Array.from(new Set(idxs));
}

const W = 600;
const H = 190;
const PADL = 44;
const PADR = 12;
const PADT = 12;
const PADB = 26;
const innerW = W - PADL - PADR;
const innerH = H - PADT - PADB;

function Grid({ max, fmtY }: { max: number; fmtY: (n: number) => string }) {
  const lines = [0, 0.25, 0.5, 0.75, 1];
  return (
    <g>
      {lines.map((f) => {
        const y = PADT + innerH * (1 - f);
        return (
          <g key={f}>
            <line x1={PADL} y1={y} x2={W - PADR} y2={y} stroke="currentColor" strokeOpacity={0.12} />
            <text x={PADL - 6} y={y + 3} textAnchor="end" fontSize="9" fill="currentColor" fillOpacity={0.55}>
              {fmtY(max * f)}
            </text>
          </g>
        );
      })}
    </g>
  );
}

function XLabels({ labels }: { labels: string[] }) {
  const n = labels.length;
  return (
    <g>
      {xTicks(labels).map((i) => {
        const x = PADL + (n <= 1 ? innerW / 2 : (i * innerW) / (n - 1));
        return (
          <text key={i} x={x} y={H - 8} textAnchor="middle" fontSize="9" fill="currentColor" fillOpacity={0.55}>
            {labels[i]}
          </text>
        );
      })}
    </g>
  );
}

function Legend({ items, hidden, onToggle }: { items: { name: string; color: string }[]; hidden?: Set<string>; onToggle?: (name: string) => void }) {
  return (
    <div className="flex flex-wrap gap-3 mt-2">
      {items.map((it) => {
        const off = hidden?.has(it.name);
        return (
          <button
            key={it.name}
            type="button"
            onClick={() => onToggle?.(it.name)}
            title={off ? 'Click to show' : 'Click to hide'}
            className={`flex items-center gap-1.5 text-xs ${off ? 'text-muted line-through opacity-50' : 'text-muted'} hover:text-ink`}
          >
            <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: off ? 'transparent' : it.color, border: `1px solid ${it.color}` }} />
            {it.name}
          </button>
        );
      })}
    </div>
  );
}

// Shared hover state: maps the mouse X to the nearest data-point index.
function useHoverIndex(n: number, mode: 'line' | 'bar' = 'line') {
  const ref = useRef<SVGSVGElement>(null);
  const [idx, setIdx] = useState<number | null>(null);
  function onMove(e: React.MouseEvent) {
    const svg = ref.current;
    if (!svg || n === 0) return;
    const rect = svg.getBoundingClientRect();
    const vbX = ((e.clientX - rect.left) / rect.width) * W;
    let i;
    if (mode === 'bar') {
      i = Math.floor((vbX - PADL) / (innerW / n));
    } else {
      const frac = n <= 1 ? 0 : (vbX - PADL) / innerW;
      i = Math.round(frac * (n - 1));
    }
    i = Math.max(0, Math.min(n - 1, i));
    setIdx(i);
  }
  return { ref, idx, onMove, clear: () => setIdx(null) };
}

function Tooltip({ leftPct, title, rows }: { leftPct: number; title: string; rows: { name: string; color: string; value: string }[] }) {
  const clamped = Math.max(6, Math.min(94, leftPct));
  return (
    <div
      className="pointer-events-none absolute z-10 rounded-lg border border-line bg-surface px-2.5 py-1.5 shadow-lg text-xs"
      style={{ left: `${clamped}%`, top: 6, transform: 'translateX(-50%)', whiteSpace: 'nowrap' }}
    >
      <div className="text-muted mb-0.5">{title}</div>
      {rows.map((r) => (
        <div key={r.name} className="flex items-center gap-1.5 text-ink">
          <span className="inline-block h-2 w-2 rounded-sm" style={{ background: r.color }} />
          <span className="text-muted">{r.name}</span>
          <span className="ml-auto font-medium tabular-nums">{r.value}</span>
        </div>
      ))}
    </div>
  );
}

export type ChartType = 'line' | 'bar';

export interface ChartSeries { name: string; values: number[] }

/** Unified chart: renders multi-series as either a line or a (grouped) bar chart. */
export function Chart({
  labels,
  full,
  series,
  type,
  fmtY = (n) => String(Math.round(n)),
}: {
  labels: string[];
  full?: string[];
  series: ChartSeries[];
  type: ChartType;
  fmtY?: (n: number) => string;
}) {
  const [hidden, setHidden] = useState<Set<string>>(new Set());
  const toggle = (name: string) => setHidden((prev) => {
    const next = new Set(prev);
    if (next.has(name)) next.delete(name); else next.add(name);
    return next;
  });
  // Keep each series' color stable by its original index, even when some are hidden.
  const colorOf = (i: number) => PALETTE[i % PALETTE.length];
  const visible = series.map((s, si) => ({ s, si })).filter(({ s }) => !hidden.has(s.name));

  const max = niceMax(Math.max(1, ...visible.flatMap(({ s }) => s.values)));
  const n = labels.length;
  const slot = innerW / Math.max(1, n);
  const lineX = (i: number) => PADL + (n <= 1 ? innerW / 2 : (i * innerW) / (n - 1));
  const slotCenter = (i: number) => PADL + (i + 0.5) * slot;
  const yAt = (v: number) => PADT + innerH * (1 - v / max);
  const { ref, idx, onMove, clear } = useHoverIndex(n, type === 'bar' ? 'bar' : 'line');
  const guideX = type === 'bar' ? slotCenter : lineX;

  // Grouped bars: each visible series gets a slice of the slot width.
  const groupW = slot * 0.7;
  const barW = groupW / Math.max(1, visible.length);

  return (
    <div className="text-muted relative">
      <svg ref={ref} viewBox={`0 0 ${W} ${H}`} className="w-full" style={{ height: 190 }} onMouseMove={onMove} onMouseLeave={clear}>
        <Grid max={max} fmtY={fmtY} />
        {type === 'line'
          ? visible.map(({ s, si }) => {
              const color = colorOf(si);
              const pts = s.values.map((v, i) => `${lineX(i)},${yAt(v)}`).join(' ');
              const last = s.values.length - 1;
              return (
                <g key={s.name}>
                  <polyline points={pts} fill="none" stroke={color} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
                  {last >= 0 && idx === null && <circle cx={lineX(last)} cy={yAt(s.values[last])} r={3} fill={color} />}
                </g>
              );
            })
          : visible.map(({ s, si }, vi) => {
              const color = colorOf(si);
              const gx = (i: number) => PADL + i * slot + (slot - groupW) / 2 + vi * barW;
              return (
                <g key={s.name}>
                  {s.values.map((v, i) => {
                    const h = innerH * (v / max);
                    return <rect key={i} x={gx(i)} y={PADT + innerH - h} width={Math.max(1, barW * 0.85)} height={h} rx={1} fill={color} fillOpacity={idx === i ? 1 : v > 0 ? 0.9 : 0.25} />;
                  })}
                </g>
              );
            })}
        {idx !== null && (
          <g>
            <line x1={guideX(idx)} y1={PADT} x2={guideX(idx)} y2={PADT + innerH} stroke="currentColor" strokeOpacity={0.4} strokeDasharray="3 3" />
            {type === 'line' && visible.map(({ s, si }) => (
              <circle key={s.name} cx={lineX(idx)} cy={yAt(s.values[idx])} r={3.5} fill={colorOf(si)} stroke="var(--surface)" strokeWidth={1.5} />
            ))}
          </g>
        )}
        <XLabels labels={labels} />
        {/* transparent capture rect so hover works over empty areas */}
        <rect x={PADL} y={PADT} width={innerW} height={innerH} fill="transparent" />
      </svg>
      {idx !== null && visible.length > 0 && (
        <Tooltip
          leftPct={(guideX(idx) / W) * 100}
          title={(full && full[idx]) || labels[idx]}
          rows={visible.map(({ s, si }) => ({ name: s.name, color: colorOf(si), value: fmtK(s.values[idx]) }))}
        />
      )}
      <Legend items={series.map((s, si) => ({ name: s.name, color: colorOf(si) }))} hidden={hidden} onToggle={toggle} />
    </div>
  );
}
