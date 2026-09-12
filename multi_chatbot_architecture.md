# edvora.chat — Multi-Chatbot Architecture for Colleges & Universities

This document details how **edvora.chat** handles multi-chatbot configurations for colleges and universities with multiple departments or schools.

---

## 🎯 Architecture Truth: Option 3

> **A college can create multiple chatbots (specific for each department/school), and ALL chatbots can answer questions pertaining to ANY department regardless of which department page they are embedded on.**

---

## 🧠 Architectural Design Principles

### 1. Knowledge Base Scoping is Tenant-Level (`organization_id`)
- All uploaded PDFs, documents, URLs, and text pastes belong to the **College / University (Organization)**.
- When a student asks a question to *any* chatbot belonging to that college, the **`ContentEngine`** searches across the college's entire knowledge base.

### 2. Chatbots are Persona & Touchpoint Scopes (`bot_token`)
- Each chatbot has its own unique **`bot_token`**, **`name`**, **`welcome_message`**, **`primary_color`**, and optional **`system_prompt_override`**.
- This allows a university to create distinct personas (e.g. *Engineering Bot* vs *Business School Bot*) while backed by a single unified knowledge base.

---

## 📍 Chatbot Placement Strategy

### 1. Main University Homepage (`www.college.edu`)
- Embed the **Primary / General Admissions Chatbot** (`data-bot-token="PRIMARY_BOT_TOKEN"`).
- **Welcome Greeting:**  
  > *"Welcome to Delhi Tech! How can I assist you with programs, fees, or admissions today?"*

### 2. Department / School Pages (`www.college.edu/engineering` or `/business`)
- Embed the **Department-Specific Chatbot** (e.g. `data-bot-token="ENGINEERING_BOT_TOKEN"`).
- **Welcome Greeting:**  
  > *"Hi there! Welcome to the School of Engineering. Ask me about B.Tech CSE, Labs, or Placements!"*

---

## ⭐ Why This Design is Superior for Universities

| Student Scenario | What Happens | Result |
| :--- | :--- | :--- |
| Student on **Main Homepage** asks *"What is B.Tech fee?"* | The **General Bot** queries the college knowledge base and answers accurately. | ✅ Accurate Answer |
| Student on **Engineering Page** asks *"What is B.Tech fee?"* | The **Engineering Bot** queries the college knowledge base and answers accurately. | ✅ Accurate Answer |
| Student on **Engineering Page** asks *"Do you also offer MBA?"* | The **Engineering Bot** **still answers accurately** (informs them about MBA fees/eligibility) because it has access to the full college knowledge base. | ✅ Accurate Cross-Department Answer |

Because all chatbots belong to the same college tenant (`organization_id`), a department bot will **never fail** or say *"I don't know"* if a student asks a cross-department question. At the same time, each department gets personalized greetings and custom branding colors!
