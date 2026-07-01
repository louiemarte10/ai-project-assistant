// Base URL for the PHP API. Dev: set VITE_API_BASE in .env.development to the
// deployed server's /api (there is no local PHP). Prod: .env.production points
// at the sub-path /api on 50.12.
const API_BASE = import.meta.env.VITE_API_BASE || '/api';

export interface Project {
  project_id: number;
  project_name: string;
  repository_url: string[]; // JSON array of repo URLs
  created_at: string;
}

export interface ProjectMetadata {
  meta_id: number;
  project_id: number;
  server_location: string;
  tech_stack: string;
  functional_purpose: string;
  overview?: string;
  generated_at?: string;
}

export interface ProjectDocument {
  document_id: number;
  project_id: number;
  file_name: string;
  file_path: string;
  mime_type?: string | null;
  byte_size: number;
  created_at: string;
}

export interface ChatAttachment {
  data: string; // base64 (no data: prefix)
  name: string;
  mime: string;
}

export interface ChatConversation {
  conversation_id: number;
  title: string;
  created_at: string;
  updated_at: string | null;
}

export interface ChatAttachmentRef {
  i: number;
  mime: string;
  is_image: boolean;
}

export interface ChatMessage {
  message_id: number;
  project_id: number;
  sender_role: 'user' | 'ai';
  message_payload: string;
  timestamp: string;
  attachments?: ChatAttachmentRef[];
  is_error?: boolean;
}

export interface SessionInfo {
  logged_in: boolean;
  user_id?: number;
  user_name?: string | null;
  name?: string;
  session_token?: string;
  login_url?: string;
}

export interface UsageBucket {
  input_tokens: number;
  output_tokens: number;
  cache_write_tokens: number;
  cache_read_tokens: number;
  total_tokens: number;
  messages: number;
  cost_usd: number;
}

export interface UsageSeries {
  labels: string[]; // short x-axis labels (e.g. "Jun 25" or "18:35")
  full: string[];   // long labels for tooltips (e.g. "Jun 24, 6:35 PM")
  requests: number[];
  by: Record<string, { input: number[]; output: number[]; requests: number[] }>; // grouped by the chosen breakdown
}

export interface UsageByProject extends UsageBucket {
  project_id: number;
  project_name: string;
}

export interface UsageSummary {
  provider: string;
  model: string;
  tier: string;
  reset_at: string | null;
  range: string;
  project_id: number;
  pricing: {
    input_per_mtok: number;
    output_per_mtok: number;
    cache_write_per_mtok: number;
    cache_read_per_mtok: number;
  };
  budget: {
    monthly_usd: number;
    lock_pct: number;
    spent_usd: number;
    remaining_usd: number;
    percent: number;
    locked: boolean;
  };
  month: UsageBucket & { label: string };
  all_time: UsageBucket;
  range_totals: UsageBucket;
  series: UsageSeries;
  errors: { labels: string[]; full: string[]; by_type: Record<string, number[]>; total: number };
  by_project: UsageByProject[];
  is_admin?: boolean;
  scope?: 'all' | 'self';
}

export interface ApiKeyRow {
  id: number;
  user_id: number;
  name: string;
  ai_model: string;
  key_masked: string;
  create_date: string;
  expiration: string | null;
  active: boolean;
}

export interface MyApiKey {
  id: number;
  ai_model: string;
  key_masked: string;
  create_date: string;
  expiration: string | null;
}

export interface ApiKeyStatus {
  available: boolean; // false if the api_key_by_user table isn't created yet
  has_key: boolean;
  mine?: MyApiKey[];  // the current user's active keys
  is_admin?: boolean;
  all?: ApiKeyRow[];  // admin only
}

async function handle<T>(res: Response): Promise<T> {
  const text = await res.text();
  let data: any = undefined;
  if (text) {
    try { data = JSON.parse(text); } catch { /* non-JSON (e.g. PHP fatal) */ }
  }
  if (!res.ok || (data && data.error)) {
    const message = (data && data.error) || `Request failed (${res.status})`;
    throw new Error(message);
  }
  return data as T;
}

export const api = {
  base: API_BASE,

  // Same-origin in production → the portal session cookie is sent automatically.
  getSession: () => fetch(`${API_BASE}/session.php`).then(handle<SessionInfo>),

  getUsage: (range = '28d', projectId = 0, userId = 0, breakdown = 'model') =>
    fetch(`${API_BASE}/usage.php?range=${range}&project_id=${projectId}&user_id=${userId}&breakdown=${breakdown}`).then(handle<UsageSummary>),

  getApiKey: (all = false) =>
    fetch(`${API_BASE}/apikey.php${all ? '?all=1' : ''}`).then(handle<ApiKeyStatus>),

  saveApiKey: (body: { api_key: string; ai_model?: string; expiration?: string }) =>
    fetch(`${API_BASE}/apikey.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(handle<{ success: boolean; has_key: boolean; key_masked: string }>),

  deleteApiKey: (id?: number) =>
    fetch(`${API_BASE}/apikey.php${id ? `?id=${id}` : ''}`, { method: 'DELETE' }).then(handle<{ success: boolean }>),

  setKeyModel: (id: number, ai_model: string) =>
    fetch(`${API_BASE}/apikey.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, ai_model }),
    }).then(handle<{ success: boolean }>),

  resetUsage: () =>
    fetch(`${API_BASE}/usage.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reset: true }),
    }).then(handle<{ success: boolean; reset_at: string }>),

  backfillUsage: () =>
    fetch(`${API_BASE}/usage.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ backfill: true }),
    }).then(handle<{ success: boolean; imported: number }>),

  listProjects: () => fetch(`${API_BASE}/projects.php`).then(handle<Project[]>),

  getProject: (id: number) =>
    fetch(`${API_BASE}/project.php?id=${id}`).then(handle<{ project: Project; metadata: ProjectMetadata | null }>),

  createProject: (body: { projectName: string; repositoryUrls?: string[] }) =>
    fetch(`${API_BASE}/projects.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(handle<Project & { github_import?: { imported: string[]; errors: string[] } }>),

  updateProject: (id: number, body: { projectName: string; repositoryUrls?: string[] }) =>
    fetch(`${API_BASE}/project.php?id=${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(handle<{ success: boolean; project: Project; github_import?: { imported: string[]; errors: string[] } }>),

  deleteProject: (id: number) =>
    fetch(`${API_BASE}/project.php?id=${id}`, { method: 'DELETE' }).then(handle<{ success: boolean }>),

  listDocuments: (id: number) =>
    fetch(`${API_BASE}/documents.php?project_id=${id}`).then(handle<ProjectDocument[]>),

  getDocument: (projectId: number, documentId: number) =>
    fetch(`${API_BASE}/documents.php?project_id=${projectId}&document_id=${documentId}`)
      .then(handle<ProjectDocument & { content_text: string }>),

  // Raw image URL (for <img src>); only meaningful for image docs.
  documentRawUrl: (projectId: number, documentId: number) =>
    `${API_BASE}/documents.php?project_id=${projectId}&document_id=${documentId}&raw=1`,

  deleteDocument: (projectId: number, documentId: number) =>
    fetch(`${API_BASE}/documents.php?project_id=${projectId}&document_id=${documentId}`, { method: 'DELETE' })
      .then(handle<{ success: boolean }>),

  // Uploads in batches because the server's PHP caps each request at
  // max_file_uploads (20). Chunking lets the user select many files at once.
  uploadDocuments: async (id: number, files: FileList | File[]) => {
    const all = Array.from(files);
    const BATCH = 15;
    const merged: { saved: string[]; skipped: string[] } = { saved: [], skipped: [] };
    for (let i = 0; i < all.length; i += BATCH) {
      const form = new FormData();
      all.slice(i, i + BATCH).forEach((f) => form.append('files[]', f));
      const res = await fetch(`${API_BASE}/documents.php?project_id=${id}`, {
        method: 'POST',
        body: form,
      }).then(handle<{ saved: string[]; skipped: string[] }>);
      merged.saved.push(...res.saved);
      merged.skipped.push(...res.skipped);
    }
    return merged;
  },

  generateSummary: (id: number) =>
    fetch(`${API_BASE}/summary.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ project_id: id }),
    }).then(handle<{ metadata: ProjectMetadata; overview: string }>),

  listConversations: (projectId: number) =>
    fetch(`${API_BASE}/conversations.php?project_id=${projectId}`).then(handle<ChatConversation[]>),

  deleteConversation: (projectId: number, conversationId: number) =>
    fetch(`${API_BASE}/conversations.php?project_id=${projectId}&conversation_id=${conversationId}`, { method: 'DELETE' })
      .then(handle<{ success: boolean }>),

  getChat: (projectId: number, conversationId: number) =>
    fetch(`${API_BASE}/chat.php?project_id=${projectId}&conversation_id=${conversationId}`).then(handle<ChatMessage[]>),

  sendChat: (projectId: number, conversationId: number, message: string, attachments?: ChatAttachment[], signal?: AbortSignal) =>
    fetch(`${API_BASE}/chat.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      signal,
      body: JSON.stringify({
        project_id: projectId,
        conversation_id: conversationId || undefined,
        message,
        attachments: attachments && attachments.length ? attachments : undefined,
      }),
    }).then(handle<{ userMessage: ChatMessage; aiMessage: ChatMessage; conversation_id: number; conversation_title: string | null }>),

  // Streaming send: posts to chat.php?stream=1 and invokes onDelta() as text
  // arrives (SSE). Resolves with the final {userMessage, aiMessage, …}.
  sendChatStream: async (
    projectId: number,
    conversationId: number,
    message: string,
    attachments: ChatAttachment[] | undefined,
    opts: { onDelta: (t: string) => void; onThought?: (t: string) => void; signal?: AbortSignal },
  ) => {
    const res = await fetch(`${API_BASE}/chat.php?stream=1`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      signal: opts.signal,
      body: JSON.stringify({
        project_id: projectId,
        conversation_id: conversationId || undefined,
        message,
        attachments: attachments && attachments.length ? attachments : undefined,
      }),
    });
    const ct = res.headers.get('content-type') || '';
    if (!res.ok || !ct.includes('text/event-stream') || !res.body) {
      const text = await res.text();
      let data: any; try { data = JSON.parse(text); } catch { /* non-JSON */ }
      throw new Error((data && data.error) || `Request failed (${res.status})`);
    }
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buf = '';
    let done: { userMessage: ChatMessage; aiMessage: ChatMessage; conversation_id: number; conversation_title: string | null } | null = null;
    for (;;) {
      const { value, done: rd } = await reader.read();
      if (rd) break;
      buf += decoder.decode(value, { stream: true });
      let sep: number;
      while ((sep = buf.indexOf('\n\n')) !== -1) {
        const chunk = buf.slice(0, sep);
        buf = buf.slice(sep + 2);
        const line = chunk.split('\n').find((l) => l.startsWith('data:'));
        if (!line) continue;
        let evt: any;
        try { evt = JSON.parse(line.slice(5).trim()); } catch { continue; }
        if (evt.type === 'delta') opts.onDelta(evt.text);
        else if (evt.type === 'thought') opts.onThought?.(evt.text);
        else if (evt.type === 'done') done = evt;
        else if (evt.type === 'error') throw new Error(evt.error);
      }
    }
    return done;
  },

  // Raw image URL for a chat message's pasted/attached image (i = which one).
  chatAttachUrl: (projectId: number, messageId: number, i = 0) =>
    `${API_BASE}/chat.php?project_id=${projectId}&attach=1&message_id=${messageId}&i=${i}`,

  exportUrl: (id: number) => `${API_BASE}/export.php?project_id=${id}`,
};
