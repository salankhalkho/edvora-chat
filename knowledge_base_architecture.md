# Knowledge Base & LLM-Augmented Keyword Architecture

This document describes the design and implementation of the Knowledge Base system in **edvora.chat**, specifically detailing content processing, document validity & freshness lifecycle, LLM semantic keyword enrichment, database indexing, and 3-tier context retrieval.

---

## 📑 System Overview

The Knowledge Base architecture uses a **hybrid, asynchronous enrichment and temporal validity model**:
1. **Synchronous Ingestion & Validity Setting**: Uploads, URL scraping, or text pastes immediately extract content, set category, academic version (`2026-27`), effective date, and expiration date (`expires_on`), returning a fast `201 Created` response.
2. **Temporal AI Guardrails**: Query retrieval in `ContentEngine.php` strictly verifies:
   ```sql
   AND status = 'active'
   AND (effective_from IS NULL OR effective_from <= CURDATE())
   AND (expires_on IS NULL OR expires_on >= CURDATE())
   ```
   Expired or future-dated sources are instantly invisible to visitor chatbots in real-time.
3. **Asynchronous LLM Enrichment**: A background worker process calls the primary chat LLM to extract **10–15 intent-aligned 2–5 word phrases** (e.g., `"hostel fee per year"`, `"MBA admission eligibility"`) and stores them in `semantic_keywords`.
4. **3-Tier Context Retrieval**: At query time, user questions are matched against `semantic_keywords` using a dedicated MySQL `FULLTEXT` index, falling back gracefully to title/algorithmic keywords if enrichment is still in progress.
5. **1-Click Expiration & Replacement Workflow**: Administrators can replace outdated documents with 1 click (`/v1/knowledge/{id}/replace`), archiving the old version, adopting department bindings, and updating academic versions with full audit lineage (`previous_version_id` / `replaced_by_id`).

---

## 🗄️ Database Schema & Indexing

### 1. `knowledge_sources` Table Structure

| Column | Data Type | Description |
|---|---|---|
| `id` | `INT` (PK) | Primary Key |
| `organization_id` | `INT` | Foreign Key to `organizations` |
| `category` | `VARCHAR(100)` | Document category (e.g. Admissions, Fees, Scholarships, Placements) |
| `academic_version` | `VARCHAR(50)` | Academic cycle (e.g. `2026-27`, `2025-26`, `Evergreen`) |
| `effective_from` | `DATE` | Date from which the content is active for visitor AI |
| `expires_on` | `DATE` | Expiration date; once reached, excluded from AI responses |
| `last_reviewed_at` | `DATE` | Timestamp of last manual administrative audit |
| `review_frequency_days` | `INT` | Review cycle (e.g. 90, 180, 365 days) |
| `previous_version_id` | `INT` | ID of predecessor document version |
| `replaced_by_id` | `INT` | ID of successor document version |
| `title` | `VARCHAR(255)` | Source title or document name |
| `raw_content` | `LONGTEXT` | Unedited extracted text |
| `processed_content` | `LONGTEXT` | Compacted text used for LLM context building |
| `keywords` | `TEXT` | Algorithmic, high-frequency single-word tokens (immediate fallback) |
| `semantic_keywords` | `TEXT` | **LLM-generated 2–5 word strategic phrase tags (primary match)** |
| `status` | `ENUM` | `processing`, `active`, `expiring_soon`, `expired`, `archived`, `failed` |

### 2. Indexes & FULLTEXT Matching

- **`ft_semantic_keywords`** on `(semantic_keywords)`: Primary index used for **Tier 1 (Intent-Phrase) Search**.
- **`ft_knowledge_content`** on `(title, keywords, processed_content)`: Secondary index used for **Tier 2 (Fallback) Search**.
- **`idx_ks_org_validity`** on `(organization_id, status, expires_on)`: Composite index for fast temporal filtering.
- **`idx_ks_category`** on `(organization_id, category)`: Scoped category index.

---

## 🔄 End-to-End Life Cycle & Data Flow

```
[1. INGESTION PHASE] (Synchronous ~ms)
   User uploads PDF / pastes text / submits URL with Category & Expiry Date
     └─► KnowledgeController processes raw content, sets validity dates, and extracts basic `keywords`
     └─► Saves to `knowledge_sources` with status = 'active'
     └─► Inserts job `enrich_keywords` into `jobs` table (run_at = NOW())
     └─► Returns HTTP 201 Created response immediately

[2. ASYNC ENRICHMENT & HEALTH MONITORING] (Background Supervisor Worker)
   Supervisor process (`job_runner.php`):
     ├─► Job `enrich_keywords`: Extracts 10-15 high-intent phrases via LLM
     └─► Job `evaluate_content_health`: Automatically flags items expiring within 30 days or expired

[3. RETRIEVAL PHASE] (At Chat Turn ~ms)
   User sends a message to the Chatbot
     └─► ContentEngine::selectContext() applies Temporal Guardrail:
           WHERE status = 'active' 
           AND (effective_from IS NULL OR effective_from <= CURDATE())
           AND (expires_on IS NULL OR expires_on >= CURDATE())
     └─► Runs 3-Tier Search against validated active documents
           ├─► Tier 1: MATCH(semantic_keywords) AGAINST(user_query) [Primary]
           ├─► Tier 2: MATCH(title, keywords, processed_content) [Fallback]
           └─► Tier 3: LIKE search [Last Resort]
     └─► Selects top 1-3 matching sources (score >= 30% of max score)
     └─► PromptBuilder injects `processed_content` into Master Prompt
     └─► Primary LLM generates accurate, fresh visitor answer
```

---

## 🚀 1-Click Version Replacement & Audit Lineage

When an admission cycle concludes or fees are updated:
1. Admin clicks **[ 🔄 Replace ]** on the outdated source.
2. Selects new file, text, or URL, and specifies the new Academic Version (e.g. `2027-28`) and expiration date.
3. The API `/v1/knowledge/{id}/replace`:
   - Sets previous source: `status = 'archived'`, `replaced_by_id = new_id`.
   - Creates new source: `status = 'active'`, `previous_version_id = old_id`.
   - Clones all department bindings in `department_knowledge`.
   - Dispatches background semantic enrichment job.
   - Preserves complete document audit history for accreditation review.

---

## 📁 Key File Map

| File Path | Function / Responsibility |
|---|---|
| [`app/Database/Migrations.php`](file:///c:/xampp/htdocs/edvora.chat/app/Database/Migrations.php) | Adds validity columns, enum updates, and composite indexes to `knowledge_sources` |
| [`app/Services/ContentEngine.php`](file:///c:/xampp/htdocs/edvora.chat/app/Services/ContentEngine.php) | Enforces temporal guardrails (`effective_from` & `expires_on`) across search tiers |
| [`app/Controllers/KnowledgeController.php`](file:///c:/xampp/htdocs/edvora.chat/app/Controllers/KnowledgeController.php) | Handles `healthSummary()`, `replaceVersion()`, `extendValidity()`, `markReviewed()`, and `archive()` |
| [`public/app/index.html`](file:///c:/xampp/htdocs/edvora.chat/public/app/index.html) | Content Health KPI strip, smart filter pills, validity date pickers with presets, and Replace Modal |
| [`workers/job_runner.php`](file:///c:/xampp/htdocs/edvora.chat/workers/job_runner.php) | Background worker loop handling `enrich_keywords` and `evaluate_content_health` |
