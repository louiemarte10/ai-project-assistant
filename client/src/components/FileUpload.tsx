import { useRef, useState } from 'react';
import Swal from 'sweetalert2';
import { useDeleteDocument, useDocuments, useUploadDocuments } from '../api/hooks';
import { formatPHT } from '../format';
import { ProjectDocument } from '../api/client';
import DocumentModal from './DocumentModal';

// SweetAlert themed to the app's CSS variables (works across light/dark/blue).
function themedSwal() {
  const css = getComputedStyle(document.documentElement);
  return Swal.mixin({
    background: css.getPropertyValue('--surface').trim() || '#1c2230',
    color: css.getPropertyValue('--text').trim() || '#e5e9f0',
    confirmButtonColor: '#dc2626',
    cancelButtonColor: css.getPropertyValue('--border').trim() || '#475569',
  });
}

export default function FileUpload({ projectId }: { projectId: number }) {
  const { data: documents } = useDocuments(projectId);
  const upload = useUploadDocuments(projectId);
  const del = useDeleteDocument(projectId);
  const inputRef = useRef<HTMLInputElement>(null);
  const [result, setResult] = useState<{ saved: string[]; skipped: string[] } | null>(null);
  const [viewDoc, setViewDoc] = useState<ProjectDocument | null>(null);

  async function onFiles(files: FileList | null) {
    if (!files || files.length === 0) return;
    setResult(null);
    const res = await upload.mutateAsync(files);
    setResult(res);
    if (inputRef.current) inputRef.current.value = '';
  }

  return (
    <div className="bg-surface rounded-xl shadow-sm border border-line p-5 space-y-3">
      <div className="flex items-center justify-between gap-2">
        <h3 className="font-semibold text-ink">Project documents</h3>
        {documents && (
          <span className="text-xs font-medium text-muted bg-surface2 border border-line rounded-full px-2.5 py-0.5 shrink-0">
            {documents.length} total
          </span>
        )}
      </div>
      <p className="text-sm text-muted">
        Upload docs &amp; source (.md, .txt, code, .pdf, .docx), spreadsheets (.xlsx, .csv), and
        images (.png/.jpg). These ground the AI summary and chat for this project only.
      </p>

      <input
        ref={inputRef}
        type="file"
        multiple
        onChange={(e) => onFiles(e.target.files)}
        className="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-brand file:px-4 file:py-2 file:text-white"
      />

      {upload.isPending && <p className="text-sm text-muted">Uploading &amp; extracting…</p>}
      {upload.error && <p className="text-sm text-red-500">{(upload.error as Error).message}</p>}
      {result && (
        <p className="text-sm text-muted">
          Saved {result.saved.length} file(s)
          {result.skipped.length > 0 && `, skipped ${result.skipped.length} (${result.skipped.join(', ')})`}.
        </p>
      )}

      <ul className="text-sm text-ink divide-y divide-line max-h-[60vh] overflow-y-auto pr-1">
        {documents?.map((d) => (
          <li key={d.document_id} className="py-2 flex items-center gap-2">
            <button
              onClick={async () => {
                const res = await themedSwal().fire({
                  title: 'Delete document?',
                  html: `<span style="opacity:.8">${d.file_name}</span>`,
                  icon: 'warning',
                  showCancelButton: true,
                  confirmButtonText: 'Delete',
                  cancelButtonText: 'Cancel',
                });
                if (res.isConfirmed) {
                  await del.mutateAsync(d.document_id);
                  themedSwal().fire({ title: 'Deleted', icon: 'success', timer: 1200, showConfirmButton: false });
                }
              }}
              disabled={del.isPending}
              title="Delete document"
              className="text-red-500 hover:bg-red-500/10 rounded p-1 shrink-0 disabled:opacity-50"
              aria-label="Delete document"
            >
              🗑
            </button>
            <button
              type="button"
              onClick={() => setViewDoc(d)}
              className="flex-1 min-w-0 text-left hover:text-brand"
              title="View document"
            >
              <div className="truncate">
                {d.mime_type && d.mime_type.indexOf('image/') === 0 ? '🖼 ' : ''}{d.file_name}
              </div>
              <div className="text-[11px] text-muted">Uploaded {formatPHT(d.created_at)}</div>
            </button>
            <span className="text-muted text-xs shrink-0">{(d.byte_size / 1024).toFixed(1)} KB</span>
          </li>
        ))}
        {documents && documents.length === 0 && (
          <li className="py-1.5 text-muted">No documents uploaded yet.</li>
        )}
      </ul>
      {del.error && <p className="text-sm text-red-500">{(del.error as Error).message}</p>}

      {viewDoc && (
        <DocumentModal projectId={projectId} doc={viewDoc} onClose={() => setViewDoc(null)} />
      )}
    </div>
  );
}
