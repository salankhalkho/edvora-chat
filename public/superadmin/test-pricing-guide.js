/**
 * Multi-Currency Pricing Testing Guide & Interactive Simulator
 * Superadmin Component — edvora.chat
 */

(function () {
    let activeSimCurrency = 'USD';
    let activeSimCycle = 'monthly';

    const SIM_DATA = {
        USD: {
            url: 'https://edvora.chat/pricing?country=US',
            cycleLabel: { monthly: '/month', yearly: '/mo' },
            starter: { monthly: '$299', yearly: '$1,990', yearlyNote: '$1,990 billed annually' },
            growth: { monthly: '$699', yearly: '$5,990', yearlyNote: '$5,990 billed annually' },
            pro: { monthly: '$2,100', yearly: '$21,000', yearlyNote: '$21,000 billed annually' }
        },
        INR: {
            url: 'https://edvora.chat/pricing?country=IN',
            cycleLabel: { monthly: '/month', yearly: '/mo' },
            starter: { monthly: '₹2,999', yearly: '₹29,990', yearlyNote: '₹29,990 billed annually' },
            growth: { monthly: '₹6,999', yearly: '₹69,990', yearlyNote: '₹69,990 billed annually' },
            pro: { monthly: '₹14,999', yearly: '₹1,49,990', yearlyNote: '₹1,49,990 billed annually' }
        }
    };

    function injectModal() {
        if (document.getElementById('testPricingGuideModalOverlay')) return;

        const modalHtml = `
        <div id="testPricingGuideModalOverlay" class="tpg-overlay" style="display:none;">
            <div class="tpg-modal" role="dialog" aria-modal="true" aria-labelledby="tpgModalTitle">
                <!-- Modal Header -->
                <div class="tpg-header">
                    <div class="tpg-title-group">
                        <div class="tpg-icon-badge">🧪</div>
                        <div>
                            <div id="tpgModalTitle" class="tpg-title">Multi-Currency Pricing Testing Guide &amp; Simulator</div>
                            <div class="tpg-subtitle">How to test and verify USD ($) and INR (₹) geolocation pricing on edvora.chat</div>
                        </div>
                    </div>
                    <button type="button" class="tpg-close-btn" onclick="closeTestPricingModal()" title="Close (Esc)">&times;</button>
                </div>

                <!-- Navigation Tabs -->
                <div class="tpg-tab-bar">
                    <button type="button" class="tpg-tab-btn active" data-tpg-tab="guide" onclick="switchTpgTab('guide')">
                        📖 Testing Guide (Option 1 &amp; 2)
                    </button>
                    <button type="button" class="tpg-tab-btn" data-tpg-tab="simulator" onclick="switchTpgTab('simulator')">
                        🖥️ Interactive Simulator
                    </button>
                    <button type="button" class="tpg-tab-btn" data-tpg-tab="screenshot" onclick="switchTpgTab('screenshot')">
                        📸 Live Screenshot Reference
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="tpg-body">
                    <!-- TAB 1: GUIDE -->
                    <div id="tpgView-guide" class="tpg-view active">
                        <!-- Quick Launch Strip -->
                        <div class="tpg-quick-strip">
                            <div class="tpg-quick-label">⚡ Direct QA Launchers:</div>
                            <div class="tpg-quick-buttons">
                                <a href="https://edvora.chat/pricing?country=US" target="_blank" rel="noopener noreferrer" class="tpg-pill-btn tpg-pill-usd">
                                    🇺🇸 Test USD Pricing ($) ↗
                                </a>
                                <a href="https://edvora.chat/pricing?country=IN" target="_blank" rel="noopener noreferrer" class="tpg-pill-btn tpg-pill-inr">
                                    🇮🇳 Test Indian Pricing (₹) ↗
                                </a>
                                <a href="https://edvora.chat/pricing" target="_blank" rel="noopener noreferrer" class="tpg-pill-btn tpg-pill-neutral">
                                    🌐 Default Geo Detection ↗
                                </a>
                            </div>
                        </div>

                        <!-- Option 1 -->
                        <div class="tpg-card">
                            <div class="tpg-card-header">
                                <span class="tpg-badge-rec">Option 1 (Recommended)</span>
                                <h3 class="tpg-card-title">URL Parameter Override</h3>
                            </div>
                            <p class="tpg-text">
                                The pricing page script includes a built-in query parameter check, so you can test any country or currency directly simply by adding <code>?country=</code> or <code>?currency=</code> to the URL:
                            </p>
                            <ul class="tpg-list">
                                <li>
                                    <strong>View USD pricing:</strong>
                                    <div class="tpg-url-row">
                                        <a href="https://edvora.chat/pricing?country=US" target="_blank" class="tpg-link">https://edvora.chat/pricing?country=US</a>
                                        <span class="tpg-text-muted">or</span>
                                        <a href="https://edvora.chat/pricing?currency=USD" target="_blank" class="tpg-link">https://edvora.chat/pricing?currency=USD</a>
                                        <button type="button" class="tpg-copy-btn" onclick="tpgCopyText('https://edvora.chat/pricing?country=US', this)">Copy USD URL</button>
                                    </div>
                                </li>
                                <li>
                                    <strong>View Indian pricing:</strong>
                                    <div class="tpg-url-row">
                                        <a href="https://edvora.chat/pricing?country=IN" target="_blank" class="tpg-link">https://edvora.chat/pricing?country=IN</a>
                                        <span class="tpg-text-muted">or</span>
                                        <a href="https://edvora.chat/pricing?currency=INR" target="_blank" class="tpg-link">https://edvora.chat/pricing?currency=INR</a>
                                        <button type="button" class="tpg-copy-btn" onclick="tpgCopyText('https://edvora.chat/pricing?country=IN', this)">Copy INR URL</button>
                                    </div>
                                </li>
                            </ul>

                            <div class="tpg-code-header">
                                <span>How it works in the code:</span>
                                <button type="button" class="tpg-copy-btn" onclick="tpgCopyCode('tpgCodeSnippet1', this)">Copy Code</button>
                            </div>
                            <pre class="tpg-code-block"><code id="tpgCodeSnippet1"><span class="tpg-c-k">const</span> urlParams = <span class="tpg-c-k">new</span> <span class="tpg-c-f">URLSearchParams</span>(window.location.search);
<span class="tpg-c-k">const</span> override = urlParams.<span class="tpg-c-f">get</span>(<span class="tpg-c-s">'country'</span>) || (urlParams.<span class="tpg-c-f">get</span>(<span class="tpg-c-s">'currency'</span>) === <span class="tpg-c-s">'USD'</span> ? <span class="tpg-c-s">'US'</span> : <span class="tpg-c-k">null</span>);

<span class="tpg-c-cm">// Use the URL override if present; otherwise use the Cloudflare worker detection</span>
<span class="tpg-c-k">const</span> detectedCountry = override || (<span class="tpg-c-k">typeof</span> window.visitorCountry !== <span class="tpg-c-s">'undefined'</span> ? window.visitorCountry : <span class="tpg-c-s">''</span>);
<span class="tpg-c-k">const</span> currentCurrency = (detectedCountry.<span class="tpg-c-f">toUpperCase</span>() === <span class="tpg-c-s">'IN'</span>) ? <span class="tpg-c-s">'INR'</span> : <span class="tpg-c-s">'USD'</span>;</code></pre>

                            <div class="tpg-callout">
                                <span class="tpg-callout-icon">💡</span>
                                <div>
                                    <strong>Why this is best:</strong> It requires no VPN, works on any browser or mobile phone, is completely invisible to regular visitors, and makes QA and development testing instantaneous.
                                </div>
                            </div>
                        </div>

                        <!-- Option 2 -->
                        <div class="tpg-card" style="margin-top: 20px;">
                            <div class="tpg-card-header">
                                <span class="tpg-badge-alt">Option 2</span>
                                <h3 class="tpg-card-title">Browser DevTools Console</h3>
                            </div>
                            <p class="tpg-text">
                                At any time on <a href="https://edvora.chat/pricing" target="_blank" class="tpg-link">https://edvora.chat/pricing</a>, you can open DevTools (<kbd>F12</kbd> or right-click &rarr; <em>Inspect</em>), switch to the <strong>Console</strong> tab, and run:
                            </p>

                            <div class="tpg-code-header">
                                <span>Switch to USD ($):</span>
                                <button type="button" class="tpg-copy-btn" onclick="tpgCopyCode('tpgCodeSnippet2', this)">Copy Snippet</button>
                            </div>
                            <pre class="tpg-code-block"><code id="tpgCodeSnippet2">window.visitorCountry = <span class="tpg-c-s">'US'</span>;
updatePricingDisplay();</code></pre>

                            <div class="tpg-code-header" style="margin-top: 12px;">
                                <span>Switch back to INR (₹):</span>
                                <button type="button" class="tpg-copy-btn" onclick="tpgCopyCode('tpgCodeSnippet3', this)">Copy Snippet</button>
                            </div>
                            <pre class="tpg-code-block"><code id="tpgCodeSnippet3">window.visitorCountry = <span class="tpg-c-s">'IN'</span>;
updatePricingDisplay();</code></pre>

                            <p class="tpg-text" style="margin-top: 10px; color: #94a3b8;">
                                ✨ The page will immediately switch all card prices, badges, and comparison tables to USD (or INR) without even needing a page refresh.
                            </p>
                        </div>
                    </div>

                    <!-- TAB 2: INTERACTIVE SIMULATOR -->
                    <div id="tpgView-simulator" class="tpg-view">
                        <div class="tpg-sim-container">
                            <!-- Browser Chrome Window -->
                            <div class="tpg-browser-window">
                                <div class="tpg-browser-header">
                                    <div class="tpg-browser-dots">
                                        <span class="tpg-dot tpg-dot-red"></span>
                                        <span class="tpg-dot tpg-dot-yellow"></span>
                                        <span class="tpg-dot tpg-dot-green"></span>
                                    </div>
                                    <div class="tpg-browser-address-bar">
                                        <span class="tpg-browser-lock">🔒</span>
                                        <span id="tpgSimAddressUrl" class="tpg-browser-url">https://edvora.chat/pricing?country=US</span>
                                    </div>
                                    <a id="tpgSimOpenLink" href="https://edvora.chat/pricing?country=US" target="_blank" rel="noopener noreferrer" class="tpg-browser-external" title="Open this exact URL in new tab">
                                        Open Live ↗
                                    </a>
                                </div>

                                <!-- Simulator Controls Bar -->
                                <div class="tpg-sim-controls">
                                    <div class="tpg-sim-control-group">
                                        <span class="tpg-sim-label">Simulate Visitor Region:</span>
                                        <div class="tpg-sim-btn-group">
                                            <button type="button" id="tpgSimBtnUSD" class="tpg-sim-toggle active" onclick="setSimCurrency('USD')">
                                                🇺🇸 US / Global ($ USD)
                                            </button>
                                            <button type="button" id="tpgSimBtnINR" class="tpg-sim-toggle" onclick="setSimCurrency('INR')">
                                                🇮🇳 India (₹ INR)
                                            </button>
                                        </div>
                                    </div>

                                    <div class="tpg-sim-control-group">
                                        <span class="tpg-sim-label">Billing Frequency:</span>
                                        <div class="tpg-sim-btn-group">
                                            <button type="button" id="tpgSimBtnMonthly" class="tpg-sim-toggle active" onclick="setSimCycle('monthly')">
                                                Monthly
                                            </button>
                                            <button type="button" id="tpgSimBtnYearly" class="tpg-sim-toggle" onclick="setSimCycle('yearly')">
                                                Yearly <span class="tpg-save-pill">Save ~17%</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Mockup Content Surface -->
                                <div class="tpg-sim-content">
                                    <div class="tpg-sim-hero">
                                        <div class="tpg-sim-tag">• MULTI-TENANT INSTITUTIONAL PRICING •</div>
                                        <h2 class="tpg-sim-headline">Predictable, Value-Driven Plans for <span class="tpg-highlight">Higher Education.</span></h2>
                                        <p class="tpg-sim-subhead">Equip your admissions department with full AI engagement, high-intent lead qualification, tour bookings, and document delivery.</p>
                                    </div>

                                    <!-- 3 Pricing Cards Mockup -->
                                    <div class="tpg-sim-cards-grid">
                                        <!-- Starter Card -->
                                        <div class="tpg-sim-card">
                                            <div class="tpg-sim-card-top">
                                                <div class="tpg-sim-card-tier">STARTER</div>
                                                <h4 class="tpg-sim-card-name">Starter</h4>
                                                <p class="tpg-sim-card-desc">Perfect for small institutes launching their first AI assistant</p>
                                            </div>
                                            <div class="tpg-sim-price-box">
                                                <div class="tpg-sim-price-main">
                                                    <span id="tpgSimStarterPrice" class="tpg-sim-val">$299</span>
                                                    <span id="tpgSimStarterCycle" class="tpg-sim-freq">/month</span>
                                                </div>
                                                <div id="tpgSimStarterNote" class="tpg-sim-note">Billed monthly</div>
                                            </div>
                                            <ul class="tpg-sim-features">
                                                <li><span class="tpg-check">✓</span> <strong>1</strong> Department Chatbot</li>
                                                <li><span class="tpg-check">✓</span> <strong>2,000</strong> AI Conversations / mo</li>
                                                <li><span class="tpg-check">✓</span> Lead Capture Engine</li>
                                                <li><span class="tpg-check">✓</span> Standard Analytics</li>
                                            </ul>
                                            <button type="button" class="tpg-sim-btn tpg-sim-btn-light">Choose Starter</button>
                                        </div>

                                        <!-- Growth Card (Highlighted) -->
                                        <div class="tpg-sim-card tpg-sim-card-featured">
                                            <div class="tpg-sim-badge">Most Popular</div>
                                            <div class="tpg-sim-card-top">
                                                <div class="tpg-sim-card-tier">MOST POPULAR</div>
                                                <h4 class="tpg-sim-card-name">Growth</h4>
                                                <p class="tpg-sim-card-desc">Best for growing colleges needing multiple bots &amp; higher limits</p>
                                            </div>
                                            <div class="tpg-sim-price-box">
                                                <div class="tpg-sim-price-main">
                                                    <span id="tpgSimGrowthPrice" class="tpg-sim-val">$699</span>
                                                    <span id="tpgSimGrowthCycle" class="tpg-sim-freq">/month</span>
                                                </div>
                                                <div id="tpgSimGrowthNote" class="tpg-sim-note">Billed monthly</div>
                                            </div>
                                            <ul class="tpg-sim-features">
                                                <li><span class="tpg-check tpg-check-pistachio">✓</span> <strong>3</strong> Department Chatbots</li>
                                                <li><span class="tpg-check tpg-check-pistachio">✓</span> <strong>200,000</strong> AI Conversations / mo</li>
                                                <li><span class="tpg-check tpg-check-pistachio">✓</span> Live Human Agent Hand-off</li>
                                                <li><span class="tpg-check tpg-check-pistachio">✓</span> Campus Tour &amp; Visit Booking</li>
                                            </ul>
                                            <button type="button" class="tpg-sim-btn tpg-sim-btn-accent">Choose Growth</button>
                                        </div>

                                        <!-- Pro Card -->
                                        <div class="tpg-sim-card">
                                            <div class="tpg-sim-card-top">
                                                <div class="tpg-sim-card-tier">PRO</div>
                                                <h4 class="tpg-sim-card-name">Pro</h4>
                                                <p class="tpg-sim-card-desc">For large universities requiring maximum capacity &amp; custom limits</p>
                                            </div>
                                            <div class="tpg-sim-price-box">
                                                <div class="tpg-sim-price-main">
                                                    <span id="tpgSimProPrice" class="tpg-sim-val">$2,100</span>
                                                    <span id="tpgSimProCycle" class="tpg-sim-freq">/month</span>
                                                </div>
                                                <div id="tpgSimProNote" class="tpg-sim-note">Billed monthly</div>
                                            </div>
                                            <ul class="tpg-sim-features">
                                                <li><span class="tpg-check">✓</span> <strong>Unlimited</strong> Department Chatbots</li>
                                                <li><span class="tpg-check">✓</span> <strong>Unlimited</strong> AI Conversations / mo</li>
                                                <li><span class="tpg-check">✓</span> Dedicated Account Manager</li>
                                                <li><span class="tpg-check">✓</span> Custom CRM &amp; SIS Webhooks</li>
                                            </ul>
                                            <button type="button" class="tpg-sim-btn tpg-sim-btn-light">Choose Pro</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: SCREENSHOT REFERENCE -->
                    <div id="tpgView-screenshot" class="tpg-view">
                        <div class="tpg-screenshot-container">
                            <div class="tpg-screenshot-header">
                                <div>
                                    <div class="tpg-screenshot-title">📸 Verified Production USD Pricing View</div>
                                    <div class="tpg-screenshot-desc">Live browser capture of <code>https://edvora.chat/pricing?country=US</code></div>
                                </div>
                                <a href="/superadmin/pricing-test-usd-preview.png" target="_blank" class="tpg-pill-btn tpg-pill-neutral">
                                    Open Full-Size Image ↗
                                </a>
                            </div>

                            <div class="tpg-screenshot-frame">
                                <img src="/superadmin/pricing-test-usd-preview.png" alt="USD Pricing Preview on Edvora.chat" class="tpg-screenshot-img" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="tpg-footer">
                    <div class="tpg-footer-hint">
                        💡 <strong>Pro Tip:</strong> Parameters are case-insensitive. Both <code>?country=us</code> and <code>?currency=usd</code> switch the entire site cleanly.
                    </div>
                    <button type="button" class="tpg-btn-secondary" onclick="closeTestPricingModal()">Close Guide</button>
                </div>
            </div>
        </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        injectStyles();
        bindGlobalEvents();
    }

    function injectStyles() {
        if (document.getElementById('testPricingGuideStyles')) return;

        const css = `
        .tpg-overlay {
            position: fixed;
            inset: 0;
            background: rgba(4, 7, 15, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #f1f5f9;
        }
        .tpg-modal {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 16px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(255, 255, 255, 0.05);
            max-width: 960px;
            width: 100%;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: tpgFadeIn 0.18s ease-out;
        }
        @keyframes tpgFadeIn {
            from { opacity: 0; transform: scale(0.98) translateY(6px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .tpg-header {
            padding: 16px 20px;
            background: #111827;
            border-bottom: 1px solid #1f2937;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .tpg-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .tpg-icon-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(37, 99, 235, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .tpg-title {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.01em;
        }
        .tpg-subtitle {
            font-size: 11.5px;
            color: #94a3b8;
            margin-top: 2px;
        }
        .tpg-close-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.15s ease;
        }
        .tpg-close-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
        }
        .tpg-tab-bar {
            display: flex;
            gap: 4px;
            background: #0b1120;
            padding: 8px 16px;
            border-bottom: 1px solid #1e293b;
        }
        .tpg-tab-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tpg-tab-btn:hover {
            color: #e2e8f0;
            background: rgba(255, 255, 255, 0.05);
        }
        .tpg-tab-btn.active {
            color: #ffffff;
            background: #1e293b;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }
        .tpg-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        .tpg-view {
            display: none;
        }
        .tpg-view.active {
            display: block;
        }

        /* Quick Launch Strip */
        .tpg-quick-strip {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .tpg-quick-label {
            font-size: 11.5px;
            font-weight: 700;
            color: #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .tpg-quick-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .tpg-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .tpg-pill-usd {
            background: rgba(37, 99, 235, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.4);
        }
        .tpg-pill-usd:hover {
            background: rgba(37, 99, 235, 0.3);
            color: #bfdbfe;
        }
        .tpg-pill-inr {
            background: rgba(16, 185, 129, 0.15);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.4);
        }
        .tpg-pill-inr:hover {
            background: rgba(16, 185, 129, 0.3);
            color: #a7f3d0;
        }
        .tpg-pill-neutral {
            background: rgba(148, 163, 184, 0.1);
            color: #cbd5e1;
            border: 1px solid rgba(148, 163, 184, 0.25);
        }
        .tpg-pill-neutral:hover {
            background: rgba(148, 163, 184, 0.2);
            color: #f1f5f9;
        }

        /* Card container */
        .tpg-card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 18px 20px;
        }
        .tpg-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .tpg-badge-rec {
            background: #047857;
            color: #ecfdf5;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 4px;
            letter-spacing: 0.5px;
        }
        .tpg-badge-alt {
            background: #475569;
            color: #f8fafc;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 4px;
            letter-spacing: 0.5px;
        }
        .tpg-card-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
        }
        .tpg-text {
            font-size: 12.5px;
            color: #cbd5e1;
            line-height: 1.6;
            margin: 0 0 12px 0;
        }
        .tpg-list {
            margin: 0 0 16px 0;
            padding-left: 20px;
            color: #cbd5e1;
            font-size: 12px;
            line-height: 1.8;
        }
        .tpg-url-row {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 2px;
        }
        .tpg-link {
            color: #60a5fa;
            text-decoration: none;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            background: rgba(37, 99, 235, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        .tpg-link:hover {
            color: #93c5fd;
            text-decoration: underline;
        }
        .tpg-text-muted {
            color: #64748b;
            font-size: 11px;
        }
        .tpg-code-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .tpg-copy-btn {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            font-size: 10.5px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .tpg-copy-btn:hover {
            background: #334155;
            color: #ffffff;
        }
        .tpg-copy-btn.copied {
            background: #047857 !important;
            color: #ffffff !important;
            border-color: #10b981 !important;
        }
        .tpg-code-block {
            background: #090d16;
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 12px 14px;
            overflow-x: auto;
            margin: 0 0 14px 0;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            line-height: 1.5;
            color: #e2e8f0;
        }
        .tpg-c-k { color: #f43f5e; font-weight: 600; }
        .tpg-c-f { color: #60a5fa; }
        .tpg-c-s { color: #34d399; }
        .tpg-c-cm { color: #64748b; font-style: italic; }

        .tpg-callout {
            background: rgba(37, 99, 235, 0.08);
            border-left: 3px solid #3b82f6;
            border-radius: 0 8px 8px 0;
            padding: 10px 14px;
            display: flex;
            gap: 10px;
            font-size: 11.5px;
            color: #bfdbfe;
            line-height: 1.5;
        }
        .tpg-callout-icon {
            font-size: 16px;
            flex-shrink: 0;
        }

        /* Simulator Styles */
        .tpg-sim-container {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .tpg-browser-window {
            background: #F1F7F4;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.4);
            color: #092F2E;
        }
        .tpg-browser-header {
            background: #e2e8f0;
            border-bottom: 1px solid #cbd5e1;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .tpg-browser-dots {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }
        .tpg-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .tpg-dot-red { background: #ef4444; }
        .tpg-dot-yellow { background: #f59e0b; }
        .tpg-dot-green { background: #10b981; }
        .tpg-browser-address-bar {
            flex: 1;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: #334155;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .tpg-browser-external {
            font-size: 11px;
            font-weight: 700;
            color: #063d3b;
            text-decoration: none;
            background: #cbd5e1;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.15s ease;
            flex-shrink: 0;
        }
        .tpg-browser-external:hover {
            background: #94a3b8;
            color: #ffffff;
        }

        /* Simulator Controls */
        .tpg-sim-controls {
            background: #063D3B;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .tpg-sim-control-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .tpg-sim-label {
            font-size: 11px;
            font-weight: 700;
            color: #e6f7d2;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .tpg-sim-btn-group {
            display: inline-flex;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 8px;
            padding: 2px;
        }
        .tpg-sim-toggle {
            background: transparent;
            border: none;
            color: #b9d7c7;
            font-size: 11px;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .tpg-sim-toggle.active {
            background: #ffffff;
            color: #063D3B;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .tpg-save-pill {
            background: #C8FF63;
            color: #063D3B;
            font-size: 9px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
            margin-left: 4px;
        }

        /* Simulator Content Surface */
        .tpg-sim-content {
            padding: 24px 20px;
            background: #F1F7F4;
        }
        .tpg-sim-hero {
            text-align: center;
            max-width: 620px;
            margin: 0 auto 24px auto;
        }
        .tpg-sim-tag {
            font-size: 10px;
            font-weight: 800;
            color: #063D3B;
            background: #E6F7D2;
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        .tpg-sim-headline {
            font-size: 20px;
            font-weight: 800;
            color: #063D3B;
            margin: 0 0 6px 0;
            line-height: 1.25;
            letter-spacing: -0.02em;
        }
        .tpg-highlight {
            color: #c05621;
        }
        .tpg-sim-subhead {
            font-size: 11.5px;
            color: #71817D;
            margin: 0;
            line-height: 1.4;
        }

        /* 3 Cards Grid */
        .tpg-sim-cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            align-items: stretch;
        }
        @media (max-width: 768px) {
            .tpg-sim-cards-grid { grid-template-columns: 1fr; }
        }
        .tpg-sim-card {
            background: #ffffff;
            border: 1.5px solid #DDE9E3;
            border-radius: 14px;
            padding: 18px 16px;
            display: flex;
            flex-direction: column;
            position: relative;
            box-shadow: 0 4px 14px rgba(6, 61, 59, 0.04);
        }
        .tpg-sim-card-featured {
            background: #063D3B;
            border-color: #063D3B;
            color: #ffffff;
            box-shadow: 0 10px 30px rgba(6, 61, 59, 0.25);
        }
        .tpg-sim-badge {
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(200, 255, 99, 0.2);
            color: #C8FF63;
            border: 1px solid rgba(200, 255, 99, 0.4);
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 2px 7px;
            border-radius: 12px;
            letter-spacing: 0.5px;
        }
        .tpg-sim-card-tier {
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #71817D;
            margin-bottom: 2px;
        }
        .tpg-sim-card-featured .tpg-sim-card-tier {
            color: #B9D7C7;
        }
        .tpg-sim-card-name {
            font-size: 16px;
            font-weight: 800;
            margin: 0 0 4px 0;
            color: #063D3B;
        }
        .tpg-sim-card-featured .tpg-sim-card-name {
            color: #ffffff;
        }
        .tpg-sim-card-desc {
            font-size: 10.5px;
            color: #71817D;
            margin: 0 0 14px 0;
            min-height: 28px;
            line-height: 1.35;
        }
        .tpg-sim-card-featured .tpg-sim-card-desc {
            color: #B9D7C7;
        }
        .tpg-sim-price-box {
            border-bottom: 1px solid #DDE9E3;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .tpg-sim-card-featured .tpg-sim-price-box {
            border-bottom-color: rgba(255, 255, 255, 0.15);
        }
        .tpg-sim-price-main {
            display: flex;
            align-items: baseline;
            gap: 2px;
        }
        .tpg-sim-val {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: -0.02em;
            color: #063D3B;
        }
        .tpg-sim-card-featured .tpg-sim-val {
            color: #ffffff;
        }
        .tpg-sim-freq {
            font-size: 11px;
            font-weight: 600;
            color: #71817D;
        }
        .tpg-sim-card-featured .tpg-sim-freq {
            color: #B9D7C7;
        }
        .tpg-sim-note {
            font-size: 9.5px;
            color: #71817D;
            margin-top: 2px;
        }
        .tpg-sim-card-featured .tpg-sim-note {
            color: #B9D7C7;
        }
        .tpg-sim-features {
            list-style: none;
            padding: 0;
            margin: 0 0 16px 0;
            font-size: 10.5px;
            line-height: 1.8;
            flex: 1;
        }
        .tpg-check {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #E6F7D2;
            color: #063D3B;
            font-size: 9px;
            font-weight: 900;
            text-align: center;
            line-height: 14px;
            margin-right: 4px;
        }
        .tpg-check-pistachio {
            background: #C8FF63;
            color: #063D3B;
        }
        .tpg-sim-btn {
            width: 100%;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
        }
        .tpg-sim-btn-light {
            background: #F1F7F4;
            color: #063D3B;
            border: 1px solid #DDE9E3;
        }
        .tpg-sim-btn-light:hover {
            background: #E6F7D2;
        }
        .tpg-sim-btn-accent {
            background: #C8FF63;
            color: #063D3B;
        }
        .tpg-sim-btn-accent:hover {
            background: #b5f545;
        }

        /* Screenshot View */
        .tpg-screenshot-container {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .tpg-screenshot-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 10px;
            padding: 12px 16px;
        }
        .tpg-screenshot-title {
            font-size: 13px;
            font-weight: 700;
            color: #ffffff;
        }
        .tpg-screenshot-desc {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 2px;
        }
        .tpg-screenshot-frame {
            background: #090d16;
            border: 1px solid #1f2937;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
        }
        .tpg-screenshot-img {
            width: 100%;
            height: auto;
            display: block;
            object-fit: contain;
        }

        /* Modal Footer */
        .tpg-footer {
            padding: 12px 20px;
            background: #111827;
            border-top: 1px solid #1f2937;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .tpg-footer-hint {
            font-size: 11px;
            color: #94a3b8;
        }
        .tpg-btn-secondary {
            background: #1e293b;
            color: #e2e8f0;
            border: 1px solid #334155;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .tpg-btn-secondary:hover {
            background: #334155;
            color: #ffffff;
        }
        `;

        const styleEl = document.createElement('style');
        styleEl.id = 'testPricingGuideStyles';
        styleEl.textContent = css;
        document.head.appendChild(styleEl);
    }

    function bindGlobalEvents() {
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeTestPricingModal();
            }
        });

        const overlay = document.getElementById('testPricingGuideModalOverlay');
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    closeTestPricingModal();
                }
            });
        }
    }

    window.openTestPricingModal = function () {
        injectModal();
        const overlay = document.getElementById('testPricingGuideModalOverlay');
        if (overlay) {
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            updateSimDisplay();
        }
    };

    window.closeTestPricingModal = function () {
        const overlay = document.getElementById('testPricingGuideModalOverlay');
        if (overlay) {
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    window.switchTpgTab = function (tabName) {
        document.querySelectorAll('.tpg-tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-tpg-tab') === tabName);
        });

        document.querySelectorAll('.tpg-view').forEach(view => {
            view.classList.toggle('active', view.id === 'tpgView-' + tabName);
        });

        if (tabName === 'simulator') {
            updateSimDisplay();
        }
    };

    window.setSimCurrency = function (curr) {
        activeSimCurrency = curr;
        document.getElementById('tpgSimBtnUSD').classList.toggle('active', curr === 'USD');
        document.getElementById('tpgSimBtnINR').classList.toggle('active', curr === 'INR');
        updateSimDisplay();
    };

    window.setSimCycle = function (cycle) {
        activeSimCycle = cycle;
        document.getElementById('tpgSimBtnMonthly').classList.toggle('active', cycle === 'monthly');
        document.getElementById('tpgSimBtnYearly').classList.toggle('active', cycle === 'yearly');
        updateSimDisplay();
    };

    function updateSimDisplay() {
        const d = SIM_DATA[activeSimCurrency] || SIM_DATA.USD;
        const cycleLabel = d.cycleLabel[activeSimCycle] || '/month';

        const addressUrl = d.url;
        const addressEl = document.getElementById('tpgSimAddressUrl');
        const openLink = document.getElementById('tpgSimOpenLink');
        if (addressEl) addressEl.innerText = addressUrl;
        if (openLink) openLink.href = addressUrl;

        // Starter
        const starterVal = d.starter[activeSimCycle];
        const starterNote = activeSimCycle === 'monthly' ? 'Billed monthly' : d.starter.yearlyNote;
        document.getElementById('tpgSimStarterPrice').innerText = starterVal;
        document.getElementById('tpgSimStarterCycle').innerText = cycleLabel;
        document.getElementById('tpgSimStarterNote').innerText = starterNote;

        // Growth
        const growthVal = d.growth[activeSimCycle];
        const growthNote = activeSimCycle === 'monthly' ? 'Billed monthly' : d.growth.yearlyNote;
        document.getElementById('tpgSimGrowthPrice').innerText = growthVal;
        document.getElementById('tpgSimGrowthCycle').innerText = cycleLabel;
        document.getElementById('tpgSimGrowthNote').innerText = growthNote;

        // Pro
        const proVal = d.pro[activeSimCycle];
        const proNote = activeSimCycle === 'monthly' ? 'Billed monthly' : d.pro.yearlyNote;
        document.getElementById('tpgSimProPrice').innerText = proVal;
        document.getElementById('tpgSimProCycle').innerText = cycleLabel;
        document.getElementById('tpgSimProNote').innerText = proNote;
    }

    window.tpgCopyText = function (text, btn) {
        if (!navigator.clipboard) {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        } else {
            navigator.clipboard.writeText(text);
        }
        showBtnFeedback(btn, '✓ Copied!');
    };

    window.tpgCopyCode = function (codeElemId, btn) {
        const el = document.getElementById(codeElemId);
        if (!el) return;
        const text = el.innerText || el.textContent;
        window.tpgCopyText(text, btn);
    };

    function showBtnFeedback(btn, text) {
        if (!btn) return;
        const orig = btn.innerText;
        btn.innerText = text;
        btn.classList.add('copied');
        setTimeout(() => {
            btn.innerText = orig;
            btn.classList.remove('copied');
        }, 1800);
    }
})();
