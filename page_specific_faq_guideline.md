# Page-Specific FAQ Section — Agent Guideline
### edvora.chat · Internal Functional Pages · Version 1.0
*Authoritative reference for AI agents implementing FAQ sections on any functional page of the edvora.chat dashboard.*

---

## 1. Purpose of This Document

This document defines:
1. **Branding & structural standards** — how every FAQ section must look and be structured so it visually matches every other FAQ section across the platform.
2. **Content direction** — how to write FAQ entries that genuinely help users understand the product, extract maximum value, and avoid common mistakes.

Every agent implementing a FAQ section on any internal page **must read and follow this document completely** before writing a single line of HTML.

---

## 2. Design & Branding Standards

### 2.1 Overall Visual Language

The FAQ section inherits the **edvora.chat Light Luxury** design system (see `BRANDING_GUIDELINES.md`). The encyclopedic editorial style (established on the Knowledge Hub page) is the **canonical visual reference** for all FAQ sections.

| Property | Value |
|---|---|
| Section background | `#FFFFFF` (white card surface) |
| Wrapper border | `1.5px solid #DCE9E5` |
| Wrapper border-radius | `16px` |
| Body canvas | `#FAF7F0` (warm parchment — encyclopedic tone) |
| Font family | `''Plus Jakarta Sans'', sans-serif` |
| Primary heading color | `#063D3B` (Deep Forest Teal) |
| Body text color | `#1E293B` |
| Muted / secondary text | `#648781` |
| Accent border (left bar) | `#047857` (emerald) |
| Golden rules table header bg | `#F8FBFA` |

### 2.2 Positioning on the Page

- The FAQ section always appears **below the primary functional content** of the page (below tables, KPI tiles, action panels, etc.).
- Minimum top margin from the last functional element: **70px** (`margin-top: 70px`).
- The FAQ title and pill are **outside any card or box boundary** — they float freely above the card.
- The card wrapper (`.encyclopedia-wrapper`) begins immediately below the title block.

### 2.3 The FAQ Pill (Mandatory)

Every FAQ section must begin with an identifying pill placed **above the title**:

```html
<div style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px;
     border-radius: 6px; background: #ECFDF5; border: 1px solid #A7F3D0;
     color: #047857; font-size: 10.5px; font-weight: 800;
     text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">
  <span>Frequently Asked Questions</span>
</div>
```

> **Rule:** The pill text is always "Frequently Asked Questions" — do not change it per page.

### 2.4 Title Block & Accordion Toggle (Mandatory Collapsible Standard)

The FAQ title section is **visible by default**, while the full encyclopedic article is **hidden (`display: none;`) by default**. Clicking anywhere on the title header smoothly reveals or collapses the article.

```html
<div id="{tab_name}FaqToggle" onclick="toggle{TabName}Faq()" style="margin: 70px 24px 16px 24px; cursor: pointer; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; user-select: none; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
  <div>
    <!-- Pill -->
    <div style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 6px; background: #ECFDF5; border: 1px solid #A7F3D0; color: #047857; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">
      <span>Frequently Asked Questions</span>
    </div>
    <!-- Title -->
    <h2 style="font-family: var(--brand-font-display); font-size: 20px; font-weight: 800; color: #063D3B; letter-spacing: -0.02em; margin: 0 0 6px 0;">
      [Strategic Page Guide Title]
    </h2>
    <!-- Subtitle with hint -->
    <p style="font-size: 13px; color: #648781; margin: 0; line-height: 1.4;">
      [Subtitle description] &bull; <span id="{tab_name}FaqHint" style="color: #047857; font-weight: 600;">Click to read full guide</span>
    </p>
  </div>
  <!-- Read Guide CTA button + Chevron -->
  <div style="display: flex; align-items: center; gap: 8px; margin-top: 10px; padding: 6px 12px; border-radius: 8px; background: #F1F7F4; border: 1.5px solid #DCE9E5; color: #063D3B; font-size: 12px; font-weight: 700; flex-shrink: 0;">
    <span id="{tab_name}FaqToggleText">Read Guide</span>
    <svg id="{tab_name}FaqChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#063D3B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.3s ease; transform: rotate(0deg);">
      <polyline points="6 9 12 15 18 9"></polyline>
    </svg>
  </div>
</div>
```

### 2.5 Wrapper Card (Hidden by Default)

The body of the FAQ section sits inside an `.encyclopedia-wrapper` with `display: none;` initially:
```html
<div id="{tab_name}FaqBody" class="encyclopedia-wrapper" style="margin-top: 0; display: none;">
  <div class="encyclopedia-body" style="padding-top: 28px;">
    <!-- content here -->
  </div>
  <div class="encyclopedia-footer-credo">
    <!-- footer credo here -->
  </div>
</div>
```

And the standard JS toggle function:
```javascript
function toggle{TabName}Faq() {
    const body = document.getElementById('{tab_name}FaqBody');
    const chevron = document.getElementById('{tab_name}FaqChevron');
    const toggleText = document.getElementById('{tab_name}FaqToggleText');
    const hint = document.getElementById('{tab_name}FaqHint');
    if (!body) return;

    const isHidden = body.style.display === 'none' || body.style.display === '';
    if (isHidden) {
        body.style.display = 'block';
        if (chevron) chevron.style.transform = 'rotate(180deg)';
        if (toggleText) toggleText.innerText = 'Hide Guide';
        if (hint) hint.innerText = 'Click to collapse guide';
    } else {
        body.style.display = 'none';
        if (chevron) chevron.style.transform = 'rotate(0deg)';
        if (toggleText) toggleText.innerText = 'Read Guide';
        if (hint) hint.innerText = 'Click to read full guide';
    }
}
```

### 2.6 Preamble (Drop-Cap Paragraph — Mandatory)

Every FAQ section starts with a classical drop-cap introductory paragraph that sets the context for this specific page:

```html
<div class="encyclopedia-preamble" style="margin-bottom: 24px;">
  <span class="encyclopedia-dropcap">T</span>
  <div style="flex: 1;">
    <strong>The Core Mission:</strong> [One paragraph explaining what this page does,
    why it matters, what goes wrong if used incorrectly, and what the ideal outcome is.]
  </div>
</div>
```

> **Content rule:** The drop-cap letter is always the first letter of the first word of the paragraph body (after "The Core Mission:"). Keep the preamble to 3–5 sentences maximum.

### 2.7 Section Title Labels

Use this class for all section headings within the FAQ body:
```html
<div class="encyclopedia-section-title" style="margin-top: 16px;">
  [Section Title Here]
</div>
```

Standard section titles to use (in order):
1. `Step-by-Step Operations: How to Get the Best Out of Each Function`
2. `Best Practices: The [N] Golden Rules for [Page Goal]`

### 2.8 Operation Cards (2-Column Grid)

Functional guidance entries are laid out as numbered cards in a 2-column responsive grid:

```html
<div class="encyclopedia-grid">
  <div class="encyclopedia-entry-card">
    <div class="encyclopedia-entry-header">
      <div class="encyclopedia-entry-num">1</div>
      <h3 class="encyclopedia-entry-title">Card Title</h3>
    </div>
    <div class="encyclopedia-entry-text">
      [Explanation paragraph]
      <ul>
        <li><strong>Feature Name:</strong> Description of what it does and how to use it.</li>
      </ul>
      <!-- Optional: intent/tip/warning box -->
      <div style="margin-top: 10px; padding: 8px 10px; background: #FAF7F0;
           border-left: 3px solid #047857; font-size: 10.5px; color: #063D3B;">
        <strong>Pro Tip:</strong> [Actionable advice.]
      </div>
    </div>
  </div>
</div>
```

> **Rule:** Cards are numbered sequentially starting from `1`. Minimum 4 cards, maximum 8 cards per page. Each card covers exactly one coherent functional area of the page.

### 2.9 Golden Rules Table (Mandatory)

After the operation cards, every FAQ section must include a "Golden Rules" table with 3–7 rows:

```html
<div class="encyclopedia-section-title">Best Practices: The [N] Golden Rules for [Page Goal]</div>
<table class="encyclopedia-table">
  <thead>
    <tr>
      <th style="width: 38px; text-align: center;">#</th>
      <th style="width: 22%;">Rule</th>
      <th style="width: 38%;">What to Do in [Page Name]</th>
      <th style="width: 36%;">Expected Result / Benefit</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="text-align: center; font-weight: 800; color: #063D3B;">1</td>
      <td><strong>[Rule Name]</strong></td>
      <td>[Specific action to take in the UI]</td>
      <td>[The outcome or benefit the user will experience]</td>
    </tr>
  </tbody>
</table>
```

### 2.10 Summary Box (Mandatory)

After the golden rules table, include a summary closing paragraph:

```html
<div style="padding: 16px 20px; background: #F1F7F4; border: 1.5px solid #D8E7E1;
     border-radius: 10px; font-size: 12px; line-height: 1.6; color: #063D3B;">
  <strong>Summary:</strong> [2–3 sentence synthesis of what happens when the user applies
  all the guidance above — the ideal outcome state for this page.]
</div>
```

### 2.11 Footer Credo (Mandatory)

Every FAQ section closes with an `.encyclopedia-footer-credo` block. The left side shows a shield SVG + section label; the right shows a version tag.

```html
<div class="encyclopedia-footer-credo">
  <div style="display: flex; align-items: center; gap: 8px;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
         stroke="#047857" stroke-width="2.5">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    </svg>
    <span style="font-weight: 800; color: #063D3B; text-transform: uppercase;
          letter-spacing: 0.05em;">[Page-Specific Standard Label]</span>
  </div>
  <div style="font-family: var(--brand-font-mono); font-size: 10px; color: #78644E;">
    Edvora Chat OS &bull; [Page Name] Guardrail Framework v1.0
  </div>
</div>
```

---

## 3. CSS Classes Reference

All FAQ styling uses the `.encyclopedia-*` class family. These are defined in **both**:
- `public/theme-branding.css`
- `public/app/theme-branding.css`

**Never add inline styles for structure** — only use inline styles for spacing overrides (`margin`, `padding`) or one-off contextual adjustments. All visual styles must come from the class definitions.

| Class | Purpose |
|---|---|
| `.encyclopedia-wrapper` | Outer card shell — white bg, border, border-radius, shadow |
| `.encyclopedia-body` | Inner content area — parchment bg (`#FAF7F0`), padding |
| `.encyclopedia-preamble` | Drop-cap preamble flex row |
| `.encyclopedia-dropcap` | Large decorative first letter |
| `.encyclopedia-section-title` | Section heading with left accent |
| `.encyclopedia-grid` | 2-column responsive card grid |
| `.encyclopedia-entry-card` | Individual operation card — white bg, border, border-radius |
| `.encyclopedia-entry-header` | Card header row — number + title flex row |
| `.encyclopedia-entry-num` | Circular numbered badge (teal bg, white text) |
| `.encyclopedia-entry-title` | Card title text |
| `.encyclopedia-entry-text` | Card body text + list items |
| `.encyclopedia-table` | Full-width editorial table — parchment header, striped rows |
| `.encyclopedia-footer-credo` | Bottom footer bar — flex space-between |

> **Important:** If a new class is genuinely needed (not achievable by the existing set), add it to **both** CSS files simultaneously. Never add a page-specific CSS class to only one file.

---

## 4. Content Writing Direction

### 4.1 Core Purpose of FAQ Content

Every FAQ section exists for one purpose: **to make the user confidently productive on that specific page**. The content must answer:
1. What is this page for and why does it matter?
2. How do I use each key feature correctly?
3. What are the common mistakes and how do I avoid them?
4. What does doing everything right look like?

### 4.2 Tone & Voice

| Principle | How to apply |
|---|---|
| **Plain English** | Write as if explaining to a busy university administrator on their first day. No jargon or technical acronyms unless unavoidable. |
| **User-perspective** | Always frame advice from the user''s goal, not the system''s feature ("Track which sources are stale" not "Use the lifecycle filter flag"). |
| **Decisive** | Never write "you may want to consider". Write "Do this. It prevents X." |
| **Specific** | Name exact UI elements: button labels, tab names, field names, as they appear on screen. Use `<strong>` for UI labels and `<code>` for system values or field keys. |
| **Consequence-first** | When describing a blunder/mistake, state the consequence first ("The chatbot will confuse two fee schedules if..."), then state the correct action. |

### 4.3 Operation Cards — What to Cover

Each card must cover **one coherent functional area** of the page. For every page, identify:

| Card Type | What It Covers |
|---|---|
| **Primary Action Card** | The main thing users do on this page (adding a record, uploading a file, creating an entry). How to do it correctly, what fields matter most. |
| **Configuration / Settings Card** | Key settings or metadata the user must fill in correctly (scope, department, dates, types). What happens if skipped or wrong. |
| **Lifecycle / Status Card** | How statuses work on this page (active, expired, pending, review-due). What triggers each status. What the user must do when they see each. |
| **Search / Filter / Bulk Card** | How to navigate, search, and manage records at scale. Keyboard shortcuts if present. Pagination. Export if available. |
| **Audit / Review Card** | How to review, verify, or sign off on records. Who is responsible. What the review cycle looks like. |
| **Error Recovery Card** | What to do when something goes wrong — wrong document uploaded, wrong department assigned, duplicate record, stale data noticed. |

> Not all pages will need all 6 card types. Use only what is relevant to the page. Minimum 4 cards.

### 4.4 Golden Rules — How to Write Them

Each rule must have:
- A **short, memorable rule name** (3–5 words, bold, title case) — e.g., *"No Duplicate Versions"*, *"Scope to Correct Department"*.
- A **specific action** — what exactly to click, fill, or verify in the UI.
- A **clear outcome** — what the chatbot or system does correctly as a result.

**Rules to always consider for any page:**
1. Avoid duplicates / redundant records.
2. Assign to the correct scope/department/category.
3. Set validity/expiry dates where applicable.
4. Use descriptive names and keywords so search and AI retrieval work correctly.
5. Review regularly — do not create and forget.
6. Use replace/rollover for updates, not addition of a second record.
7. Check warning/attention states at least weekly.

Adapt and select the most relevant 3–7 rules for the specific page.

### 4.5 Mandatory Blunder Warnings

Every FAQ section must directly address the top 3 blunders possible on that page. Blunders are embedded inside operation cards as left-bordered tip boxes (see Section 2.8 — the "Common Mistake" box pattern):

```html
<div style="margin-top: 10px; padding: 8px 10px; background: #FAF7F0;
     border-left: 3px solid #047857; font-size: 10.5px; color: #063D3B;">
  <strong>Common Mistake:</strong> [What users typically do wrong here and why it causes problems.]
</div>
```

Standard blunders to consider per page type:
- **Knowledge pages:** Uploading outdated PDFs without expiry dates; uploading the same policy twice; not assigning to a department; skipping keywords.
- **Department/Team pages:** Creating duplicate departments; not assigning members before activating a chatbot; assigning the wrong head.
- **Lead / Conversation pages:** Marking leads as resolved without follow-up notes; ignoring unread lead alerts.
- **Settings / Config pages:** Changing live settings without understanding downstream effects on active chat sessions.
- **Analytics pages:** Drawing conclusions from short time windows; ignoring off-hours drop patterns.

### 4.6 Preamble Writing Template

Use this mental template for the preamble paragraph:

> *"The [Page Name] is not just a [surface description]; it is the [deeper role in the system]. A [user persona] who [uses this page incorrectly] risks [specific consequence]. However, [the correct approach] ensures [the positive outcome]. This guide details how to leverage every function of [Page Name] to [ideal state]."*

Keep it to 3–5 sentences. It must be specific to the page — not generic platform language.

### 4.7 Summary Box Writing Template

> *"By applying these practices within [Page Name], your [system component] operates as [ideal description of the outcome] — [specific user-facing benefit 1], [specific user-facing benefit 2], and [specific user-facing benefit 3]."*

---

## 5. Page Coverage Plan

The following internal pages require a FAQ section. Implement in this priority order:

| Priority | Page | Tab ID | Key User Workflow |
|---|---|---|---|
| Done | Knowledge Hub | `#knowledge` | Upload, scope, expiry, review of knowledge sources |
| Next | Departments | `#departments` | Create, configure, assign members to departments |
| 3 | Teams | `#teams` | Build teams, assign agents, set roles |
| 4 | Student Leads | `#leads` | Capture, qualify, follow up on prospective student leads |
| 5 | ChatBot Settings | `#chatbot` | Configure chatbot persona, master prompt, widget settings |
| 6 | Analytics & Reports | `#analytics` | Read conversation metrics, identify gaps, export data |
| 7 | Billing & Plans | `#billing` | Manage subscription, quotas, plan upgrades |
| 8 | User Management | `#users` | Invite, assign roles, manage access |

When implementing a new page''s FAQ:
1. Read this document fully.
2. Read the page''s tab HTML file (`public/app/tabs/{page}.html`) to understand current UI elements.
3. Read `BRANDING_GUIDELINES.md` for any color or typography updates.
4. Write the FAQ section following Sections 2 and 4 of this document exactly.
5. Deploy both CSS files and the tab HTML file using the Standard Deployment Workflow in `AGENTS.md`.

---

## 6. Quality Checklist

Before deploying any FAQ section, verify every item:

### Design Checklist
- [ ] FAQ pill is present above the title, using exact color tokens (`#ECFDF5` bg, `#047857` text, `#A7F3D0` border).
- [ ] Title (`<h2>`) and subtitle (`<p>`) are outside any card boundary, with `margin: 70px 24px 16px 24px`.
- [ ] Preamble has a drop-cap letter via `.encyclopedia-dropcap`.
- [ ] Operation cards use `.encyclopedia-grid` and `.encyclopedia-entry-card` classes.
- [ ] Cards are numbered sequentially (`encyclopedia-entry-num`).
- [ ] Golden rules table uses `.encyclopedia-table` class with 4 columns.
- [ ] Summary box uses `#F1F7F4` background with `1.5px solid #D8E7E1` border.
- [ ] Footer credo is present with shield SVG, page label, and version tag.
- [ ] No structural inline styles that duplicate class definitions.
- [ ] Both `public/theme-branding.css` and `public/app/theme-branding.css` are updated if any new CSS classes were added.

### Content Checklist
- [ ] Preamble is page-specific (not generic) and covers: what it is, why it matters, consequence of misuse, ideal outcome.
- [ ] Minimum 4 operation cards covering the page''s key functional areas.
- [ ] Each card has at least one `<ul>` list of specific, named UI actions.
- [ ] At least 3 tip/blunder boxes embedded across the cards.
- [ ] Golden rules table has 3–7 rows, each with a specific UI action and a clear outcome.
- [ ] Summary box names the specific page and articulates the positive outcome state.
- [ ] All UI element names match what is actually rendered on the page (no invented button labels).
- [ ] Tone is decisive, specific, and user-perspective — no passive voice or vague statements.

---

*This document is maintained alongside `AGENTS.md`, `BRANDING_GUIDELINES.md`, and `architecture.md` as a core project governance document. Update it when new pages are added to the platform or when the FAQ design pattern evolves.*
