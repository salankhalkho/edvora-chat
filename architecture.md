# System Architecture — edvora.chat

> **MANDATORY FOR ALL AI AGENTS & HUMAN DEVELOPERS:**
> This is the canonical, authoritative technical architecture document for **edvora.chat**.
> Read this in full before touching any code. Every module''s design decision, data flow, and constraint is documented here.

---

## ⚠️ DEPRECATION NOTICE

The following sub-systems are **fully deprecated** and must **NOT** be referenced, extended, or consulted:

- ~~Department & Team Management~~ — `architecture_department_team_management.md` is **obsolete**.
- ~~Semantic Keywords (`semantic_keywords` column)~~ — **dropped** from `knowledge_sources` table.
- ~~`enrich_keywords` background job~~ — **decommissioned**.
- ~~3-Tier FULLTEXT search in `ContentEngine`~~ — **being replaced** by vector similarity retrieval.
- ~~`department_knowledge` join table~~ — **deprecated**.

---

## 1. Product Summary

**edvora.chat** is a **Multi-Tenant AI Chatbot SaaS** purpose-built for colleges and universities.
Each tenant (organization) gets one or more embeddable chatbots that answer prospective student questions in real time using the institution''s own knowledge.

---

## 2. Infrastructure

| Property | Value |
|---|---|
| **VPS** | Ubuntu 24.04 LTS — `166.1.2.112` |
| **Web Server** | Apache 2.4.58 with PHP-FPM 8.2 |
| **App Root** | `/var/www/edvora.chat/` |
| **Web Root** | `/var/www/edvora.chat/public/` |
| **PHP** | 8.2 via `unix:/run/php/php8.2-fpm.sock` (CLI/FPM) |
| **PHP-FPM Upload Envelope** | `upload_max_filesize = 60M`, `post_max_size = 65M` (in `/etc/php/8.2/fpm/php.ini`) |
| **Database** | MariaDB 10.11.14 — database `edvora_chat` |
| **Cache / Queue** | Redis 7 (`127.0.0.1:6379`) |
| **Process Manager** | Supervisor — `workers/job_runner.php` |
| **SSL** | Certbot HTTPS |
| **Document Extraction** | `pdftotext` (poppler-utils) |
| **Local Dev** | `c:\xampp\htdocs\edvora.chat\` — NO local PHP/MySQL |

---

## 3. Directory Structure

```
/var/www/edvora.chat/
├── public/                     ← Apache web root
│   ├── index.php               ← Router entry point
│   ├── widget.js               ← Embeddable chatbot widget
│   ├── app/                    ← Tenant admin SPA (HTML/JS/CSS)
│   └── superadmin/             ← Superadmin dashboard
├── app/
│   ├── Config/                 ← Database.php, Env.php
│   ├── Controllers/            ← REST API controllers
│   ├── Core/                   ← Router, Request, Response, JWT, Redis
│   ├── Database/               ← Migrations.php, Seeders.php, Backfill scripts
│   ├── Helpers/                ← AuditLogger, Validator
│   ├── Middleware/             ← Auth, Tenant, SuperAdmin middleware
│   ├── Services/               ← Core business logic services
│   └── Views/                  ← PHP landing/pricing/legal pages
├── workers/
│   └── job_runner.php          ← Supervisor background worker
├── storage/
│   ├── knowledge/              ← Filesystem-backed knowledge sources (deterministic per tenant)
│   │   └── {org_id}/           ← Tenant knowledge folder
│   │       ├── source_{id}.txt ← Clean UTF-8 text for chunking & embedding
│   │       └── original/       ← Original binary files (.pdf, .docx) for user download
│   ├── programs/               ← Auto-generated program TXT files ({org_id}/program_{id}.txt)
│   ├── uploads/                ← Temporary / legacy uploaded files
│   └── logs/                   ← Application logs
├── architecture.md             ← THIS FILE
├── AGENTS.md                   ← Mandatory agent/developer rules
├── BRANDING_GUIDELINES.md      ← UI/typography rules
├── theme-branding.css          ← Global design system CSS
├── migrate.php                 ← Migration runner (called on every deploy)
└── deploy.ps1                  ← Unified deployment script
```

---

## 4. Core Database Schema

> **Multi-Tenancy Rule:** Every table with tenant data MUST include `organization_id INT NOT NULL` and every query MUST include `WHERE organization_id = :org_id`.

### 4.1 `organizations`
Tenant root record. One row per college/university.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | |
| `name` | VARCHAR(255) | College/university name |
| `slug` | VARCHAR(255) UNIQUE | URL-safe identifier |
| `logo_url` | VARCHAR(500) | |
| `primary_color` | VARCHAR(30) | Brand hex color |
| `plan_id` | INT FK→plans | Current active plan |
| `subscription_status` | ENUM | `active`, `inactive`, `trial`, `cancelled` |

---

### 4.2 `users`
Admin and staff users per tenant.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | |
| `organization_id` | INT FK | |
| `name` | VARCHAR(255) | |
| `email` | VARCHAR(255) UNIQUE | |
| `password_hash` | VARCHAR(255) | bcrypt |
| `role` | ENUM | `superadmin`, `owner`, `admin`, `staff` |
| `can_manage_structure` | TINYINT(1) | Access gate for structural changes |

---

### 4.3 `chatbots`
Each org can have multiple chatbot instances, each with its own embed token and widget design.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | |
| `organization_id` | INT FK | |
| `name` | VARCHAR(255) | |
| `bot_token` | VARCHAR(64) UNIQUE | Used by `widget.js` to authenticate |
| `allowed_domains` | JSON | Whitelist of domains where widget is permitted |
| `system_prompt_override` | TEXT | Per-bot LLM persona override |
| `widget_style` | VARCHAR(50) | `glassmorphism`, `flat`, etc. |
| `quick_chips` | TEXT | Suggested question chips shown to visitors |

---

### 4.4 `knowledge_sources` — Source Registry
The registry of all knowledge documents, URLs, text pastes, and auto-generated program TXT files.

> **Database Lean Rule:** This table stores **metadata only**. Raw and processed text content is NEVER stored in database rows. Instead, clean UTF-8 text is persisted deterministically on the filesystem at `storage/knowledge/{organization_id}/source_{id}.txt` and loaded on-demand. Original uploaded binaries (.pdf, .docx) are saved at `storage/knowledge/{organization_id}/original/`. At runtime, the chatbot retrieves context from `knowledge_items`.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | |
| `organization_id` | INT FK | |
| `chatbot_id` | INT FK NULL | Optional scope to a specific chatbot |
| `program_id` | INT FK NULL | FK→programs if source is an auto-generated program TXT |
| `type` | ENUM | `document`, `url`, `text_paste`, `program_txt` |
| `title` | VARCHAR(255) | |
| `category` | VARCHAR(100) | e.g. `Admissions`, `Tuition & Fees`, `Campus Life` |
| `source_url` | VARCHAR(500) NULL | For `url` type |
| `file_path` | VARCHAR(500) NULL | Relative path to `storage/knowledge/{org_id}/source_{id}.txt` |
| `original_file_path` | VARCHAR(500) NULL | Relative path to original binary in `original/` folder for downloads |
| `original_file_size` | BIGINT UNSIGNED NULL | Original uploaded binary file size in bytes (documents only, NULL for text/url) |
| `file_size_bytes` | BIGINT UNSIGNED | Text file size in bytes (clean source_{id}.txt on disk) |
| `token_count` | INT UNSIGNED | Estimated word/subword token count |
| `checksum_sha256` | CHAR(64) | SHA-256 hash of clean text content |
| `keywords` | TEXT | Algorithmic frequency-ranked single-word tokens |
| `content_hash` | VARCHAR(64) | SHA-256 to detect URL content changes |
| `status` | ENUM | `pending`, `active`, `expiring_soon`, `expired`, `archived`, `failed` |
| `effective_from` | DATE | AI guardrail: invisible to chatbot before this date |
| `expires_on` | DATE | AI guardrail: invisible to chatbot after this date |
| `academic_version` | VARCHAR(50) | e.g. `2026-27`, `Evergreen` |
| `previous_version_id` | INT | Audit chain for document replacement |
| `replaced_by_id` | INT | Forward pointer to the replacement source |

**Indexes:**
- `ft_knowledge_content` — FULLTEXT on `(title, keywords)` (Tier 2 fallback)
- `idx_ks_org_validity` — `(organization_id, status, expires_on)`

---

### 4.5 `knowledge_items` — THE Universal Retrieval Layer

This is the most important table. Every fact the chatbot may ever need is stored here as a self-contained text chunk with a vector embedding. The chatbot queries ONLY this table at runtime.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | |
| `organization_id` | INT FK | Multi-tenant isolation |
| `source_id` | INT FK→knowledge_sources | Parent source registry entry |
| `program_id` | INT FK→programs NULL | Optional program scope for filtered retrieval |
| `content` | TEXT NOT NULL | Self-contained, embedding-friendly sentence/chunk |
| `page` | INT NULL | Source page number (for PDFs) |
| `embedding` | JSON NULL | Float vector array from embedding API |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

**Indexes:**
- `idx_org_source` — `(organization_id, source_id)`
- `idx_org_program` — `(organization_id, program_id)`
- `ft_item_topic_content` — FULLTEXT on `(content)` (fallback)

**CRITICAL CONTENT RULE — Self-Identifying Chunks:**
The `content` field must always include the program/document identity so the embedding captures full context:

| BAD (bare value) | GOOD (self-contained) |
|---|---|
| `2 years` | `MBA Program — Duration: 2 years.` |
| `$32,000 USD` | `MBA Program — Tuition: $32,000 USD.` |
| `Full-time` | `MBA Program — Study mode: Full-time.` |
| `Applications close May 1` | `MBA Program — Application deadline: May 1, 2027.` |

---

### 4.6 `programs`
Structured academic program records. Source of truth for program data. When created or updated, triggers auto-generation of `knowledge_sources` + `knowledge_items`.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | |
| `organization_id` | INT FK | |
| `course_name` | VARCHAR(255) | e.g. "Master of Business Administration" |
| `degree_type` | VARCHAR(100) | e.g. "Master''s", "Bachelor''s", "PhD" |
| `department_name` | VARCHAR(255) | e.g. "Business Administration" |
| `duration` | VARCHAR(100) | e.g. "2 years" |
| `tuition_fee` | VARCHAR(255) | e.g. "$32,000 USD per year" |
| `mode` | VARCHAR(100) | e.g. "Full-time", "Part-time", "Online" |
| `intake_months` | VARCHAR(255) | e.g. "January, September" |
| `eligibility` | TEXT | Admission requirements |
| `description` | TEXT | General program description |

---

### 4.7 Other Supporting Tables

| Table | Purpose |
|---|---|
| `conversations` | Per-visitor chat session with UTM and lead data |
| `messages` | Per-turn message log with `knowledge_sources_used` JSON |
| `leads` | Captured visitor contact details |
| `jobs` | Background job queue (`type`, `payload`, `status`, `run_at`) |
| `llm_providers` | Multi-provider LLM config with AES-256 encrypted API keys |
| `platform_config` | Global key-value store (master prompt, encryption keys, etc.) |
| `plans` | Subscription plan definitions |
| `plan_quotas` | Per-plan usage limits (messages, chatbots, etc.) |
| `plan_features` | Per-plan feature toggles |
| `subscriptions` | Per-org Razorpay subscription records |
| `usage_logs` | Monthly per-org usage aggregation |
| `audit_logs` | Full action audit trail |
| `widget_customizations` | Fine-grained per-chatbot widget design JSON blob |

---

## 5. Knowledge Ingestion Pipelines

### 5.1 Program Ingestion (Auto-triggered on create/update)

```
programs row  (created or updated via ProgramController)
      |
      v
ProgramTextGenerator::generate($program)
      |  Produces self-contained embedding-friendly sentences, e.g.:
      |  "MBA is a Master's program in Business Administration."
      |  "MBA Program — Duration: 2 years."
      |  "MBA Program — Tuition: $32,000 USD."
      |  "MBA Program — Study mode: Full-time."
      |  "MBA Program — Application deadline: May 1, 2027."
      |
      v
Write TXT file → storage/programs/{org_id}/program_{id}.txt
      |
      v
INSERT knowledge_sources  (type='program_txt', program_id=X, status='processing')
      |
      v
INSERT jobs  { type: 'embed_program', payload: { program_id, source_id } }
      |
      v  [Supervisor picks up job from jobs table]
      |
KnowledgeChunker::chunkText($txt)  →  string[]  (one sentence per chunk)
      |
EmbeddingService::embed($chunk)  →  float[]  (vector)
      |
INSERT knowledge_items  (org_id, source_id, program_id, content, embedding)
      |
UPDATE knowledge_sources  SET status='active'
```

**Incremental Update Rule:** When a `programs` row is updated, ONLY the chunks that changed are re-embedded. Changed `knowledge_items` rows are deleted and re-inserted. Unchanged facts are untouched.

---

### 5.2 Document / PDF / URL / Text Paste Ingestion (Filesystem-Backed)

```
Admin uploads PDF/DOCX, Pastes Text, or Enters URL  →  KnowledgeController
      |
      v
DocumentParser::extract($file)    [pdftotext for PDFs]
  or  UrlScraper::scrape($url)
  or  DocumentParser::sanitizeText($paste)
      |  → raw extracted text string
      |
      v
ContentCompactor::process($raw)   → clean normalized UTF-8 text + keywords
      |
      v
INSERT knowledge_sources  (title, type, category, status='pending')
      |  → creates row and generates primary key {id}
      |
      v
KnowledgeFileStorage::saveText($orgId, $id, $cleanText)
      |  → writes deterministically to storage/knowledge/{org_id}/source_{id}.txt
      |  → writes uploaded original binary to storage/knowledge/{org_id}/original/
      |  → computes file_size_bytes, token_count, checksum_sha256
      v
UPDATE knowledge_sources  SET file_path, original_file_path, file_size_bytes, token_count, checksum_sha256
      |
      v
INSERT jobs  { type: 'chunk_and_embed', payload: { source_id, organization_id } }
      |
      v  [Supervisor picks up job via workers/job_runner.php]
      |
KnowledgeFileStorage::loadText($orgId, $sourceId)  →  reads clean text directly from disk
      |
      v
KnowledgeChunker::chunkText($text)  →  string[]  (300–500 char semantic chunks)
      |
      v
EmbeddingService::embed($chunk)  →  float[]  (1536-dim vector via OpenAI text-embedding-3-small)
      |
      v
INSERT knowledge_items  (org_id, source_id, program_id=null, content, embedding)
      |
      v
UPDATE knowledge_sources  SET status='active'
```

---

### 5.3 Dynamic Per-Plan File Upload Limits & Server Envelope (MANDATORY ARCHITECTURE)

Edvora enforces strict multi-tenant plan gating for file uploads. Each plan offers a distinct maximum document size configured dynamically in the `plan_quotas` table:

| Plan Tier | Quota Key (`plan_quotas`) | Default Document Limit | Total Storage Limit (`total_knowledge_mb`) |
|---|---|---|---|
| **Starter** | `max_file_upload_mb` | **15 MB** | 250 MB |
| **Growth** | `max_file_upload_mb` | **25 MB** | 1,024 MB (1 GB) |
| **Pro** | `max_file_upload_mb` | **50 MB** | 5,120 MB (5 GB) |

#### ⚠️ VPS Server-Level Envelope Requirement (Critical for Server Migration / Rebuild)
Web servers (PHP-FPM) drop multipart payloads before reaching PHP application code if they exceed the PHP ini directives. To ensure the application layer can enforce per-plan limits dynamically without infrastructure interference:
1. **PHP-FPM (`/etc/php/8.2/fpm/php.ini`) must maintain a server-level envelope exceeding the highest tier:**
   ```ini
   upload_max_filesize = 60M
   post_max_size = 65M
   ```
2. **Reload Command (when migrating or provisioning VPS):**
   ```bash
   sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 60M/' /etc/php/8.2/fpm/php.ini
   sudo sed -i 's/^post_max_size = .*/post_max_size = 65M/' /etc/php/8.2/fpm/php.ini
   sudo systemctl reload php8.2-fpm && sudo systemctl reload apache2
   ```

#### 3-Tier Enforcement Flow:
1. **Infrastructure Envelope (VPS):** Accepts requests up to 60 MB without early socket termination.
2. **Backend Gate (`KnowledgeController.php`):**
   - Inspects `$_FILES['file']['error']` and maps standard PHP error codes (`UPLOAD_ERR_INI_SIZE`, `UPLOAD_ERR_PARTIAL`, etc.) to informative HTTP 413 / 400 responses.
   - Resolves tenant's active plan quota from `plan_quotas` via `organizations.plan_id`.
   - Rejects files exceeding `max_file_upload_mb` with HTTP 422:
     > *"File size (X.X MB) exceeds your [Plan Name] plan limit of [Y] MB per document. Please compress the file or upgrade your plan."*
   - Also enforces `max_knowledge_sources` before writing to disk or dispatching jobs.
3. **Frontend UX & Pre-validation (`/v1/auth/me`, `knowledge-ingestion.html`, `app-core.js`):**
   - `/v1/auth/me` supplies the tenant's plan quotas in the `organization.quotas` payload.
   - The dropzone UI updates dynamically (`"Supports up to {max_file_upload_mb} MB documents"`).
   - Instant client-side validation runs on file selection and form submission, giving instant feedback without network delays.

---

### 5.4 URL Refresh

When an admin manually refreshes a URL source:
1. Re-scrape the URL.
2. Compare `content_hash` — if unchanged, touch `last_fetched_at` and return.
3. If changed: delete existing `knowledge_items` for that `source_id`, re-chunk, re-embed, re-insert.

---

## 6. Query-Time Retrieval Pipeline (Chat Turn)

```
Visitor message: "How long is the MBA program?"
      |
      v
ChatController::handleMessage()
      |
      v
IntentClassifier::classify($message)
  |-- TIER_GREETING        → return greeting template, skip retrieval
  |-- TIER_CLARIFICATION   → return clarification prompt, skip retrieval
  `-- TIER_KNOWLEDGE       → proceed to vector retrieval
      |
      v
EmbeddingService::embed($userMessage)  →  float[]  (question vector)
      |
      v
VectorSearchEngine::findClosest(
    embedding  = $questionVector,
    org_id     = $organizationId,
    program_id = $detectedProgramId ?? null,   ← optional scoping
    limit      = 5
)
  |  Fetches embedding column from knowledge_items for org
  |  Computes cosine similarity in PHP
  |  Returns top-5 items sorted by similarity score
      |
      v
Top-5 knowledge_items.content strings
      |
      v
PromptBuilder::build()
  |-- Master Prompt         (platform_config key: 'master_prompt')
  |-- Bot Override Prompt   (chatbots.system_prompt_override)
  |-- Knowledge Context Block:
  |     "--- KNOWLEDGE BASE CONTEXT ---
  |      MBA Program — Duration: 2 years.
  |      MBA Program — Tuition: $32,000 USD.
  |      MBA Program — Study mode: Full-time.
  |      --- END KNOWLEDGE BASE CONTEXT ---"
  |-- Lead Capture Instructions
  `-- Campus Tour Recommendation Engine
      |
      v
LlmService::complete($systemPrompt, $conversationHistory)
  |-- Primary LLM provider
  `-- Fallback LLM provider on failure
      |
      v
Response → visitor browser  +  logged in messages table
```

---

## 7. Program-Scoped Retrieval

When `ProgramDetector` identifies the visitor is asking about a specific program, the vector search is scoped:

```sql
SELECT content, embedding
FROM knowledge_items
WHERE organization_id = :org_id
  AND program_id = :detected_program_id
```

This prevents MBA answers from mixing with BBA or BCA answers. If no program is detected, the search runs across the full org knowledge base.

---

## 8. LLM Provider Management

| Field | Notes |
|---|---|
| `provider` | `openai`, `gemini`, `groq`, `anthropic` |
| `role` | `primary`, `fallback`, `inactive` |
| `api_key_encrypted` | AES-256 encrypted; key stored in `platform_config.LLM_ENCRYPTION_KEY` |
| `model_name` | e.g. `gpt-4o-mini`, `gemini-2.0-flash` |

`LlmService::complete()` tries `primary` first, falls back to `fallback` on timeout or API error. All LLM errors are logged.

---

## 9. Background Job Worker

Supervisor runs `workers/job_runner.php` as a persistent process.

**Active Job Types:**

| Job Type | Trigger | Action |
|---|---|---|
| `chunk_and_embed` | New document/URL/text source | Chunks text, embeds, writes `knowledge_items` |
| `embed_program` | Program created or updated | Generates TXT, chunks, embeds, writes `knowledge_items` |
| `evaluate_content_health` | Scheduled | Marks sources as `expired` or `expiring_soon` |
| `fetch_url` | URL source created | Scrapes URL content |

**Retry Schedule:** 30 min → 60 min → 5 hr → permanently `failed`.

---

## 10. Multi-Tenant Security

- Every SQL query includes `WHERE organization_id = :org_id`.
- `TenantMiddleware` validates JWT and injects `organization_id` into every API request context.
- Widget requests authenticate via `bot_token` only.
- `chatbots.allowed_domains` JSON array prevents widget loading on unauthorized domains.

---

## 11. Billing & Plan Enforcement

- Plans in `plans` + quotas in `plan_quotas` + toggles in `plan_features`.
- `PlanGate` middleware enforces per-org message quotas, chatbot count limits, and feature access.
- Razorpay webhooks update `subscriptions` and `organizations.subscription_status` on payment events.

---

## 12. Lead Capture Engine

Triggered during chat by conversational events:
- Prospectus download request
- Campus tour booking
- Counselor callback request
- Scholarship eligibility check

On trigger: chatbot collects name, email, phone from visitor → saved to `leads` table.

---

## 13. Deployment

All changes are deployed using the master script:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy.ps1 -Message "description"
```

Steps:
1. Packages `app/`, `public/`, config files into `deploy_package.tar.gz`
2. SCPs to VPS `/tmp/`
3. SSH extracts with `sudo tar` (overwriting `www-data`-owned files)
4. Runs `php /var/www/edvora.chat/migrate.php`
5. `sudo chown -R critical:www-data /var/www/edvora.chat`
6. `sudo systemctl reload apache2`
7. Verifies live HTTP 200
8. `git push origin main`

---

## 14. Key Service File Map

| File | Responsibility |
|---|---|
| [`app/Services/EmbeddingService.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/EmbeddingService.php) | Calls embedding API, returns float[] vector — TO BE CREATED |
| [`app/Services/VectorSearchEngine.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/VectorSearchEngine.php) | Cosine similarity search against `knowledge_items` — TO BE CREATED |
| [`app/Services/KnowledgeChunker.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/KnowledgeChunker.php) | Splits text into self-contained sentence chunks — TO BE CREATED |
| [`app/Services/ProgramTextGenerator.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/ProgramTextGenerator.php) | Converts `programs` row to embedding-friendly text — TO BE CREATED |
| [`app/Services/ContentEngine.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/ContentEngine.php) | FULLTEXT search (current fallback during migration window) |
| [`app/Services/ContentCompactor.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/ContentCompactor.php) | Cleans raw text, extracts keywords |
| [`app/Services/LlmService.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/LlmService.php) | Multi-provider LLM completion with primary/fallback |
| [`app/Services/PromptBuilder.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/PromptBuilder.php) | Assembles system prompt from master prompt + knowledge context |
| [`app/Services/IntentClassifier.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/IntentClassifier.php) | Classifies user message tier (greeting/clarification/knowledge) |
| [`app/Services/ProgramDetector.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/ProgramDetector.php) | Detects which program a visitor is asking about |
| [`app/Controllers/KnowledgeController.php`](file:///c:/xampp/htdocs/edvora.chat/app/Controllers/KnowledgeController.php) | REST API for knowledge source CRUD and ingestion |
| [`app/Controllers/ProgramController.php`](file:///c:/xampp/htdocs/edvora.chat/app/Controllers/ProgramController.php) | REST API for programs — triggers auto-embedding on save |
| [`app/Controllers/ChatController.php`](file:///c:/xampp/htdocs/edvora.chat/app/Controllers/ChatController.php) | Chat turns: embedding → retrieval → prompt → LLM → response |
| [`app/Database/Migrations.php`](file:///c:/xampp/htdocs/edvora.chat/app/Database/Migrations.php) | Idempotent schema creation and ALTER TABLE migrations |
| [`workers/job_runner.php`](file:///c:/xampp/htdocs/edvora.chat/workers/job_runner.php) | Supervisor background worker — handles all job types |

---

## 15. UI / Branding Rules

All UI must follow [`BRANDING_GUIDELINES.md`](file:///c:/xampp/htdocs/edvora.chat/BRANDING_GUIDELINES.md) and link [`theme-branding.css`](file:///c:/xampp/htdocs/edvora.chat/theme-branding.css):

| Element | Spec |
|---|---|
| Header height | 48px |
| Sub-panel header | 36px height, 12px/600 font, 26px action buttons |
| Sidebar width | 220px |
| Footer status bar | 32px height |
| Body typography | 12px/400, `var(--brand-font-sans)` |

---

*Last updated: September 2026.*
