import { ReactNode, useState } from 'react';
import Swal from 'sweetalert2';
import { useApiKey, useBackfillUsage, useDeleteApiKey, useProjects, useResetUsage, useSaveApiKey, useSetKeyModel, useUsage } from '../api/hooks';
import { UsageBucket } from '../api/client';
import { Chart, ChartSeries, ChartType } from '../components/UsageCharts';

const fmtUsd = (n: number) => `$${n.toFixed(2)}`;
const fmtNum = (n: number) => n.toLocaleString();
const RANGES = [
  { key: '1h', label: 'Last 1 hour' },
  { key: '5h', label: 'Last 5 hours' },
  { key: '1d', label: 'Last 1 day' },
  { key: '7d', label: '7 Days' },
  { key: '28d', label: '28 Days' },
  { key: '90d', label: '90 Days' },
];

function Card({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <section className="bg-surface rounded-xl border border-line p-5">
      <div className="flex items-center justify-between gap-2 mb-3">
        <h2 className="font-semibold text-ink text-sm">{title}</h2>
        {action}
      </div>
      {children}
    </section>
  );
}

function ChartTypeButton({ type, onToggle }: { type: ChartType; onToggle: () => void }) {
  const other = type === 'line' ? 'bar' : 'line';
  return (
    <button
      type="button"
      onClick={onToggle}
      title={`Switch to ${other} chart`}
      className="rounded-lg border border-line p-1.5 text-muted hover:text-ink hover:bg-surface2"
    >
      {other === 'bar' ? (
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5">
          <rect x="2" y="8" width="3" height="6" /><rect x="6.5" y="4" width="3" height="10" /><rect x="11" y="6" width="3" height="8" />
        </svg>
      ) : (
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5">
          <polyline points="2,12 6,7 9,9 14,3" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      )}
    </button>
  );
}

function ChartCard({
  title, labels, full, series, defaultType = 'line', fmtY, hasData, emptyText,
}: {
  title: string;
  labels: string[];
  full: string[];
  series: ChartSeries[];
  defaultType?: ChartType;
  fmtY?: (n: number) => string;
  hasData: boolean;
  emptyText: string;
}) {
  const [type, setType] = useState<ChartType>(defaultType);
  return (
    <Card title={title} action={hasData ? <ChartTypeButton type={type} onToggle={() => setType((t) => (t === 'line' ? 'bar' : 'line'))} /> : undefined}>
      {hasData
        ? <Chart labels={labels} full={full} series={series} type={type} fmtY={fmtY} />
        : <p className="text-muted text-sm py-8 text-center">{emptyText}</p>}
    </Card>
  );
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="bg-surface rounded-xl border border-line p-4">
      <div className="text-xs text-muted">{label}</div>
      <div className="text-2xl font-semibold text-ink mt-1">{value}</div>
      {sub && <div className="text-xs text-muted mt-1">{sub}</div>}
    </div>
  );
}

function TokenBreakdown({ b }: { b: UsageBucket }) {
  const rows: [string, number][] = [
    ['Input tokens', b.input_tokens],
    ['Output tokens', b.output_tokens],
    ['Cache write tokens', b.cache_write_tokens],
    ['Cache read tokens', b.cache_read_tokens],
  ];
  return (
    <table className="w-full text-sm">
      <tbody>
        {rows.map(([k, v]) => (
          <tr key={k} className="border-b border-line last:border-0">
            <td className="py-1.5 text-muted">{k}</td>
            <td className="py-1.5 text-right text-ink tabular-nums">{fmtNum(v)}</td>
          </tr>
        ))}
        <tr className="font-medium">
          <td className="py-1.5 text-ink">Total</td>
          <td className="py-1.5 text-right text-ink tabular-nums">{fmtNum(b.total_tokens)}</td>
        </tr>
      </tbody>
    </table>
  );
}

export default function DashboardPage() {
  const [range, setRange] = useState('28d');
  const [projectId, setProjectId] = useState(0);
  const [userId, setUserId] = useState(0); // admin: filter by a specific user (0 = all)
  const [breakdown, setBreakdown] = useState('model'); // chart grouping: model | user | project
  const { data: u, isLoading, error } = useUsage(range, projectId, userId, breakdown);
  const { data: projects } = useProjects();
  const { data: keyInfo } = useApiKey(true); // admin gets all users' keys in .all
  const reset = useResetUsage();
  const backfill = useBackfillUsage();
  const saveKey = useSaveApiKey();
  const delKey = useDeleteApiKey();
  const setKeyModelMut = useSetKeyModel();
  const [newKey, setNewKey] = useState('');
  const [newModel, setNewModel] = useState('gemini-2.5-flash-lite');

  // Distinct users (for the admin's user filter), from the API-keys list.
  const userOptions = (() => {
    const seen: Record<number, string> = {};
    (keyInfo?.all || []).forEach((k) => { if (!seen[k.user_id]) seen[k.user_id] = k.name; });
    return Object.keys(seen).map((id) => ({ user_id: Number(id), name: seen[Number(id)] }));
  })();

  const scopeName = projectId === 0
    ? 'All projects'
    : (projects?.find((p) => p.project_id === projectId)?.project_name || `Project #${projectId}`);

  async function onReset() {
    const css = getComputedStyle(document.documentElement);
    const r = await Swal.fire({
      title: 'Reset usage counters?',
      text: 'The dashboard will start counting from now. Stored history is kept but no longer shown.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Reset',
      confirmButtonColor: '#dc2626',
      background: css.getPropertyValue('--surface').trim() || '#1c2230',
      color: css.getPropertyValue('--text').trim() || '#e5e9f0',
    });
    if (r.isConfirmed) {
      await reset.mutateAsync();
      Swal.fire({
        toast: true, position: 'top-end', timer: 1500, showConfirmButton: false, icon: 'success', title: 'Usage reset',
        background: css.getPropertyValue('--surface').trim() || '#1c2230',
        color: css.getPropertyValue('--text').trim() || '#e5e9f0',
      });
    }
  }

  if (isLoading) return <p className="text-muted">Loading usage…</p>;
  if (error) return <p className="text-red-500">{(error as Error).message}</p>;
  if (!u) return null;

  const providerName = u.provider === 'gemini' ? 'Gemini' : u.provider === 'claude' ? 'Claude' : u.provider;
  const pct = Math.min(100, u.budget.percent);
  const barColor = u.budget.locked ? 'bg-red-500' : pct >= 60 ? 'bg-amber-500' : 'bg-emerald-500';

  const seriesKeys = Object.keys(u.series.by);
  const inputSeries = seriesKeys.map((m) => ({ name: m, values: u.series.by[m].input }));
  const outputSeries = seriesKeys.map((m) => ({ name: m, values: u.series.by[m].output }));
  const reqSeries = seriesKeys.map((m) => ({ name: m, values: u.series.by[m].requests }));
  const totalReq = u.series.requests.reduce((a, b) => a + b, 0);
  const bkLabel = breakdown === 'user' ? 'user' : breakdown === 'project' ? 'project' : 'model';
  const errorTypes = Object.keys(u.errors.by_type);
  const errorSeries = errorTypes.map((t) => ({ name: t, values: u.errors.by_type[t] }));
  const rangeLabel = RANGES.find((r) => r.key === range)?.label || range;
  const rt = u.range_totals;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <h1 className="text-2xl font-semibold text-ink">{providerName} API Usage</h1>
          <span className={`text-xs font-medium px-2.5 py-0.5 rounded-full ${u.provider === 'gemini' ? 'bg-emerald-500/15 text-emerald-500' : 'bg-amber-500/15 text-amber-500'}`}>
            ● {u.tier}
          </span>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {u.is_admin && userOptions.length > 0 && (
            <label className="text-sm text-muted flex items-center gap-2">
              User
              <select
                value={userId}
                onChange={(e) => setUserId(Number(e.target.value))}
                className="rounded-lg border border-line bg-surface2 text-ink text-sm px-2 py-1 max-w-[200px]"
              >
                <option value={0}>All users</option>
                {userOptions.map((o) => <option key={o.user_id} value={o.user_id}>{o.name}</option>)}
              </select>
            </label>
          )}
          <label className="text-sm text-muted flex items-center gap-2">
            Project
            <select
              value={projectId}
              onChange={(e) => setProjectId(Number(e.target.value))}
              className="rounded-lg border border-line bg-surface2 text-ink text-sm px-2 py-1 max-w-[220px]"
            >
              <option value={0}>All projects</option>
              {projects?.map((p) => <option key={p.project_id} value={p.project_id}>{p.project_name}</option>)}
            </select>
          </label>
          <label className="text-sm text-muted flex items-center gap-2">
            Time Range
            <select
              value={range}
              onChange={(e) => setRange(e.target.value)}
              className="rounded-lg border border-line bg-surface2 text-ink text-sm px-2 py-1"
            >
              {RANGES.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
            </select>
          </label>
          {u.is_admin && (
            <button
              onClick={async () => {
                const r = await backfill.mutateAsync();
                const css = getComputedStyle(document.documentElement);
                Swal.fire({
                  toast: true, position: 'top-end', timer: 2500, showConfirmButton: false, icon: 'success',
                  title: `Re-imported ${r.imported} usage rows`,
                  background: css.getPropertyValue('--surface').trim() || '#1c2230',
                  color: css.getPropertyValue('--text').trim() || '#e5e9f0',
                });
              }}
              disabled={backfill.isPending}
              title="Rebuild the usage ledger from the database so it's attributed per user"
              className="text-sm rounded-lg border border-line text-ink px-3 py-1.5 hover:bg-surface2 disabled:opacity-50"
            >
              {backfill.isPending ? 'Re-importing…' : '⟲ Re-import usage'}
            </button>
          )}
          <button onClick={onReset} disabled={reset.isPending} className="text-sm rounded-lg border border-line text-red-500 px-3 py-1.5 hover:bg-red-500/10 disabled:opacity-50">
            ↺ Reset usage
          </button>
        </div>
      </div>
      <p className="text-sm text-muted -mt-3">
        Model <span className="font-mono">{u.model}</span> · showing <span className="text-ink font-medium">{scopeName}</span>
        {' · '}<span className="text-ink font-medium">{u.is_admin ? (userId > 0 ? (userOptions.find((o) => o.user_id === userId)?.name || `User ${userId}`) : 'all users') : 'your usage'}</span>
        {u.reset_at && <> · counting since <span className="font-mono">{u.reset_at}</span></>}
      </p>

      {/* Budget */}
      <Card title={`Monthly budget — ${u.month.label}`} action={
        <span className={`text-xs font-medium px-2.5 py-0.5 rounded-full ${u.budget.locked ? 'bg-red-500/15 text-red-500' : 'bg-emerald-500/15 text-emerald-500'}`}>
          {u.budget.locked ? 'Chat locked' : 'Active'}
        </span>
      }>
        <div className="flex items-end justify-between text-sm mb-1">
          <span className="text-ink font-medium">{fmtUsd(u.budget.spent_usd)} <span className="text-muted">/ {fmtUsd(u.budget.monthly_usd)}</span></span>
          <span className="text-muted">{u.budget.percent}% used · locks at {u.budget.lock_pct}%</span>
        </div>
        <div className="h-3 w-full rounded-full bg-surface2 overflow-hidden">
          <div className={`h-full ${barColor} transition-all`} style={{ width: `${pct}%` }} />
        </div>
        <p className="text-xs text-muted mt-2">
          {u.provider === 'gemini'
            ? 'On the Gemini free tier this is effectively $0 — the figure is the paid-overage estimate if you exceed the free quota.'
            : u.budget.locked
              ? `Chat is disabled until next month or the budget is raised in the server config.`
              : `${fmtUsd(u.budget.remaining_usd)} remaining this month. Chat auto-disables at ${u.budget.lock_pct}% of budget.`}
        </p>
      </Card>

      {/* Your API keys (add multiple for fallback; pick the model) */}
      <Card title="Your API keys">
        <p className="text-xs text-muted mb-3">
          Add one or more Gemini keys — if one hits its daily limit, the next is used automatically.
          Pick the model per key (Flash-Lite has a much larger free quota).
        </p>
        <div className="space-y-2 mb-4">
          {(keyInfo?.mine || []).map((k) => (
            <div key={k.id} className="flex items-center gap-3 text-sm border-b border-line pb-2 last:border-0">
              <span className="font-mono text-xs text-ink">{k.key_masked}</span>
              <select
                value={k.ai_model}
                onChange={(e) => setKeyModelMut.mutate({ id: k.id, ai_model: e.target.value })}
                className="rounded border border-line bg-surface2 text-ink text-xs px-1.5 py-0.5"
              >
                <option value="gemini-2.5-flash-lite">2.5 Flash-Lite</option>
                <option value="gemini-2.5-flash">2.5 Flash</option>
              </select>
              <span className="text-muted text-xs">added {k.create_date}</span>
              <button
                onClick={() => delKey.mutate(k.id)}
                disabled={delKey.isPending}
                className="ml-auto text-red-500 text-xs hover:underline disabled:opacity-50"
              >
                Remove
              </button>
            </div>
          ))}
          {(keyInfo?.mine || []).length === 0 && <p className="text-muted text-sm">No keys yet — add one below.</p>}
        </div>
        <div className="flex flex-wrap gap-2 items-center">
          <input
            value={newKey}
            onChange={(e) => setNewKey(e.target.value)}
            type="password"
            placeholder="Paste a Gemini API key"
            className="flex-1 min-w-[200px] rounded-lg border border-line bg-surface2 text-ink px-3 py-2 text-sm placeholder:text-muted"
          />
          <select value={newModel} onChange={(e) => setNewModel(e.target.value)} className="rounded-lg border border-line bg-surface2 text-ink text-sm px-2 py-2">
            <option value="gemini-2.5-flash-lite">2.5 Flash-Lite</option>
            <option value="gemini-2.5-flash">2.5 Flash</option>
          </select>
          <button
            onClick={async () => { try { await saveKey.mutateAsync({ api_key: newKey.trim(), ai_model: newModel }); setNewKey(''); } catch { /* shown below */ } }}
            disabled={saveKey.isPending || !newKey.trim()}
            className="rounded-lg bg-brand text-white px-4 py-2 text-sm disabled:opacity-50"
          >
            {saveKey.isPending ? 'Validating…' : 'Add key'}
          </button>
        </div>
        {saveKey.error && <p className="text-red-500 text-sm mt-2">{(saveKey.error as Error).message}</p>}
      </Card>

      {/* Admin only: every user's API key */}
      {u.is_admin && keyInfo?.all && (
        <Card title="API keys (all users)">
          {keyInfo.all.length === 0 ? (
            <p className="text-muted text-sm py-4 text-center">No user API keys yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-muted text-xs border-b border-line">
                    <th className="text-left font-medium py-2">User</th>
                    <th className="text-left font-medium py-2">Model</th>
                    <th className="text-left font-medium py-2">API key</th>
                    <th className="text-left font-medium py-2">Added</th>
                    <th className="text-left font-medium py-2">Expires</th>
                    <th className="text-left font-medium py-2">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {keyInfo.all.map((k) => (
                    <tr key={k.id} className="border-b border-line last:border-0">
                      <td className="py-2 text-ink">{k.name}</td>
                      <td className="py-2 text-muted font-mono text-xs">{k.ai_model}</td>
                      <td className="py-2 text-muted font-mono text-xs">{k.key_masked}</td>
                      <td className="py-2 text-muted text-xs">{k.create_date}</td>
                      <td className="py-2 text-muted text-xs">{k.expiration || '—'}</td>
                      <td className="py-2">
                        <span className={`text-xs px-2 py-0.5 rounded-full ${k.active ? 'bg-emerald-500/15 text-emerald-500' : 'bg-surface2 text-muted'}`}>
                          {k.active ? 'active' : 'deleted'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {/* Overview: requests over time */}
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard title="Total API Requests" defaultType="bar" hasData={totalReq > 0} emptyText="No requests in this range yet."
          labels={u.series.labels} full={u.series.full} series={[{ name: 'Requests', values: u.series.requests }]} />
        <ChartCard title="Total API Errors" defaultType="line" hasData={u.errors.total > 0} emptyText="No errors in this range. 🎉"
          labels={u.errors.labels} full={u.errors.full} series={errorSeries} />
      </div>

      {/* Token/request charts — choose how to break the lines down */}
      <div className="flex items-center justify-between gap-2 -mb-1">
        <h2 className="font-semibold text-ink">Token &amp; request charts</h2>
        <div className="flex items-center gap-1 rounded-lg border border-line p-0.5 bg-surface">
          {[['model', 'By model'], ['user', 'By user'], ['project', 'By project']].map(([key, label]) => (
            <button
              key={key}
              onClick={() => setBreakdown(key)}
              className={`text-xs px-3 py-1 rounded-md ${breakdown === key ? 'bg-brand text-white' : 'text-muted hover:text-ink'}`}
            >
              {label}
            </button>
          ))}
        </div>
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard title={`Input Tokens per ${bkLabel}`} hasData={seriesKeys.length > 0} emptyText="No data available." fmtY={fmtNum}
          labels={u.series.labels} full={u.series.full} series={inputSeries} />
        <ChartCard title={`Output Tokens per ${bkLabel}`} hasData={seriesKeys.length > 0} emptyText="No data available." fmtY={fmtNum}
          labels={u.series.labels} full={u.series.full} series={outputSeries} />
        <ChartCard title={`Requests per ${bkLabel}`} hasData={seriesKeys.length > 0} emptyText="No data available."
          labels={u.series.labels} full={u.series.full} series={reqSeries} />
      </div>

      {/* Totals — reflect the selected Time Range */}
      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label={`Estimated cost · ${rangeLabel}`} value={fmtUsd(rt.cost_usd)} sub={`${fmtNum(rt.messages)} AI calls`} />
        <Stat label={`Total tokens · ${rangeLabel}`} value={fmtNum(rt.total_tokens)} sub={`${fmtNum(rt.input_tokens)} in · ${fmtNum(rt.output_tokens)} out`} />
        <Stat label="Since reset — est. cost" value={fmtUsd(u.all_time.cost_usd)} sub={`${fmtNum(u.all_time.total_tokens)} tokens · ${fmtNum(u.all_time.messages)} calls`} />
      </div>

      {/* Usage by project */}
      <Card title={`Usage by project · ${rangeLabel}`}>
        {u.by_project.length === 0 ? (
          <p className="text-muted text-sm py-4 text-center">No usage recorded yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-muted text-xs border-b border-line">
                  <th className="text-left font-medium py-2">Project</th>
                  <th className="text-right font-medium py-2">AI calls</th>
                  <th className="text-right font-medium py-2">Input</th>
                  <th className="text-right font-medium py-2">Output</th>
                  <th className="text-right font-medium py-2">Total tokens</th>
                  <th className="text-right font-medium py-2">Est. cost</th>
                </tr>
              </thead>
              <tbody>
                {u.by_project.map((p) => (
                  <tr
                    key={p.project_id}
                    onClick={() => setProjectId(projectId === p.project_id ? 0 : p.project_id)}
                    className={`border-b border-line last:border-0 cursor-pointer hover:bg-surface2 ${projectId === p.project_id ? 'bg-surface2' : ''}`}
                    title="Click to filter the dashboard by this project"
                  >
                    <td className="py-2 text-ink">{p.project_name}</td>
                    <td className="py-2 text-right text-ink tabular-nums">{fmtNum(p.messages)}</td>
                    <td className="py-2 text-right text-muted tabular-nums">{fmtNum(p.input_tokens)}</td>
                    <td className="py-2 text-right text-muted tabular-nums">{fmtNum(p.output_tokens)}</td>
                    <td className="py-2 text-right text-ink tabular-nums">{fmtNum(p.total_tokens)}</td>
                    <td className="py-2 text-right text-ink tabular-nums">{fmtUsd(p.cost_usd)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* Breakdown + pricing */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={`Token breakdown · ${rangeLabel}`}><TokenBreakdown b={rt} /></Card>
        <Card title="Pricing used for estimates (USD / 1M tokens)">
          <div className="grid gap-3 sm:grid-cols-4 text-sm">
            <div><div className="text-muted text-xs">Input</div><div className="text-ink">{fmtUsd(u.pricing.input_per_mtok)}</div></div>
            <div><div className="text-muted text-xs">Output</div><div className="text-ink">{fmtUsd(u.pricing.output_per_mtok)}</div></div>
            <div><div className="text-muted text-xs">Cache write</div><div className="text-ink">{fmtUsd(u.pricing.cache_write_per_mtok)}</div></div>
            <div><div className="text-muted text-xs">Cache read</div><div className="text-ink">{fmtUsd(u.pricing.cache_read_per_mtok)}</div></div>
          </div>
          <p className="text-xs text-muted mt-3">
            Estimated from stored token counts — APIs don't expose the real account balance. Edit rates in <span className="font-mono">api/config.php</span>.
          </p>
        </Card>
      </div>
    </div>
  );
}
