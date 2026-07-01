import { api } from '../api/client';

export default function MarkdownExport({ projectId, projectName }: { projectId: number; projectName: string }) {
  const url = api.exportUrl(projectId);

  return (
    <div className="bg-surface rounded-xl shadow-sm border border-line p-5 space-y-3">
      <h3 className="font-semibold text-ink">Export README</h3>
      <p className="text-sm text-muted">
        Download a ready-to-use <code>README.md</code> built from this project's summary and
        document list — drop it straight into the repo.
      </p>
      <a
        href={url}
        download={`${projectName}-README.md`}
        className="inline-block rounded-lg bg-brand text-white px-4 py-2 text-sm"
      >
        ⬇ Download README.md
      </a>
    </div>
  );
}
