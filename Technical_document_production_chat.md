# Technical Architecture & Data Ingestion Specification: edvora.chat

This document details the end-to-end operational and technical architecture of **edvora.chat**, explaining:
1. How documents and knowledge are ingested, parsed, compacted, and stored in the database (`https://edvora.chat/app/#knowledge-ingestion`).
2. How the conversational chat runtime processes visitor messages and orchestrates knowledge retrieval, intent classification, LLM response generation, and lead capture (`https://edvora.chat/test_chat.html?token=xxxx` and production widgets).
3. All involved database tables, crucial fields, and their architectural roles.

---

## 1. High-Level System Architecture Flow

```
┌──────────────────────────────────────────────────────────────────────────┐
│                   STAGE 1: KNOWLEDGE INGESTION ENGINE                    │
│   (https://edvora.chat/app/#knowledge-ingestion / KnowledgeController)   │
└──────────────────────────────────────────────────────────────────────────┘
      │
      ├── [1] Document Upload (.pdf, .docx, .txt) ──> pdftotext / DocumentParser
      ├── [2] Web URL Crawler & Scraper           ──> cURL / UrlScraper
      └── [3] Manual Text Paste / Raw Markdown    ──> Sanitize UTF-8
                                 │
                                 ▼
                     ContentCompactor::process()
                     ├── Deduplicates boilerplate & cleans formatting
                     ├── Produces: processed_content (dense text for LLM)
                     └── Produces: keywords (algorithmic term frequency)
                                 │
                                 ▼
                     Saved into MariaDB: knowledge_sources
                                 │
                                 ▼
         Async Background Job via Redis/Supervisor (enrich_keywords)
         └── Calls LLM to generate semantic_keywords (natural intent phrases)
                                 │
                                 ▼
                     Fulltext Indexes Ready for Querying

============================================================================

┌──────────────────────────────────────────────────────────────────────────┐
│                   STAGE 2: CHAT RUNTIME PIPELINE                         │
│      (https://edvora.chat/test_chat.html / ChatController::complete)     │
└──────────────────────────────────────────────────────────────────────────┘
      │
      ▼
Visitor sends message via `test_chat.html` / `widget.js`
      │
      ▼
1. Validation & Multi-Tenant Authentication (bot_token ──> chatbots table)
2. Session Resolution (conversations table: finds or inserts conversation session)
3. Persist User Message (messages table: role = 'user')
4. Intent Classification (IntentClassifier: Conversational / Clarification / Knowledge Query)
      │
      ├── If Knowledge Query:
      │   ContentEngine::selectContext() performs 3-tier retrieval:
      │   ├── Tier 1: FULLTEXT MATCH against semantic_keywords (User query intent phrases)
      │   ├── Tier 2: FULLTEXT MATCH against title + keywords + processed_content
      │   └── Tier 3: LIKE search across title, keywords, semantic_keywords, processed_content
      │   Adaptive Selection: Top 1–3 sources (score >= 30% of top score)
      │
5. System Prompt Assembly (PromptBuilder: Master Prompt + Bot Override + Retrieved processed_content + Counselor Brain)
6. LLM Inference (LlmService: Primary provider with auto-fallback to backup provider)
7. Lead Trigger Evaluation (Detects and parses [LEAD_TRIGGER:type] tags for brochure/callbacks)
8. Persist Assistant Response (messages table: role = 'assistant', logs tokens & knowledge_sources_used)
9. Return JSON Response to frontend widget
```

---

## 2. Deep Dive: Knowledge Ingestion Engine (`/app/#knowledge-ingestion`)

The knowledge ingestion system is managed by [`App\Controllers\KnowledgeController`](file:///c:/xampp/htdocs/edvora.chat/app/Controllers/KnowledgeController.php). It accepts data via 3 primary entry channels:

### A. Ingestion Channels
1. **Document Upload (`POST /v1/knowledge/upload`)**:
   - Accepts `.pdf`, `.docx`, and `.txt` files.
   - Saved locally to `/storage/uploads/` with unique tenant-prefixed filenames (`doc_{orgId}_{timestamp}_{hash}.ext`).
   - Parsed by [`DocumentParser`](file:///c:/xampp/htdocs/edvora.chat/app/Services/DocumentParser.php). PDFs are extracted on the VPS using binary extraction tools (`pdftotext` from `poppler-utils`).
2. **URL Crawling/Scraping (`POST /v1/knowledge/url`)**:
   - [`UrlScraper`](file:///c:/xampp/htdocs/edvora.chat/app/Services/UrlScraper.php) downloads web content via cURL, removes HTML boilerplate (`<script>`, `<style>`, navigation bars, footers), and computes an MD5 `content_hash` for change detection.
3. **Text Paste (`POST /v1/knowledge/paste`)**:
   - Directly accepts raw text, FAQs, policy guides, fee schedules, or admissions guidelines.

### B. Dual-Content Architecture & Compaction
Rather than stuffing raw HTML/PDF dumps into the LLM context window, edvora.chat uses a split dual-content model:
- **`raw_content`**: The 100% complete, unedited source text preserved for auditing and manual source review. It is **never** injected into the LLM context prompt directly.
- **`processed_content`**: Generated synchronously by [`ContentCompactor::process()`](file:///c:/xampp/htdocs/edvora.chat/app/Services/ContentCompactor.php). This routine strips cookie notices, sitemaps, redundant headers, blank lines, and duplicates. The result is a high-density, concise representation optimized to minimize token consumption and maximize LLM comprehension.
- **`keywords`**: Single words and tokens extracted algorithmically using term-frequency heuristics with stopword filtering (e.g., `admissions`, `btech`, `scholarship`, `hostel`).
- **`semantic_keywords`**: Generated asynchronously via a queue job (`enrich_keywords`) where an LLM creates 2- to 5-word phrases matching how students actually query the bot (e.g., *"hostel fee per semester"*, *"minimum JEE cutoff for CSE"*).

---

## 3. Deep Dive: Chat Runtime (`test_chat.html?token=xxxx`)

When testing a chatbot at `https://edvora.chat/test_chat.html?token=xxxx` or via the live embed widget:

1. **Widget Bootstrap (`GET /v1/widget/config/{bot_token}`)**:
   - `test_chat.html` loads and extracts the query parameter `?token=xxxx` (and optional `&dept_id=yy`).
   - Requests widget configuration from [`ChatController::widgetConfig`](file:///c:/xampp/htdocs/edvora.chat/app/Controllers/ChatController.php#L21-L101).
   - Validates that the token exists in `chatbots` and that the tenant organization is active.
   - Cascades theme colors, bot avatars, greeting messages, active lead capture flags, and quick-action chips (`quick_chips`).
   - Dynamically loads `widget.js` with `data-is-test="1"` to avoid polluting production billing analytics.

2. **Message Dispatch (`POST /v1/chat/completions`)**:
   - Transmits payload: `{ bot_token, message, visitor_id, is_test, department_id, page_url, page_title }`.
   - **Conversation Tracking**: Looks up existing active session in `conversations` matching `(organization_id, chatbot_id, visitor_id)`. If absent, inserts a new session.
   - **User Message Storage**: Inserts record into `messages` with `role = 'user'`.
   - **Intent Classification**: [`IntentClassifier::classify()`](file:///c:/xampp/htdocs/edvora.chat/app/Services/IntentClassifier.php) detects whether the message is purely conversational/greeting (`TIER_CONVERSATIONAL`), a clarification/complaint (`TIER_CLARIFICATION`), or an inquiry requiring institutional knowledge (`TIER_KNOWLEDGE_QUERY`).
   - **Knowledge Retrieval Engine (No Vector DB)**:
     If classified as a knowledge inquiry, queries [`ContentEngine::selectContext()`](file:///c:/xampp/htdocs/edvora.chat/app/Services/ContentEngine.php#L30-L130):
     - **Tier 1 (Intent Phrase Match)**: `MATCH(semantic_keywords) AGAINST(:query IN NATURAL LANGUAGE MODE)`.
     - **Tier 2 (Keyword/Content Fallback)**: `MATCH(title, keywords, processed_content) AGAINST(:query IN NATURAL LANGUAGE MODE)`.
     - **Tier 3 (Sub-string Fallback)**: Multi-term SQL `LIKE` conditions.
     - **Adaptive Context Selection**: Grabs top 1 to 3 sources that score at least 30% of the highest scored source. Expired or un-effective sources (`expires_on < CURDATE()` or `effective_from > CURDATE()`) are automatically filtered out.
   - **LLM Prompt Assembly**: [`PromptBuilder::build()`](file:///c:/xampp/htdocs/edvora.chat/app/Services/PromptBuilder.php) aggregates:
     1. Global Platform Master Prompt (`platform_config.master_prompt`).
     2. Chatbot-specific instructions (`chatbots.system_prompt_override`).
     3. Department-specific tone and context.
     4. Knowledge base context (injected from `processed_content` of selected sources).
     5. Conversation history (last 6 messages).
   - **Inference & Lead Triggering**:
     - Dispatched via [`LlmService::complete()`](file:///c:/xampp/htdocs/edvora.chat/app/Services/LlmService.php).
     - If the model determines the visitor has high intent (e.g. asking for application forms or campus visit), it appends `[LEAD_TRIGGER:brochure]` or `[LEAD_TRIGGER:callback]`.
     - The controller strips this internal token from user-facing text and constructs a dynamic UI form trigger in the API response.
   - **Assistant Message Storage**: Inserts assistant reply into `messages` with `knowledge_sources_used` (JSON array of source IDs) and `tokens_used`.

---

## 4. Database Schema: Tables Involved & Crucial Fields

The following tables form the foundation of the knowledge storage and chat system:

### Table 1: `knowledge_sources`
*The authoritative repository for all institutional knowledge.*

| Field Name | Type | Key Role & Purpose |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PRIMARY KEY` | Unique document identifier. |
| `organization_id` | `INT (FK)` | Tenant isolation boundary. Every query strictly filters by `organization_id`. |
| `chatbot_id` | `INT NULL (FK)` | Optional binding to a specific bot (NULL means tenant-wide). |
| `type` | `ENUM('document', 'url', 'text_paste')` | Channel through which information entered the system. |
| `title` | `VARCHAR(255)` | Document title; indexed for search and cited in UI sources. |
| `category` | `VARCHAR(100)` | Categorization (e.g., Admissions, Hostel, Placements, Fees, Scholarships). |
| `academic_version` | `VARCHAR(50)` | Version tag (e.g., `2025-2026`, `v1.2`) to prevent serving outdated policy documents. |
| `raw_content` | `LONGTEXT NULL` | **Full, unedited original text** extracted from PDF/DOCX, web scraper, or text paste. Preserved for administrative audit and editing; **never passed directly into the LLM**. |
| `processed_content` | `LONGTEXT NULL` | **Compacted, high-density knowledge representation**. Stripped of boilerplate, deduplicated, and formatted specifically for injection into LLM system prompts. |
| `keywords` | `TEXT NULL` | High-frequency domain terms extracted algorithmically for FULLTEXT search. |
| `semantic_keywords`| `TEXT NULL` | High-intent question phrases generated asynchronously by LLM (e.g. *"how much is hostel deposit"*). |
| `source_url` | `VARCHAR(500) NULL` | Origin URL if ingested via scraper. |
| `file_path` | `VARCHAR(500) NULL` | Relative path to uploaded file on disk (`/storage/uploads/...`). |
| `content_hash` | `VARCHAR(64) NULL` | MD5 hash of raw content to identify duplicates or document updates. |
| `status` | `ENUM('processing', 'active', 'failed', 'archived')` | Operational state. Only `active` sources are queried during chat. |
| `effective_from` | `DATE NULL` | Starting date of validity. Documents in the future are suppressed. |
| `expires_on` | `DATE NULL` | Expiration date. Documents past this date are automatically filtered out. |
| `last_reviewed_at` | `DATE NULL` | Date when department admin last reviewed/certified accuracy. |
| `review_frequency_days` | `INT DEFAULT 180` | Cadence for review alerts (e.g., every 6 months). |

---

### Table 2: `department_knowledge`
*Enables fine-grained departmental routing of knowledge.*

| Field Name | Type | Key Role & Purpose |
|---|---|---|
| `department_id` | `INT (FK)` | References `departments.id`. |
| `knowledge_source_id` | `INT (FK)` | References `knowledge_sources.id`. |

*Role:* Associates documents with specific departments (e.g., Admissions, Hostel & Housing, Bursar/Accounts). When visitors chat with a department-scoped bot, department-specific documents receive precedence.

---

### Table 3: `chatbots`
*Defines the chatbot instance, UI customization, and behavior rules.*

| Field Name | Type | Key Role & Purpose |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PRIMARY KEY` | Unique chatbot ID. |
| `organization_id` | `INT (FK)` | Owning tenant organization. |
| `name` | `VARCHAR(255)` | Public display name of the chatbot assistant. |
| `bot_token` | `VARCHAR(64) UNIQUE` | Public authentication token passed in `?token=xxxx` and `widget.js`. |
| `welcome_message` | `TEXT NULL` | Initial greeting displayed when the widget opens. |
| `system_prompt_override` | `TEXT NULL` | Custom tenant instructions appended to the LLM system prompt. |
| `lead_capture_enabled` | `TINYINT(1) DEFAULT 1` | Master toggle for triggering lead forms (brochure, campus tour, callback). |
| `quick_chips` | `JSON NULL` | Pre-defined clickable starter questions shown above the input box. |
| `is_active` | `TINYINT(1) DEFAULT 1` | Chatbot operational status. Inactive bots return 404/403. |

---

### Table 4: `conversations`
*Maintains session identity, visitor attribution, and lead capture status.*

| Field Name | Type | Key Role & Purpose |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PRIMARY KEY` | Unique session ID. |
| `organization_id` | `INT (FK)` | Owning tenant. |
| `chatbot_id` | `INT (FK)` | Bot instance handling the session. |
| `department_id` | `INT NULL (FK)` | Active department context if routed to a specific department. |
| `visitor_id` | `VARCHAR(64)` | Persistent UUID or cookie generated by the frontend widget (`mob_...` or UUID). |
| `visitor_name` | `VARCHAR(255) NULL` | Name captured via conversational lead form. |
| `visitor_email` | `VARCHAR(255) NULL` | Email captured via lead form. |
| `visitor_phone` | `VARCHAR(50) NULL` | Phone number captured for counselor callbacks. |
| `is_test` | `TINYINT(1) DEFAULT 0` | Flagged as `1` when initiated from `test_chat.html`. Test sessions do not count against monthly plan message quotas in `usage_logs`. |
| `lead_captured_at` | `TIMESTAMP NULL` | Timestamp when the visitor submitted their contact details. Used to prevent redundant lead popups. |
| `started_at` | `TIMESTAMP` | When the session was initiated. |
| `last_message_at` | `TIMESTAMP` | Timestamp of the most recent exchange. |

---

### Table 5: `messages`
*The chronological audit trail of all conversational interactions.*

| Field Name | Type | Key Role & Purpose |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PRIMARY KEY` | Unique message ID. |
| `conversation_id` | `INT (FK)` | Links message to the parent conversation. |
| `organization_id` | `INT (FK)` | Multi-tenant scoping. |
| `role` | `ENUM('user', 'assistant', 'system')` | Identifies author of the message. |
| `content` | `TEXT` | Raw message body (visitor query or AI counselor answer). |
| `knowledge_sources_used` | `JSON NULL` | Array of `knowledge_sources.id` records retrieved and provided to the LLM as context for this answer. Allows traceability and source attribution. |
| `tokens_used` | `INT DEFAULT 0` | Total LLM token count (prompt + completion) consumed for generating the response. |
| `created_at` | `TIMESTAMP` | Exact message timestamp. |

---

### Table 6: `leads`
*Stores captured prospective student inquiries.*

| Field Name | Type | Key Role & Purpose |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PRIMARY KEY` | Unique lead ID. |
| `organization_id` | `INT (FK)` | Organization receiving the prospect. |
| `chatbot_id` | `INT (FK)` | Chatbot responsible for capturing the lead. |
| `conversation_id` | `INT NULL (FK)` | Link back to full chat transcript where the lead originated. |
| `name`, `email`, `phone` | `VARCHAR` | Contact coordinates. |
| `program_interest` | `VARCHAR(255) NULL` | Inferred or selected academic course (e.g. B.Tech Computer Science). |
| `status` | `ENUM('new', 'contacted', 'converted')` | Lead progression status for admissions counseling teams. |

---

### Table 7: `jobs`
*Async queue for document processing and LLM keyword enrichment.*

| Field Name | Type | Key Role & Purpose |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PRIMARY KEY` | Job ID. |
| `type` | `VARCHAR(100)` | Task type (e.g., `enrich_keywords`, `process_document`, `scrape_url`). |
| `payload` | `JSON` | Encoded task parameters (e.g. `{"knowledge_source_id": 42}`). |
| `status` | `ENUM('pending', 'running', 'done', 'failed')` | Job lifecycle status polled by Supervisor workers. |

---

## 5. Summary of Key Differences: `raw_content` vs `processed_content` vs `keywords`

| Field | Source / Origin | Usage in edvora.chat | Why It Matters |
|---|---|---|---|
| **`raw_content`** | Direct file extraction (`pdftotext`), URL scraper, or text paste. | Archival, document editing, and administrative reference. **Never** fed into prompt context. | Ensures original source fidelity without wasting token windows on legal disclaimers, repeated footers, or unformatted page numbers. |
| **`processed_content`** | Generated via `ContentCompactor::process()`. | **Directly fed to LLM** inside the system prompt during chat completions. | High-density distillation of facts, figures, fees, and rules; ensures accurate AI answers within budget. |
| **`keywords`** | Algorithmic term-frequency extraction minus stopwords. | MariaDB `FULLTEXT(title, keywords, processed_content)` indexing. | Provides rapid fallback matching for institutional acronyms and specific course names. |
| **`semantic_keywords`** | LLM-generated natural language search queries via background worker. | MariaDB `FULLTEXT(semantic_keywords)` Tier 1 matching. | Matches user queries even when phrased differently from document headings. |
