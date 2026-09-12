# edvora.chat — Multi-Lingual Architecture & Query Translation Specification

This document details the technical specification and architectural workflow for **Multi-Lingual Chatbot Support** (Hindi, Tamil, Telugu, Spanish, and Romanized scripts) in **edvora.chat**.

---

## 🎯 Architecture Overview & Query Translation Directive

### **Key Architectural Rule:**
> **No direct MariaDB FULLTEXT search queries on native non-English scripts (Hindi, Tamil, Telugu, Spanish, Hinglish). Every non-English visitor query MUST first be translated into English before performing the knowledge base retrieval.**

---

## 🔄 5-Step Execution Workflow

```
[Visitor Question in Native Script / Non-English]
  (e.g., "बी.टेक कंप्यूटर साइंस की फीस कितनी है?")
                          │
                          ▼
 1. Query Language Detection & English Translation
  (Translate: "What is the fee for B.Tech Computer Science?")
                          │
                          ▼
 2. Normal English ContentEngine Retrieval
  (FULLTEXT & LIKE Search against English title, keywords, processed_content)
                          │
                          ▼
 3. Retrieve Top 1 to 3 English Knowledge Context Blocks
  ("B.Tech CS Tuition Fee: ₹1,85,000 per year...")
                          │
                          ▼
 4. Prompt Assembly & Multilingual LLM Completion
  (System Instructions: Answer the question using the English Context, but reply in visitor's original script/language)
                          │
                          ▼
 5. Output Response to Visitor in Original Language/Script
  ("बी.टेक कंप्यूटर साइंस की वार्षिक ट्यूशन फीस ₹1,85,000 प्रति वर्ष है...")
```

---

## 🎭 Real-World Scenarios

### Scenario A: Visitor asks in Hindi Script (देवनागरी)
1. **Visitor Query:** *"बी.टेक कंप्यूटर साइंस की फीस और हॉस्टल का खर्चा कितना है?"*
2. **Translation Step:** Transformed into English search query: `"B.Tech Computer Science fees and hostel charges"`
3. **Retrieval Step:** Matches English knowledge source: *B.Tech Fees and Hostel Fee Structure 2026*.
4. **LLM Response Generation:** LLM synthesizes answer using English context and responds in Hindi Devanagari script:  
   > *"बी.टेक कंप्यूटर साइंस की वार्षिक ट्यूशन फीस ₹1,85,000 प्रति वर्ष है और हॉस्टल फीस ₹65,000 प्रति वर्ष है..."*

### Scenario B: Visitor asks in Romanized Hindi (Hinglish)
1. **Visitor Query:** *"B.Tech CS ki fees aur hostel charges kitna hai?"*
2. **Translation Step:** Transformed into English search query: `"B.Tech Computer Science tuition and hostel fees"`
3. **Retrieval Step:** Matches English knowledge source.
4. **LLM Response Generation:** Responds in natural Hinglish:  
   > *"B.Tech Computer Science ki annual tuition fee ₹1,85,000 per year hai aur hostel fee ₹65,000 per year hai..."*

### Scenario C: Visitor asks in Tamil Script (தமிழ்)
1. **Visitor Query:** *"கணினி அறிவியல் சேர்க்கை தகுதி என்ன?"*
2. **Translation Step:** Transformed into English search query: `"Computer Science admission eligibility criteria"`
3. **Retrieval Step:** Matches English knowledge source.
4. **LLM Response Generation:** Responds in natural Tamil script.

### Scenario D: Visitor asks in Spanish (Español)
1. **Visitor Query:** *"¿Cuáles son los requisitos de admisión para B.Tech?"*
2. **Translation Step:** Transformed into English search query: `"B.Tech admission requirements eligibility"`
3. **Retrieval Step:** Matches English knowledge source.
4. **LLM Response Generation:** Responds in fluent Spanish.

---

## 🛠️ Application Implementation Plan (Pending Approval)

1. **`QueryTranslator.php` Service (`app/Services/QueryTranslator.php`):**
   - Receives raw visitor query.
   - Detects if non-English / native script.
   - Uses lightweight fast LLM prompt or rule-based translation to produce clean English search terms for `ContentEngine`.

2. **`ChatController.php` Workflow Update:**
   - Translates query $\rightarrow$ Runs `ContentEngine::selectContext($orgId, $englishQuery)` $\rightarrow$ Passes English context + original visitor message to `LlmService::complete()`.

3. **Master Prompt Multilingual Directives:**
   - Instructs LLM to maintain strict fidelity to the English Knowledge Context while generating the answer in the visitor's detected language and script.
