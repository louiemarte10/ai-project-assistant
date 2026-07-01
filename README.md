# AI Project Assistant

Internal tool to analyze handed-over projects: upload their docs/source, get an AI technical
summary, ask project-isolated questions in chat, and export a README.

## Architecture

Matches the Callbox server pattern (like `lh_transfer_contact_v2`): a **React (Vite) static
build + a PHP API**, served entirely by Apache on `192.168.50.12`. **No Node runtime on the
server.**

```
client/
├── src/                      React + TypeScript UI (Vite, Tailwind, React Query)
├── public/api/*.php          PHP API (PHP 5.3-compatible) — copied into dist/api/ at build
├── .env.production           VITE_API_BASE = <sub-path>/api
├── .env.development          VITE_API_BASE = deployed API URL (no local PHP)
├── deploy.ps1                build + WinSCP sync of dist/ → server
└── DEPLOY.md                 deploy guide
schema.sql                    DB schema (already applied to callbox_reports)
DESIGN.md                     original design doc (note: backend implemented in PHP, not Node)
```

## Data

- **Database:** `callbox_reports` on the `main` MaxScale proxy (`192.168.50.24`), tables
  `projects`, `project_metadata`, `project_documents`, `chat_logs` (see `schema.sql`).
- PHP connects via `require .../config/pipeline-x.php` + `config::get_server_by_name('main')`
  with the `app_pipe` user (same pattern as the other tools).
- **AI:** Gemini (`gemini-2.5-flash`) via server-side PHP cURL. Key in `client/public/api/secret-gemini.php` (gitignored, deployed inside `dist/`).

## Develop locally

```bash
cd client
npm install
npm run dev        # http://localhost:5173 — calls the deployed PHP API (CORS open)
```

There is no local PHP/DB; `npm run dev` talks to the live API on 50.12 (`.env.development`).

## Deploy

See **[client/DEPLOY.md](client/DEPLOY.md)**. Short version:

```powershell
cd client
npm run build
# then either: Ctrl+Shift+P → SFTP: Sync Local -> Remote   (context = client/dist)
# or:          .\deploy.ps1
```

Public URL: `http://192.168.50.12/playground/doromal/projects-assistant-tool/`

## API endpoints (flat PHP)

| Method | File | Purpose |
|---|---|---|
| GET/POST | `projects.php` | list / create project (creates `pending` metadata row) |
| GET/DELETE | `project.php?id=` | get project+metadata / delete (cascades) |
| GET/POST | `documents.php?project_id=` | list / upload `files[]` (extracts text: md/code/txt, DOCX, PDF*) |
| POST | `summary.php` | `{project_id}` → AI summary, updates metadata |
| GET/POST | `chat.php` | history / `{project_id, message}` → project-isolated AI reply |
| GET | `export.php?project_id=` | download README.md |

\* PDF needs `poppler-utils` on the server (PHP 5.3 can't parse PDFs natively); DOCX/text work without it.

## Security notes

- Gemini key and DB access are server-side only. `secret-gemini.php` is gitignored.
- ⚠️ Rotate the Gemini key shared in chat. The SFTP/DB passwords live in gitignored configs.
- The API is unauthenticated (internal LAN tool); add auth before any external exposure.
