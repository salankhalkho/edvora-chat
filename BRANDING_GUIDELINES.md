# Edvora.chat Light Design System & Branding Guidelines
### Version 2.0 (Teal + Pistachio + Misty Sage)
*Authoritative visual guide for all web consoles, sub-panels, and dashboard modules.*

---

## 1. Core Visual Philosophy
Edvora.chat utilizes a high-trust, clarity-driven **Light Luxury** aesthetic tailored specifically for College and University Admissions leaders, Registrars, and Deans.
- **Tone:** Academic prestige, clarity, high contrast, zero clutter.
- **Colors:** Deep Forest Teal (`#063D3B`) paired with crisp white surfaces (`#FFFFFF`), misty green canvas (`#F1F7F4`), and high-energy electric pistachio accents (`#C8FF63`).
- **Surfaces:** Uncluttered white cards with crisp 1px borders (`#DCE9E5`) and subtle elevation shadows (`rgba(6, 61, 59, 0.03)`).

---

## 2. Color Palette & Token Registry

### Brand Anchor & Accents
| Token | Hex Value | Role & Usage |
|---|---|---|
| `--e-teal` | `#063D3B` | Primary brand anchor, headers, titles, active nav text, CTA buttons |
| `--e-teal-dark` | `#0A524F` | Button hover & active interaction states |
| `--e-teal-text` | `#092F2E` | High-contrast readable body text |
| `--e-pistachio` | `#C8FF63` | Electric high-visibility accent, CTA text, copy button |
| `--e-soft-pistachio` | `#E6F7D2` | Active sidebar nav background, selected card backgrounds |
| `--e-sage` | `#B9D7C7` | Active border highlights, pill borders, dashed dropzones |
| `--e-mist` | `#F1F7F4` | Global workspace canvas / body background |
| `--e-border` | `#DDE9E3` | Standard structural borders & dividers |
| `--e-card-border` | `#DCE9E5` | Structural card & panel borders |
| `--e-hover-bg` | `#F4FAF7` | Row hover, secondary button background |

### Status Colors
| State | Badge BG | Text Color | Border Color |
|---|---|---|---|
| **Live / Active** | `#ECFDF5` | `#047857` | `#A7F3D0` |
| **Expiring Soon / Warning** | `#FEF3C7` | `#B45309` | `#FDE68A` |
| **Expired / Critical Danger** | `#FEF2F2` | `#DC2626` | `#FECDD3` |
| **Review Due / Audit** | `#F5F3FF` | `#7C3AED` | `#DDD6FE` |
| **Archived / Historical** | `#F1F5F9` | `#648781` | `#CBD5E1` |

---

## 3. Typography Stack

- **Body & Sans Display:** `'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;`
- **Monospace / Metric Data:** `'JetBrains Mono', monospace;`

### Font Weights
- **800 (Extra Bold):** Main topbar logo text, prominent metric counters (`.ops-conv-val`, `.ckh-kpi-val`).
- **700 (Bold):** Card titles, primary CTA buttons, table section headers, active pills.
- **600 (Semi Bold):** Navigation items, table row titles, subheaders.
- **400 / 500 (Regular / Medium):** Body text, descriptions, table data cells.

---

## 4. Shell & Layout Standards

- **Top Navbar Height:** `56px` (`.brand-header` / `.brand-top-navbar`), sticky top, background `#FFFFFF`, border-bottom `1.5px solid #DDE9E3`.
- **Sidebar Width:** `230px` (`.brand-sidebar` / `.dashboard-sidebar`), background `#FFFFFF`, border-right `1.5px solid #DDE9E3`.
- **Footer Status Bar:** `32px` (`.brand-footer`), background `#FFFFFF`, border-top `1.5px solid #DDE9E3`.
- **Panel Header:** `38px` (`.brand-panel-header`), background `#F8FBFA`, border-bottom `1.5px solid #E6F0EC`.
- **Main Canvas:** `.workspace-main`, background `#F1F7F4`, padding `20px 24px 40px 24px`.

---

## 5. Component Patterns

### 1. Operations Command Frame (`.ops-dashboard-frame`)
Used for primary command centers (Overview, Central Knowledge Hub, Department Management):
```css
.ops-dashboard-frame {
  background: #FFFFFF;
  border: 1.5px solid #DCE9E5;
  border-radius: 18px;
  overflow: hidden;
  box-shadow: 0 16px 50px -10px rgba(6, 61, 59, 0.08), 0 4px 16px rgba(6, 61, 59, 0.03);
}
```

### 2. Buttons
- **Primary CTA:** `background: #063D3B; color: #C8FF63; font-weight: 700; border-radius: 8px;`
  Hover: `background: #0A524F; box-shadow: 0 4px 14px rgba(6, 61, 59, 0.15);`
- **Secondary Action:** `background: #FFFFFF; color: #063D3B; border: 1.5px solid #DDE9E3; border-radius: 8px;`
  Hover: `background: #F4FAF7; border-color: #B9D7C7;`

### 3. Data Tables
- **Header (`th`):** `background: #F8FBFA; color: #648781; font-size: 10.5px; font-weight: 700; text-transform: uppercase; border-bottom: 1.5px solid #E6F0EC;`
- **Data Cell (`td`):** `color: #1E293B; border-bottom: 1px solid #EEF5F2; font-size: 12px;`
- **Row Hover:** `background: #FAFDFB;`

### 4. Modular Tab Architecture
Every tab is stored in `public/app/tabs/{tab_name}.html` and lazily fetched into `<div class="tab-content" id="tab-{tab_name}" data-loaded="false"></div>` via `switchNavTab('{tab_name}')`.

---

## 6. Knowledge Document Type Badges (`.ckh-file-badge`)
All institutional knowledge document types share an identical geometric profile for absolute visual rhythm and clarity:
* **Footprint:** `26px` width &times; `30px` height, `5px` border-radius, `1px solid` border.
* **Typography:** `8px`, `font-weight: 800`, uppercase, `letter-spacing: 0.04em`, line-height 1.

| Document Format | Badge Text | Class | Background | Text Color | Border Color |
|---|---|---|---|---|---|
| **PDF Document** | `PDF` | `.ckh-file-pdf` | `#FEF2F2` | `#DC2626` | `#FECDD3` |
| **Web Source / Scraped URL** | `WEB` | `.ckh-file-web` | `#FEF3C7` | `#D97706` | `#FDE68A` |
| **Word Document** | `DOC` | `.ckh-file-doc` | `#EFF6FF` | `#2563EB` | `#BFDBFE` |
| **Spreadsheet** | `XLS` / `CSV` | `.ckh-file-xls` | `#ECFDF5` | `#059669` | `#A7F3D0` |
| **Presentation Slides** | `PPT` | `.ckh-file-ppt` | `#FFF7ED` | `#EA580C` | `#FED7AA` |
| **Text Memo / Policy Note** | `TXT` | `.ckh-file-txt` | `#F8FAFC` | `#475569` | `#CBD5E1` |
| **Markdown** | `MD` | `.ckh-file-md` | `#F5F3FF` | `#7C3AED` | `#DDD6FE` |
| **Data Feed / API** | `API` | `.ckh-file-api` | `#F0FDFA` | `#0D9488` | `#99F6E4` |
| **Archived / Historical** | `ARC` | `.ckh-file-arc` | `#F1F5F9` | `#64748B` | `#CBD5E1` |

