<!-- EDVORA GLOBAL COOKIE CONSENT BANNER & MODAL (US, UK, CANADA & GLOBAL) -->
<style>
  #edvora-cookie-banner {
    position: fixed;
    bottom: 20px;
    left: 20px;
    right: 20px;
    max-width: 580px;
    background: rgba(6, 61, 59, 0.96);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1.5px solid rgba(200, 255, 99, 0.25);
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    padding: 22px;
    z-index: 99999;
    color: #ffffff;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    transform: translateY(120%);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  }
  #edvora-cookie-banner.show {
    transform: translateY(0);
  }
  .cookie-title {
    font-size: 15px;
    font-weight: 800;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
  }
  .cookie-desc {
    font-size: 12.5px;
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.8);
    margin-bottom: 16px;
  }
  .cookie-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .cookie-btn-primary {
    background: #C8FF63;
    color: #063D3B;
    border: none;
    border-radius: 9px;
    padding: 8px 16px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.2s ease;
  }
  .cookie-btn-primary:hover {
    background: #d4ff7d;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(200, 255, 99, 0.3);
  }
  .cookie-btn-secondary {
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 9px;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
  }
  .cookie-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.2);
  }
  .cookie-btn-text {
    background: transparent;
    color: rgba(255, 255, 255, 0.7);
    border: none;
    padding: 8px 10px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: underline;
    cursor: pointer;
    transition: color 0.2s;
  }
  .cookie-btn-text:hover {
    color: #C8FF63;
  }
  
  /* Floating Re-open Badge */
  #edvora-cookie-badge {
    position: fixed;
    bottom: 20px;
    left: 20px;
    z-index: 99998;
    background: #063D3B;
    border: 1px solid rgba(200, 255, 99, 0.3);
    color: #C8FF63;
    padding: 6px 12px;
    border-radius: 100px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    display: none;
    align-items: center;
    gap: 6px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    transition: all 0.2s;
  }
  #edvora-cookie-badge:hover {
    transform: scale(1.05);
    background: #092F2E;
  }

  /* Preferences Modal */
  #edvora-cookie-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(4, 40, 38, 0.75);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 100000;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  #edvora-cookie-modal.open {
    display: flex;
  }
  .cookie-modal-card {
    background: #ffffff;
    border-radius: 20px;
    width: 100%;
    max-width: 540px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3);
    border: 1.5px solid #DCE9E5;
  }
  .cookie-modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #E6F0EC;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .cookie-category-item {
    padding: 16px 24px;
    border-bottom: 1px solid #F0F6F3;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
  }
  .cookie-toggle-switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
    flex-shrink: 0;
  }
  .cookie-toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
  }
  .cookie-slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #cbd5e1;
    transition: .3s;
    border-radius: 24px;
  }
  .cookie-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
  }
  input:checked + .cookie-slider {
    background-color: #063D3B;
  }
  input:checked + .cookie-slider:before {
    transform: translateX(20px);
  }
  input:disabled + .cookie-slider {
    background-color: #063D3B;
    opacity: 0.6;
    cursor: not-allowed;
  }
</style>

<!-- Banner Markup -->
<div id="edvora-cookie-banner" role="dialog" aria-live="polite" aria-label="Cookie Consent Banner">
  <div class="cookie-title">
    <span>🍪</span> Global Privacy &amp; Cookie Consent
  </div>
  <p class="cookie-desc">
    Edvora and our higher-education institutional partners use essential cookies for secure authentication and performance telemetry. In accordance with US (CCPA), UK (UK GDPR), and Canadian (PIPEDA) regulations, we respect your privacy choices.
  </p>
  <div class="cookie-actions">
    <button class="cookie-btn-primary" onclick="EdvoraConsent.acceptAll()">Accept All Cookies</button>
    <button class="cookie-btn-secondary" onclick="EdvoraConsent.acceptEssential()">Essential Only</button>
    <button class="cookie-btn-text" onclick="EdvoraConsent.openModal()">Customize Preferences</button>
    <a href="/privacy" class="cookie-btn-text" style="text-decoration:none; margin-left:auto;">Privacy Policy &rarr;</a>
  </div>
</div>

<!-- Floating Trigger Badge -->
<div id="edvora-cookie-badge" onclick="EdvoraConsent.openModal()" title="Manage Cookie Preferences">
  <span>🛡️</span> <span>Cookie Preferences</span>
</div>

<!-- Preferences Modal Markup -->
<div id="edvora-cookie-modal" role="dialog" aria-modal="true" aria-labelledby="cookieModalTitle">
  <div class="cookie-modal-card">
    <div class="cookie-modal-header">
      <div>
        <h3 id="cookieModalTitle" style="font-size: 17px; font-weight: 800; color: #063D3B; margin: 0;">Institutional Privacy &amp; Cookie Preferences</h3>
        <p style="font-size: 12px; color: #648781; margin: 2px 0 0 0;">Manage your data collection preferences across our platform.</p>
      </div>
      <button onclick="EdvoraConsent.closeModal()" style="background:none; border:none; font-size:18px; cursor:pointer; color:#648781; padding:4px 8px;">✕</button>
    </div>

    <div style="padding: 6px 0;">
      <!-- Category 1: Essential -->
      <div class="cookie-category-item">
        <div>
          <div style="font-size: 13.5px; font-weight: 700; color: #063D3B; display:flex; align-items:center; gap:6px;">
            <span>🔒 Strictly Necessary</span>
            <span style="font-size: 10px; background: #E8F5F1; color: #047857; padding: 2px 6px; border-radius: 4px; font-weight: 800;">REQUIRED</span>
          </div>
          <p style="font-size: 12px; color: #648781; margin: 4px 0 0 0; line-height: 1.5;">
            Essential for account authentication, CSRF protection, secure chatbot streaming, and remembering your consent choice. Cannot be disabled.
          </p>
        </div>
        <label class="cookie-toggle-switch">
          <input type="checkbox" checked disabled>
          <span class="cookie-slider"></span>
        </label>
      </div>

      <!-- Category 2: Performance & Analytics -->
      <div class="cookie-category-item">
        <div>
          <div style="font-size: 13.5px; font-weight: 700; color: #063D3B;">📊 Performance &amp; Analytics</div>
          <p style="font-size: 12px; color: #648781; margin: 4px 0 0 0; line-height: 1.5;">
            Anonymously measures page dwell time, scroll depth, and admissions funnel progression to optimize platform performance.
          </p>
        </div>
        <label class="cookie-toggle-switch">
          <input type="checkbox" id="consent_analytics" checked>
          <span class="cookie-slider"></span>
        </label>
      </div>

      <!-- Category 3: Marketing Attribution -->
      <div class="cookie-category-item">
        <div>
          <div style="font-size: 13.5px; font-weight: 700; color: #063D3B;">🎯 Marketing Attribution</div>
          <p style="font-size: 12px; color: #648781; margin: 4px 0 0 0; line-height: 1.5;">
            Captures campaign UTM parameters to measure admissions marketing channel ROI without cross-site tracking or selling data.
          </p>
        </div>
        <label class="cookie-toggle-switch">
          <input type="checkbox" id="consent_marketing" checked>
          <span class="cookie-slider"></span>
        </label>
      </div>

      <!-- Category 4: Functional & Chatbot -->
      <div class="cookie-category-item">
        <div>
          <div style="font-size: 13.5px; font-weight: 700; color: #063D3B;">🤖 Functional &amp; Preferences</div>
          <p style="font-size: 12px; color: #648781; margin: 4px 0 0 0; line-height: 1.5;">
            Remembers widget language preferences, high-contrast themes, and audio notifications between visits.
          </p>
        </div>
        <label class="cookie-toggle-switch">
          <input type="checkbox" id="consent_functional" checked>
          <span class="cookie-slider"></span>
        </label>
      </div>
    </div>

    <div style="padding: 16px 24px; background: #F8FBFA; border-top: 1px solid #E6F0EC; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
      <a href="/privacy" style="font-size: 12px; color: #063D3B; text-decoration: underline; font-weight: 600;">Read Full Cookie Policy</a>
      <div style="display: flex; gap: 8px;">
        <button onclick="EdvoraConsent.acceptEssential()" class="cookie-btn-secondary" style="background:#ffffff; color:#063D3B; border-color:#D5E8DF;">Essential Only</button>
        <button onclick="EdvoraConsent.saveCustom()" class="cookie-btn-primary">Save Preferences</button>
      </div>
    </div>
  </div>
</div>

<!-- Consent Management Engine JS -->
<script>
window.EdvoraConsent = (function() {
  const STORAGE_KEY = 'edvora_cookie_consent_v1';
  const BANNER_EL = document.getElementById('edvora-cookie-banner');
  const BADGE_EL = document.getElementById('edvora-cookie-badge');
  const MODAL_EL = document.getElementById('edvora-cookie-modal');

  function getConsent() {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      return stored ? JSON.parse(stored) : null;
    } catch(e) {
      return null;
    }
  }

  function setConsent(data) {
    try {
      const consentObj = {
        version: 1,
        timestamp: new Date().toISOString(),
        necessary: true,
        analytics: !!data.analytics,
        marketing: !!data.marketing,
        functional: !!data.functional,
        status: data.status || 'custom'
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(consentObj));
      applyConsent(consentObj);
      hideBanner();
      closeModal();
      showBadge();
    } catch(e) {
      console.warn('Unable to persist cookie consent', e);
    }
  }

  function applyConsent(consent) {
    window.edvoraConsentSettings = consent;
    // Dispatch custom DOM event for analytics / tracking modules
    window.dispatchEvent(new CustomEvent('edvoraCookieConsentChanged', { detail: consent }));
  }

  let bannerShown = false;
  let scrollListenerAttached = false;
  let bottomObserver = null;

  function isMobileDevice() {
    return window.innerWidth <= 768 || (window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
  }

  function isNearBottom() {
    const scrollY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
    const windowHeight = window.innerHeight || document.documentElement.clientHeight || 0;
    const docHeight = Math.max(
      document.body.scrollHeight,
      document.documentElement.scrollHeight,
      document.body.offsetHeight,
      document.documentElement.offsetHeight,
      document.body.clientHeight,
      document.documentElement.clientHeight
    );
    // Trigger when user is within 300px of page bottom
    return (scrollY + windowHeight) >= (docHeight - 300);
  }

  function displayBannerNow() {
    if (bannerShown || !BANNER_EL) return;
    bannerShown = true;
    cleanupMobileTriggers();
    BANNER_EL.classList.add('show');
  }

  function cleanupMobileTriggers() {
    if (scrollListenerAttached) {
      window.removeEventListener('scroll', checkMobileScroll);
      scrollListenerAttached = false;
    }
    if (bottomObserver) {
      bottomObserver.disconnect();
      bottomObserver = null;
    }
    window.removeEventListener('resize', handleResize);
  }

  function checkMobileScroll() {
    if (isNearBottom()) {
      displayBannerNow();
    }
  }

  function handleResize() {
    if (bannerShown) return;
    if (!isMobileDevice()) {
      displayBannerNow();
    }
  }

  function setupMobileScrollTrigger() {
    if (isNearBottom()) {
      setTimeout(displayBannerNow, 600);
      return;
    }

    if (!scrollListenerAttached) {
      window.addEventListener('scroll', checkMobileScroll, { passive: true });
      scrollListenerAttached = true;
    }

    const footerEl = document.querySelector('footer');
    if ('IntersectionObserver' in window && footerEl) {
      bottomObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            displayBannerNow();
            break;
          }
        }
      }, { threshold: 0.05 });
      bottomObserver.observe(footerEl);
    }

    window.addEventListener('resize', handleResize, { passive: true });
  }

  function showBanner() {
    if (!BANNER_EL || bannerShown) return;

    if (isMobileDevice()) {
      // Mobile: show only when user scrolls to the bottom to avoid obscuring the hero section
      setupMobileScrollTrigger();
    } else {
      // Desktop / large screens: continue to behave as is (display after 600ms)
      setTimeout(displayBannerNow, 600);
    }
  }

  function hideBanner() {
    cleanupMobileTriggers();
    if (BANNER_EL) {
      BANNER_EL.classList.remove('show');
    }
  }

  function showBadge() {
    if (BADGE_EL) {
      BADGE_EL.style.display = 'inline-flex';
    }
  }

  function init() {
    const existing = getConsent();
    if (!existing) {
      showBanner();
    } else {
      applyConsent(existing);
      showBadge();
    }
  }

  // Self-init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return {
    acceptAll: function() {
      setConsent({ analytics: true, marketing: true, functional: true, status: 'all' });
    },
    acceptEssential: function() {
      setConsent({ analytics: false, marketing: false, functional: false, status: 'essential' });
    },
    saveCustom: function() {
      const analytics = document.getElementById('consent_analytics')?.checked || false;
      const marketing = document.getElementById('consent_marketing')?.checked || false;
      const functional = document.getElementById('consent_functional')?.checked || false;
      setConsent({ analytics, marketing, functional, status: 'custom' });
    },
    openModal: function() {
      const existing = getConsent() || { analytics: true, marketing: true, functional: true };
      const a = document.getElementById('consent_analytics');
      const m = document.getElementById('consent_marketing');
      const f = document.getElementById('consent_functional');
      if (a) a.checked = existing.analytics;
      if (m) m.checked = existing.marketing;
      if (f) f.checked = existing.functional;
      if (MODAL_EL) MODAL_EL.classList.add('open');
    },
    closeModal: function() {
      if (MODAL_EL) MODAL_EL.classList.remove('open');
    }
  };
})();
</script>
