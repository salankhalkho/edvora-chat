// ═══════════════════════════════════════════════════════════════════
// APP-CORE.JS - Global state, auth flow, token management, navigation, tab loading
// BUG AREAS:
//   Auth/login errors      -> handleSessionExpired() / showAuthError()
//   Token refresh          -> doRefreshToken() / processPendingRequests()
//   Navigation/tabs        -> switchNavTab()
//   Knowledge ingestion    -> submitIngestFile() / submitIngestUrl() / submitIngestText()
//   App identity/org name  -> initAppIdentity() / updateAppIdentityUI()
//   Mobile sidebar         -> toggleMobileSidebar()
//   Dashboard init         -> initDashboard() (boots entire app)
//   Toast notifications    -> showToast()
// LOADED BY: index.html via <script src="js/app-core.js">
// ═══════════════════════════════════════════════════════════════════
        let token = localStorage.getItem('edvora_token');
        let currentUser = null;
        let currentWidgetBot = {
            id: null,
            botToken: '',
            widget_style: 'glassmorphism',
            theme_mode: 'light',
            primary_color: '#063D3B',
            secondary_color: '#059669',
            name: 'AI Admissions Assistant',
            header_subtitle: 'Online Now',
            avatar_icon: 'ðŸ¤–',
            launcher_icon: 'modern_chat',
            launcher_text: 'Ask AI',
            border_radius: 'curved',
            welcome_message: 'Hi there! ðŸ‘‹ Welcome to our admissions assistant. How can I assist you with admissions, programs, or campus life today?',
            quick_chips: []
        };
        let testChatState = {
            scope: 'org',
            deptId: null,
            departments: [],
            loaded: false
        };

        // Lightweight Toast Notification Utility
        function showToast(message, type = 'info') {
            let container = document.getElementById('brandToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'brandToastContainer';
                container.style.cssText = 'position: fixed; bottom: 44px; right: 24px; z-index: 100000; display: flex; flex-direction: column; gap: 8px; pointer-events: none;';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            const bg = type === 'success' ? 'rgba(6, 78, 59, 0.95)' : (type === 'error' ? 'rgba(153, 27, 27, 0.95)' : 'rgba(30, 27, 75, 0.95)');
            const border = type === 'success' ? 'rgba(52, 211, 153, 0.4)' : (type === 'error' ? 'rgba(244, 63, 94, 0.4)' : 'rgba(129, 140, 248, 0.4)');
            const color = type === 'success' ? '#a7f3d0' : (type === 'error' ? '#fecdd3' : '#e0e7ff');
            const icon = type === 'success' ? 'âœ“' : (type === 'error' ? 'âš ï¸' : 'â„¹ï¸');

            toast.style.cssText = `background: ${bg}; border: 1px solid ${border}; color: ${color}; padding: 10px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5); backdrop-filter: blur(8px); display: flex; align-items: center; gap: 8px; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); transform: translateY(12px); opacity: 0; pointer-events: auto;`;
            toast.innerHTML = `<span>${icon}</span><span>${message}</span>`;
            container.appendChild(toast);

            requestAnimationFrame(() => {
                toast.style.transform = 'translateY(0)';
                toast.style.opacity = '1';
            });

            setTimeout(() => {
                toast.style.transform = 'translateY(12px)';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function escapeJsString(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
        }

        // Global Fetch Interceptor for Token Management & 401 Handling
        const _nativeFetch = window.fetch;
        let isRefreshingToken = false;
        let pendingRequests = [];

        function processPendingRequests(newToken) {
            pendingRequests.forEach(resolve => resolve(newToken));
            pendingRequests = [];
        }

        async function doRefreshToken() {
            const rToken = localStorage.getItem('edvora_refresh_token');
            if (!rToken) return null;

            try {
                const res = await _nativeFetch('/v1/auth/refresh', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ refresh_token: rToken })
                });
                const data = await res.json();
                if (data.status === 'success' && data.data && data.data.access_token) {
                    token = data.data.access_token;
                    localStorage.setItem('edvora_token', token);
                    return token;
                }
            } catch (err) {
                console.error('Token refresh request failed:', err);
            }
            return null;
        }

        function handleSessionExpired(customMsg = 'Your session has expired. Please log in again.') {
            localStorage.removeItem('edvora_token');
            localStorage.removeItem('edvora_refresh_token');
            document.documentElement.classList.remove('has-auth-token');
            token = null;
            currentUser = null;
            const appEl = document.getElementById('appContainer');
            const authEl = document.getElementById('authContainer');
            if (appEl) appEl.style.display = 'none';
            if (authEl) authEl.style.display = 'flex';
            if (typeof setAuthCard === 'function') setAuthCard('login');
            showToast(customMsg, 'error');
        }

        window.fetch = async function(resource, init = {}) {
            const url = typeof resource === 'string' ? resource : (resource ? resource.url : '');
            
            // Auto-attach Bearer token for /v1/ API requests if not already explicitly provided on protected routes
            const isAuthRoute = url.includes('/v1/auth/login') || url.includes('/v1/auth/signup') || url.includes('/v1/auth/refresh') || url.includes('/v1/auth/forgot-password');
            
            if (url.startsWith('/v1/') && !isAuthRoute && token) {
                init.headers = init.headers || {};
                if (init.headers instanceof Headers) {
                    if (!init.headers.has('Authorization')) {
                        init.headers.set('Authorization', 'Bearer ' + token);
                    }
                } else if (Array.isArray(init.headers)) {
                    const hasAuth = init.headers.some(h => h[0].toLowerCase() === 'authorization');
                    if (!hasAuth) {
                        init.headers.push(['Authorization', 'Bearer ' + token]);
                    }
                } else {
                    if (!init.headers['Authorization'] && !init.headers['authorization']) {
                        init.headers['Authorization'] = 'Bearer ' + token;
                    }
                }
            }

            let response;
            try {
                response = await _nativeFetch(resource, init);
            } catch (networkErr) {
                throw networkErr;
            }

            // Handle 401 Unauthorized / Token Expired on protected /v1/ routes
            if (response.status === 401 && !isAuthRoute) {
                if (!isRefreshingToken) {
                    isRefreshingToken = true;
                    const newToken = await doRefreshToken();
                    isRefreshingToken = false;

                    if (newToken) {
                        processPendingRequests(newToken);
                        // Retry current failed request with new token
                        if (init.headers instanceof Headers) {
                            init.headers.set('Authorization', 'Bearer ' + newToken);
                        } else if (Array.isArray(init.headers)) {
                            const idx = init.headers.findIndex(h => h[0].toLowerCase() === 'authorization');
                            if (idx >= 0) init.headers[idx][1] = 'Bearer ' + newToken;
                            else init.headers.push(['Authorization', 'Bearer ' + newToken]);
                        } else if (typeof init.headers === 'object') {
                            init.headers['Authorization'] = 'Bearer ' + newToken;
                        }
                        return await _nativeFetch(resource, init);
                    } else {
                        processPendingRequests(null);
                        handleSessionExpired();
                        return response;
                    }
                } else {
                    // Another request is already refreshing the token, wait for it
                    return new Promise((resolve) => {
                        pendingRequests.push(async (newToken) => {
                            if (newToken) {
                                if (init.headers instanceof Headers) {
                                    init.headers.set('Authorization', 'Bearer ' + newToken);
                                } else if (Array.isArray(init.headers)) {
                                    const idx = init.headers.findIndex(h => h[0].toLowerCase() === 'authorization');
                                    if (idx >= 0) init.headers[idx][1] = 'Bearer ' + newToken;
                                    else init.headers.push(['Authorization', 'Bearer ' + newToken]);
                                } else if (typeof init.headers === 'object') {
                                    init.headers['Authorization'] = 'Bearer ' + newToken;
                                }
                                resolve(await _nativeFetch(resource, init));
                            } else {
                                resolve(response);
                            }
                        });
                    });
                }
            }

            return response;
        };

        // In-page Auth Error Handlers
        function showAuthError(targetId, message) {
            const banner = document.getElementById(targetId);
            const textSpan = document.getElementById(targetId + 'Text');
            if (banner && textSpan) {
                textSpan.textContent = message;
                banner.style.display = 'flex';
            }
        }

        function clearAuthError(targetId) {
            const banner = document.getElementById(targetId);
            if (banner) {
                banner.style.display = 'none';
            }
        }

        ['loginEmail', 'loginPassword'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', () => clearAuthError('loginError'));
        });
        ['signupName', 'signupEmail', 'signupPassword', 'signupCollege'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', () => clearAuthError('signupError'));
        });

        // Auth Modal Card State Controller
        function setAuthCard(mode) {
            clearAuthError('loginError');
            clearAuthError('signupError');
            const loginCard = document.getElementById('loginCard');
            const signupCard = document.getElementById('signupCard');
            if (mode === 'signup' || mode === 'register') {
                document.documentElement.classList.add('auth-mode-signup');
                if (loginCard) loginCard.style.display = 'none';
                if (signupCard) signupCard.style.display = 'block';
            } else {
                document.documentElement.classList.remove('auth-mode-signup');
                if (signupCard) signupCard.style.display = 'none';
                if (loginCard) loginCard.style.display = 'block';
            }
        }

        function syncAuthFromUrl() {
            const hash = (window.location.hash || '').toLowerCase();
            const path = (window.location.pathname || '').toLowerCase();
            const search = window.location.search || '';
            const params = new URLSearchParams(search);
            const mode = params.get('mode');
            if (hash === '#signup' || hash === '#register' || path.endsWith('/signup') || path.endsWith('/register') || mode === 'signup' || mode === 'register') {
                setAuthCard('signup');
            } else {
                setAuthCard('login');
            }
        }

        // Auth Modal Toggle Listeners
        function bindAuthToggleListeners() {
            const showSignupEl = document.getElementById('showSignup');
            if (showSignupEl && !showSignupEl._bound) {
                showSignupEl._bound = true;
                showSignupEl.onclick = (e) => {
                    e.preventDefault();
                    if (window.location.hash !== '#signup') {
                        history.replaceState(null, '', '#signup');
                    }
                    setAuthCard('signup');
                };
            }
            
            const showLoginEl = document.getElementById('showLogin');
            if (showLoginEl && !showLoginEl._bound) {
                showLoginEl._bound = true;
                showLoginEl.onclick = (e) => {
                    e.preventDefault();
                    if (window.location.hash !== '#login' && window.location.hash !== '') {
                        history.replaceState(null, '', '#login');
                    }
                    setAuthCard('login');
                };
            }
        }
        bindAuthToggleListeners();
        document.addEventListener('DOMContentLoaded', bindAuthToggleListeners);
        document.addEventListener('edvora:partials-ready', bindAuthToggleListeners);

        const VALID_APP_TABS = ['overview', 'knowledge', 'knowledge-ingestion', 'academic-programs', 'academic-program-detail', 'course-staff-assignment', 'program-staff', 'program-lead-magnet', 'add-programs', 'edit-programs', 'departments', 'leads', 'callbacks', 'campus-tours', 'campus-tours-scheduling', 'create-tour-slot', 'edit-tour-slot', 'scholarships', 'scholarship-configuration', 'scholarship-configuration-edit', 'teams', 'assets', 'multilingual', 'conversion-engine', 'placeholder', 'analytics', 'knowledge-gaps', 'integrations', 'chatbot', 'test-chat', 'organization', 'org-settings', 'campuses', 'campus-editor', 'settings', 'knowledge-view', 'knowledge-editor', 'knowledge-edit', 'profile'];

        // Navigation Tabs Handling with Async Modular Loading
        const _tabLoadPromises = {};
        async function loadTabContent(tab) {
            if (!tab) tab = 'overview';
            const pane = document.getElementById('tab-' + tab);
            if (!pane) return;
            if (pane.dataset.loaded === 'true') return;
            if (_tabLoadPromises[tab]) {
                return await _tabLoadPromises[tab];
            }
            const loadPromise = (async () => {
                try {
                    const res = await fetch('/app/tabs/' + tab + '.html?v=' + Date.now());
                    if (res.ok) {
                        pane.innerHTML = await res.text();
                        pane.dataset.loaded = 'true';
                        // Re-execute scripts inside loaded tab
                        pane.querySelectorAll('script').forEach(oldScript => {
                            const newScript = document.createElement('script');
                            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                            oldScript.parentNode.replaceChild(newScript, oldScript);
                        });
                    } else {
                        console.warn('[Edvora] Tab failed to load:', tab, res.status);
                    }
                } catch(e) {
                    console.warn('[Edvora] Tab load error:', tab, e);
                } finally {
                    delete _tabLoadPromises[tab];
                }
            })();
            _tabLoadPromises[tab] = loadPromise;
            return await loadPromise;
        }

        window.addEventListener('hashchange', () => {
            if (!token) {
                syncAuthFromUrl();
            } else {
                const rawHash = location.hash.replace('#', '').trim();
                const target = rawHash.split('?')[0];
                if (target && VALID_APP_TABS.includes(target)) {
                    switchNavTab(target, null, false);
                }
            }
        });

        // Initialize auth card state on initial execution
        syncAuthFromUrl();

        // Dynamic App Identity Hydration & Missing College Name Alert Handler
        function updateAppIdentityUI(org, user) {
            user = user || window.currentUser || {};
            org = org || window.currentOrgProfile || {};
            const rawName = (org && org.name ? org.name : (localStorage.getItem('edvora_org_name') || '')).trim();
            const topbarEl = document.getElementById('topbarOrgName');
            const bannerEl = document.getElementById('missingOrgNameBanner');
            const bannerText = document.getElementById('missingOrgBannerText');
            const btnBanner = document.getElementById('btnBannerFixOrgName');

            const role = (user && user.role) ? user.role : '';
            const isOwnerOrAdmin = role === 'owner' || role === 'org_admin' || role === 'admin' || role === 'superadmin' || role === 'super_admin';

            if (rawName && rawName.toLowerCase() !== 'unnamed institution' && rawName.toLowerCase() !== 'widget preview' && rawName.toLowerCase() !== 'landing page') {
                window.currentOrgName = rawName;
                localStorage.setItem('edvora_org_name', rawName);
                if (topbarEl) {
                    topbarEl.textContent = rawName;
                    topbarEl.title = rawName;
                    topbarEl.style.cursor = 'default';
                    topbarEl.onclick = null;
                }
                if (bannerEl) bannerEl.style.display = 'none';
            } else {
                window.currentOrgName = '';
                if (topbarEl) {
                    if (isOwnerOrAdmin) {
                        topbarEl.innerHTML = '<span style="display:inline-flex; align-items:center; gap:5px; background:rgba(239, 68, 68, 0.12); color:#DC2626; border:1px solid rgba(239, 68, 68, 0.35); padding:2px 10px; border-radius:999px; font-size:11.5px; font-weight:700; cursor:pointer;" title="Click to set official College / University Name">âš ï¸ College Name Not Set &bull; Click to Configure</span>';
                        topbarEl.style.cursor = 'pointer';
                        topbarEl.onclick = () => {
                            switchNavTab('settings');
                            setTimeout(() => {
                                const inp = document.getElementById('settings_name');
                                if (inp) { inp.scrollIntoView({ behavior: 'smooth', block: 'center' }); inp.focus(); inp.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.3)'; }
                            }, 350);
                        };
                    } else {
                        topbarEl.innerHTML = '<span style="color:#DC2626; font-weight:700;" title="Institution name not configured. Contact your administrator.">âš ï¸ Unnamed Institution</span>';
                        topbarEl.style.cursor = 'default';
                        topbarEl.onclick = null;
                    }
                }

                if (bannerEl && isOwnerOrAdmin) {
                    bannerEl.style.display = 'block';
                    if (btnBanner) {
                        btnBanner.onclick = () => {
                            switchNavTab('settings');
                            setTimeout(() => {
                                const inp = document.getElementById('settings_name');
                                if (inp) { inp.scrollIntoView({ behavior: 'smooth', block: 'center' }); inp.focus(); inp.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.3)'; }
                            }, 350);
                        };
                    }
                } else if (bannerEl) {
                    bannerEl.style.display = 'none';
                }
            }

            if (org && org.website_url) {
                window.currentOrgWebsite = org.website_url;
                localStorage.setItem('edvora_org_website', org.website_url);
            }

            // Hydrate Plan Badge & Plan Quotas in Header and UI
            const planBadge = document.getElementById('planBadge');
            const planName = (org && org.plan_name ? org.plan_name : (user.plan_name || 'Starter')).trim();
            if (planBadge) {
                planBadge.textContent = planName + ' Plan';
            }

            // Hydrate Ingestion File Upload Limit
            const maxMb = (org && org.quotas && org.quotas.max_file_upload_mb) ? org.quotas.max_file_upload_mb : (planName === 'Pro' ? 50 : (planName === 'Growth' ? 25 : 15));
            window.currentOrgMaxUploadMb = maxMb;
            const dropLimitEl = document.getElementById('ingestMaxFileLimitText');
            if (dropLimitEl) {
                dropLimitEl.textContent = maxMb + ' MB';
            }

            const ovName = document.getElementById('overviewCollegeName');
            if (ovName) {
                ovName.innerText = rawName || 'Your Institution';
            }
            const userCol = document.getElementById('userCollege');
            if (userCol) {
                userCol.innerText = rawName || 'Your Institution';
            }
            if (typeof WC_DEFAULTS !== 'undefined' && rawName) {
                WC_DEFAULTS.header_bot_name = rawName;
            }
        }
        window.updateAppIdentityUI = updateAppIdentityUI;

        // Immediate optimistic hydration from cached institution name & website
        try {
            const cachedOrgName = localStorage.getItem('edvora_org_name');
            const cachedOrgWebsite = localStorage.getItem('edvora_org_website');
            if (cachedOrgWebsite) {
                window.currentOrgWebsite = cachedOrgWebsite;
            }
            if (cachedOrgName) {
                updateAppIdentityUI({ name: cachedOrgName, website_url: cachedOrgWebsite || null });
            }
        } catch(_) {}

        async function initAppIdentity() {
            if (!token) {
                const rToken = localStorage.getItem('edvora_refresh_token');
                if (rToken) {
                    await doRefreshToken();
                }
            }
            if (!token) return;
            try {
                const res = await fetch('/v1/auth/me');
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    window.currentUser = data.data.user || {};
                    window.currentOrgProfile = data.data.organization || {};
                    updateAppIdentityUI(window.currentOrgProfile, window.currentUser);
                }
            } catch (err) {
                console.warn('[Edvora] Failed to initialize app identity:', err);
            }
        }
        window.initAppIdentity = initAppIdentity;

        // Immediate Pre-Auth Tab & Hash Resolution (Prevents Overview Flash and Blank Screen on Refresh)
        (function() {
            if (token) {
                initAppIdentity();
                const rawH = location.hash.replace('#', '').trim();
                const h = rawH.split('?')[0];
                const initialTab = (h && VALID_APP_TABS.includes(h)) ? h : (localStorage.getItem('edvora_active_tab') || 'overview');
                const safeTab = VALID_APP_TABS.includes(initialTab) ? initialTab : 'overview';
                
                // Immediately highlight active tab & hide others
                document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                
                const targetPane = document.getElementById('tab-' + safeTab);
                if (targetPane) targetPane.classList.add('active');
                const targetNav = document.querySelector(`.nav-item[data-tab="${safeTab}"]`);
                if (targetNav) targetNav.classList.add('active');
                
                loadTabContent(safeTab);
            }
        })();

        
        function toggleMobileSidebar() {
            const sb = document.querySelector('.dashboard-sidebar');
            const bd = document.getElementById('mobileSidebarBackdrop');
            if (sb) sb.classList.toggle('mobile-open');
            if (bd) bd.classList.toggle('active');
        }

        function closeMobileSidebar() {
            const sb = document.querySelector('.dashboard-sidebar');
            const bd = document.getElementById('mobileSidebarBackdrop');
            if (sb) sb.classList.remove('mobile-open');
            if (bd) bd.classList.remove('active');
        }

        
        // Knowledge Ingestion Custom Category Toggle Helper
        function toggleCustomCategoryInput(selectId, wrapId, inputId) {
            const sel = document.getElementById(selectId);
            const wrap = document.getElementById(wrapId);
            const inp = document.getElementById(inputId);
            if (!sel || !wrap) return;
            if (sel.value === '__custom__') {
                wrap.style.display = 'block';
                if (inp) inp.focus();
            } else {
                wrap.style.display = 'none';
            }
        }

        // Knowledge Ingestion Tab Controller
        function switchIngestMode(mode) {
            const btnUp = document.getElementById('tabBtnUpload');
            const btnUrl = document.getElementById('tabBtnUrl');
            const btnTxt = document.getElementById('tabBtnText');
            const formUp = document.getElementById('ingestFormFile');
            const formUrl = document.getElementById('ingestFormUrl');
            const formTxt = document.getElementById('ingestFormText');
            if (!btnUp || !formUp) return;

            const activeStyle = 'padding: 8px 16px; font-size: 12px; font-weight: 700; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; background: #063D3B; color: #C8FF63; border: 1px solid #063D3B;';
            const inactiveStyle = 'padding: 8px 16px; font-size: 12px; font-weight: 600; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; background: #FFFFFF; color: #648781; border: 1px solid #D1E5DE;';

            btnUp.style.cssText = mode === 'upload' ? activeStyle : inactiveStyle;
            btnUrl.style.cssText = mode === 'url' ? activeStyle : inactiveStyle;
            btnTxt.style.cssText = mode === 'text' ? activeStyle : inactiveStyle;

            formUp.style.display = mode === 'upload' ? 'block' : 'none';
            formUrl.style.display = mode === 'url' ? 'block' : 'none';
            formTxt.style.display = mode === 'text' ? 'block' : 'none';
        }

        function handleFileSelected(input) {
            const textEl = document.getElementById('dropZonePromptText');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const maxMb = window.currentOrgMaxUploadMb || 15;
                const fileMb = (file.size / (1024 * 1024)).toFixed(2);
                if (file.size > maxMb * 1024 * 1024) {
                    if (textEl) {
                        textEl.innerHTML = `<span style="color: #EF4444;">⚠️ Selected: ${escapeHtml(file.name)} (${fileMb} MB) — Exceeds ${maxMb} MB limit</span>`;
                    }
                    showToast(`Selected file (${fileMb} MB) exceeds your plan limit of ${maxMb} MB. Please choose a smaller file or upgrade.`, 'error');
                } else {
                    if (textEl) textEl.innerText = `Selected: ${file.name} (${fileMb} MB)`;
                }
            }
        }

        // Knowledge Ingestion Expiry Helpers
        function ingestSetExpiryDelta(inputId, days) {
            const d = new Date();
            d.setDate(d.getDate() + days);
            const iso = d.toISOString().split('T')[0];
            const el = document.getElementById(inputId);
            if (el) el.value = iso;
            showToast(`Validity expiry set to ${iso} (+${days} days)`, 'info');
        }

        function ingestClearExpiry(inputId) {
            const el = document.getElementById(inputId);
            if (el) el.value = '';
            showToast('Document expiration cleared â€” set to Evergreen policy.', 'info');
        }

        // Knowledge Ingestion Academic Programs Loader (Radio Button Pills)
        async function loadIngestPrograms() {
            const containers = document.querySelectorAll('.ingest-program-pill-container');
            if (!containers.length) return;

            try {
                const res = await fetch('/v1/programs', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                const programs = (data.status === 'success' && data.data) ? (data.data.courses || (Array.isArray(data.data) ? data.data : [])) : [];

                containers.forEach(container => {
                    const containerId = container.id || '';
                    const radioName = containerId.includes('File') ? 'ingest_file_program' : (containerId.includes('Url') ? 'ingest_url_program' : 'ingest_text_program');

                    let html = `
                        <label style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; background: #E8F5F3; border: 1.5px solid #063D3B; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #063D3B; user-select: none; transition: all 0.15s ease; box-shadow: 0 0 0 1px #063D3B;">
                            <input type="radio" name="${radioName}" value="" checked onchange="updateIngestProgramRadios('${containerId}')" style="cursor: pointer; accent-color: #063D3B;" />
                            <span>ðŸŒ</span>
                            <span>Entire Organization (All Programs)</span>
                        </label>
                    `;

                    if (programs.length > 0) {
                        html += programs.map(p => `
                            <label style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; background: #FFFFFF; border: 1.5px solid #E2E8F0; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #0F172A; user-select: none; transition: all 0.15s ease;">
                                <input type="radio" name="${radioName}" value="${p.id}" onchange="updateIngestProgramRadios('${containerId}')" style="cursor: pointer; accent-color: #063D3B;" />
                                <span>ðŸŽ“</span>
                                <span>${escapeHtml(p.course_name)}${p.course_code ? ' (' + escapeHtml(p.course_code) + ')' : ''}</span>
                            </label>
                        `).join('');
                    }

                    container.innerHTML = html;
                });
            } catch(e) {
                console.error('[Edvora] Error loading programs for ingestion:', e);
                containers.forEach(c => {
                    c.innerHTML = '<span style="font-size: 12px; color: #EF4444;">Failed to load academic programs.</span>';
                });
            }
        }
        window.loadIngestPrograms = loadIngestPrograms;

        function updateIngestProgramRadios(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const labels = container.querySelectorAll('label');
            labels.forEach(label => {
                const radio = label.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    label.style.borderColor = '#063D3B';
                    label.style.background = '#E8F5F3';
                    label.style.color = '#063D3B';
                    label.style.boxShadow = '0 0 0 1px #063D3B';
                } else if (label) {
                    label.style.borderColor = '#E2E8F0';
                    label.style.background = '#FFFFFF';
                    label.style.color = '#0F172A';
                    label.style.boxShadow = 'none';
                }
            });
        }
        window.updateIngestProgramRadios = updateIngestProgramRadios;

        // Knowledge Ingestion Department Scope Loader
        async function loadIngestDepartments() {
            const containers = document.querySelectorAll('.ingest-dept-pill-container');
            if (!containers.length) return;

            try {
                const res = await fetch('/v1/departments', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                const depts = (data.status === 'success' && data.data) ? (Array.isArray(data.data) ? data.data : (data.data.departments || [])) : [];

                containers.forEach(container => {
                    const containerId = container.id || '';
                    const radioName = containerId.includes('File') ? 'ingest_file_dept' : (containerId.includes('Url') ? 'ingest_url_dept' : 'ingest_text_dept');

                    if (depts.length === 0) {
                        container.innerHTML = '<span style="font-size: 12px; color: #94A3B8;">No departments configured in workspace. Global scope will be applied.</span>';
                        return;
                    }

                    let html = `
                        <label style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; background: #E8F5F3; border: 1.5px solid #063D3B; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #063D3B; user-select: none; transition: all 0.15s ease; box-shadow: 0 0 0 1px #063D3B;">
                            <input type="radio" name="${radioName}" value="" checked onchange="updateIngestDeptRadios('${containerId}')" style="cursor: pointer; accent-color: #063D3B;" />
                            <span>ðŸŒ</span>
                            <span>Entire Organization (Global Scope)</span>
                        </label>
                    `;

                    html += depts.map(d => `
                        <label style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; background: #FFFFFF; border: 1.5px solid #E2E8F0; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #0F172A; user-select: none; transition: all 0.15s ease;">
                            <input type="radio" name="${radioName}" value="${d.id}" onchange="updateIngestDeptRadios('${containerId}')" style="cursor: pointer; accent-color: #063D3B;" />
                            <span>${escapeHtml(d.icon || 'ðŸ«')}</span>
                            <span>${escapeHtml(d.name)}</span>
                        </label>
                    `).join('');

                    container.innerHTML = html;
                });
            } catch(e) {
                console.error('[Edvora] Error loading departments for ingestion:', e);
                containers.forEach(c => {
                    c.innerHTML = '<span style="font-size: 12px; color: #EF4444;">Failed to load departments.</span>';
                });
            }
        }
        window.loadIngestDepartments = loadIngestDepartments;

        function updateIngestDeptRadios(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const labels = container.querySelectorAll('label');
            labels.forEach(label => {
                const radio = label.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    label.style.borderColor = '#063D3B';
                    label.style.background = '#E8F5F3';
                    label.style.color = '#063D3B';
                    label.style.boxShadow = '0 0 0 1px #063D3B';
                } else if (label) {
                    label.style.borderColor = '#E2E8F0';
                    label.style.background = '#FFFFFF';
                    label.style.color = '#0F172A';
                    label.style.boxShadow = 'none';
                }
            });
        }
        window.updateIngestDeptRadios = updateIngestDeptRadios;

        async function submitIngestFile(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitFile');
            const title = document.getElementById('ingestDocTitle').value.trim();
            let category = document.getElementById('ingestDocCategory').value;
            if (category === '__custom__') {
                const customInp = document.getElementById('ingestDocCategoryCustom');
                const customVal = customInp ? customInp.value.trim() : '';
                if (!customVal) {
                    alert('Please specify a custom category name.');
                    if (customInp) customInp.focus();
                    return;
                }
                category = customVal;
            }
            const academic_version = document.getElementById('ingestAcademicVersion').value.trim();
            const effective_from = document.getElementById('ingestFileEffectiveFrom').value.trim();
            const expires_on = document.getElementById('ingestFileExpiresOn').value.trim();
            const review_freq = parseInt(document.getElementById('ingestFileReviewFreq').value) || 180;
            const deptRadio = document.querySelector('input[name="ingest_file_dept"]:checked');
            const department_id = deptRadio ? deptRadio.value.trim() : '';
            const programRadio = document.querySelector('input[name="ingest_file_program"]:checked');
            const program_id = programRadio ? programRadio.value.trim() : '';
            const fileInput = document.getElementById('ingestFileInput');

            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Please select a PDF, DOCX, or TXT document to upload.');
                return;
            }

            const selectedFile = fileInput.files[0];
            const maxAllowedMb = window.currentOrgMaxUploadMb || 15;
            if (selectedFile.size > maxAllowedMb * 1024 * 1024) {
                const actualMb = (selectedFile.size / (1024 * 1024)).toFixed(2);
                alert(`File size (${actualMb} MB) exceeds your plan limit of ${maxAllowedMb} MB per document. Please compress the file or upgrade your plan.`);
                return;
            }

            const formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('title', title);
            formData.append('category', category);
            formData.append('academic_version', academic_version);
            formData.append('review_frequency_days', review_freq);
            if (effective_from) formData.append('effective_from', effective_from);
            if (expires_on) formData.append('expires_on', expires_on);
            if (department_id) formData.append('department_id', department_id);
            if (program_id) formData.append('program_id', program_id);

            const origText = btn.innerHTML;
            btn.innerHTML = '<span>Indexing...</span>';
            btn.disabled = true;

            try {
                const res = await fetch('/v1/knowledge/upload', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token },
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Document uploaded, extracted, and indexed successfully!', 'success');
                    // Reset form and file selection state
                    fileInput.value = '';
                    const promptText = document.getElementById('dropZonePromptText');
                    if (promptText) promptText.innerText = 'Click or Drag PDF, DOCX, or TXT file here';
                    
                    // Reset button before switching tabs
                    btn.innerHTML = origText;
                    btn.disabled = false;

                    try {
                        await switchNavTab('knowledge');
                        if (typeof loadKnowledge === 'function') loadKnowledge();
                    } catch(navErr) {
                        console.warn('[Edvora] Error navigating to knowledge hub:', navErr);
                        window.location.hash = '#knowledge';
                    }
                    return;
                } else {
                    alert('Upload failed: ' + (data.message || 'Unknown error'));
                }
            } catch(err) {
                alert('Upload error: ' + err.message);
            } finally {
                btn.innerHTML = origText;
                btn.disabled = false;
            }
        }

        async function submitIngestUrl(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitUrl');
            const url = document.getElementById('ingestTargetUrl').value.trim();
            const title = document.getElementById('ingestUrlTitle').value.trim();
            let category = document.getElementById('ingestUrlCategory').value;
            if (category === '__custom__') {
                const customInp = document.getElementById('ingestUrlCategoryCustom');
                const customVal = customInp ? customInp.value.trim() : '';
                if (!customVal) {
                    alert('Please specify a custom category name.');
                    if (customInp) customInp.focus();
                    return;
                }
                category = customVal;
            }
            const academic_version = document.getElementById('ingestUrlAcademicVersion').value.trim();
            const effective_from = document.getElementById('ingestUrlEffectiveFrom').value.trim();
            const expires_on = document.getElementById('ingestUrlExpiresOn').value.trim();
            const review_freq = parseInt(document.getElementById('ingestUrlReviewFreq').value) || 180;
            const deptRadio = document.querySelector('input[name="ingest_url_dept"]:checked');
            const department_id = deptRadio ? deptRadio.value.trim() : '';
            const programRadio = document.querySelector('input[name="ingest_url_program"]:checked');
            const program_id = programRadio ? programRadio.value.trim() : '';

            const origText = btn.innerHTML;
            btn.innerHTML = '<span>Crawling...</span>';
            btn.disabled = true;

            const payload = {
                url,
                title,
                category,
                academic_version,
                review_frequency_days: review_freq
            };
            if (effective_from) payload.effective_from = effective_from;
            if (expires_on) payload.expires_on = expires_on;
            if (department_id) payload.department_id = department_id;
            if (program_id) payload.program_id = program_id;

            try {
                const res = await fetch('/v1/knowledge/crawl', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Web page crawled and indexed successfully!', 'success');
                    await switchNavTab('knowledge');
                    if (typeof loadKnowledge === 'function') loadKnowledge();
                } else {
                    alert('Crawl failed: ' + (data.message || 'Unknown error'));
                }
            } catch(err) {
                alert('Crawl error: ' + err.message);
            } finally {
                btn.innerHTML = origText;
                btn.disabled = false;
            }
        }

        async function submitIngestText(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitText');
            const title = document.getElementById('ingestTextTitle').value.trim();
            let category = document.getElementById('ingestTextCategory').value;
            if (category === '__custom__') {
                const customInp = document.getElementById('ingestTextCategoryCustom');
                const customVal = customInp ? customInp.value.trim() : '';
                if (!customVal) {
                    alert('Please specify a custom category name.');
                    if (customInp) customInp.focus();
                    return;
                }
                category = customVal;
            }
            const academic_version = document.getElementById('ingestTextAcademicVersion').value.trim();
            const effective_from = document.getElementById('ingestTextEffectiveFrom').value.trim();
            const expires_on = document.getElementById('ingestTextExpiresOn').value.trim();
            const review_freq = parseInt(document.getElementById('ingestTextReviewFreq').value) || 180;
            const deptRadio = document.querySelector('input[name="ingest_text_dept"]:checked');
            const department_id = deptRadio ? deptRadio.value.trim() : '';
            const programRadio = document.querySelector('input[name="ingest_text_program"]:checked');
            const program_id = programRadio ? programRadio.value.trim() : '';
            const content = document.getElementById('ingestTextContent').value.trim();

            const origText = btn.innerHTML;
            btn.innerHTML = '<span>Saving...</span>';
            btn.disabled = true;

            const payload = {
                title,
                category,
                content,
                academic_version,
                review_frequency_days: review_freq
            };
            if (effective_from) payload.effective_from = effective_from;
            if (expires_on) payload.expires_on = expires_on;
            if (department_id) payload.department_id = department_id;
            if (program_id) payload.program_id = program_id;

            try {
                const res = await fetch('/v1/knowledge/paste', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Direct circular saved and indexed successfully!', 'success');
                    await switchNavTab('knowledge');
                    if (typeof loadKnowledge === 'function') loadKnowledge();
                } else {
                    alert('Save failed: ' + (data.message || 'Unknown error'));
                }
            } catch(err) {
                alert('Save error: ' + err.message);
            } finally {
                btn.innerHTML = origText;
                btn.disabled = false;
            }
        }

        async function switchNavTab(tab, subtab = null, saveHistory = true) {
            if (!tab) tab = 'overview';
            await loadTabContent(tab);
            if (typeof closeMobileSidebar === "function") closeMobileSidebar();
            const navItem = document.querySelector(`.nav-item[data-tab="${tab}"]`);
            
            document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            
            if (navItem) {
                navItem.classList.add('active');
            } else if (tab === 'knowledge-gaps') {
                const anItem = document.querySelector(`.nav-item[data-tab="analytics"]`);
                if (anItem) anItem.classList.add('active');
            } else if (tab === 'chatbot' || tab === 'settings' || tab === 'scholarships' || tab === 'scholarship-configuration' || tab === 'scholarship-configuration-edit') {
                const orgItem = document.querySelector(`.nav-item[data-tab="org-settings"]`);
                if (orgItem) orgItem.classList.add('active');
            } else if (tab === 'add-programs' || tab === 'edit-programs' || tab === 'academic-program-detail' || tab === 'course-staff-assignment' || tab === 'program-staff' || tab === 'program-lead-magnet') {
                const progItem = document.querySelector(`.nav-item[data-tab="academic-programs"]`);
                if (progItem) progItem.classList.add('active');
            } else if (tab === 'knowledge-edit' || tab === 'knowledge-editor') {
                const kItem = document.querySelector(`.nav-item[data-tab="knowledge"]`);
                if (kItem) kItem.classList.add('active');
            }

            const targetTab = document.getElementById('tab-' + tab);
            if (targetTab) {
                targetTab.classList.add('active');
            }

            // Universal 1-Click Root-Reset on Navigation Click (Personal Preference & Coding Standard)
            try {
                if (tab === 'departments') {
                    if (typeof window.closeDeptDeepDive === 'function') {
                        window.closeDeptDeepDive();
                    } else {
                        const grid = document.getElementById('view-departments-grid');
                        const detail = document.getElementById('view-departments-detail');
                        if (detail) detail.style.display = 'none';
                        if (grid) grid.style.display = 'block';
                    }
                } else if (tab === 'knowledge') {
                    const docModal = document.getElementById('docPreviewModal');
                    if (docModal) docModal.style.display = 'none';
                    const editModal = document.getElementById('editDocModal');
                    if (editModal) editModal.style.display = 'none';
                    const editorTab = document.getElementById('tab-knowledge-editor');
                    if (editorTab) editorTab.classList.remove('active');
                    const editTab = document.getElementById('tab-knowledge-edit');
                    if (editTab) editTab.classList.remove('active');
                    const viewTab = document.getElementById('tab-knowledge-view');
                    if (viewTab) viewTab.classList.remove('active');
                    if (window._skipKnowledgeFilterResetOnce) {
                        window._skipKnowledgeFilterResetOnce = false;
                    } else {
                        window._userSelectedKnowledgeDeptFilter = null;
                        window._userSelectedKnowledgeProgramFilter = null;
                        const deptSelect = document.getElementById('ckhDeptFilter');
                        if (deptSelect) deptSelect.value = 'all';
                        const progSelect = document.getElementById('ckhProgramFilter');
                        if (progSelect) progSelect.value = 'all';
                    }
                } else if (tab === 'leads') {
                    if (typeof closeLeadDrawer === 'function') {
                        closeLeadDrawer();
                    } else {
                        const drawer = document.getElementById('leadDetailDrawer');
                        if (drawer) drawer.classList.remove('open');
                    }
                } else if (tab === 'teams') {
                    if (typeof closeAddStaffModal === 'function') closeAddStaffModal();
                } else if (tab === 'knowledge-ingestion') {
                    if (typeof loadIngestPrograms === 'function') loadIngestPrograms();
                    if (typeof loadIngestDepartments === 'function') loadIngestDepartments();
                } else if (tab === 'profile') {
                    if (typeof loadProfileData === 'function') loadProfileData();
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch(e) {
                console.warn('[Edvora] Error in universal tab root reset:', e);
            }
            
            // Clean header title text (remove emoji prefix if present)
            let cleanTitle = navItem ? navItem.innerText.trim().replace(/^[^\w\s]+\s*/, '') : '';
            if (tab === 'knowledge') {
                cleanTitle = 'Central Knowledge Hub (Institution-Wide)';
            } else if (tab === 'knowledge-edit' || tab === 'knowledge-editor') {
                cleanTitle = 'Edit Knowledge Document';
            } else if (tab === 'academic-programs') {
                cleanTitle = 'Academic Programs & Degrees';
            } else if (tab === 'add-programs') {
                cleanTitle = 'Add Academic Program';
            } else if (tab === 'edit-programs') {
                cleanTitle = 'Edit Academic Program';
            } else if (tab === 'academic-program-detail') {
                cleanTitle = 'Academic Program Details';
            } else if (tab === 'course-staff-assignment') {
                cleanTitle = 'Course Staff Assignment';
            } else if (tab === 'program-lead-magnet') {
                cleanTitle = 'Program Lead Magnet';
            } else if (tab === 'teams') {
                cleanTitle = 'Teams & Staff Management';
            } else if (tab === 'assets') {
                cleanTitle = 'Lead-Magnet Assets & Documents';
            } else if (tab === 'multilingual') {
                cleanTitle = 'Multilingual Support';
            } else if (tab === 'analytics') {
                cleanTitle = 'Admissions & ROI Analytics';
            } else if (tab === 'knowledge-gaps') {
                cleanTitle = 'Inquiry Leakage & Knowledge Gap Breakup';
            } else if (tab === 'integrations') {
                cleanTitle = 'Higher Ed Integrations Hub (USA & Canada)';
            } else if (tab === 'callbacks') {
                cleanTitle = 'Counselor Callbacks Queue';
            } else if (tab === 'org-settings') {
                cleanTitle = 'Control Panel';
            } else if (tab === 'campus-tours') {
                cleanTitle = 'Campus Tours & Visit Management';
            } else if (tab === 'campus-tours-scheduling') {
                cleanTitle = 'Campus Visit Scheduling & Rules';
            } else if (tab === 'create-tour-slot') {
                cleanTitle = 'Create Campus Tour Slot';
            } else if (tab === 'edit-tour-slot') {
                cleanTitle = 'Edit Campus Tour Slot';
            } else if (tab === 'campuses') {
                cleanTitle = 'Campus Management';
            } else if (tab === 'campus-editor') {
                cleanTitle = 'Campus Editor';
            } else if (tab === 'settings') {
                cleanTitle = 'Institution Profile';
            } else if (tab === 'chatbot') {
                cleanTitle = 'ChatBot Settings';
            } else if (tab === 'profile') {
                cleanTitle = 'My Profile & Security';
            }
            const headerTitleEl = document.getElementById('headerTitle');
            if (headerTitleEl) headerTitleEl.innerText = cleanTitle;

            localStorage.setItem('edvora_active_tab', tab);

            if (saveHistory) {
                try {
                    history.replaceState(null, '', '#' + tab);
                } catch(e) {}
            }

            // If subtab specified or saved for chatbot tab
            if (tab === 'chatbot') {
                let savedSubtab = subtab || localStorage.getItem('edvora_studio_subtab') || 'widget';
                if (savedSubtab !== 'embed') {
                    savedSubtab = 'widget';
                }
                if (typeof switchStudioSubtab === 'function') {
                    switchStudioSubtab(savedSubtab, false);
                }
                if (typeof loadWidgetCustomization === 'function') {
                    loadWidgetCustomization();
                }
            }

            // Trigger data fetch for selected tab with defensive safety
            try {
                if (tab === 'departments') {
                    if (typeof closeDeptDeepDive === 'function') closeDeptDeepDive();
                    if (typeof loadDepartments === 'function') loadDepartments();
                } else if (tab === 'academic-programs') {
                    if (typeof loadAcademicProgramsTab === 'function') loadAcademicProgramsTab();
                } else if (tab === 'add-programs') {
                    if (typeof initAddProgramsPage === 'function') initAddProgramsPage();
                } else if (tab === 'edit-programs') {
                    if (typeof initEditProgramsPage === 'function') initEditProgramsPage();
                } else if (tab === 'academic-program-detail') {
                    if (typeof initAcademicProgramDetailPage === 'function') initAcademicProgramDetailPage();
                } else if (tab === 'teams') {
                    if (typeof loadTeamsWorkspace === 'function') loadTeamsWorkspace();
                } else if (tab === 'overview') {
                    if (typeof applyTimeWindowUI === 'function') {
                        applyTimeWindowUI(window.currentGlobalTimeWindow || '30d');
                    }
                    if (typeof loadAnalytics === 'function') loadAnalytics(window.currentGlobalTimeWindow || '30d');
                } else if (tab === 'analytics') {
                    if (typeof loadAdmissionsAnalytics === 'function') loadAdmissionsAnalytics();
                } else if (tab === 'knowledge-gaps') {
                    if (typeof loadKnowledgeGapsBreakup === 'function') loadKnowledgeGapsBreakup();
                } else if (tab === 'integrations') {
                    if (typeof renderIntegrationsHubView === 'function') renderIntegrationsHubView();
                } else if (tab === 'knowledge') {
                    if (typeof loadKnowledge === 'function') loadKnowledge();
                } else if (tab === 'assets') {
                    if (typeof loadAssets === 'function') loadAssets();
                } else if (tab === 'multilingual') {
                    if (typeof loadMultilingualSettings === 'function') loadMultilingualSettings();
                } else if (tab === 'conversion-engine' || tab === 'placeholder') {
                    if (typeof loadConversionEngine === 'function') loadConversionEngine();
                } else if (tab === 'leads') {
                    if (typeof loadLeads === 'function') loadLeads();
                } else if (tab === 'callbacks') {
                    if (typeof loadCallbacks === 'function') loadCallbacks();
                } else if (tab === 'campus-tours') {
                    if (typeof loadCampusTours === 'function') loadCampusTours();
                } else if (tab === 'campus-tours-scheduling') {
                    if (typeof loadTourSlots === 'function') loadTourSlots();
                    if (typeof loadTourSettings === 'function') loadTourSettings();
                } else if (tab === 'create-tour-slot') {
                    if (typeof initCreateTourSlotPage === 'function') initCreateTourSlotPage();
                } else if (tab === 'edit-tour-slot') {
                    if (typeof initEditTourSlotPage === 'function') initEditTourSlotPage();
                } else if (tab === 'scholarships') {
                    if (typeof loadScholarships === 'function') loadScholarships();
                } else if (tab === 'scholarship-configuration') {
                    if (typeof initScholarshipConfiguration === 'function') initScholarshipConfiguration();
                } else if (tab === 'scholarship-configuration-edit') {
                    if (typeof initScholarshipEditor === 'function') initScholarshipEditor();
                } else if (tab === 'chatbot') {
                    if (typeof loadWidgetCustomization === 'function') loadWidgetCustomization();
                } else if (tab === 'test-chat') {
                    if (typeof loadTestChatStudio === 'function') loadTestChatStudio();
                } else if (tab === 'organization') {
                    if (typeof loadCollegeProfile === 'function') loadCollegeProfile();
                } else if (tab === 'campuses') {
                    if (typeof loadCampuses === 'function') loadCampuses();
                } else if (tab === 'campus-editor') {
                    if (typeof initCampusEditorPage === 'function') initCampusEditorPage();
                } else if (tab === 'course-staff-assignment') {
                    if (typeof initCourseStaffAssignmentPage === 'function') initCourseStaffAssignmentPage();
                } else if (tab === 'program-staff') {
                    if (typeof initProgramStaffPage === 'function') initProgramStaffPage();
                } else if (tab === 'program-lead-magnet') {
                    if (typeof initProgramLeadMagnetPage === 'function') initProgramLeadMagnetPage();
                } else if (tab === 'academic-program-detail') {
                    if (typeof initAcademicProgramDetailPage === 'function') initAcademicProgramDetailPage();
                } else if (tab === 'knowledge-edit' || tab === 'knowledge-editor') {
                    if (typeof openKnowledgeEditor === 'function') openKnowledgeEditor();
                } else if (tab === 'settings') {
                    if (typeof loadSettingsData === 'function') loadSettingsData();
                } else if (tab === 'profile') {
                    if (typeof loadProfileData === 'function') loadProfileData();
                }
            } catch (tabErr) {
                console.warn(`[Edvora] Handled non-fatal error initializing tab ${tab}:`, tabErr);
            }
        }

        document.querySelectorAll('.nav-item').forEach(item => {
            item.onclick = () => {
                const tab = item.getAttribute('data-tab');
                switchNavTab(tab);
            };
        });

        // â”€â”€ ðŸ‘¤ USER PROFILE & SECURITY WORKSPACE LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        function loadProfileData() {
            const nameInput = document.getElementById('profile_name');
            const emailInput = document.getElementById('profile_email');
            const currentPwdInput = document.getElementById('profile_current_password');
            const newPwdInput = document.getElementById('profile_new_password');
            const confirmPwdInput = document.getElementById('profile_confirm_password');
            const errorNotice = document.getElementById('profileFormErrorNotice');
            const pwdStrengthCard = document.getElementById('pwdStrengthCard');
            const pwdMatchNotice = document.getElementById('pwdMatchNotice');

            if (currentPwdInput) currentPwdInput.value = '';
            if (newPwdInput) newPwdInput.value = '';
            if (confirmPwdInput) confirmPwdInput.value = '';
            if (errorNotice) errorNotice.style.display = 'none';
            if (pwdStrengthCard) pwdStrengthCard.style.display = 'none';
            if (pwdMatchNotice) pwdMatchNotice.style.display = 'none';

            // Cold-start self-healing: if currentUser is not yet populated, fetch it
            if ((!window.currentUser || !window.currentUser.name) && token) {
                fetch('/v1/auth/me', { headers: { 'Authorization': 'Bearer ' + token } })
                    .then(r => r.json())
                    .then(d => {
                        if (d.status === 'success' && d.data && d.data.user) {
                            window.currentUser = d.data.user;
                            if (d.data.organization && d.data.organization.name) {
                                window.currentOrgName = d.data.organization.name;
                            }
                            loadProfileData();
                        }
                    }).catch(() => {});
            }

            const user = window.currentUser || {};
            const orgName = window.currentOrgName || 'Your Institution';

            if (nameInput && user.name) nameInput.value = user.name;
            if (emailInput && user.email) emailInput.value = user.email;

            // Update Top Identity Hero Card (Exact Match to User Sketch)
            const summaryAvatar = document.getElementById('profileSummaryAvatar');
            const summaryName = document.getElementById('profileSummaryName');
            const heroPrivilege = document.getElementById('profileHeroPrivilege');
            const heroDepts = document.getElementById('profileHeroDepartments');
            const heroEmail = document.getElementById('profileHeroEmail');
            const heroOrg = document.getElementById('profileHeroOrg');
            const tabRoleText = document.getElementById('profileTabRoleText');
            const tabOrgText = document.getElementById('profileTabOrgText');

            if (summaryAvatar) summaryAvatar.innerText = user.name ? user.name.charAt(0).toUpperCase() : 'U';
            if (summaryName) summaryName.innerText = user.name || 'User Profile';
            if (heroEmail) heroEmail.innerText = user.email || 'user@institution.edu';
            if (heroOrg) heroOrg.innerText = orgName;
            if (tabOrgText) tabOrgText.innerText = orgName;

            // App Privilege Line (Matching sketch: "App Privilege: Team")
            const isOwner = user.role === 'owner';
            const isSysAdmin = user.role === 'owner' || user.role === 'org_admin' || user.role === 'admin' || user.role === 'superadmin' || user.role === 'super_admin';
            
            let privilegeLabel = 'Team Member';
            let privilegeIcon = 'ðŸ‘¥';
            if (isOwner) {
                privilegeLabel = 'Institution Owner';
                privilegeIcon = 'ðŸ‘‘';
            } else if (isSysAdmin) {
                privilegeLabel = 'Full Administrator';
                privilegeIcon = 'ðŸ›¡ï¸';
            }

            const heroPrivText = document.getElementById('profileHeroPrivilegeText');
            const heroPrivIcon = document.getElementById('profileHeroPrivilegeIcon');
            const heroDeptCount = document.getElementById('profileHeroDeptCount');
            const heroMemberSinceText = document.getElementById('profileHeroMemberSinceText');

            if (heroPrivText) heroPrivText.innerText = 'App Privilege: ' + privilegeLabel;
            if (heroPrivIcon) heroPrivIcon.innerText = privilegeIcon;
            if (tabRoleText) tabRoleText.innerText = 'Role: ' + privilegeLabel;

            // Format Member Since (e.g. "September 2024")
            if (heroMemberSinceText) {
                if (user.created_at) {
                    try {
                        const d = new Date(user.created_at);
                        const month = d.toLocaleString('en-US', { month: 'long' });
                        const year = d.getFullYear();
                        heroMemberSinceText.innerText = `Member Since: ${month} ${year}`;
                    } catch (e) {
                        heroMemberSinceText.innerText = 'Member Since: Active';
                    }
                } else {
                    heroMemberSinceText.innerText = 'Member Since: Verified User';
                }
            }

            // Department Name Badges (Strict Edvora Brand Theme: Mint & Forest Teal)
            if (heroDepts) {
                const depts = Array.isArray(user.departments) ? user.departments : [];
                if (depts.length > 0) {
                    if (heroDeptCount) heroDeptCount.innerText = `${depts.length} Unit${depts.length > 1 ? 's' : ''} Assigned`;
                    heroDepts.innerHTML = depts.map(d => `
                        <span class="profile-dept-badge" style="display: inline-flex; align-items: center; gap: 7px; padding: 6px 14px; background: #F4FAF7; border: 1.5px solid #D1E5DE; border-radius: 8px; font-size: 11.5px; font-weight: 700; color: #063D3B; box-shadow: 0 1px 3px rgba(6, 61, 59, 0.04); transition: all 0.15s ease;">
                            <span style="font-size: 13px;">${d.icon || 'ðŸ«'}</span>
                            <span>${d.name}</span>
                        </span>
                    `).join('');
                } else if (isSysAdmin) {
                    if (heroDeptCount) heroDeptCount.innerText = 'System-Wide Access';
                    heroDepts.innerHTML = `
                        <span class="profile-dept-badge" style="display: inline-flex; align-items: center; gap: 7px; padding: 6px 14px; background: #F4FAF7; border: 1.5px solid #D1E5DE; border-radius: 8px; font-size: 11.5px; font-weight: 700; color: #063D3B; box-shadow: 0 1px 3px rgba(6, 61, 59, 0.04);">
                            <span style="font-size: 13px;">ðŸ›ï¸</span>
                            <span>All Campus Units &amp; Departments (System-Wide Oversight)</span>
                        </span>
                    `;
                } else {
                    if (heroDeptCount) heroDeptCount.innerText = 'General Access';
                    heroDepts.innerHTML = `
                        <span class="profile-dept-badge" style="display: inline-flex; align-items: center; gap: 7px; padding: 6px 14px; background: #F4FAF7; border: 1.5px solid #D1E5DE; border-radius: 8px; font-size: 11.5px; font-weight: 700; color: #063D3B; box-shadow: 0 1px 3px rgba(6, 61, 59, 0.04);">
                            <span style="font-size: 13px;">ðŸ«</span>
                            <span>General Admissions Queue</span>
                        </span>
                    `;
                }
            }
        }

        async function handleUserProfileSubmit(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveProfile');
            const errorNotice = document.getElementById('profileFormErrorNotice');
            const errorText = document.getElementById('profileFormErrorText');

            if (errorNotice) errorNotice.style.display = 'none';

            const name = document.getElementById('profile_name')?.value?.trim() || '';
            const email = document.getElementById('profile_email')?.value?.trim() || '';
            const currentPassword = document.getElementById('profile_current_password')?.value || '';
            const newPassword = document.getElementById('profile_new_password')?.value || '';
            const confirmPassword = document.getElementById('profile_confirm_password')?.value || '';

            if (!name) {
                showProfileError('Please provide a valid display name.');
                return;
            }
            if (!email) {
                showProfileError('Please provide a valid email address.');
                return;
            }

            const isEmailChanging = window.currentUser && (email.toLowerCase() !== (window.currentUser.email || '').toLowerCase());
            const isPasswordChanging = Boolean(newPassword && newPassword.length > 0);

            // Both email change and password change strictly require current password
            if ((isEmailChanging || isPasswordChanging) && !currentPassword) {
                showProfileError('Current password is required to change your email address or set a new password.');
                document.getElementById('profile_current_password')?.focus();
                return;
            }

            if (isPasswordChanging) {
                // Check 5 complexity rules
                const hasLength = newPassword.length >= 8;
                const hasNumber = /[0-9]/.test(newPassword);
                const hasLower = /[a-z]/.test(newPassword);
                const hasUpper = /[A-Z]/.test(newPassword);
                const hasSpecial = /[^a-zA-Z0-9]/.test(newPassword);

                if (!hasLength || !hasNumber || !hasLower || !hasUpper || !hasSpecial) {
                    showProfileError('New password must satisfy all 5 criteria in the checklist below.');
                    document.getElementById('profile_new_password')?.focus();
                    return;
                }

                if (newPassword !== confirmPassword) {
                    showProfileError('New password and confirmation password do not match.');
                    document.getElementById('profile_confirm_password')?.focus();
                    return;
                }
            }

            const origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.innerHTML = '<span>Saving...</span>';
                btn.disabled = true;
            }

            try {
                const payload = {
                    name: name,
                    email: email,
                    current_password: currentPassword,
                    new_password: newPassword
                };

                const res = await fetch('/v1/auth/profile', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();

                if (data.status === 'success') {
                    // If a fresh access token was issued (e.g. after email update)
                    if (data.data && data.data.access_token) {
                        token = data.data.access_token;
                        localStorage.setItem('edvora_token', token);
                    }

                    // Update in-memory user state
                    if (window.currentUser) {
                        window.currentUser.name = name;
                        window.currentUser.email = email;
                    }

                    // Update DOM references immediately
                    const navUserName = document.getElementById('userName');
                    const navUserAvatar = document.getElementById('userAvatar');
                    if (navUserName) navUserName.innerText = name;
                    if (navUserAvatar) navUserAvatar.innerText = name ? name.charAt(0).toUpperCase() : 'U';

                    // Clear sensitive password inputs
                    const curPwd = document.getElementById('profile_current_password');
                    const newPwd = document.getElementById('profile_new_password');
                    const cnfPwd = document.getElementById('profile_confirm_password');
                    if (curPwd) curPwd.value = '';
                    if (newPwd) newPwd.value = '';
                    if (cnfPwd) cnfPwd.value = '';
                    const strCard = document.getElementById('pwdStrengthCard');
                    if (strCard) strCard.style.display = 'none';
                    const mNotice = document.getElementById('pwdMatchNotice');
                    if (mNotice) mNotice.style.display = 'none';

                    // Update Summary Card
                    loadProfileData();

                    showToast('âœ“ Profile & credentials updated successfully!', 'success');
                } else {
                    showProfileError(data.message || 'Could not update profile. Please check inputs.');
                }
            } catch (err) {
                console.error('[Edvora] Error saving profile:', err);
                showProfileError('Network connection error while saving profile.');
            } finally {
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
            }
        }

        function showProfileError(msg) {
            const errorNotice = document.getElementById('profileFormErrorNotice');
            const errorText = document.getElementById('profileFormErrorText');
            if (errorNotice && errorText) {
                errorText.innerText = msg;
                errorNotice.style.display = 'block';
                errorNotice.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                alert(msg);
            }
        }

        // â”€â”€ ðŸš€ CONVERSION ENGINE CLIENT LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let ceCurrentSubtab = 'overview';
        let ceCachedPipelineData = null;
        let activeDrawerLeadId = null;

        function switchCeSubtab(subtab) {
            ceCurrentSubtab = subtab;
            document.querySelectorAll('.ce-subnav-btn').forEach(btn => {
                if (btn.getAttribute('data-cesub') === subtab) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            document.querySelectorAll('.ce-subtab-panel').forEach(p => p.style.display = 'none');
            const target = document.getElementById('ceSubtab-' + subtab);
            if (target) target.style.display = 'block';

            if (subtab === 'pipeline') {
                loadPipelineData();
            } else if (subtab === 'followups') {
                loadCeFollowups();
            } else if (subtab === 'pulse') {
                loadCePulse();
            }
        }

        async function loadConversionEngine() {
            if (!token) return;
            try {
                // Fetch Overview stats
                const res = await fetch('/v1/conversion-engine/overview', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const resData = await res.json();
                if (resData.status === 'success' && resData.data) {
                    const d = resData.data;
                    // Top stats
                    const statLeads = document.getElementById('ceStatLeads');
                    const statQual = document.getElementById('ceStatQualified');
                    const statApps = document.getElementById('ceStatApplications');
                    const statEnr = document.getElementById('ceStatEnrolled');

                    if (statLeads) statLeads.innerText = (d.funnel.leads || 0).toLocaleString();
                    if (statQual) statQual.innerText = (d.funnel.qualified || 0).toLocaleString();
                    if (statApps) statApps.innerText = (d.funnel.applications || 0).toLocaleString();
                    if (statEnr) statEnr.innerText = (d.funnel.enrolled || 0).toLocaleString();

                    // Funnel steps
                    const elV = document.getElementById('ceFunnelVisitors');
                    const elL = document.getElementById('ceFunnelLeads');
                    const elQ = document.getElementById('ceFunnelQualified');
                    const elC = document.getElementById('ceFunnelContacted');
                    const elA = document.getElementById('ceFunnelApps');
                    const elE = document.getElementById('ceFunnelEnrolled');

                    if (elV) elV.innerText = (d.funnel.visitors || 0).toLocaleString();
                    if (elL) elL.innerText = (d.funnel.leads || 0).toLocaleString();
                    if (elQ) elQ.innerText = (d.funnel.qualified || 0).toLocaleString();
                    if (elC) elC.innerText = (d.funnel.contacted || 0).toLocaleString();
                    if (elA) elA.innerText = (d.funnel.applications || 0).toLocaleString();
                    if (elE) elE.innerText = (d.funnel.enrolled || 0).toLocaleString();

                    // Needs Attention Alerts
                    const altH = document.getElementById('ceAlertHotLeads');
                    const altC = document.getElementById('ceAlertCallbacks');
                    const altT = document.getElementById('ceAlertTours');
                    const altS = document.getElementById('ceAlertScholarship');

                    if (altH) altH.innerText = `${d.needs_attention.hot_leads_uncontacted || 0} hot leads haven't been contacted in 24h`;
                    if (altC) altC.innerText = `${d.needs_attention.callbacks_due_today || 0} callbacks are due today`;
                    if (altT) altT.innerText = `${d.needs_attention.campus_visitors_no_app || 0} campus visitors haven't started applications`;
                    if (altS) altS.innerText = `${d.needs_attention.scholarship_followups || 0} scholarship-qualified students need follow-up`;

                    // Activity
                    const actC = document.getElementById('ceActCallbacks');
                    const actT = document.getElementById('ceActTours');
                    const actS = document.getElementById('ceActScholarships');
                    const actM = document.getElementById('ceActMagnets');

                    if (actC) actC.innerText = (d.activity.callbacks || 0).toLocaleString();
                    if (actT) actT.innerText = (d.activity.campus_tours || 0).toLocaleString();
                    if (actS) actS.innerText = (d.activity.scholarships || 0).toLocaleString();
                    if (actM) actM.innerText = (d.activity.lead_magnets || 0).toLocaleString();
                }

                // Also background load followups count badge and pipeline
                loadCeFollowups(true);
            } catch (err) {
                console.error('Failed to load Conversion Engine overview:', err);
            }
        }

        async function loadPipelineData() {
            if (!token) return;
            try {
                const res = await fetch('/v1/conversion-engine/pipeline', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    ceCachedPipelineData = data.data.stages;
                    renderPipelineBoard(ceCachedPipelineData);
                    populateProgramFilters(ceCachedPipelineData);
                }
            } catch (err) {
                console.error('Failed to load pipeline:', err);
            }
        }

        function populateProgramFilters(stages) {
            const select = document.getElementById('cePipelineProgramFilter');
            if (!select) return;
            const currentVal = select.value;
            const programs = new Set();
            Object.values(stages).forEach(cards => {
                cards.forEach(c => {
                    if (c.program_interest) programs.add(c.program_interest);
                });
            });
            let optsHtml = '<option value="">All Programs</option>';
            programs.forEach(p => {
                optsHtml += `<option value="${escapeHtml(p)}" ${currentVal === p ? 'selected' : ''}>${escapeHtml(p)}</option>`;
            });
            select.innerHTML = optsHtml;
        }

        function renderPipelineBoard(stages) {
            const board = document.getElementById('ceKanbanBoard');
            if (!board) return;

            const stageConfigs = [
                { key: 'new', label: 'New Inquiries', icon: 'ðŸ†•', color: '#6366f1' },
                { key: 'qualified', label: 'Qualified', icon: 'ðŸŽ¯', color: '#38bdf8' },
                { key: 'contacted', label: 'Contacted', icon: 'ðŸ“ž', color: '#fbbf24' },
                { key: 'application', label: 'Application Started', icon: 'ðŸ“', color: '#a855f7' },
                { key: 'campus_visit', label: 'Campus Visit', icon: 'ðŸ«', color: '#818cf8' },
                { key: 'decision', label: 'Committee Review', icon: 'âš–ï¸', color: '#f43f5e' },
                { key: 'enrolled', label: 'Enrolled Students', icon: 'ðŸŽ“', color: '#22c55e' },
                { key: 'lost', label: 'Archived / Lost', icon: 'ðŸ“', color: '#64748b' }
            ];

            const search = (document.getElementById('cePipelineSearch')?.value || '').toLowerCase().trim();
            const progFilter = document.getElementById('cePipelineProgramFilter')?.value || '';

            let html = '';

            stageConfigs.forEach(stg => {
                let cards = stages[stg.key] || [];

                // Filter
                if (search || progFilter) {
                    cards = cards.filter(c => {
                        const matchSearch = !search || 
                            (c.name && c.name.toLowerCase().includes(search)) || 
                            (c.email && c.email.toLowerCase().includes(search)) || 
                            (c.phone && c.phone.includes(search)) || 
                            (c.program_interest && c.program_interest.toLowerCase().includes(search));
                        const matchProg = !progFilter || (c.program_interest === progFilter);
                        return matchSearch && matchProg;
                    });
                }

                html += `
                <div class="ce-kanban-col" data-stage="${stg.key}">
                    <div class="ce-kanban-col-header" style="border-top: 2px solid ${stg.color};">
                        <div class="ce-kanban-col-title">
                            <span>${stg.icon}</span> ${stg.label}
                        </div>
                        <span class="ce-kanban-col-badge">${cards.length}</span>
                    </div>
                    <div class="ce-kanban-col-cards">
                `;

                if (cards.length === 0) {
                    html += `
                        <div style="padding: 24px 12px; text-align: center; color: #648781; font-size: 11px;">
                            No candidates in this stage
                        </div>
                    `;
                } else {
                    cards.forEach(card => {
                        const score = card.conversion_score || 50;
                        let scoreClass = '';
                        if (score >= 90) scoreClass = 'top';
                        else if (score >= 75) scoreClass = 'high';

                        // Relative time helper
                        const timeAgo = formatTimeAgo(card.last_activity_at || card.created_at);

                        // Chips
                        const signals = Array.isArray(card.intent_signals) ? card.intent_signals : [];
                        const topSignal = signals.length > 0 ? signals[0] : null;

                        html += `
                        <div class="ce-kanban-card" onclick="openLeadIntelligence(${card.id})">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 6px;">
                                <span class="ce-score-chip ${scoreClass}">ðŸ”¥ ${score}</span>
                                <span style="font-size: 10px; color: #648781; font-family: var(--brand-font-mono);">${timeAgo}</span>
                            </div>
                            <div style="font-size: 13px; font-weight: 700; color: #ffffff; margin-bottom: 2px;">
                                ${escapeHtml(card.name)}
                            </div>
                            <div style="font-size: 11px; color: #4F7470; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 8px;">
                                ðŸŽ“ ${escapeHtml(card.program_interest || 'General Admissions')}
                            </div>

                            ${topSignal ? `
                            <div style="font-size: 10px; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 4px; padding: 3px 6px; color: var(--brand-indigo-300); margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <span>${topSignal.icon || 'âš¡'}</span> ${escapeHtml(topSignal.signal)}
                            </div>
                            ` : ''}

                            <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 8px; margin-top: 4px;">
                                <span style="font-size: 10px; color: #648781;">${escapeHtml(card.acquisition_source || 'Website')}</span>
                                <span style="font-size: 11px; font-weight: 700; color: var(--brand-indigo-400);">Open Intelligence â†’</span>
                            </div>
                        </div>
                        `;
                    });
                }

                html += `
                    </div>
                </div>
                `;
            });

            board.innerHTML = html;
        }

        function filterPipelineBoard() {
            if (ceCachedPipelineData) {
                renderPipelineBoard(ceCachedPipelineData);
            }
        }

        function formatTimeAgo(dateStr) {
            if (!dateStr) return 'recently';
            const diffMs = Date.now() - new Date(dateStr).getTime();
            const mins = Math.floor(diffMs / 60000);
            if (mins < 1) return 'just now';
            if (mins < 60) return `${mins}m ago`;
            const hours = Math.floor(mins / 60);
            if (hours < 24) return `${hours}h ago`;
            const days = Math.floor(hours / 24);
            return `${days}d ago`;
        }

        async function openLeadIntelligence(leadId) {
            if (!token || !leadId) return;
            activeDrawerLeadId = leadId;

            const overlay = document.getElementById('ceLeadDrawerOverlay');
            if (overlay) overlay.style.display = 'flex';

            try {
                const res = await fetch(`/v1/conversion-engine/leads/${leadId}/intelligence`, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    const d = data.data;
                    const lead = d.lead;
                    const scoring = d.scoring;
                    const nba = d.next_best_action;
                    const events = d.journey_events || [];

                    // Populate Drawer Header & Score
                    const nameEl = document.getElementById('drawerStudentName');
                    if (nameEl) nameEl.innerText = lead.name;

                    const scoreEl = document.getElementById('drawerScoreVal');
                    if (scoreEl) scoreEl.innerHTML = `ðŸ”¥ ${scoring.score} <span style="font-size: 14px; color: #648781; font-weight: 500;">/ 100</span>`;

                    const scoreDesc = document.getElementById('drawerScoreDesc');
                    if (scoreDesc) scoreDesc.innerText = scoring.rationale;

                    const stageBadge = document.getElementById('drawerStageBadge');
                    if (stageBadge) stageBadge.innerText = `Stage: ${lead.pipeline_stage ? lead.pipeline_stage.toUpperCase() : 'NEW'}`;

                    const stageSelect = document.getElementById('drawerStageSelect');
                    if (stageSelect) stageSelect.value = lead.pipeline_stage || 'new';

                    // Populate NBA Box
                    const nbaTitle = document.getElementById('drawerNbaTitle');
                    const nbaReason = document.getElementById('drawerNbaReason');
                    const nbaActions = document.getElementById('drawerNbaActions');

                    if (nbaTitle) nbaTitle.innerText = nba.title || 'Next Action';
                    if (nbaReason) nbaReason.innerText = nba.reason || '';

                    if (nbaActions) {
                        const cleanPhone = (lead.phone || '').replace(/[^\d+]/g, '');
                        nbaActions.innerHTML = `
                            ${cleanPhone ? `
                            <a href="tel:${cleanPhone}" class="brand-btn-primary brand-btn-sm" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px; height:30px; padding:0 14px;">
                                ðŸ“ž Call (${cleanPhone})

// ── initDashboard() is placed here (app boot entry point) ──
// It was originally between loadCampusTours() and startOnboardingWizard()
// BUG AREA: dashboard initialization order -> initDashboard()
        async function initDashboard() {
            if (!token) return;

            try {
                const resMe = await fetch('/v1/auth/me', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const meData = await resMe.json();

                if (meData.status !== 'success') {
                    handleSessionExpired('Session verification failed. Please log in.');
                    return;
                }

                // Smart Onboarding replaces the mandatory 16-step wizard
                // App container is shown directly, and any gaps are flagged via the notification badge.
                document.getElementById('authContainer').style.display = 'none';
                document.getElementById('appContainer').style.display = 'grid';

                const user = meData.data.user || {};
                currentUser = user;
                window.currentUser = user;
                const org = meData.data.organization || {};
                window.currentOrgProfile = org;
                const botToken = meData.data.bot_token;
                window.currentBotToken = botToken || '';

                updateAppIdentityUI(org, user);

                document.getElementById('userName').innerText = user.name || 'User';
                document.getElementById('userAvatar').innerText = user.name ? user.name.charAt(0).toUpperCase() : 'U';

                // User Role Badge Display & Sidebar Visibility Scoping
                const roleBadge = document.getElementById('userRoleBadge');
                const settingsSec = document.getElementById('settingsProfileSidebarSection');
                const isStaff = user.role === 'staff';

                if (settingsSec) {
                    settingsSec.style.display = isStaff ? 'none' : 'block';
                }

                if (roleBadge) {
                    const isSysAdmin = user.role === 'owner' || user.role === 'org_admin' || user.role === 'admin' || user.role === 'superadmin' || user.role === 'super_admin';
                    if (isSysAdmin) {
                        roleBadge.innerText = 'ðŸ›¡ï¸ Admin';
                        roleBadge.style.cssText = 'font-size: 9px; font-weight: 700; text-transform: uppercase; padding: 2px 6px; border-radius: 8px; background: rgba(99, 102, 241, 0.2); color: var(--brand-indigo-400); border: 1px solid rgba(99, 102, 241, 0.4); white-space: nowrap;';
                    } else {
                        roleBadge.innerText = 'ðŸ‘¤ Staff';
                        roleBadge.style.cssText = 'font-size: 9px; font-weight: 700; text-transform: uppercase; padding: 2px 6px; border-radius: 8px; background: rgba(56, 189, 248, 0.15); color: var(--brand-cyan-400); border: 1px solid rgba(56, 189, 248, 0.3); white-space: nowrap;';
                    }
                }

                const embedBox = document.getElementById('embedCodeBox');
                if (embedBox) embedBox.innerText = `<script src="https://edvora.chat/widget.js" data-bot-token="${botToken}" async><\/script>`;
                if (typeof updateMobileCodeSnippets === 'function') {
                    updateMobileCodeSnippets(botToken);
                }
                if (typeof syncOverviewEmbedSnippet === 'function') {
                    syncOverviewEmbedSnippet(botToken);
                }

                // Sync profile data if profile tab is active or preloaded
                if (typeof loadProfileData === 'function') {
                    loadProfileData();
                }

                // Fetch current readiness score for Overview banner & Knowledge Gap audit
                try {
                    fetch('/v1/onboarding/status', { headers: { 'Authorization': 'Bearer ' + token } })
                        .then(r => r.json())
                        .then(d => {
                            if (d.status === 'success') {
                                checkKnowledgeGapsAudit(d.data);

                                const bannerCard = document.getElementById('overviewReadinessCard');
                                const badge = document.getElementById('dashReadinessScoreBadge');
                                const desc = document.getElementById('dashReadinessDesc');
                                const openBtn = document.getElementById('dashOpenWizardBtn');
                                const org = d.data.organization || {};
                                const isCompleted = Boolean(d.data.is_completed || d.data.onboarding_completed || org.onboarding_completed);

                                if (isCompleted && d.data.readiness_score >= 80) {
                                    if (bannerCard) bannerCard.style.display = 'none';
                                } else {
                                    if (bannerCard) bannerCard.style.display = 'none';
                                    if (badge) {
                                        badge.innerText = `Knowledge Primer: ${d.data.readiness_score}%`;
                                        badge.style.background = 'rgba(245, 158, 11, 0.15)';
                                        badge.style.color = '#fbbf24';
                                        badge.style.borderColor = 'rgba(245, 158, 11, 0.3)';
                                    }
                                    if (desc) {
                                        desc.innerText = `AI Knowledge Base: ${(d.data.programs || []).length} program(s), ${d.data.stats?.knowledge_sources_count || 0} document(s) active. Review missing knowledge to optimize answers.`;
                                    }
                                    if (openBtn) {
                                        openBtn.innerText = `ðŸ” Review Knowledge Gaps`;
                                        openBtn.onclick = () => showKnowledgeGapsModal();
                                    }
                                }
                            }
                        }).catch(() => {});
                } catch(e) {}

                // Determine active tab from URL hash or localStorage
                let rawUrlHash = location.hash.replace('#', '').trim();
                let urlHash = rawUrlHash.split('?')[0];
                let initialTab = (urlHash && VALID_APP_TABS.includes(urlHash)) 
                    ? urlHash 
                    : (localStorage.getItem('edvora_active_tab') || 'overview');

                if (!VALID_APP_TABS.includes(initialTab)) {
                    initialTab = 'overview';
                }

                await switchNavTab(initialTab, null, false);
                if (initialTab === 'overview') { loadAnalytics(); }

                // Fetch initial callbacks summary for sidebar notification badge
                try {
                    fetch('/v1/callbacks?status=pending', { headers: { 'Authorization': 'Bearer ' + token } })
                        .then(r => r.json())
                        .then(d => {
                            if (d.status === 'success' && d.data && d.data.metrics) {
                                const badgeEl = document.getElementById('sidebarCallbackBadge');
                                if (badgeEl) {
                                    const count = d.data.metrics.pending || 0;
                                    if (count > 0) {
                                        badgeEl.innerText = count;
                                        badgeEl.style.display = 'inline-block';
                                    } else {
                                        badgeEl.style.display = 'none';
                                    }
                                }
                            }
                        }).catch(() => {});
                } catch(e) {}
            } catch (err) {
                console.error(err);
            }
        }

        // â”€â”€ PROGRESSIVE ONBOARDING ENGINE JAVASCRIPT LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let currentOnbStep = 1;
        let onbDataState = {
            organization: {},
            programs: [],
            chatbot: {},
            readiness_score: 0,
            readiness_label: 'Needs Setup',
            readiness_breakdown: {}
        };
        let onbChatConversationId = null;

        // Initialize chip and radio listeners
        document.addEventListener('click', (e) => {
            const radio = e.target.closest('.onb-radio-card');
            if (radio && radio.parentElement) {
                radio.parentElement.querySelectorAll('.onb-radio-card').forEach(el => el.classList.remove('active'));
                radio.classList.add('active');
            }

            const chip = e.target.closest('.onb-chip-toggle');
            if (chip) {
                chip.classList.toggle('active');
            }
        });


// ══════════════════════════════════════════════════════════════════════
// BOOT SEQUENCE - Wait for HTML partials before calling initDashboard()
// BUG AREA: app not initializing -> check edvora:partials-ready event below
// BUG AREA: "Cannot read properties of null" -> partial not loaded yet
// BUG AREA: token refresh on boot -> doRefreshToken() called here if needed
// ══════════════════════════════════════════════════════════════════════
(function() {
    async function boot() {
        if (!token && localStorage.getItem('edvora_refresh_token')) {
            await doRefreshToken();
        }
        if (token) {
            initDashboard();
        }
    }
    if (window._edvoraPartialsReady) {
        boot();
    } else {
        document.addEventListener('edvora:partials-ready', boot, { once: true });
    }
})();
