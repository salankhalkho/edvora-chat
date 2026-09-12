# edvora.chat — Access, Login & How It Works Guide

This document explains how to access the platform, log in as a College Admin or Super Admin, embed the chatbot widget, and understand how the system works.

---

## 🔑 Login & Access Instructions

### 1. Public Landing Page
- **URL:** [https://edvora.chat/](https://edvora.chat/)
- **Description:** The homepage displays edvora.chat features, value propositions, and subscription pricing plans.
- **Actions:**
  - Click **"Sign In"** (top right or footer) to open the College Admin Login interface.
  - Click **"Register College"** or **"Onboard Your College Free"** to register a new college account in <60 seconds.
  - Click **"Super Admin"** to access the Super Admin Control Center.

---

### 2. College Admin Dashboard Login
- **URL:** [https://edvora.chat/app](https://edvora.chat/app)
- **Who uses it:** College Admissions Deans, Marketing Directors, and Admissions Counselors.
- **Features:**
  - **Overview:** View total conversations, messages, student leads, and conversion rate %.
  - **Knowledge Base:** Upload PDFs, DOCX, TXT files, submit webpage URLs, or paste text. View dual content (`raw_content` vs `processed_content`) and extracted keywords.
  - **Student Leads:** View captured leads (Name, Email, Phone, Program Interest), update status (`new`, `contacted`, `converted`), add counselor notes, and download CSV export.
  - **Chatbot Settings:** Customize chatbot name, welcome message, primary color picker, and copy the embed script snippet.
  - **College Profile:** Update college name, primary branding color, and view subscription plan details.

---

### 3. Super Admin Panel Login
- **URL:** [https://edvora.chat/superadmin](https://edvora.chat/superadmin)
- **Who uses it:** Platform Owners & Super Admins.
- **Default Credentials:**
  - **Email:** `superadmin@edvora.chat`
  - **Password:** `SuperAdmin_Secure2026!`
- **Features:**
  - **Master Prompt:** Live edit the global Master Prompt template with variable injection (`{{COLLEGE_NAME}}`, `{{KNOWLEDGE_CONTEXT}}`).
  - **Multi-LLM Providers:** Manage OpenAI, Gemini, Groq, and Anthropic gateways. Set `Primary` vs `Fallback` failover roles and encrypt API keys using AES-256.
  - **College Tenants:** View all registered colleges, total users, chatbots, leads count, and toggle active/inactive subscription status.

---

## 💻 How to Embed Chatbot Widget on College Website

To embed the edvora.chat AI Admissions Assistant on any college website:

1. Log in to the College Admin Dashboard at `https://edvora.chat/app`.
2. Go to **Chatbot Settings** and copy your unique embed code snippet:
   ```html
   <script src="https://edvora.chat/widget.js" data-bot-token="YOUR_BOT_TOKEN" async></script>
   ```
3. Paste this single script tag into your college website's HTML before the closing `</body>` tag.
4. The floating chat button with your college's primary color will immediately appear on your website!
