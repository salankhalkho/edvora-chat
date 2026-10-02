# Timezone & Primary Campus Localization Architecture — edvora.chat

> **CANONICAL & AUTHORITATIVE GUIDE:**
> This document defines the multi-tenant timezone resolution, localized date/time rendering, and export formatting contracts for **edvora.chat**.
> Every table column, schedule slot, calendar invite, lead capture log, and CSV export MUST conform to the rules documented here.

---

## 1. Executive Summary & Design Principle

**edvora.chat** serves colleges and universities across the globe (e.g. United States, India, United Kingdom, Canada, UAE, Australia, and Singapore).
Prospective students and university admissions counselors operate in the institution's local geographical timezone.

### The Golden Rule of Localization
> **All human-facing dates, times, schedules, and exports are dynamically localized to the timezone of the tenant's PRIMARY CAMPUS (`campuses.country` where `is_primary = 1`).**
> Hardcoded timezones (such as forcing "IST" or "EST") are strictly forbidden anywhere in the system.

---

## 2. Primary Campus Resolution Chain

When resolving a tenant's geographical location and timezone, the system executes the following deterministic 4-step resolution chain:

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Primary Campus Query                                     │
│    SELECT country, state, city FROM campuses                │
│    WHERE organization_id = :org_id AND is_primary = 1 LIMIT 1│
└──────────────────────────────┬──────────────────────────────┘
                               │ (If not found or empty)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. First Registered Campus Fallback                         │
│    SELECT country, state, city FROM campuses                │
│    WHERE organization_id = :org_id ORDER BY id ASC LIMIT 1  │
└──────────────────────────────┬──────────────────────────────┘
                               │ (If tenant has 0 campuses)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Organization Root Profile Fallback                       │
│    SELECT country, state, city FROM organizations           │
│    WHERE id = :org_id                                       │
└──────────────────────────────┬──────────────────────────────┘
                               │ (If country not specified)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. Fallback 3: Mandatory Default to U.S.A.                  │
│    Country: "United States"                                 │
│    Timezone: "America/New_York"                             │
│    Abbreviation: "EST" / "EDT" (Dynamic DST)                │
│    Locale: "en-US"                                          │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Country & Region to Timezone Engine

The mapping engine resolves geographical entities into IANA timezones, canonical short abbreviations, and date formatting locales:

| Country | Region / State Mapping | IANA Timezone | Canonical Abbreviation | Locale |
|---|---|---|---|---|
| **United States** | **Central (CT):** Alabama (`AL`), Texas (`TX`), Illinois (`IL`), Tennessee (`TN`), Missouri (`MO`), Wisconsin (`WI`), Minnesota (`MN`), Louisiana (`LA`), Mississippi (`MS`), Oklahoma (`OK`), Kansas (`KS`), Nebraska (`NE`), Iowa (`IA`), Arkansas (`AR`) | `America/Chicago` | `CST` / `CDT` (or `CT`) | `en-US` |
| **United States** | **Eastern (ET):** New York (`NY`), Florida (`FL`), Georgia (`GA`), North Carolina (`NC`), Pennsylvania (`PA`), Ohio (`OH`), Massachusetts (`MA`), Virginia (`VA`), Michigan (`MI`), New Jersey (`NJ`), Maryland (`MD`), DC | `America/New_York` | `EST` / `EDT` (or `ET`) | `en-US` |
| **United States** | **Mountain (MT):** Colorado (`CO`), Arizona (`AZ`), Utah (`UT`), New Mexico (`NM`), Idaho (`ID`), Montana (`MT`), Wyoming (`WY`) | `America/Denver` (or `America/Phoenix` for AZ) | `MST` / `MDT` | `en-US` |
| **United States** | **Pacific (PT):** California (`CA`), Washington (`WA`), Oregon (`OR`), Nevada (`NV`) | `America/Los_Angeles` | `PST` / `PDT` (or `PT`) | `en-US` |
| **United States** | **Alaska & Hawaii:** Alaska (`AK`), Hawaii (`HI`) | `America/Anchorage` / `Pacific/Honolulu` | `AKST` / `HST` | `en-US` |
| **United States** | *Unspecified / Default US* | `America/New_York` | `EST` / `EDT` | `en-US` |
| **India** | *All States* | `Asia/Kolkata` | `IST` | `en-IN` |
| **United Kingdom** | England, Scotland, Wales, Northern Ireland | `Europe/London` | `GMT` / `BST` | `en-GB` |
| **Canada** | Ontario, Quebec / British Columbia | `America/Toronto` / `America/Vancouver` | `EST` / `PST` | `en-CA` |
| **Australia** | NSW, Victoria, Queensland / WA | `Australia/Sydney` / `Australia/Perth` | `AEST` / `AWST` | `en-AU` |
| **United Arab Emirates** | Dubai, Abu Dhabi, Sharjah | `Asia/Dubai` | `GST` | `en-AE` |
| **Singapore** | *National* | `Asia/Singapore` | `SGT` | `en-SG` |
| **European Union** | Germany, France, Netherlands, Spain, Italy | `Europe/Berlin` / `Europe/Paris` | `CET` / `CEST` | `en-DE` / `en-FR` |
| **Ireland** | *National* | `Europe/Dublin` | `IST` / `GMT` | `en-IE` |
| **New Zealand** | *National* | `Pacific/Auckland` | `NZST` / `NZDT` | `en-NZ` |
| **Japan** | *National* | `Asia/Tokyo` | `JST` | `ja-JP` |
| **South Africa** | *National* | `Africa/Johannesburg` | `SAST` | `en-ZA` |
| **Saudi Arabia / Qatar** | *National* | `Asia/Riyadh` / `Asia/Qatar` | `AST` | `ar-SA` |
| **Any Unrecognized** | *Fallback to USA* | `America/New_York` | `EST` / `EDT` | `en-US` |

---

## 4. Backend Implementation Specification

### 4.1 Helper Class: `App\Helpers\TenantLocalizationHelper`
A dedicated backend helper located in `app/Helpers/TenantLocalizationHelper.php`:
- `getTenantLocalization(int $orgId): array`
  Returns:
  ```php
  [
      'campus_id'      => 5,
      'campus_name'    => 'Alabama A&M Main Campus',
      'country'        => 'United States',
      'state'          => 'Alabama',
      'city'           => 'Normal',
      'timezone'       => 'America/Chicago',
      'timezone_short' => 'CDT', // Dynamic DST abbreviation
      'locale'         => 'en-US'
  ]
  ```
- `formatTenantDateTime(string|\DateTime $datetime, int $orgId, string $format = 'd M Y, h:i A'): string`
  Converts UTC timestamp into localized timestamp with timezone suffix.

### 4.2 Authentication & Profile API Contracts
- `GET /v1/auth/me`: Injects `primary_campus` localization block into `organization`.
- `GET /v1/organization/profile`: Injects `primary_campus` localization block.
- `GET /v1/campus-tour-scheduling/slots`: Selects `c.country`, `c.state`, `c.city` in addition to `c.name`.

### 4.3 CSV Export APIs
- `GET /v1/leads/export`:
  - Column header: `Date & Time Captured ({$tzShort})` (e.g. `Date & Time Captured (CDT)` or `(IST)`).
  - Values: converted from UTC to tenant timezone via `\DateTime::setTimezone()`.
- `GET /v1/campus-tours/export`:
  - Column header: `Booked On ({$tzShort})`.
  - Values: converted to tenant timezone.
- `GET /v1/callbacks/export`:
  - Column headers: `Requested Date & Time ({$tzShort})`, `Completed Date & Time ({$tzShort})`.
  - Values: converted to tenant timezone.

---

## 5. Frontend Client Implementation Specification

### 5.1 Global State in SPA (`public/app/index.html`)
When `initAppIdentity()` runs:
```javascript
window.tenantLocalization = org.primary_campus || {};
window.tenantCountry = window.tenantLocalization.country || 'United States';
window.tenantTimezone = window.tenantLocalization.timezone || 'America/New_York';
window.tenantTzShort = window.tenantLocalization.timezone_short || 'EDT';
window.tenantLocale = window.tenantLocalization.locale || 'en-US';
```

### 5.2 Formatting Functions
- `formatTenantDateTime(dateInput, options)`: Formats date using `window.tenantLocale` and `window.tenantTimezone`, appending `window.tenantTzShort`.
- `formatToIST(dateInput)`: Retained as a 100% backward-compatible alias forwarding directly to `formatTenantDateTime(dateInput)`.

### 5.3 Dynamic UI Updates
A universal updater `updateTenantTzLabels()` dynamically updates:
1. **`#leads`:**
   - Table header: `Date & Time (<TZ>)`
   - Custom Date Range label: `Custom Date Range (<TZ>):`
   - Export CSV button tooltip: `Export student contacts as CSV (<TZ>)`
   - Table cell timestamps: formatted in `<TZ>`
   - Lead detail drawer: `leadDrawerCapturedAt` and message timestamps in `<TZ>`
2. **`#campus-tours-scheduling`:**
   - Table header: `Date & Time (<TZ>)`
   - Table slot time: 12-hour formatted with timezone (e.g. `10:00 AM – 11:30 AM <TZ>`)
   - Table slot date: formatted in localized date format
   - Slot creation/editing badges: `⏰ Times are scheduled in <TZ> (<Country>)`
3. **`#campus-tours`:**
   - Table header: `Booked On (<TZ>)`
   - Button: `📥 Export CSV (<TZ>)`
4. **`#callbacks`:**
   - Table header: `Requested (<TZ>)`
   - Button: `📥 Export CSV (<TZ>)`
   - Detail modal timestamp: in `<TZ>`
5. **`#overview`:**
   - Date range selector label: `Custom Date Range (<TZ>):`
6. **`#session-journeys` & Analytics Pages:**
   - Visitor session logs & timelines formatted in tenant timezone.

---

## 6. Pre-Completion Quality Checklist

- [ ] Does Fallback 3 default strictly to U.S.A. (`America/New_York` / `EST` / `EDT`)?
- [ ] Are dynamic DST abbreviations correctly computed (e.g. CDT vs CST)?
- [ ] Is `architecture_timezone.md` linked in `architecture.md`?
- [ ] Are all CSV export columns and timestamps synchronized with the Web UI?
- [ ] Has full deployment script (`deploy.ps1`) executed with live verification and Git sync?
