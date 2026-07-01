import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { ProjectMetadata } from '../api/client';
import { useGenerateSummary } from '../api/hooks';
import { formatPHT } from '../format';

const PENDING = 'pending';

export default function SummaryDashboard({
  projectId,
  metadata,
}: {
  projectId: number;
  metadata: ProjectMetadata | null;
}) {
  const generate = useGenerateSummary(projectId);

  const hasSummary = metadata && metadata.tech_stack !== PENDING;
  // Persisted overview (survives refresh) — comes from project_metadata.overview.
  const overview = metadata?.overview || '';

  async function onGenerate() {
    await generate.mutateAsync();
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="font-semibold text-lg text-ink">AI Technical Summary</h3>
          {hasSummary && metadata?.generated_at && (
            <p className="text-xs text-muted mt-0.5">Generated {formatPHT(metadata.generated_at)} (UTC+8)</p>
          )}
        </div>
        <button
          onClick={onGenerate}
          disabled={generate.isPending}
          className="rounded-lg bg-brand text-white px-4 py-2 text-sm disabled:opacity-50"
        >
          {generate.isPending ? 'Analyzing…' : hasSummary ? 'Regenerate' : 'Generate summary'}
        </button>
      </div>

      {generate.error && <p className="text-red-500 text-sm">{(generate.error as Error).message}</p>}

      {!hasSummary && !generate.isPending && (
        <p className="text-muted text-sm">
          No summary yet. Upload documents, then click <em>Generate summary</em>.
        </p>
      )}

      {hasSummary && (
        <div className="grid gap-3 sm:grid-cols-3">
          <Card title="Functional Purpose">{metadata!.functional_purpose}</Card>
          <Card title="Server / Environment">{metadata!.server_location}</Card>
          <Card title="Tech Stack">
            <div className="flex flex-wrap gap-1.5">
              {metadata!.tech_stack.split(',').map((t, i) => (
                <span key={i} className="rounded-full bg-surface2 px-2.5 py-0.5 text-xs">
                  {t.trim()}
                </span>
              ))}
            </div>
          </Card>
        </div>
      )}

      {overview && (
        <div className="bg-surface rounded-xl border border-line p-4 markdown text-ink">
          <ReactMarkdown remarkPlugins={[remarkGfm]}>{overview}</ReactMarkdown>
        </div>
      )}
    </div>
  );
}

function Card({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="bg-surface rounded-xl border border-line p-4">
      <div className="text-xs uppercase tracking-wide text-muted mb-1">{title}</div>
      <div className="text-sm text-ink">{children}</div>
    </div>
  );
}
