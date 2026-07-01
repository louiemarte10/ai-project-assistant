# Deploying the Project Turnover Assistant

This is a **Vite + React** app that compiles to static files served by Apache, with a
**PHP API** in `public/api/` (copied into `dist/api/` at build time). There is **no Node
runtime on the server** — you build locally and upload `dist/`. This mirrors the
`lh_transfer_contact_v2` tool's deploy model.

---

## Server / connection facts

| | |
|---|---|
| Host | `192.168.50.12` (port 22), user `root`, password auth |
| Apache docroot | `/var/www/html` |
| Deploy folder | `/var/www/html/playground/doromal/projects-assistant-tool/` |
| Public URL | `http://192.168.50.12/playground/doromal/projects-assistant-tool/` |
| API URL | `http://192.168.50.12/playground/doromal/projects-assistant-tool/api/` |
| Database | `callbox_reports` on the `main` proxy (192.168.50.24), via `config/pipeline-x.php` |
| PHP | ~5.3 (API code is written 5.3-compatible) |

`base` in `vite.config.ts` and `VITE_API_BASE` in `.env.production` **must** match the
deploy folder URL above, or assets/API calls will 404.

---

## One-time setup on the server

1. **Gemini key:** `public/api/secret-gemini.php` (gitignored) holds the key and is copied
   into `dist/` by the build, so it uploads with everything else. Put the ROTATED key there.
2. **PDF support (optional):** PHP 5.3 can't extract PDF text. To enable it, install poppler
   on the server: `yum install -y poppler-utils` (the API auto-detects `pdftotext`).
   Without it, PDFs are skipped on upload; DOCX / text / code work regardless.

---

## Deploy — option A: VS Code SFTP extension (your usual flow)

The repo's `.vscode/sftp.json` has `context: "client/dist"`, so it uploads the built output.

1. Build first (the extension does **not** build):
   ```powershell
   cd client
   npm run build
   ```
2. `Ctrl+Shift+P` → **SFTP: Sync Local -> Remote**.

## Deploy — option B: one command (build + sync)

```powershell
cd client
.\deploy.ps1 -DryRun     # preview the diff — transfers nothing
.\deploy.ps1             # build + upload only changed files (and delete stale ones)
```

### What gets uploaded
Only the contents of `dist/`: `index.html`, `assets/` (hashed JS/CSS), and `api/` (the PHP).
Never `node_modules/`, `src/`, `package*.json`, or `.env.*`.

---

## Verify after deploy

1. API health (DB connectivity):
   `http://192.168.50.12/playground/doromal/projects-assistant-tool/api/projects.php` → `[]` or a JSON list
2. App loads (no 404s in the browser console):
   `http://192.168.50.12/playground/doromal/projects-assistant-tool/`

## Troubleshooting

- **Assets 404** — `base` in `vite.config.ts` doesn't match the deploy URL. Fix, rebuild, redeploy.
- **API 500 / blank** — check `pipeline-x.php` resolves and `callbox_reports` is reachable; the
  PHP suppresses warnings so a 500 usually means a DB/connection issue.
- **`error: AI error: ...`** — `secret-gemini.php` missing/invalid on the server, or no outbound
  HTTPS. Confirm the key file deployed into `dist/api/`.
- **PDF uploads skipped** — install `poppler-utils` (see one-time setup).
