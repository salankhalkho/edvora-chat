<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service &amp; Data Processing Addendum — edvora.chat</title>
    <meta name="description" content="Institutional Terms of Service and Data Processing Addendum (DPA) for edvora.chat AI admissions conversion SaaS platform.">
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
        .highlight-box { background: #F4FAF7; border: 1.5px solid #A7D7C8; border-radius: 12px; padding: 18px 20px; margin: 16px 0; }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between">

<?php require __DIR__ . '/partials/header.php'; ?>

<main class="max-w-[1000px] mx-auto px-6 py-12 flex-1 w-full">
  <div class="mb-8">
    <div class="legal-badge mb-3">Master Subscription Agreement &amp; DPA</div>
    <h1 class="text-3xl sm:text-4xl font-extrabold text-e-teal tracking-tight mb-3">Terms of Service</h1>
    <p class="text-e-muted text-[15px]">Effective: August 2026 • Governing Institutional Software Subscriptions</p>
  </div>

  <div class="legal-card">
    <h2 class="legal-h2">1. Acceptance of Terms &amp; Authority</h2>
    <p class="legal-p">
      By registering a college account, deploying the Edvora widget (<code class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">widget.js</code>) on your institutional web properties, or accessing the Edvora Admin Console at <code class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">edvora.chat/app</code>, you agree to be bound by these Terms of Service and incorporated Data Processing Addendum. You represent that you have legal authority to bind your post-secondary institution.
    </p>

    <h2 class="legal-h2">2. SaaS Platform Scope &amp; Availability SLA</h2>
    <p class="legal-p">
      Edvora provides conversational admissions routing, multi-department knowledge management, lead conversion telemetry, viewbook delivery, and campus visit scheduling tools. We maintain a targeted <strong>99.9% Uptime Service Level Agreement (SLA)</strong> for student-facing chatbot delivery, supported by Redis cache caching and automated multi-LLM failover routing.
    </p>

    <h2 class="legal-h2">3. Data Processing Addendum (DPA) &amp; Controller-Processor Roles</h2>
    <div class="highlight-box">
      <h4 class="font-bold text-[14px] text-e-teal mb-2">🛡️ Institutional Data Processing Terms</h4>
      <p class="text-[13.5px] text-e-teal leading-[1.65] mb-3">
        In providing the Edvora SaaS platform, Edvora acts strictly as a <strong>Data Processor / Service Provider</strong> under FERPA, UK GDPR, EU GDPR, and US State Privacy laws (CCPA/CPRA).
      </p>
      <ul class="list-disc pl-5 text-[13px] text-e-teal space-y-1.5">
        <li><strong>Customer Instructions:</strong> Edvora processes personal data exclusively on documented instructions from the Institution.</li>
        <li><strong>Confidentiality &amp; Security:</strong> Personnel authorized to process data are committed to binding confidentiality. AES-256 encryption at rest and TLS 1.3 in transit are maintained at all times.</li>
        <li><strong>Subprocessor Transparency:</strong> Edvora engages only vetted cloud infrastructure and enterprise API providers bound by equivalent data protection obligations.</li>
        <li><strong>Assistance with DSARs:</strong> Edvora provides automated tools within the College Console enabling institutions to fulfill student access, export (JSON/CSV), and erasure requests.</li>
      </ul>
    </div>

    <h2 class="legal-h2">4. 100% Institutional Data Ownership &amp; Zero Model Training</h2>
    <p class="legal-p">
      All institution-uploaded collateral, viewbooks, syllabi, fee matrices, and captured student lead records remain the exclusive property of the institution. <strong>Edvora shall not use institutional documents or applicant conversations to train public or proprietary foundation models.</strong>
    </p>

    <h2 class="legal-h2">5. Subscription Plans, Quotas &amp; Data Portability</h2>
    <p class="legal-p">
      Services are billed on a recurring monthly or annual basis. Conversation quotas, knowledge storage, and department slots scale according to your chosen plan. 
    </p>
    <p class="legal-p">
      <strong>Data Portability Guarantee:</strong> Upon termination or expiration of your subscription, the institution has the right to export all student leads, counselor callback rosters, and campus tour bookings in structured JSON/CSV format. Upon written request, all institutional data will be permanently purged from active databases within 30 days.
    </p>

    <h2 class="legal-h2">6. Intellectual Property Rights</h2>
    <p class="legal-p">
      The institution grants Edvora a limited, non-exclusive license solely to host, index, and retrieve institutional knowledge for conversational answering. Edvora retains all rights in the core SaaS software, AI prompt orchestrators, widget styling engines, and platform UI.
    </p>

    <h2 class="legal-h2">7. Limitation of Liability</h2>
    <p class="legal-p">
      Neither party shall be liable for indirect, incidental, special, or consequential damages. Edvora's aggregate liability under this agreement is limited to the fees paid by the institution during the twelve (12) months preceding the claim.
    </p>

    <h2 class="legal-h2">8. Governing Law &amp; Legal Notices</h2>
    <div class="p-4 rounded-xl bg-[#F7FAF9] border border-e-border text-sm text-e-teal">
      <strong>Legal &amp; Contractual Compliance:</strong> <a href="mailto:legal@edvora.chat" class="underline font-semibold hover:text-emerald-700">legal@edvora.chat</a><br>
      <strong>Billing &amp; Subscription Inquiries:</strong> <a href="mailto:billing@edvora.chat" class="underline font-semibold hover:text-emerald-700">billing@edvora.chat</a><br>
      <strong>Data Protection Officer:</strong> <a href="mailto:privacy@edvora.chat" class="underline font-semibold hover:text-emerald-700">privacy@edvora.chat</a>
    </div>
  </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/demo_modal.php'; ?>

</body>
</html>
