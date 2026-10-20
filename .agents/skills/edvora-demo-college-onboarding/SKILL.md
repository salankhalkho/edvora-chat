---
name: edvora-demo-college-onboarding
description: >
  Autonomously provisions a full-featured demo university/college tenant on Edvora.chat
  given a list of program or institutional URLs. Creates the organization (is_demo=1,
  onboarding_completed=1, onboarding_step=1, plan_id=3, country='United States'), owner admin user,
  active admissions chatbot with default welcome message, ingests and vector-embeds knowledge sources,
  registers academic degree programs, and verifies widget completions.
---

# Edvora Demo College Onboarding Skill

This skill automates the complete onboarding of a new demo college or university into the Edvora multi-tenant platform by supplying a list of web URLs.

## When to Use This Skill
Activate this skill whenever the user asks to:
* "Create a demo account for [College/University Name]"
* "Onboard a new university from these URLs"
* "Set up a demo tenant for [School] with programs"
* "Ingest knowledge and academic programs for a new college"

---

## Technical & Architecture Rules (MANDATORY)

1. **Remote Execution Only:**
   * There is **NO local PHP** and **NO local MySQL**.
   * All script execution runs on the production VPS (`62.171.145.160`) via SSH (`root@62.171.145.160`).
   * Never modify existing production code files during tenant provisioning.
2. **Database Isolation:**
   * Target database is exclusively `edvora_chat`.
   * Multi-tenancy is strictly isolated by `organization_id`.
   * `institute_id` is derived automatically (`md5(organization_id)`) solely for human admin reference.
3. **Tenant Specification:**
   * `is_demo = 1`
   * `onboarding_completed = 1`
   * `onboarding_step = 1`
   * `plan_id = 3` (Pro Plan)
   * `country = 'United States'` (or parameterized)
   * `subscription_status = 'active'`
   * `welcome_message = NULL` (uses platform default message)

---

## Onboarding Procedure

### Step 1: Analyze Input & Structure Payload
From the user's prompt and provided URLs:
1. Extract the College/University name (e.g. *"Columbia University"*, *"Harvard Business School"*).
2. Determine primary website domain from the URLs (e.g. `https://www.columbia.edu`).
3. For each URL, classify if it represents an Academic Program (MBA, MS, BS, PhD, Certificate) or Institutional Knowledge.
4. Compose a JSON payload matching this structure:

```json
{
  "name": "Columbia University - School of Business",
  "website_url": "https://business.columbia.edu",
  "country": "United States",
  "plan_id": 3,
  "admin_email": "demo-columbia-cbs@edvora.chat",
  "admin_password": "DemoPassword2026!",
  "primary_color": "#1D4ED8",
  "urls": [
    "https://business.columbia.edu/mba",
    "https://business.columbia.edu/phd",
    "https://business.columbia.edu/msba"
  ],
  "programs": [
    {
      "course_name": "Full-Time MBA",
      "course_code": "MBA",
      "program_type": "postgraduate",
      "duration": "2 Years",
      "mode": "full_time",
      "is_admissions_open": 1,
      "currency": "USD",
      "eligibility": "Bachelor degree, GMAT/GRE, professional experience.",
      "application_url": "https://business.columbia.edu/mba",
      "source_url": "https://business.columbia.edu/mba"
    }
  ]
}
```

*Note: If specific `programs` array is omitted, the provisioning script will still ingest all URLs into `knowledge_sources`.*

---

### Step 2: Upload Script and Config to VPS
1. Save the payload locally to a scratch file:
   `<appDataDir>\brain\<conversation-id>\scratch\college_config.json`
2. Upload the runner script and config to VPS `/tmp/`:
   ```powershell
   scp -O -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no "<script_path>/onboard_college.php" root@62.171.145.160:/tmp/onboard_college.php
   scp -O -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no "<scratch_path>/college_config.json" root@62.171.145.160:/tmp/college_config.json
   ```

---

### Step 3: Execute Onboarding on Remote Server
Execute the onboarding runner with a generous timeout (`WaitMsBeforeAsync: 10000`):
```powershell
ssh -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no root@62.171.145.160 "php /tmp/onboard_college.php --config=/tmp/college_config.json"
```

The script will:
1. Insert `organizations`, `users`, `chatbots`, `subscriptions`, and `usage_logs`.
2. Scrape each URL and save compacted text to `/var/www/edvora.chat/storage/knowledge/{orgId}/`.
3. Dispatch `chunk_and_embed` jobs to the `jobs` table.
4. Insert academic degree records into `programs` and dispatch `embed_program` jobs.
5. Monitor Supervisor worker progress until all embeddings are generated and active.
6. Print the summary and `RESULT_JSON:{...}`.

Clean up temporary files in `/tmp/`:
```powershell
ssh -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no root@62.171.145.160 "rm -f /tmp/onboard_college.php /tmp/college_config.json"
```

---

### Step 4: Verification via Widget API
Send 3 to 5 realistic prospect questions to test the newly generated chatbot:
```powershell
curl.exe -s -X POST https://edvora.chat/v1/chat/completions `
  -F "bot_token=<NEW_BOT_TOKEN>" `
  -F "message=What programs do you offer and what is the admission criteria?" `
  -F "visitor_id=test_verify_01" `
  -F "is_test=1"
```
Verify that:
* `status === "success"`
* `active_program` is detected if asking about a specific degree.
* `sources_used` returns the relevant ingested knowledge sources.

---

### Step 5: Deliver Completion Report
Output a clean summary table to the user with:
* **Organization ID & Name**
* **Admin Login Email & Password**
* **Admissions Console Link:** `https://edvora.chat/app/#overview`
* **Academic Programs Catalog Link:** `https://edvora.chat/app/#academic-programs`
* **Public Chat Widget Link:** `https://edvora.chat/widget/<bot_token>`
* **Superadmin Link:** `https://edvora.chat/superadmin/demo-accounts`
* Total knowledge sources and embedded chunks.
