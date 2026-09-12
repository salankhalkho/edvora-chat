# Technical Architecture & Operation Document: Edvora Live Interactive Demo Engine (`https://edvora.chat/try`)

---

## 1. Executive Overview

The **Interactive Demo Engine** at `https://edvora.chat/try` (also accessible via `/preview`) is a zero-friction, personalized product preview and lead-conversion funnel. 

Unlike conventional SaaS demos that show simulated static screenshots or mock sandboxes, Edvora dynamically crawls the prospect’s actual university or college website in real time, indexes its key institutional facts (academic degrees, admissions criteria, tuition, and financial aid), and spins up a functional AI Admissions Counselor alongside a live admissions intelligence telemetry monitor.

---

## 2. High-Level System Architecture

```mermaid
graph TD
    A[Visitor on /try] -->|Enter University URL| B[Stage 1: URL Submission]
    B -->|POST /v1/preview/analyze| C[DemoPreviewController::analyze]
    C --> D[DemoPreviewService::analyzeWebsite]
    
    subgraph Scraping & Deduplication Layer
        D -->|Check Domain| E[(demo_previews Table)]
        E -->|Cached Profile Found?| F[Instant Re-use & New Session Token]
        E -->|New Domain| G[Live cURL Fetcher & Link Spider]
        G --> H[HTML Normalizer & Text Extractor]
        H --> I[Program & Department Regex Extractor]
        I --> J[Save Context to demo_previews]
    end

    F --> K[Stage 2A: Discovery Visualizer]
    J --> K
    K --> L[Stage 3: Work Email Gating]
    L -->|POST /v1/preview/capture-email| M[Capture Stage 1 Lead]
    M --> N[Stage 4: Dual-Pane Live Interactive Console]
    
    subgraph Dual-Pane Interactive Environment
        N --> O[Left Pane: Live Chatbot Widget]
        N --> P[Right Pane: Admissions Intelligence HUD]
        O -->|Student Asks Question| Q[POST /v1/preview/chat]
        Q --> R[LlmService::complete with Scraped Context & Conversion Protocol]
        R --> S[AI Reply + [SOURCE] + [LEAD_ACTION]]
        S -->|Real-time Telemetry| P
        S -->|Inline Lead Bubble| T[Stage 5: High-Intent Lead Capture]
    end
    
    T -->|POST /v1/preview/counselor-request| U[Conversion Summary & CTA]
```

---

## 3. End-to-End User Journey & State Transitions

The client-side single page application in [`public/preview.html`](public/preview.html) manages a 5-stage state machine:

| Stage | Name | Description | User Action / Trigger |
|---|---|---|---|
| **1** | **URL Input** | Clean input field asking for university domain (e.g., `ucla.edu`, `stanford.edu`). | User submits URL. |
| **2A** | **Discovery Animation** | Real-time visual pipeline showing progress: connecting, parsing pages, extracting degree programs, structuring fee rules. | Driven asynchronously while cURL request completes or executes fake-wait steps on cached hits. |
| **2B** | **Error Fallback** | Displays connection errors or timeout warnings with retry options. | Triggered if cURL fails, DNS fails, or site blocks requests. |
| **3** | **Lead Gate #1 (Email Capture)** | Displays institution name and badge recap (e.g., "6 programs found, Admissions & Fees mapped"). Prompts for work email. | User inputs email and clicks "Launch Interactive Demo". |
| **4** | **Dual-Pane Interactive Preview** | **Left:** Functional widget with dynamic scenario chips.<br>**Right:** Real-time Admissions Intelligence HUD showing intent score, funnel stage, and extracted fields. | User interacts with bot; AI detects intent and pops inline capture forms. |
| **5** | **Conversion Recap (Closing Screen)** | Summarizes captured lead data, conversational transcript, and estimated admissions ROI. | CTA prompts signing up for full deployment. |

---

## 4. Backend Engine & API Specifications

All endpoints are public-facing without requiring prior tenant authentication, routed through [`public/index.php`](public/index.php) and handled by [`DemoPreviewController`](app/Controllers/DemoPreviewController.php) and [`DemoPreviewService`](app/Services/DemoPreviewService.php).

### 4.1. Website Analysis & Spidering (`POST /v1/preview/analyze`)
* **Request Payload**:
  ```json
  { "url": "https://www.columbia.edu" }
  ```
* **Processing Logic**:
  1. **URL Normalization**: Adds `https://` protocol if omitted, cleans paths, and extracts root domain via `DemoPreviewService::extractDomain()`.
  2. **Deduplication Engine**: Checks `demo_previews` for existing successful scrapes on the same domain:
     * *If found*: Clones cached knowledge context (`scraped_context`), increments demo session records, and returns immediate response with `deduplicated: true`.
     * *If fresh*: Initiates a 15-second cURL request to root homepage with browser-like `User-Agent`.
  3. **Link Extraction & Targeted Crawl**:
     * Parses HTML DOM for internal hyperlinks.
     * Categorizes links using keyword patterns for `admissions`, `academics/programs`, `tuition/fees`, and `scholarships`.
     * Follows up to 2 high-priority subpages (e.g., `/admissions` and `/academics`) with an 8-second timeout each.
  4. **Text Cleaning & Program Discovery**:
     * Strips scripts, styles, SVGs, and navigation boilerplate.
     * Runs regex dictionary matching for undergraduate and graduate programs (`B.Tech`, `M.S.`, `Nursing`, `Computer Science`, `MBA`, etc.).
     * Concatenates combined text up to a 12,000-character payload saved to `demo_previews.scraped_context`.
* **Response**:
  ```json
  {
    "status": "success",
    "data": {
      "session_token": "prev_a73d9...",
      "institution_name": "Columbia University",
      "domain": "columbia.edu",
      "pages_found": 18,
      "programs_found": 8,
      "programs": ["MBA", "Nursing", "Computer Science", "Economics"],
      "has_admissions": true,
      "has_fees": true,
      "has_scholarships": true,
      "scrape_status": "success"
    }
  }
  ```

---

### 4.2. Work Email Capture (`POST /v1/preview/capture-email`)
* **Request Payload**:
  ```json
  {
    "session_token": "prev_a73d9...",
    "email": "dean@columbia.edu"
  }
  ```
* **Processing**: Associates the visitor's work email with the active session in `demo_previews`, updates `lead_stage = 'email_captured'`, and allows the frontend to transition into Stage 4.

---

### 4.3. Grounded Conversational AI Engine (`POST /v1/preview/chat`)
* **Request Payload**:
  ```json
  {
    "session_token": "prev_a73d9...",
    "message": "Can you tell me about the MBA tuition and scholarships?",
    "history": [
      { "role": "user", "content": "What degrees do you have?" },
      { "role": "assistant", "content": "We offer MBA, Nursing, and CS..." }
    ]
  }
  ```
* **Prompt Assembly & Guardrails**:
  The system prompt dynamically injects the scraped context, institution name, and strict behavioral rules:
  * **Grounding**: Never invent facts; always cite data from the scraped knowledge base using `[SOURCE:<domain>]`.
  * **Brevity**: 3–4 sentences maximum to maintain high mobile engagement.
  * **Strict Prohibitions**: Never tell the user to "visit the website" or "look at the admissions portal" — the AI acts as the institutional counselor.
  * **Lead Trigger Injection**: When a student confirms interest or asks about tours, prospectus downloads, or financial aid, the LLM emits a trigger tag:
    ```
    [LEAD_ACTION:prospectus] | [LEAD_ACTION:tour] | [LEAD_ACTION:scholarship] | [LEAD_ACTION:counselor]
    ```

* **Intent & Affirmation Parser**:
  Even if the LLM omits the bracket tag, the backend analyzes user intent using a multi-tier fallback:
  1. Regular expression parsing of the model output for `[LEAD_ACTION:*]`.
  2. Keyword detection on explicit requests (`"schedule a tour"`, `"evaluate scholarship"`, `"send prospectus"`, `"call me"`).
  3. Conversational affirmation checking (`"yes please"`, `"sounds good"`, `"that would be great"`) matched against the assistant's previous question.

* **Response**:
  ```json
  {
    "status": "success",
    "data": {
      "reply": "Tuition for the MBA program is structured on a per-credit basis with merit-based fellowships available. Would you like an advisor to assess your scholarship eligibility?",
      "source_citation": "https://columbia.edu",
      "suggest_counselor": true,
      "lead_type": "scholarship",
      "intent_level": "high",
      "detected_program": "MBA"
    }
  }
  ```

---

### 4.4. High-Intent Lead Conversion (`POST /v1/preview/counselor-request`)
* **Request Payload**:
  ```json
  {
    "session_token": "prev_a73d9...",
    "name": "Sarah Jenkins",
    "phone": "+1 555 234 5678",
    "request_type": "scholarship"
  }
  ```
* **Processing**:
  * Saves contact details and request type into `demo_previews`.
  * Updates `lead_stage = 'counselor_requested'` and sets `counselor_requested_at = NOW()`.
  * Generates an inline confirmation card inside the chat stream and updates the Right-Pane Admissions Intelligence HUD to show `Lead Status: CAPTURED`.

---

## 5. Dual-Pane Real-Time Telemetry Monitor

The preview screen showcases what happens behind the scenes when a student interacts with Edvora:

1. **Left Side — Student View (380px Chat Widget)**:
   * Brand styling dynamically colored to match the university.
   * Scenario chips populated with the actual program names scraped from their website (e.g., *"Can you send me the prospectus for MBA?"*).
   * Interactive lead cards embedded directly inside the message feed.

2. **Right Side — Admissions Intelligence Console**:
   * **Live Message Counter & Funnel Stage**: Transitions from `Exploring` $\rightarrow$ `Interested` $\rightarrow$ `High Intent` $\rightarrow$ `Lead Captured`.
   * **Real-time Field Extraction**: Displays extracted user attributes (Name, Phone/WhatsApp, Desired Program, Preferred Contact Channel) as the visitor types.
   * **Journey Trail**: Visual pills tagging every milestone touched (`Programs Discussed`, `Tuition Inquired`, `Scholarship Evaluated`, `Tour Requested`).

---

## 6. Super Admin Visibility & CRM Tracking

Every interaction initiated on `/try` is recorded in the MariaDB database table `demo_previews`:
* **Telemetry Collected**: Website URL, domain, work email, institution name, scraping duration, count of pages discovered, full text context, chat message volume, student contact details, and timestamped lead milestones.
* **Super Admin Portal (`/superadmin`)**:
  * Under the **Demo Intelligence** tab (`/v1/superadmin/demos`), administrators can inspect active demos in real time, view raw scraped context, review the full conversation transcript, and export qualified demo leads to CSV.

---

## 7. Key Engineering Safeguards

1. **Anti-Scraping & Timeout Resistance**: Root requests are capped at 15s, subpages at 8s. Inaccessible sites fail gracefully with descriptive error guidance and allow retry without crashing server threads.
2. **Context Window Capping**: Scraped context is cleaned of HTML markup, scripts, and whitespace, then truncated to 12,000 characters to fit securely inside LLM token budgets while leaving adequate space for multi-turn history.
3. **Prompt Leakage & Hallucination Prevention**: Explicit rules prevent the model from assuming roles outside of the identified institution or referencing third-party colleges.
4. **Instant Deduplication**: Popular university domains are cached, reducing analysis latency from ~6–10 seconds down to <300ms on repeat demonstrations.
