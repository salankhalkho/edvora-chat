<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipping &amp; Digital Delivery Policy — edvora.chat</title>
    <meta name="description" content="Shipping &amp; Electronic Delivery Policy for edvora.chat. Cloud-based SaaS software with instant digital delivery and zero shipping fees.">
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
        .legal-card { background: #ffffff; border: 1.5px solid #DCE9E5; border-radius: 18px; padding: 36px 32px; box-shadow: 0 10px 30px rgba(6,61,59,0.04); margin-bottom: 28px; }
        .legal-h2 { font-size: 20px; font-weight: 800; color: #063D3B; margin-top: 32px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .legal-h2:first-of-type { margin-top: 0; }
        .legal-p { font-size: 14.5px; line-height: 1.75; color: #4F7470; margin-bottom: 16px; }
        .legal-list { list-style-type: disc; margin-left: 20px; margin-bottom: 16px; font-size: 14.5px; line-height: 1.75; color: #4F7470; }
        .legal-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 100px; font-size: 11.5px; font-weight: 700; color: #047857; text-transform: uppercase; letter-spacing: 0.05em; }
        .success-box { background: #ECFDF5; border: 1.5px solid #A7F3D0; border-radius: 12px; padding: 20px; margin: 18px 0; color: #065F46; }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between">

<?php require __DIR__ . '/partials/header.php'; ?>

<main class="max-w-[1000px] mx-auto px-6 py-12 flex-1 w-full">
  <div class="mb-8">
    <div class="legal-badge mb-3">Merchant Compliance &bull; Instant Digital Delivery</div>
    <h1 class="text-3xl sm:text-4xl font-extrabold text-e-teal tracking-tight mb-3">Shipping &amp; Digital Delivery Policy</h1>
    <p class="text-e-muted text-[15px]">Effective Date: October 2026 &bull; Governing edvora.chat Electronic Software Fulfillment</p>
  </div>

  <div class="legal-card">
    <div class="success-box">
      <h3 class="font-extrabold text-[16px] text-emerald-900 mb-2">⚡ 100% DIGITAL ELECTRONIC DELIVERY &bull; NO PHYSICAL SHIPPING</h3>
      <p class="text-[14px] leading-relaxed text-emerald-950">
        <strong>edvora.chat supplies cloud-hosted Software-as-a-Service (SaaS). We do NOT ship physical products or parcels via courier or postal authorities.</strong> Access to all software features, college administrative dashboards, and API integrations is delivered purely electronically and instantaneously upon transaction completion.
      </p>
    </div>

    <h2 class="legal-h2">1. Electronic Fulfillment &amp; Instant Access</h2>
    <p class="legal-p">
      Upon successful completion of payment on <code class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">https://edvora.chat/</code>:
    </p>
    <ul class="legal-list">
      <li><strong>Immediate Account Provisioning:</strong> Your institutional user account is automatically provisioned and activated in real time.</li>
      <li><strong>Instant Access via User ID &amp; Password:</strong> You receive immediate, unrestricted access to the application via your registered User ID (email address) and password at <a href="/app" class="underline text-e-teal font-semibold">https://edvora.chat/app</a>.</li>
      <li><strong>Electronic Order Confirmation &amp; Invoice:</strong> A digital order confirmation, payment receipt, and subscription invoice are automatically generated and dispatched to your registered email address within seconds of transaction approval.</li>
    </ul>

    <h2 class="legal-h2">2. Delivery Timeframes</h2>
    <p class="legal-p">
      Since our software is deployed entirely in the cloud, delivery is instantaneous:
    </p>
    <ul class="legal-list">
      <li><strong>Standard Fulfillment Window:</strong> 0 to 15 minutes following online payment authorization.</li>
      <li><strong>Delivery Delays:</strong> In the rare event that an automated confirmation email is delayed by email spam filters or network firewalls, your access to the web console remains immediately active. You can log in directly at <a href="/app" class="underline text-e-teal font-semibold">https://edvora.chat/app</a> using your credentials. If you experience any access issues, please contact <a href="mailto:support@edvora.chat" class="underline font-semibold text-e-teal">support@edvora.chat</a> for immediate assistance.</li>
    </ul>

    <h2 class="legal-h2">3. Shipping Charges</h2>
    <p class="legal-p">
      Because delivery is executed entirely via telecommunication networks and cloud servers, <strong>there are no shipping, handling, freight, or courier fees</strong> applied to any of our subscriptions or software plans.
    </p>

    <h2 class="legal-h2">4. Company &amp; Operational Details</h2>
    <div class="p-5 rounded-xl bg-[#F7FAF9] border border-e-border text-sm text-e-teal leading-relaxed">
      <strong>Platform Owner:</strong> Edvora Chat &bull; 7007242973<br>
      <strong>Registered Operational Office:</strong> 1st Floor, HNO289A, Basharatpur West, Near Water tank, Gorakhpur, Uttar Pradesh, 273004, India<br>
      <strong>Customer Support:</strong> <a href="mailto:support@edvora.chat" class="underline font-semibold">support@edvora.chat</a><br>
      <strong>Telephone:</strong> <a href="tel:+917007242973" class="underline font-semibold">+91 7007242973</a> (Monday &ndash; Friday, 09:00 &ndash; 18:00 IST)
    </div>
  </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/demo_modal.php'; ?>

</body>
</html>
