# 🚨 CRITICAL DEVELOPER & AI ASSISTANT INSTRUCTIONS
### Project: edvora.chat — Multi-Tenant AI Chatbot SaaS for Colleges & Universities

> ⚠️ **MANDATORY FOR ALL AI AGENTS & DEVELOPERS:**
> This document defines the strict, non-negotiable operational rules for working in this workspace. Follow them in every session without exception.

---

## ⚡ CORE OPERATIONAL RULES (TOP PRIORITY)

### 1. ⚠️ LOCAL ENVIRONMENT: NO LOCAL PHP / NO LOCAL MYSQL
* **There is NO local PHP installation.** The `php` command does NOT exist in the local Windows shell. Never attempt to run local `php` CLI commands or PHP dev servers.
* **There is NO local MySQL database.** Local XAMPP MySQL is obsolete and must never be started or connected to.
* All PHP execution, unit testing, migrations, and database operations run **exclusively on the remote production VPS** (`166.1.2.112`).

### 2. 🚀 MANDATORY PRODUCTION DEPLOYMENT & GITHUB SYNC
* **Automated Unified Deployment Script:** Always deploy using the master script:
  ```powershell
  powershell -ExecutionPolicy Bypass -File .\deploy.ps1 -Message "your commit message"
  ```
  This script atomically packages the project, deploys with `sudo tar` to the VPS (`166.1.2.112`), runs migrations, sets file ownership (`critical:www-data`), reloads Apache, verifies live HTTP response, and pushes to GitHub (`origin/main`).
* **Never separate VPS deployment and GitHub sync:** They must always occur together.
* **Never declare a task or fix complete until it is deployed to the live production server and verified.**
* Make all edits locally in `c:\xampp\htdocs\edvora.chat\`, then **immediately deploy** using `deploy.ps1`.

### 3. ✅ BLANKET PERMISSION GRANTED (DO NOT ASK FOR APPROVAL)
* The user has granted **blanket pre-approval** for all local shell commands (PowerShell, `tar.exe`, `scp`, `ssh`, `curl`, file read/write).
* **DO NOT** prompt or ask the user for permission to run standard deployment, testing, or editing commands. Proceed autonomously.
* *Only* ask for permission for destructive, irreversible data loss actions (e.g. dropping entire production tables).

### 4. 🔒 SHARED VPS SAFETY & ISOLATION (`166.1.2.112`)
The production VPS hosts multiple other live domains (`agenticactivity.com`, `kidhealthcenter.com`, `legalcomplianceindia.com`, etc.).
* **Strict Path Scope:** You may ONLY create, read, edit, or delete files inside `/var/www/edvora.chat/`. Never touch anything outside this folder.
* **Database Isolation:** Connect ONLY to database `edvora_chat`. Never touch or query any other database.
* **Web Server:** Production uses **Apache 2.4** with PHP-FPM 8.2 (`unix:/run/php/php8.2-fpm.sock`). Always use `sudo systemctl reload apache2` (never restart unless necessary).

### 5. 📐 ARCHITECTURE & DESIGN COMPLIANCE
* **Architecture Rules:** Always consult [`architecture.md`](file:///c:/xampp/htdocs/edvora.chat/architecture.md) before refactoring, fixing bugs, or implementing sub-system features.
* **UI/Branding Rules:** All UI pages, components, and modals MUST follow [`BRANDING_GUIDELINES.md`](file:///c:/xampp/htdocs/edvora.chat/BRANDING_GUIDELINES.md) and link [`theme-branding.css`](file:///c:/xampp/htdocs/edvora.chat/theme-branding.css).

---

## 🚀 STANDARD PRODUCTION DEPLOYMENT WORKFLOW

Whenever code, styling, or database scripts are modified locally, execute the unified deployment script in PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy.ps1 -Message "feat/fix: description of changes"
```

### Manual Fallback (If Running Step-by-Step):
If executing manually, you MUST use `sudo` for `tar` extraction so existing files owned by `www-data` are cleanly overwritten:
1. **Package:**
   ```powershell
   tar.exe -czvf deploy_package.tar.gz app public workers BRANDING_GUIDELINES.md theme-branding.css AGENTS.md architecture.md architecture_chatbot.md architecture_department_team_management.md migrate.php deploy.ps1
   ```
2. **Upload (MANDATORY: Always use `-O` and `-o BatchMode=yes`):**
   ```powershell
   scp -O -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no deploy_package.tar.gz critical@166.1.2.112:/tmp/deploy_package.tar.gz
   ```
   > ⚠️ **CRITICAL OPENSSH / SCP RULE (NEVER OMIT `-O`):** OpenSSH 9.0+ defaults to SFTP mode. In headless Windows background execution, SFTP buffering stalls and hangs for 30+ minutes. The `-O` flag forces the legacy SCP protocol, which uploads the 3 MB package in under 3 seconds!
3. **Root Extract, Migrate, Chown, Supervisor Worker Restart & Reload:**
   ```powershell
   ssh -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no critical@166.1.2.112 "echo 'dYt2295ZBM_EgUb' | sudo -S tar -xzf /tmp/deploy_package.tar.gz -C /var/www/edvora.chat/ && php /var/www/edvora.chat/migrate.php && echo 'dYt2295ZBM_EgUb' | sudo -S chown -R critical:www-data /var/www/edvora.chat && echo 'dYt2295ZBM_EgUb' | sudo -S supervisorctl restart edvora-worker:* && echo 'dYt2295ZBM_EgUb' | sudo -S systemctl reload apache2"
   ```
4. **Git Sync (Mandatory):**
   ```powershell
   git add -A ; git commit -m "commit message" ; git push origin main
   ```

---

## 🖥️ VPS ENVIRONMENT & TECHNICAL STACK

| Property | Production Specification |
|---|---|
| **Host IP** | `166.1.2.112` |
| **SSH User** | `critical` (SSH Key: `C:/Users/Salan Khalkho/.ssh/id_ed25519`) |
| **OS** | Ubuntu 24.04 LTS (Noble) |
| **Web Root** | `/var/www/edvora.chat/public` |
| **App Root** | `/var/www/edvora.chat` |
| **Web Server** | Apache 2.4.58 (VirtualHost: `/etc/apache2/sites-available/edvora.chat.conf`) |
| **PHP Version** | PHP 8.2.31 (cli/fpm) via socket `unix:/run/php/php8.2-fpm.sock` |
| **Database** | MariaDB 10.11.14 (Database: `edvora_chat`, User: `edvora`) |
| **Cache & Queue** | Redis 7 (`127.0.0.1:6379`) |
| **Process Manager** | Supervisor (`/etc/supervisor/conf.d/edvora-worker.conf`) |
| **SSL / Certs** | Certbot HTTPS enabled |
| **Document Extraction** | `pdftotext` (poppler-utils) |

---

## 🏗️ CORE ARCHITECTURE PRINCIPLES

1. **Multi-Tenancy Isolation:** Every database query MUST include `WHERE organization_id = ?` (enforced via middleware and model scopes).
2. **Knowledge Engine (No Vector DB / No RAG):**
   * Uses MySQL `FULLTEXT` indexing against `knowledge_sources(raw_content, keywords)`.
   * Adaptive context retrieval (1 to 3 sources selected per prompt).
3. **Super Admin Controls:**
   * **Master Prompt:** Stored in `platform_config` key `master_prompt`, prepended to every AI response.
   * **LLM Manager:** Multi-provider management in `llm_providers` with `primary` and `fallback` roles; API keys encrypted with AES-256 (`LLM_ENCRYPTION_KEY`).
   * **Plan Manager:** Dynamic plan creation/editing with quotas and feature toggles.
4. **Lead Capture Engine:** Triggered via conversational events (prospectus requests, campus tour booking, counselor callback, scholarship evaluation).
5. **Subscription & Billing:** Razorpay subscription integration with `PlanGate` middleware enforcing quotas and feature flags.
6. **Navigation & Section Reset Convention (MANDATORY):** Re-clicking any left navigation sidebar item must ALWAYS act as a universal reset returning that section to its top-level root page (closing any active deep-dive consoles, detail views, conversation drawers, or modals and smoothly scrolling to top).
7. **Zero Hardcoded Tenant Data / Entity Labels (MANDATORY):** All entity names, department titles, programs, and tenant-scoped resources MUST be dynamically derived from the database / API payloads (`d.name`, `item.title`, etc.). Never hardcode static overrides or normalizations (e.g., converting dynamic department names into hardcoded strings like 'Bursar & Finance' or 'Career Cell'). Whenever you encounter any hardcoded label or hardcoded mock logic in any session, you MUST immediately correct it to dynamic resolution and explicitly inform the user.
8. **Superadmin `index.html` File Bloat Prohibition (MANDATORY):** The file `public/superadmin/index.html` is already at capacity (>3,800 lines). **NEVER add new feature code, sub-views, or massive script blocks directly into `index.html`.** All new tools, debug inspectors, dashboards, or sub-systems MUST be implemented as independent standalone HTML pages (e.g., `llm-logs.html`, `db-info.html`) or modular external JS components.

---

## 🎨 BRANDING & UI DESIGN MATRIX

All UI interfaces and components must strictly adhere to [`BRANDING_GUIDELINES.md`](file:///c:/xampp/htdocs/edvora.chat/BRANDING_GUIDELINES.md):
* **Stylesheet:** Always link [`theme-branding.css`](file:///c:/xampp/htdocs/edvora.chat/theme-branding.css).
* **Header Height:** `48px` (`.brand-header` / `#header`).
* **Sub-Panel Header Height:** `36px` (`.brand-panel-header`, font `12px` / `600`, action buttons `26px` height / `11px` font).
* **Sidebar Width:** `220px` (`.brand-sidebar` / `#sidebar`).
* **Footer Status Bar Height:** `32px` (`.brand-footer` / `#footer`).
* **Body Typography:** `12px` / `400` regular (`var(--brand-font-sans)`).

---

## 📁 SYSTEM DIRECTORY MAP

```
c:\xampp\htdocs\edvora.chat\  (Local Workspace)
/var/www/edvora.chat/         (Production Remote)
├── public/                   ← Web root (index.php, widget.js, assets)
├── app/                      ← Core PHP application
│   ├── Api/                  ← REST API controllers
│   ├── Models/               ← Database models
│   ├── Services/             ← Core services (LlmService, ContentEngine, Billing)
│   ├── Jobs/                 ← Background jobs (ProcessDocument, FetchUrlContent)
│   └── Helpers/              ← Helper functions
├── storage/                  ← Uploaded documents & file storage (uploads/, logs/)
├── workers/                  ← Supervisor worker scripts
├── BRANDING_GUIDELINES.md    ← Authoritative UI & typography guide
├── theme-branding.css        ← Production design system CSS
├── architecture.md           ← Master technical & sub-system architecture index
└── AGENTS.md                 ← This file (Core developer/agent rules)
```

---

---

## 🗄️ REMOTE DATABASE & SSH EXECUTION BEST PRACTICES

To prevent command errors, quote parsing failures, or authentication issues when inspecting or querying the production MariaDB database via SSH:

### 1. Database Authentication
* MariaDB on the VPS uses root socket authentication. Direct CLI access with `-u edvora -p...` will fail.
* Always pipe `sudo mariadb edvora_chat` or authenticate using sudo:
  ```powershell
  ssh -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o StrictHostKeyChecking=no critical@166.1.2.112 "echo '<SQL QUERY>' | sudo mariadb edvora_chat"
  ```

### 2. The Heredoc Pattern (MANDATORY for Complex or Multi-line Queries)
* In Windows PowerShell, nested quotes (`"`, `'`, `\"`) and special characters (`$`, `\`) get stripped or misinterpreted before reaching SSH, resulting in unexpected EOF or syntax errors.
* **Preferred Heredoc Pattern:** Pipe SQL using an unquoted/single-quoted remote Heredoc `cat << 'EOF' | sudo mariadb edvora_chat`:
  ```powershell
  ssh -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no critical@166.1.2.112 "cat << 'EOF' | sudo mariadb edvora_chat
  SELECT value_text FROM platform_config WHERE key_name = 'master_prompt';
  EOF"
  ```
* This completely isolates the SQL from PowerShell quote manipulation.

### 3. Verify Schema Columns First
* Always run `DESCRIBE <table>` or check `app/Database/Migrations.php` before querying to avoid column guessing.
* Common non-standard column naming conventions in `edvora_chat`:
  * `platform_config`: Uses `key_name` and `value_text` (NOT `key` / `value`, `config_key` / `config_value`).
  * `llm_providers`: Uses `model_name` (not `model`), `api_key_encrypted` (not `api_key`).
  * `messages`: Uses `role` (not `sender`), `content` (not `message`).

### 4. Tool Execution Timeout
* Set `WaitMsBeforeAsync: 8000` on SSH commands to receive synchronous execution results and prevent unnecessary background task scheduling.

---

## 📋 PRE-COMPLETION CHECKLIST FOR EVERY TASK

Before concluding any user request, verify:
- [ ] Were changes tested / executed against the remote server (not local PHP)?
- [ ] Were files bundled and deployed to `166.1.2.112` via the 3-step deployment command?
- [ ] Was MariaDB migration executed if schema was altered (`php migrate.php`)?
- [ ] Did Apache reload cleanly with `sudo systemctl reload apache2`?
- [ ] Does the UI strictly conform to [`theme-branding.css`](file:///c:/xampp/htdocs/edvora.chat/theme-branding.css)?
- [ ] Were changes committed and pushed to GitHub (`git push origin main`)?

