<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us &amp; Institutional Support — edvora.chat</title>
    <meta name="description" content="Contact Edvora Chat. Registered office address, phone support, billing desk, and Grievance Officer details.">
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
        .legal-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #E8F5F1; border: 1px solid #A7D7C8; border-radius: 100px; font-size: 11.5px; font-weight: 700; color: #047857; text-transform: uppercase; letter-spacing: 0.05em; }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between">

<?php require __DIR__ . '/partials/header.php'; ?>

<main class="max-w-[1000px] mx-auto px-6 py-12 flex-1 w-full">
  <div class="mb-8">
    <div class="legal-badge mb-3">Customer Support &bull; Institutional Inquiries &bull; Grievance Redressal</div>
    <h1 class="text-3xl sm:text-4xl font-extrabold text-e-teal tracking-tight mb-3">Contact Us</h1>
    <p class="text-e-muted text-[15px]">We are here to assist you with institutional admissions automation, billing, and platform compliance.</p>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
    <!-- Contact Info Card -->
    <div class="legal-card mb-0">
      <h2 class="text-xl font-bold text-e-teal mb-4 flex items-center gap-2">
        <span>📍</span> Registered Operational Office
      </h2>
      <div class="text-[14px] text-e-teal leading-relaxed mb-6">
        <strong>Edvora Chat &bull; 7007242973</strong><br>
        1st Floor, HNO289A, Basharatpur West,<br>
        Near Water tank, Gorakhpur,<br>
        Uttar Pradesh, 273004, India
      </div>

      <h2 class="text-xl font-bold text-e-teal mb-4 flex items-center gap-2">
        <span>📞</span> Telephone &amp; Hours
      </h2>
      <div class="text-[14px] text-e-teal leading-relaxed mb-6">
        <strong>Phone:</strong> <a href="tel:+917007242973" class="hover:text-emerald-700 underline">+91 7007242973</a><br>
        <strong>Operating Hours:</strong> Monday &ndash; Friday (09:00 &ndash; 18:00 IST)<br>
        <strong>Response Time:</strong> Within 24 business hours
      </div>

      <h2 class="text-xl font-bold text-e-teal mb-4 flex items-center gap-2">
        <span>✉️</span> Official Email Desks
      </h2>
      <div class="text-[14px] text-e-teal leading-relaxed space-y-2">
        <div><strong>General Support:</strong> <a href="mailto:support@edvora.chat" class="hover:text-emerald-700 underline">support@edvora.chat</a></div>
        <div><strong>Billing &amp; Subscriptions:</strong> <a href="mailto:billing@edvora.chat" class="hover:text-emerald-700 underline">billing@edvora.chat</a></div>
        <div><strong>Privacy &amp; Compliance:</strong> <a href="mailto:privacy@edvora.chat" class="hover:text-emerald-700 underline">privacy@edvora.chat</a></div>
        <div><strong>Grievance Officer:</strong> <a href="mailto:privacy@edvora.chat" class="hover:text-emerald-700 underline">Salan Khalkho (Grievance Officer)</a></div>
      </div>
    </div>

    <!-- Quick Inquiry / Contact Card -->
    <div class="legal-card mb-0 flex flex-col justify-between">
      <div>
        <h2 class="text-xl font-bold text-e-teal mb-2">Book a Consultation or Send a Message</h2>
        <p class="text-[13.5px] text-e-muted leading-relaxed mb-6">
          Are you an admissions director, registrar, or higher education dean? Request a tailored demonstration of our AI admissions conversion software.
        </p>

        <div class="space-y-4 text-sm">
          <div class="p-4 rounded-xl bg-[#F7FAF9] border border-e-border">
            <div class="font-bold text-e-teal mb-1">Schedule a Live Demo</div>
            <p class="text-xs text-e-muted mb-3">See how Edvora ingests your curriculum catalogs and viewbooks to generate qualified student applications.</p>
            <button onclick="openDemo()" class="btn-primary text-xs px-4 py-2 w-full justify-center">Schedule Demo &rarr;</button>
          </div>

          <div class="p-4 rounded-xl bg-[#F7FAF9] border border-e-border">
            <div class="font-bold text-e-teal mb-1">College Registration &amp; Sign In</div>
            <p class="text-xs text-e-muted mb-3">Sign up for an institutional account or manage your existing admissions widget and staff team.</p>
            <div class="flex gap-2">
              <a href="/app" class="btn-secondary text-xs px-3 py-2 flex-1 text-center justify-center">Sign In</a>
              <a href="/app#signup" class="btn-primary text-xs px-3 py-2 flex-1 text-center justify-center">Register &rarr;</a>
            </div>
          </div>
        </div>
      </div>

      <div class="pt-6 border-t border-e-border text-xs text-e-muted mt-6">
        All inquiries are processed in accordance with our <a href="/privacy" class="underline text-e-teal">Privacy Policy</a>.
      </div>
    </div>
  </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/demo_modal.php'; ?>

</body>
</html>
