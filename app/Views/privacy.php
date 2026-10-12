<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy, Terms &amp; Compliance Disclosures — edvora.chat</title>
    <meta name="description" content="Official Privacy Policy, Information Technology Act 2000 disclosures, Refund & Cancellation Policy, Digital Delivery, FERPA, GDPR, and Grievance Officer for edvora.chat.">
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
              'e-teal-dark': '#092F2E',
              'e-lime': '#C8FF63',
              'e-sage': '#DDE9E3',
              'e-muted': '#4F7470',
              'e-border': '#D5E8DF',
              'e-card-border': '#DCE9E5',
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
        .legal-card { background: #ffffff; border: 1.5px solid #DCE9E5; border-radius: 18px; padding: 36px 32px; box-shadow: 0 10px 30px rgba(6,61,59,0.04); margin-bottom: 28px; }
        .legal-h2 { font-size: 21px; font-weight: 800; color: #063D3B; margin-top: 36px; margin-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        .legal-h2:first-of-type { margin-top: 0; }
        .legal-h3 { font-size: 16px; font-weight: 700; color: #063D3B; margin-top: 24px; margin-bottom: 8px; }
        .legal-p { font-size: 14.5px; line-height: 1.75; color: #4F7470; margin-bottom: 16px; }
        .legal-list { list-style-type: disc; margin-left: 20px; margin-bottom: 16px; font-size: 14.5px; line-height: 1.75; color: #4F7470; }
        .legal-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #E8F5F1; border: 1px solid #A7D7C8; border-radius: 100px; font-size: 11.5px; font-weight: 700; color: #047857; text-transform: uppercase; letter-spacing: 0.05em; }
        .compliance-box { background: #F8FBFA; border: 1.5px solid #DCE9E5; border-radius: 12px; padding: 20px; margin: 18px 0; }
        .highlight-notice { background: #FEF3C7; border: 1.5px solid #FCD34D; border-radius: 12px; padding: 18px 20px; margin: 18px 0; color: #92400E; }
        .danger-notice { background: #FEF2F2; border: 1.5px solid #FECDD3; border-radius: 12px; padding: 18px 20px; margin: 18px 0; color: #991B1B; }
        .success-box { background: #ECFDF5; border: 1.5px solid #A7F3D0; border-radius: 12px; padding: 20px; margin: 18px 0; color: #065F46; }
        .cookie-table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px; }
        .cookie-table th { background: #F4FAF7; border: 1px solid #DCE9E5; padding: 10px 14px; text-align: left; font-weight: 700; color: #063D3B; }
        .cookie-table td { border: 1px solid #E6F0EC; padding: 10px 14px; color: #4F7470; vertical-align: top; }
        .nav-pill { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #FFFFFF; border: 1px solid #D5E8DF; border-radius: 999px; font-size: 12.5px; font-weight: 600; color: #063D3B; transition: all .15s; }
        .nav-pill:hover { background: #E6F7D2; border-color: #A7D7C8; }
        html { scroll-behavior: smooth; }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between">

<?php require __DIR__ . '/partials/header.php'; ?>

<main class="max-w-[1020px] mx-auto px-6 py-12 flex-1 w-full">
  <!-- Top Banner & Title -->
  <div class="mb-8">
    <div class="legal-badge mb-3">Institutional Compliance &bull; Payment Gateway Disclosures &bull; IT Act 2000</div>
    <h1 class="text-3xl sm:text-4xl font-extrabold text-e-teal tracking-tight mb-3">Privacy Policy &amp; Terms of Service</h1>
    <p class="text-e-muted text-[15px] leading-relaxed">
      Governing <strong class="text-e-teal">edvora.chat</strong> &bull; Multi-Tenant Higher Education Admissions SaaS Platform &bull; Last Updated: October 2026
    </p>
  </div>

  <!-- Entity & Registered Office Identification Callout -->
  <div class="p-5 rounded-2xl bg-white border border-[#DCE9E5] shadow-sm mb-8">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
      <div>
        <div class="text-[11px] font-bold text-e-teal uppercase tracking-wider mb-1">Platform Domain &amp; Application</div>
        <div class="text-sm font-semibold text-e-teal">https://edvora.chat/ (Web &amp; College Console)</div>
        <div class="text-e-muted mt-1">Cloud-Hosted Software-as-a-Service (SaaS) for Educational Institutions</div>
      </div>
      <div>
        <div class="text-[11px] font-bold text-e-teal uppercase tracking-wider mb-1">Registered Platform Owner &amp; Office</div>
        <div class="text-sm font-semibold text-e-teal">Edvora Chat &bull; 7007242973</div>
        <div class="text-e-muted mt-1">1st Floor, HNO289A, Basharatpur West, Near Water tank, Gorakhpur, Uttar Pradesh, 273004, India</div>
      </div>
    </div>
  </div>

  <!-- Quick Anchor Navigation Strip -->
  <div class="flex items-center gap-2 flex-wrap mb-8">
    <a href="#privacy-policy" class="nav-pill">🔒 Privacy Policy</a>
    <a href="#payment-gateway" class="nav-pill">💳 Payment &amp; Security</a>
    <a href="#refund-policy" class="nav-pill font-bold bg-[#E6F7D2]">🚫 No Refund &amp; Cancellation</a>
    <a href="#shipping-policy" class="nav-pill">⚡ Instant Digital Delivery</a>
    <a href="#cookies" class="nav-pill">📋 Cookie Matrix</a>
    <a href="#global-compliance" class="nav-pill">🌐 FERPA &amp; Global Rights</a>
    <a href="#terms-and-jurisdiction" class="nav-pill">⚖️ IT Act &amp; Jurisdiction</a>
    <a href="#grievance-officer" class="nav-pill">👤 Grievance Officer</a>
  </div>

  <!-- MAIN LEGAL CONTAINER -->
  <div class="legal-card" id="privacy-policy">
    <!-- SECTION 1: IT ACT PREAMBLE & APPLICABILITY -->
    <h2 class="legal-h2">
      <span>1.</span> Statutory Declaration &amp; Information Technology Act Compliance
    </h2>
    <p class="legal-p">
      This document is an electronic record in terms of the <strong>Information Technology Act, 2000</strong> and rules there under as applicable, and the amended provisions pertaining to electronic records in various statutes as amended by the Information Technology Act, 2000. This electronic record is generated by a computer system and does not require any physical or digital signatures.
    </p>
    <p class="legal-p">
      This document is published in accordance with the provisions of <strong>Rule 3 (1) of the Information Technology (Intermediaries guidelines) Rules, 2011</strong> that require publishing the rules and regulations, privacy policy, and Terms of Use for access or usage of domain name <strong>https://edvora.chat/</strong> (&lsquo;Website&rsquo;), including the related web applications, subdomains, college administrative portals, APIs, and mobile endpoints (hereinafter collectively referred to as the &lsquo;Platform&rsquo;).
    </p>
    <p class="legal-p">
      The Platform is owned and operated by <strong>7007242973 (Edvora Chat / Salan Khalkho)</strong>, having its registered operational office at <strong>1st Floor, HNO289A, Basharatpur West, Near Water tank, Gorakhpur, UP, 273004, India</strong> (hereinafter referred to as &lsquo;Platform Owner&rsquo;, &lsquo;we&rsquo;, &lsquo;us&rsquo;, or &lsquo;our&rsquo;).
    </p>
    <p class="legal-p">
      This Privacy Policy describes how 7007242973 and its affiliates collect, use, share, protect or otherwise process your information and personal data through our website https://edvora.chat/. Your personal data will primarily be stored and processed in India in strict accordance with the laws of India, including data protection legislation. By visiting this Platform, providing your information, or availing any subscription or product/service offered on the Platform, you expressly agree to be bound by the terms and conditions of this Privacy Policy, our Terms of Use, and applicable service agreements. If you do not agree, please do not use or access our Platform.
    </p>

    <!-- SECTION 2: DATA COLLECTION -->
    <h2 class="legal-h2">
      <span>2.</span> Collection of Personal Data &amp; Information
    </h2>
    <p class="legal-p">
      We collect your personal data when you use our Platform, register a college account, subscribe to our services, or otherwise interact with us during the course of our relationship:
    </p>
    <ul class="legal-list">
      <li><strong>Account &amp; Registration Information:</strong> When signing up, registering, or managing an account on the Platform, we collect details such as your full name, institutional designation, college or university name, work telephone/mobile number, email address, postal address, and information shared as proof of identity or institutional address.</li>
      <li><strong>Prospective Student Lead Data:</strong> When prospective students interact with the Edvora chatbot deployed on institutional websites, the chatbot captures conversational inquiry data, program preferences, academic interests, campus tour scheduling requests, contact details, and callback requests on behalf of the partnering institution.</li>
      <li><strong>Sensitive Personal Data &amp; Payment Instrument Information:</strong> Sensitive personal data is collected solely with your explicit consent to process subscription payments for our SaaS plans. This includes bank account, credit card, debit card, or other payment instrument information. All such transactions are processed through authorized, RBI-licensed, PCI-DSS compliant third-party payment gateways and payment aggregators. <strong>Edvora Chat does NOT directly store credit/debit card numbers, CVVs, card PINs, or net banking passwords on its servers.</strong></li>
      <li><strong>Technical &amp; Telemetry Data:</strong> We may track user behavior, browser type, operating system, IP address, navigation patterns, dwell time, and preferences on our Platform to ensure secure delivery and optimize performance.</li>
    </ul>

    <div class="danger-notice text-xs leading-relaxed">
      <strong>⚠️ FRAUD &amp; PHISHING WARNING:</strong> If you receive an email, SMS, or phone call from any person or entity claiming to represent &ldquo;7007242973&rdquo; or &ldquo;Edvora Chat&rdquo; seeking personal payment credentials such as debit/credit card PINs, CVVs, one-time passwords (OTPs), or net-banking passwords, <strong>NEVER provide such information</strong>. We will never solicit your secret payment passwords or OTPs. If you have already revealed such details, immediately report it to an appropriate law enforcement cyber cell and your banking provider.
    </div>

    <!-- SECTION 3: USE OF PERSONAL DATA -->
    <h2 class="legal-h2">
      <span>3.</span> Use of Personal Data &amp; Service Fulfillment
    </h2>
    <p class="legal-p">
      We use personal data to provide the services you request, fulfill subscription orders, and maintain institutional software operations:
    </p>
    <ul class="legal-list">
      <li><strong>Service Delivery &amp; Account Provisioning:</strong> To provision your college account, issue API tokens and widget snippets (<code class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">widget.js</code>), maintain conversational admissions routing, and enable campus tour management.</li>
      <li><strong>Order Processing &amp; Billing:</strong> To process subscription plan purchases, recurring billing renewals, and generate digital tax invoices and receipts.</li>
      <li><strong>Customer Experience &amp; Support:</strong> To resolve technical support requests, troubleshoot conversational workflows, and answer platform queries.</li>
      <li><strong>Security &amp; Abuse Prevention:</strong> To detect, investigate, and protect against error, cyber intrusions, unauthorized multi-tenant access, fraud, and other criminal activities.</li>
      <li><strong>Institutional Communications:</strong> To inform you about product updates, scheduled maintenance, security advisories, and service updates. You may opt out of non-essential promotional messages at any time.</li>
    </ul>

    <!-- SECTION 4: DATA SHARING & PAYMENT GATEWAY DISCLOSURES -->
    <div id="payment-gateway"></div>
    <h2 class="legal-h2">
      <span>4.</span> Data Sharing, Disclosure &amp; Payment Gateway Partners
    </h2>
    <p class="legal-p">
      We do not sell, rent, or trade your personal data. We disclose personal data only under strictly regulated conditions:
    </p>
    <ul class="legal-list">
      <li><strong>Payment Gateways &amp; Banking Partners:</strong> We disclose necessary transaction details to RBI-authorized payment aggregators, payment gateways (including Razorpay), prepaid payment instrument issuers, and acquiring banking partners solely to verify, process, and settle your SaaS subscription payments.</li>
      <li><strong>Cloud Infrastructure Providers:</strong> Data is hosted on secure enterprise cloud servers with strict row-level multi-tenant isolation, bound by stringent confidentiality and non-disclosure obligations.</li>
      <li><strong>Legal &amp; Law Enforcement Compliance:</strong> We may disclose personal data to government agencies, judicial authorities, or law enforcement bodies if required to do so by applicable Indian law, court orders, subpoenas, or in good faith belief that such disclosure is reasonably necessary to enforce our Terms of Use, protect public safety, or respond to claims of third-party rights violation.</li>
      <li><strong>Corporate Restructuring:</strong> In the event of a merger, acquisition, or reorganization, the acquiring entity will be obligated to adhere to this Privacy Policy.</li>
    </ul>

    <!-- SECTION 5: STRICT NO REFUND AND CANCELLATION POLICY -->
    <div id="refund-policy"></div>
    <div class="mt-8 pt-8 border-t border-e-border">
      <div class="legal-badge mb-2">Mandatory Merchant Policy &bull; Digital Goods &bull; SaaS Delivery</div>
      <h2 class="text-2xl font-extrabold text-e-teal mb-4 flex items-center gap-2">
        <span>5.</span> Cancellation &amp; Refund Policy (Strict No-Refund Policy for SaaS)
      </h2>
      
      <div class="highlight-notice">
        <h4 class="font-bold text-[15px] mb-2 text-amber-900">📌 IMPORTANT NOTICE: 100% NON-PHYSICAL SOFTWARE &bull; NO REFUND POLICY</h4>
        <p class="text-[13.5px] leading-relaxed text-amber-950">
          <strong>edvora.chat supplies cloud-hosted Software-as-a-Service (SaaS). We do NOT manufacture, sell, or deliver physical goods or merchandise.</strong> Upon completion of subscription payment, subscribers receive instantaneous, unhindered electronic access to the application via their unique registered User ID and password. Because our software services are intangible, fully provisioned, and instantly consumable upon purchase, <strong>WE OPERATE A STRICT NO-REFUND POLICY</strong>.
        </p>
      </div>

      <h3 class="legal-h3">5.1 Nature of Products &amp; Services</h3>
      <p class="legal-p">
        Edvora Chat provides subscription-based, cloud-hosted admissions conversion software, AI conversational agents, knowledge ingestion tools, and student lead telemetry engines designed for colleges, universities, and post-secondary educational institutions. All products and services offered on <code class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">https://edvora.chat/</code> are entirely digital, electronic, and intangible software tools. There is zero physical component or tangible inventory.
      </p>

      <h3 class="legal-h3">5.2 Strict No-Refund Policy</h3>
      <p class="legal-p">
        Since user account activation, administrative features, conversational quotas, and API access are provisioned automatically and immediately upon successful payment confirmation:
      </p>
      <ul class="legal-list">
        <li><strong>All Sales Are Final:</strong> All subscription purchases, plan payments, setup charges, and renewal fees are strictly non-refundable and non-creditable.</li>
        <li><strong>No Partial or Prorated Refunds:</strong> We do not offer full refunds, partial refunds, prorated credits, or cash-back under any circumstances once access credentials have been provisioned or the billing period has commenced.</li>
        <li><strong>No Refunds for Underutilized Quotas:</strong> Subscription plans provide allocated monthly conversational capacities and knowledge allowances. Unused quotas or conversational volumes at the conclusion of any billing period cannot be refunded or carried over.</li>
        <li><strong>Exemption from Physical Return / Replacement:</strong> Because our software is supplied electronically over the web, return policies applicable to physical goods (such as defective physical items, packaging returns, or courier doorstep rejections) do not apply to our SaaS platform.</li>
      </ul>

      <h3 class="legal-h3">5.3 Subscription Cancellation Policy</h3>
      <p class="legal-p">
        While payments are non-refundable, subscribers have complete autonomy to cancel their future recurring renewals at any time:
      </p>
      <ul class="legal-list">
        <li><strong>Self-Service Cancellation:</strong> You may cancel your subscription renewal at any time prior to the next scheduled billing date directly through the <em>Billing &amp; Subscription</em> tab in your Edvora College Admin Console (<a href="/app" class="underline text-e-teal font-semibold">https://edvora.chat/app</a>).</li>
        <li><strong>Written Cancellation Notice:</strong> Alternatively, you may submit a formal cancellation request by emailing our billing desk at <a href="mailto:billing@edvora.chat" class="underline font-semibold text-e-teal">billing@edvora.chat</a> from your registered administrative email address at least 48 hours prior to the renewal date.</li>
        <li><strong>Access Through Billing Cycle:</strong> When a subscription renewal is cancelled, your access to the platform and features remains fully active until the end of your currently paid billing period. No further recurring charges will be initiated after the expiration of the active cycle.</li>
        <li><strong>No Retroactive Cancellations:</strong> Cancellation requests submitted after an automatic renewal has already processed cannot be retroactively refunded for that cycle.</li>
      </ul>

      <h3 class="legal-h3">5.4 Technical Duplicate Transaction Resolution</h3>
      <p class="legal-p">
        In the exceptional event of a technical network error or payment gateway glitch resulting in an accidental, verified duplicate transaction where a customer is charged twice for the identical subscription plan within the same billing timeframe:
      </p>
      <ul class="legal-list">
        <li>The subscriber must report the duplicate transaction to <a href="mailto:billing@edvora.chat" class="underline font-semibold text-e-teal">billing@edvora.chat</a> within <strong>7 business days</strong> of the transaction date, including payment reference IDs and timestamps.</li>
        <li>Upon technical verification by our finance desk, the verified duplicate charge will be processed back to the original source payment instrument within <strong>5 to 7 business days</strong>, subject to banking clearing timelines.</li>
      </ul>
    </div>

    <!-- SECTION 6: SHIPPING & DIGITAL DELIVERY POLICY -->
    <div id="shipping-policy"></div>
    <div class="mt-8 pt-8 border-t border-e-border">
      <div class="legal-badge mb-2">Electronic Fulfillment &bull; Instant Access Provisioning</div>
      <h2 class="text-2xl font-extrabold text-e-teal mb-4 flex items-center gap-2">
        <span>6.</span> Shipping &amp; Digital Delivery Policy
      </h2>

      <div class="success-box">
        <h4 class="font-bold text-[15px] mb-2 text-emerald-900">⚡ 100% INSTANT DIGITAL DELIVERY &bull; NO PHYSICAL SHIPPING</h4>
        <p class="text-[13.5px] leading-relaxed text-emerald-950">
          edvora.chat provides digital cloud software (SaaS). We do not dispatch any physical parcels, domestic couriers, or postal consignments. Access to all purchased services and software licenses is delivered purely electronically and instantaneously upon transaction completion.
        </p>
      </div>

      <h3 class="legal-h3">6.1 Delivery Timeline &amp; Mechanism</h3>
      <ul class="legal-list">
        <li><strong>Instant Access via User ID &amp; Password:</strong> Upon successful completion of online payment via the payment gateway, your user account is provisioned automatically in real time. You receive instant, immediate access to the full application and features via your registered User ID (email) and password at <a href="/app" class="underline font-semibold text-e-teal">https://edvora.chat/app</a>.</li>
        <li><strong>Electronic Confirmation &amp; Receipt:</strong> A digital confirmation receipt, subscription invoice, and onboarding instructions are dispatched immediately to the registered email address provided during registration within seconds of payment clearance.</li>
        <li><strong>Delivery Window:</strong> Digital delivery is executed instantaneously (within 0 to 15 minutes of payment confirmation). If you do not receive account confirmation or access credentials within 30 minutes due to email filtering, please check your spam folder or write immediately to <a href="mailto:support@edvora.chat" class="underline font-semibold text-e-teal">support@edvora.chat</a>.</li>
      </ul>

      <h3 class="legal-h3">6.2 Shipping &amp; Handling Charges</h3>
      <p class="legal-p">
        Because delivery is executed entirely via digital telecommunications and cloud infrastructure, <strong>there are zero shipping, freight, handling, or packaging charges</strong> levied on any of our software plans or subscriptions.
      </p>
    </div>

    <!-- SECTION 7: GLOBAL HIGHER EDUCATION PRIVACY & STANDARDS -->
    <div id="global-compliance"></div>
    <div class="mt-8 pt-8 border-t border-e-border">
      <div class="legal-badge mb-2">Institutional Compliance Standards</div>
      <h2 class="text-2xl font-extrabold text-e-teal mb-4 flex items-center gap-2">
        <span>7.</span> Global Higher-Education Data Governance (FERPA, GDPR, CCPA, PIPEDA)
      </h2>

      <p class="legal-p">
        edvora.chat is engineered specifically for colleges, universities, and post-secondary educational institutions worldwide. We adhere to top-tier global compliance frameworks:
      </p>

      <div class="compliance-box">
        <h4 class="font-bold text-[14px] text-e-teal mb-2">🎓 FERPA Compliance (34 CFR Part 99)</h4>
        <p class="text-[13px] text-e-muted leading-relaxed">
          For institutions in the United States subject to FERPA, Edvora operates as a designated &ldquo;School Official&rdquo; with legitimate educational interests under direct institutional control. Student conversational data is strictly sequestered with mandatory row-level isolation (<code class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">WHERE organization_id = ?</code>). Records from University A can never be accessed by University B.
        </p>
      </div>

      <div class="compliance-box">
        <h4 class="font-bold text-[14px] text-e-teal mb-2">🛡️ Absolute Zero Model Training Guarantee</h4>
        <p class="text-[13px] text-e-muted leading-relaxed">
          We provide an absolute contractual guarantee: <strong>Your institution's uploaded course catalogs, syllabi, fee schedules, prospectuses, and student chat transcripts are NEVER used to train, refine, or fine-tune public foundation AI models</strong> (OpenAI, Google, Anthropic). All conversational inference operates exclusively via zero-data-retention enterprise API endpoints.
        </p>
      </div>

      <div class="compliance-box">
        <h4 class="font-bold text-[14px] text-e-teal mb-2">🌍 US State Acts (CCPA/CPRA), UK/EU GDPR &amp; Canada PIPEDA</h4>
        <p class="text-[13px] text-e-muted leading-relaxed">
          We comply with the California Consumer Privacy Act (CCPA/CPRA), UK Data Protection Act 2018 (PECR &amp; UK GDPR), EU GDPR (Arts. 13-20), and Canada&rsquo;s PIPEDA (10 Fair Information Principles). We do not sell or share student personal information for cross-context behavioral marketing. Institutional administrators can execute Data Subject Access Requests (DSAR) and Right to Erasure actions directly in the College Console.
        </p>
      </div>
    </div>

    <!-- SECTION 8: COOKIE AUDIT MATRIX -->
    <div id="cookies"></div>
    <div class="mt-8 pt-8 border-t border-e-border">
      <div class="legal-badge mb-2">Telemetry &amp; Consent</div>
      <h2 class="text-2xl font-extrabold text-e-teal mb-4 flex items-center gap-2">
        <span>8.</span> Cookie Policy &amp; Consent Management
      </h2>
      <p class="legal-p">
        Our platform utilizes essential cookies for authentication and performance telemetry. In compliance with global consent standards, non-essential cookies require opt-in consent for visitors from jurisdictions mandating prior consent.
      </p>

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
              <td>Authentication tokens (<code class="font-mono text-xs">edvora_token</code>), CSRF defense, bot security, and storing consent preferences.</td>
              <td><span class="text-emerald-700 font-bold">Always Active</span></td>
              <td>Session / 1 Year</td>
            </tr>
            <tr>
              <td><strong>Performance &amp; Telemetry</strong></td>
              <td>Measures page dwell time, interaction counts, chatbot latency, and aggregated conversion metrics.</td>
              <td><span class="text-amber-700 font-bold">Opt-In Required</span></td>
              <td>180 Days</td>
            </tr>
            <tr>
              <td><strong>Marketing Attribution</strong></td>
              <td>Captures UTM tags (<code class="font-mono text-xs">utm_source</code>) to attribute student inquiries without cross-site tracking.</td>
              <td><span class="text-amber-700 font-bold">Opt-In Required</span></td>
              <td>90 Days</td>
            </tr>
            <tr>
              <td><strong>Functional &amp; Chatbot</strong></td>
              <td>Remembers preferred widget language, voice synthesizer toggles, and UI preferences across sessions.</td>
              <td><span class="text-slate-600 font-bold">Optional</span></td>
              <td>365 Days</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-e-muted mt-2">
        💡 You may adjust or withdraw your cookie preferences anytime via the <button onclick="EdvoraConsent.openModal()" class="text-e-teal underline font-semibold cursor-pointer border-0 bg-transparent p-0 font-inherit">Cookie Settings</button> modal.
      </p>
    </div>

    <!-- SECTION 9: DATA SECURITY, RETENTION & DELETION -->
    <h2 class="legal-h2">
      <span>9.</span> Data Security Precautions, Retention &amp; Deletion
    </h2>
    <p class="legal-p">
      To protect your personal data from unauthorized access, alteration, disclosure, or destruction, we adopt strict industry-standard technical and organizational security measures:
    </p>
    <ul class="legal-list">
      <li><strong>Encryption:</strong> All data in transit is encrypted using <strong>TLS 1.3</strong>. Institutional secrets, passwords, and sensitive keys are encrypted at rest using <strong>AES-256</strong>.</li>
      <li><strong>Data Retention:</strong> We retain your personal data for no longer than is required for the purpose for which it was collected or as mandated by applicable regulatory record-keeping laws.</li>
      <li><strong>Account Deletion:</strong> You have the option to delete your account by navigating to Profile &amp; Settings in the Edvora College Console or by submitting a written request to <a href="mailto:privacy@edvora.chat" class="underline font-semibold text-e-teal">privacy@edvora.chat</a>. Upon verified deletion, active records are purged within 30 days. We may retain anonymized aggregates for statistical audit purposes.</li>
      <li><strong>User Responsibilities:</strong> Users are solely responsible for maintaining the confidentiality of their login credentials and passwords.</li>
    </ul>

    <!-- SECTION 10: TERMS OF USE & LEGAL JURISDICTION -->
    <div id="terms-and-jurisdiction"></div>
    <div class="mt-8 pt-8 border-t border-e-border">
      <div class="legal-badge mb-2">Legal Binding Agreement</div>
      <h2 class="text-2xl font-extrabold text-e-teal mb-4 flex items-center gap-2">
        <span>10.</span> Terms of Use, Indemnity &amp; Governing Jurisdiction
      </h2>
      <p class="legal-p">
        Your access and use of the Platform and Services are subject to the following binding contractual terms:
      </p>
      <ul class="legal-list">
        <li><strong>Authorized Use:</strong> You agree to provide true, accurate, and complete information during registration and use. You shall not use the Platform for any purpose that is unlawful, fraudulent, or forbidden by these Terms or applicable laws of India.</li>
        <li><strong>Proprietary Rights:</strong> All software, code, algorithms, visual layouts, graphics, prompt orchestrators, and widget frameworks are the exclusive intellectual property of Edvora Chat and its licensors.</li>
        <li><strong>Indemnification:</strong> You shall indemnify and hold harmless Platform Owner, its affiliates, directors, officers, agents, and employees from any claims, losses, or legal liabilities arising from your violation of these Terms, Privacy Policy, or third-party rights.</li>
        <li><strong>Force Majeure:</strong> Neither party shall be liable for any failure or delay in performance caused by circumstances beyond reasonable control, including natural disasters, acts of government, internet backbone failures, or power outages.</li>
        <li><strong>Governing Law:</strong> These Terms and any dispute relating thereto shall be governed by and construed in accordance with the <strong>laws of India</strong>.</li>
        <li><strong>Exclusive Jurisdiction:</strong> All disputes arising out of or in connection with these Terms, transactions on the Platform, or the Privacy Policy shall be subject to the <strong>exclusive jurisdiction of the competent courts in Gorakhpur, Uttar Pradesh, India</strong>.</li>
      </ul>
    </div>

    <!-- SECTION 11: GRIEVANCE OFFICER & CONTACT INFORMATION -->
    <div id="grievance-officer"></div>
    <div class="mt-8 pt-8 border-t border-e-border">
      <div class="legal-badge mb-2">Statutory Nodal Officer &bull; IT Rules 2011</div>
      <h2 class="text-2xl font-extrabold text-e-teal mb-4 flex items-center gap-2">
        <span>11.</span> Grievance Officer &amp; Official Contact Information
      </h2>
      <p class="legal-p">
        In accordance with the <strong>Information Technology Act, 2000</strong> and rules made thereunder, as well as the <strong>Information Technology (Intermediary Guidelines and Digital Media Ethics Code) Rules, 2021</strong>, the name and contact details of the Grievance Officer are published below:
      </p>

      <div class="p-6 rounded-2xl bg-[#F7FAF9] border border-[#D5E8DF] shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-[13.5px]">
          <div>
            <div class="text-[11px] font-bold text-e-teal uppercase tracking-wider mb-1">Grievance &amp; Compliance Officer</div>
            <div class="text-base font-extrabold text-e-teal">Salan Khalkho</div>
            <div class="text-e-muted font-medium mt-0.5">Designation: Grievance Officer &amp; Nodal Contact</div>

            <div class="mt-4 text-[11px] font-bold text-e-teal uppercase tracking-wider mb-1">Company &amp; Platform Entity</div>
            <div class="font-semibold text-e-teal">Edvora Chat &bull; 7007242973</div>
            <div class="text-e-muted mt-0.5 leading-relaxed">
              1st Floor, HNO289A, Basharatpur West,<br>
              Near Water tank, Gorakhpur,<br>
              Uttar Pradesh, 273004, India
            </div>
          </div>

          <div>
            <div class="text-[11px] font-bold text-e-teal uppercase tracking-wider mb-1">Direct Contact Details</div>
            <div class="mt-1 space-y-2">
              <div>
                <span class="text-e-muted">Grievance Email:</span><br>
                <a href="mailto:privacy@edvora.chat" class="font-semibold text-e-teal underline hover:text-emerald-700">privacy@edvora.chat</a>
              </div>
              <div>
                <span class="text-e-muted">Support &amp; General Inquiries:</span><br>
                <a href="mailto:support@edvora.chat" class="font-semibold text-e-teal underline hover:text-emerald-700">support@edvora.chat</a>
              </div>
              <div>
                <span class="text-e-muted">Billing Desk:</span><br>
                <a href="mailto:billing@edvora.chat" class="font-semibold text-e-teal underline hover:text-emerald-700">billing@edvora.chat</a>
              </div>
              <div>
                <span class="text-e-muted">Telephone Support:</span><br>
                <a href="tel:+917007242973" class="font-semibold text-e-teal hover:text-emerald-700">+91 7007242973</a>
              </div>
              <div>
                <span class="text-e-muted">Operational Hours:</span><br>
                <span class="font-semibold text-e-teal">Monday &ndash; Friday (09:00 &ndash; 18:00 IST)</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/demo_modal.php'; ?>

</body>
</html>
