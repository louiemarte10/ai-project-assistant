import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { api, ProjectDocument } from '../api/client';

export default function DocumentModal({
  projectId,
  doc,
  onClose,
}: {
  projectId: number;
  doc: ProjectDocument;
  onClose: () => void;
}) {
  const isImage = !!doc.mime_type && doc.mime_type.indexOf('image/') === 0;
  const isPdf = doc.mime_type === 'application/pdf';
  const isInline = isImage || isPdf; // rendered from the raw endpoint, not JSON

  // Close on Escape.
  useEffect(() => {
    const h = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);

  const { data, isLoading, error } = useQuery({
    queryKey: ['document', doc.document_id],
    queryFn: () => api.getDocument(projectId, doc.document_id),
    enabled: !isInline, // images/PDFs are loaded via the raw endpoint, not the JSON body
  });

  const isMarkdown = /\.md$/i.test(doc.file_name);

  return (
    <div
      className="fixed inset-0 z-[100] bg-black/60 flex items-center justify-center p-4"
      onClick={onClose}
    >
      <div
        className="bg-surface border border-line rounded-xl shadow-xl w-full max-w-3xl max-h-[85vh] flex flex-col"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between px-4 py-3 border-b border-line">
          <h3 className="font-semibold text-ink truncate pr-3">{doc.file_name}</h3>
          <button onClick={onClose} className="text-muted hover:text-ink text-lg leading-none" aria-label="Close">✕</button>
        </div>

        <div className="overflow-auto p-4">
          {isImage ? (
            <img
              src={api.documentRawUrl(projectId, doc.document_id)}
              alt={doc.file_name}
              className="max-w-full mx-auto rounded"
            />
          ) : isPdf ? (
            <iframe
              src={api.documentRawUrl(projectId, doc.document_id)}
              title={doc.file_name}
              className="w-full rounded border border-line"
              style={{ height: '70vh' }}
            />
          ) : isLoading ? (
            <p className="text-muted text-sm">Loading…</p>
          ) : error ? (
            <p className="text-red-500 text-sm">{(error as Error).message}</p>
          ) : isMarkdown ? (
            <div className="markdown text-ink text-sm">
              <ReactMarkdown remarkPlugins={[remarkGfm]}>{data?.content_text || '_empty_'}</ReactMarkdown>
            </div>
          ) : (
            <pre className="text-ink text-xs whitespace-pre-wrap break-words font-mono">
              {data?.content_text || '(empty)'}
            </pre>
          )}
        </div>
      </div>
    </div>
  );
}
