import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useDocuments, useProject } from '../api/hooks';
import SummaryDashboard from '../components/SummaryDashboard';
import ChatPanel from '../components/ChatPanel';
import FileUpload from '../components/FileUpload';
import MarkdownExport from '../components/MarkdownExport';

type Tab = 'summary' | 'chat' | 'export';

export default function ProjectWorkspace() {
  const { id } = useParams();
  const projectId = Number(id);
  const { data, isLoading, error } = useProject(projectId);
  const { data: documents } = useDocuments(projectId);
  const [tab, setTab] = useState<Tab>('summary');

  const hasDocs = (documents?.length ?? 0) > 0;

  // If the chat tab is open but the project has no documents (e.g. they were all
  // deleted), fall back to Summary so the user isn't stuck on a disabled tab.
  useEffect(() => {
    if (tab === 'chat' && !hasDocs) setTab('summary');
  }, [tab, hasDocs]);

  if (isLoading) return <p className="text-muted">Loading project…</p>;
  if (error) return <p className="text-red-500">{(error as Error).message}</p>;
  if (!data) return null;

  const { project, metadata } = data;

  const tabs: { key: Tab; label: string; disabled?: boolean }[] = [
    { key: 'summary', label: 'Summary' },
    { key: 'chat', label: 'Chat', disabled: !hasDocs },
    { key: 'export', label: 'Export' },
  ];

  return (
    <div className="space-y-5">
      <div>
        <Link to="/projects" className="text-sm text-muted hover:underline">← All projects</Link>
        <h1 className="text-2xl font-semibold mt-1 text-ink">{project.project_name}</h1>
        <div className="flex flex-col">
          {(project.repository_url || []).map((u) => (
            <a key={u} href={u} target="_blank" rel="noreferrer" className="text-sm text-brand">
              {u}
            </a>
          ))}
        </div>
      </div>

      <div className="grid gap-5 lg:grid-cols-[320px_1fr]">
        <aside>
          <FileUpload projectId={projectId} />
        </aside>

        <section className="space-y-4">
          <nav className="flex gap-1 border-b border-line">
            {tabs.map((t) => (
              <button
                key={t.key}
                onClick={() => !t.disabled && setTab(t.key)}
                disabled={t.disabled}
                title={t.disabled ? 'Add a document or repository to enable chat' : undefined}
                className={`px-4 py-2 text-sm border-b-2 -mb-px ${
                  tab === t.key
                    ? 'border-brand font-medium text-ink'
                    : 'border-transparent text-muted hover:text-ink'
                } ${t.disabled ? 'opacity-40 cursor-not-allowed hover:text-muted' : ''}`}
              >
                {t.label}
              </button>
            ))}
          </nav>

          {tab === 'summary' && <SummaryDashboard projectId={projectId} metadata={metadata} />}
          {tab === 'chat' && (hasDocs
            ? <ChatPanel projectId={projectId} />
            : <NoDocsNote />)}
          {tab === 'export' && <MarkdownExport projectId={projectId} projectName={project.project_name} />}
        </section>
      </div>
    </div>
  );
}

function NoDocsNote() {
  return (
    <div className="bg-surface border border-line rounded-xl p-6 text-center">
      <p className="text-ink font-medium mb-1">Chat isn't available yet</p>
      <p className="text-muted text-sm">
        This project has no documents for the assistant to reference. Please upload at least one
        project document (.md, .txt, code, .pdf, .docx, .xlsx, .csv, or an image), or add a GitHub
        repository to the project so its files are imported. Once a document is added, the chat will
        be enabled.
      </p>
    </div>
  );
}
