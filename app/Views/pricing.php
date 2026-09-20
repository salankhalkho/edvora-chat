<?php
use App\Config\Database;

// Detect visitor country
$clientCountry = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['GEOIP_COUNTRY_CODE'] ?? '');
$defaultCurrency = ($clientCountry === 'IN') ? 'INR' : 'USD';

// Fetch active plans from database
$db = Database::getConnection();
$stmt = $db->query("SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
$plans = $stmt->fetchAll();

foreach ($plans as &$plan) {
    // Quotas
    $stmtQ = $db->prepare("SELECT quota_key, quota_label, quota_value, quota_period FROM plan_quotas WHERE plan_id = :pid ORDER BY id ASC");
    $stmtQ->execute([':pid' => $plan['id']]);
    $quotasRaw = $stmtQ->fetchAll();
    $plan['quota_map'] = [];
    foreach ($quotasRaw as $q) {
        $plan['quota_map'][$q['quota_key']] = $q['quota_value'];
    }

    // Features
    $stmtF = $db->prepare("SELECT feature_key, feature_label, is_enabled FROM plan_features WHERE plan_id = :pid ORDER BY id ASC");
    $stmtF->execute([':pid' => $plan['id']]);
    $featuresRaw = $stmtF->fetchAll();
    $plan['feature_map'] = [];
    foreach ($featuresRaw as $f) {
        $plan['feature_map'][$f['feature_key']] = (int)$f['is_enabled'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Institutional Plans &amp; Pricing — Edvora.chat</title>
    <meta name="description" content="Transparent, predictable pricing for colleges and universities. Choose the right Edvora AI admissions conversion plan for your institution.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'e-mist': '#F1F7F4',
                        'e-teal': '#063D3B',
                        'e-pistachio': '#C8FF63',
                        'e-soft-pistachio': '#E6F7D2',
                        'e-sage': '#B9D7C7',
                        'e-border': '#DDE9E3',
                        'e-text': '#092F2E',
                        'e-muted': '#71817D',
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'Inter', 'system-ui', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace']
                    },
                }
            }
        }
    </script>
    <style>
        :root {
            --e-teal: #063D3B;
            --e-pistachio: #C8FF63;
            --e-soft-pistachio: #E6F7D2;
            --e-sage: #B9D7C7;
            --e-mist: #F1F7F4;
            --e-border: #DDE9E3;
            --e-text: #092F2E;
            --e-muted: #71817D;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: "Plus Jakarta Sans", Inter, system-ui, sans-serif;
            background: #F1F7F4;
            color: #092F2E;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* Navbar & Global Overlays */
        #navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(241, 247, 244, 0.94);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid #DDE9E3;
            box-shadow: 0 4px 24px rgba(6, 61, 59, 0.04);
            transition: all 0.3s ease;
        }
        #navbar.scrolled {
            background: rgba(241, 247, 244, 0.98);
            box-shadow: 0 4px 24px rgba(6, 61, 59, 0.08);
        }

        /* Mobile Menu */
        #mobile-menu {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(241, 247, 244, 0.98);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            z-index: 9999;
            flex-direction: column;
            padding: 32px 24px;
        }
        #mobile-menu.open {
            display: flex;
        }

        /* Demo Modal */
        #demo-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10000;
            background: rgba(6, 61, 59, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        #demo-modal.open {
            display: flex;
        }

        /* Glassmorphism & Cards */
        .glass {
            background: rgba(255, 255, 255, 0.85);
            border: 1.5px solid rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 20px 60px rgba(6, 61, 59, 0.06), inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }
        .glass-card {
            background: #ffffff;
            border: 1.5px solid #DDE9E3;
            border-radius: 24px;
            box-shadow: 0 12px 36px rgba(6, 61, 59, 0.05);
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        }
        .glass-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 24px 60px rgba(6, 61, 59, 0.1);
            border-color: #B9D7C7;
        }
        .featured-card {
            background: #063D3B;
            border: 2px solid #C8FF63;
            box-shadow: 0 28px 70px rgba(6, 61, 59, 0.25), 0 0 0 1px rgba(200, 255, 99, 0.3);
            color: #ffffff;
            position: relative;
            transition: transform 0.22s ease, box-shadow 0.22s ease;
        }
        .featured-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 32px 80px rgba(6, 61, 59, 0.35);
        }

        /* Buttons */
        .btn-primary {
            background: #C8FF63;
            color: #063D3B;
            font-weight: 700;
            border-radius: 12px;
            transition: all .2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            letter-spacing: -.01em;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(200, 255, 99, 0.4);
            background: #d4ff70;
            color: #063D3B;
        }
        .btn-secondary {
            background: #ffffff;
            color: #063D3B;
            font-weight: 600;
            border: 1.5px solid #DDE9E3;
            border-radius: 12px;
            transition: all .2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-secondary:hover {
            transform: translateY(-1px);
            background: #FAFCFB;
            box-shadow: 0 6px 20px rgba(6, 61, 59, 0.08);
            border-color: #063D3B;
            color: #063D3B;
        }
        .btn-ghost {
            color: #063D3B;
            font-weight: 600;
            border-radius: 10px;
            transition: all .2s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .btn-ghost:hover {
            background: rgba(6, 61, 59, 0.06);
        }

        /* Typography & Badges */
        .eyebrow {
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #063D3B;
            opacity: .75;
        }
        .pill-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(200, 255, 99, 0.25);
            border: 1px solid rgba(200, 255, 99, 0.6);
            color: #063D3B;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 100px;
        }

        /* Interactive Switchers */
        .currency-btn {
            padding: 7px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .currency-btn.active {
            background: #063D3B;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(6, 61, 59, 0.18);
        }
        .currency-btn:not(.active) {
            background: transparent;
            color: #71817D;
        }
        .currency-btn:not(.active):hover {
            color: #063D3B;
            background: rgba(6, 61, 59, 0.05);
        }

        .cycle-btn {
            padding: 7px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .cycle-btn.active {
            background: #063D3B;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(6, 61, 59, 0.18);
        }
        .cycle-btn:not(.active) {
            background: transparent;
            color: #71817D;
        }
        .cycle-btn:not(.active):hover {
            color: #063D3B;
        }
        .savings-tag {
            background: #C8FF63;
            color: #063D3B;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: -0.01em;
        }

        /* Matrix Comparison Table */
        .matrix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #ffffff;
            border: 1.5px solid #DDE9E3;
            border-radius: 20px;
            overflow: hidden;
        }
        .matrix-table th, .matrix-table td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid #EEF4F1;
        }
        .matrix-table th {
            background: #F8FAF9;
            font-size: 13.5px;
            font-weight: 700;
            color: #063D3B;
        }
        .matrix-table tr:last-child td {
            border-bottom: none;
        }
        .matrix-table .cat-header {
            background: #F1F7F4;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #063D3B;
            padding: 14px 20px;
        }
        .matrix-table .highlight-col {
            background: rgba(200, 255, 99, 0.08);
            font-weight: 600;
        }
        .matrix-check {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #E6F7D2;
            color: #063D3B;
            font-size: 12px;
            font-weight: 900;
        }
        .matrix-cross {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #F1F5F9;
            color: #94A3B8;
            font-size: 11px;
            font-weight: 700;
        }

        /* FAQ Accordion */
        .faq-item {
            background: #ffffff;
            border: 1.5px solid #DDE9E3;
            border-radius: 16px;
            margin-bottom: 12px;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        .faq-item:hover {
            border-color: #B9D7C7;
        }
        .faq-question {
            padding: 18px 24px;
            font-size: 16px;
            font-weight: 700;
            color: #063D3B;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }
        .faq-answer {
            padding: 0 24px 20px 24px;
            font-size: 14.5px;
            line-height: 1.65;
            color: #4F7470;
            display: none;
        }
        .faq-item.open .faq-answer {
            display: block;
        }
        .faq-icon {
            transition: transform 0.25s ease;
        }
        .faq-item.open .faq-icon {
            transform: rotate(180deg);
        }
    </style>
</head>
<body data-default-currency="<?= htmlspecialchars($defaultCurrency) ?>">

<!-- HEADER NAVBAR -->
<?php require __DIR__ . '/partials/header.php'; ?>

<!-- MAIN CONTENT -->
<main class="pt-32 pb-20 px-4 sm:px-6">
    <div class="max-w-[1240px] mx-auto">

        <!-- HEADER SECTION -->
        <div class="text-center max-w-[840px] mx-auto mb-12">
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full mb-4" style="background:#E6F7D2;border:1px solid #B9D7C7">
                <span class="w-2 h-2 rounded-full bg-emerald-600 animate-pulse"></span>
                <span class="text-[12px] font-bold text-e-teal tracking-wide uppercase">Multi-Tenant Institutional Pricing</span>
            </div>
            <h1 class="font-extrabold text-e-teal tracking-tight mb-4" style="font-size:clamp(2.2rem,4vw,3.3rem);line-height:1.12">
                Predictable, Value-Driven Plans for <span style="color:#D97706">Higher Education.</span>
            </h1>
            <p class="text-[17px] text-e-muted leading-[1.65] max-w-[660px] mx-auto">
                Equip your admissions department with full AI engagement, high-intent lead qualification, tour bookings, and document delivery.
            </p>

            <!-- SWITCHERS CONTAINER -->
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                <!-- Currency Switcher -->
                <div class="bg-white p-1 rounded-xl border border-e-border inline-flex items-center shadow-sm">
                    <button id="currBtnUsd" onclick="setCurrency('USD')" class="currency-btn active">
                        <span>🌐</span> <span>USA &amp; Canada ($ USD)</span>
                    </button>
                    <button id="currBtnInr" onclick="setCurrency('INR')" class="currency-btn">
                        <span>🇮🇳</span> <span>India (INR ₹)</span>
                    </button>
                </div>

                <!-- Billing Cycle Switcher -->
                <div class="bg-white p-1 rounded-xl border border-e-border inline-flex items-center shadow-sm">
                    <button id="cycleBtnMonthly" onclick="setBillingCycle('monthly')" class="cycle-btn active">
                        Monthly
                    </button>
                    <button id="cycleBtnYearly" onclick="setBillingCycle('yearly')" class="cycle-btn">
                        Yearly <span class="savings-tag">Save ~17%</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 3 CORE PLAN CARDS -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-20">
            <?php foreach ($plans as $p): ?>
                <?php 
                    $isGrowth = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                    $cardClass = $isGrowth ? 'featured-card p-8 rounded-3xl flex flex-col' : 'glass-card p-8 rounded-3xl flex flex-col';
                    $textColor = $isGrowth ? 'text-white' : 'text-e-teal';
                    $subTextColor = $isGrowth ? 'text-emerald-100/75' : 'text-e-muted';
                    $borderIconBg = $isGrowth ? 'bg-emerald-500/20 text-[#C8FF63]' : 'bg-[#E6F7D2] text-[#063D3B]';

                    // Currency Price calculations
                    $priceMonthlyInr = number_format($p['price_monthly_paise'] / 100);
                    $priceYearlyInr = number_format(($p['price_yearly_paise'] / 100) / 12);
                    $priceYearlyInrTotal = number_format($p['price_yearly_paise'] / 100);

                    $priceMonthlyUsd = number_format($p['price_monthly_usd_cents'] / 100);
                    $priceYearlyUsd = number_format(($p['price_yearly_usd_cents'] / 100) / 12);
                    $priceYearlyUsdTotal = number_format($p['price_yearly_usd_cents'] / 100);

                    $qChatbots = ($p['quota_map']['max_chatbots'] ?? 1) == -1 ? 'Unlimited' : ($p['quota_map']['max_chatbots'] ?? 1);
                    $qMsgs = ($p['quota_map']['max_messages_per_month'] ?? 2000) == -1 ? 'Unlimited' : number_format($p['quota_map']['max_messages_per_month'] ?? 2000);
                    $qLeads = ($p['quota_map']['max_leads_per_month'] ?? 100) == -1 ? 'Unlimited' : number_format($p['quota_map']['max_leads_per_month'] ?? 100);
                    $qSources = ($p['quota_map']['max_knowledge_sources'] ?? 20) == -1 ? 'Unlimited' : ($p['quota_map']['max_knowledge_sources'] ?? 20);
                    $qUpload = ($p['quota_map']['max_file_upload_mb'] ?? 5) == -1 ? 'Unlimited' : ($p['quota_map']['max_file_upload_mb'] ?? 5) . ' MB';
                    $qStaff = ($p['quota_map']['max_staff_users'] ?? 1) == -1 ? 'Unlimited' : ($p['quota_map']['max_staff_users'] ?? 1);

                    $badge = !empty($p['badge_text']) ? $p['badge_text'] : ($isGrowth ? 'Most Popular' : null);
                    $ctaText = !empty($p['cta_text']) ? $p['cta_text'] : ($isGrowth ? 'Register Institution →' : (stripos($p['name'], 'Pro') !== false ? 'Book Enterprise Demo →' : 'Get Started →'));
                    $ctaLink = !empty($p['cta_link']) ? $p['cta_link'] : ($isGrowth ? '/app#signup?plan=' . urlencode($p['name']) : (stripos($p['name'], 'Pro') !== false ? '#demo' : '/app#signup?plan=' . urlencode($p['name'])));
                ?>
                <div class="<?= $cardClass ?>">
                    <?php if ($badge): ?>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[11px] font-extrabold tracking-widest uppercase text-[#C8FF63]"><?= htmlspecialchars(strtoupper($badge)) ?></span>
                            <span class="pill-badge text-[10px] px-2.5 py-0.5"><?= htmlspecialchars($badge) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="eyebrow mb-3"><?= strtoupper(htmlspecialchars($p['name'])) ?></div>
                    <?php endif; ?>

                    <h3 class="text-[22px] font-black <?= $textColor ?> mb-2"><?= htmlspecialchars($p['name']) ?></h3>
                    <p class="text-[13.5px] <?= $subTextColor ?> leading-[1.5] mb-6 min-h-[42px]"><?= htmlspecialchars($p['description']) ?></p>

                    <!-- Price Display -->
                    <div class="mb-6 pb-6 border-b <?= $isGrowth ? 'border-white/15' : 'border-e-border' ?>">
                        <!-- USD Monthly -->
                        <div class="price-box usd-price monthly-price">
                            <div class="flex items-baseline gap-1">
                                <span class="text-[36px] font-black <?= $textColor ?> tracking-tight">$<?= $priceMonthlyUsd ?></span>
                                <span class="text-[14px] font-medium <?= $subTextColor ?>">/month</span>
                            </div>
                            <div class="text-[11.5px] <?= $subTextColor ?> mt-1">Billed monthly</div>
                        </div>
                        <!-- USD Yearly -->
                        <div class="price-box usd-price yearly-price hidden">
                            <div class="flex items-baseline gap-1">
                                <span class="text-[36px] font-black <?= $textColor ?> tracking-tight">$<?= $priceYearlyUsd ?></span>
                                <span class="text-[14px] font-medium <?= $subTextColor ?>">/mo</span>
                            </div>
                            <div class="text-[11.5px] <?= $subTextColor ?> mt-1">$<?= $priceYearlyUsdTotal ?> billed annually</div>
                        </div>

                        <!-- INR Monthly -->
                        <div class="price-box inr-price monthly-price hidden">
                            <div class="flex items-baseline gap-1">
                                <span class="text-[36px] font-black <?= $textColor ?> tracking-tight">&#8377;<?= $priceMonthlyInr ?></span>
                                <span class="text-[14px] font-medium <?= $subTextColor ?>">/month</span>
                            </div>
                            <div class="text-[11.5px] <?= $subTextColor ?> mt-1">Billed monthly</div>
                        </div>
                        <!-- INR Yearly -->
                        <div class="price-box inr-price yearly-price hidden">
                            <div class="flex items-baseline gap-1">
                                <span class="text-[36px] font-black <?= $textColor ?> tracking-tight">&#8377;<?= $priceYearlyInr ?></span>
                                <span class="text-[14px] font-medium <?= $subTextColor ?>">/mo</span>
                            </div>
                            <div class="text-[11.5px] <?= $subTextColor ?> mt-1">&#8377;<?= $priceYearlyInrTotal ?> billed annually</div>
                        </div>
                    </div>

                    <!-- Highlights List (Dynamic from DB Quotas & Features) -->
                    <ul class="space-y-3.5 mb-8 flex-1 list-none p-0 text-[13.5px]">
                        <li class="flex items-center gap-2.5 <?= $textColor ?>">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 <?= $borderIconBg ?> text-[11px] font-black">✓</span>
                            <span><strong><?= $qChatbots ?></strong> Department Chatbot<?= $qChatbots === 1 || $qChatbots === '1' ? '' : 's' ?></span>
                        </li>
                        <li class="flex items-center gap-2.5 <?= $textColor ?>">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 <?= $borderIconBg ?> text-[11px] font-black">✓</span>
                            <span><strong><?= $qMsgs ?></strong> AI Conversations / month</span>
                        </li>
                        <li class="flex items-center gap-2.5 <?= $textColor ?>">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 <?= $borderIconBg ?> text-[11px] font-black">✓</span>
                            <span><strong><?= $qLeads ?></strong> Captured Qualified Leads / mo</span>
                        </li>
                        <li class="flex items-center gap-2.5 <?= $textColor ?>">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 <?= $borderIconBg ?> text-[11px] font-black">✓</span>
                            <span><strong><?= $qSources ?></strong> Knowledge Sources (PDFs/Websites)</span>
                        </li>
                        <li class="flex items-center gap-2.5 <?= $textColor ?>">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 <?= $borderIconBg ?> text-[11px] font-black">✓</span>
                            <span><strong><?= $qUpload ?></strong> File Upload &bull; <strong><?= $qStaff ?></strong> Counselor Seat<?= $qStaff === 1 || $qStaff === '1' ? '' : 's' ?></span>
                        </li>
                        <?php if (!empty($p['feature_map']['campus_tour'])): ?>
                        <li class="flex items-center gap-2.5 <?= $textColor ?>">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 <?= $borderIconBg ?> text-[11px] font-black">✓</span>
                            <span>Campus Tour &amp; Counselor Scheduling</span>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($p['feature_map']['whatsapp_integration'])): ?>
                        <li class="flex items-center gap-2.5 <?= $textColor ?>">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 <?= $borderIconBg ?> text-[11px] font-black">✓</span>
                            <span>WhatsApp Integration &amp; 99.9% SLA</span>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <!-- CTA Button -->
                    <?php if ($ctaLink === '#demo'): ?>
                        <button onclick="openDemo()" class="<?= $isGrowth ? 'btn-primary' : 'btn-secondary' ?> py-3.5 justify-center text-[14.5px] w-full font-bold">
                            <?= htmlspecialchars($ctaText) ?>
                        </button>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($ctaLink) ?>" class="<?= $isGrowth ? 'btn-primary' : 'btn-secondary' ?> py-3.5 justify-center text-[14.5px] w-full font-bold">
                            <?= htmlspecialchars($ctaText) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- COMPLETE PLAN BREAKUP & COMPARISON MATRIX (100% DYNAMIC FROM DATABASE) -->
        <div class="mb-24">
            <div class="text-center max-w-[700px] mx-auto mb-10">
                <div class="eyebrow mb-2">COMPLETE PLAN BREAKUP</div>
                <h2 class="text-[28px] font-extrabold text-e-teal tracking-tight">Compare All Features &amp; Quotas Side-by-Side</h2>
                <p class="text-[14.5px] text-e-muted mt-2">Every plan includes the complete consultative AI conversation model tailored for college admissions.</p>
            </div>

            <div class="overflow-x-auto shadow-sm rounded-2xl">
                <table class="matrix-table">
                    <thead>
                        <tr>
                            <th class="w-[34%]">Capability &amp; Limits</th>
                            <?php foreach ($plans as $p): ?>
                                <?php $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false); ?>
                                <th class="text-center <?= $isHighlight ? 'highlight-col' : '' ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if (!empty($p['badge_text'])): ?>
                                        <div class="text-[11px] font-semibold text-emerald-600">(<?= htmlspecialchars($p['badge_text']) ?>)</div>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Category: Core Capacity -->
                        <tr><td colspan="<?= count($plans) + 1 ?>" class="cat-header">1. Core Capacity &amp; Usage Quotas</td></tr>
                        
                        <!-- Row: Department Chatbots -->
                        <tr>
                            <td class="font-semibold text-e-teal">Department Chatbots</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $val = $p['quota_map']['max_chatbots'] ?? 1;
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    $display = ($val == -1) ? 'Unlimited Multi-Campus' : ($val . ' Department' . ($val == 1 ? '' : 's'));
                                    $colorClass = ($val == -1) ? 'font-bold text-emerald-700' : ($isHighlight ? 'font-bold text-e-teal' : '');
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?> <?= $colorClass ?>">
                                    <?= $display ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Row: Knowledge Sources -->
                        <tr>
                            <td class="font-semibold text-e-teal">Knowledge Sources (PDFs/Websites/Text)</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $val = $p['quota_map']['max_knowledge_sources'] ?? 20;
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    $display = ($val == -1) ? 'Unlimited Knowledge' : ($val . ' Sources');
                                    $colorClass = ($val == -1) ? 'font-bold text-emerald-700' : ($isHighlight ? 'font-bold text-e-teal' : '');
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?> <?= $colorClass ?>">
                                    <?= $display ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Row: Monthly AI Conversations -->
                        <tr>
                            <td class="font-semibold text-e-teal">Monthly AI Conversations</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $val = $p['quota_map']['max_messages_per_month'] ?? 2000;
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    $display = ($val == -1) ? 'Unlimited' : number_format($val) . ' / mo';
                                    $colorClass = ($val == -1 || $val >= 50000) ? 'font-bold text-emerald-700' : ($isHighlight ? 'font-bold text-e-teal' : '');
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?> <?= $colorClass ?>">
                                    <?= $display ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Row: Captured Leads & Contacts -->
                        <tr>
                            <td class="font-semibold text-e-teal">Captured Leads &amp; Contacts</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $val = $p['quota_map']['max_leads_per_month'] ?? 100;
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    $display = ($val == -1) ? 'Unlimited Leads' : number_format($val) . ' Leads / mo';
                                    $colorClass = ($val == -1) ? 'font-bold text-emerald-700' : ($isHighlight ? 'font-bold text-e-teal' : '');
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?> <?= $colorClass ?>">
                                    <?= $display ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Row: Document File Upload Limit -->
                        <tr>
                            <td class="font-semibold text-e-teal">Document File Upload Limit</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $val = $p['quota_map']['max_file_upload_mb'] ?? 5;
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    $display = ($val == -1) ? 'Unlimited' : $val . ' MB per file';
                                    $colorClass = ($val == -1 || $val >= 50) ? 'font-bold text-emerald-700' : ($isHighlight ? 'font-bold text-e-teal' : '');
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?> <?= $colorClass ?>">
                                    <?= $display ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Row: Staff & Counselor Seats -->
                        <tr>
                            <td class="font-semibold text-e-teal">Staff &amp; Counselor Seats</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $val = $p['quota_map']['max_staff_users'] ?? 1;
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    $display = ($val == -1) ? 'Unlimited Seats' : ($val . ' Seat' . ($val == 1 ? '' : 's'));
                                    $colorClass = ($val == -1) ? 'font-bold text-emerald-700' : ($isHighlight ? 'font-bold text-e-teal' : '');
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?> <?= $colorClass ?>">
                                    <?= $display ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Row: History Retention -->
                        <tr>
                            <td class="font-semibold text-e-teal">Conversation History Retention</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $val = $p['quota_map']['conversation_history_days'] ?? 30;
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    $display = ($val == -1) ? 'Multi-Year / Custom' : ($val >= 365 ? '365 Days / Multi-Year' : $val . ' Days');
                                    $colorClass = ($val >= 365 || $val == -1) ? 'font-bold text-emerald-700' : ($isHighlight ? 'font-bold text-e-teal' : '');
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?> <?= $colorClass ?>">
                                    <?= $display ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Category: Conversational Admissions Engine -->
                        <tr><td colspan="<?= count($plans) + 1 ?>" class="cat-header">2. Conversational Admissions Engine</td></tr>
                        <?php 
                            $engineFeatures = [
                                'lead_capture' => 'Instant Lead Capture Triggers',
                                'campus_tour' => 'Campus Tour Booking &amp; Slot Scheduling',
                                'counselor_callback' => 'Counselor 1-on-1 Callback Dispatch',
                                'asset_delivery' => 'Instant Fee &amp; Prospectus PDF Delivery',
                                'intent_scoring' => 'Automated Intent Scoring &amp; Lead Qualification'
                            ];
                        ?>
                        <?php foreach ($engineFeatures as $fKey => $fLabel): ?>
                            <tr>
                                <td class="font-semibold text-e-teal"><?= $fLabel ?></td>
                                <?php foreach ($plans as $p): ?>
                                    <?php 
                                        $isEnabled = !empty($p['feature_map'][$fKey]);
                                        $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    ?>
                                    <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?>">
                                        <?php if ($isEnabled): ?>
                                            <span class="matrix-check">✓</span>
                                        <?php else: ?>
                                            <span class="matrix-cross">✕</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Category: Platform & Customization -->
                        <tr><td colspan="<?= count($plans) + 1 ?>" class="cat-header">3. Platform &amp; Customization</td></tr>
                        <?php 
                            $platformFeatures = [
                                'custom_branding' => 'Custom Branding, Colors &amp; Avatar',
                                'allowed_domains' => 'Allowed Domains &amp; Embed Security',
                                'multilingual' => 'Multi-Language Admissions Counseling',
                                'url_auto_refresh' => 'URL Knowledge Source Auto-Refresh',
                                'mobile_sdk' => 'Mobile Webview &amp; Integration SDK'
                            ];
                        ?>
                        <?php foreach ($platformFeatures as $fKey => $fLabel): ?>
                            <tr>
                                <td class="font-semibold text-e-teal"><?= $fLabel ?></td>
                                <?php foreach ($plans as $p): ?>
                                    <?php 
                                        $isEnabled = !empty($p['feature_map'][$fKey]);
                                        $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    ?>
                                    <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?>">
                                        <?php if ($isEnabled): ?>
                                            <span class="matrix-check">✓</span>
                                        <?php else: ?>
                                            <span class="matrix-cross">✕</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Category: Analytics & Governance -->
                        <tr><td colspan="<?= count($plans) + 1 ?>" class="cat-header">4. Analytics, Insights &amp; Compliance</td></tr>
                        <?php 
                            $analyticsFeatures = [
                                'analytics_dashboard' => 'Admissions Intelligence &amp; Lead Dashboard',
                                'knowledge_gap_detection' => 'Knowledge Gap Detection &amp; Resolution',
                                'csv_export' => 'CSV Lead &amp; Analytics Export',
                                'institutional_privacy' => 'Institutional Privacy &amp; Data Encryption'
                            ];
                        ?>
                        <?php foreach ($analyticsFeatures as $fKey => $fLabel): ?>
                            <tr>
                                <td class="font-semibold text-e-teal"><?= $fLabel ?></td>
                                <?php foreach ($plans as $p): ?>
                                    <?php 
                                        $isEnabled = !empty($p['feature_map'][$fKey]);
                                        $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    ?>
                                    <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?>">
                                        <?php if ($isEnabled): ?>
                                            <span class="matrix-check">✓</span>
                                        <?php else: ?>
                                            <span class="matrix-cross">✕</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Category: Enterprise & Support -->
                        <tr><td colspan="<?= count($plans) + 1 ?>" class="cat-header">5. Enterprise &amp; Dedicated Support</td></tr>
                        
                        <!-- Support Channel Row -->
                        <tr>
                            <td class="font-semibold text-e-teal">Support Channel</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $sTier = $p['feature_map']['support_channel'] ?? 0;
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                    if ($sTier == 2) {
                                        $sText = 'Dedicated Account Mgr';
                                        $sClass = 'font-bold text-emerald-700';
                                    } elseif ($sTier == 1) {
                                        $sText = 'Priority Email + Chat';
                                        $sClass = 'font-bold text-e-teal';
                                    } else {
                                        $sText = 'Email Support';
                                        $sClass = '';
                                    }
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?> <?= $sClass ?>">
                                    <?= $sText ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- WhatsApp Integration -->
                        <tr>
                            <td class="font-semibold text-e-teal">WhatsApp Official Business Integration</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $isEnabled = !empty($p['feature_map']['whatsapp_integration']);
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?>">
                                    <?php if ($isEnabled): ?>
                                        <span class="font-bold text-emerald-700"><span class="matrix-check">✓</span> Included</span>
                                    <?php else: ?>
                                        <span class="matrix-cross">✕</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- SLA & 99.9% Uptime Guarantee -->
                        <tr>
                            <td class="font-semibold text-e-teal">SLA &amp; 99.9% Uptime Guarantee</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $isEnabled = !empty($p['feature_map']['sla_guarantee']);
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?>">
                                    <?php if ($isEnabled): ?>
                                        <span class="font-bold text-emerald-700"><span class="matrix-check">✓</span> Included</span>
                                    <?php else: ?>
                                        <span class="matrix-cross">✕</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Custom SIS / CRM Webhook Integration -->
                        <tr>
                            <td class="font-semibold text-e-teal">Custom SIS / CRM Webhook Integration</td>
                            <?php foreach ($plans as $p): ?>
                                <?php 
                                    $isEnabled = !empty($p['feature_map']['custom_integrations']);
                                    $isHighlight = (!empty($p['badge_text']) || stripos($p['name'], 'Growth') !== false);
                                ?>
                                <td class="text-center <?= $isHighlight ? 'highlight-col' : '' ?>">
                                    <?php if ($isEnabled): ?>
                                        <span class="font-bold text-emerald-700"><span class="matrix-check">✓</span> Included</span>
                                    <?php else: ?>
                                        <span class="matrix-cross">✕</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- FREQUENTLY ASKED QUESTIONS -->
        <div class="max-w-[840px] mx-auto mb-24">
            <div class="text-center mb-10">
                <div class="eyebrow mb-2">COMMON QUESTIONS</div>
                <h2 class="text-[28px] font-extrabold text-e-teal tracking-tight">Institutional Billing &amp; Plan FAQs</h2>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>How does Edvora handle admissions surges during peak enrollment periods?</span>
                    <span class="faq-icon text-[18px]">▼</span>
                </div>
                <div class="faq-answer">
                    Edvora is architected on enterprise cloud infrastructure with zero queue bottlenecks. During application deadlines and counselling result days, your bot effortlessly handles thousands of concurrent student queries without any degradation in response speed.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>Can we upgrade or downgrade our plan anytime?</span>
                    <span class="faq-icon text-[18px]">▼</span>
                </div>
                <div class="faq-answer">
                    Yes. You can upgrade your plan at any time directly from the College Admin billing dashboard. Prorated adjustments are calculated automatically so you never lose unused credit.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>How are institutions in the US, Canada, and internationally billed?</span>
                    <span class="faq-icon text-[18px]">▼</span>
                </div>
                <div class="faq-answer">
                    Institutions in the United States, Canada, and internationally are billed in US Dollars ($ USD) via credit card, ACH, wire transfer, or standard institutional Purchase Orders (NET 30 / W-9 vendor compliant). Indian institutions can be billed in INR (₹) via GST-compliant invoicing.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>Do you offer a guided pilot or trial for universities?</span>
                    <span class="faq-icon text-[18px]">▼</span>
                </div>
                <div class="faq-answer">
                    Yes! You can book a personalized demo with our higher-education solutions team. We will ingest your sample academic viewbook, course catalog, or tuition schedule and demonstrate a live, interactive test bot tailored to your campus within 24 hours.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>Is student conversation data kept private and compliant?</span>
                    <span class="faq-icon text-[18px]">▼</span>
                </div>
                <div class="faq-answer">
                    Absolutely. Every institution runs in strict multi-tenant isolation. Your student lead contacts and institutional knowledge bases are never shared, never leaked across tenants, and never used to train global third-party AI models.
                </div>
            </div>
        </div>

        <!-- FINAL CTA BANNER -->
        <div class="glass rounded-[32px] p-10 md:p-14 text-center border border-e-border relative overflow-hidden" style="background:linear-gradient(135deg,rgba(230,247,210,.7),rgba(255,255,255,.9))">
            <div class="eyebrow mb-3">TRANSFORM YOUR ADMISSIONS PIPELINE</div>
            <h2 class="text-[30px] md:text-[38px] font-extrabold text-e-teal tracking-tight mb-4" style="line-height:1.15">
                Ready to Turn Web Traffic into Enrolled Students?
            </h2>
            <p class="text-[16px] text-e-muted max-w-[600px] mx-auto leading-[1.65] mb-8">
                Join forward-thinking colleges and universities scaling their student recruitment with Edvora.
            </p>
            <div class="flex items-center justify-center gap-4 flex-wrap">
                <button onclick="openDemo()" class="btn-primary text-[15.5px] px-8 py-3.5 font-bold">
                    Schedule an Institutional Demo &rarr;
                </button>
                <a href="/app#signup" class="btn-secondary text-[15.5px] px-8 py-3.5 font-bold">
                    Register Your College
                </a>
            </div>
        </div>

    </div>
</main>

<!-- DEMO MODAL -->
<?php require __DIR__ . '/partials/demo_modal.php'; ?>

<!-- FOOTER -->
<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
    let currentCurrency = document.body.dataset.defaultCurrency || 'INR';
    let currentCycle = 'monthly';

    // Check localStorage preference
    const savedCurrency = localStorage.getItem('edvora_currency');
    if (savedCurrency && (savedCurrency === 'INR' || savedCurrency === 'USD')) {
        currentCurrency = savedCurrency;
    }

    function updatePricingDisplay() {
        // Toggle buttons active state
        document.getElementById('currBtnInr').classList.toggle('active', currentCurrency === 'INR');
        document.getElementById('currBtnUsd').classList.toggle('active', currentCurrency === 'USD');

        document.getElementById('cycleBtnMonthly').classList.toggle('active', currentCycle === 'monthly');
        document.getElementById('cycleBtnYearly').classList.toggle('active', currentCycle === 'yearly');

        // Hide all price boxes
        document.querySelectorAll('.price-box').forEach(el => el.classList.add('hidden'));

        // Show the active combination
        const selector = `.${currentCurrency.toLowerCase()}-price.${currentCycle}-price`;
        document.querySelectorAll(selector).forEach(el => el.classList.remove('hidden'));
    }

    function setCurrency(curr) {
        currentCurrency = curr;
        localStorage.setItem('edvora_currency', curr);
        updatePricingDisplay();
    }

    function setBillingCycle(cycle) {
        currentCycle = cycle;
        updatePricingDisplay();
    }

    function toggleFaq(btn) {
        const item = btn.parentElement;
        item.classList.toggle('open');
    }

    function toggleMenu() {
        const m = document.getElementById('mobile-menu');
        if (m) {
            const open = m.classList.toggle('open');
            document.body.style.overflow = open ? 'hidden' : '';
        }
    }

    window.addEventListener('scroll', () => {
        const nav = document.getElementById('navbar');
        if (nav) nav.classList.toggle('scrolled', window.scrollY > 40);
    }, { passive: true });

    // Initialize on load
    document.addEventListener('DOMContentLoaded', () => {
        updatePricingDisplay();
    });
</script>

</body>
</html>
