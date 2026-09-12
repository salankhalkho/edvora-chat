<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy &amp; Data Governance — edvora.chat</title>
    <meta name="description" content="Global Privacy Policy, Cookie Disclosures, FERPA, GDPR, PIPEDA, and CCPA Compliance Standards for edvora.chat.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              'e-teal': '#063D3B',
              'e-lime': '#C8FF63',
              'e-sage': '#DDE9E3',
              'e-muted': '#4F7470',
              'e-border': '#D5E8DF',
              'e-dark': '#042826',
            },
            fontFamily: {
              sans: ['Plus Jakarta Sans', 'system-ui', 'sans-serif'],
              mono: ['JetBrains Mono', 'monospace'],
            }
          }
        }
      }
    </script>
    <style>
        :root {
            --brand-primary: #063D3B;
            --brand-accent: #C8FF63;
            --brand-bg: #F1F7F4;
            --brand-font-sans: 'Plus Jakarta Sans', system-ui, sans-serif;
        }
        body {
            font-family: var(--brand-font-sans);
            background-color: var(--brand-bg);
            color: #063D3B;
            -webkit-font-smoothing: antialiased;
        }
        .btn-primary { background: #063D3B; color: #fff; border-radius: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all .2s; border: none; cursor: pointer; }
        .btn-primary:hover { background: #092F2E; transform: translateY(-1px); }
        .btn-secondary { background: #ffffff; color: #063D3B; border: 1.5px solid #D5E8DF; border-radius: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all .2s; cursor: pointer; }
        .btn-secondary:hover { border-color: #063D3B; background: #FAFCFB; }
        .btn-ghost { color: #4F7470; font-weight: 600; transition: color .2s; }
        .btn-ghost:hover { color: #063D3B; }
        #demo-modal { display: none; position: fixed; inset: 0; z-index: 999; background: rgba(4,40,38,.7); backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 16px; }
        #demo-modal.open { display: flex; }
        #mobile-menu { display: none; position: fixed; inset: 0; z-index: 998; background: #F1F7F4; flex-direction: column; padding: 24px; }
        #mobile-menu.open { display: flex; }
        .glass { background: rgba(255,255,255,0.95); backdrop-filter: blur(12px); border: 1.5px solid #D5E8DF; }
        .legal-card { background: #ffffff; border: 1.5px solid #DCE9E5; border-radius: 18px; padding: 36px 32px; box-shadow: 0 10px 30px rgba(6,61,59,0.04); }
        .legal-h2 { font-size: 20px; font-weight: 800; color: #063D3B; margin-top: 36px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .legal-h2:first-of-type { margin-top: 0; }
        .legal-p { font-size: 14.5px; line-height: 1.75; color: #4F7470; margin-bottom: 16px; }
        .legal-list { list-style-type: disc; margin-left: 20px; margin-bottom: 16px; font-size: 14.5px; line-height: 1.75; color: #4F7470; }
        .legal-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #E8F5F1; border: 1px solid #A7D7C8; border-radius: 100px; font-size: 11.5px; font-weight: 700; color: #047857; text-transform: uppercase; letter-spacing: 0.05em; }
        .compliance-box { background: #F8FBFA; border: 1.5px solid #DCE9E5; border-radius: 12px; padding: 18px 20px; margin: 16px 0; }
        .cookie-table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px; }
        .cookie-table th { background: #F4FAF7; border: 1px solid #DCE9E5; padding: 10px 14px; text-align: left; font-weight: 700; color: #063D3B; }
        .cookie-table td { border: 1px solid #E6F0EC; padding: 10px 14px; color: #4F7470; vertical-align: top; }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between">

<?php require __DIR__ . '/partials/header.php'; ?>

<main class="max-w-[1000px] mx-auto px-6 py-12 flex-1 w-full">
  <div class="mb-8">
    <div class="legal-badge mb-3">Institutional Compliance &amp; Global Data Privacy</div>
    <h1 class="text-3xl sm:text-4xl font-extrabold text-e-teal tracking-tight mb-3">Global Privacy Policy &amp; Cookie Policy</h1>
    <p class="text-e-muted text-[15px]">Effective Date: August 2026 • Governing edvora.chat Multi-Tenant Higher Education SaaS</p>
  </div>

  <div class="legal-card">
    <h2 class="legal-h2">1. Overview, Role &amp; Data Ownership</h2>
    <p class="legal-p">
      edvora.chat (&ldquo;Edvora&rdquo;, &ldquo;we&rdquo;, &ldquo;us&rdquo;, or &ldquo;our&rdquo;) provides an enterprise AI admissions conversion platform built for colleges, universities, and post-secondary educational institutions (&ldquo;Institutional Partners&rdquo;). 
    </p>
    <p class="legal-p">
      Under global data protection legislation (including the UK GDPR, EU GDPR, Canada PIPEDA, and US State Privacy Laws), our Institutional Partner is the <strong>Data Controller</strong> who determines what prospective student inquiries to solicit, while Edvora acts as the <strong>Data Processor / Service Provider</strong> that processes data strictly on the institution's documented instructions.
    </p>

    <h2 class="legal-h2">2. Cookie Policy &amp; Consent Management (US, UK, Canada &amp; Global)</h2>
    <p class="legal-p">
      We implement a multi-jurisdictional consent management framework. For visitors from the UK, EU, and Canada, non-essential cookies and tracking scripts are blocked by default until explicit prior consent is granted. For visitors from the United States, we provide clear notice at collection and immediate opt-out rights.
    </p>

    <div class="compliance-box">
      <h4 class="font-bold text-[14px] text-e-teal mb-2">📋 Institutional Cookie Audit Matrix</h4>
      <div class="overflow-x-auto">
        <table class="cookie-table">
          <thead>
            <tr>
              <th>Cookie Category</th>
              <th>Purpose &amp; Description</th>
              <th>Default Setting</th>
              <th>Lifespan</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>Strictly Necessary</strong></td>
              <td>Authentication sessions (<code class="font-mono text-xs">edvora_token</code>), CSRF defense, bot security, and remembering your cookie consent choice.</td>
              <td><span class="text-emerald-700 font-bold">Always Active</span></td>
              <td>Session / 1 Year</td>
            </tr>
            <tr>
              <td><strong>Performance &amp; Analytics</strong></td>
              <td>Measures page dwell time, interaction rate, scroll depth, and anonymous conversion funnel progression.</td>
              <td><span class="text-amber-700 font-bold">Opt-In Required</span> (UK/CA)</td>
              <td>180 Days</td>
            </tr>
            <tr>
              <td><strong>Marketing Attribution</strong></td>
              <td>Captures UTM tags (<code class="font-mono text-xs">utm_source</code>, <code class="font-mono text-xs">utm_campaign</code>) to attribute student inquiries to advertising campaigns without cross-site tracking.</td>
              <td><span class="text-amber-700 font-bold">Opt-In Required</span> (UK/CA)</td>
              <td>90 Days</td>
            </tr>
            <tr>
              <td><strong>Functional &amp; Chatbot</strong></td>
              <td>Remembers preferred widget language, voice synthesizer toggles, and chat bubble UI preferences across browser sessions.</td>
              <td><span class="text-slate-600 font-bold">Optional</span></td>
              <td>365 Days</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-e-muted mt-2">
        💡 You can modify or withdraw your cookie preferences at any time by clicking the <strong>Cookie Settings</strong> link in the footer or the floating privacy badge on any page.
      </p>
    </div>

    <h2 class="legal-h2">3. FERPA Higher-Education Data Protection &amp; Tenancy Isolation</h2>
    <p class="legal-p">
      For US institutions subject to the <strong>Family Educational Rights and Privacy Act (FERPA, 34 CFR Part 99)</strong>:
    </p>
    <ul class="legal-list">
      <li><strong>School Official Status:</strong> Edvora acts as a designated &ldquo;School Official&rdquo; with legitimate educational interests, performing admissions advising and inquiry services that would otherwise be handled by institutional staff.</li>
      <li><strong>Direct Control:</strong> Edvora operates under the direct control of the college with respect to the use, maintenance, and deletion of education records and applicant inquiry files.</li>
      <li><strong>Row-Level Tenant Scoping:</strong> All database queries strictly enforce isolation (<code class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">WHERE organization_id = ?</code>). Student records from University A are mathematically inaccessible to University B.</li>
    </ul>

    <h2 class="legal-h2">4. Zero Foundation Model Training Guarantee</h2>
    <p class="legal-p">
      We provide an absolute contractual guarantee: <strong>Your institution's uploaded course catalogs, viewbooks, fee tables, and student conversational transcripts are NEVER used to train, refine, or fine-tune public AI foundation models</strong> (e.g. OpenAI, Anthropic, Google). All AI inference occurs through zero-data-retention enterprise API endpoints.
    </p>

    <h2 class="legal-h2">5. US State Privacy Disclosures (CCPA / CPRA &amp; State Acts)</h2>
    <p class="legal-p">
      Under the California Consumer Privacy Act as amended by the CPRA:
    </p>
    <ul class="legal-list">
      <li><strong>No Sale or Sharing of Personal Information:</strong> Edvora does NOT sell personal information and does NOT share personal data for cross-context behavioral advertising.</li>
      <li><strong>Global Privacy Control (GPC):</strong> Our web platforms and widgets honor automated GPC browser signals as an immediate opt-out of optional telemetry.</li>
      <li><strong>Consumer Privacy Rights:</strong> Students and parents have the right to know what personal information is collected, request correction or complete erasure, and not be discriminated against for exercising their rights.</li>
    </ul>

    <h2 class="legal-h2">6. UK &amp; EU GDPR Compliance (PECR, Art. 13 &amp; 14)</h2>
    <ul class="legal-list">
      <li><strong>Lawful Basis:</strong> We process prospective student lead data under <em>Consent (Art. 6(1)(a))</em> and <em>Performance of a Contract / Legitimate Interests (Art. 6(1)(b)/(f))</em> for admissions dispatch.</li>
      <li><strong>Data Subject Rights:</strong> Right to Access (Art. 15), Right to Rectification (Art. 16), Right to Erasure / Right to be Forgotten (Art. 17), and Right to Data Portability (Art. 20) in structured JSON/CSV format.</li>
      <li><strong>Institutional Self-Service:</strong> Institutional administrators can fulfill erasure and export requests instantly via the <strong>Edvora College Console</strong>.</li>
    </ul>

    <h2 class="legal-h2">7. Canada PIPEDA &amp; CPPA (Bill C-27) Compliance</h2>
    <p class="legal-p">
      Edvora complies with the 10 Fair Information Principles of Canada&rsquo;s <strong>Personal Information Protection and Electronic Documents Act (PIPEDA)</strong>: Accountability, Identifying Purposes, Meaningful Consent, Limiting Collection, Limiting Use, Disclosure &amp; Retention, Accuracy, Safeguards, Openness, Individual Access, and Challenging Compliance.
    </p>

    <h2 class="legal-h2">8. Data Security, Encryption &amp; Retention</h2>
    <p class="legal-p">
      All data in transit is encrypted using <strong>TLS 1.3</strong>. Institutional secrets, API keys, and database backups are encrypted at rest using <strong>AES-256</strong>. Student lead records are retained according to the retention window configured by the institution (defaulting to the duration of the active admissions recruitment cycle).
    </p>

    <h2 class="legal-h2">9. Privacy Inquiries &amp; Data Protection Officer</h2>
    <p class="legal-p">
      For compliance inquiries, Data Subject Access Requests (DSAR), or to request a signed Data Processing Addendum (DPA), please contact:
    </p>
    <div class="p-4 rounded-xl bg-[#F7FAF9] border border-e-border text-sm text-e-teal">
      <strong>Global Privacy &amp; Data Governance Office:</strong> <a href="mailto:privacy@edvora.chat" class="underline font-semibold hover:text-emerald-700">privacy@edvora.chat</a><br>
      <strong>Institutional DPA Requests:</strong> <a href="mailto:legal@edvora.chat" class="underline font-semibold hover:text-emerald-700">legal@edvora.chat</a><br>
      <strong>Technical Security Desk:</strong> <a href="mailto:security@edvora.chat" class="underline font-semibold hover:text-emerald-700">security@edvora.chat</a>
    </div>
  </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/demo_modal.php'; ?>

</body>
</html>
