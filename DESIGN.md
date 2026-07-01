# Project Turnover Summary & Debugging Assistant — Design & Implementation Plan

> **Status:** Historical design doc. The app is now **built and deployed**.
> **Important:** the backend was implemented in **PHP** (not Node/Express/Knex as planned
> below), to match the target Apache/PHP server (`192.168.50.12`) which has no Node runtime.
> The data model, features, AI/context-isolation approach, and prompts all carried over
> unchanged. For the as-built architecture and deploy steps see [README.md](README.md) and
> [client/DEPLOY.md](client/DEPLOY.md). Sections below are kept for design rationale.
> **Last updated:** 2026-06-23

---

## 1. Overview

An internal, responsive web tool that helps developers onboard onto newly handed-over
projects. For each project, the tool:

1. Ingests the project's documentation and source via **file/folder upload**.
2. Uses an AI provider to produce a **technical summary** (server location, tech stack,
   functional purpose) and stores it as structured metadata.
3. Exposes a **context-isolated chat** that answers questions about *only that project*.
4. Can **export a ready-to-use Markdown / README** for the repo.

### Core principle: context isolation
Every AI request and every chat log is scoped to a single `project_id`. The backend never
mixes one project's documents, metadata, or history into another project's context. This is
enforced at three layers: the DB (foreign keys), the API (every query filtered by `project_id`),
and the AI layer (context assembled only from the active project's documents).

---

## 2. Tech stack & rationale

| Layer | Choice | Why |
|---|---|---|
| Frontend | **React + Vite + TypeScript** | Fast dev server, modern build, type safety. |
| Routing | **React Router** | Per-project routes (`/projects/:id`). |
| Server state | **TanStack Query (React Query)** | Caching, loading/error states for API calls. |
| Styling | **Tailwind CSS** | Responsive layout with minimal custom CSS. |
| Markdown render | **react-markdown + remark-gfm** | Render AI answers and summary safely. |
| Backend | **Node.js + Express + TypeScript** | Matches the requested stack; simple REST. |
| DB access | **Knex.js (query builder)** | Maps 1:1 to the provided MySQL DDL incl. ENUM/FK; `schema.sql` stays the single source of truth (see note). |
| Database | **MySQL 8** | As specified. |
| File upload | **multer** | Multipart handling for file/folder upload. |
| Text extraction | `fs` for text/markdown/code; **pdf-parse** (pdf) + **mammoth** (docx) — **required for v1** | Turn uploads into plain text for the AI. |
| AI | **Provider-agnostic interface + Gemini adapter (confirmed)** | First adapter is Gemini; layer stays swappable. |

> **Decision (Knex, confirmed):** Because the schema is hand-authored in `schema.sql` and the
> team imports it directly into MySQL, `schema.sql` is the **single source of truth**. Knex is
> used purely as a typed query builder against the existing schema — we do **not** use Knex
> migrations (avoids a second, competing schema definition). Prisma was the alternative but it
> prefers to own migrations, which would create dual sources of truth with the imported SQL.

---

## 3. High-level architecture

```
┌─────────────────────────────────────────────────────────┐
│  Browser (React SPA, responsive)                          │
│  ┌───────────────┐ ┌──────────────┐ ┌─────────────────┐  │
│  │ Project list  │ │ AI Summary   │ │ Context-Isolated │  │
│  │ + upload      │ │ Dashboard    │ │ Chat             │  │
│  └───────────────┘ └──────────────┘ └─────────────────┘  │
└───────────────────────────┬──────────────────────────────┘
                            │ REST / JSON (fetch + React Query)
┌───────────────────────────▼──────────────────────────────┐
│  Express API (TypeScript)                                  │
│  Routes → Controllers → Services                           │
│   • ProjectService      • IngestionService                 │
│   • SummaryService      • ChatService                      │
│                          │                                 │
│   AI layer (interface) ──┼──► GeminiProvider (adapter)     │
│                          │     [OpenAI/Claude addable]      │
│  Knex ───────────────────┘                                 │
└───────────────────────────┬──────────────────────────────┘
                            │
                    ┌────────▼────────┐   ┌──────────────────┐
                    │   MySQL 8       │   │ uploads/ on disk │
                    │ projects /      │   │ (raw files)      │
                    │ metadata /      │   └──────────────────┘
                    │ chat_logs /     │
                    │ documents       │
                    └─────────────────┘
```

---

## 4. Repository / file structure

Monorepo with separate `client` and `server` packages.

```
project-turnover/
├── DESIGN.md                  ← this document
├── README.md
├── docker-compose.yml         ← MySQL (+ optional app) for local dev
├── .env.example               ← documents env vars; real .env is gitignored
├── schema.sql                 ← SINGLE SOURCE OF TRUTH for the DB (import this)
│
├── server/
│   ├── package.json
│   ├── tsconfig.json
│   ├── knexfile.ts            ← connection config only (no migrations)
│   ├── uploads/               ← raw uploaded files (gitignored)
│   └── src/
│       ├── index.ts           ← Express bootstrap
│       ├── db.ts              ← Knex instance
│       ├── config.ts          ← env loading & validation
│       ├── routes/
│       │   ├── projects.routes.ts
│       │   ├── documents.routes.ts
│       │   ├── summary.routes.ts
│       │   └── chat.routes.ts
│       ├── controllers/
│       ├── services/
│       │   ├── project.service.ts
│       │   ├── ingestion.service.ts
│       │   ├── summary.service.ts
│       │   └── chat.service.ts
│       ├── ai/
│       │   ├── AIProvider.ts          ← interface
│       │   ├── geminiProvider.ts       ← reference adapter
│       │   ├── prompts.ts              ← summary & chat prompt templates
│       │   └── index.ts                ← provider factory (reads env)
│       └── middleware/
│           ├── upload.ts               ← multer config
│           └── errorHandler.ts
│
└── client/
    ├── package.json
    ├── vite.config.ts
    ├── tailwind.config.js
    ├── index.html
    └── src/
        ├── main.tsx
        ├── App.tsx                     ← routes + layout
        ├── api/                        ← typed fetch wrappers + React Query hooks
        │   ├── projects.ts
        │   ├── documents.ts
        │   ├── summary.ts
        │   └── chat.ts
        ├── pages/
        │   ├── ProjectsPage.tsx        ← list + create + upload
        │   └── ProjectWorkspace.tsx    ← tabs: Summary | Chat | Export
        ├── components/
        │   ├── SummaryDashboard.tsx
        │   ├── ChatPanel.tsx
        │   ├── MessageBubble.tsx
        │   ├── FileUpload.tsx
        │   └── MarkdownExport.tsx
        └── lib/
            └── markdown.ts             ← summary → README builder
```

---

## 5. Database schema

The three tables below are implemented **exactly as specified**. Section 5.4 adds one table
the spec is missing.

### 5.1 `projects`
| Column | Type | Constraints |
|---|---|---|
| project_id | INT | PK, AUTO_INCREMENT |
| project_name | VARCHAR(255) | NOT NULL |
| repository_url | VARCHAR(255) | NULL |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |

### 5.2 `project_metadata`
| Column | Type | Constraints |
|---|---|---|
| meta_id | INT | PK, AUTO_INCREMENT |
| project_id | INT | FK → projects(project_id) |
| server_location | VARCHAR(255) | NOT NULL |
| tech_stack | TEXT | NOT NULL |
| functional_purpose | TEXT | NOT NULL |

> These three fields are exactly what the **AI Summary** produces. **Decision (confirmed):** a
> metadata row is created at **project creation time** with `"pending"` placeholder values for
> all three NOT NULL columns; the AI summary later **updates** that row in place. The
> `UNIQUE(project_id)` constraint guarantees exactly one metadata row per project, so summary
> regeneration is an `UPDATE`, never a duplicate insert.

### 5.3 `chat_logs`
| Column | Type | Constraints |
|---|---|---|
| message_id | INT | PK, AUTO_INCREMENT |
| project_id | INT | FK → projects(project_id) — enforces isolation |
| sender_role | ENUM('user','ai') | NOT NULL |
| message_payload | TEXT | NOT NULL |
| timestamp | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |

### 5.4 `project_documents` — **approved addition (not in original spec)**
The provided schema has nowhere to store the uploaded documentation that the AI reads from.
Without it, the AI summary cannot be regenerated and chat answers cannot be grounded. **Approved
— stored in the database** (full extracted text in `content_text`):

| Column | Type | Constraints | Description |
|---|---|---|---|
| document_id | INT | PK, AUTO_INCREMENT | |
| project_id | INT | FK → projects(project_id) | Isolation. |
| file_name | VARCHAR(255) | NOT NULL | Original filename. |
| file_path | VARCHAR(512) | NOT NULL | Location under `uploads/`. |
| content_text | LONGTEXT | NOT NULL | Extracted plain text fed to the AI. |
| byte_size | INT | NOT NULL | |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | |

All FKs use `ON DELETE CASCADE` so deleting a project removes its metadata, chat, and documents.

---

## 6. Backend API design (REST)

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/projects` | List projects. |
| POST | `/api/projects` | Create project (name, repository_url). |
| GET | `/api/projects/:id` | Get one project + its metadata. |
| DELETE | `/api/projects/:id` | Delete project (cascades). |
| POST | `/api/projects/:id/documents` | Upload one or more files (multipart). Extracts text, stores rows. |
| GET | `/api/projects/:id/documents` | List uploaded documents. |
| POST | `/api/projects/:id/summary` | (Re)generate AI summary → upsert `project_metadata`. |
| GET | `/api/projects/:id/chat` | Fetch chat history (scoped). |
| POST | `/api/projects/:id/chat` | Send a user message; returns AI reply; both logged. |
| GET | `/api/projects/:id/export` | Build & return README markdown. |

**Every** handler that touches metadata/chat/documents filters by the `:id` path param — this
is the API-layer half of context isolation.

---

## 7. AI integration layer

Provider is not finalized (likely **Gemini**), so the AI code sits behind an interface and is
selected at runtime from env. No provider SDK leaks into services.

```ts
// server/src/ai/AIProvider.ts
export interface AIProvider {
  generateSummary(input: {
    projectName: string;
    documents: { fileName: string; text: string }[];
  }): Promise<{ serverLocation: string; techStack: string; functionalPurpose: string; overview: string }>;

  chat(input: {
    projectName: string;
    context: string;            // assembled ONLY from this project's documents + metadata
    history: { role: 'user' | 'ai'; text: string }[];
    userMessage: string;
  }): Promise<string>;
}
```

- `geminiProvider.ts` implements this against the Gemini API.
- `ai/index.ts` is a factory: `AI_PROVIDER=gemini` → GeminiProvider. Adding OpenAI/Claude later
  is a new adapter file + one switch case — no service changes.
- **Context assembly** (`buildContext`) concatenates the active project's `content_text`
  (truncated/chunked to fit the model's token budget) plus its current metadata. If documents
  exceed the budget, v1 truncates with a notice; **RAG/embeddings is a documented future upgrade**
  (see §12).
- System prompt instructs the model to answer *only* from the supplied project context and to
  say so when the answer isn't present — reinforcing isolation and reducing hallucination.

### Security
- API keys live **server-side only** in `.env` (`GEMINI_API_KEY`), never shipped to the client.
- The browser talks only to our Express API; the API talks to the AI provider.
- Uploaded files validated by extension/size; `uploads/` is gitignored.

---

## 8. Document ingestion pipeline

1. User uploads files/folder via `FileUpload` (multipart → multer).
2. `IngestionService` extracts plain text per file:
   - `.md`, `.txt`, source files (`.js/.ts/.py/.json/...`) → read directly.
   - `.pdf` → **pdf-parse**, `.docx` → **mammoth** (both required for v1).
   - Unknown/binary → skipped with a warning.
3. Each file → one `project_documents` row (raw file also saved under `uploads/<project_id>/`).
4. Summary and chat both read from these rows, scoped by `project_id`.

---

## 9. Frontend design

- **ProjectsPage** — list existing projects, create a new one (name + optional repo URL),
  upload its documents.
- **ProjectWorkspace** (`/projects/:id`) — tabbed: **Summary | Chat | Export**.
  - **SummaryDashboard** — shows server location, tech stack (chips), functional purpose, and a
    "Regenerate summary" action; empty state prompts to upload docs first.
  - **ChatPanel** — message list (user/ai bubbles), input box, history loaded per project; AI
    answers rendered as markdown. State keyed by `project_id` so switching projects never bleeds.
  - **MarkdownExport** — preview the generated README and download `.md`.
- Responsive: single-column stack on mobile, sidebar + content on desktop (Tailwind breakpoints).

---

## 10. Core features → implementation mapping

| Requirement | How it's met |
|---|---|
| Context-isolated chat | `chat_logs.project_id` FK + API filtering + per-project AI context assembly. |
| AI summary dashboard | `POST /summary` fills `project_metadata`; `SummaryDashboard` renders it. |
| Markdown/README export | `lib/markdown.ts` composes metadata + overview into README; `GET /export` downloads it. |

---

## 11. Config, build & run (local dev)

- `docker-compose.yml` brings up MySQL 8.
- `.env.example` documents: `DATABASE_URL`/MySQL creds, `AI_PROVIDER`, `GEMINI_API_KEY`, `PORT`.
- `cd server && npm i && npm run migrate && npm run dev` (Express on :4000).
- `cd client && npm i && npm run dev` (Vite on :5173, proxy `/api` → :4000).

---

## 12. Future enhancements (out of scope for v1)

- **RAG / embeddings** for large doc sets instead of truncation (pgvector/MySQL vector or a
  vector DB; chunk + embed + retrieve top-k per query).
- **Clone-from-repository_url** ingestion (the field exists; auto-indexing is a later phase).
- Auth / multi-user accounts and per-user history.
- Streaming chat responses (SSE) for faster perceived latency.

---

## 13. Resolved decisions (signed off 2026-06-23)

1. **`project_documents` table** — ✅ approved; documents stored in the database (`content_text`).
2. **`project_metadata` NOT NULL** — ✅ insert `"pending"` placeholders at project creation; the
   AI summary `UPDATE`s the row later. `UNIQUE(project_id)` enforces one row per project.
3. **AI provider** — ✅ Gemini is the first adapter. **Key handling:** the real
   `GEMINI_API_KEY` lives only in a **gitignored `.env`** and is read via `process.env` —
   never committed, never in this doc. ⚠️ The key shared in chat must be **rotated** as it is
   now in the conversation transcript.
4. **Document types** — ✅ v1 supports text/markdown/code **plus `.pdf` (pdf-parse) and
   `.docx` (mammoth)**.
5. **Knex vs Prisma** — ✅ **Knex**, used as a query builder only; `schema.sql` is the single
   source of truth (no Knex migrations). Rationale in §2.

---

## 14. Suggested implementation phases

1. **Scaffold** — repo, Docker MySQL, migrations (all 4 tables), Express + Vite skeletons.
2. **Projects + upload** — CRUD projects, file upload, text extraction, `project_documents`.
3. **AI summary** — provider interface + Gemini adapter, `POST /summary`, dashboard UI.
4. **Context-isolated chat** — chat API, history, context assembly, chat UI.
5. **Markdown export** — README builder + download.
6. **Polish** — responsive pass, empty/error states, validation, README.
