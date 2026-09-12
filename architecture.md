# System Architecture Index — edvora.chat

Welcome to the central technical architecture index for **edvora.chat**.

---

## 🏛️ SUB-SYSTEM ARCHITECTURES & FIX GUIDELINES

> ⚠️ **MANDATORY INSTRUCTION FOR BUG FIXES & FEATURE UPDATES:**
> Whenever you perform any update, refactoring, API addition, database migration, or bug fix on a specific module or sub-system, you **MUST** consult its corresponding detailed architecture specification file before applying any changes.

| Sub-System Module | Architecture Specification File | Description & Scope |
| :--- | :--- | :--- |
| 🏢 **Department & Team Management** | [`architecture_department_team_management.md`](file:///c:/xampp/htdocs/edvora.chat/architecture_department_team_management.md) | **LOOK HERE FOR ALL BUG FIXING & UPDATES TO DEPARTMENTS & TEAMS.** Comprehensive specifications for 14 pre-built templates, knowledge base scoping, assigned staff, operating hours, multi-channel contact handles, escalation rules, lead routing, LLM prompt injection, and widget UI menu pills. |
| 📚 **Knowledge Base & RAG Engine** | [`knowledge_base_architecture.md`](file:///c:/xampp/htdocs/edvora.chat/knowledge_base_architecture.md) | Ingestion pipeline, PDF/URL parser, content compaction, dual-tier FULLTEXT search, semantic keyword indexing, and vector/phrase retrieval. |
| 🤖 **Multi-Chatbot Core** | [`multi_chatbot_architecture.md`](file:///c:/xampp/htdocs/edvora.chat/multi_chatbot_architecture.md) | Multi-tenant organization isolation, chatbot token validation, embed script generator, and allowed domain security boundaries. |
| 🌐 **Multilingual RAG Pipeline** | [`multilingual_support_spec.md`](file:///c:/xampp/htdocs/edvora.chat/multilingual_support_spec.md) | Indic & international script matching, automated query translation, cross-lingual context building, and zero-loss English facts retrieval. |
| 🏗️ **Overall Technical Architecture** | [`rough_Technical_Architecture.md`](file:///c:/xampp/htdocs/edvora.chat/rough_Technical_Architecture.md) | System overview, database ERD diagram, Redis queue worker execution, Supervisor process management, and Apache reverse proxy config. |

---

## 🚨 Department & Team Management Fix Directive

For all bug fixing, updates, API modifications, or UI enhancements relating to **Department / Team Management**, you **MUST** refer directly to:
👉 [`architecture_department_team_management.md`](file:///c:/xampp/htdocs/edvora.chat/architecture_department_team_management.md)

This file contains the complete database schema, REST API endpoint definitions, seeder logic, frontend state management, LLM system prompt integration, and widget intent pill rendering logic.
