# Edvora.chat

## Design, Branding & Frontend Engineering Bible

**Document:** `EDVORA_DESIGN_BIBLE.md`
**Status:** Permanent Project Standard
**Applies to:** Marketing Website · SaaS Dashboard · Chatbot Widget · Onboarding · Public UI · Product UI · Future Edvora Interfaces

---

# 1. Purpose

This document defines the permanent visual, branding, UX and frontend engineering standards for **Edvora.chat**.

Every future Edvora interface should follow this document unless a deliberate design-system decision is made to change it.

The objective is to ensure that Edvora always feels like the same product regardless of:

* Website
* Dashboard
* Chatbot
* Mobile experience
* Onboarding
* Admin interface
* Documentation
* Marketing pages
* Embedded widgets
* Future applications

---

# 2. Edvora Brand Positioning

## 2.1 What Edvora Is

Edvora is an:

> **AI-powered admissions conversion engine for higher education.**

It helps colleges and universities:

* Engage prospective students
* Answer questions
* Capture leads
* Qualify prospects
* Route inquiries
* Connect students with counselors
* Schedule callbacks
* Book campus visits
* Promote scholarships
* Deliver lead magnets
* Track admissions intent
* Analyze student questions
* Move prospects toward applications and enrollment

The product architecture explicitly supports organizations, departments, programs, staff, chatbots, knowledge sources, leads, conversations, analytics and multiple deployment channels.

---

# 3. The Most Important Positioning Rule

## Never reduce Edvora to "a chatbot."

Avoid:

> "AI chatbot for colleges"

as the primary description.

Prefer:

> **AI admissions conversion engine**

or:

> **Admissions AI that turns conversations into qualified leads.**

or:

> **The AI conversion engine for higher education.**

The chatbot is the interface.

The **conversion engine is the product.**

---

# 4. Core Brand Idea

The fundamental Edvora journey is:

```text
Visitor
   ↓
Conversation
   ↓
Intent
   ↓
Lead
   ↓
Qualified Lead
   ↓
Counselor
   ↓
Campus Visit
   ↓
Application
   ↓
Enrollment
```

This journey should influence:

* Website design
* Product UI
* Illustrations
* Dashboard visualizations
* Marketing copy
* Animations
* Iconography
* Product demonstrations

The landing page should make this transformation visually obvious.

---

# 5. Brand Promise

## Primary

> **Turn more website visitors into qualified students.**

## Secondary

> **Turn student conversations into admissions opportunities.**

## Product description

> **Edvora helps colleges capture, qualify, route and convert prospective students—24/7.**

## Short description

> **AI admissions conversion for higher education.**

---

# 6. Brand Personality

Edvora should feel:

### Intelligent

The product is sophisticated without trying to look futuristic.

### Premium

It should look like software that a serious university would trust.

### Calm

Avoid visual noise and aggressive marketing aesthetics.

### Human

Edvora deals with students, parents, counselors and institutions.

### Efficient

The interface should communicate speed and clarity.

### Trustworthy

Higher education involves sensitive institutional information and student interactions.

### Modern

Edvora should look current without chasing design trends.

---

# 7. What Edvora Should NOT Feel Like

Avoid making Edvora look like:

* A generic AI startup
* A crypto company
* A cyber-security dashboard
* A gaming product
* A dark-mode developer tool
* A generic chatbot SaaS
* A children's education product
* An overly corporate ERP
* A noisy marketing website

Especially avoid:

* Dark neon backgrounds
* Purple/blue AI gradients
* Glowing AI brains
* Robots
* Excessive 3D illustrations
* Excessive particle effects
* Rainbow gradients
* Over-animated interfaces

---

# 8. Permanent Visual Direction

## Light Theme First

Edvora is fundamentally a:

> **Light-theme product.**

The primary visual environment should be:

```text
Off-white
+
White
+
Deep Teal
+
Pistachio
+
Soft Sage
+
Glass
```

Dark mode may be introduced later if there is a genuine product requirement.

Dark mode must never replace the primary Edvora visual identity.

---

# 9. Signature Color Palette

## 9.1 Primary Background

```text
#F1F7F4
```

Name:

**Edvora Mist**

Use for:

* Website background
* Large page areas
* Empty space
* Sections

---

## 9.2 Primary Surface

```text
#FFFFFF
```

Name:

**Pure White**

Use for:

* Cards
* Panels
* Forms
* Modals
* Content areas

---

## 9.3 Primary Brand Color

```text
#063D3B
```

Name:

**Edvora Deep Teal**

This is the most important brand color.

Use for:

* Headlines
* Navigation
* Primary dark sections
* Dashboard headers
* Important UI
* Dark cards
* Text on pistachio buttons

---

## 9.4 Primary Accent

```text
#C8FF63
```

Name:

**Edvora Pistachio**

This is the signature Edvora accent.

Use for:

* Primary CTA buttons
* Important highlights
* Selected states
* Small badges
* Key numbers
* Icons
* Funnel milestones
* Interactive indicators

Do not flood the interface with pistachio.

It should remain special.

---

## 9.5 Soft Accent

```text
#E6F7D2
```

Name:

**Edvora Soft Pistachio**

Use for:

* Highlight backgrounds
* Feature cards
* Callout areas
* Hover states
* Soft visual emphasis

---

## 9.6 Secondary Green

```text
#B9D7C7
```

Name:

**Edvora Sage**

Use for:

* Secondary UI
* Charts
* Borders
* Supporting graphics
* Background gradients

---

## 9.7 Primary Text

```text
#092F2E
```

Use for:

* Body text
* Headings
* Navigation
* UI labels

---

## 9.8 Secondary Text

```text
#71817D
```

Use for:

* Descriptions
* Metadata
* Helper text
* Supporting information

---

## 9.9 Borders

```text
#DDE9E3
```

Use instead of harsh gray borders.

---

# 10. Color Ratio

A typical Edvora page should approximately follow:

```text
70%  Off-white / white

20%  Deep teal / text

8%   Soft sage / secondary accents

2%   Pistachio / primary accent
```

The pistachio color should be **rare enough to attract attention**.

If everything is bright green, nothing feels important.

---

# 11. Color Rules

## Do

Use:

```text
Pistachio CTA
+
Deep teal text
+
White glass
+
Off-white background
```

## Do not

Use:

```text
Pistachio background
+
white text
```

for large areas unless there is a very specific reason.

Pistachio works best with:

> **Deep Teal typography.**

---

# 12. Gradients

Gradients are allowed but should be extremely subtle.

Preferred:

```css
background:
    radial-gradient(
        circle at 10% 10%,
        rgba(200,255,99,.15),
        transparent 30%
    ),
    #F1F7F4;
```

Avoid:

```text
Blue → Purple
Purple → Pink
Rainbow
Neon
```

The Edvora brand does not depend on gradients.

---

# 13. Glassmorphism

Glassmorphism is a core Edvora visual language.

However:

> **Glassmorphism must remain subtle.**

Do not turn every element into glass.

Use glass primarily for:

* Hero dashboards
* Floating cards
* Feature cards
* Analytics panels
* Conversion funnel cards
* Chat interfaces
* Modals
* CTA panels

---

# 14. Standard Glass Component

```css
.glass {
    background: rgba(255, 255, 255, 0.72);

    border: 1px solid rgba(255, 255, 255, 0.90);

    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);

    box-shadow:
        0 24px 70px rgba(6, 61, 59, 0.07),
        inset 0 1px 0 rgba(255, 255, 255, 0.90);
}
```

---

# 15. Glass Design Rules

Glass cards should:

* Have strong readability
* Have subtle transparency
* Have soft borders
* Have soft shadows
* Sit over an interesting background
* Maintain accessibility

Avoid:

* Excessive blur
* Low text contrast
* Multiple overlapping glass layers
* Glass over glass over glass
* Excessive transparency

---

# 16. Typography

## Primary Typeface

Preferred:

> **Plus Jakarta Sans**

Fallback:

```text
Inter
system-ui
sans-serif
```

Example:

```css
font-family:
    "Plus Jakarta Sans",
    Inter,
    system-ui,
    sans-serif;
```

---

# 17. Typography Hierarchy

## Display

```text
64–76px
Weight: 700–800
Line height: 0.98–1.08
```

## H1

```text
48–64px
Weight: 700–800
```

## H2

```text
40–52px
Weight: 700–800
```

## H3

```text
24–32px
Weight: 700
```

## Body Large

```text
18–20px
Line height: 1.6
```

## Body

```text
15–17px
Line height: 1.6
```

## Small

```text
12–14px
```

---

# 18. Typography Personality

Headlines should be:

* Short
* Confident
* Clear
* Benefit-oriented

Prefer:

> **Turn More Website Visitors Into Qualified Students.**

Avoid:

> "Revolutionizing the future of AI-powered educational engagement through intelligent conversational experiences."

Edvora should sound intelligent without sounding complicated.

---

# 19. Headline Style

Preferred structure:

```text
Problem
+
Outcome
```

Example:

> Stop Losing High-Intent Visitors. Turn Them Into Qualified Leads.

Or:

```text
Capability
+
Business outcome
```

Example:

> Intelligent Conversations. More Admissions Opportunities.

---

# 20. Brand Voice

Edvora's voice is:

### Confident

Not arrogant.

### Direct

Not technical for the sake of being technical.

### Intelligent

Not filled with AI buzzwords.

### Human

Not robotic.

### Commercial

Focused on outcomes.

---

# 21. Words to Prefer

Use:

* Students
* Prospective students
* Applicants
* Parents
* Counselors
* Admissions
* Enrollment
* Conversion
* Intent
* Qualified
* Opportunity
* Conversation
* Knowledge
* Campus
* Institution
* Department
* Program
* Application

---

# 22. Words to Avoid

Avoid excessive use of:

* Revolutionary
* Disruptive
* Next-generation
* Cutting-edge
* AI-powered everything
* Neural
* Futuristic
* Autonomous intelligence
* Cognitive
* Hyper-intelligent
* Magic

Edvora should sell outcomes, not AI terminology.

---

# 23. CTA Language

Preferred:

```text
See Edvora in Action →
Book a Demo
Explore the Conversion Engine
Test Your Knowledge
See How It Works
Start Converting
```

Avoid:

```text
Learn More
Submit
Click Here
Try Our Revolutionary AI
Get Started Now!!!
```

CTAs should communicate what happens next.

---

# 24. Logo

The primary wordmark should be:

```text
edvora.chat
```

Prefer lowercase.

The visual identity should be:

```text
Deep Teal wordmark
+
Pistachio symbol/accent
```

Avoid:

* Gradients in the logo
* 3D logos
* Complex symbols
* Generic AI robot symbols
* Brain icons

---

# 25. Logo Mark

The logo mark should be simple enough to work at:

```text
16px
24px
32px
48px
```

The symbol should work independently as:

* Favicon
* App icon
* Dashboard icon
* Chatbot launcher
* Social profile image

---

# 26. Iconography

Icons should be:

* Simple
* Rounded
* Minimal
* Consistent
* Mostly outline-based

Preferred stroke:

```text
1.5px–2px
```

Avoid:

* Mixed icon styles
* Highly detailed icons
* 3D icons
* Cartoon icons
* Emoji-heavy UI

---

# 27. SVG First

SVG should be the preferred format for custom interface graphics.

Use SVG for:

* Icons
* Logos
* Diagrams
* Funnel graphics
* Process illustrations
* Decorative graphics
* Product mockups
* Simple charts where appropriate

Avoid raster images when an SVG can communicate the same idea.

---

# 28. SVG Design Rules

SVGs should use the Edvora palette.

Example:

```text
Deep Teal
Pistachio
Sage
White
```

Keep:

* Rounded corners
* Thin strokes
* Minimal geometry
* Plenty of whitespace

SVG illustrations should look like part of the product rather than stock illustrations.

---

# 29. Photography

Photography should be used sparingly.

When used, prefer:

* Students
* Campuses
* Counselors
* Families
* Real educational environments

Avoid generic:

* Handshake photos
* Corporate boardrooms
* Random laptop stock photos
* Artificial AI imagery

The product UI should normally carry the visual storytelling.

---

# 30. Product UI Is the Hero

Whenever possible:

> **Show the actual product instead of an illustration of the product.**

Examples:

* Admissions pipeline
* Lead card
* Counselor inbox
* Knowledge hub
* Conversation
* Analytics
* Department routing

This creates credibility.

---

# 31. Dashboard Design

The Edvora dashboard should feel like:

> **Admissions Command Center**

rather than:

> Generic SaaS Admin Panel.

Important dashboard concepts should visually emphasize:

```text
Leads
Qualified Leads
Conversions
Applications
Counselor Activity
Student Intent
Admissions Funnel
```

---

# 32. Dashboard Color Rules

Primary UI:

```text
White
Off-white
Deep Teal
Soft Sage
```

Action:

```text
Pistachio
```

Status:

```text
Success → Green
Warning → Warm Amber
Error → Muted Red
Info → Teal
```

Status colors must remain secondary to the Edvora brand palette.

---

# 33. Admissions Funnel

The funnel is a core Edvora visual.

Preferred:

```text
VISITORS
    ↓
CONVERSATIONS
    ↓
LEADS
    ↓
QUALIFIED
    ↓
COUNSELOR
    ↓
APPLICATION
    ↓
ENROLLMENT
```

The funnel should appear throughout the product where relevant.

Examples:

* Landing page
* Dashboard
* Analytics
* Reports
* Marketing pages

---

# 34. Cards

Standard card radius:

```text
20px
```

Large feature cards:

```text
24–32px
```

Small cards:

```text
14–16px
```

Avoid excessive rounded cards where every element looks like a floating bubble.

---

# 35. Buttons

## Primary

```css
background: #C8FF63;
color: #063D3B;
```

Radius:

```text
12–14px
```

Weight:

```text
600
```

## Secondary

White/glass:

```text
background: rgba(255,255,255,.7);
border: 1px solid #DDE9E3;
color: #063D3B;
```

---

# 36. Button Behavior

Hover:

```text
Translate upward 1–2px
Slight shadow increase
```

Do not use:

* Shaking
* Pulsing
* Flashing
* Excessive glow

---

# 37. Navigation

The public website navigation should remain minimal.

Recommended:

```text
Platform
Solutions
How It Works
Resources
Pricing
```

Right:

```text
Log in
Book a Demo →
```

On mobile:

```text
Logo
Menu
```

Avoid large multi-level mega menus unless the site genuinely requires them.

---

# 38. Page Width

Recommended maximum content width:

```text
1200–1280px
```

For large hero/dashboard sections:

```text
1280–1400px
```

Do not stretch content across the entire screen.

Whitespace is part of the design.

---

# 39. Spacing System

Use a consistent spacing scale.

Preferred base:

```text
4px
8px
12px
16px
24px
32px
48px
64px
80px
96px
128px
```

Large marketing sections should generally have:

```text
96–140px
```

vertical separation on desktop.

---

# 40. Responsive Design

Edvora must be designed mobile-first.

Breakpoints:

```text
sm   640px
md   768px
lg   1024px
xl   1280px
2xl  1536px
```

Never design desktop first and "make it responsive later."

---

# 41. Mobile Principles

On mobile:

* Reduce decorative elements
* Reduce glass complexity
* Keep typography readable
* Keep CTA buttons accessible
* Avoid horizontal overflow
* Stack cards
* Preserve the visual hierarchy

Hero should remain visually impressive without requiring a large screen.

---

# 42. Animation Philosophy

Animations should communicate:

* Progress
* Interaction
* State changes
* Hierarchy

They should not exist merely because animation is possible.

---

# 43. Animation Speed

Recommended:

```text
Micro interaction: 150–200ms

Card hover: 200–250ms

Panel transition: 250–350ms

Large section animation: 400–700ms
```

Avoid unnecessarily slow animations.

---

# 44. Scroll Animations

Allowed:

* Fade in
* Slide up slightly
* Scale from 0.98 to 1
* SVG drawing
* Funnel progression

Avoid:

* Large parallax effects
* Constant floating animations
* Excessive motion
* Scroll hijacking

---

# 45. Accessibility

Accessibility is mandatory.

Ensure:

* WCAG-conscious contrast
* Keyboard navigation
* Visible focus states
* Semantic HTML
* Proper button labels
* Alt text
* ARIA only when necessary
* Reduced-motion support

Example:

```css
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}
```

---

# 46. Frontend Technology Philosophy

Edvora should prefer:

> **The simplest technology that solves the problem well.**

Do not introduce a heavy framework simply because it is popular.

The public-facing website should be lightweight, fast and easy to maintain.

---

# 47. Primary Frontend Stack

## Required / Preferred

### HTML

Use semantic HTML5.

```text
<header>
<nav>
<main>
<section>
<article>
<footer>
```

---

### Tailwind CSS

Tailwind should be the primary utility styling system.

Use it for:

* Layout
* Spacing
* Responsive behavior
* Typography
* Common UI
* Component states

---

### Pure CSS

Custom CSS is encouraged when it improves clarity.

Use pure CSS for:

* Glassmorphism
* Complex gradients
* Custom animations
* CSS variables
* Special visual effects
* Browser-specific behavior

Do not force every visual effect into Tailwind utility classes.

---

### Lightweight JavaScript

Use vanilla JavaScript where possible.

JavaScript should handle:

* Mobile menu
* Tabs
* Accordions
* Modals
* Simple sliders
* Form interactions
* Lightweight animations
* Chat widget behavior
* API requests

---

# 48. JavaScript Framework Policy

Do not introduce:

```text
React
Vue
Angular
Svelte
Next.js
Nuxt
```

for simple marketing pages unless a real requirement exists.

A static landing page does not need a JavaScript application framework.

If a future requirement genuinely requires a framework, document the reason before introducing it.

---

# 49. Heavy Libraries

Avoid unnecessary dependencies such as:

* Large UI frameworks
* Large animation libraries
* Multiple icon libraries
* Large carousel libraries
* Large chart libraries for simple charts
* Multiple CSS frameworks

Prefer:

```text
HTML
+
Tailwind
+
CSS
+
Vanilla JS
+
SVG
```

---

# 50. Icons

Preferred order:

```text
1. Custom SVG
2. Small SVG icon set
3. CSS shapes for trivial indicators
```

Do not load several icon libraries.

---

# 51. Charts

For simple marketing visualizations:

Prefer:

```text
SVG
CSS
HTML
```

For dashboard analytics requiring interactivity:

Use a lightweight chart library only when necessary.

Do not introduce a heavy visualization framework for a simple bar chart.

---

# 52. Images

Prefer:

```text
SVG
WebP
AVIF
```

Use optimized raster images when photography is actually useful.

Do not ship unnecessarily large PNG/JPEG files.

---

# 53. Fonts

Avoid loading multiple font families.

Preferred:

```text
Plus Jakarta Sans
```

with:

```text
Inter
system-ui
sans-serif
```

Only load the weights actually required.

---

# 54. Performance Philosophy

The website should feel:

> **Instant.**

Optimize for:

* Low JavaScript
* Small CSS
* Optimized images
* Minimal dependencies
* Lazy loading
* Proper caching
* Compressed assets
* Minimal third-party scripts

Do not sacrifice performance for decorative effects.

---

# 55. Public Website Architecture

Preferred:

```text
HTML
│
├── Tailwind CSS
├── Custom CSS
├── Lightweight JS
└── SVG
```

Conceptually:

```text
Browser
   │
   ├── HTML
   ├── CSS
   ├── SVG
   └── JS
```

The public website should not require a complex frontend runtime.

---

# 56. Chatbot Widget Architecture

The widget should remain lightweight.

The documented architecture is:

```text
College Website
       ↓
JavaScript Widget
       ↓
SaaS API
       ↓
Chatbot Service
       ↓
AI Service
```

The widget should identify the chatbot through a public identifier.

Example:

```html
<script
    src="https://cdn.edvora.chat/chatbot.js"
    data-chatbot="CHATBOT_ID">
</script>
```

The widget must never contain:

* Database credentials
* LLM API keys
* Private organization credentials
* Administrative credentials

---

# 57. Widget Performance

The Edvora widget must:

* Load asynchronously
* Avoid blocking the host website
* Keep JavaScript small
* Avoid unnecessary dependencies
* Avoid interfering with host CSS
* Use isolated class names
* Fail gracefully
* Work on mobile
* Respect host website performance

---

# 58. CSS Naming

For custom CSS, use an Edvora namespace where practical.

Example:

```css
.edvora-glass {}
.edvora-widget {}
.edvora-chat {}
.edvora-card {}
```

Avoid generic names such as:

```css
.card {}
.button {}
.container {}
```

inside reusable widget CSS.

---

# 59. Tailwind Usage

Tailwind classes should remain readable.

Avoid enormous class strings when a reusable component makes the design clearer.

Instead of repeatedly writing a 20-class glass card:

Create:

```css
.edvora-glass
```

or a reusable component abstraction appropriate to the project's actual architecture.

---

# 60. CSS Variables

Maintain brand tokens centrally.

Example:

```css
:root {
    --edvora-bg: #F1F7F4;

    --edvora-white: #FFFFFF;

    --edvora-teal: #063D3B;

    --edvora-text: #092F2E;

    --edvora-pistachio: #C8FF63;

    --edvora-soft-pistachio: #E6F7D2;

    --edvora-sage: #B9D7C7;

    --edvora-muted: #71817D;

    --edvora-border: #DDE9E3;
}
```

These variables are the permanent source of truth for brand colors.

---

# 61. Component Philosophy

Build a small reusable visual system.

Core components:

```text
Button
GlassCard
Section
Badge
Stat
MetricCard
Navigation
Modal
Tabs
Accordion
Input
Select
Toast
Funnel
Chart
ChatBubble
Avatar
```

Do not create dozens of variations without a genuine need.

---

# 62. Component Consistency

The same visual component should look the same throughout Edvora.

For example:

If a primary button is:

```text
Pistachio
+
Deep Teal
+
12px radius
```

then the same rule should apply to:

* Landing page
* Dashboard
* Onboarding
* Settings
* Chatbot

---

# 63. Empty States

Empty states should be useful and calm.

Example:

> **No qualified leads yet.**

> Once Edvora starts capturing conversations, your qualified prospects will appear here.

CTA:

**Preview the Conversion Engine →**

Avoid:

* Huge illustrations
* Excessive emoji
* Blaming language

---

# 64. Loading States

Use simple:

* Skeletons
* Soft spinners
* Progress indicators

Avoid large animated loaders.

Preferred visual language:

```text
Deep Teal
+
Soft Sage
+
Pistachio progress
```

---

# 65. Error States

Errors should be human-readable.

Avoid:

> `ERROR 500: REQUEST_FAILED_EXCEPTION`

Prefer:

> **Something went wrong.**

> We couldn't load this information right now. Please try again.

Then:

**Try Again**

---

# 66. Product Empty Space

Whitespace is a feature.

Do not attempt to fill every area of the screen.

Premium design requires breathing room.

---

# 67. Landing Page Design Principle

The landing page should not be a feature catalog.

The story should be:

```text
Problem
   ↓
Missed opportunities
   ↓
Edvora conversion engine
   ↓
Capture
   ↓
Qualify
   ↓
Route
   ↓
Convert
   ↓
Measure
```

---

# 68. Landing Page Visual Priority

Priority order:

```text
1. Conversion Engine

2. Lead Capture & Qualification

3. Knowledge

4. Human / Department Routing

5. Conversion Actions

6. Analytics

7. Multi-channel

8. Multilingual

9. Supporting features
```

## The documented product materials strongly emphasize lead capture, qualification, counselor handoff, campus tours, scholarships, lead magnets and analytics.

# 69. Landing Page Hero

Preferred headline:

> **Turn More Website Visitors Into Qualified Students.**

Supporting message:

> Edvora turns your college website into a 24/7 admissions engine—answering questions, capturing high-intent leads, qualifying prospects, connecting students with counselors and moving them closer to enrollment.

Primary CTA:

> **See Edvora in Action →**

Secondary:

> **Explore the Conversion Engine**

---

# 70. Hero Visual

The hero should show the admissions funnel rather than simply displaying a chatbot.

Preferred:

```text
VISITOR
  ↓
CONVERSATION
  ↓
LEAD
  ↓
QUALIFIED
  ↓
COUNSELOR
  ↓
APPLICATION
  ↓
ENROLLMENT
```

The hero should communicate:

> **Edvora moves people forward.**

---

# 71. Product Screenshots

When showing product UI:

* Use realistic data
* Use consistent Edvora colors
* Use meaningful labels
* Avoid fake technical complexity
* Avoid clutter
* Show actual workflows

Preferred screens:

```text
Admissions Pipeline
Lead Profile
Conversation
Knowledge Hub
Department Routing
Analytics
```

---

# 72. Data Visualization

Charts should tell a story.

Preferred colors:

```text
Deep Teal
Pistachio
Sage
Soft Green
```

Example:

```text
Visitors       ███████████████████
Conversations  █████████████
Leads          ███████
Qualified      ████
Applications   ██
Enrollment     █
```

Avoid rainbow charts.

---

# 73. Analytics Philosophy

Edvora analytics should answer:

> **What are prospective students asking, where are they dropping off, and what should the admissions team do next?**

Not merely:

> "How many pageviews did we receive?"

The product materials explicitly position conversational analytics around student questions, objections, demand and admissions performance.

---

# 74. Department UI

Departments should visually feel like specialized teams.

Examples:

```text
Admissions
Finance
Scholarships
Placements
Hostel
International
Student Services
```

Each can have:

* Queue
* Staff
* Knowledge
* Working hours
* Routing
* Performance

The architecture explicitly supports departments, staff, knowledge, working hours, escalation and lead assignment.

---

# 75. Multi-Channel UI

Edvora can appear across:

```text
Website
WhatsApp
Student Portal
Mobile App
```

The visual identity must remain consistent across all channels.

The underlying product materials explicitly describe a central Edvora AI engine serving these channels.

---

# 76. Multilingual UI

Multilingual functionality should feel native.

Do not make language switching look like a technical feature.

Use simple:

```text
English
हिन्दी
Español
العربية
தமிழ்
```

The supplied product material describes automatic language detection and multilingual/code-switching support.

---

# 77. Security Visual Language

Security should feel:

> Quiet and trustworthy.

Avoid:

* Padlock overload
* Hacker imagery
* Dark security graphics
* "Military-grade" marketing language

Use:

```text
Role-based access
Secure knowledge
Controlled departments
Auditability
Tenant isolation
```

The technical architecture explicitly requires tenant isolation and role-based controls.

---

# 78. Design Tokens

Permanent Edvora tokens:

```css
:root {

    /* Brand */

    --edvora-teal: #063D3B;
    --edvora-text: #092F2E;

    --edvora-pistachio: #C8FF63;
    --edvora-soft-pistachio: #E6F7D2;

    --edvora-sage: #B9D7C7;

    /* Surfaces */

    --edvora-bg: #F1F7F4;
    --edvora-surface: #FFFFFF;

    /* Text */

    --edvora-muted: #71817D;

    /* Border */

    --edvora-border: #DDE9E3;

    /* Radius */

    --edvora-radius-sm: 10px;
    --edvora-radius-md: 14px;
    --edvora-radius-lg: 20px;
    --edvora-radius-xl: 28px;
    --edvora-radius-2xl: 32px;

    /* Shadows */

    --edvora-shadow-sm:
        0 10px 30px rgba(6,61,59,.05);

    --edvora-shadow-md:
        0 20px 50px rgba(6,61,59,.07);

    --edvora-shadow-lg:
        0 30px 80px rgba(6,61,59,.09);
}
```

---

# 79. Recommended Technology Stack

## Marketing Website

```text
HTML5
Tailwind CSS
Pure CSS
Vanilla JavaScript
SVG
```

Optional:

```text
Alpine.js
```

only if interactive behavior becomes cumbersome with vanilla JS.

---

# 80. SaaS Dashboard

The dashboard may use a more structured frontend architecture if product complexity requires it.

However:

> Do not introduce a frontend framework simply because the dashboard is a SaaS application.

The architecture should remain pragmatic.

Where possible:

```text
HTML
+
Tailwind
+
CSS
+
Lightweight JS
```

If a framework becomes necessary because of genuine application complexity, document the decision.

---

# 81. Backend

The public design system does not prescribe the backend language.

The existing platform architecture should remain aligned with the established Edvora technical architecture.

Initial infrastructure is intentionally simple:

```text
Nginx
Application
Database
Background Workers
File Storage
LLM
```

The system is intended to begin on a single VPS and scale components independently later.

---

# 82. Database

The architecture supports:

```text
PostgreSQL
```

with:

```text
MySQL
```

also acceptable where already established.

The database must support multi-tenant isolation from the beginning.

---

# 83. AI Architecture

The frontend should never expose:

* LLM API keys
* Database credentials
* Private organization credentials
* Administrative credentials

The current architecture uses a platform-controlled LLM configuration and routes chatbot requests through the SaaS backend.

---

# 84. Knowledge Architecture

The Edvora product intentionally favors a simpler knowledge architecture.

Core sources:

```text
Documents
+
URLs
+
Structured metadata
+
Context selection
+
LLM
```

The current architecture does not require:

```text
Vector database
Embeddings
RAG pipeline
Reranking
```

This simplicity should influence the product's visual language too:

> **Powerful, but not complicated.**

---

# 85. Dependency Rule

Before adding a dependency, ask:

1. Can HTML solve it?
2. Can CSS solve it?
3. Can Tailwind solve it?
4. Can SVG solve it?
5. Can 20 lines of JavaScript solve it?
6. Is the dependency actually worth its maintenance cost?

Only then add a library.

---

# 86. Third-Party Script Rule

Third-party scripts must be minimized.

Every external script can affect:

* Performance
* Privacy
* Reliability
* Security
* Page rendering

Do not add:

```text
Analytics
Chat
Heatmaps
Tracking
Animations
Widgets
Fonts
```

without evaluating the performance impact.

---

# 87. Code Quality

Frontend code should be:

* Simple
* Readable
* Semantic
* Modular
* Documented where necessary
* Easy for another developer to understand

Avoid clever code.

Avoid premature abstractions.

---

# 88. File Structure

Recommended public website structure:

```text
/edvora-web
│
├── index.html
│
├── /pages
│   ├── platform.html
│   ├── solutions.html
│   ├── pricing.html
│   └── contact.html
│
├── /assets
│   ├── /images
│   ├── /svg
│   ├── /icons
│   └── /fonts
│
├── /css
│   ├── theme.css
│   ├── components.css
│   └── animations.css
│
├── /js
│   ├── navigation.js
│   ├── interactions.js
│   └── analytics.js
│
└── tailwind.config.js
```

The exact structure can evolve, but the principle remains:

> Keep the frontend understandable.

---

# 89. Design Review Checklist

Before approving any Edvora page, ask:

### Brand

* Does it look like Edvora?
* Is deep teal dominant?
* Is pistachio used selectively?
* Is the page light?

### UX

* Is the primary message obvious?
* Is the CTA obvious?
* Is there enough whitespace?
* Is the hierarchy clear?

### Visual

* Is glassmorphism subtle?
* Are cards consistent?
* Are SVGs consistent?
* Is the page free of visual clutter?

### Performance

* Is JavaScript necessary?
* Can any library be removed?
* Are images optimized?
* Are fonts optimized?

### Copy

* Does the copy explain an outcome?
* Is it concise?
* Does it avoid meaningless AI buzzwords?
* Does it reinforce the conversion engine?

---

# 90. Things We Will Never Do

Unless the design system is intentionally revised:

```text
❌ Dark neon AI theme
❌ Blue + purple as primary brand colors
❌ Generic robot imagery
❌ Giant AI brain graphics
❌ Excessive gradients
❌ Excessive animations
❌ Rainbow charts
❌ Huge JavaScript frameworks for simple pages
❌ Multiple UI libraries
❌ Multiple icon libraries
❌ Unnecessary dependencies
❌ Fake product screenshots
❌ Unsubstantiated performance claims
❌ Cluttered dashboards
❌ Tiny unreadable typography
❌ Poor contrast
```

---

# 91. Things We Will Always Do

```text
✓ Light-first design

✓ Deep teal brand anchor

✓ Pistachio signature accent

✓ Soft green/off-white backgrounds

✓ Subtle glassmorphism

✓ Generous whitespace

✓ Product UI over stock illustrations

✓ SVG where appropriate

✓ Semantic HTML

✓ Tailwind CSS

✓ Pure CSS when appropriate

✓ Lightweight JavaScript

✓ Performance-first development

✓ Mobile-first design

✓ Accessible interfaces

✓ Clear conversion-focused copy

✓ Consistent components

✓ Simple technology

✓ Measurable admissions outcomes
```

---

# 92. The Edvora Visual Formula

Every major Edvora page should be capable of being described as:

```text
OFF-WHITE CANVAS
        +
DEEP TEAL TYPOGRAPHY
        +
PISTACHIO ACCENTS
        +
WHITE GLASS
        +
SOFT SAGE
        +
REAL PRODUCT UI
        +
GENEROUS WHITESPACE
```

This is the permanent visual foundation.

---

# 93. The Edvora Product Formula

The product itself should always communicate:

```text
Knowledge
    ↓
Conversation
    ↓
Intent
    ↓
Lead
    ↓
Qualification
    ↓
Routing
    ↓
Human Action
    ↓
Application
    ↓
Enrollment
```

---

# 94. Final Design Principle

## Make complexity look simple.

Edvora has significant functionality:

* AI
* Knowledge
* Departments
* Staff
* Routing
* Leads
* CRM
* Analytics
* WhatsApp
* Student portals
* Mobile
* Multilingual support
* Appointments
* Campus visits

But the user should never feel that complexity.

The interface should communicate:

> **"Edvora makes admissions easier."**

not:

> **"Look how technically sophisticated our software is."**

---

# 95. Final Brand Principle

If every future Edvora design decision is reduced to five questions:

### 1. Is it useful?

### 2. Is it simple?

### 3. Does it feel premium?

### 4. Does it look unmistakably Edvora?

### 5. Does it help the user move toward an outcome?

If the answer is yes to all five:

**Ship it.**

---

# 96. Permanent Edvora Identity

```text
                    EDVORA.CHAT

             AI ADMISSIONS CONVERSION
                    ENGINE

                         │
              ┌──────────┴──────────┐
              │                     │
          DEEP TEAL             PISTACHIO
          TRUST                 GROWTH
              │                     │
              └──────────┬──────────┘
                         │
                     GLASS
                  INTELLIGENCE
                         │
                         ▼
                  LIGHT CANVAS
                         │
                         ▼
              SIMPLE TECHNOLOGY
                         │
                         ▼
              CONVERSATION → LEAD
                         │
                         ▼
                  LEAD → STUDENT
```

## Edvora in one sentence

> **Edvora turns student conversations into admissions opportunities.**

That sentence, the **deep-teal + pistachio visual system**, the **light glassmorphism aesthetic**, and the **lightweight HTML/Tailwind/CSS/JS/SVG engineering philosophy** form the permanent foundation of the Edvora.chat design system.
