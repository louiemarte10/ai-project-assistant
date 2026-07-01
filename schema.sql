-- =============================================================================
-- Project Turnover Summary & Debugging Assistant
-- MySQL 8 schema.
--
-- On Callbox infra these tables already live in the `callbox_reports` database
-- on the "main" MaxScale proxy (192.168.50.24). This script is NON-DESTRUCTIVE
-- (CREATE TABLE IF NOT EXISTS, no DROP, no CREATE DATABASE) so it is safe to run
-- against that shared database — it will not touch existing tables or data.
--
-- Usage (target an existing database):
--   mysql -u <user> -p callbox_reports < schema.sql
-- =============================================================================

-- -----------------------------------------------------------------------------
-- projects — master record for each handed-over project
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
  project_id       INT             NOT NULL AUTO_INCREMENT,
  project_name     VARCHAR(255)    NOT NULL,
  -- JSON array of repository URLs, e.g. ["https://github.com/org/api","https://github.com/org/portal"].
  -- Docs (.md/.env) from every repo in the array are imported into the project.
  repository_url   JSON            NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For an EXISTING projects table (callbox_reports), convert repository_url to JSON
-- (the column should be empty or hold valid JSON first) and drop the old 2nd column:
--   ALTER TABLE projects MODIFY repository_url JSON NULL;
--   ALTER TABLE projects DROP COLUMN repository_url_2;   -- if it was added earlier
-- (The PHP stores/reads a JSON array either way, so it also works on the old VARCHAR.)

-- -----------------------------------------------------------------------------
-- project_metadata — AI-generated technical summary for a project
-- (server_location / tech_stack / functional_purpose are filled by the AI summary)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_metadata (
  meta_id             INT     NOT NULL AUTO_INCREMENT,
  project_id          INT     NOT NULL,
  server_location     VARCHAR(255) NOT NULL,
  tech_stack          TEXT    NOT NULL,
  functional_purpose  TEXT    NOT NULL,
  -- AI-generated markdown overview shown under the summary cards (persisted so
  -- it survives a page refresh).
  overview            LONGTEXT NULL,
  -- Gemini token usage for the latest summary generation (usageMetadata JSON),
  -- e.g. {"totalTokenCount":13218,"promptTokenCount":8335,"thoughtsTokenCount":3125,
  --        "promptTokensDetails":[{"modality":"TEXT","tokenCount":8335}],"candidatesTokenCount":1758}
  usage_meta_data     JSON NULL,
  -- UTC timestamp of the last AI summary generation (rendered in UTC+8 in the UI).
  generated_at        DATETIME NULL,
  PRIMARY KEY (meta_id),
  UNIQUE KEY uq_metadata_project (project_id),          -- one metadata row per project
  CONSTRAINT fk_metadata_project
    FOREIGN KEY (project_id) REFERENCES projects (project_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For an EXISTING project_metadata table (callbox_reports), add the columns with:
--   ALTER TABLE project_metadata ADD COLUMN overview LONGTEXT NULL AFTER functional_purpose;
--   ALTER TABLE project_metadata ADD COLUMN usage_meta_data JSON NULL AFTER overview;
--   ALTER TABLE project_metadata ADD COLUMN generated_at DATETIME NULL AFTER usage_meta_data;

-- -----------------------------------------------------------------------------
-- project_documents — raw uploaded docs/source, extracted to text for the AI
-- (additive table; the AI summary & chat are grounded on these rows)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_documents (
  document_id   INT           NOT NULL AUTO_INCREMENT,
  project_id    INT           NOT NULL,
  file_name     VARCHAR(255)  NOT NULL,
  -- For text docs: original filename. For images: absolute path on the server's
  -- uploads/ dir (the bytes are read from there and sent to Gemini).
  file_path     VARCHAR(512)  NOT NULL,
  -- Extracted text (text/code/csv/docx/pdf/xlsx). Empty for image rows.
  content_text  LONGTEXT      NOT NULL,
  -- MIME type; "image/*" marks an image row (read from file_path for the AI).
  mime_type     VARCHAR(100)  DEFAULT NULL,
  byte_size     INT           NOT NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (document_id),
  KEY idx_documents_project (project_id),
  CONSTRAINT fk_documents_project
    FOREIGN KEY (project_id) REFERENCES projects (project_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For an EXISTING project_documents table (callbox_reports), add the column with:
--   ALTER TABLE project_documents ADD COLUMN mime_type VARCHAR(100) DEFAULT NULL AFTER content_text;

-- -----------------------------------------------------------------------------
-- chat_conversations — separate chat threads per project (+ per agent), each
-- titled from its first question (like ChatGPT conversations).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_conversations (
  conversation_id INT          NOT NULL AUTO_INCREMENT,
  project_id      INT          NOT NULL,
  user_id         INT          DEFAULT NULL,         -- the agent who owns the thread
  title           VARCHAR(255) NOT NULL DEFAULT 'New chat',
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (conversation_id),
  KEY idx_conv_project_user (project_id, user_id),
  CONSTRAINT fk_conv_project
    FOREIGN KEY (project_id) REFERENCES projects (project_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- chat_logs — messages, grouped into conversations (conversation_id).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_logs (
  message_id       INT                    NOT NULL AUTO_INCREMENT,
  project_id       INT                    NOT NULL,
  conversation_id  INT                    DEFAULT NULL,  -- which thread this belongs to
  sender_role      ENUM('user','ai')      NOT NULL,
  message_payload  TEXT                   NOT NULL,
  -- token_used: PHP login session id (session_id()) of the px_login session that
  -- sent the message.
  token_used       VARCHAR(64)            DEFAULT NULL,
  -- user_id: the logged-in agent's id (callbox_pipeline2.users.user_id).
  user_id          INT                    DEFAULT NULL,
  -- Gemini token usage for this message (usageMetadata JSON; set on AI rows).
  usage_meta_data  JSON                   NULL,
  timestamp        TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id),
  KEY idx_chat_project_time (project_id, timestamp),    -- fast per-project history fetch
  KEY idx_chat_token (token_used),
  KEY idx_chat_user (user_id),
  KEY idx_chat_conversation (conversation_id),
  CONSTRAINT fk_chat_project
    FOREIGN KEY (project_id) REFERENCES projects (project_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For the EXISTING callbox_reports DB, add conversation support with:
--   CREATE TABLE chat_conversations (
--     conversation_id INT NOT NULL AUTO_INCREMENT,
--     project_id INT NOT NULL,
--     user_id INT DEFAULT NULL,
--     title VARCHAR(255) NOT NULL DEFAULT 'New chat',
--     created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     updated_at TIMESTAMP NULL DEFAULT NULL,
--     PRIMARY KEY (conversation_id),
--     KEY idx_conv_project_user (project_id, user_id),
--     CONSTRAINT fk_conv_project FOREIGN KEY (project_id) REFERENCES projects (project_id) ON DELETE CASCADE ON UPDATE CASCADE
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--   ALTER TABLE chat_logs ADD COLUMN conversation_id INT DEFAULT NULL AFTER project_id, ADD KEY idx_chat_conversation (conversation_id);
-- (Earlier chat_logs migrations: CHANGE session_token token_used; ADD user_id; ADD usage_meta_data.)

-- ── Per-user AI API keys (run this in phpMyAdmin; app_pipe can't ALTER/CREATE) ──
CREATE TABLE IF NOT EXISTS api_key_by_user (
  id           INT NOT NULL AUTO_INCREMENT,
  user_id      INT NOT NULL,
  ai_model     VARCHAR(64) NOT NULL DEFAULT 'gemini-2.5-flash',
  api_key      VARCHAR(255) NOT NULL,
  create_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expiration   DATETIME NULL DEFAULT NULL,
  x            ENUM('active','deleted') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  KEY idx_apikey_user (user_id, x)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
