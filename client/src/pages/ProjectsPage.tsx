import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import Swal from 'sweetalert2';
import { useCreateProject, useDeleteProject, useProjects } from '../api/hooks';
import { formatPHT } from '../format';
import { Project } from '../api/client';
import EditProjectModal from '../components/EditProjectModal';

export default function ProjectsPage() {
  const { data: projects, isLoading, error } = useProjects();
  const createProject = useCreateProject();
  const deleteProject = useDeleteProject();

  const [name, setName] = useState('');
  const [repos, setRepos] = useState<string[]>(['']);
  const [editProject, setEditProject] = useState<Project | null>(null);

  function setRepoAt(i: number, val: string) {
    setRepos((r) => r.map((v, idx) => (idx === i ? val : v)));
  }
  function addRepo() { setRepos((r) => [...r, '']); }
  function removeRepo(i: number) { setRepos((r) => (r.length === 1 ? [''] : r.filter((_, idx) => idx !== i))); }

  async function onCreate(e: FormEvent) {
    e.preventDefault();
    if (!name.trim()) return;
    const urls = repos.map((u) => u.trim()).filter(Boolean);
    const res = await createProject.mutateAsync({ projectName: name.trim(), repositoryUrls: urls });
    setName('');
    setRepos(['']);

    const imp = res.github_import;
    if (imp) {
      const css = getComputedStyle(document.documentElement);
      const n = imp.imported.length;
      Swal.fire({
        toast: true, position: 'top-end', timer: 4000, showConfirmButton: false,
        icon: n > 0 ? 'success' : (imp.errors.length ? 'warning' : 'info'),
        title: n > 0 ? `Imported ${n} file(s) from GitHub` : 'No files imported',
        text: imp.errors.length ? imp.errors.join(' · ') : '',
        background: css.getPropertyValue('--surface').trim() || '#1c2230',
        color: css.getPropertyValue('--text').trim() || '#e5e9f0',
      });
    }
  }

  const inputClass = 'w-full rounded-lg border border-line bg-surface text-ink px-3 py-2 placeholder:text-muted';

  return (
    <div className="space-y-6">
      <section className="bg-surface rounded-xl shadow-sm border border-line p-5">
        <h2 className="font-semibold text-lg mb-3 text-ink">Add Project</h2>
        <form onSubmit={onCreate} className="space-y-3 max-w-2xl">
          <label className="block">
            <span className="text-sm text-muted">Project name *</span>
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="DataHub Pro" className={`mt-1 ${inputClass}`} />
          </label>

          <div>
            <span className="text-sm text-muted">Repository URLs <span className="opacity-70">(GitHub — backend, frontend, … auto-imports .md/.env)</span></span>
            <div className="mt-1 space-y-2">
              {repos.map((url, i) => (
                <div key={i} className="flex gap-2">
                  <input
                    value={url}
                    onChange={(e) => setRepoAt(i, e.target.value)}
                    placeholder="https://github.com/org/repo"
                    className={inputClass}
                  />
                  <button
                    type="button"
                    onClick={() => removeRepo(i)}
                    title="Remove"
                    className="rounded-lg border border-line bg-surface2 text-muted px-3 shrink-0 hover:text-red-500"
                  >
                    ✕
                  </button>
                </div>
              ))}
              <button type="button" onClick={addRepo} className="text-sm text-brand hover:underline">
                + Add another repository
              </button>
            </div>
          </div>

          <button
            type="submit"
            disabled={createProject.isPending}
            className="rounded-lg bg-brand text-white px-5 py-2 disabled:opacity-50"
          >
            {createProject.isPending ? 'Creating…' : 'Create'}
          </button>
        </form>
        {createProject.error && (
          <p className="text-red-500 text-sm mt-2">{(createProject.error as Error).message}</p>
        )}
      </section>

      <section>
        <h2 className="font-semibold text-lg mb-3 text-ink">Projects</h2>
        {isLoading && <p className="text-muted">Loading…</p>}
        {error && <p className="text-red-500">{(error as Error).message}</p>}
        {projects && projects.length === 0 && (
          <p className="text-muted">No projects yet. Create one above to get started.</p>
        )}
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {projects?.map((p) => (
            <li key={p.project_id} className="bg-surface rounded-xl shadow-sm border border-line p-4 flex flex-col">
              <Link to={`/projects/${p.project_id}`} className="font-medium text-ink hover:underline">
                {p.project_name}
              </Link>
              {(p.repository_url || []).map((u) => (
                <a key={u} href={u} target="_blank" rel="noreferrer" className="text-xs text-brand truncate">
                  {u}
                </a>
              ))}
              <span className="text-xs text-muted mt-1">{formatPHT(p.created_at)}</span>
              <div className="mt-3 flex gap-2">
                <Link
                  to={`/projects/${p.project_id}`}
                  className="text-sm rounded-lg bg-surface2 text-ink px-3 py-1.5 hover:opacity-80"
                >
                  Open
                </Link>
                <button
                  onClick={() => setEditProject(p)}
                  className="text-sm rounded-lg bg-surface2 text-ink px-3 py-1.5 hover:opacity-80"
                >
                  Edit
                </button>
                <button
                  onClick={async () => {
                    const css = getComputedStyle(document.documentElement);
                    const bg = css.getPropertyValue('--surface').trim() || '#1c2230';
                    const fg = css.getPropertyValue('--text').trim() || '#e5e9f0';
                    const res = await Swal.fire({
                      title: 'Delete project?',
                      text: `"${p.project_name}" and all its data (documents, chat, and summary) will be permanently removed.`,
                      icon: 'warning',
                      showCancelButton: true,
                      confirmButtonText: 'Delete',
                      cancelButtonText: 'Cancel',
                      confirmButtonColor: '#dc2626',
                      background: bg,
                      color: fg,
                    });
                    if (res.isConfirmed) {
                      await deleteProject.mutateAsync(p.project_id);
                      Swal.fire({
                        toast: true, position: 'top-end', timer: 1500, showConfirmButton: false,
                        icon: 'success', title: 'Project deleted', background: bg, color: fg,
                      });
                    }
                  }}
                  className="text-sm rounded-lg px-3 py-1.5 text-red-500 hover:bg-red-500/10"
                >
                  Delete
                </button>
              </div>
            </li>
          ))}
        </ul>
      </section>

      {editProject && (
        <EditProjectModal project={editProject} onClose={() => setEditProject(null)} />
      )}
    </div>
  );
}
