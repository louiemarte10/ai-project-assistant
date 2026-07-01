import { FormEvent, useEffect, useState } from 'react';
import Swal from 'sweetalert2';
import { Project } from '../api/client';
import { useUpdateProject } from '../api/hooks';

export default function EditProjectModal({ project, onClose }: { project: Project; onClose: () => void }) {
  const update = useUpdateProject();
  const [name, setName] = useState(project.project_name);
  const [repos, setRepos] = useState<string[]>(
    project.repository_url && project.repository_url.length ? [...project.repository_url] : [''],
  );

  useEffect(() => {
    const h = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);

  const setRepoAt = (i: number, v: string) => setRepos((r) => r.map((x, idx) => (idx === i ? v : x)));
  const addRepo = () => setRepos((r) => [...r, '']);
  const removeRepo = (i: number) => setRepos((r) => (r.length === 1 ? [''] : r.filter((_, idx) => idx !== i)));

  async function onSave(e: FormEvent) {
    e.preventDefault();
    if (!name.trim()) return;
    const urls = repos.map((u) => u.trim()).filter(Boolean);
    const res = await update.mutateAsync({ id: project.project_id, projectName: name.trim(), repositoryUrls: urls });
    const imp = res.github_import;
    const css = getComputedStyle(document.documentElement);
    Swal.fire({
      toast: true, position: 'top-end', timer: 3500, showConfirmButton: false, icon: 'success',
      title: 'Project updated',
      text: imp && imp.imported.length ? `Imported ${imp.imported.length} new file(s)` : '',
      background: css.getPropertyValue('--surface').trim() || '#1c2230',
      color: css.getPropertyValue('--text').trim() || '#e5e9f0',
    });
    onClose();
  }

  const inputClass = 'w-full rounded-lg border border-line bg-surface text-ink px-3 py-2 placeholder:text-muted';

  return (
    <div className="fixed inset-0 z-[100] bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-surface border border-line rounded-xl shadow-xl w-full max-w-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-4 py-3 border-b border-line">
          <h3 className="font-semibold text-ink">Edit project</h3>
          <button onClick={onClose} className="text-muted hover:text-ink text-lg leading-none" aria-label="Close">✕</button>
        </div>
        <form onSubmit={onSave} className="p-4 space-y-3">
          <label className="block">
            <span className="text-sm text-muted">Project name *</span>
            <input value={name} onChange={(e) => setName(e.target.value)} className={`mt-1 ${inputClass}`} />
          </label>
          <div>
            <span className="text-sm text-muted">Repository URLs <span className="opacity-70">(optional — new repos auto-import .md/.env)</span></span>
            <div className="mt-1 space-y-2">
              {repos.map((url, i) => (
                <div key={i} className="flex gap-2">
                  <input value={url} onChange={(e) => setRepoAt(i, e.target.value)} placeholder="https://github.com/org/repo" className={inputClass} />
                  <button type="button" onClick={() => removeRepo(i)} title="Remove" className="rounded-lg border border-line bg-surface2 text-muted px-3 shrink-0 hover:text-red-500">✕</button>
                </div>
              ))}
              <button type="button" onClick={addRepo} className="text-sm text-brand hover:underline">+ Add another repository</button>
            </div>
          </div>
          {update.error && <p className="text-red-500 text-sm">{(update.error as Error).message}</p>}
          <div className="flex gap-2 justify-end pt-1">
            <button type="button" onClick={onClose} className="rounded-lg bg-surface2 text-ink px-4 py-2 text-sm">Cancel</button>
            <button type="submit" disabled={update.isPending} className="rounded-lg bg-brand text-white px-5 py-2 text-sm disabled:opacity-50">
              {update.isPending ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
