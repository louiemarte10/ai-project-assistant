import { useEffect, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { api, ChatMessage } from '../api/client';
import { formatPHT } from '../format';

export default function MessageBubble({ message }: { message: ChatMessage }) {
  const isUser = message.sender_role === 'user';
  const [zoom, setZoom] = useState<string | null>(null);
  useEffect(() => {
    if (!zoom) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setZoom(null); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [zoom]);
  // Image sources: local previews (optimistic pending bubble) take precedence,
  // else the server-stored images fetched by message_id + index.
  const localPreviews = (message as ChatMessage & { previews?: string[] }).previews;
  const serverImages =
    message.message_id > 0
      ? (message.attachments || [])
          .filter((a) => a.is_image)
          .map((a) => api.chatAttachUrl(message.project_id, message.message_id, a.i))
      : [];
  const images = localPreviews && localPreviews.length ? localPreviews : serverImages;
  // Hide the "[attached: …]" marker — the thumbnails already show the files.
  const text = message.message_payload.replace(/\n*\[attached:[^\]]*\]\s*$/i, '').trimEnd();
  // An AI error reply (flagged by the server, or by the ⚠️ prefix after reload).
  const isError = !isUser && (message.is_error === true || /^⚠/.test(message.message_payload));
  return (
    <div className={`flex flex-col ${isUser ? 'items-end' : 'items-start'}`}>
      <div className="text-[11px] text-muted mb-1 px-1">{formatPHT(message.timestamp)}</div>
      <div className={`max-w-[85%] rounded-2xl px-4 py-2.5 text-sm ${isError ? 'border border-red-500/40 bg-red-500/10 text-red-500' : isUser ? 'bubble-user' : 'bubble-ai'}`}>
        {images.length > 0 && (
          <div className="flex flex-wrap gap-2 mb-2">
            {images.map((src, i) => (
              <button key={i} type="button" onClick={() => setZoom(src)} title="View image" className="block">
                <img src={src} alt="attachment" className="h-16 w-16 rounded-lg border border-line object-cover hover:opacity-80" />
              </button>
            ))}
          </div>
        )}
        {isUser ? (
          text && <span className="whitespace-pre-wrap">{text}</span>
        ) : (
          <div className="markdown">
            <ReactMarkdown remarkPlugins={[remarkGfm]}>{text}</ReactMarkdown>
          </div>
        )}
      </div>

      {zoom && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4"
          onClick={() => setZoom(null)}
        >
          <img src={zoom} alt="attachment" className="max-h-[90vh] max-w-[90vw] rounded-lg object-contain shadow-2xl" />
          <button
            type="button"
            onClick={() => setZoom(null)}
            className="absolute top-4 right-4 h-9 w-9 rounded-full bg-white/15 text-white text-lg leading-none hover:bg-white/25"
            title="Close"
          >
            ✕
          </button>
        </div>
      )}
    </div>
  );
}
