# SaaS AI Admissions Chatbot — Master Technical Architecture

## 1. Architecture Overview

The platform is a multi-tenant SaaS application that allows colleges to deploy AI-powered admissions and parent/student assistants on their websites.

The platform should support:

* Multiple colleges/organizations
* Multiple chatbots per college
* Multiple departments/teams
* Multiple programs
* Multiple campuses/websites
* Multiple staff/counselors
* College-provided documents and URLs as AI knowledge
* Lead generation and qualification
* Human handoff
* Admissions workflows
* CRM
* Analytics
* Integrations
* White-label branding

### Core architecture

```text
                         SaaS PLATFORM
                              │
                     ┌────────┴────────┐
                     │                 │
               Super Admin       College/Tenant
                                       │
             ┌───────────────┬─────────┼──────────────┐
             │               │         │              │
          Chatbots       Departments  Programs       Staff
             │               │         │              │
             └───────────────┴─────────┼──────────────┘
                                       │
                              Knowledge Content
                              ┌────────┴────────┐
                              │                 │
                          Documents            URLs
                              │                 │
                              └────────┬────────┘
                                       │
                              Content Processing
                                       │
                                       ▼
                              AI Context System
                                       │
                                       ▼
                                  LLM Engine
                                       │
                    ┌──────────────────┼──────────────────┐
                    │                  │                  │
                  Chat             Leads              Actions
                    │                  │                  │
                    ▼                  ▼                  ▼
              Conversation           CRM          Appointments /
              / Handoff                         Applications /
                                                Events / etc.
```

---

# 2. Infrastructure Architecture

The system should initially run on a **single VPS**.

The architecture should nevertheless keep major components logically separated so they can later be moved to separate servers/services.

### Initial deployment

```text
Single VPS
│
├── Web Application
├── API
├── Background Workers
├── Database
├── Document Storage
├── Content Processing
└── Scheduled Jobs
```

Later:

```text
Load Balancer
      │
 ┌────┼────┐
 ▼    ▼    ▼
App  App   App
 │
 ├── Database Cluster
 ├── Worker Cluster
 ├── Object Storage
 └── External AI Services
```

### Markdown

**`01_infrastructure_and_scalability.md`**

---

# 3. Landing Page & Authentication

Public SaaS website with:

* Landing page
* Features
* Pricing
* Demo
* Login
* Signup
* Forgot password
* Email verification
* Organization creation
* User onboarding

### Markdown

**`02_landing_page_and_authentication.md`**

---

# 4. Multi-Tenant Organization / College

Each college is an independent tenant.

```text
Platform
│
├── College A
│   ├── Chatbots
│   ├── Departments
│   ├── Staff
│   ├── Programs
│   ├── Leads
│   └── Conversations
│
└── College B
    ├── Chatbots
    ├── Departments
    ├── Staff
    ├── Programs
    ├── Leads
    └── Conversations
```

Tenant data must never leak between organizations.

### Markdown

**`03_organization_and_multi_tenancy.md`**

---

# 5. Department / Team Management

Colleges can create or customize departments.

Example:

```text
College
│
├── Admissions
├── Finance
├── Placements
├── Hostel
├── Scholarships
├── International
└── Student Services
```

Departments can have:

* Staff
* Knowledge content
* Working hours
* Escalation rules
* Lead assignment rules
* Contact details
* Department-specific chatbot behavior

### Markdown

**`04_department_and_team_management.md`**

---

# 6. Staff & Counselor Management

Manage:

* Staff
* Counselors
* Roles
* Departments
* Availability
* Working hours
* Program expertise
* Lead capacity
* Permissions

### Markdown

**`05_staff_and_counselor_management.md`**

---

# 7. Chatbot Management

Each college can create multiple bots.

```text
College
│
├── Main Website Bot
├── MBA Admissions Bot
├── Executive MBA Bot
└── International Admissions Bot
```

Each chatbot can have:

* Name
* Personality
* Instructions
* Departments
* Programs
* Knowledge sources
* Lead settings
* Handoff settings
* Branding
* Deployment settings

### Markdown

**`06_chatbot_management.md`**

---

# 8. Program Management

Programs are first-class entities.

Examples:

* MBA
* PGDM
* Executive MBA
* Online MBA
* PhD
* Certificate Programs

Each program can contain:

* Eligibility
* Fees
* Duration
* Specializations
* Admission process
* Deadlines
* Placement information
* Application URL
* Related department

### Markdown

**`07_program_management.md`**

---

# 9. Campus & Website Management

Support:

* Multiple campuses
* Multiple college websites
* Multiple domains
* Campus-specific information
* Website-specific chatbot configuration

### Markdown

**`08_campus_and_website_management.md`**

---

# 10. Knowledge / Content Management

This is deliberately a **simple content architecture**.

The college does NOT need to configure a vector database.

The college provides:

### Documents

* PDF
* DOCX
* TXT
* Markdown
* CSV
* Other supported documents

### URLs

The college can provide:

```text
https://college.edu/mba
https://college.edu/admissions
https://college.edu/fees
https://college.edu/placements
```

The system extracts and stores the content.

### Markdown

**`09_knowledge_content_management.md`**

---

# 11. Document Processing

Uploaded documents go through:

```text
Upload
  ↓
Validate
  ↓
Extract Text
  ↓
Clean Content
  ↓
Store Content
  ↓
Mark as Approved
```

Store:

* Original file
* Extracted text
* Document name
* Source
* Version
* Upload date
* Department
* Program
* Approval status

There is **no embedding/vectorization requirement**.

### Markdown

**`10_document_processing.md`**

---

# 12. Website URL Content

The college can provide important URLs instead of uploading documents.

Example:

```text
MBA Admissions
https://college.edu/mba-admissions

Fees
https://college.edu/mba-fees

Placements
https://college.edu/placements
```

The system:

```text
URL
 ↓
Fetch page
 ↓
Extract useful content
 ↓
Clean HTML
 ↓
Store content
```

The system can periodically refresh the content.

### Markdown

**`11_url_content_processing.md`**

---

# 13. Website Crawling

Optional automated crawling can allow a college to provide:

```text
https://college.edu
```

The system can discover relevant pages and import their content.

Crawling should support:

* Sitemap
* Internal links
* URL limits
* Allowed paths
* Excluded paths
* Crawl frequency
* Failed pages
* Content updates

### Markdown

**`12_website_crawling.md`**

---

# 14. Content Context System

Instead of RAG/vector search, use a simpler **content-context selection system**.

```text
User Question
      ↓
Determine Topic
      ↓
Determine Department
      ↓
Determine Program
      ↓
Select Relevant Stored Content
      ↓
Build LLM Context
      ↓
LLM
      ↓
Answer
```

For example:

```text
User:
"What is the MBA fee?"

System:
Topic = Fees
Program = MBA
Department = Finance/Admissions

Context:
MBA Fees Content
+
Approved Fee Document
+
Relevant College Instructions

        ↓

LLM
```

The system should only send the relevant content needed for the answer rather than the entire college database.

### Markdown

**`13_content_context_engine.md`**

---

# 15. Source-Controlled Answers

Every knowledge source should have:

* Source
* Version
* Approval status
* Department
* Program
* Effective date
* Expiry date where applicable

The AI should prefer approved content.

Example:

```text
Answer:
MBA tuition fee is ₹8,50,000.

Source:
MBA Fee Structure 2026
Status: Approved
```

### Markdown

**`14_source_controlled_answers.md`**

---

# 16. AI Model Architecture

The platform should initially use **one LLM configuration controlled by the Super Admin**.

```text
Super Admin
     │
     ▼
LLM Provider
     │
     ▼
Selected Model
     │
     ▼
All College Chatbots
```

Super Admin controls:

* LLM provider
* Model
* API key
* Temperature
* Token limits
* Global AI settings
* Global system instructions

Colleges do not need their own LLM API keys.

### Markdown

**`15_llm_provider_and_model_management.md`**

---

# 17. AI Prompt Architecture

Prompt construction:

```text
Global Platform Instructions
          +
College Instructions
          +
Department Instructions
          +
Chatbot Instructions
          +
Page Context
          +
Conversation Context
          +
Selected College Content
          +
User Message
          ↓
         LLM
```

### Markdown

**`16_ai_prompt_architecture.md`**

---

# 18. AI Guardrails

Control what the AI can and cannot do.

Examples:

### Allowed

* Explain programs
* Answer admission questions
* Explain fees
* Capture leads
* Book appointments

### Not allowed

* Promise admission
* Invent fees
* Guarantee placement
* Invent deadlines
* Make unauthorized eligibility decisions

### Markdown

**`17_ai_guardrails.md`**

---

# 19. Anti-Hallucination Controls

The AI should:

* Answer from approved college content
* Avoid unsupported claims
* Say when information is unavailable
* Offer human handoff
* Avoid inventing numbers
* Respect source validity
* Follow confidence rules

### Markdown

**`18_anti_hallucination_controls.md`**

---

# 20. Human Approval

Sensitive answers can require counselor/admin approval.

Example:

```text
Student asks sensitive question
          ↓
AI detects sensitive topic
          ↓
Approval required
          ↓
Human reviews answer
          ↓
Approve / Edit / Reject
```

### Markdown

**`19_human_approval_workflow.md`**

---

# 21. Admissions & Lead Generation

The chatbot should capture:

* Name
* Phone
* Email
* Program
* Location
* Entrance exam
* Graduation
* Work experience
* Admission interest

### Markdown

**`20_admissions_and_lead_generation.md`**

---

# 22. Lead Qualification

The chatbot can ask dynamic qualification questions.

Example:

```text
Program?
     ↓
Entrance exam?
     ↓
Score?
     ↓
Graduation?
     ↓
Experience?
     ↓
Admission intent?
```

### Markdown

**`21_lead_qualification.md`**

---

# 23. Lead Scoring

Assign a score based on:

* Program interest
* Entrance score
* Application intent
* Fee questions
* Scholarship interest
* Callback request
* Appointment request
* Conversation behavior

Classify:

```text
HOT
WARM
COLD
```

### Markdown

**`22_lead_scoring.md`**

---

# 24. Lead CRM

Manage:

* Lead profile
* Status
* Program
* Source
* Campaign
* Score
* Counselor
* Notes
* Activity history
* Conversion

### Markdown

**`23_lead_crm.md`**

---

# 25. Counselor Assignment

Support:

* Manual assignment
* Round robin
* Department-based
* Program-based
* Availability-based
* Capacity-based
* Location-based

### Markdown

**`24_counselor_assignment.md`**

---

# 26. Human Handoff

Support:

```text
AI
 ↓
Department
 ↓
Counselor
```

Handoff triggers can include:

* User request
* Low confidence
* Sensitive question
* Complaint
* High-value lead
* Explicit callback request

### Markdown

**`25_human_handoff.md`**

---

# 27. WhatsApp Integration

Support:

* WhatsApp Business
* Incoming messages
* Outgoing messages
* Templates
* Lead synchronization
* Conversation synchronization
* Human handoff
* Webhooks

### Markdown

**`26_whatsapp_integration.md`**

---

# 28. Multilingual Support

Support multiple languages while using the same approved college content.

```text
College Content
      ↓
English / Hindi / Other Language
      ↓
LLM Response
```

### Markdown

**`27_multilingual_support.md`**

---

# 29. Appointment Booking

Support:

* Counselor availability
* Time slots
* Booking
* Rescheduling
* Cancellation
* Reminders
* Calendar integration

### Markdown

**`28_appointment_booking.md`**

---

# 30. Campus Visit Booking

Support:

* Campus
* Date
* Time
* Visitor details
* Number of visitors
* Host/counselor
* Confirmation
* Reminders

### Markdown

**`29_campus_visit_booking.md`**

---

# 31. Webinar / Open Day Registration

Support:

* Event creation
* Registration
* Capacity
* Confirmation
* Reminders
* Attendance

### Markdown

**`30_event_registration.md`**

---

# 32. Scholarship Assistant

Use approved scholarship content and rules.

The chatbot can:

* Ask eligibility questions
* Identify potentially relevant scholarships
* Explain requirements
* Provide application information
* Escalate uncertain cases

### Markdown

**`31_scholarship_assistant.md`**

---

# 33. Fees Calculator

Calculate using structured college-provided fee data.

```text
Tuition
+
Hostel
+
Other Fees
-
Scholarship
=
Estimated Cost
```

Fee data must be versioned by academic year.

### Markdown

**`32_fees_calculator.md`**

---

# 34. ROI / Placement Assistant

Use approved placement information.

Support:

* Average package
* Median package
* Highest package
* Placement percentage
* Recruiters
* Program outcomes

### Markdown

**`33_roi_and_placement_assistant.md`**

---

# 35. Program Comparison

Compare programs using structured program data.

Example:

```text
MBA vs PGDM

Eligibility
Fees
Duration
Specializations
Curriculum
Placements
```

### Markdown

**`34_program_comparison.md`**

---

# 36. Competitor Comparison

Use only college-approved competitor information.

The AI must not invent competitor claims.

### Markdown

**`35_competitor_comparison.md`**

---

# 37. Conversation Management

Store:

* Visitor
* Conversation
* Messages
* AI responses
* Human responses
* Department
* Counselor
* Lead
* Program
* Handoff events

### Markdown

**`36_conversation_management.md`**

---

# 38. Notifications

Support notifications for:

* New lead
* Hot lead
* Handoff
* Appointment
* Application event
* Counselor assignment
* System events

### Markdown

**`37_notifications.md`**

---

# 39. Working Hours

Configure:

* College hours
* Department hours
* Counselor hours
* Holidays
* Time zone
* After-hours behavior

### Markdown

**`38_working_hours.md`**

---

# 40. Escalation Rules

Rules can trigger escalation based on:

* Intent
* Keywords
* AI confidence
* Sensitive topics
* Complaints
* High-value leads
* Department
* Program

### Markdown

**`39_escalation_rules.md`**

---

# 41. Admin Controls

Super Admin and College Admin controls.

### Super Admin

* Organizations
* LLM
* Plans
* Usage
* System settings
* Platform configuration

### College Admin

* Chatbots
* Departments
* Staff
* Programs
* Knowledge
* Leads
* Analytics
* Integrations
* Branding

### Markdown

**`40_admin_controls.md`**

---

# 42. Roles & Permissions

Roles may include:

```text
Organization Owner
Admin
Department Manager
Counselor
Knowledge Manager
Analyst
```

Permissions should be resource-based.

### Markdown

**`41_roles_and_permissions.md`**

---

# 43. Branding / White Label

Allow colleges to customize:

* Logo
* Colors
* Bot name
* Avatar
* Welcome message
* Chat launcher
* Widget appearance

### Markdown

**`42_branding_and_white_label.md`**

---

# 44. Multiple Websites / Campuses

Support:

```text
College
│
├── Campus A
│   └── Website
│
├── Campus B
│   └── Website
│
└── Campus C
    └── Website
```

Each can have specific content and chatbot configuration.

### Markdown

**`43_multi_website_and_campus.md`**

---

# 45. Analytics Dashboard

Track:

* Visitors
* Conversations
* Leads
* Qualified leads
* Hot leads
* Handoffs
* Appointments
* Applications
* Conversions
* Popular questions
* Programs
* Departments
* Counselors

### Markdown

**`44_analytics_dashboard.md`**

---

# 46. Conversion Analytics

Track:

```text
Visitor
 ↓
Chat
 ↓
Lead
 ↓
Qualified Lead
 ↓
Counselor
 ↓
Application
 ↓
Enrollment
```

Track conversion rates at every stage.

### Markdown

**`45_conversion_analytics.md`**

---

# 47. Proactive Chat

Trigger chat based on:

* Time on page
* Page
* Exit intent
* Returning visitor
* Campaign
* Program

### Markdown

**`46_proactive_chat.md`**

---

# 48. Page-Aware Intelligence

Pass current website context to the chatbot.

Example:

```text
Current page:
MBA Admissions

Suggested topics:
Eligibility
Fees
Application
Scholarships
```

The current page becomes part of the AI context.

### Markdown

**`47_page_aware_intelligence.md`**

---

# 49. Campaign Tracking

Capture:

* UTM source
* UTM medium
* UTM campaign
* Google Ads
* Meta
* LinkedIn
* Email
* Organic
* Referral

Track:

```text
Campaign
 ↓
Visitor
 ↓
Chat
 ↓
Lead
 ↓
Application
 ↓
Enrollment
```

### Markdown

**`48_campaign_tracking.md`**

---

# 50. Chatbot Deployment

Provide:

* JavaScript widget
* WordPress integration
* iframe
* API
* Domain verification

Basic deployment:

```html
<script
    src="https://cdn.example.com/chatbot.js"
    data-chatbot="CHATBOT_ID">
</script>
```

### Markdown

**`49_chatbot_deployment.md`**

---

# 51. Integrations

Create a generic integration framework.

Potential integrations:

```text
CRM
├── Salesforce
├── HubSpot
└── Zoho

Communication
├── WhatsApp
├── Email
└── SMS

Calendar
├── Google Calendar
└── Microsoft Calendar

College Systems
├── Admission System
├── ERP
└── Student Information System
```

### Markdown

**`50_integrations_framework.md`**

---

# 52. API Architecture

The platform should expose APIs for:

* Authentication
* Organizations
* Chatbots
* Departments
* Staff
* Programs
* Knowledge
* Leads
* Conversations
* Appointments
* Events
* Analytics
* Integrations

### Markdown

**`51_api_architecture.md`**

---

# 53. Webhook Architecture

Support:

* Incoming webhooks
* Outgoing webhooks
* Event subscriptions
* Signature verification
* Retry
* Idempotency
* Delivery logs

### Markdown

**`52_webhook_architecture.md`**

---

# 54. Background Jobs

Use background workers for operations that don't need to block user requests.

Examples:

* Document processing
* URL fetching
* Website crawling
* Content refresh
* Notifications
* Analytics processing
* Webhooks
* Scheduled tasks

### Markdown

**`53_background_jobs.md`**

---

# 55. Database Architecture

Core entities:

```text
Organization
User
Role
Department
Staff
Chatbot
Program
Campus
Website

KnowledgeSource
Document
DocumentContent
URLSource
URLContent

Lead
LeadScore
Conversation
Message

Appointment
Event
Application

Campaign
Integration
Notification
AnalyticsEvent
AuditLog

Subscription
Usage
```

### Markdown

**`54_database_architecture.md`**

---

# 56. Security & Privacy

Implement:

* HTTPS
* Authentication
* Authorization
* RBAC
* Tenant isolation
* Encryption
* Secure API keys
* Rate limiting
* Data retention
* Data deletion
* Consent
* Audit logs

### Markdown

**`55_security_and_privacy.md`**

---

# 57. Audit Logging

Track important administrative actions:

* Login
* User changes
* Permission changes
* Knowledge changes
* AI configuration
* Lead changes
* Conversation access
* Approval actions
* Integration changes

### Markdown

**`56_audit_logging.md`**

---

# 58. Billing & Subscription

SaaS billing should support:

* Plans
* Trial
* Subscription
* Usage limits
* AI usage
* Message limits
* Chatbot limits
* Staff limits
* Billing history
* Invoices
* Upgrade/downgrade

### Markdown

**`57_billing_and_subscription.md`**

---

# 59. Usage Metering

Track:

* LLM tokens
* Messages
* Conversations
* Leads
* Documents
* Storage
* Crawls
* API calls
* WhatsApp messages

### Markdown

**`58_usage_metering.md`**

---

# 60. Platform Observability

Monitor:

* Application errors
* API latency
* LLM latency
* LLM usage
* Database performance
* Background jobs
* Failed crawls
* Failed integrations
* Webhooks
* System uptime

### Markdown

**`59_platform_observability.md`**

---

# 61. Backup & Disaster Recovery

Implement:

* Database backups
* Document backups
* Configuration backups
* Retention
* Restore procedures
* Disaster recovery

### Markdown

**`60_backup_and_disaster_recovery.md`**

---

# 62. Simplified AI Knowledge Architecture

The important architectural decision is:

```text
                COLLEGE
                   │
          ┌────────┴────────┐
          │                 │
      Documents            URLs
          │                 │
          ▼                 ▼
     Text Extraction    Page Extraction
          │                 │
          └────────┬────────┘
                   ▼
             Stored Content
                   │
                   ▼
          Content Context Engine
                   │
        ┌──────────┼──────────┐
        │          │          │
      Program   Department   Page
        │          │          │
        └──────────┼──────────┘
                   ▼
             Relevant Content
                   │
                   ▼
              Prompt Builder
                   │
                   ▼
                  LLM
                   │
                   ▼
                Answer
```

### Explicitly NOT required initially

```text
❌ Vector Database
❌ Embeddings
❌ Vector Search
❌ RAG Pipeline
❌ Reranking
❌ Separate Retrieval Service
```

The system instead uses **stored college content + structured metadata + context selection + LLM**.

---

# 63. Final Product Hierarchy

```text
COLLEGE
│
├── Dashboard
│
├── Chatbots
│   ├── Main Website Bot
│   ├── MBA Admissions Bot
│   └── Executive MBA Bot
│
├── Departments
│   ├── Admissions
│   ├── Finance
│   ├── Placements
│   ├── Hostel
│   └── Scholarships
│
├── Programs
│   ├── MBA
│   ├── PGDM
│   └── Executive MBA
│
├── Staff
│   ├── Counselors
│   └── Department Staff
│
├── Knowledge
│   ├── Documents
│   └── URLs
│
├── Leads
│
├── Conversations
│
├── Appointments
│
├── Events
│
├── Applications
│
├── Campaigns
│
├── Analytics
│
├── Integrations
│
├── AI Controls
│
├── Branding
│
└── Settings
```

---

# 64. Core Architectural Philosophy

The MVP should remain deliberately simple:

```text
College
   │
   ├── Provides documents
   ├── Provides important URLs
   ├── Creates departments
   ├── Creates programs
   ├── Adds counselors
   └── Configures chatbot
             │
             ▼
       Content Processing
             │
             ▼
       Context Selection
             │
             ▼
       Single Platform LLM
             │
             ▼
       AI Admissions Assistant
             │
       ┌─────┼─────┐
       ▼     ▼     ▼
     Leads  CRM  Handoff
```

Start with **one VPS + one platform-controlled LLM + database + document/URL content storage**.

As usage grows, the infrastructure can be separated and scaled without changing the core product architecture.
