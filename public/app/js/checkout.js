/**
 * ═══════════════════════════════════════════════════════════════════
 * CHECKOUT & COMMERCIAL GATEWAY CONTROLLER (checkout.js)
 * Project: edvora.chat — Multi-Tenant Admissions Assistant
 * Handles:
 *   - Scenario 1: Pricing page -> Preselected Plan -> Registration -> Payment -> Status Page
 *   - Scenario 2: Direct Registration -> Plan Selection -> Payment -> Status Page
 *   - Razorpay (INR) & PayPal (USD) gateway execution
 *   - Status Pages (Success with Welcome + Login link / Failed with Try Again)
 *   - Strict Dashboard gating (no unpaid access)
 * ═══════════════════════════════════════════════════════════════════
 */

(function () {
    'use strict';

    // Global Checkout State
    window.edvoraCheckoutState = {
        plan: 'Starter',
        planId: 1,
        cycle: 'monthly',
        currency: 'INR',
        gateway: 'razorpay',
        hasPreselectedPlan: false,
        orgId: null,
        orgName: '',
        userEmail: '',
        userName: '',
        activeOrder: null,
        lastFailureReason: ''
    };

    const state = window.edvoraCheckoutState;

    // Plan Specifications & Pricing
    const PLAN_PRICING = {
        Starter: {
            id: 1,
            name: 'Starter',
            inr: { monthly: 2999, yearly: 29990, formattedMonthly: '₹2,999/mo', formattedYearly: '₹29,990/yr' },
            usd: { monthly: 299, yearly: 2490, formattedMonthly: '$299/mo', formattedYearly: '$2,490/yr' },
            highlights: ['1 Department Chatbot', '2,000 AI Conversations / mo', '200 Qualified Leads / mo', '20 Knowledge Sources', '2 Counselor Seats']
        },
        Growth: {
            id: 2,
            name: 'Growth',
            inr: { monthly: 6999, yearly: 69990, formattedMonthly: '₹6,999/mo', formattedYearly: '₹69,990/yr' },
            usd: { monthly: 699, yearly: 5990, formattedMonthly: '$699/mo', formattedYearly: '$5,990/yr' },
            highlights: ['3 Department Chatbots', '200,000 AI Conversations / mo', 'Unlimited Qualified Leads', '100 Knowledge Sources', '10 Counselor Seats & Tours']
        }
    };

    /**
     * Initialize URL parameters and detected geo currency
     */
    function initCheckoutParameters() {
        const urlParams = new URLSearchParams(window.location.search);
        let rawHash = window.location.hash || '';
        if (rawHash.includes('?')) {
            const hashParts = rawHash.split('?')[1];
            const hashParams = new URLSearchParams(hashParts);
            hashParams.forEach((val, key) => {
                if (!urlParams.has(key)) urlParams.set(key, val);
            });
        }

        // 1. Detect Plan
        const planParam = urlParams.get('plan');
        if (planParam) {
            const normalizedPlan = planParam.charAt(0).toUpperCase() + planParam.slice(1).toLowerCase();
            if (PLAN_PRICING[normalizedPlan]) {
                state.plan = normalizedPlan;
                state.planId = PLAN_PRICING[normalizedPlan].id;
                state.hasPreselectedPlan = true;
            }
        }

        // 2. Detect Billing Cycle
        const cycleParam = urlParams.get('cycle') || urlParams.get('billing') || urlParams.get('billing_cycle');
        if (cycleParam && (cycleParam.toLowerCase() === 'yearly' || cycleParam.toLowerCase() === 'annual')) {
            state.cycle = 'yearly';
        } else {
            state.cycle = 'monthly';
        }

        // 3. Detect Currency & Gateway
        const currencyParam = (urlParams.get('currency') || '').toUpperCase();
        let detectedCountry = (window.visitorCountry || window.detectedCountry || '').toUpperCase();
        if (!detectedCountry) {
            try {
                const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                if (tz.includes('Calcutta') || tz.includes('Kolkata') || tz.includes('India')) {
                    detectedCountry = 'IN';
                }
            } catch (_) {}
        }

        if (currencyParam === 'USD' || currencyParam === 'INR') {
            state.currency = currencyParam;
        } else if (detectedCountry === 'IN') {
            state.currency = 'INR';
        } else if (detectedCountry && detectedCountry !== 'IN') {
            state.currency = 'USD';
        } else {
            state.currency = 'INR'; // Default fallback
        }

        state.gateway = (state.currency === 'USD') ? 'paypal' : 'razorpay';

        // Update signup card pill badge if preselected plan exists (Scenario 1)
        updateSignupPlanBadgeUI();
    }

    /**
     * Update the pill badge on Signup Card (Scenario 1)
     */
    function updateSignupPlanBadgeUI() {
        const badgeEl = document.getElementById('signupSelectedPlanBadge');
        const nameEl = document.getElementById('signupSelectedPlanName');
        const priceEl = document.getElementById('signupSelectedPlanPrice');
        const cycleEl = document.getElementById('signupSelectedPlanCycle');

        if (!badgeEl) return;

        if (state.hasPreselectedPlan && PLAN_PRICING[state.plan]) {
            const planData = PLAN_PRICING[state.plan];
            const priceInfo = (state.currency === 'USD') ? planData.usd : planData.inr;
            const priceDisplay = (state.cycle === 'yearly') ? priceInfo.formattedYearly : priceInfo.formattedMonthly;

            if (nameEl) nameEl.textContent = `${planData.name} Plan`;
            if (priceEl) priceEl.textContent = priceDisplay;
            if (cycleEl) cycleEl.textContent = (state.cycle === 'yearly') ? 'Billed annually (~17% savings)' : 'Billed monthly';
            badgeEl.style.display = 'flex';
        } else {
            badgeEl.style.display = 'none';
        }
    }

    /**
     * Switch visible card inside #authContainer
     * Available modes: 'login', 'signup', 'select-plan', 'checkout', 'status-success', 'status-failed'
     */
    function switchAuthView(mode) {
        const cards = {
            login: document.getElementById('loginCard'),
            signup: document.getElementById('signupCard'),
            'select-plan': document.getElementById('planSelectCard'),
            checkout: document.getElementById('checkoutGatewayCard'),
            status: document.getElementById('paymentStatusCard')
        };

        // Hide all cards first
        Object.values(cards).forEach(card => {
            if (card) card.style.display = 'none';
        });

        // Clear active error alerts
        ['loginError', 'signupError', 'checkoutGatewayError'].forEach(errId => {
            const el = document.getElementById(errId);
            if (el) el.style.display = 'none';
        });

        document.documentElement.classList.remove('auth-mode-signup', 'has-auth-token');

        if (mode === 'login') {
            if (cards.login) cards.login.style.display = 'block';
            history.replaceState(null, '', '#login');
        } else if (mode === 'signup') {
            document.documentElement.classList.add('auth-mode-signup');
            if (cards.signup) cards.signup.style.display = 'block';
            updateSignupPlanBadgeUI();
            history.replaceState(null, '', '#signup');
        } else if (mode === 'select-plan') {
            if (cards['select-plan']) {
                cards['select-plan'].style.display = 'block';
                renderPlanCardsUI();
            }
            history.replaceState(null, '', '#select-plan');
        } else if (mode === 'checkout') {
            if (cards.checkout) {
                cards.checkout.style.display = 'block';
                renderCheckoutSummaryUI();
            }
            history.replaceState(null, '', '#checkout');
        } else if (mode === 'status-success') {
            if (cards.status) {
                cards.status.style.display = 'block';
                const successView = document.getElementById('statusSuccessView');
                const failedView = document.getElementById('statusFailedView');
                if (successView) successView.style.display = 'block';
                if (failedView) failedView.style.display = 'none';
            }
            history.replaceState(null, '', '#payment-success');
        } else if (mode === 'status-failed') {
            if (cards.status) {
                cards.status.style.display = 'block';
                const successView = document.getElementById('statusSuccessView');
                const failedView = document.getElementById('statusFailedView');
                if (successView) successView.style.display = 'none';
                if (failedView) failedView.style.display = 'block';
            }
            history.replaceState(null, '', '#payment-failed');
        }
    }
    window.switchAuthView = switchAuthView;

    /**
     * Render Plan Selection Cards (Scenario 2 Step 2)
     */
    function renderPlanCardsUI() {
        const starterPriceEl = document.getElementById('planSelStarterPrice');
        const starterSubEl = document.getElementById('planSelStarterSub');
        const growthPriceEl = document.getElementById('planSelGrowthPrice');
        const growthSubEl = document.getElementById('planSelGrowthSub');

        const isUsd = state.currency === 'USD';
        const isYearly = state.cycle === 'yearly';

        if (starterPriceEl && starterSubEl) {
            starterPriceEl.textContent = isUsd 
                ? (isYearly ? '$2,490' : '$299') 
                : (isYearly ? '₹29,990' : '₹2,999');
            starterSubEl.textContent = isYearly ? '/year (save 17%)' : '/month';
        }

        if (growthPriceEl && growthSubEl) {
            growthPriceEl.textContent = isUsd 
                ? (isYearly ? '$5,990' : '$699') 
                : (isYearly ? '₹69,990' : '₹6,999');
            growthSubEl.textContent = isYearly ? '/year (save 17%)' : '/month';
        }

        // Cycle switcher button states
        const btnCycleMonthly = document.getElementById('planSelBtnMonthly');
        const btnCycleYearly = document.getElementById('planSelBtnYearly');
        if (btnCycleMonthly && btnCycleYearly) {
            btnCycleMonthly.classList.toggle('active', !isYearly);
            btnCycleYearly.classList.toggle('active', isYearly);
        }

        // Currency switcher button states
        const btnCurrInr = document.getElementById('planSelBtnInr');
        const btnCurrUsd = document.getElementById('planSelBtnUsd');
        if (btnCurrInr && btnCurrUsd) {
            btnCurrInr.classList.toggle('active', !isUsd);
            btnCurrUsd.classList.toggle('active', isUsd);
        }
    }

    /**
     * Render Order Summary on Checkout Screen (Step 3)
     */
    async function renderCheckoutSummaryUI() {
        const orgNameEl = document.getElementById('chkSummaryOrgName');
        const planNameEl = document.getElementById('chkSummaryPlanName');
        const billingEl = document.getElementById('chkSummaryBilling');
        const amountEl = document.getElementById('chkSummaryAmount');
        const gatewayTitleEl = document.getElementById('chkGatewayTitle');
        const razorpayBtn = document.getElementById('chkBtnRazorpay');
        const paypalContainer = document.getElementById('chkPaypalContainer');
        const currencyToggleLink = document.getElementById('chkCurrencyToggleLink');

        if (orgNameEl) orgNameEl.textContent = state.orgName || 'Your College Account';
        if (planNameEl) planNameEl.textContent = `${state.plan} Plan`;
        if (billingEl) billingEl.textContent = (state.cycle === 'yearly') ? 'Annual Subscription (1 Year)' : 'Monthly Subscription';

        const planData = PLAN_PRICING[state.plan] || PLAN_PRICING.Starter;
        const isUsd = state.currency === 'USD';
        const priceInfo = isUsd ? planData.usd : planData.inr;
        const totalFormatted = (state.cycle === 'yearly') ? priceInfo.formattedYearly : priceInfo.formattedMonthly;

        if (amountEl) amountEl.textContent = totalFormatted;

        if (isUsd) {
            if (gatewayTitleEl) gatewayTitleEl.textContent = 'Pay with PayPal or International Card';
            if (razorpayBtn) razorpayBtn.style.display = 'none';
            if (paypalContainer) paypalContainer.style.display = 'block';
            if (currencyToggleLink) currencyToggleLink.textContent = 'Switch to INR (Razorpay)';
            initPayPalButton();
        } else {
            if (gatewayTitleEl) gatewayTitleEl.textContent = 'Pay with Razorpay (UPI, Cards, NetBanking)';
            if (razorpayBtn) {
                razorpayBtn.style.display = 'flex';
                razorpayBtn.innerHTML = `<span>🔒</span> <span>Pay ${totalFormatted} via Razorpay</span>`;
            }
            if (paypalContainer) paypalContainer.style.display = 'none';
            if (currencyToggleLink) currencyToggleLink.textContent = 'Switch to USD (PayPal)';
        }
    }

    /**
     * Request Order Creation from Backend API
     */
    async function requestCreateOrder() {
        const token = localStorage.getItem('edvora_token');
        if (!token) {
            throw new Error('Authentication session missing. Please register or sign in.');
        }

        const res = await fetch('/v1/billing/create-order', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
                plan: state.plan,
                plan_id: state.planId,
                billing_cycle: state.cycle,
                currency: state.currency,
                gateway: state.gateway
            })
        });

        const data = await res.json();
        if (data.status !== 'success' || !data.data) {
            throw new Error(data.message || 'Unable to create commercial transaction order.');
        }

        state.activeOrder = data.data;
        return data.data;
    }

    /**
     * Launch Razorpay Checkout Gateway Modal
     */
    async function launchRazorpayPayment() {
        const btn = document.getElementById('chkBtnRazorpay');
        const origText = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="brand-spinner" style="width:14px;height:14px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:smartPulse 0.8s infinite;"></span> Initializing Secure Checkout...';
        }

        try {
            const order = await requestCreateOrder();

            if (typeof window.Razorpay === 'undefined') {
                throw new Error('Razorpay SDK is not loaded. Please refresh the page.');
            }

            const options = {
                key: order.razorpay_key_id,
                amount: order.amount_subunits,
                currency: 'INR',
                name: 'EdvoraChat',
                description: `${order.plan_name} Plan (${order.billing_cycle})`,
                image: '/edvora_logo.svg',
                order_id: order.razorpay_order_id,
                prefill: {
                    name: state.userName || '',
                    email: state.userEmail || ''
                },
                theme: {
                    color: '#063D3B'
                },
                modal: {
                    ondismiss: function () {
                        showPaymentStatus(false, 'Transaction was cancelled before completion. Please try again to activate your account.');
                    }
                },
                handler: async function (response) {
                    await handlePaymentVerification({
                        gateway: 'razorpay',
                        razorpay_payment_id: response.razorpay_payment_id,
                        razorpay_order_id: response.razorpay_order_id || order.order_id,
                        razorpay_signature: response.razorpay_signature,
                        plan_id: order.plan_id,
                        billing_cycle: order.billing_cycle,
                        currency: 'INR'
                    });
                }
            };

            const rzp = new window.Razorpay(options);
            rzp.on('payment.failed', function (errResponse) {
                const desc = errResponse?.error?.description || 'Payment failed. Please try a different card or UPI ID.';
                showPaymentStatus(false, desc);
            });
            rzp.open();

        } catch (err) {
            console.error('Razorpay Error:', err);
            showPaymentStatus(false, err.message || 'Unable to connect to Razorpay. Please retry.');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        }
    }

    /**
     * Load PayPal JavaScript SDK Dynamically and Render Buttons
     */
    let paypalLoaded = false;
    async function initPayPalButton() {
        const container = document.getElementById('chkPaypalBtnWrapper');
        if (!container) return;
        container.innerHTML = '<div style="font-size:12px;color:#64748b;text-align:center;padding:12px 0;"><span class="brand-spinner" style="width:12px;height:12px;display:inline-block;border:2px solid #063D3B;border-top-color:transparent;border-radius:50%;animation:smartPulse 0.8s infinite;margin-right:6px;"></span> Loading PayPal Secure Checkout...</div>';

        try {
            const order = await requestCreateOrder();
            const clientId = order.paypal_client_id || 'sb';

            if (!window.paypal) {
                await new Promise((resolve, reject) => {
                    const existing = document.getElementById('paypal-sdk-script');
                    if (existing) existing.remove();
                    const script = document.createElement('script');
                    script.id = 'paypal-sdk-script';
                    script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=USD&intent=capture`;
                    script.onload = () => resolve();
                    script.onerror = () => reject(new Error('Failed to load PayPal SDK'));
                    document.head.appendChild(script);
                });
            }

            container.innerHTML = '';
            window.paypal.Buttons({
                style: {
                    layout: 'vertical',
                    color: 'gold',
                    shape: 'rect',
                    label: 'pay'
                },
                createOrder: function (data, actions) {
                    return actions.order.create({
                        purchase_units: [{
                            description: `${order.plan_name} Plan (${order.billing_cycle}) - edvora.chat`,
                            amount: {
                                currency_code: 'USD',
                                value: order.amount_units.toString()
                            }
                        }]
                    });
                },
                onApprove: async function (data, actions) {
                    try {
                        const details = await actions.order.capture();
                        await handlePaymentVerification({
                            gateway: 'paypal',
                            paypal_order_id: data.orderID,
                            paypal_capture_id: details.id,
                            plan_id: order.plan_id,
                            billing_cycle: order.billing_cycle,
                            currency: 'USD'
                        });
                    } catch (captureErr) {
                        console.error('PayPal Capture Error:', captureErr);
                        showPaymentStatus(false, 'PayPal capture failed. Please try again.');
                    }
                },
                onCancel: function () {
                    showPaymentStatus(false, 'PayPal checkout cancelled. Please retry to activate your console.');
                },
                onError: function (err) {
                    console.error('PayPal Buttons Error:', err);
                    showPaymentStatus(false, 'PayPal encountered an error. Please retry or choose another payment method.');
                }
            }).render(container);

        } catch (err) {
            console.error('PayPal Init Error:', err);
            container.innerHTML = `
                <div style="background:#FEF2F2;border:1px solid #FCA5A5;border-radius:8px;padding:12px;font-size:12px;color:#991B1B;text-align:center;margin-bottom:10px;">
                    ${err.message || 'PayPal is currently unavailable.'}
                </div>
                <button type="button" class="brand-btn-secondary" style="width:100%;height:38px;font-size:12px;" onclick="window.edvoraCheckout.switchCurrency('INR')">
                    Pay in INR via Razorpay (UPI / Cards)
                </button>
            `;
        }
    }

    /**
     * Verify payment on Backend, update subscription status, and show Status Page
     */
    async function handlePaymentVerification(verifyPayload) {
        const token = localStorage.getItem('edvora_token');
        try {
            const res = await fetch('/v1/billing/verify-payment', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                body: JSON.stringify(verifyPayload)
            });

            const data = await res.json();
            if (data.status === 'success') {
                showPaymentStatus(true, '', data.data);
            } else {
                showPaymentStatus(false, data.message || 'Payment verification failed.');
            }
        } catch (err) {
            console.error('Verification Network Error:', err);
            showPaymentStatus(false, 'Network error while verifying payment. Please contact support.');
        }
    }

    /**
     * Display Status Page (Success or Failed)
     */
    function showPaymentStatus(isSuccess, failureMessage = '', successData = {}) {
        if (isSuccess) {
            const planEl = document.getElementById('statusSuccessPlan');
            const txEl = document.getElementById('statusSuccessTxId');
            const amtEl = document.getElementById('statusSuccessAmount');
            const emailEl = document.getElementById('statusSuccessEmail');

            if (planEl) planEl.textContent = `${successData.plan_name || state.plan} Plan (${successData.billing_cycle || state.cycle})`;
            if (txEl) txEl.textContent = successData.transaction_id || 'EDV-' + Math.random().toString(36).substr(2, 9).toUpperCase();
            if (amtEl) amtEl.textContent = successData.amount_formatted || '';
            if (emailEl) emailEl.textContent = successData.owner_email || state.userEmail || 'your official email';

            switchAuthView('status-success');
        } else {
            const reasonEl = document.getElementById('statusFailedReasonText');
            if (reasonEl) reasonEl.textContent = failureMessage || 'The transaction could not be completed. No charges were incurred.';
            state.lastFailureReason = failureMessage;
            switchAuthView('status-failed');
        }
    }

    /**
     * Public Checkout API for inline HTML listeners
     */
    window.edvoraCheckout = {
        init: initCheckoutParameters,
        switchView: switchAuthView,
        selectPlan: function (planName) {
            if (PLAN_PRICING[planName]) {
                state.plan = planName;
                state.planId = PLAN_PRICING[planName].id;
                switchAuthView('checkout');
            }
        },
        setBillingCycle: function (cycle) {
            state.cycle = (cycle === 'yearly') ? 'yearly' : 'monthly';
            renderPlanCardsUI();
        },
        switchCurrency: function (curr) {
            state.currency = (curr === 'USD') ? 'USD' : 'INR';
            state.gateway = (state.currency === 'USD') ? 'paypal' : 'razorpay';
            renderPlanCardsUI();
            if (document.getElementById('checkoutGatewayCard')?.style.display === 'block') {
                renderCheckoutSummaryUI();
            }
        },
        toggleCurrencyCheckout: function () {
            this.switchCurrency(state.currency === 'USD' ? 'INR' : 'USD');
        },
        payWithRazorpay: launchRazorpayPayment,
        retryPayment: function () {
            switchAuthView('checkout');
        },
        simulateTestPaymentSuccess: async function () {
            // Safe developer testing helper
            await handlePaymentVerification({
                gateway: state.gateway,
                razorpay_payment_id: 'test_pay_' + bin2hex(8),
                razorpay_order_id: 'test_order_' + bin2hex(8),
                razorpay_signature: 'test_valid_sig',
                plan_id: state.planId,
                billing_cycle: state.cycle,
                currency: state.currency
            });
        }
    };

    function bin2hex(len) {
        let str = '';
        for (let i = 0; i < len; i++) {
            str += Math.floor(Math.random() * 16).toString(16);
        }
        return str;
    }

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCheckoutParameters);
    } else {
        initCheckoutParameters();
    }

})();
