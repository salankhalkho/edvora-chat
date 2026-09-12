# EDVORA SYSTEM GUIDE & ARCHITECTURE REFERENCE

> **Document Purpose**: Comprehensive system reference manual for EdvoraChat. Designed for progressive disclosure, structured maintenance, and fast information retrieval by both human users and AI assistants.

---

## 📜 SECTION 0: DOCUMENT GOVERNANCE & MAINTENANCE RULES

When updating or expanding this document (whether by a Human or an AI Assistant), strict adherence to the following maintenance rules is mandatory:

### 1. Catalog Registration Rule
- **Every new topic or feature added MUST be registered** in the `📑 TABLE OF CONTENTS & TOPIC CATALOG` at the top of this document before writing the section content.
- Format for catalog entries:
  `- [TAG: UNIQUE_TOPIC_TAG] — 1-sentence summary of what this topic covers.`

### 2. Progressive Disclosure Tagging Rule
- Every section heading MUST include its unique bracketed tag (e.g., `### [TAG: ROLE_TYPES] 1.1 Overview of System Roles`).
- Every topic section MUST follow this internal structure:
  1. **Topic Title & Tag**
  2. **⚡ Quick Take / Summary** (1-2 sentences for instant overview)
  3. **📖 Detailed Specification & Technical Nuances** (Tables, code snippets, or diagrams)

### 3. Single Source of Truth & No Duplication
- **Do not create duplicate sections** for an existing topic.
- If new details or edge cases emerge for a topic already present, update and extend the existing section under its designated tag.

### 4. AI Assistant Response Protocol
- **When asked *"What information can I get from edvora_guide.md?"***: The AI assistant MUST extract and return only the **Table of Contents & Topic Catalog**.
- **When asked a specific question**: The AI assistant MUST search for the corresponding `[TAG: ...]` and return only the relevant section (Progressive Disclosure Method).

---

## 📑 TABLE OF CONTENTS & TOPIC CATALOG

Quick index of all documented topics. Ask any AI assistant *"What information is available in edvora_guide.md?"* to retrieve this high-level catalog.

- **[SECTION-0: DOCUMENT GOVERNANCE & MAINTENANCE RULES]**
  - `[TAG: GOVERNANCE_RULES]` — Rules for adding or updating information in this guide.
- **[SECTION-1: SYSTEM ROLES & ACCESS CONTROL]**
  - `[TAG: ROLE_TYPES]` — Definition of Full Admin, Content Admin, and Team Member roles.
  - `[TAG: PERMISSION_MATRIX]` — System-wide feature permission matrix.
- **[SECTION-2: DEPARTMENT CONFIGURATION vs. OPERATIONAL DUTY]**
  - `[TAG: CONTENT_VS_DUTY]` — Separation between Content Editing Rights and Active Duty Lead Routing.
  - `[TAG: ADMIN_CHECKBOX_PURPOSE]` — Why Admins are listed in Department Staff checklists and why checkboxes exist.
  - `[TAG: LEAD_NOTIFICATION_ROUTING]` — How student inquiries, emails, and WhatsApp escalations are routed.

---

## 1. SYSTEM ROLES & ACCESS CONTROL

### `[TAG: ROLE_TYPES]` 1.1 Overview of System Roles

EdvoraChat uses a streamlined 2-role system with granular admin privilege flags:

1. **👑 Full Administrator (`role: admin`, `can_manage_structure: 1`)**
   - **Scope**: Full system control.
   - **Capabilities**: Can manage department content, create/import/delete departments, onboard/remove team members, and reset user passwords.

2. **👑 Content Administrator (`role: admin`, `can_manage_structure: 0`)**
   - **Scope**: Department content & daily operations.
   - **Capabilities**: Can manage department knowledge sources, FAQs, prospectus text, operating hours, and student leads. Cannot onboard/delete users or delete department structures.

3. **👥 Team Member (`role: staff`, `can_manage_structure: 0`)**
   - **Scope**: Assigned department operations.
   - **Capabilities**: Handles live student chats, receives lead notifications, and responds to escalations for departments they are assigned to.

---

### `[TAG: PERMISSION_MATRIX]` 1.2 System Feature Permission Matrix

| Feature / Capability | 👑 Full Admin | 👑 Content Admin | 👥 Team Member |
|---|:---:|:---:|:---:|
| **Manage Department Content (FAQs, Knowledge, Hours)** | ✅ Full | ✅ Full | 🏫 Assigned Depts Only |
| **View & Handle Student Leads & Escalations** | ✅ Full | ✅ Full | 🏫 Assigned Depts Only |
| **Onboard / Delete Users & Admins** | ✅ Yes | 🚫 Forbidden | 🚫 Forbidden |
| **Reset User Passwords** | ✅ Yes | 🚫 Forbidden | 🚫 Forbidden |
| **Create / Import / Delete Department Structure** | ✅ Yes | 🚫 Forbidden | 🚫 Forbidden |

---

## 2. DEPARTMENT CONFIGURATION vs. OPERATIONAL DUTY

### `[TAG: CONTENT_VS_DUTY]` 2.1 Content Management Rights vs. Active Duty Staffing

> **Core Architecture Principle**: System Access Rights and Operational Duty Assignments are completely decoupled.

1. **Content Management Rights (Global Role Attribute)**:
   - Dictates whether a user has permission to open a department's configuration panel and edit its FAQs, Knowledge Scope, or Operating Hours.
   - Automatically granted to all Admins (Full & Content) across the organization.

2. **Assigned Staff Checkbox (`department_staff` Table)**:
   - Does **NOT** grant or revoke permission to edit department content.
   - Designates that person as an **Active On-Duty Lead Handler** for live student interactions.

---

### `[TAG: ADMIN_CHECKBOX_PURPOSE]` 2.2 Why Admins Are Listed in the Department Staff Checklist

When configuring a department under `Configure Department → 3. Assigned Staff`, all organization members (including Admins) appear with checkboxes. Here is why:

#### 1. Prevents Notification Spam for Administrators
- Admins (e.g., Deans, Department Heads, System Admins) need to build FAQs and upload prospectus documents, but they typically **do not want to receive 50+ daily student lead emails, SMS, or WhatsApp alerts** intended for front-desk staff.
- **Unchecked Admin**: Can edit all FAQs & Knowledge freely, but **will not receive** live student lead notifications.

#### 2. Enables Flexibility for Small Teams & On-Call Shifts
- In smaller colleges, off-peak hours, or admission rushes, Administrators may double up as active lead responders.
- **Checked Admin**: Adds the Admin to the live escalation pool so they receive lead alerts and WhatsApp handoffs alongside regular staff.

---

### `[TAG: LEAD_NOTIFICATION_ROUTING]` 2.3 Lead & Escalation Routing Logic

When a student interacts with the AI Chatbot and requests human assistance or submits an inquiry:

```
[Student Chatbot Interaction]
              │
              ▼
   [Triggers Handoff / Lead]
              │
              ▼
  [Check Department Assigned Staff List]
              │
              ├─► (Checked Users) ──► Receive Email Alert + WhatsApp Escalation
              │
              └─► (Unchecked Users) ─► No Alert Sent (Clean Inbox)
```

---

## 3. SUMMARY QUICK REFERENCE

| User Type | Checkbox Status | Content Edit Rights | Live Lead Alerts Received |
|---|:---:|:---:|:---:|
| **Admin** | ⬜ Unchecked | ✅ Allowed | 🚫 No (Inbox Protected) |
| **Admin** | ☑️ Checked | ✅ Allowed | ✅ Yes (On-Duty Responder) |
| **Team Member** | ⬜ Unchecked | 🚫 Restricted | 🚫 No |
| **Team Member** | ☑️ Checked | 🏫 Assigned Dept | ✅ Yes (On-Duty Responder) |

---
*End of Document. Updated: August 2026.*
