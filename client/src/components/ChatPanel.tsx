import { ClipboardEvent, FormEvent, useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import Swal from 'sweetalert2';
import { useApiKey, useChat, useConversations, useDeleteApiKey, useDeleteConversation, useSaveApiKey, useSetKeyModel, useUsage } from '../api/hooks';
import { api } from '../api/client';
import { useAuth } from '../auth';
import MessageBubble from './MessageBubble';

const MAX_ATTACH_BYTES = 8 * 1024 * 1024;

// Pool of status messages shown while the AI generates a reply. A random one is
// picked on each rotation, so the wording isn't a fixed sequence.
const THINKING_MESSAGES = [
  'Reading your question…',
  'Loading the project documents…',
  'Scanning the linked repository…',
  'Selecting the most relevant files…',
  'Reviewing the code and docs…',
  'Cross-referencing the details…',
  'Connecting the dots…',
  'Gathering the context…',
  'Working through it…',
  'Composing your answer…',
  'Drafting the response…',
  'Refining the wording…',
  'Polishing the response…',
  'Almost done…',
];

interface Attached { data: string; name: string; mime: string; isImage: boolean; preview: string; size: number; w?: number; h?: number }

function readFile(file: File): Promise<Attached> {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onerror = reject;
    r.onload = () => {
      const url = String(r.result);
      const comma = url.indexOf(',');
      const mime = file.type || 'application/octet-stream';
      const base: Attached = { data: url.slice(comma + 1), name: file.name || 'attachment', mime, isImage: mime.startsWith('image/'), preview: url, size: file.size };
      if (base.isImage) {
        const img = new Image();
        img.onload = () => resolve({ ...base, w: img.naturalWidth, h: img.naturalHeight });
        img.onerror = () => resolve(base);
        img.src = url;
      } else {
        resolve(base);
      }
    };
    r.readAsDataURL(file);
  });
}

function fmtSize(n: number) {
  if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(n / 1024))} KB`;
}

export default function ChatPanel({ projectId }: { projectId: number }) {
  const auth = useAuth();
  const { data: conversations } = useConversations(projectId);
  const del = useDeleteConversation(projectId);
  const qc = useQueryClient();
  const { data: usage } = useUsage();
  const locked = usage?.budget.locked ?? false;
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [streamText, setStreamText] = useState<string | null>(null);
  const { data: keyStatus } = useApiKey();
  const saveKey = useSaveApiKey();
  const delKey = useDeleteApiKey();
  const setKeyModelMut = useSetKeyModel();
  const [keyInput, setKeyInput] = useState('');
  const [keyModel, setKeyModel] = useState('gemini-2.5-flash-lite');
  const [showKeyForm, setShowKeyForm] = useState(false);
  const needsKey = keyStatus?.available === true && keyStatus?.has_key === false;
  const keyAvailable = keyStatus?.available === true;
  const primaryKey = keyStatus?.mine?.[0];

  // null = initializing; 0 = a fresh "New chat" not yet saved; >0 = a thread.
  const [activeConvId, setActiveConvId] = useState<number | null>(null);
  const [input, setInput] = useState('');
  // The just-sent user message, shown immediately while the AI is thinking so it
  // doesn't disappear until the reply lands (with a local image preview if any).
  const [pending, setPending] = useState<{ text: string; previews: string[] } | null>(null);
  const [attachments, setAttachments] = useState<Attached[]>([]);
  const [attachError, setAttachError] = useState('');
  const [thinkingIdx, setThinkingIdx] = useState(0);
  const [thinkingText, setThinkingText] = useState('');
  const [expanded, setExpanded] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const taRef = useRef<HTMLTextAreaElement>(null);
  const abortRef = useRef<AbortController | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const measureRef = useRef<HTMLSpanElement>(null);
  const [selWidth, setSelWidth] = useState(160);

  // On first load, open the most recent thread (or a fresh New chat if none).
  useEffect(() => {
    if (activeConvId === null && conversations) {
      setActiveConvId(conversations.length > 0 ? conversations[0].conversation_id : 0);
    }
  }, [activeConvId, conversations]);

  const convId = activeConvId && activeConvId > 0 ? activeConvId : 0;
  const { data: messages, isLoading } = useChat(projectId, convId);

  // Size the thread dropdown to fit the selected title (grows for long titles).
  const activeTitle = convId === 0
    ? 'New chat'
    : (conversations?.find((c) => c.conversation_id === convId)?.title || '');
  useEffect(() => {
    if (measureRef.current) {
      const w = measureRef.current.offsetWidth;
      setSelWidth(Math.min(460, Math.max(140, w + 48))); // + padding + dropdown arrow
    }
  }, [activeTitle, conversations]);

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, sending, pending, streamText, activeConvId]);

  // Esc collapses the expanded (modal) chat view.
  useEffect(() => {
    if (!expanded) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setExpanded(false); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [expanded]);

  // While waiting for the reply, show a random "thinking" message, changing every
  // 5 seconds (avoids repeating the one before it).
  useEffect(() => {
    if (!sending) return;
    const pick = () => setThinkingIdx((prev) => {
      if (THINKING_MESSAGES.length <= 1) return 0;
      let n = prev;
      while (n === prev) n = Math.floor(Math.random() * THINKING_MESSAGES.length);
      return n;
    });
    pick();
    const t = setInterval(pick, 5000);
    return () => clearInterval(t);
  }, [sending]);

  // Typewriter the current "thinking" message in (re-types when it rotates).
  useEffect(() => {
    if (!sending) { setThinkingText(''); return; }
    const msg = THINKING_MESSAGES[thinkingIdx];
    setThinkingText('');
    let i = 0;
    const t = setInterval(() => {
      i += 1;
      setThinkingText(msg.slice(0, i));
      if (i >= msg.length) clearInterval(t);
    }, 35);
    return () => clearInterval(t);
  }, [sending, thinkingIdx]);

  async function addFiles(files: FileList | File[] | null | undefined) {
    if (!files) return;
    const list = Array.from(files);
    if (list.length === 0) return;
    setAttachError('');
    const ok: Attached[] = [];
    for (const f of list) {
      if (f.size > MAX_ATTACH_BYTES) { setAttachError(`"${f.name}" is too large (max 8 MB).`); continue; }
      ok.push(await readFile(f));
    }
    if (ok.length) setAttachments((prev) => [...prev, ...ok]);
  }
  function onPaste(e: ClipboardEvent) {
    const imgs = Array.from(e.clipboardData.items).filter((i) => i.type.startsWith('image/'));
    if (imgs.length) {
      e.preventDefault();
      addFiles(imgs.map((i) => i.getAsFile()).filter(Boolean) as File[]);
    }
  }
  function removeAttachment(idx: number) {
    setAttachments((prev) => prev.filter((_, i) => i !== idx));
  }

  async function onSend(e?: FormEvent) {
    e?.preventDefault();
    const text = input.trim();
    if ((!text && attachments.length === 0) || sending || locked) return;
    const sent = attachments;
    const payload = sent.map((a) => ({ data: a.data, name: a.name, mime: a.mime }));
    const nonImg = sent.filter((a) => !a.isImage).map((a) => a.name);
    const displayText = text || (nonImg.length ? `[attached: ${nonImg.join(', ')}]` : '');
    setInput('');
    if (taRef.current) taRef.current.style.height = 'auto';
    setAttachments([]);
    setSendError(null);
    setSending(true);
    setStreamText(null);
    setPending({ text: displayText, previews: sent.filter((a) => a.isImage).map((a) => a.preview) });
    const controller = new AbortController();
    abortRef.current = controller;
    try {
      const res = await api.sendChat(projectId, convId, text, payload.length ? payload : undefined, controller.signal);
      // Typewriter reveal: animate the full reply in, ~2.5s regardless of length.
      const full = res.aiMessage.message_payload;
      setStreamText('');
      const perTick = Math.max(2, Math.round(full.length / 200));
      for (let i = 0; i < full.length; ) {
        if (controller.signal.aborted) break;
        i = Math.min(full.length, i + perTick);
        setStreamText(full.slice(0, i));
        // eslint-disable-next-line no-await-in-loop
        await new Promise((r) => setTimeout(r, 15));
      }
      // Persist into the cache so it survives refetch, like the old mutation did.
      qc.setQueryData(['chat', projectId, res.conversation_id], (old: any) => {
        const prev = Array.isArray(old) ? old : [];
        return [...prev, res.userMessage, res.aiMessage];
      });
      qc.setQueryData(['conversations', projectId], (old: any) => {
        const list = Array.isArray(old) ? [...old] : [];
        const now = new Date().toISOString();
        const i = list.findIndex((c: any) => c.conversation_id === res.conversation_id);
        const title = res.conversation_title || (i >= 0 ? list[i].title : 'New chat');
        if (i >= 0) list[i] = { ...list[i], title, updated_at: now };
        else list.unshift({ conversation_id: res.conversation_id, title, created_at: now, updated_at: now });
        list.sort((a: any, b: any) => String(b.updated_at || b.created_at).localeCompare(String(a.updated_at || a.created_at)));
        return list;
      });
      if (res.conversation_id && res.conversation_id !== convId) setActiveConvId(res.conversation_id);
    } catch (err) {
      // Stopped by the user — restore their draft so they can edit/resend.
      if ((err as Error)?.name === 'AbortError') {
        setInput(text);
        setAttachments(sent);
      } else {
        setSendError((err as Error).message);
      }
    } finally {
      abortRef.current = null;
      setSending(false);
      setPending(null);
      setStreamText(null);
    }
  }

  function onStop() {
    abortRef.current?.abort();
  }

  async function onDeleteConversation() {
    if (!convId) return;
    const css = getComputedStyle(document.documentElement);
    const bg = css.getPropertyValue('--surface').trim() || '#1c2230';
    const fg = css.getPropertyValue('--text').trim() || '#e5e9f0';
    const r = await Swal.fire({
      title: 'Delete this chat?', text: 'This conversation and its messages will be removed.',
      icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc2626',
      background: bg, color: fg,
    });
    if (r.isConfirmed) { await del.mutateAsync(convId); setActiveConvId(null); }
  }

  const panel = (
    <div className={`bg-surface shadow-sm border border-line flex flex-col ${expanded ? 'rounded-xl h-[90vh] w-[95vw] max-w-5xl' : 'rounded-xl h-[70vh]'}`}>
      <div className="px-4 py-3 border-b border-line">
        <div className="flex items-center justify-between gap-2">
          <h3 className="font-semibold text-ink">Project Chat</h3>
          <div className="flex items-center gap-2">
            {/* Hidden measurer: same font as the select, used to size it to the title. */}
            <span ref={measureRef} aria-hidden="true" className="absolute invisible whitespace-pre text-sm">{activeTitle}</span>
            <select
              value={convId}
              onChange={(e) => setActiveConvId(Number(e.target.value))}
              style={{ width: selWidth }}
              className="rounded-lg border border-line bg-surface2 text-ink text-sm px-2 py-1"
            >
              {convId === 0 && <option value={0}>New chat</option>}
              {conversations?.map((c) => (
                <option key={c.conversation_id} value={c.conversation_id}>{c.title}</option>
              ))}
            </select>
            <button onClick={() => setActiveConvId(0)} title="New chat" className="rounded-lg bg-brand text-white text-sm px-3 py-1">+ New</button>
            {convId > 0 && (
              <button onClick={onDeleteConversation} title="Delete this chat" className="rounded-lg border border-line text-red-500 text-sm px-2 py-1 hover:bg-red-500/10">🗑</button>
            )}
            {keyAvailable && (
              <button onClick={() => setShowKeyForm(true)} title="API key & model" className="rounded-lg border border-line text-ink text-sm px-2 py-1 hover:bg-surface2">🔑</button>
            )}
            <button onClick={() => setExpanded((v) => !v)} title={expanded ? 'Collapse' : 'Expand to full screen'} className="rounded-lg border border-line text-ink text-sm px-2 py-1 hover:bg-surface2">{expanded ? '🗗' : '⛶'}</button>
          </div>
        </div>
        <p className="text-xs text-muted mt-1">
          Answers are isolated to this project's documents &amp; repos.
          {auth?.name ? ` · Chatting as ${auth.name}` : ''}
          {primaryKey && (
            <>
              {' · Model: '}
              <select
                value={primaryKey.ai_model}
                onChange={(e) => setKeyModelMut.mutate({ id: primaryKey.id, ai_model: e.target.value })}
                title="Switch the model used for chat"
                className="rounded border border-line bg-surface2 text-ink text-[11px] px-1 py-0.5 ml-0.5"
              >
                <option value="gemini-2.5-flash-lite">Gemini 2.5 Flash-Lite</option>
                <option value="gemini-2.5-flash">Gemini 2.5 Flash</option>
              </select>
              {keyStatus!.mine!.length > 1 ? ` (+${keyStatus!.mine!.length - 1} fallback)` : ''}
            </>
          )}
        </p>
      </div>

      <div ref={scrollRef} className="flex-1 overflow-y-auto p-4 space-y-3 bg-surface2">
        {convId === 0 && (!messages || messages.length === 0) && (
          <p className="text-muted text-sm">New chat — ask a question to begin.</p>
        )}
        {isLoading && <p className="text-muted text-sm">Loading…</p>}
        {messages?.map((m) => <MessageBubble key={m.message_id} message={m} />)}
        {pending !== null && (
          <MessageBubble
            message={{ message_id: -1, project_id: projectId, sender_role: 'user', message_payload: pending.text, timestamp: new Date().toISOString(), previews: pending.previews } as any}
          />
        )}
        {streamText !== null && streamText !== '' && (
          <MessageBubble
            message={{ message_id: -2, project_id: projectId, sender_role: 'ai', message_payload: streamText + ' ▌', timestamp: new Date().toISOString() } as any}
          />
        )}
        {sending && (streamText === null || streamText === '') && <p className="text-muted text-sm">{thinkingText}▌</p>}
        {sendError && <p className="text-red-500 text-sm">{sendError}</p>}
      </div>

      {locked && (
        <div className="px-4 py-3 border-t border-line bg-red-500/10 text-sm text-red-500">
          <span className="font-medium">Chat is temporarily disabled.</span>{' '}
          The monthly AI budget limit ({usage?.budget.lock_pct}% of ${usage?.budget.monthly_usd}) has been reached
          ({usage?.budget.percent}% used). It re-enables next month, or once the budget is raised in the server config.
          See the <span className="font-medium">Dashboard</span> for details.
        </div>
      )}

      <form onSubmit={onSend} className="p-3 border-t border-line space-y-2">
        {attachments.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {attachments.map((a, i) => (
              <div key={i} className="flex items-center gap-2 rounded-lg border border-line bg-surface2 pl-1 pr-2 py-1">
                {a.isImage ? (
                  <img src={a.preview} alt={a.name} className="h-9 w-9 object-cover rounded" />
                ) : (
                  <span className="h-9 w-9 flex items-center justify-center rounded bg-surface text-base">📄</span>
                )}
                <div className="min-w-0">
                  <div className="text-xs text-ink truncate max-w-[150px]">{a.name}</div>
                  <div className="text-[10px] text-muted">{a.isImage && a.w ? `${a.w}×${a.h}` : fmtSize(a.size)}</div>
                </div>
                <button type="button" onClick={() => removeAttachment(i)} title="Remove" className="ml-1 text-muted hover:text-red-500 text-sm leading-none">✕</button>
              </div>
            ))}
          </div>
        )}
        {attachError && <p className="text-red-500 text-xs">{attachError}</p>}
        <div className="flex gap-2">
          <button type="button" disabled={locked} onClick={() => fileRef.current?.click()} title="Attach images / files (multiple)" className="rounded-lg border border-line bg-surface2 text-ink px-3 py-2 text-sm disabled:opacity-50">📎</button>
          <input ref={fileRef} type="file" multiple accept="image/*,.csv,.xlsx,.txt,.md,.pdf,.docx" className="hidden"
            onChange={(e) => { addFiles(e.target.files); if (fileRef.current) fileRef.current.value = ''; }} />
          <textarea
            ref={taRef}
            value={input}
            onChange={(e) => {
              setInput(e.target.value);
              const el = e.target;
              el.style.height = 'auto';
              el.style.height = `${Math.min(160, el.scrollHeight)}px`;
            }}
            onPaste={onPaste}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
                e.preventDefault();
                onSend();
              }
            }}
            disabled={locked}
            rows={1}
            placeholder={locked ? 'Chat disabled — AI budget limit reached.' : 'Ask about this project… (Enter to send, Shift+Enter for a new line)'}
            className="flex-1 resize-none rounded-lg border border-line bg-surface text-ink px-3 py-2 text-sm placeholder:text-muted disabled:opacity-50 max-h-40 min-h-[40px]"
          />
          {sending ? (
            <button type="button" onClick={onStop} title="Stop generating" className="rounded-lg bg-red-600 text-white px-4 py-2 text-sm hover:bg-red-700">■ Stop</button>
          ) : (
            <button type="submit" disabled={locked || (!input.trim() && attachments.length === 0)} className="rounded-lg bg-brand text-white px-4 py-2 text-sm disabled:opacity-50">Send</button>
          )}
        </div>
      </form>
    </div>
  );

  if (needsKey || showKeyForm) {
    const existing = keyStatus?.mine || [];
    return (
      <div className="bg-surface rounded-xl shadow-sm border border-line p-6 h-[70vh] flex flex-col items-center justify-center text-center">
        <div className="max-w-md w-full">
          <div className="text-3xl mb-2">🔑</div>
          <h3 className="font-semibold text-ink text-lg mb-1">{needsKey ? 'Add your Gemini API key' : 'API key & model'}</h3>
          <p className="text-muted text-sm mb-4">
            {needsKey ? "To use the chat, enter your own Google Gemini API key. It's saved to your account and used for your conversations. " : 'Add another key (used as fallback when one hits its limit) or switch model. '}
            Get one free at{' '}
            <a className="text-brand hover:underline" href="https://aistudio.google.com/apikey" target="_blank" rel="noreferrer">aistudio.google.com/apikey</a>.
          </p>
          {existing.length > 0 && (
            <div className="text-left text-xs text-muted mb-3 space-y-1">
              {existing.map((k) => (
                <div key={k.id} className="flex items-center gap-2">
                  <span className="font-mono text-ink">{k.key_masked}</span>
                  <select
                    value={k.ai_model}
                    onChange={(e) => setKeyModelMut.mutate({ id: k.id, ai_model: e.target.value })}
                    className="rounded border border-line bg-surface2 text-ink text-[11px] px-1 py-0.5"
                  >
                    <option value="gemini-2.5-flash-lite">2.5 Flash-Lite</option>
                    <option value="gemini-2.5-flash">2.5 Flash</option>
                  </select>
                  <button onClick={() => delKey.mutate(k.id)} className="ml-auto text-red-500 hover:underline">remove</button>
                </div>
              ))}
            </div>
          )}
          <input
            value={keyInput}
            onChange={(e) => setKeyInput(e.target.value)}
            type="password"
            placeholder="Paste a Gemini API key (AIza… or AQ.…)"
            className="w-full rounded-lg border border-line bg-surface2 text-ink px-3 py-2 text-sm placeholder:text-muted"
          />
          <label className="flex items-center justify-between gap-2 mt-2 text-sm text-muted">
            <span>Model</span>
            <select value={keyModel} onChange={(e) => setKeyModel(e.target.value)} className="rounded-lg border border-line bg-surface2 text-ink text-sm px-2 py-1">
              <option value="gemini-2.5-flash-lite">Gemini 2.5 Flash-Lite (bigger free quota)</option>
              <option value="gemini-2.5-flash">Gemini 2.5 Flash (smarter, ~20/day free)</option>
            </select>
          </label>
          <button
            onClick={async () => { try { await saveKey.mutateAsync({ api_key: keyInput.trim(), ai_model: keyModel }); setKeyInput(''); setShowKeyForm(false); } catch { /* shown below */ } }}
            disabled={saveKey.isPending || !keyInput.trim()}
            className="mt-3 w-full rounded-lg bg-brand text-white px-4 py-2 text-sm disabled:opacity-50"
          >
            {saveKey.isPending ? 'Validating…' : (needsKey ? 'Save key & start chatting' : 'Save key')}
          </button>
          {saveKey.error && <p className="text-red-500 text-sm mt-2">{(saveKey.error as Error).message}</p>}
          {!needsKey && (
            <button onClick={() => { setShowKeyForm(false); setKeyInput(''); }} className="mt-3 text-sm text-muted hover:text-ink">← Back to chat</button>
          )}
          {needsKey && <p className="text-[11px] text-muted mt-3">Your key is validated with Google before saving. Manage all keys from the Dashboard.</p>}
        </div>
      </div>
    );
  }

  if (expanded) {
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={() => setExpanded(false)}>
        <div className="w-full flex justify-center" onClick={(e) => e.stopPropagation()}>{panel}</div>
      </div>
    );
  }
  return panel;
}
