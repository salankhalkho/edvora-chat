# Campus Creation & Tour Management Architecture — edvora.chat

> **MANDATORY FOR ALL AI AGENTS & DEVELOPERS:**
> This document defines the end-to-end technical architecture for Campus Entity Creation, Academic Program Mapping, Tour Slot Scheduling, Conversational Chatbot Booking, On-Ground Attendee Manifest & Check-In, Sheet Sharing, Post-Tour Feedback Surveys, and AI Sentiment Synthesis in **edvora.chat**.

---

## 1. Executive Summary & System Scope

Campus visits represent the highest-converting physical lead engagement modality in university admissions. Prospective students who tour a college campus are over **4× more likely to matriculate** than purely digital leads.

The **edvora.chat Campus & Tour Sub-System** connects 6 lifecycle stages:
1. **Campus Entity Management (`#campuses`)**: Multi-campus geographical configuration, facilities metadata, and timezone binding.
2. **Academic Program Mapping (`program_campuses`)**: Associating degree programs with physical campus locations.
3. **Visit Slot Scheduling & Policies (`#campus-tours-scheduling`)**: Defining time windows, capacity limits, assigned counselors, and routing rules.
4. **Chatbot Conversational Discovery & Booking (`widget.js`)**: Real-time slot presentation, qualification, lead capture, and immediate booking confirmation.
5. **On-Ground Attendee Manifest & Check-In (`Phase 1`)**: Slot-scoped rosters, 1-click attendance marking (`Attended` / `No-Show`), printable check-in sheets, and tokenized shareable gatekeeper links for campus security/student ambassadors.
6. **Post-Tour Feedback & AI Synthesis (`Phase 2`)**: Automated/bulk survey dispatch, mobile feedback intake, and LLM-driven executive synthesis of candidate sentiment and facility feedback.

---

## 2. End-to-End Data Flow Diagram

```
[ College Admin ]
       │
       ├─► 1. Defines Campuses (Name, Address, Country/State, Primary Campus)
       ├─► 2. Maps Degree Programs to Campuses (program_campuses)
       └─► 3. Schedules Tour Slots (campus_tour_slots + campus_tour_slot_programs)
                     │
                     ▼
[ Prospective Student on Chatbot Widget (widget.js) ]
       │
       ├─► Asks about visiting / labs / campus life
       ├─► Chatbot detects 'campus_tour' intent & displays interactive slots
       ├─► Student selects slot, enters Name, Email, Phone, Group Size
       └─► POST /v1/campus-tours
                     │
                     ├─► Inserts campus_tour_bookings (status: 'pending')
                     ├─► Increments campus_tour_slots.booked_count
                     ├─► Inserts unified lead into `leads` table (lead_type: 'campus_tour')
                     └─► Sends instant alert email to Admissions Coordinator
                     │
                     ▼
[ Tour Event Day: On-Ground Coordination (Phase 1) ]
       │
       ├─► Admin / Ambassador opens Slot Roster in Admin or via Tokenized Link
       ├─► Mark Attendance: [🟢 Attended] | [⚪ No-Show]
       ├─► If Attended ──► Automatically warms CRM lead intent score to 🔥 HOT (95/100)
       ├─► Download Slot Attendee CSV / Print Check-In Sheet
       └─► Share live Roster Check-In Link with front gate / security guards
                     │
                     ▼
[ Post-Tour Engagement & AI Intelligence (Phase 2) ]
       │
       ├─► System or Admin triggers Feedback Survey to all Attended candidates
       ├─► Student completes mobile survey (/tour-feedback?token=...)
       │     (Ratings: Overall, Facilities, Guide, Intent to Apply, Open Comments)
       ├─► Admin reviews responses in Slot Manifest
       └─► AI Engine (LlmService) generates Executive Synthesis:
             • Sentiment & Net Promoter Score
             • Top Campus Highlights & Facilities Praise
             • Friction Points & Candidate Concerns
             • High-Priority Admissions Follow-up Items
```

---

## 3. Database Architecture & Relationships

### 3.1 ER Diagram

```
┌────────────────────┐
│   organizations    │
└─────────┬──────────┘
          │ 1
          │
          ├───► N ┌────────────────────┐ 1       N ┌───────────────────────────┐
          │       │      campuses      ├──────────►│     program_campuses      │
          │       └────────┬───────────┘           └─────────────┬─────────────┘
          │                │ 1                                   │ N
          │                │                                     ▼
          │                │ N                     ┌───────────────────────────┐
          │       ┌────────▼───────────┐           │         programs          │
          │       │ campus_tour_slots  │           └─────────────┬─────────────┘
          │       └────────┬───────────┘                         │
          │                │ 1                                   │ N
          │                ├───────────► N ┌─────────────────────▼─────────────┐
          │                │               │     campus_tour_slot_programs     │
          │                │               └───────────────────────────────────┘
          │                │ 1
          │                ├───► N ┌───────────────────────────────────────────┐
          │                │       │       campus_tour_roster_tokens           │
          │                │       └───────────────────────────────────────────┘
          │                │ 1
          │                ├───► N ┌───────────────────────────────────────────┐
          │                │       │           campus_tour_bookings            │
          │                │       └──────────────┬────────────────────────────┘
          │                │                      │ 1
          │                │ 1                    │
          │                │                      ▼ N
          │                └───────────► N ┌───────────────────────────────────┐
          │                                │       campus_tour_feedbacks       │
          │                                └───────────────────────────────────┘
```

### 3.2 Schema Specifications

#### 1. `campuses`
Represents physical locations of the institution.
* `id` INT AUTO_INCREMENT PRIMARY KEY
* `organization_id` INT NOT NULL (Multi-tenant partition)
* `name` VARCHAR(255) NOT NULL
* `short_name` VARCHAR(100) NULL
* `is_primary` TINYINT(1) DEFAULT 0 — The primary campus determines institution timezone and default localization.
* `campus_area` VARCHAR(100) NULL (e.g. "50 Acres", "Urban Center")
* `virtual_tour_url` VARCHAR(500) NULL
* `has_hostel` TINYINT(1) DEFAULT 0
* `contact_email` VARCHAR(255) NULL
* `contact_phone` VARCHAR(50) NULL
* `address_line`, `city`, `state`, `country`, `pincode` VARCHAR
* `status` ENUM('active', 'inactive') DEFAULT 'active'

#### 2. `program_campuses` (Junction)
Maps academic programs (`programs.id`) to physical campuses (`campuses.id`). Enables the chatbot to know which degrees are offered at which physical locations.

#### 3. `campus_tour_slots`
Represents distinct visit windows available for candidate booking.
* `id` INT AUTO_INCREMENT PRIMARY KEY
* `organization_id` INT NOT NULL
* `campus_id` INT NOT NULL (FK `campuses.id`)
* `title` VARCHAR(255) NOT NULL DEFAULT 'Guided Campus Visit'
* `is_general` TINYINT(1) DEFAULT 1 (1 = All academic programs, 0 = Specialized tour restricted to mapped programs)
* `tour_date` DATE NOT NULL
* `start_time` TIME NOT NULL
* `end_time` TIME NOT NULL
* `max_capacity` INT DEFAULT 15
* `booked_count` INT DEFAULT 0
* `counselor_user_id` INT NULL (FK `users.id` — assigned tour guide/coordinator)
* `ai_feedback_summary` TEXT NULL (Cached AI feedback summary)
* `ai_feedback_generated_at` TIMESTAMP NULL
* `status` ENUM('active', 'cancelled', 'completed') DEFAULT 'active'

#### 4. `campus_tour_slot_programs` (Junction)
Maps specific degree programs to a tour slot when `is_general = 0`. For example, a specialized "School of Medicine Lab Tour" mapped specifically to MBBS and MD programs.

#### 5. `campus_tour_bookings`
Stores individual candidate reservations.
* `id` INT AUTO_INCREMENT PRIMARY KEY
* `organization_id` INT NOT NULL
* `chatbot_id` INT NOT NULL
* `conversation_id` INT NULL
* `slot_id` INT NULL (FK `campus_tour_slots.id`)
* `program_id` INT NULL (FK `programs.id`)
* `assigned_user_id` INT NULL (FK `users.id`)
* `student_name` VARCHAR(255) NOT NULL
* `student_email` VARCHAR(255) NOT NULL
* `student_phone` VARCHAR(50) NOT NULL
* `preferred_date` DATE NULL
* `preferred_time` VARCHAR(50) DEFAULT 'Morning'
* `program_interest` VARCHAR(255) NULL
* `group_size` TINYINT DEFAULT 1
* `notes` TEXT NULL
* `status` ENUM('pending', 'confirmed', 'attended', 'completed', 'no_show', 'cancelled') DEFAULT 'pending'
* `attended_at` TIMESTAMP NULL (Recorded upon on-ground check-in)
* `check_in_by_user_id` INT NULL
* `check_in_notes` VARCHAR(255) NULL
* `feedback_sent_at` TIMESTAMP NULL

#### 6. `campus_tour_roster_tokens` (Secure Gate / Ambassador Sharing)
Enables college admissions teams to generate temporary or standing unauthenticated access tokens for front-gate security guards, receptionist tablets, or student ambassadors.
* `id` INT AUTO_INCREMENT PRIMARY KEY
* `organization_id` INT NOT NULL
* `slot_id` INT NOT NULL (FK `campus_tour_slots.id`)
* `access_token` VARCHAR(64) UNIQUE NOT NULL
* `created_by_user_id` INT NULL
* `expires_at` DATETIME NOT NULL
* `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP

#### 7. `campus_tour_feedbacks` (Post-Tour Surveys)
Stores verified candidate feedback collected after attending a tour.
* `id` INT AUTO_INCREMENT PRIMARY KEY
* `organization_id` INT NOT NULL
* `booking_id` INT NOT NULL (FK `campus_tour_bookings.id`)
* `slot_id` INT NOT NULL (FK `campus_tour_slots.id`)
* `feedback_token` VARCHAR(64) UNIQUE NOT NULL
* `rating_overall` TINYINT NOT NULL (1 to 5 stars)
* `rating_facilities` TINYINT NULL (1 to 5 stars)
* `rating_guide` TINYINT NULL (1 to 5 stars)
* `intent_to_apply` ENUM('definitely', 'likely', 'exploring', 'unlikely') DEFAULT 'likely'
* `highlight_text` TEXT NULL (What impressed them most)
* `improvement_text` TEXT NULL (What could be improved)
* `pending_questions` TEXT NULL (Unresolved questions for admissions team)
* `submitted_at` TIMESTAMP NULL
* `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP

---

## 4. Operational Workflows

### 4.1 Chatbot Booking Flow (`widget.js` & Backend)
1. When a visitor expresses interest in visiting or touring the campus, the AI conversational turn emits an `admissions_response` with action type `'campus_tour'`.
2. The widget client fetches available tour schedules from `/v1/campus-tours/slots?bot_token=...`.
3. If the candidate was discussing a specific academic program, the endpoint automatically filters for slots that either:
   - Match that program in `campus_tour_slot_programs`, OR
   - Have `is_general = 1`.
4. The candidate clicks an interactive schedule pill or selects their preferred date/time.
5. The form posts to `POST /v1/campus-tours`.
6. Atomic operations occur:
   - Insertion into `campus_tour_bookings`.
   - Increment of `booked_count` on `campus_tour_slots`.
   - Unified registration into `leads` table with `lead_type = 'campus_tour'`.
   - Update of `conversations` table (`lead_capture_trigger = 'campus_tour'`).
   - Email dispatch to institution admissions owner via `EmailService::sendCampusTourNotification`.

---

### 4.2 Phase 1: Slot Attendee Manifest, Check-In & Gate Sharing

#### Slot Attendee Manifest UI
In `#campus-tours-scheduling`, each slot row includes a prominent action:
* **`[ 👥 View Roster (N) ]`**
Clicking this opens the **Slot Attendee Console** displaying:
* Slot Metadata: Campus, Date, Time, Guide, and Progress Bar:
  - `Booked: N / Max Capacity`
  - `Attended: A` | `No-Show: B` | `Pending: C`
* Quick Candidate Actions:
  - 1-Click WhatsApp (`https://wa.me/...`) with pre-filled greeting.
  - 1-Click Phone Call (`tel:...`).
* 1-Click Attendance Toggles:
  - `[🟢 Attended]` | `[⚪ No-Show]` | `[🟡 Pending]`
* When an attendee is marked `Attended`:
  - `campus_tour_bookings.status = 'attended'`
  - `campus_tour_bookings.attended_at = NOW()`
  - The linked record in `leads` has its `intent_score` boosted to `95` (🔥 Hot Lead).

#### Sheet Export & Gate Check-in Link
Admissions teams often collaborate with on-ground personnel:
1. **Download CSV**: Downloads a clean CSV formatted with institution timezone timestamps (`tenant-tz-label`).
2. **Print Check-In Sheet**: Opens a clean, ink-friendly printable HTML table with signature lines and check boxes for physical clipboard check-in.
3. **Share Gate Link (`campus_tour_roster_tokens`)**:
   - Generates a tokenized URL: `https://edvora.chat/gate-checkin.html?token={TOKEN}`.
   - Usable on mobile/tablet at the campus gate or reception desk.
   - Allows gate staff to search names and tap `[Check In]` in real time without needing SaaS credentials.

---

### 4.3 Phase 2: Post-Tour Feedback & AI Synthesis

#### Feedback Dispatch Options
1. **Automated Trigger**: When a slot concludes (or status moves to completed), survey emails are sent to all attendees who checked in.
2. **Manual Bulk Trigger**: Inside the Slot Attendee Console, the admin clicks **`[ 📩 Send Feedback to Attended (N) ]`**.

#### Student Survey Intake Form
A dedicated lightweight public page (`https://edvora.chat/tour-feedback.html?token={TOKEN}`):
* 5-Star Ratings for Overall Experience, Academic Labs/Facilities, and Student Guide.
* Single-select Intent to Apply: *Definitely*, *Likely*, *Still Exploring*, *Unlikely*.
* Qualitative Feedback: Highlights, Concerns/Suggestions, and Pending Questions.

#### AI-Powered Feedback Synthesis (`LlmService`)
Rather than forcing admissions officers to read through dozens of individual responses, Edvora's LLM engine parses all qualitative and quantitative submissions for the slot and generates an **Executive Intelligence Report**:
1. **Executive Impression**: Overall candidate sentiment and satisfaction score.
2. **Key Highlights & Praise**: What stood out to attendees (e.g. labs, campus greenery, friendly staff).
3. **Friction Points & Concerns**: Areas that caused hesitation (e.g. hostel conditions, distance, pricing confusion).
4. **Actionable Admissions Directives**: Specific high-intent candidates needing immediate counselor callbacks or scholarship guidance.

---

## 5. Security & Multi-Tenancy Invariants

1. **Mandatory Tenant Isolation**: Every query must include `WHERE organization_id = :org_id`.
2. **Token Security**: Roster tokens and feedback tokens use cryptographically random 64-character hex strings generated via `bin2hex(random_bytes(32))`.
3. **Expiry Enforcement**: Gate roster tokens expire automatically after 48 hours unless refreshed. Feedback tokens become read-only once submitted.
4. **Human-Facing ID Rule**: `organizations.institute_id` is strictly for human reference and must NEVER be used in code logic or query filtering. Always use `organizations.id`.

---

## 6. API Route Reference

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| `GET` | `/v1/campuses` | Bearer | List organization campuses |
| `POST` | `/v1/campuses` | Bearer | Create a new campus |
| `GET` | `/v1/campus-tours/slots` | Bearer / Bot Token | List active tour slots |
| `POST` | `/v1/campus-tours/slots` | Bearer | Create a new visit slot |
| `GET` | `/v1/campus-tours/slots/{id}` | Bearer | Get single slot details |
| `PUT` | `/v1/campus-tours/slots/{id}` | Bearer | Update visit slot |
| `DELETE` | `/v1/campus-tours/slots/{id}` | Bearer | Cancel / delete visit slot |
| `GET` | `/v1/campus-tours/slots/{id}/attendees` | Bearer | Fetch slot-specific attendee roster |
| `POST` | `/v1/campus-tours/slots/{id}/attendees/{bid}/attendance` | Bearer | Mark attendee status (`attended`, `no_show`) |
| `GET` | `/v1/campus-tours/slots/{id}/export` | Bearer | Export slot attendee roster to CSV |
| `POST` | `/v1/campus-tours/slots/{id}/share-token` | Bearer | Generate tokenized gate check-in link |
| `GET` | `/v1/public/tour-roster/{token}` | Public | Gatekeeper view of slot roster |
| `POST` | `/v1/public/tour-roster/{token}/check-in` | Public | Gatekeeper check-in submission |
| `POST` | `/v1/campus-tours/slots/{id}/send-feedback` | Bearer | Bulk dispatch feedback survey to attendees |
| `GET` | `/v1/public/tour-feedback/{token}` | Public | Student survey form metadata |
| `POST` | `/v1/public/tour-feedback/{token}` | Public | Student feedback survey submission |
| `GET` | `/v1/campus-tours/slots/{id}/feedbacks` | Bearer | View slot feedback responses & scorecard |
| `POST` | `/v1/campus-tours/slots/{id}/ai-summary` | Bearer | Generate LLM executive feedback synthesis |

---

## 7. UI Implementation Rules

All UI components must strictly adhere to [`BRANDING_GUIDELINES.md`](file:///c:/xampp/htdocs/edvora.chat/BRANDING_GUIDELINES.md) and [`theme-branding.css`](file:///c:/xampp/htdocs/edvora.chat/theme-branding.css):
* Palette: Primary `#063D3B` (Teal), Accent `#C8FF63` (Lime), Secondary backgrounds `#F4FAF7` / `#FFFFFF`, Borders `#E6F0EC` / `#D1E5DE`.
* Typography: Sans-serif body (`var(--brand-font-sans)`), Display headers (`var(--brand-font-display)`), Monospace timestamps (`var(--brand-font-mono)`).
* All timestamps displayed in tenant timezone using `TenantLocalizationHelper`.
