// ═══════════════════════════════════════════════════════════════════
// ONBOARDING.JS - Smart AI onboarding engine (SSE) + progressive wizard
// BUG AREAS:
//   Smart onboarding SSE  -> launchSmartOnboardingEngine() (EventSource/SSE handler)
//   Smart terminal feed   -> appendSmartTerminal() / updateSmartProgress()
//   Completion screen     -> showSmartCompletionScreen()
//   Fallback handler      -> fallbackSmartOnboarding()
//   Wizard step nav       -> goToOnbStep() / saveOnbStep()
//   Wizard chat preview   -> sendOnbChatMessage() / appendOnbBubble()
//   Program list          -> renderOnbProgramsList() / addProgramOnbRow()
//   Readiness audit       -> renderReadinessAudit()
//   Embed snippet         -> copyOnbSnippet() / emailWebmasterSnippet()
// LOADED BY: index.html via <script src="js/onboarding.js">
// ═══════════════════════════════════════════════════════════════════
        function launchSmartOnboardingEngine(authToken, botToken, websiteUrl) {
            smartOnboardingActiveBotToken = botToken || '';

            // Clean domain for display
            let displayDomain = websiteUrl;
            try {
                const u = new URL(websiteUrl.startsWith('http') ? websiteUrl : 'https://' + websiteUrl);
                displayDomain = u.hostname.replace(/^www\./, '');
            } catch(e) {}

            // Switch to smart onboarding overlay
            document.documentElement.classList.remove('auth-mode-signup');
            document.documentElement.classList.add('in-smart-onboarding');
            const smartContainer = document.getElementById('smartOnboardingContainer');
            if (smartContainer) smartContainer.style.display = 'flex';

            // Reset stages
            const discoveryView = document.getElementById('smartDiscoveryView');
            const completeView = document.getElementById('smartCompleteView');
            if (discoveryView) discoveryView.style.display = 'block';
            if (completeView) completeView.style.display = 'none';

            // Reset headers & counters
            const hdrWebsite = document.getElementById('smartHeaderWebsite');
            if (hdrWebsite) hdrWebsite.textContent = displayDomain;

            const activeTarget = document.getElementById('smartActiveTargetUrl');
            if (activeTarget) activeTarget.textContent = websiteUrl.startsWith('http') ? websiteUrl : 'https://' + websiteUrl;

            const pagesEl = document.getElementById('smartTelemetryPages');
            if (pagesEl) pagesEl.textContent = '0';

            const chipsCont = document.getElementById('smartLiveChipsContainer');
            if (chipsCont) {
                chipsCont.innerHTML = '<div id="smartLiveChipsPlaceholder" style="font-size: 12px; color: #475569; font-style: italic; padding: 4px 0;">Awaiting catalog extraction from pages...</div>';
            }
            const chipsCountEl = document.getElementById('smartLiveChipsCount');
            if (chipsCountEl) chipsCountEl.textContent = '0 items';
            smartLiveChipsTotal = 0;

            const deptsEl = document.getElementById('smartTelemetryDepts');
            if (deptsEl) deptsEl.textContent = '0';
            const progsEl = document.getElementById('smartTelemetryPrograms');
            if (progsEl) progsEl.textContent = '0';
            const ksEl = document.getElementById('smartTelemetryKnowledge');
            if (ksEl) ksEl.textContent = '0';

            updateSmartProgress(8, 'Connecting to institution server...');
            clearSmartTerminal();
            appendSmartTerminal('CONNECT', `Establishing secure crawler connection to ${displayDomain}...`, '#6366f1');

            // Wire Exit / Dashboard button
            const enterDashBtn = document.getElementById('smartEnterDashboardBtn');
            if (enterDashBtn) {
                enterDashBtn.onclick = () => {
                    closeSmartEventSource();
                    exitSmartOnboardingToDashboard();
                };
            }

            // Connect SSE
            closeSmartEventSource();
            const sseUrl = `/v1/onboarding/scrape-stream?auth_token=${encodeURIComponent(authToken)}`;
            
            try {
                smartEventSource = new EventSource(sseUrl);

                smartEventSource.onmessage = (event) => {
                    if (!event.data) return;
                    try {
                        const payload = JSON.parse(event.data);
                        handleSmartOnboardingEvent(payload, displayDomain);
                    } catch(pe) {
                        console.error('[SmartOnboarding] Failed to parse SSE event:', pe, event.data);
                    }
                };

                smartEventSource.onerror = (err) => {
                    console.warn('[SmartOnboarding] SSE connection closed or encountered error:', err);
                    closeSmartEventSource();
                    if (completeView && completeView.style.display === 'block') return;
                    fallbackSmartOnboarding(authToken, displayDomain);
                };
            } catch(e) {
                console.error('[SmartOnboarding] Failed to initialize EventSource:', e);
                fallbackSmartOnboarding(authToken, displayDomain);
            }
        }

        function handleSmartOnboardingEvent(payload, domain) {
            const event = payload.event;
            const msg = payload.message || '';
            const pct = typeof payload.pct === 'number' ? payload.pct : null;

            if (pct !== null) {
                updateSmartProgress(pct, msg);
            }

            switch(event) {
                case 'connecting':
                    appendSmartTerminal('CONNECT', msg, '#6366f1');
                    break;
                case 'homepage_fetched':
                    appendSmartTerminal('HTML', msg, '#34d399');
                    break;
                case 'links_discovered':
                    appendSmartTerminal('SPIDER', msg, '#818cf8');
                    break;
                case 'page_inspected':
                    appendSmartTerminal('PAGES', msg, '#38bdf8');
                    const pagesEl = document.getElementById('smartTelemetryPages');
                    if (pagesEl) {
                        pagesEl.textContent = payload.pages_crawled || 1;
                    }
                    break;
                case 'catalog_found':
                    appendSmartTerminal('CATALOG', msg, '#34d399');
                    break;
                case 'subpages_fetched':
                    appendSmartTerminal('PAGES', msg, '#34d399');
                    break;
                case 'ai_analyzing':
                    appendSmartTerminal('AI-LLM', msg, '#f59e0b');
                    break;
                case 'logo_saved':
                    appendSmartTerminal('ASSET', msg, '#10b981');
                    if (payload.logo_url) {
                        const logoWrap = document.getElementById('smartTelemetryLogoWrap');
                        const logoImg = document.getElementById('smartTelemetryLogoImg');
                        if (logoWrap && logoImg) {
                            logoImg.src = payload.logo_url;
                            logoWrap.style.display = 'inline-flex';
                        }
                    }
                    break;
                case 'org_updated':
                    appendSmartTerminal('ENTITY', msg, '#38bdf8');
                    break;
                case 'saving_departments':
                    appendSmartTerminal('DEPT', msg, '#818cf8');
                    break;
                case 'dept_found':
                    appendSmartTerminal('DEPT', msg, '#818cf8');
                    if (payload.departments_count) {
                        const dEl = document.getElementById('smartTelemetryDepts');
                        if (dEl) dEl.textContent = payload.departments_count;
                    }
                    if (payload.name) {
                        addSmartLiveChip('dept', payload.name, payload.icon || 'ðŸ«');
                    }
                    break;
                case 'course_found':
                    appendSmartTerminal('COURSE', msg, '#818cf8');
                    if (payload.course_name) {
                        addSmartLiveChip('dept', payload.course_name, 'ðŸ“–');
                    }
                    break;
                case 'departments_written':
                    appendSmartTerminal('DEPT', msg, '#10b981');
                    const deptsCount = payload.departments_count || 0;
                    const dEl = document.getElementById('smartTelemetryDepts');
                    if (dEl) dEl.textContent = deptsCount;
                    break;
                case 'no_departments':
                    appendSmartTerminal('NOTICE', msg, '#fbbf24');
                    break;
                case 'saving_programs':
                    appendSmartTerminal('ACADEMIC', msg, '#34d399');
                    break;
                case 'program_found':
                    appendSmartTerminal('PROGRAM', msg, '#34d399');
                    if (payload.programs_count) {
                        const pEl = document.getElementById('smartTelemetryPrograms');
                        if (pEl) pEl.textContent = payload.programs_count;
                    }
                    if (payload.name) {
                        addSmartLiveChip('program', payload.name, 'ðŸŽ“');
                    }
                    break;
                case 'programs_written':
                    appendSmartTerminal('ACADEMIC', msg, '#10b981');
                    const progsCount = payload.programs_count || 0;
                    const pEl = document.getElementById('smartTelemetryPrograms');
                    if (pEl) pEl.textContent = progsCount;
                    break;
                case 'no_programs':
                    appendSmartTerminal('NOTICE', msg, '#fbbf24');
                    break;
                case 'saving_knowledge':
                    appendSmartTerminal('INGEST', msg, '#f59e0b');
                    break;
                case 'cluster_found':
                    appendSmartTerminal('KNOWLEDGE', msg, '#f59e0b');
                    if (payload.knowledge_count) {
                        const kEl = document.getElementById('smartTelemetryKnowledge');
                        if (kEl) kEl.textContent = payload.knowledge_count;
                    }
                    if (payload.title) {
                        addSmartLiveChip('knowledge', payload.title, 'ðŸ“„');
                    }
                    break;
                case 'knowledge_saved':
                    appendSmartTerminal('INGEST', msg, '#10b981');
                    const ksCount = payload.knowledge_count || 0;
                    const kEl = document.getElementById('smartTelemetryKnowledge');
                    if (kEl) kEl.textContent = ksCount;
                    break;
                case 'configuring_chatbot':
                    appendSmartTerminal('BOT', msg, '#6366f1');
                    break;
                case 'complete':
                case 'already_complete':
                    appendSmartTerminal('READY', msg, '#10b981');
                    updateSmartProgress(100, 'âœ¨ All verified colleges, degrees, and facts indexed successfully!');
                    closeSmartEventSource();
                    setTimeout(() => {
                        showSmartCompletionScreen(payload, domain, false);
                    }, 2200);
                    break;
                case 'scrape_failed':
                    appendSmartTerminal('ALERT', msg, '#ef4444');
                    closeSmartEventSource();
                    setTimeout(() => {
                        showSmartCompletionScreen(payload, domain, true);
                    }, 1500);
                    break;
                case 'error':
                    appendSmartTerminal('ERROR', msg, '#ef4444');
                    closeSmartEventSource();
                    setTimeout(() => {
                        showSmartCompletionScreen(payload, domain, true);
                    }, 1500);
                    break;
                default:
                    appendSmartTerminal('INFO', msg, '#94a3b8');
                    break;
            }
        }

        function updateSmartProgress(pct, statusText) {
            const fill = document.getElementById('smartProgressBarFill');
            const pctEl = document.getElementById('smartProgressPct');
            const statusEl = document.getElementById('smartStatusText');
            if (fill) fill.style.width = Math.min(100, Math.max(5, pct)) + '%';
            if (pctEl) pctEl.textContent = Math.min(100, Math.max(5, pct)) + '%';
            if (statusEl && statusText) statusEl.textContent = statusText;
        }

        function clearSmartTerminal() {
            const term = document.getElementById('smartTerminalLog');
            if (term) term.innerHTML = '';
        }

        function appendSmartTerminal(tag, message, tagColor = '#6366f1') {
            const term = document.getElementById('smartTerminalLog');
            if (!term) return;
            const time = new Date().toTimeString().split(' ')[0];
            const row = document.createElement('div');
            row.style.wordBreak = 'break-word';
            row.innerHTML = `<span style="color:#64748b;">${time}</span> <span style="color:${tagColor}; font-weight:700;">[${tag}]</span> <span>${escapeHtml(message)}</span>`;
            term.appendChild(row);
            term.scrollTop = term.scrollHeight;
        }

        function closeSmartEventSource() {
            if (smartEventSource) {
                try { smartEventSource.close(); } catch(e) {}
                smartEventSource = null;
            }
        }

        function toggleSmartCompletionLogs() {
            const logSection = document.getElementById('smartCompletedLogsSection');
            if (!logSection) return;
            const isHidden = logSection.style.display === 'none';
            logSection.style.display = isHidden ? 'block' : 'none';
            const btn = document.getElementById('smartToggleLogsBtn');
            if (btn) btn.textContent = isHidden ? 'â–² Hide Discovery Terminal Log' : 'ðŸ“‹ View Full Discovery Terminal Log';
        }

        function showSmartCompletionScreen(payload, domain, hasErrors = false) {
            const discoveryView = document.getElementById('smartDiscoveryView');
            const completeView = document.getElementById('smartCompleteView');
            if (discoveryView) discoveryView.style.display = 'none';
            if (completeView) completeView.style.display = 'block';

            // Archive terminal log so user can review it
            const activeTerm = document.getElementById('smartTerminalLog');
            const completedLogsBody = document.getElementById('smartCompletedLogsBody');
            if (activeTerm && completedLogsBody) {
                completedLogsBody.innerHTML = activeTerm.innerHTML;
            }

            const summary = payload.summary || {};
            const botToken = payload.bot_token || smartOnboardingActiveBotToken || '';
            smartOnboardingActiveBotToken = botToken;

            // Header state
            const badge = document.getElementById('smartHeaderBadge');
            const badgeText = document.getElementById('smartHeaderBadgeText');
            if (badge) {
                badge.style.background = hasErrors ? 'rgba(239, 68, 68, 0.15)' : 'rgba(52, 211, 153, 0.15)';
                badge.style.borderColor = hasErrors ? 'rgba(239, 68, 68, 0.3)' : 'rgba(52, 211, 153, 0.3)';
                badge.style.color = hasErrors ? '#f87171' : '#34d399';
            }
            if (badgeText) badgeText.textContent = hasErrors ? 'Setup Completed (Starter Mode)' : 'Bot Primed & Active';

            // Complete card details
            const instName = payload.institution_name || domain;
            const titleEl = document.getElementById('smartCompleteTitle');
            const subEl = document.getElementById('smartCompleteSubtitle');
            const iconEl = document.getElementById('smartCompleteIcon');
            const finalInstEl = document.getElementById('smartFinalInstName');
            const finalWebEl = document.getElementById('smartFinalWebsite');

            if (finalInstEl) finalInstEl.textContent = instName;
            if (finalWebEl) finalWebEl.textContent = domain;

            if (hasErrors) {
                if (iconEl) iconEl.textContent = 'âš¡';
                if (titleEl) titleEl.textContent = 'Starter Workspace Initialized!';
                if (subEl) subEl.textContent = `A workspace was set up for ${instName}. You can upload official brochures or add degree programs directly in the console.`;
            } else {
                if (iconEl) iconEl.textContent = 'ðŸŽ‰';
                if (titleEl) titleEl.textContent = 'Your AI Student Assistant is Ready!';
                if (subEl) subEl.textContent = `Verified details for ${instName} were distilled into your production database.`;
            }

            // Logo
            if (payload.logo_url) {
                const finalLogoImg = document.getElementById('smartFinalLogoImg');
                const finalLogoFallback = document.getElementById('smartFinalLogoFallback');
                if (finalLogoImg && finalLogoFallback) {
                    finalLogoImg.src = payload.logo_url;
                    finalLogoImg.style.display = 'block';
                    finalLogoFallback.style.display = 'none';
                }
            }

            // Summary metrics
            const dCount = summary.departments !== undefined ? summary.departments : (parseInt(document.getElementById('smartTelemetryDepts')?.textContent) || 0);
            const pCount = summary.programs !== undefined ? summary.programs : (summary.courses !== undefined ? summary.courses : (parseInt(document.getElementById('smartTelemetryPrograms')?.textContent) || 0));
            const kCount = summary.knowledge_sources !== undefined ? summary.knowledge_sources : (parseInt(document.getElementById('smartTelemetryKnowledge')?.textContent) || 0);

            const sumDepts = document.getElementById('smartSummaryDepts');
            const sumPrograms = document.getElementById('smartSummaryPrograms');
            const sumKnowledge = document.getElementById('smartSummaryKnowledge');
            if (sumDepts) sumDepts.textContent = dCount;
            if (sumPrograms) sumPrograms.textContent = pCount;
            if (sumKnowledge) sumKnowledge.textContent = kCount;



            // Test bot button
            const testBtn = document.getElementById('smartTestBotBtn');
            if (testBtn) {
                testBtn.onclick = () => {
                    const testUrl = botToken ? `/test_chat.html?token=${encodeURIComponent(botToken)}` : '/test';
                    window.open(testUrl, '_blank');
                };
            }
        }

        async function fallbackSmartOnboarding(authToken, domain) {
            try {
                appendSmartTerminal('FALLBACK', 'Finalizing onboarding via direct pipeline...', '#f59e0b');
                const res = await fetch('/v1/onboarding/smart-complete', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + authToken,
                        'Content-Type': 'application/json'
                    }
                });
                const d = await res.json();
                if (d.status === 'success' && d.data) {
                    showSmartCompletionScreen(d.data, domain, false);
                } else {
                    showSmartCompletionScreen({ institution_name: domain }, domain, true);
                }
            } catch(e) {
                console.error('[SmartOnboarding] Fallback failed:', e);
                showSmartCompletionScreen({ institution_name: domain }, domain, true);
            }
        }

        function exitSmartOnboardingToDashboard() {
            closeSmartEventSource();
            document.documentElement.classList.remove('in-smart-onboarding');
            const container = document.getElementById('smartOnboardingContainer');
            if (container) container.style.display = 'none';
            initDashboard();
        }

        // Logout Event
        document.getElementById('logoutBtn').onclick = () => {
            localStorage.removeItem('edvora_token');
            localStorage.removeItem('edvora_refresh_token');
            localStorage.removeItem('edvora_org_name');
            localStorage.removeItem('edvora_active_tab');
            localStorage.removeItem('edvora_studio_subtab');
            document.documentElement.classList.remove('has-auth-token');
            token = null;
            currentUser = null;
            currentDepartments = [];
            location.hash = '';
            location.reload();
        };
        window.currentGlobalTimeWindow = '30d';
        window.overviewCustomStartDate = '';
        window.overviewCustomEndDate = '';

        window.leadsActiveWindow = '30d';
        window.leadsCustomStartDate = '';
        window.leadsCustomEndDate = '';

        const windowProfiles = {
            '24h': {
                label: 'Last 24 Hours',
                compare: 'vs previous 24 hours',
                v2l: '12.4%',
                v2lDelta: 'â†‘ 0.6%',
                ql: 38,
                qlDelta: 'â†“ 20.8%',
                ai: 9,
                aiDelta: 'â†“ 52.6%',
                leads: 92,
                callbacks: 28,
                tours: 14,
                scholarships: 46,
                magnets: 72
            },
            '7d': {
                label: 'Last 7 Days',
                compare: 'vs previous 7 days',
                v2l: '11.9%',
                v2lDelta: 'â†‘ 0.7%',
                ql: 142,
                qlDelta: 'â†“ 48.2%',
                ai: 28,
                aiDelta: 'â†“ 57.6%',
                leads: 384,
                callbacks: 118,
                tours: 52,
                scholarships: 185,
                magnets: 280
            },
            '30d': {
                label: 'Last 30 Days',
                compare: 'vs previous 30 days',
                v2l: '11.0%',
                v2lDelta: 'â†‘ 2.3%',
                ql: 486,
                qlDelta: 'â†‘ 18.8%',
                ai: 94,
                aiDelta: 'â†‘ 32.4%',
                leads: 1284,
                callbacks: 412,
                tours: 186,
                scholarships: 628,
                magnets: 954
            },
            '3m': {
                label: 'Last 3 Months',
                compare: 'vs previous 3 months',
                v2l: '11.2%',
                v2lDelta: 'â†‘ 1.8%',
                ql: 720,
                qlDelta: 'â†‘ 15.4%',
                ai: 148,
                aiDelta: 'â†‘ 24.1%',
                leads: 2150,
                callbacks: 690,
                tours: 310,
                scholarships: 980,
                magnets: 1540
            },
            '6m': {
                label: 'Last 6 Months',
                compare: 'vs previous 6 months',
                v2l: '10.5%',
                v2lDelta: 'â†‘ 2.1%',
                ql: 950,
                qlDelta: 'â†‘ 18.2%',
                ai: 190,
                aiDelta: 'â†‘ 28.5%',
                leads: 2366,
                callbacks: 777,
                tours: 348,
                scholarships: 1120,
                magnets: 1820
            },
            'all': {
                label: 'All Time',
                compare: 'vs historic baseline',
                v2l: '9.8%',
                v2lDelta: 'Historic',
                ql: 950,
                qlDelta: 'Historic',
                ai: 190,
                aiDelta: 'Historic',
                leads: 2366,
                callbacks: 777,
                tours: 348,
                scholarships: 1120,
                magnets: 1820
            }
        };

        function applyTimeWindowUI(win, customLabel) {
            const p = windowProfiles[win] || windowProfiles['30d'];
            window.currentGlobalTimeWindow = win;

            // 1. Sync header buttons
            ['24h', '7d', '30d', '3m', '6m', 'all', 'custom'].forEach(w => {
                const btn = document.getElementById(`timeBtn${w}`);
                if (btn) {
                    if (w === win) {
                        btn.classList.add('active');
                        btn.style.background = '#063D3B';
                        btn.style.color = '#C8FF63';
                        btn.style.fontWeight = '700';
                    } else {
                        btn.classList.remove('active');
                        btn.style.background = 'transparent';
                        btn.style.color = '#648781';
                        btn.style.fontWeight = '600';
                    }
                }
            });

            const ind = document.getElementById('overviewActiveRangeIndicator');
            if (ind) {
                ind.innerText = `Active Window: ${customLabel || p.label}`;
            }

            // 2. Sync select element
            const perfSelect = document.getElementById('perfTimeSelect');
            if (perfSelect) perfSelect.value = win;
        }

        window.setGlobalTimeWindow = function(win) {
            const dateBar = document.getElementById('overviewCustomDatePickerBar');
            if (dateBar && win !== 'custom') {
                dateBar.style.display = 'none';
            }
            applyTimeWindowUI(win);
            if (typeof token !== 'undefined' && token) {
                loadAnalytics(win);
            }
        };

        window.toggleOverviewCustomDatePicker = function() {
            const dateBar = document.getElementById('overviewCustomDatePickerBar');
            if (!dateBar) return;
            const isHidden = dateBar.style.display === 'none' || !dateBar.style.display;
            dateBar.style.display = isHidden ? 'flex' : 'none';
            if (isHidden) {
                const now = new Date();
                const past = new Date(Date.now() - 30 * 86400000);
                const sEl = document.getElementById('overviewCustomStartDate');
                const eEl = document.getElementById('overviewCustomEndDate');
                if (sEl && !sEl.value) sEl.value = past.toISOString().slice(0, 10);
                if (eEl && !eEl.value) eEl.value = now.toISOString().slice(0, 10);
            }
        };

        window.applyOverviewCustomDateRange = function() {
            const sEl = document.getElementById('overviewCustomStartDate');
            const eEl = document.getElementById('overviewCustomEndDate');
            if (!sEl || !eEl || !sEl.value || !eEl.value) {
                showToast('Please select both start and end dates.', 'warning');
                return;
            }
            window.overviewCustomStartDate = sEl.value;
            window.overviewCustomEndDate = eEl.value;
            applyTimeWindowUI('custom', `${sEl.value} â€“ ${eEl.value}`);
            loadAnalytics('custom', sEl.value, eEl.value);
        };

        // Keyboard navigation for unified action cards
        document.addEventListener('keydown', function(e) {
            if ((e.key === 'Enter' || e.key === ' ') && document.activeElement && document.activeElement.classList.contains('unified-action-col')) {
                e.preventDefault();
                document.activeElement.click();
            }
        });

        async function loadAnalytics(targetWindow, startDate, endDate) {
            if (!token) return;
            const activeWin = targetWindow || window.currentGlobalTimeWindow || '30d';
            window.currentGlobalTimeWindow = activeWin;
            try {
                let url = `/v1/analytics/summary?window=${encodeURIComponent(activeWin)}`;
                if (activeWin === 'custom' && (startDate || window.overviewCustomStartDate)) {
                    const s = startDate || window.overviewCustomStartDate;
                    const e = endDate || window.overviewCustomEndDate;
                    url += `&start_date=${encodeURIComponent(s)}&end_date=${encodeURIComponent(e)}`;
                }
                const res = await fetch(url, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const stats = data.data;
                    const quotas = stats.quotas || {};
                    const qDocs = quotas.documents || { limit: 50, used: 48, remaining: 2, percentage: 96 };
                    const qDepts = quotas.departments || { limit: 5, used: 4, remaining: 1, percentage: 80 };
                    const qTeams = quotas.teams || { limit: 10, used: 8, remaining: 2, percentage: 80 };
                    const qBots = quotas.chatbots || { limit: 3, used: 2, remaining: 1, percentage: 66 };
                    const actions = stats.action_metrics || {};
                    const perf = stats.admissions_performance || {};

                    // Top Header Bar
                    const nameEl = document.getElementById('overviewCollegeName');
                    if (nameEl) {
                        const org = window.currentOrgName || '';
                        if (org && !org.toLowerCase().includes('widget') && !org.toLowerCase().includes('landing page')) {
                            nameEl.innerText = org;
                        } else {
                            nameEl.innerText = 'Your Institution';
                        }
                    }
                    const campusUnitsCountEl = document.getElementById('hdrCampusUnitsCount');
                    if (campusUnitsCountEl) {
                        const units = stats.total_departments > 0 ? stats.total_departments : 4;
                        campusUnitsCountEl.innerText = `${units} Campus Units`;
                    }

                    // Dynamic Repurposed Entity Cards Data (Department, Courses, Campus)
                    const em = stats.entity_metrics || {};
                    const deptsData = em.departments || {};
                    const coursesData = em.courses || {};
                    const campusesData = em.campuses || {};

                    // 1. Department Card
                    const deptTotal = deptsData.active !== undefined ? deptsData.active : (stats.total_departments || 8);
                    const elDeptCount = document.getElementById('statDeptCount');
                    if (elDeptCount) elDeptCount.innerText = deptTotal;

                    const elDeptBadge = document.getElementById('statDeptActiveBadge');
                    if (elDeptBadge) elDeptBadge.innerText = `${deptTotal} Active`;

                    const elDeptChips = document.getElementById('statDeptChips');
                    if (elDeptChips && stats.department_preview && stats.department_preview.length > 0) {
                        const previews = stats.department_preview;
                        const firstTwo = previews.slice(0, 2);
                        const remaining = Math.max(0, deptTotal - 2);
                        let chipsHtml = firstTwo.map(d => {
                            const shortName = (d.name || '').replace(/^Department of |^College of /i, '').trim();
                            return `<span class="ops-dept-chip" title="${escapeHtml(d.name)}">${escapeHtml(shortName)}</span>`;
                        }).join('');
                        if (remaining > 0) {
                            chipsHtml += `<span class="ops-dept-chip-more">+${remaining} more</span>`;
                        }
                        elDeptChips.innerHTML = chipsHtml;
                    }

                    // 2. Academic Programs Card
                    const courseTotal = coursesData.total !== undefined ? coursesData.total : 0;
                    const elCourseCount = document.getElementById('statCoursesCount');
                    if (elCourseCount) elCourseCount.innerText = courseTotal;

                    const openCount = coursesData.admissions_open !== undefined ? coursesData.admissions_open : courseTotal;
                    const elCoursesOpenPct = document.getElementById('statCoursesOpenPct');
                    if (elCoursesOpenPct) {
                        const pct = courseTotal > 0 ? Math.round((openCount / courseTotal) * 100) : 100;
                        elCoursesOpenPct.innerText = `${pct}% Enrolling`;
                    }

                    const cBreak = coursesData.breakdown || { undergraduate: 0, postgraduate: 0, doctoral: 0, other: 0 };
                    const ug = cBreak.undergraduate || 0;
                    const pg = cBreak.postgraduate || 0;
                    const doc = cBreak.doctoral || 0;
                    const other = cBreak.other || 0;
                    const cSum = (ug + pg + doc + other) || courseTotal || 1;

                    const segUg = document.getElementById('courseSegUg');
                    if (segUg) {
                        segUg.style.width = `${Math.round((ug / cSum) * 100)}%`;
                        segUg.title = `${ug} Undergraduate Programs`;
                    }
                    const segPg = document.getElementById('courseSegPg');
                    if (segPg) {
                        segPg.style.width = `${Math.round((pg / cSum) * 100)}%`;
                        segPg.title = `${pg} Postgraduate Programs`;
                    }
                    const segDoc = document.getElementById('courseSegDoc');
                    if (segDoc) {
                        segDoc.style.width = `${Math.round((doc / cSum) * 100)}%`;
                        segDoc.title = `${doc} Doctoral Programs`;
                    }
                    const segOther = document.getElementById('courseSegOther');
                    if (segOther) {
                        segOther.style.width = `${Math.round((other / cSum) * 100)}%`;
                        segOther.title = `${other} Other / Certificate Programs`;
                    }

                    const lblUg = document.getElementById('lblUgCount');
                    if (lblUg) lblUg.innerText = `${ug} UG`;
                    const lblPg = document.getElementById('lblPgCount');
                    if (lblPg) lblPg.innerText = `${pg} PG`;
                    const lblDoc = document.getElementById('lblDocCount');
                    if (lblDoc) lblDoc.innerText = `${doc} PhD`;
                    const lblOther = document.getElementById('lblOtherCount');
                    if (lblOther) lblOther.innerText = `${other} Cert`;

                    // 3. Campus Card
                    const campusTotal = campusesData.total !== undefined ? campusesData.total : 1;
                    const elCampusCount = document.getElementById('statCampusCount');
                    if (elCampusCount) elCampusCount.innerText = campusTotal;

                    const elCampusUnitLbl = document.getElementById('statCampusUnitLbl');
                    if (elCampusUnitLbl) elCampusUnitLbl.innerText = campusTotal === 1 ? 'Campus Site' : 'Campus Sites';

                    const elCampusBadge = document.getElementById('statCampusStatusBadge');
                    if (elCampusBadge) elCampusBadge.innerText = (campusesData.active || campusTotal) + ' Active';

                    const prim = campusesData.primary || {};
                    const areaStr = prim.campus_area || '868 Acres';
                    const elCampusArea = document.getElementById('statCampusArea');
                    if (elCampusArea) elCampusArea.innerText = areaStr;

                    const cityState = [prim.city, prim.state].filter(Boolean).join(', ') || 'Normal, Alabama';
                    const elCampusCity = document.getElementById('statCampusCityState');
                    if (elCampusCity) elCampusCity.innerText = cityState;

                    const elCampusPrimName = document.getElementById('statCampusPrimaryName');
                    if (elCampusPrimName && prim.name) {
                        elCampusPrimName.title = prim.name;
                    }

                    // Backward compatible perf handlers (if old elements exist)
                    if (perf.visitor_to_lead) {
                        const statV2L = document.getElementById('statVisitorToLead');
                        if (statV2L) statV2L.innerText = typeof perf.visitor_to_lead === 'number' ? `${perf.visitor_to_lead}%` : (perf.visitor_to_lead.includes('%') ? perf.visitor_to_lead : `${perf.visitor_to_lead}%`);
                    }
                    if (perf.qualified_leads !== undefined) {
                        const statQL = document.getElementById('statQualifiedLeads');
                        if (statQL) statQL.innerText = Number(perf.qualified_leads).toLocaleString();
                    }
                    if (perf.application_intents !== undefined) {
                        const statAI = document.getElementById('statAppIntents');
                        if (statAI) statAI.innerText = Number(perf.application_intents).toLocaleString();
                    }
                    if (actions.leads_captured !== undefined) {
                        const actLeads = document.getElementById('statActionLeads');
                        if (actLeads) actLeads.innerText = Number(actions.leads_captured).toLocaleString();
                    }
                    if (actions.callbacks_booked !== undefined) {
                        const actCallbacks = document.getElementById('statActionCallbacks');
                        if (actCallbacks) actCallbacks.innerText = Number(actions.callbacks_booked).toLocaleString();
                    }
                    if (actions.campus_tours !== undefined) {
                        const actTours = document.getElementById('statActionTours');
                        if (actTours) actTours.innerText = Number(actions.campus_tours).toLocaleString();
                    }
                    if (actions.scholarship_interest !== undefined) {
                        const actScholarships = document.getElementById('statActionScholarships');
                        if (actScholarships) actScholarships.innerText = Number(actions.scholarship_interest).toLocaleString();
                    }
                    if (actions.lead_magnets_sent !== undefined) {
                        const actMagnets = document.getElementById('statActionLeadMagnets');
                        if (actMagnets) actMagnets.innerText = Number(actions.lead_magnets_sent).toLocaleString();
                    }

                    // Window label updates on action tile subtexts
                    if (actions.window_label) {
                        const sLeads = document.getElementById('statActionLeadsSub');
                        if (sLeads) sLeads.innerText = `${actions.window_label} â€¢ Verified Contact`;
                        const sCallbacks = document.getElementById('statActionCallbacksSub');
                        if (sCallbacks) sCallbacks.innerText = `${actions.window_label} â€¢ Counselor Calls`;
                        const sTours = document.getElementById('statActionToursSub');
                        if (sTours) sTours.innerText = `${actions.window_label} â€¢ Visits Scheduled`;
                        const sSchol = document.getElementById('statActionScholarshipsSub');
                        if (sSchol) sSchol.innerText = `${actions.window_label} â€¢ Aid Inquiries`;
                        const sMagnets = document.getElementById('statActionLeadMagnetsSub');
                        if (sMagnets) sMagnets.innerText = `${actions.window_label} â€¢ Dispatched Guides`;
                    }

                    // 3. Operational Card 1: Documents & Knowledge Base
                    const docUsed = qDocs.used !== undefined ? qDocs.used : 48;
                    const docLim = (qDocs.limit !== undefined && qDocs.limit > 0) ? qDocs.limit : 50;
                    const docRem = qDocs.remaining !== undefined ? qDocs.remaining : Math.max(0, docLim - docUsed);
                    const docPct = Math.min(100, Math.round((docUsed / docLim) * 100));

                    const qDocsBadge = document.getElementById('quotaDocsBadge');
                    if (qDocsBadge) qDocsBadge.innerText = `${docPct}% Allocated`;
                    const qDocsLine = document.getElementById('quotaDocsLine');
                    if (qDocsLine) qDocsLine.innerText = `Quota: ${docLim} limit â€¢ ${docUsed} used â€¢ ${docRem} remaining`;
                    const qDocsProgress = document.getElementById('quotaDocsProgress');
                    if (qDocsProgress) qDocsProgress.style.width = `${docPct}%`;
                    const qDocsInStore = document.getElementById('quotaDocsInStore');
                    if (qDocsInStore) qDocsInStore.innerText = `${docUsed} Documents in Store`;
                    const qDocsSlotsRem = document.getElementById('quotaDocsSlotsRemaining');
                    if (qDocsSlotsRem) qDocsSlotsRem.innerText = `${docRem} Slots Remaining`;

                    const kb = stats.knowledge_breakdown || {};
                    const kbActive = kb.active !== undefined ? kb.active : 40;
                    const kbExpiring = kb.expiring_soon !== undefined ? kb.expiring_soon : 6;
                    const kbExpired = kb.expired !== undefined ? kb.expired : 2;

                    const statActive = document.getElementById('statDocsActive');
                    if (statActive) statActive.innerText = kbActive;
                    const statExpiring = document.getElementById('statDocsExpiring');
                    if (statExpiring) statExpiring.innerText = kbExpiring;
                    const statExpired = document.getElementById('statDocsExpired');
                    if (statExpired) statExpired.innerText = kbExpired;

                    const barActive = document.getElementById('docBarActive');
                    if (barActive) {
                        barActive.style.width = `${Math.round((kbActive / docLim) * 100)}%`;
                        barActive.title = `${kbActive} Active Documents`;
                    }
                    const barExpiring = document.getElementById('docBarExpiring');
                    if (barExpiring) {
                        barExpiring.style.width = `${Math.round((kbExpiring / docLim) * 100)}%`;
                        barExpiring.title = `${kbExpiring} Expiring Soon`;
                    }
                    const barExpired = document.getElementById('docBarExpired');
                    if (barExpired) {
                        barExpired.style.width = `${Math.round((kbExpired / docLim) * 100)}%`;
                        barExpired.title = `${kbExpired} Expired`;
                    }

                    // 3. Operational Card 2: Teams & Counselor Seats + Departments
                    const teamUsed = qTeams.used !== undefined ? qTeams.used : 8;
                    const teamLim = (qTeams.limit !== undefined && qTeams.limit > 0) ? qTeams.limit : 10;
                    const teamRem = qTeams.remaining !== undefined ? qTeams.remaining : Math.max(0, teamLim - teamUsed);
                    const teamPct = Math.min(100, Math.round((teamUsed / teamLim) * 100));

                    const qTeamsBadge = document.getElementById('quotaTeamsBadge');
                    if (qTeamsBadge) qTeamsBadge.innerText = `${teamUsed} / ${teamLim} Active`;
                    const qTeamsLine = document.getElementById('quotaTeamsLine');
                    if (qTeamsLine) qTeamsLine.innerText = `Quota: ${teamLim} seats â€¢ ${teamUsed} active â€¢ ${teamRem} remaining`;
                    const qTeamsProgress = document.getElementById('quotaTeamsProgress');
                    if (qTeamsProgress) qTeamsProgress.style.width = `${teamPct}%`;
                    const qTeamsAssigned = document.getElementById('quotaTeamsAssigned');
                    if (qTeamsAssigned) qTeamsAssigned.innerText = `${teamUsed} Staff Assigned`;
                    const qTeamsSlotsRem = document.getElementById('quotaTeamsSlotsRemaining');
                    if (qTeamsSlotsRem) qTeamsSlotsRem.innerText = `${teamRem} Seats Available`;
                    const footerStaffCountText = document.getElementById('footerStaffCountText');
                    if (footerStaffCountText) footerStaffCountText.innerText = `${teamUsed} Staff`;

                    // Department Quota strip in Teams Card
                    const deptUsed = qDepts.used !== undefined ? qDepts.used : 4;
                    const deptLim = (qDepts.limit !== undefined && qDepts.limit > 0) ? qDepts.limit : 5;
                    const deptRem = qDepts.remaining !== undefined ? qDepts.remaining : Math.max(0, deptLim - deptUsed);
                    const qDeptsLine = document.getElementById('quotaDeptsLine');
                    if (qDeptsLine) qDeptsLine.innerText = `${deptUsed} Campus Departments`;

                    // Render Staff Roster Preview
                    const rosterStaffList = document.getElementById('rosterStaffList');
                    if (rosterStaffList && stats.staff_preview && stats.staff_preview.length > 0) {
                        const avatarColors = ['#063D3B', '#0284C7', '#D97706', '#7C3AED', '#047857', '#DC2626'];
                        rosterStaffList.innerHTML = stats.staff_preview.slice(0, 3).map((user, idx) => {
                            const initials = (user.name || 'ST').split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
                            const roleLabel = (user.role || 'staff').replace('_', ' ').toUpperCase();
                            const color = avatarColors[idx % avatarColors.length];
                            return `
                                <div class="ops-team-row">
                                  <div style="display: flex; align-items: center; gap: 8px;">
                                    <div class="ops-team-avatar" style="background:${color}; color:#fff;">${initials}</div>
                                    <div>
                                      <div style="font-weight: 700; color: #063D3B;">${escapeHtml(user.name)}</div>
                                      <div style="font-size: 9.5px; color: #648781;">${escapeHtml(roleLabel)} &bull; ${escapeHtml(user.email || '')}</div>
                                    </div>
                                  </div>
                                  <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 9.5px; font-weight: 700; color: #047857; background: #ECFDF5; padding: 2px 7px; border-radius: 4px;"><span style="width: 5px; height: 5px; border-radius: 50%; background: #047857;"></span>Active</span>
                                </div>
                            `;
                        }).join('');
                    }

                    // 3. Operational Card 3: Chatbots & Embed Code
                    const botUsed = qBots.used !== undefined ? qBots.used : 2;
                    const botLim = (qBots.limit !== undefined && qBots.limit > 0) ? qBots.limit : 3;
                    const botRem = qBots.remaining !== undefined ? qBots.remaining : Math.max(0, botLim - botUsed);

                    const qBotsBadge = document.getElementById('quotaChatbotsBadge');
                    if (qBotsBadge) qBotsBadge.innerText = 'CDN v2.4 Live';
                    const qBotsLine = document.getElementById('quotaChatbotsLine');
                    if (qBotsLine) qBotsLine.innerText = `Chatbot Quota: ${botLim} bots â€¢ ${botUsed} active â€¢ ${botRem} remaining`;
                    const qBotsProgress = document.getElementById('quotaChatbotsProgress');
                    if (qBotsProgress) qBotsProgress.style.width = `${Math.round((botUsed / botLim) * 100)}%`;
                    const qBotsAssigned = document.getElementById('quotaChatbotsAssigned');
                    if (qBotsAssigned) qBotsAssigned.innerText = `${botUsed} Chatbots Active`;
                    const qBotsSlotsRem = document.getElementById('quotaChatbotsSlotsRemaining');
                    if (qBotsSlotsRem) qBotsSlotsRem.innerText = `${botRem} Bot Slot Remaining`;

                    const activeBotToken = stats.primary_bot_token || (window.currentWidgetBot && window.currentWidgetBot.botToken) || window.currentBotToken || 'c074a1862919e240ee863231a67701dc';
                    const liveHdrLink = document.getElementById('overviewTestLiveHeaderLink');
                    if (liveHdrLink) liveHdrLink.href = `/test_chat.html?token=${encodeURIComponent(activeBotToken)}`;
                    const liveBtmLink = document.getElementById('overviewTestLiveBottomLink');
                    if (liveBtmLink) liveBtmLink.href = `/test_chat.html?token=${encodeURIComponent(activeBotToken)}`;

                    syncOverviewEmbedSnippet(activeBotToken);
                }
            } catch (err) {
                console.error('Error loading analytics:', err);
            }
        }

        function syncOverviewEmbedSnippet(botToken) {
            const tok = botToken || window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || '';
            const box = document.getElementById('overviewEmbedCodeBox');
            if (box) {
                box.innerText = `<script src="https://edvora.chat/widget.js" data-bot-token="${tok || 'YOUR_BOT_TOKEN'}" async><\/script>`;
            }
            const link = document.getElementById('overviewStandaloneLink');
            if (link) {
                link.href = tok ? `/test/${encodeURIComponent(tok)}` : '/test';
            }
            const nameEl = document.getElementById('overviewCollegeName');
            if (nameEl) {
                const org = window.currentOrgName || '';
                if (org && !org.toLowerCase().includes('widget') && !org.toLowerCase().includes('landing page')) {
                    nameEl.innerText = org;
                } else {
                    nameEl.innerText = 'Your Institution';
                }
            }
        }

        async function copyOverviewEmbedScript() {
            const tok = window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || '';
            const scriptSnippet = `<script src="https://edvora.chat/widget.js" data-bot-token="${tok || 'YOUR_BOT_TOKEN'}" async><\/script>`;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(scriptSnippet);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = scriptSnippet;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                const btnText = document.getElementById('overviewCopyText');
                const btnIcon = document.getElementById('overviewCopyIcon');
                if (btnText) btnText.innerText = 'Copied Code!';
                if (btnIcon) btnIcon.innerText = 'âœ“';
                if (typeof showToast === 'function') {
                    showToast('Embed script tag copied to clipboard!', 'success');
                }
                setTimeout(() => {
                    if (btnText) btnText.innerText = 'Copy Embed Code';
                    if (btnIcon) btnIcon.innerText = 'ðŸ“‹';
                }, 2500);
            } catch(err) {
                if (typeof showToast === 'function') {
                    showToast('Failed to copy script snippet', 'error');
                }
            }
        }

        function launchOverviewQuickPrompt(queryText) {
            switchNavTab('test-chat');
            setTimeout(() => {
                const iframe = document.getElementById('testChatIframe');
                if (iframe && iframe.contentWindow) {
                    try {
                        iframe.contentWindow.postMessage({ type: 'EDVORA_TEST_PROMPT', query: queryText }, '*');
                    } catch(e) {}
                }
            }, 800);
            if (typeof showToast === 'function') {
                showToast(`Opened Test Console for: "${queryText}"`, 'info');
            }
        }

        let currentLeadsList = [];
        let filteredLeadsList = [];
        let currentLeadsStatusFilter = 'all';
        let leadsCurrentPage = 1;
        let leadsPageSize = 50;
        let leadsTotalPages = 1;
        let activeEditingLeadId = null;

        function formatToIST(dateInput) {
            if (!dateInput) return 'N/A';
            try {
                let dateObj;
                if (typeof dateInput === 'string' && !dateInput.endsWith('Z') && !dateInput.includes('T')) {
                    dateObj = new Date(dateInput.replace(/-/g, '/') + ' UTC');
                } else {
                    dateObj = new Date(dateInput);
                }

                return dateObj.toLocaleString('en-IN', {
                    timeZone: 'Asia/Kolkata',
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                }) + ' IST';
            } catch (e) {
                return dateInput;
            }
        }

        async function exportLeadsFile(format = 'csv') {
            if (!token) {
                showToast('Authentication token missing. Please log in.', 'error');
                return;
            }
            try {
                showToast(`Generating ${format.toUpperCase()} export file...`, 'info');
                const win = window.leadsActiveWindow || '30d';
                let exportUrl = `/v1/leads/export?format=${format}&token=${encodeURIComponent(token)}&window=${encodeURIComponent(win)}`;
                if (win === 'custom' && window.leadsCustomStartDate && window.leadsCustomEndDate) {
                    exportUrl += `&start_date=${encodeURIComponent(window.leadsCustomStartDate)}&end_date=${encodeURIComponent(window.leadsCustomEndDate)}`;
                }
                const res = await fetch(exportUrl, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    showToast(err.message || 'Export request failed.', 'error');
                    return;
                }
                const blob = await res.blob();
                const blobUrl = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = blobUrl;
                const timestamp = new Date().toISOString().slice(0, 10);
                a.download = format === 'json' ? `edvora_student_leads_${win}_${timestamp}.json` : `edvora_student_leads_${win}_${timestamp}.csv`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(blobUrl);
                a.remove();
                showToast(`Student leads (${win.toUpperCase()}) exported as ${format.toUpperCase()} successfully.`, 'success');
            } catch (err) {
                console.error('Export error:', err);
                showToast('Network error while exporting leads.', 'error');
            }
        }

        window.changeLeadsTimeWindow = function(win) {
            window.leadsActiveWindow = win;
            const dateBar = document.getElementById('leadsCustomDatePickerBar');
            if (dateBar && win !== 'custom') {
                dateBar.style.display = 'none';
            }
            ['24h', '7d', '30d', '3m', '6m', 'all', 'custom'].forEach(w => {
                const btn = document.getElementById(`leadsTimeBtn${w}`);
                if (btn) {
                    if (w === win) {
                        btn.style.background = '#063D3B';
                        btn.style.color = '#FFFFFF';
                        btn.style.boxShadow = '0 1px 3px rgba(6, 61, 59, 0.2)';
                    } else {
                        btn.style.background = 'transparent';
                        btn.style.color = '#4F7470';
                        btn.style.boxShadow = 'none';
                    }
                }
            });

            const labels = {
                '24h': 'Last 24 Hours',
                '7d': 'Last 7 Days',
                '30d': 'Last 30 Days',
                '3m': 'Last 3 Months',
                '6m': 'Last 6 Months',
                'all': 'All Time'
            };
            const ind = document.getElementById('leadsActiveRangeIndicator');
            if (ind) ind.innerText = `Active Window: ${labels[win] || win.toUpperCase()}`;

            leadsCurrentPage = 1;
            loadLeads();
        };

        window.toggleLeadsCustomDatePicker = function() {
            const dateBar = document.getElementById('leadsCustomDatePickerBar');
            if (!dateBar) return;
            const isHidden = dateBar.style.display === 'none' || !dateBar.style.display;
            dateBar.style.display = isHidden ? 'flex' : 'none';
            if (isHidden) {
                const now = new Date();
                const past = new Date(Date.now() - 30 * 86400000);
                const sEl = document.getElementById('leadsCustomStartDate');
                const eEl = document.getElementById('leadsCustomEndDate');
                if (sEl && !sEl.value) sEl.value = past.toISOString().slice(0, 10);
                if (eEl && !eEl.value) eEl.value = now.toISOString().slice(0, 10);
            }
        };

        window.applyLeadsCustomDateRange = function() {
            const sEl = document.getElementById('leadsCustomStartDate');
            const eEl = document.getElementById('leadsCustomEndDate');
            if (!sEl || !eEl || !sEl.value || !eEl.value) {
                showToast('Please select both start and end dates.', 'warning');
                return;
            }
            window.leadsActiveWindow = 'custom';
            window.leadsCustomStartDate = sEl.value;
            window.leadsCustomEndDate = eEl.value;

            ['24h', '7d', '30d', '3m', '6m', 'all', 'custom'].forEach(w => {
                const btn = document.getElementById(`leadsTimeBtn${w}`);
                if (btn) {
                    if (w === 'custom') {
                        btn.style.background = '#063D3B';
                        btn.style.color = '#FFFFFF';
                    } else {
                        btn.style.background = 'transparent';
                        btn.style.color = '#4F7470';
                    }
                }
            });

            const ind = document.getElementById('leadsActiveRangeIndicator');
            if (ind) ind.innerText = `Active Window: ${sEl.value} â€“ ${eEl.value}`;

            leadsCurrentPage = 1;
            loadLeads();
        };

        window.resetLeadsTimeWindow = function() {
            window.leadsCustomStartDate = '';
            window.leadsCustomEndDate = '';
            changeLeadsTimeWindow('30d');
        };

        function updateLeadsCardsStats(leads = [], apiStats = null) {
            const cbCountEl = document.getElementById('leadsCardCallbacksCount');
            const cbStatusEl = document.getElementById('leadsCardCallbacksStatus');
            const cbDoneEl = document.getElementById('leadsCardCallbacksDone');

            const tourCountEl = document.getElementById('leadsCardToursCount');
            const tourStatusEl = document.getElementById('leadsCardToursStatus');
            const tourDoneEl = document.getElementById('leadsCardToursDone');

            const schCountEl = document.getElementById('leadsCardScholarshipsCount');
            const schLeadsEl = document.getElementById('leadsCardScholarshipsLeads');
            const schEngineEl = document.getElementById('leadsCardScholarshipsEngine');

            const assetCountEl = document.getElementById('leadsCardAssetsCount');
            const assetDownEl = document.getElementById('leadsCardAssetsDownloads');
            const assetScopeEl = document.getElementById('leadsCardAssetsScope');

            if (apiStats) {
                // Exact window-scoped metrics directly from backend
                if (cbCountEl) cbCountEl.innerText = apiStats.callbacks_count !== undefined ? apiStats.callbacks_count : '0';
                if (cbStatusEl) cbStatusEl.innerText = `${apiStats.callbacks_pending !== undefined ? apiStats.callbacks_pending : 0} Pending`;
                if (cbDoneEl) cbDoneEl.innerText = `${apiStats.callbacks_done !== undefined ? apiStats.callbacks_done : 0} Resolved`;

                if (tourCountEl) tourCountEl.innerText = apiStats.tours_count !== undefined ? apiStats.tours_count : '0';
                if (tourStatusEl) tourStatusEl.innerText = `${apiStats.tours_upcoming !== undefined ? apiStats.tours_upcoming : 0} Scheduled`;
                if (tourDoneEl) tourDoneEl.innerText = `${apiStats.tours_done !== undefined ? apiStats.tours_done : 0} Visited`;

                if (schCountEl) schCountEl.innerText = apiStats.scholarships_count !== undefined ? apiStats.scholarships_count : '0';
                if (schLeadsEl) schLeadsEl.innerText = `${apiStats.scholarships_count || 0} Inquiries`;

                if (assetDownEl) assetDownEl.innerText = `${apiStats.lead_magnets_count !== undefined ? apiStats.lead_magnets_count : 0} Downloads`;
            } else {
                // Fallback calculation from current leads array
                const cbLeads = leads.filter(l => (l.lead_type || '').toLowerCase().includes('callback'));
                const tourLeads = leads.filter(l => (l.lead_type || '').toLowerCase().includes('tour'));
                const schLeads = leads.filter(l => (l.lead_type || '').toLowerCase().includes('scholarship') || (l.scholarship_tier && l.scholarship_tier.trim() !== ''));
                const assetLeads = leads.filter(l => (l.lead_type || '').toLowerCase().includes('asset') || (l.lead_type || '').toLowerCase().includes('prospectus') || (l.lead_type || '').toLowerCase().includes('brochure'));

                // Initial fast render from leads data
                if (cbCountEl) cbCountEl.innerText = cbLeads.length;
                if (cbStatusEl) cbStatusEl.innerText = `${cbLeads.filter(l => l.status === 'new').length || cbLeads.length} Pending`;

                if (tourCountEl) tourCountEl.innerText = tourLeads.length;
                if (tourStatusEl) tourStatusEl.innerText = `${tourLeads.filter(l => l.status === 'new').length || tourLeads.length} Upcoming`;

                if (schCountEl) schCountEl.innerText = schLeads.length;
                if (schLeadsEl) schLeadsEl.innerText = `${schLeads.length} Evaluated`;

                if (assetCountEl) assetCountEl.innerText = assetLeads.length;
                if (assetDownEl) assetDownEl.innerText = `${assetLeads.length} Downloads`;
            }

            // 2. Fetch authoritative channel data in parallel to enrich the cards with deep metrics
            if (!token) return;

            // Callbacks API (if not already fully provided by apiStats)
            if (!apiStats) {
                fetch('/v1/callbacks', { headers: { 'Authorization': 'Bearer ' + token } })
                    .then(r => r.json())
                    .then(d => {
                        if (d.status === 'success' && d.data) {
                            const metrics = d.data.metrics || {};
                            if (cbCountEl) cbCountEl.innerText = metrics.total !== undefined ? metrics.total : '0';
                            if (cbStatusEl) cbStatusEl.innerText = `${metrics.pending !== undefined ? metrics.pending : 0} Pending`;
                            const cbDoneEl = document.getElementById('leadsCardCallbacksDone');
                            if (cbDoneEl) cbDoneEl.innerText = `${metrics.completed !== undefined ? metrics.completed : 0} Resolved`;
                        }
                    }).catch(() => {});

                // Campus Tours API
                fetch('/v1/campus-tours', { headers: { 'Authorization': 'Bearer ' + token } })
                    .then(r => r.json())
                    .then(d => {
                        if (d.status === 'success' && d.data) {
                            const stats = d.data.stats || {};
                            if (tourCountEl) tourCountEl.innerText = stats.total !== undefined ? stats.total : '0';
                            if (tourStatusEl) tourStatusEl.innerText = `${(stats.pending || 0) + (stats.confirmed || 0)} Scheduled`;
                            const tourDoneEl = document.getElementById('leadsCardToursDone');
                            if (tourDoneEl) tourDoneEl.innerText = `${stats.completed || 0} Visited`;
                        }
                    }).catch(() => {});
            }

            // Scholarships API (Always check configured courses count & engine status)
            fetch('/v1/scholarships/config', { headers: { 'Authorization': 'Bearer ' + token } })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success' && d.data) {
                        const courses = d.data.courses || [];
                        const orgConfig = d.data.organization_config || {};
                        const isEnabled = orgConfig.enabled !== false && orgConfig.enabled !== 0 && orgConfig.enabled !== '0';
                        if (schCountEl && (!apiStats || !apiStats.scholarships_count)) {
                            schCountEl.innerText = courses.length;
                        }
                        const schEngineEl = document.getElementById('leadsCardScholarshipsEngine');
                        if (schEngineEl) {
                            schEngineEl.innerText = isEnabled ? 'Active' : 'Off';
                            schEngineEl.style.color = isEnabled ? 'var(--brand-emerald-400)' : 'var(--brand-amber-400)';
                        }
                    }
                }).catch(() => {});

            // Assets API (Authoritative source for lead-magnet assets count and scope)
            fetch('/v1/assets', { headers: { 'Authorization': 'Bearer ' + token } })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success' && d.data) {
                        const assets = d.data.assets || [];
                        if (assetCountEl) {
                            assetCountEl.innerText = assets.length;
                        }
                        const totalDownloads = assets.reduce((acc, a) => acc + (parseInt(a.downloads_count) || 0), 0);
                        if (assetDownEl && (!apiStats || apiStats.lead_magnets_count === undefined)) {
                            assetDownEl.innerText = `${totalDownloads} Downloads`;
                        }
                        const scopeEl = document.getElementById('leadsCardAssetsScope');
                        if (scopeEl) {
                            const orgCount = assets.filter(a => a.is_org_wide).length;
                            scopeEl.innerText = orgCount > 0 ? `${orgCount} Org-wide` : (assets.length > 0 ? 'Direct Delivery' : '0 Org-wide');
                        }
                    } else {
                        if (assetCountEl) assetCountEl.innerText = '0';
                        const scopeEl = document.getElementById('leadsCardAssetsScope');
                        if (scopeEl) scopeEl.innerText = '0 Org-wide';
                    }
                }).catch(() => {
                    if (assetCountEl) assetCountEl.innerText = '0';
                    const scopeEl = document.getElementById('leadsCardAssetsScope');
                    if (scopeEl) scopeEl.innerText = '0 Org-wide';
                });
        }

        function filterLeadsTable() {
            const query = (document.getElementById('leadsSearchInput')?.value || '').toLowerCase().trim();
            const filterKey = currentLeadsStatusFilter || 'all';
            const deptFilter = document.getElementById('leadsDeptFilter')?.value || 'all';
            const typeFilter = document.getElementById('leadsTypeFilter')?.value || 'all';

            // 1. Filter by Department, Type/Intent, and Search Query (current active scope)
            let scopeLeads = (currentLeadsList || []).filter(lead => {
                // Search query match
                if (query) {
                    const matchName = (lead.name || '').toLowerCase().includes(query);
                    const matchEmail = (lead.email || '').toLowerCase().includes(query);
                    const matchPhone = (lead.phone || '').toLowerCase().includes(query);
                    const matchProgram = (lead.program_interest || '').toLowerCase().includes(query);
                    const matchDept = (lead.department_name || '').toLowerCase().includes(query);
                    const matchNotes = (lead.notes || '').toLowerCase().includes(query);
                    const matchTier = (lead.scholarship_tier || '').toLowerCase().includes(query);
                    const matchAssigned = (lead.assigned_user_name || '').toLowerCase().includes(query);
                    if (!matchName && !matchEmail && !matchPhone && !matchProgram && !matchDept && !matchNotes && !matchTier && !matchAssigned) {
                        return false;
                    }
                }

                // Department filter match
                if (deptFilter !== 'all') {
                    if (deptFilter === 'general') {
                        if (lead.department_id || lead.department_name) return false;
                    } else {
                        const dId = String(lead.department_id || '');
                        const dName = (lead.department_name || '').toLowerCase().trim();
                        const filterLower = deptFilter.toLowerCase().trim();
                        const matchesDept = dId === deptFilter || dName === filterLower || dName.includes(filterLower);
                        if (!matchesDept) return false;
                    }
                }

                // Type / Intent filter match
                if (typeFilter !== 'all') {
                    const lType = (lead.lead_type || '').toLowerCase();
                    if (typeFilter === 'general') {
                        if (lType === 'scholarship_eval' || lType.includes('scholarship') || lType.includes('callback') || lType.includes('tour') || lType.includes('asset') || lType.includes('prospectus') || lead.scholarship_tier) {
                            return false;
                        }
                    } else if (typeFilter === 'callback') {
                        if (!lType.includes('callback')) return false;
                    } else if (typeFilter === 'tour') {
                        if (!lType.includes('tour')) return false;
                    } else if (typeFilter === 'scholarship') {
                        if (!lType.includes('scholarship') && !lead.scholarship_tier) return false;
                    } else if (typeFilter === 'asset') {
                        if (!lType.includes('asset') && !lType.includes('prospectus') && !lType.includes('brochure')) return false;
                    }
                }

                return true;
            });

            // 2. Update status tab count badges according to scoped leads
            updateLeadsFilterTabCounts(scopeLeads);

            // 3. Filter by Status Tab
            let filtered = scopeLeads.filter(lead => {
                const status = (lead.status || 'new').toLowerCase();
                const lType = (lead.lead_type || '').toLowerCase();

                if (filterKey === 'new') {
                    return status === 'new';
                } else if (filterKey === 'contacted') {
                    return status === 'contacted';
                } else if (filterKey === 'converted') {
                    return status === 'converted';
                } else if (filterKey === 'callbacks') {
                    return lType.includes('callback');
                } else if (filterKey === 'campus_tours') {
                    return lType.includes('tour');
                } else if (filterKey === 'scholarships') {
                    return lType.includes('scholarship') || (lead.scholarship_tier && lead.scholarship_tier.trim() !== '');
                }

                return true; // 'all'
            });

            filteredLeadsList = filtered;
            leadsCurrentPage = 1;
            renderLeadsTablePage();
        }

        function updateLeadsFilterTabCounts(leadsScope = []) {
            const countAll = leadsScope.length;
            const countNew = leadsScope.filter(l => (l.status || 'new').toLowerCase() === 'new').length;
            const countContacted = leadsScope.filter(l => (l.status || '').toLowerCase() === 'contacted').length;
            const countConverted = leadsScope.filter(l => (l.status || '').toLowerCase() === 'converted').length;
            const countCallbacks = leadsScope.filter(l => (l.lead_type || '').toLowerCase().includes('callback')).length;
            const countTours = leadsScope.filter(l => (l.lead_type || '').toLowerCase().includes('tour')).length;
            const countSch = leadsScope.filter(l => (l.lead_type || '').toLowerCase().includes('scholarship') || (l.scholarship_tier && l.scholarship_tier.trim() !== '')).length;

            const elAll = document.getElementById('leadsTabCountAll');
            if (elAll) elAll.innerText = countAll;
            const elNew = document.getElementById('leadsTabCountNew');
            if (elNew) elNew.innerText = countNew;
            const elCont = document.getElementById('leadsTabCountContacted');
            if (elCont) elCont.innerText = countContacted;
            const elConv = document.getElementById('leadsTabCountConverted');
            if (elConv) elConv.innerText = countConverted;
            const elCb = document.getElementById('leadsTabCountCallbacks');
            if (elCb) elCb.innerText = countCallbacks;
            const elTour = document.getElementById('leadsTabCountTours');
            if (elTour) elTour.innerText = countTours;
            const elSch = document.getElementById('leadsTabCountScholarships');
            if (elSch) elSch.innerText = countSch;
        }

        function setLeadsStatusFilter(filterKey, el) {
            currentLeadsStatusFilter = filterKey;

            // Update tab button active states
            document.querySelectorAll('#leadsStatusTabsContainer .ckh-tab-btn').forEach(btn => {
                const attr = btn.getAttribute('data-leads-filter') || '';
                if (attr === filterKey) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            filterLeadsTable();
        }

        function updateLeadsDeptDropdown() {
            const selectEl = document.getElementById('leadsDeptFilter');
            if (!selectEl) return;

            const deptMap = new Map();
            if (Array.isArray(currentDepartments)) {
                currentDepartments.forEach(d => {
                    if (d && d.name) deptMap.set(String(d.id || d.name), d.name);
                });
            }
            if (Array.isArray(currentLeadsList)) {
                currentLeadsList.forEach(lead => {
                    if (lead.department_id && lead.department_name) {
                        deptMap.set(String(lead.department_id), lead.department_name);
                    }
                });
            }

            const currentVal = selectEl.value || 'all';
            let html = '<option value="all">All Departments</option>';
            html += '<option value="general">ðŸ›ï¸ General (No Department)</option>';
            deptMap.forEach((name, id) => {
                html += `<option value="${escapeHtml(id)}">${escapeHtml(name)}</option>`;
            });
            selectEl.innerHTML = html;
            if (selectEl.querySelector(`option[value="${currentVal}"]`)) {
                selectEl.value = currentVal;
            } else {
                selectEl.value = 'all';
            }
        }

        function renderLeadsTablePage() {
            const tbody = document.getElementById('leadsTableBody');
            if (!tbody) return;

            const list = Array.isArray(filteredLeadsList) ? filteredLeadsList : [];
            const total = list.length;
            leadsTotalPages = Math.max(1, Math.ceil(total / leadsPageSize));
            if (leadsCurrentPage > leadsTotalPages) leadsCurrentPage = leadsTotalPages;
            if (leadsCurrentPage < 1) leadsCurrentPage = 1;

            const startIndex = (leadsCurrentPage - 1) * leadsPageSize;
            const endIndex = Math.min(startIndex + leadsPageSize, total);
            const pageData = list.slice(startIndex, endIndex);

            // Update Pagination UI elements
            const infoEl = document.getElementById('leadsPaginationInfo');
            if (infoEl) {
                if (total === 0) {
                    infoEl.innerText = 'Showing 0 to 0 of 0 student leads';
                } else {
                    infoEl.innerText = `Showing ${(startIndex + 1).toLocaleString()} to ${endIndex.toLocaleString()} of ${total.toLocaleString()} student leads`;
                }
            }

            const indicatorEl = document.getElementById('leadsPageIndicator');
            if (indicatorEl) {
                indicatorEl.innerText = `Page ${leadsCurrentPage} of ${leadsTotalPages}`;
            }

            const firstBtn = document.getElementById('leadsFirstBtn');
            const prevBtn = document.getElementById('leadsPrevBtn');
            const nextBtn = document.getElementById('leadsNextBtn');
            const lastBtn = document.getElementById('leadsLastBtn');

            if (firstBtn) firstBtn.disabled = leadsCurrentPage <= 1;
            if (prevBtn) prevBtn.disabled = leadsCurrentPage <= 1;
            if (nextBtn) nextBtn.disabled = leadsCurrentPage >= leadsTotalPages;
            if (lastBtn) lastBtn.disabled = leadsCurrentPage >= leadsTotalPages;

            const pageSizeSelect = document.getElementById('leadsPerPageSelect');
            if (pageSizeSelect && parseInt(pageSizeSelect.value, 10) !== leadsPageSize) {
                pageSizeSelect.value = String(leadsPageSize);
            }

            if (pageData.length > 0) {
                tbody.innerHTML = pageData.map(lead => {
                    const dateStr = formatToIST(lead.created_at);
                    const statusBadge = lead.status === 'converted'
                        ? '<span class="badge" style="background: rgba(52, 211, 153, 0.12); color: var(--brand-emerald-400); border: 1px solid rgba(52, 211, 153, 0.25); white-space: nowrap;">â— Converted</span>'
                        : (lead.status === 'contacted'
                            ? '<span class="badge" style="background: rgba(251, 191, 36, 0.12); color: var(--brand-amber-400); border: 1px solid rgba(251, 191, 36, 0.25); white-space: nowrap;">â— Contacted</span>'
                            : '<span class="badge" style="background: rgba(99, 102, 241, 0.12); color: var(--brand-indigo-400); border: 1px solid rgba(99, 102, 241, 0.25); white-space: nowrap;">â— New</span>');

                    const deptBadge = lead.department_name
                        ? `<span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: var(--brand-cyan-400); background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.25); padding: 2px 8px; border-radius: 12px; white-space: nowrap;">
                            <span>${lead.department_icon || 'ðŸ¢'}</span> <span>${lead.department_name}</span>
                           </span>`
                        : `<span style="font-size: 11px; color: #648781;">ðŸ›ï¸ General</span>`;

                    const assignedBadge = lead.assigned_user_name
                        ? `<span style="display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: #092F2E; background: var(--brand-surface-200); border: 1px solid #DDE9E3; padding: 2px 8px; border-radius: 12px; white-space: nowrap;">
                            <span style="width: 16px; height: 16px; border-radius: 50%; background: var(--brand-indigo-500); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 700;">${lead.assigned_user_name.charAt(0).toUpperCase()}</span>
                            <span>${lead.assigned_user_name}</span>
                           </span>`
                        : `<span style="font-size: 11px; color: #648781; font-style: italic; background: rgba(255,255,255,0.03); padding: 2px 8px; border-radius: 12px; border: 1px dashed var(--brand-border-subtle); display: inline-block;">Unassigned</span>`;

                    const typeBadge = (lead.lead_type === 'scholarship_eval' || lead.scholarship_tier)
                        ? `<span class="badge" style="background: rgba(52, 211, 153, 0.15); color: var(--brand-emerald-400); border: 1px solid rgba(52, 211, 153, 0.3); font-size: 10px; font-weight: 700; white-space: nowrap;">ðŸŽ“ ${lead.scholarship_tier || 'Scholarship Eval'}</span>`
                        : (lead.lead_type === 'campus_tour'
                            ? `<span class="badge" style="background: rgba(124, 58, 237, 0.15); color: #A78BFA; border: 1px solid rgba(124, 58, 237, 0.3); font-size: 10px; font-weight: 700; white-space: nowrap;">ðŸ« Campus Tour</span>`
                            : (lead.lead_type === 'counselor_callback' || lead.lead_type === 'callback'
                                ? `<span class="badge" style="background: rgba(239, 68, 68, 0.15); color: var(--brand-rose-400); border: 1px solid rgba(239, 68, 68, 0.3); font-size: 10px; font-weight: 700; white-space: nowrap;">ðŸ“ž Callback</span>`
                                : (lead.lead_type === 'asset' || lead.lead_type === 'prospectus'
                                    ? `<span class="badge" style="background: rgba(56, 189, 248, 0.15); color: var(--brand-cyan-400); border: 1px solid rgba(56, 189, 248, 0.3); font-size: 10px; font-weight: 700; white-space: nowrap;">ðŸ“¥ Asset / PDF</span>`
                                    : `<span class="badge" style="background: rgba(255,255,255,0.05); color: #648781; font-size: 10px;">ðŸ’¬ General Lead</span>`)));

                    return `
                        <tr>
                            <td style="vertical-align: middle;">
                                <div style="font-weight: 700; color: #063D3B; line-height: 1.35; font-size: 12.5px;">${lead.name}</div>
                                <div style="color: #648781; font-size: 11px; margin-top: 2px; font-family: var(--brand-font-mono);">${lead.email || 'â€”'}</div>
                            </td>
                            <td style="color: #092F2E; font-size: 12px; white-space: nowrap; min-width: 135px; font-family: var(--brand-font-mono); vertical-align: middle;">${lead.phone || 'â€”'}</td>
                            <td style="vertical-align: middle;">${deptBadge}</td>
                            <td style="vertical-align: middle;">${assignedBadge}</td>
                            <td style="font-size: 12px; vertical-align: middle;">${lead.program_interest || 'â€”'}</td>
                            <td style="vertical-align: middle;">${typeBadge}</td>
                            <td style="vertical-align: middle;">${statusBadge}</td>
                            <td style="font-size: 11px; color: #648781; white-space: nowrap; font-family: var(--brand-font-mono); vertical-align: middle;">${dateStr}</td>
                            <td style="text-align: right; white-space: nowrap; vertical-align: middle;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <button class="brand-btn-secondary brand-btn-sm" style="font-size: 11px; height: 28px; padding: 0 10px;" onclick="openLeadModal(${lead.id})">
                                        ðŸ” Details
                                    </button>
                                    <button class="brand-btn-secondary brand-btn-sm" style="font-size: 11px; height: 28px; padding: 0 8px; color: #f87171; border-color: rgba(248, 113, 113, 0.3);" onclick="deleteLead(${lead.id})" title="Permanent Erasure Request">
                                        ðŸ—‘ï¸
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');
            } else {
                const isFiltered = (document.getElementById('leadsSearchInput')?.value || '').trim() ||
                                   currentLeadsStatusFilter !== 'all' ||
                                   (document.getElementById('leadsDeptFilter')?.value || 'all') !== 'all' ||
                                   (document.getElementById('leadsTypeFilter')?.value || 'all') !== 'all';
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" style="text-align: center; color: #648781; padding: 36px 24px;">
                            <div style="font-size: 28px; margin-bottom: 8px;">${isFiltered ? 'ðŸ”' : 'ðŸŽ“'}</div>
                            <strong style="font-size: 13px; color: #092F2E; display: block;">${isFiltered ? 'No matching leads found' : 'No student leads captured yet'}</strong>
                            <span style="font-size: 12px; color: #648781;">${isFiltered ? 'Try clearing your search query or loosening the status/department filters.' : 'Prospective student inquiries and contact info captured across all chatbot departments will appear here.'}</span>
                            ${isFiltered ? `<div style="margin-top: 12px;"><button type="button" onclick="resetLeadsFilters()" class="dept-btn-secondary" style="height: 28px; padding: 0 12px; font-size: 11px; font-weight: 700; border-radius: 6px; cursor: pointer;">Clear All Filters</button></div>` : ''}
                        </td>
                    </tr>
                `;
            }
        }

        function resetLeadsFilters() {
            const searchInput = document.getElementById('leadsSearchInput');
            if (searchInput) searchInput.value = '';
            const deptFilter = document.getElementById('leadsDeptFilter');
            if (deptFilter) deptFilter.value = 'all';
            const typeFilter = document.getElementById('leadsTypeFilter');
            if (typeFilter) typeFilter.value = 'all';
            currentLeadsStatusFilter = 'all';
            document.querySelectorAll('#leadsStatusTabsContainer .ckh-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-leads-filter') === 'all');
            });
            filterLeadsTable();
        }

        function goToLeadsPage(page) {
            leadsCurrentPage = Math.max(1, Math.min(page, leadsTotalPages));
            renderLeadsTablePage();
        }

        function changeLeadsPageSize(newSize) {
            leadsPageSize = parseInt(newSize, 10) || 50;
            leadsCurrentPage = 1;
            renderLeadsTablePage();
        }

        async function loadLeads() {
            if (!token) return;
            try {
                let tbody = document.getElementById('leadsTableBody');
                if (!tbody) {
                    await new Promise(r => setTimeout(r, 60));
                    tbody = document.getElementById('leadsTableBody');
                }
                if (tbody && (!currentLeadsList || currentLeadsList.length === 0)) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="9" style="text-align: center; color: #648781; padding: 48px 24px;">
                                <div style="display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 50%; background: #F4FAF7; border: 1.5px solid #D1E5DE; margin-bottom: 12px;">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#063D3B" stroke-width="2.5" class="brand-spin" style="animation: spin 0.9s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                </div>
                                <div style="font-weight: 700; font-size: 14px; color: #063D3B; letter-spacing: -0.01em;">Loading student admissions leads...</div>
                                <div style="font-size: 11.5px; color: #648781; margin-top: 4px;">Retrieving captured inquiries and multi-channel pipeline records from database</div>
                            </td>
                        </tr>
                    `;
                }

                const win = window.leadsActiveWindow || '30d';
                let url = `/v1/leads?window=${encodeURIComponent(win)}`;
                if (win === 'custom' && window.leadsCustomStartDate && window.leadsCustomEndDate) {
                    url += `&start_date=${encodeURIComponent(window.leadsCustomStartDate)}&end_date=${encodeURIComponent(window.leadsCustomEndDate)}`;
                }

                const res = await fetch(url, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    if (Array.isArray(data.data)) {
                        currentLeadsList = data.data;
                        updateLeadsCardsStats(currentLeadsList);
                    } else if (data.data && data.data.leads) {
                        currentLeadsList = data.data.leads || [];
                        updateLeadsCardsStats(currentLeadsList, data.data.stats);
                    } else {
                        currentLeadsList = [];
                    }
                    const statLeadsEl = document.getElementById('statLeads');
                    if (statLeadsEl) {
                        statLeadsEl.innerText = currentLeadsList.length;
                    }
                    updateLeadsDeptDropdown();
                    filterLeadsTable();
                } else {
                    currentLeadsList = [];
                    filteredLeadsList = [];
                    renderLeadsTablePage();
                }
            } catch (err) {
                console.error('Error loading leads:', err);
            }
        }

        async function openLeadModal(leadId) {
            activeEditingLeadId = leadId;
            try {
                const res = await fetch('/v1/leads/' + leadId, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status !== 'success' || !data.data) {
                    alert('Could not load lead details.');
                    return;
                }

                const lead = data.data;

                document.getElementById('leadModalName').innerText = lead.name || 'Lead Details';
                document.getElementById('leadModalEmail').innerText = lead.email || 'â€”';
                document.getElementById('leadModalPhone').innerText = lead.phone || 'â€”';
                document.getElementById('leadModalProgram').innerText = lead.program_interest || 'â€”';
                document.getElementById('leadModalCapturedAt').innerText = formatToIST(lead.created_at);
                document.getElementById('leadModalNotes').value = lead.notes || '';
                document.getElementById('leadModalStatusSelect').value = lead.status || 'new';

                // Scholarship Evaluation Box
                const schBox = document.getElementById('leadModalScholarshipBox');
                if (schBox) {
                    if (lead.lead_type === 'scholarship_eval' || lead.scholarship_tier || lead.academic_score) {
                        schBox.style.display = 'block';
                        document.getElementById('leadModalAcademicScore').innerText = lead.academic_score || 'Not Specified';
                        document.getElementById('leadModalSchTier').innerText = lead.scholarship_tier || 'Standard Tier';
                        document.getElementById('leadModalWaiverAmount').innerText = lead.estimated_waiver_amount ? ('â‚¹ ' + Number(lead.estimated_waiver_amount).toLocaleString() + ' / yr') : 'Calculated in Consultation';
                    } else {
                        schBox.style.display = 'none';
                    }
                }

                const statusBadgeEl = document.getElementById('leadModalStatusBadge');
                if (statusBadgeEl) {
                    statusBadgeEl.innerText = lead.status ? lead.status.toUpperCase() : 'NEW';
                    statusBadgeEl.style.cssText = lead.status === 'converted'
                        ? 'background: rgba(52, 211, 153, 0.12); color: var(--brand-emerald-400); border: 1px solid rgba(52, 211, 153, 0.3); font-size: 10px;'
                        : (lead.status === 'contacted'
                            ? 'background: rgba(251, 191, 36, 0.12); color: var(--brand-amber-400); border: 1px solid rgba(251, 191, 36, 0.3); font-size: 10px;'
                            : 'background: rgba(99, 102, 241, 0.12); color: var(--brand-indigo-400); border: 1px solid rgba(99, 102, 241, 0.3); font-size: 10px;');
                }

                // Populate Department dropdown
                const deptSelect = document.getElementById('leadModalDeptSelect');
                deptSelect.innerHTML = `<option value="">ðŸ›ï¸ General (No Department)</option>` + 
                    currentDepartments.map(d => `<option value="${d.id}" ${lead.department_id == d.id ? 'selected' : ''}>${d.icon || 'ðŸ¢'} ${d.name}</option>`).join('');

                // Populate Staff dropdown
                const staffSelect = document.getElementById('leadModalStaffSelect');
                staffSelect.innerHTML = `<option value="">ðŸ‘¤ Unassigned</option>` +
                    availableOrgStaff.map(s => `<option value="${s.id}" ${lead.assigned_user_id == s.id ? 'selected' : ''}>ðŸ‘¤ ${s.name} (${s.role})</option>`).join('');

                // Render Chatbot Conversation Transcript
                const transcriptSection = document.getElementById('leadModalTranscriptSection');
                const transcriptBox = document.getElementById('leadModalTranscriptBox');
                const transcriptBadge = document.getElementById('leadModalTranscriptBadge');

                if (transcriptSection && transcriptBox) {
                    transcriptSection.style.display = 'block';

                    if (lead.transcript && lead.transcript.length > 0) {
                        if (transcriptBadge) {
                            transcriptBadge.style.display = 'inline-block';
                            transcriptBadge.innerText = `${lead.transcript.length} ${lead.transcript.length === 1 ? 'message' : 'messages'}`;
                        }
                        transcriptBox.innerHTML = lead.transcript.map(msg => {
                            const isUser = msg.role === 'user';
                            const msgTime = formatToIST(msg.created_at);
                            return `
                                <div style="align-self: ${isUser ? 'flex-end' : 'flex-start'}; max-width: 85%; padding: 8px 12px; border-radius: 8px; background: ${isUser ? 'var(--brand-indigo-600)' : 'var(--brand-surface-200)'}; color: ${isUser ? '#ffffff' : 'var(--brand-text-primary)'}; box-shadow: 0 1px 2px rgba(0,0,0,0.15);">
                                    <div style="font-size: 10px; opacity: 0.8; margin-bottom: 3px; font-weight: 600;">${isUser ? 'ðŸ‘¤ Prospective Student' : 'ðŸ¤– Admissions AI Assistant'} â€¢ ${msgTime}</div>
                                    <div style="line-height: 1.45; word-break: break-word;">${msg.content}</div>
                                </div>
                            `;
                        }).join('');
                    } else {
                        if (transcriptBadge) {
                            transcriptBadge.style.display = 'none';
                        }
                        transcriptBox.innerHTML = `
                            <div style="text-align: center; color: #648781; padding: 18px 14px; font-size: 12px;">
                                <div style="font-size: 18px; margin-bottom: 4px;">ðŸ’¬</div>
                                <span style="font-weight: 600; color: #4F7470; display: block; margin-bottom: 2px;">No chatbot conversation transcript recorded</span>
                                <span style="font-size: 11px; opacity: 0.85;">This lead was created via direct offline submission, manual counselor entry, or external sync.</span>
                            </div>
                        `;
                    }
                }

                document.getElementById('leadDetailModal').style.display = 'flex';
            } catch (err) {
                console.error('Error fetching lead modal details:', err);
                alert('Connection error loading lead.');
            }
        }

        function closeLeadModal() {
            document.getElementById('leadDetailModal').style.display = 'none';
            activeEditingLeadId = null;
        }

        async function saveLeadModalChanges() {
            if (!activeEditingLeadId) return;

            const status = document.getElementById('leadModalStatusSelect').value;
            const deptVal = document.getElementById('leadModalDeptSelect').value;
            const staffVal = document.getElementById('leadModalStaffSelect').value;
            const department_id = deptVal ? parseInt(deptVal) : null;
            const assigned_user_id = staffVal ? parseInt(staffVal) : null;
            const notes = document.getElementById('leadModalNotes').value.trim();

            try {
                const res = await fetch('/v1/leads/' + activeEditingLeadId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ status, department_id, assigned_user_id, notes })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeLeadModal();
                    await loadLeads();
                } else {
                    alert(data.message || 'Failed to save lead updates.');
                }
            } catch (err) {
                console.error('Error updating lead:', err);
                alert('Connection error saving lead.');
            }
        }

        async function deleteLead(leadId, studentName = null) {
            if (!token || !leadId) return;
            if (!studentName) {
                const found = (currentLeadsList || []).find(l => l.id == leadId);
                studentName = found ? found.name : 'this student';
            }
            const confirmMsg = `Are you sure you want to permanently erase the lead record for "${studentName}"?\n\nThis action executes a Right to Erasure request under FERPA, GDPR Art. 17, CCPA, and PIPEDA. All associated notes and transcripts for this lead will be permanently deleted.`;
            if (!confirm(confirmMsg)) return;

            try {
                const res = await fetch('/v1/leads/' + leadId, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Lead record permanently erased (Right to Erasure compliance).', 'success');
                    if (activeEditingLeadId === leadId) {
                        closeLeadModal();
                    }
                    await loadLeads();
                } else {
                    showToast(data.message || 'Failed to delete lead.', 'error');
                }
            } catch (err) {
                console.error('Error deleting lead:', err);
                showToast('Network error while deleting lead record.', 'error');
            }
        }

        function deleteActiveLeadRecord() {
            if (activeEditingLeadId) {
                const name = document.getElementById('leadModalName')?.innerText || 'Student';
                deleteLead(activeEditingLeadId, name);
            }
        }

        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        // COUNSELOR CALLBACKS QUEUE & MANAGEMENT LOGIC
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        let currentCallbacksList = [];
        let activeEditingCallbackId = null;
        let debounceCallbackTimer = null;

        function debounceCallbackSearch() {
            clearTimeout(debounceCallbackTimer);
            debounceCallbackTimer = setTimeout(() => {
                loadCallbacks();
            }, 300);
        }

        function cleanPhoneNumber(phone) {
            if (!phone) return '';
            let cleaned = phone.replace(/[^0-9+]/g, '');
            if (cleaned.length === 10 && !cleaned.startsWith('+')) {
                cleaned = '91' + cleaned;
            }
            return cleaned;
        }

        function buildWhatsAppUrl(phone, studentName, topic, slot) {
            const clean = cleanPhoneNumber(phone);
            if (!clean) return '#';
            const orgName = window.currentOrgName || 'our admissions team';
            const text = `Hi ${studentName || 'there'}! This is the admissions counselor from ${orgName}. You requested a callback regarding ${topic || 'admissions & courses'}. Is now a good time to speak?`;
            return `https://wa.me/${clean}?text=${encodeURIComponent(text)}`;
        }

        async function loadCallbacks() {
            if (!token) return;

            const status = document.getElementById('filterCallbackStatus') ? document.getElementById('filterCallbackStatus').value : 'all';
            const deptId = document.getElementById('filterCallbackDept') ? document.getElementById('filterCallbackDept').value : '';
            const staffId = document.getElementById('filterCallbackStaff') ? document.getElementById('filterCallbackStaff').value : '';
            const search = document.getElementById('filterCallbackSearch') ? document.getElementById('filterCallbackSearch').value.trim() : '';

            // Populate filter dropdowns if empty
            const filterDeptEl = document.getElementById('filterCallbackDept');
            if (filterDeptEl && filterDeptEl.options.length <= 1 && currentDepartments.length > 0) {
                const currentVal = filterDeptEl.value;
                filterDeptEl.innerHTML = `<option value="">Department: All</option>` +
                    currentDepartments.map(d => `<option value="${d.id}">${d.icon || 'ðŸ¢'} ${d.name}</option>`).join('');
                filterDeptEl.value = currentVal;
            }

            const filterStaffEl = document.getElementById('filterCallbackStaff');
            if (filterStaffEl && filterStaffEl.options.length <= 1 && availableOrgStaff.length > 0) {
                const currentVal = filterStaffEl.value;
                filterStaffEl.innerHTML = `<option value="">Counselor: All</option>` +
                    availableOrgStaff.map(s => `<option value="${s.id}">ðŸ‘¤ ${s.name}</option>`).join('');
                filterStaffEl.value = currentVal;
            }

            let url = `/v1/callbacks?status=${encodeURIComponent(status)}`;
            if (deptId) url += `&department_id=${encodeURIComponent(deptId)}`;
            if (staffId) url += `&assigned_user_id=${encodeURIComponent(staffId)}`;
            if (search) url += `&search=${encodeURIComponent(search)}`;

            try {
                const res = await fetch(url, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                const tbody = document.getElementById('callbacksTableBody');
                if (!tbody) return;

                if (data.status === 'success' && data.data) {
                    const callbacks = data.data.callbacks || [];
                    const metrics = data.data.metrics || {};
                    currentCallbacksList = callbacks;

                    // Update Metric cards
                    const pendingEl = document.getElementById('statPendingCallbacks');
                    if (pendingEl) pendingEl.innerText = metrics.pending || 0;

                    const activeEl = document.getElementById('statActiveCallbacks');
                    if (activeEl) activeEl.innerText = metrics.active || 0;

                    const compEl = document.getElementById('statCompletedCallbacks');
                    if (compEl) compEl.innerText = metrics.completed || 0;

                    const totalEl = document.getElementById('statTotalCallbacks');
                    if (totalEl) totalEl.innerText = metrics.total || 0;

                    // Update Left Navigation Sidebar Live Badge
                    const badgeEl = document.getElementById('sidebarCallbackBadge');
                    if (badgeEl) {
                        const pendingCount = metrics.pending || 0;
                        if (pendingCount > 0) {
                            badgeEl.innerText = pendingCount;
                            badgeEl.style.display = 'inline-block';
                        } else {
                            badgeEl.style.display = 'none';
                        }
                    }

                    if (callbacks.length > 0) {
                        tbody.innerHTML = callbacks.map(cb => {
                            const dateStr = formatToIST(cb.created_at);
                            const cleanPhone = cleanPhoneNumber(cb.student_phone);
                            const waUrl = buildWhatsAppUrl(cb.student_phone, cb.student_name, cb.topic_or_query, cb.preferred_time_slot);

                            const deptBadge = cb.department_name
                                ? `<span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: var(--brand-cyan-400); background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.25); padding: 2px 8px; border-radius: 12px; white-space: nowrap;">
                                    <span>${cb.department_icon || 'ðŸ¢'}</span> <span>${cb.department_name}</span>
                                   </span>`
                                : `<span style="font-size: 11px; color: #648781;">ðŸ›ï¸ General</span>`;

                            const slotBadge = `<span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--brand-amber-400); background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.25); padding: 2px 8px; border-radius: 12px; white-space: nowrap;">
                                <span>âš¡</span> <span>${cb.preferred_time_slot || 'ASAP'}</span>
                            </span>`;

                            const staffOptions = `<option value="">ðŸ‘¤ Unassigned</option>` +
                                availableOrgStaff.map(s => `<option value="${s.id}" ${cb.assigned_user_id == s.id ? 'selected' : ''}>${s.name}</option>`).join('');

                            const assignedSelect = `
                                <select class="brand-input" onchange="assignCallbackCounselor(${cb.id}, this.value)" style="height: 28px; font-size: 11px; padding: 0 6px; width: 140px; background-color: #FFFFFF; color: #063D3B; border: 1px solid #D1E5DE;">
                                    ${staffOptions}
                                </select>
                            `;

                            const statusSelect = `
                                <select class="brand-input" onchange="updateCallbackStatus(${cb.id}, this.value)" style="height: 28px; font-size: 11px; padding: 0 6px; width: 130px; font-weight: 600; background-color: #FFFFFF; color: ${
                                    cb.status === 'completed' ? 'var(--brand-emerald-400)' :
                                    cb.status === 'pending' ? 'var(--brand-rose-400)' :
                                    cb.status === 'scheduled' || cb.status === 'in_progress' ? 'var(--brand-amber-400)' : 'var(--brand-text-secondary)'
                                };">
                                    <option value="pending" ${cb.status === 'pending' ? 'selected' : ''}>ðŸ”´ Pending</option>
                                    <option value="scheduled" ${cb.status === 'scheduled' ? 'selected' : ''}>ðŸŸ¡ Scheduled</option>
                                    <option value="in_progress" ${cb.status === 'in_progress' ? 'selected' : ''}>ðŸŸ  In Progress</option>
                                    <option value="completed" ${cb.status === 'completed' ? 'selected' : ''}>ðŸŸ¢ Completed</option>
                                    <option value="no_response" ${cb.status === 'no_response' ? 'selected' : ''}>âšª No Response</option>
                                    <option value="cancelled" ${cb.status === 'cancelled' ? 'selected' : ''}>âš« Cancelled</option>
                                </select>
                            `;

                            return `
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: #092F2E; font-size: 13px;">${cb.student_name}</div>
                                        ${cb.student_email ? `<div style="font-size: 11px; color: #648781;">${cb.student_email}</div>` : ''}
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <span style="font-size: 12px; font-weight: 700; font-family: var(--brand-font-mono); color: #092F2E;">${cb.student_phone}</span>
                                            <div style="display: flex; gap: 4px;">
                                                <a href="tel:${cleanPhone}" class="brand-btn-primary brand-btn-sm" style="font-size: 10px; height: 24px; padding: 0 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; background: #2563EB;">
                                                    ðŸ“ž Call
                                                </a>
                                                <a href="${waUrl}" target="_blank" class="brand-btn-secondary brand-btn-sm" style="font-size: 10px; height: 24px; padding: 0 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; color: var(--brand-emerald-400); border-color: rgba(52, 211, 153, 0.3);">
                                                    ðŸ’¬ WA
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                    <td>${deptBadge}</td>
                                    <td>${slotBadge}</td>
                                    <td style="font-size: 12px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${cb.topic_or_query || 'General Admissions'}">
                                        ${cb.topic_or_query || '<span style="color: #648781;">General Inquiry</span>'}
                                    </td>
                                    <td>${assignedSelect}</td>
                                    <td>${statusSelect}</td>
                                    <td style="font-size: 11px; color: #648781; white-space: nowrap; font-family: var(--brand-font-mono);">${dateStr}</td>
                                    <td style="text-align: right;">
                                        <button class="brand-btn-secondary brand-btn-sm" style="font-size: 11px; height: 28px; padding: 0 10px; display: inline-flex; align-items: center; gap: 4px;" onclick="openCallbackModal(${cb.id})">
                                            <span>ðŸ“</span> Notes &amp; Chat
                                        </button>
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    } else {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="9" style="text-align: center; color: #648781; padding: 36px 24px;">
                                    <div style="font-size: 32px; margin-bottom: 8px;">ðŸ“ž</div>
                                    <strong style="font-size: 14px; color: #092F2E; display: block;">No counselor callbacks found</strong>
                                    <span style="font-size: 12px; color: #648781;">Student callback requests registered via the chatbot widget across all departments will appear here in real-time.</span>
                                </td>
                            </tr>
                        `;
                    }
                }
            } catch (err) {
                console.error('Error loading callbacks:', err);
            }
        }

        async function updateCallbackStatus(callbackId, newStatus) {
            if (!token || !callbackId) return;
            try {
                const res = await fetch('/v1/callbacks/' + callbackId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ status: newStatus })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('âœ“ Callback status updated', 'success');
                    loadCallbacks();
                } else {
                    alert(data.message || 'Failed to update callback status.');
                }
            } catch (err) {
                console.error('Error updating callback status:', err);
            }
        }

        async function assignCallbackCounselor(callbackId, userId) {
            if (!token || !callbackId) return;
            const assigned_user_id = userId ? parseInt(userId) : null;
            try {
                const res = await fetch('/v1/callbacks/' + callbackId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ assigned_user_id })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('âœ“ Counselor assigned successfully', 'success');
                    loadCallbacks();
                } else {
                    alert(data.message || 'Failed to assign counselor.');
                }
            } catch (err) {
                console.error('Error assigning callback counselor:', err);
            }
        }

        async function openCallbackModal(callbackId) {
            activeEditingCallbackId = callbackId;
            try {
                const res = await fetch('/v1/callbacks/' + callbackId, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status !== 'success' || !data.data) {
                    alert('Could not load callback details.');
                    return;
                }

                const cb = data.data;

                document.getElementById('callbackModalName').innerText = `${cb.student_name} â€” Callback`;
                document.getElementById('callbackModalPhone').innerText = cb.student_phone || 'â€”';
                document.getElementById('callbackModalEmail').innerText = cb.student_email || 'Not provided';
                document.getElementById('callbackModalSlot').innerText = cb.preferred_time_slot || 'Immediate (ASAP)';
                document.getElementById('callbackModalTopic').innerText = cb.topic_or_query || 'General Admissions Inquiry';
                document.getElementById('callbackModalRequestedAt').innerText = formatToIST(cb.created_at);
                document.getElementById('callbackModalNotes').value = cb.counselor_notes || '';
                document.getElementById('callbackModalStatusSelect').value = cb.status || 'pending';
                document.getElementById('callbackModalAttempts').value = cb.call_attempts || 0;

                // Configure direct dial & WhatsApp buttons in modal
                const cleanPhone = cleanPhoneNumber(cb.student_phone);
                const callBtn = document.getElementById('callbackModalCallLink');
                if (callBtn) callBtn.href = `tel:${cleanPhone}`;

                const waBtn = document.getElementById('callbackModalWaLink');
                if (waBtn) waBtn.href = buildWhatsAppUrl(cb.student_phone, cb.student_name, cb.topic_or_query, cb.preferred_time_slot);

                // Department select
                const deptSelect = document.getElementById('callbackModalDeptSelect');
                deptSelect.innerHTML = `<option value="">ðŸ›ï¸ General Admissions Desk</option>` +
                    currentDepartments.map(d => `<option value="${d.id}" ${cb.department_id == d.id ? 'selected' : ''}>${d.icon || 'ðŸ¢'} ${d.name}</option>`).join('');

                // Staff select
                const staffSelect = document.getElementById('callbackModalStaffSelect');
                staffSelect.innerHTML = `<option value="">ðŸ‘¤ Unassigned</option>` +
                    availableOrgStaff.map(s => `<option value="${s.id}" ${cb.assigned_user_id == s.id ? 'selected' : ''}>ðŸ‘¤ ${s.name} (${s.role})</option>`).join('');

                // Status badge
                const statusBadgeEl = document.getElementById('callbackModalStatusBadge');
                if (statusBadgeEl) {
                    statusBadgeEl.innerText = cb.status ? cb.status.toUpperCase().replace('_', ' ') : 'PENDING';
                }

                // Chat transcript
                const transcriptSection = document.getElementById('callbackModalTranscriptSection');
                const transcriptBox = document.getElementById('callbackModalTranscriptBox');
                if (transcriptSection && transcriptBox) {
                    transcriptSection.style.display = 'block';
                    if (cb.transcript && cb.transcript.length > 0) {
                        transcriptBox.innerHTML = cb.transcript.map(msg => {
                            const isUser = msg.role === 'user';
                            const msgTime = formatToIST(msg.created_at);
                            return `
                                <div style="align-self: ${isUser ? 'flex-end' : 'flex-start'}; max-width: 85%; padding: 8px 12px; border-radius: 8px; background: ${isUser ? 'var(--brand-indigo-600)' : 'var(--brand-surface-200)'}; color: ${isUser ? '#ffffff' : 'var(--brand-text-primary)'}; box-shadow: 0 1px 2px rgba(0,0,0,0.15);">
                                    <div style="font-size: 10px; opacity: 0.8; margin-bottom: 3px; font-weight: 600;">${isUser ? 'ðŸ‘¤ Prospective Student' : 'ðŸ¤– Admissions AI Assistant'} â€¢ ${msgTime}</div>
                                    <div style="line-height: 1.45; word-break: break-word;">${msg.content}</div>
                                </div>
                            `;
                        }).join('');
                    } else {
                        transcriptBox.innerHTML = `
                            <div style="text-align: center; color: #648781; padding: 18px 14px; font-size: 12px;">
                                <div style="font-size: 18px; margin-bottom: 4px;">ðŸ’¬</div>
                                <span style="font-weight: 600; color: #4F7470; display: block; margin-bottom: 2px;">No chatbot conversation transcript recorded</span>
                                <span style="font-size: 11px; opacity: 0.85;">This callback was requested offline or directly via phone without a web chatbot session.</span>
                            </div>
                        `;
                    }
                }

                document.getElementById('callbackDetailModal').style.display = 'flex';
            } catch (err) {
                console.error('Error fetching callback modal details:', err);
                alert('Connection error loading callback.');
            }
        }

        function modifyCallbackAttempts(delta) {
            const input = document.getElementById('callbackModalAttempts');
            if (input) {
                let current = parseInt(input.value) || 0;
                current = Math.max(0, current + delta);
                input.value = current;
            }
        }

        function closeCallbackModal() {
            document.getElementById('callbackDetailModal').style.display = 'none';
            activeEditingCallbackId = null;
        }

        async function saveCallbackModalChanges() {
            if (!activeEditingCallbackId) return;

            const status = document.getElementById('callbackModalStatusSelect').value;
            const deptVal = document.getElementById('callbackModalDeptSelect').value;
            const staffVal = document.getElementById('callbackModalStaffSelect').value;
            const department_id = deptVal ? parseInt(deptVal) : null;
            const assigned_user_id = staffVal ? parseInt(staffVal) : null;
            const counselor_notes = document.getElementById('callbackModalNotes').value.trim();
            const call_attempts = parseInt(document.getElementById('callbackModalAttempts').value) || 0;

            try {
                const res = await fetch('/v1/callbacks/' + activeEditingCallbackId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ status, department_id, assigned_user_id, counselor_notes, call_attempts })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeCallbackModal();
                    showToast('âœ“ Callback updated successfully', 'success');
                    await loadCallbacks();
                } else {
                    alert(data.message || 'Failed to save callback updates.');
                }
            } catch (err) {
                console.error('Error updating callback:', err);
                alert('Connection error saving callback.');
            }
        }

        // â”€â”€ CAMPUS TOURS MANAGEMENT LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let currentCampusToursList = [];
        let tourSearchTimeout = null;

        function debounceTourSearch() {
            clearTimeout(tourSearchTimeout);
            tourSearchTimeout = setTimeout(() => { loadCampusTours(); }, 300);
        }

        async function loadCampusTours() {
            if (!token) return;

            const status = document.getElementById('filterTourStatus') ? document.getElementById('filterTourStatus').value : 'all';
            const search = document.getElementById('filterTourSearch') ? document.getElementById('filterTourSearch').value.trim() : '';

            let url = `/v1/campus-tours`;

            try {
                const res = await fetch(url, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                const tbody = document.getElementById('campusToursTableBody');
                if (!tbody) return;

                if (data.status === 'success' && data.data) {
                    let tours = data.data.tours || [];
                    const stats = data.data.stats || {};
                    currentCampusToursList = tours;

                    // Update Metrics
                    const pendingEl = document.getElementById('statPendingTours');
                    if (pendingEl) pendingEl.innerText = stats.pending || 0;
                    const confEl = document.getElementById('statConfirmedTours');
                    if (confEl) confEl.innerText = stats.confirmed || 0;
                    const compEl = document.getElementById('statCompletedTours');
                    if (compEl) compEl.innerText = stats.completed || 0;
                    const totEl = document.getElementById('statTotalTours');
                    if (totEl) totEl.innerText = stats.total || 0;

                    // Update Sidebar Badge
                    const badgeEl = document.getElementById('sidebarTourBadge');
                    if (badgeEl) {
                        const pendingCount = stats.pending || 0;
                        if (pendingCount > 0) {
                            badgeEl.innerText = pendingCount;
                            badgeEl.style.display = 'inline-block';
                        } else {
                            badgeEl.style.display = 'none';
                        }
                    }

                    // Client-side filter
                    if (status !== 'all') {
                        tours = tours.filter(t => t.status === status);
                    }
                    if (search) {
                        const s = search.toLowerCase();
                        tours = tours.filter(t => 
                            (t.student_name && t.student_name.toLowerCase().includes(s)) ||
                            (t.student_email && t.student_email.toLowerCase().includes(s)) ||
                            (t.student_phone && t.student_phone.includes(s))
                        );
                    }

                    if (tours.length > 0) {
                        tbody.innerHTML = tours.map(t => {
                            const dateStr = formatToIST(t.created_at);
                            const cleanPhone = cleanPhoneNumber(t.student_phone);
                            const waUrl = `https://wa.me/${cleanPhone}?text=${encodeURIComponent('Hi ' + t.student_name + '! This is ' + (currentOrg ? currentOrg.name : 'College Admissions') + ' regarding your upcoming Campus Tour visit.')}`;

                            const deptBadge = t.department_name
                                ? `<span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: var(--brand-cyan-400); background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.25); padding: 2px 8px; border-radius: 12px; white-space: nowrap;">
                                    <span>${t.department_icon || 'ðŸ¢'}</span> <span>${t.department_name}</span>
                                   </span>`
                                : `<span style="font-size: 11px; color: #648781;">ðŸ›ï¸ General Campus</span>`;

                            const slotBadge = `<div style="display: flex; flex-direction: column; gap: 2px;">
                                <span style="font-size: 11px; font-weight: 700; color: #092F2E;">${t.preferred_date || 'Flexible Date'}</span>
                                <span style="font-size: 10px; color: #A78BFA; background: rgba(124, 58, 237, 0.1); border: 1px solid rgba(124, 58, 237, 0.25); padding: 1px 6px; border-radius: 8px; display: inline-block; width: fit-content;">${t.preferred_time || 'Morning'}</span>
                            </div>`;

                            const guideOptions = `<option value="">ðŸ‘¤ Unassigned</option>` +
                                (availableOrgStaff || []).map(s => `<option value="${s.id}" ${t.assigned_user_id == s.id ? 'selected' : ''}>${s.name}</option>`).join('');

                            const guideSelect = `
                                <select class="brand-input" onchange="assignTourGuide(${t.id}, this.value)" style="height: 28px; font-size: 11px; padding: 0 6px; width: 130px; background-color: #FFFFFF; color: #063D3B; border: 1px solid #D1E5DE;">
                                    ${guideOptions}
                                </select>
                            `;

                            const statusSelect = `
                                <select class="brand-input" onchange="updateTourStatus(${t.id}, this.value)" style="height: 28px; font-size: 11px; padding: 0 6px; width: 130px; font-weight: 600; background-color: #FFFFFF; color: ${
                                    t.status === 'completed' ? 'var(--brand-emerald-400)' :
                                    t.status === 'confirmed' ? 'var(--brand-amber-400)' :
                                    t.status === 'pending' ? '#A78BFA' : 'var(--brand-text-secondary)'
                                };">
                                    <option value="pending" ${t.status === 'pending' ? 'selected' : ''}>ðŸŸ£ Pending</option>
                                    <option value="confirmed" ${t.status === 'confirmed' ? 'selected' : ''}>ðŸŸ¡ Confirmed</option>
                                    <option value="completed" ${t.status === 'completed' ? 'selected' : ''}>ðŸŸ¢ Completed</option>
                                    <option value="cancelled" ${t.status === 'cancelled' ? 'selected' : ''}>âš« Cancelled</option>
                                    <option value="no_show" ${t.status === 'no_show' ? 'selected' : ''}>âšª No Show</option>
                                </select>
                            `;

                            const feedbackBtn = (t.status === 'completed' || t.status === 'confirmed')
                                ? `<button class="brand-btn-secondary brand-btn-sm" onclick="triggerTourFeedback(${t.id})" style="font-size: 10px; height: 24px; padding: 0 8px; color: var(--brand-cyan-400); border-color: rgba(56, 189, 248, 0.3);" title="Send Visit Feedback & Improvement Form Email">
                                    ðŸ“© Feedback
                                   </button>`
                                : '';

                            return `
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: #092F2E; font-size: 13px;">${t.student_name}</div>
                                        <div style="font-size: 11px; color: #648781;">${t.student_email || 'â€”'}</div>
                                        <div style="display: flex; gap: 4px; margin-top: 4px;">
                                            <a href="tel:${cleanPhone}" class="brand-btn-primary brand-btn-sm" style="font-size: 10px; height: 22px; padding: 0 7px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px; background: #2563EB;">
                                                ðŸ“ž ${t.student_phone}
                                            </a>
                                            <a href="${waUrl}" target="_blank" class="brand-btn-secondary brand-btn-sm" style="font-size: 10px; height: 22px; padding: 0 7px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px; color: var(--brand-emerald-400); border-color: rgba(52, 211, 153, 0.3);">
                                                ðŸ’¬ WA
                                            </a>
                                        </div>
                                    </td>
                                    <td>
                                        ${deptBadge}
                                        ${t.program_interest ? `<div style="font-size: 10px; color: #4F7470; margin-top: 3px;">${t.program_interest}</div>` : ''}
                                    </td>
                                    <td>${slotBadge}</td>
                                    <td style="font-weight: 600; font-size: 12px; color: #092F2E;">${t.group_size || 1} Person${(t.group_size || 1) > 1 ? 's' : ''}</td>
                                    <td>${guideSelect}</td>
                                    <td>${statusSelect}</td>
                                    <td style="font-size: 11px; color: #648781; white-space: nowrap; font-family: var(--brand-font-mono);">${dateStr}</td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 4px; align-items: center;">
                                            ${feedbackBtn}
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    } else {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="8" style="text-align: center; color: #648781; padding: 32px 24px;">
                                    <div style="font-size: 28px; margin-bottom: 8px;">ðŸ«</div>
                                    <strong style="font-size: 13px; color: #092F2E; display: block;">No campus tours found</strong>
                                    <span style="font-size: 12px; color: #648781;">Guided campus visit bookings requested by prospective students will appear here.</span>
                                </td>
                            </tr>
                        `;
                    }
                }
            } catch (err) {
                console.error('Error loading campus tours:', err);
            }
        }

        async function updateTourStatus(tourId, newStatus) {
            if (!token || !tourId) return;
            try {
                const res = await fetch('/v1/campus-tours/' + tourId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ status: newStatus })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('âœ“ Tour status updated to ' + newStatus.toUpperCase(), 'success');
                    loadCampusTours();
                } else {
                    alert(data.message || 'Failed to update tour status.');
                }
            } catch (err) {
                console.error('Error updating tour status:', err);
            }
        }

        async function assignTourGuide(tourId, userId) {
            if (!token || !tourId) return;
            const assigned_user_id = userId ? parseInt(userId) : null;
            try {
                const res = await fetch('/v1/campus-tours/' + tourId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ assigned_user_id })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('âœ“ Tour guide assigned successfully', 'success');
                    loadCampusTours();
                } else {
                    alert(data.message || 'Failed to assign tour guide.');
                }
            } catch (err) {
                console.error('Error assigning tour guide:', err);
            }
        }

        async function triggerTourFeedback(tourId) {
            if (!token || !tourId) return;
            try {
                showToast('Sending visit feedback email...', 'info');
                const res = await fetch('/v1/campus-tours/' + tourId + '/feedback', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('âœ“ Visit feedback survey dispatched to student!', 'success');
                } else {
                    alert(data.message || 'Could not send feedback email.');
                }
            } catch (err) {
                console.error('Error dispatching tour feedback:', err);
                alert('Connection error dispatching survey.');
            }
        }

        // Dashboard Initialization
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

        async function startOnboardingWizard(startStep = null) {
            if (!token) return;
            sessionStorage.removeItem('edvora_onboarding_dismissed');
            document.documentElement.classList.add('in-onboarding');
            document.getElementById('onboardingContainer').style.display = 'flex';
            document.getElementById('appContainer').style.display = 'none';
            document.getElementById('authContainer').style.display = 'none';

            await loadOnboardingData(startStep);
        }

        function exitOnboardingToDashboard() {
            sessionStorage.setItem('edvora_onboarding_dismissed', '1');
            document.documentElement.classList.remove('in-onboarding');
            document.getElementById('onboardingContainer').style.display = 'none';
            document.getElementById('appContainer').style.display = 'grid';
            initDashboard();
        }

        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        // KNOWLEDGE GAPS AUDIT & NOTIFICATION BADGE CONTROLLER
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        let currentKnowledgeGapsState = {
            hasGaps: false,
            gapsList: []
        };

        function checkKnowledgeGapsAudit(statusData) {
            if (!statusData) return;
            const org = statusData.organization || {};
            const programs = statusData.programs || [];
            const ksCount = statusData.stats?.knowledge_sources_count || 0;
            const deptCount = Array.isArray(statusData.departments) ? statusData.departments.length : 0;

            const gaps = [];

            // 1. Check Programs
            if (programs.length === 0) {
                gaps.push({
                    icon: 'ðŸŽ“',
                    title: 'Degree Programs Missing',
                    desc: 'No specific degrees or courses were indexed. The bot cannot answer program-specific inquiries.',
                    ctaText: 'Add Programs',
                    action: () => { hideKnowledgeGapsModal(); switchNavTab('knowledge'); }
                });
            }

            // 2. Check Knowledge Ingestion / Brochure
            if (ksCount === 0) {
                gaps.push({
                    icon: 'ðŸ“„',
                    title: 'Admissions Brochure / Catalog Missing',
                    desc: 'No documents, policy text, or admission FAQs are in the knowledge base.',
                    ctaText: 'Upload Documents',
                    action: () => { hideKnowledgeGapsModal(); switchNavTab('knowledge-ingestion'); }
                });
            }

            // 3. Check Departments
            if (deptCount === 0) {
                gaps.push({
                    icon: 'ðŸ“š',
                    title: 'Academic Departments Not Configured',
                    desc: 'Departmental routing and lead assignment are currently running on default settings.',
                    ctaText: 'Configure Departments',
                    action: () => { hideKnowledgeGapsModal(); switchNavTab('departments'); }
                });
            }

            // 4. Check Profile (city / state)
            if (!org.city || !org.state) {
                gaps.push({
                    icon: 'ðŸ›ï¸',
                    title: 'Campus Location Incomplete',
                    desc: 'City and state are unverified. Adding these helps prospective students locate your campus.',
                    ctaText: 'Edit Profile',
                    action: () => { hideKnowledgeGapsModal(); switchNavTab('settings'); }
                });
            }

            currentKnowledgeGapsState.hasGaps = gaps.length > 0;
            currentKnowledgeGapsState.gapsList = gaps;

            const badge = document.getElementById('headerKnowledgeGapBadge');
            const badgeText = document.getElementById('headerKnowledgeGapText');
            if (badge) {
                if (currentKnowledgeGapsState.hasGaps) {
                    badge.style.display = 'inline-flex';
                    if (badgeText) badgeText.textContent = `${gaps.length} Knowledge Gap${gaps.length > 1 ? 's' : ''}`;
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        function showKnowledgeGapsModal() {
            const modal = document.getElementById('knowledgeGapsModal');
            const listEl = document.getElementById('knowledgeGapsChecklist');
            if (!modal || !listEl) return;

            listEl.innerHTML = '';

            if (currentKnowledgeGapsState.gapsList.length === 0) {
                listEl.innerHTML = `
                    <div style="background:rgba(52,211,153,0.1); border:1px solid rgba(52,211,153,0.3); border-radius:10px; padding:18px; text-align:center;">
                        <span style="font-size:28px;">âœ“</span>
                        <div style="font-size:14px; font-weight:700; color:#34d399; margin-top:6px;">All Knowledge Systems Fully Primed!</div>
                        <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Your AI assistant has verified departments, programs, and knowledge sources.</div>
                    </div>
                `;
            } else {
                currentKnowledgeGapsState.gapsList.forEach(gap => {
                    const item = document.createElement('div');
                    item.style.cssText = 'background:#111827; border:1px solid #1f2937; border-radius:10px; padding:14px 16px; display:flex; align-items:center; justify-content:space-between; gap:14px;';
                    item.innerHTML = `
                        <div style="display:flex; align-items:flex-start; gap:12px;">
                            <span style="font-size:22px; line-height:1;">${gap.icon}</span>
                            <div>
                                <div style="font-size:13px; font-weight:700; color:#f1f5f9;">${escapeHtml(gap.title)}</div>
                                <div style="font-size:11.5px; color:#94a3b8; margin-top:2px; line-height:1.4;">${escapeHtml(gap.desc)}</div>
                            </div>
                        </div>
                        <button type="button" class="brand-btn-primary brand-btn-sm" style="flex-shrink:0; height:30px; font-size:11.5px; padding:0 14px; font-weight:600;">
                            ${escapeHtml(gap.ctaText)} â†’
                        </button>
                    `;
                    const btn = item.querySelector('button');
                    if (btn && typeof gap.action === 'function') {
                        btn.onclick = gap.action;
                    }
                    listEl.appendChild(item);
                });
            }

            modal.style.display = 'flex';
        }

        function hideKnowledgeGapsModal() {
            const modal = document.getElementById('knowledgeGapsModal');
            if (modal) modal.style.display = 'none';
        }

        function dismissOverviewBanner() {
            const bannerCard = document.getElementById('overviewReadinessCard');
            if (bannerCard) bannerCard.style.display = 'none';
            sessionStorage.setItem('edvora_banner_dismissed', '1');
        }

        async function loadOnboardingData(targetStep = null) {
            try {
                const res = await fetch('/v1/onboarding/status', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    onbDataState = data.data;
                    const org = onbDataState.organization || {};
                    const bot = onbDataState.chatbot || {};

                    // Header college name
                    const hdrEl = document.getElementById('onbHeaderCollegeName');
                    if (hdrEl) hdrEl.innerText = org.name ? `${org.name} Setup` : 'Institution Setup Wizard';

                    // Populate Step 2 Profile inputs
                    if (document.getElementById('onbOrgName')) document.getElementById('onbOrgName').value = org.name || '';
                    if (document.getElementById('onbOrgShortName')) document.getElementById('onbOrgShortName').value = org.short_name || '';
                    if (document.getElementById('onbFoundedYear')) document.getElementById('onbFoundedYear').value = org.founded_year || '';
                    if (document.getElementById('onbCity')) document.getElementById('onbCity').value = org.city || '';
                    if (document.getElementById('onbState')) document.getElementById('onbState').value = org.state || '';
                    if (document.getElementById('onbPincode')) document.getElementById('onbPincode').value = org.pincode || '';
                    if (document.getElementById('onbWebsiteUrl')) document.getElementById('onbWebsiteUrl').value = org.website_url || '';
                    if (document.getElementById('onbAcademicYear')) document.getElementById('onbAcademicYear').value = org.academic_year || '2026-27';

                    // Sync Radio Cards for Inst Type & Category
                    if (org.institution_type) {
                        document.querySelectorAll('#onbInstTypeGroup .onb-radio-card').forEach(el => {
                            el.classList.toggle('active', el.getAttribute('data-val') === org.institution_type);
                        });
                    }
                    if (org.institution_category) {
                        document.querySelectorAll('#onbInstCatGroup .onb-radio-card').forEach(el => {
                            el.classList.toggle('active', el.getAttribute('data-val') === org.institution_category);
                        });
                    }

                    // Populate Step 3 Identity
                    const prefName = org.short_name || org.name || '';
                    if (document.getElementById('onbPreferredName')) {
                        document.getElementById('onbPreferredName').value = prefName;
                        updateOnbPreviewName(prefName);
                    }
                    if (document.getElementById('onbInstitutionDesc')) document.getElementById('onbInstitutionDesc').value = org.institution_description || '';

                    // Populate Step 4 Programs
                    renderOnbProgramsList(onbDataState.programs || []);

                    // Populate Step 5 Admissions
                    const adm = org.admissions_config || {};
                    if (adm.application_method) {
                        document.querySelectorAll('#onbAppMethodGroup .onb-radio-card').forEach(el => {
                            el.classList.toggle('active', el.getAttribute('data-val') === adm.application_method);
                        });
                    }
                    if (adm.exam_required) {
                        document.querySelectorAll('#onbExamReqGroup .onb-radio-card').forEach(el => {
                            el.classList.toggle('active', el.getAttribute('data-val') === adm.exam_required);
                        });
                    }
                    if (adm.accepted_exams && Array.isArray(adm.accepted_exams)) {
                        const chipsContainer = document.getElementById('onbExamsChips');
                        if (chipsContainer) {
                            adm.accepted_exams.forEach(ex => {
                                let existing = Array.from(chipsContainer.querySelectorAll('.onb-chip-toggle')).find(c => (c.getAttribute('data-val') || '').toLowerCase() === ex.toLowerCase());
                                if (existing) {
                                    existing.classList.add('active');
                                } else {
                                    const chip = document.createElement('span');
                                    chip.className = 'onb-chip-toggle active';
                                    chip.setAttribute('data-val', ex);
                                    chip.innerText = ex;
                                    chipsContainer.appendChild(chip);
                                }
                            });
                        }
                    }
                    if (document.getElementById('onbInterviews')) document.getElementById('onbInterviews').value = adm.interviews !== undefined ? (adm.interviews ? '1' : '0') : '1';
                    if (document.getElementById('onbGd')) document.getElementById('onbGd').value = adm.group_discussions !== undefined ? (adm.group_discussions ? '1' : '0') : '0';
                    if (document.getElementById('onbRolling')) document.getElementById('onbRolling').value = adm.rolling_admissions !== undefined ? (adm.rolling_admissions ? '1' : '0') : '1';

                    // Populate Step 7 Fees & Hostel
                    const cmp = org.campus_config || {};
                    if (document.getElementById('onbHostelAvailable')) document.getElementById('onbHostelAvailable').value = cmp.hostel_available !== undefined ? (cmp.hostel_available ? '1' : '0') : '1';
                    if (document.getElementById('onbHostelFee')) document.getElementById('onbHostelFee').value = cmp.hostel_fee || '';
                    if (document.getElementById('onbFoodIncluded')) document.getElementById('onbFoodIncluded').value = cmp.food_included !== undefined ? (cmp.food_included ? '1' : '0') : '0';

                    // Populate Step 8 Scholarships
                    const sch = org.scholarship_config || {};
                    if (document.getElementById('onbOfferScholarships')) document.getElementById('onbOfferScholarships').value = sch.offer_scholarships !== undefined ? (sch.offer_scholarships ? '1' : '0') : '1';
                    if (document.getElementById('onbScholarshipName')) document.getElementById('onbScholarshipName').value = sch.scholarship_name || '';
                    if (document.getElementById('onbScholarshipBenefit')) document.getElementById('onbScholarshipBenefit').value = sch.benefit || '';
                    if (document.getElementById('onbScholarshipEligibility')) document.getElementById('onbScholarshipEligibility').value = sch.eligibility || '';

                    // Populate Step 9 Campus
                    if (document.getElementById('onbCampusName')) document.getElementById('onbCampusName').value = cmp.campus_name || '';
                    if (document.getElementById('onbTransportAvailable')) document.getElementById('onbTransportAvailable').value = cmp.transport_available !== undefined ? (cmp.transport_available ? '1' : '0') : '1';
                    if (cmp.facilities && Array.isArray(cmp.facilities)) {
                        document.querySelectorAll('#onbFacilitiesChips .onb-chip-toggle').forEach(el => {
                            el.classList.toggle('active', cmp.facilities.includes(el.getAttribute('data-val')));
                        });
                    }

                    // Populate Step 10 Placements
                    const plc = org.placements_config || {};
                    if (document.getElementById('onbPlacementRate')) document.getElementById('onbPlacementRate').value = plc.placement_rate || '95%';
                    if (document.getElementById('onbAvgPackage')) document.getElementById('onbAvgPackage').value = plc.avg_package || 'â‚¹9.6 LPA';
                    if (document.getElementById('onbHighestPackage')) document.getElementById('onbHighestPackage').value = plc.highest_package || 'â‚¹24 LPA';
                    if (document.getElementById('onbPlacementYear')) document.getElementById('onbPlacementYear').value = plc.placement_year || '2025-26';
                    if (document.getElementById('onbTopRecruiters')) document.getElementById('onbTopRecruiters').value = plc.top_recruiters || 'Google, Microsoft, Deloitte, HDFC Bank, Amazon';

                    // Populate Step 12 Bot Assistant
                    if (document.getElementById('onbBotName')) document.getElementById('onbBotName').value = bot.name || `${org.short_name || 'College'} Admissions Assistant`;
                    if (document.getElementById('onbBotColor')) document.getElementById('onbBotColor').value = bot.primary_color || '#4f46e5';
                    if (document.getElementById('onbWelcomeMsg')) document.getElementById('onbWelcomeMsg').value = bot.welcome_message || `Hello! Welcome to ${org.name || 'our institution'} admissions desk. How can I help you today?`;

                    // Populate Step 16 Snippet Code
                    const botTok = bot.bot_token || 'YOUR_BOT_TOKEN';
                    if (document.getElementById('onbEmbedScriptCode')) {
                        document.getElementById('onbEmbedScriptCode').innerText = `<!-- Edvora Admissions Assistant -->\n<script src="https://edvora.chat/widget.js" data-bot-token="${botTok}" async><\/script>`;
                    }

                    const savedStep = parseInt(onbDataState.current_step || org.onboarding_step || onbDataState.onboarding_step || 1);
                    const destStep = (targetStep !== null && targetStep !== undefined && targetStep > 0) ? parseInt(targetStep) : savedStep;
                    goToOnbStep(destStep);
                }
            } catch(e) {
                console.error('Error loading onboarding data:', e);
            }
        }

        function updateOnbPreviewName(val) {
            const el = document.getElementById('onbPreviewPreferredName');
            if (el) {
                const name = (val && val.trim()) ? val.trim() : (document.getElementById('onbOrgShortName')?.value.trim() || document.getElementById('onbOrgName')?.value.trim() || 'our institution');
                el.innerText = name;
            }
        }

        function addCustomOnbExam() {
            const inp = document.getElementById('onbCustomExamInput');
            if (!inp) return;
            const name = inp.value.trim();
            if (!name) return;

            const chipsContainer = document.getElementById('onbExamsChips');
            if (!chipsContainer) return;

            // Check if already exists
            const existing = Array.from(chipsContainer.querySelectorAll('.onb-chip-toggle')).find(c => (c.getAttribute('data-val') || '').toLowerCase() === name.toLowerCase());
            if (existing) {
                existing.classList.add('active');
            } else {
                const chip = document.createElement('span');
                chip.className = 'onb-chip-toggle active';
                chip.setAttribute('data-val', name);
                chip.innerText = name;
                chipsContainer.appendChild(chip);
            }

            inp.value = '';
        }

        function goToOnbStep(stepNum) {
            currentOnbStep = stepNum;
            const totalSteps = 16;
            const pct = Math.round(((stepNum - 1) / (totalSteps - 1)) * 100);

            // Update Progress Bar
            const progBar = document.getElementById('onbProgressBar');
            if (progBar) progBar.style.width = Math.max(6, pct) + '%';
            const progPct = document.getElementById('onbProgressPct');
            if (progPct) progPct.innerText = `${pct}% Completed`;

            const stepNames = [
                'Welcome', 'Institution Profile', 'Institution Identity', 'Academic Programs',
                'Admissions Process', 'Program Essentials', 'Fees & Hostel', 'Scholarships',
                'Campus Life', 'Placements', 'Documents & Knowledge', 'AI Assistant Setup',
                'Counselor Handoff', 'Live AI Preview', 'Readiness Score', 'Website Embed'
            ];
            const stepInd = document.getElementById('onbStepIndicator');
            if (stepInd) stepInd.innerText = `Step ${stepNum} of ${totalSteps} â€¢ ${stepNames[stepNum - 1] || ''}`;

            // Show step card
            document.querySelectorAll('.onb-step-card').forEach(card => card.style.display = 'none');
            const targetCard = document.getElementById(`onbStep${stepNum}`);
            if (targetCard) targetCard.style.display = 'block';

            // Step specific initializations
            if (stepNum === 3) {
                const curPref = document.getElementById('onbPreferredName')?.value.trim();
                if (!curPref) {
                    const autoName = document.getElementById('onbOrgShortName')?.value.trim() || document.getElementById('onbOrgName')?.value.trim() || '';
                    if (document.getElementById('onbPreferredName')) document.getElementById('onbPreferredName').value = autoName;
                    updateOnbPreviewName(autoName);
                } else {
                    updateOnbPreviewName(curPref);
                }
            }
            if (stepNum === 6) renderProgramEssentials();
            if (stepNum === 7) renderProgramFees();
            if (stepNum === 14) initOnbChatPreview();
            if (stepNum === 15) renderReadinessAudit();
        }

        async function saveOnbStep(stepNum) {
            let payload = {};

            switch (stepNum) {
                case 2: // Institution Profile
                    const activeType = document.querySelector('#onbInstTypeGroup .onb-radio-card.active');
                    const activeCat = document.querySelector('#onbInstCatGroup .onb-radio-card.active');
                    payload = {
                        name: document.getElementById('onbOrgName').value.trim(),
                        short_name: document.getElementById('onbOrgShortName').value.trim(),
                        founded_year: document.getElementById('onbFoundedYear').value.trim(),
                        institution_type: activeType ? activeType.getAttribute('data-val') : 'Private',
                        institution_category: activeCat ? activeCat.getAttribute('data-val') : 'College',
                        city: document.getElementById('onbCity').value.trim(),
                        state: document.getElementById('onbState').value.trim(),
                        pincode: document.getElementById('onbPincode').value.trim(),
                        website_url: document.getElementById('onbWebsiteUrl').value.trim(),
                        academic_year: document.getElementById('onbAcademicYear').value.trim()
                    };
                    if (!payload.name || !payload.city) {
                        showToast('Please provide institution name and city.', 'error');
                        return;
                    }
                    break;

                case 3: // Identity
                    payload = {
                        preferred_name: document.getElementById('onbPreferredName').value.trim(),
                        institution_description: document.getElementById('onbInstitutionDesc').value.trim()
                    };
                    if (!payload.institution_description) {
                        showToast('Please provide a brief institution description for AI training.', 'error');
                        return;
                    }
                    break;

                case 4: // Programs
                    const progRows = document.querySelectorAll('.onb-prog-row');
                    const progs = [];
                    progRows.forEach(row => {
                        const name = row.querySelector('.prog-name-input').value.trim();
                        const type = row.querySelector('.prog-type-select').value;
                        const duration = row.querySelector('.prog-duration-input').value.trim();
                        const mode = row.querySelector('.prog-mode-select').value;
                        const isOpen = row.querySelector('.prog-open-select').value;
                        if (name) {
                            progs.push({ name, program_type: type, duration, mode, is_admissions_open: isOpen });
                        }
                    });
                    if (progs.length === 0) {
                        showToast('Please add at least 1 academic program.', 'error');
                        return;
                    }
                    payload = { programs: progs };
                    break;

                case 5: // Admissions Basics
                    const activeApp = document.querySelector('#onbAppMethodGroup .onb-radio-card.active');
                    const activeExam = document.querySelector('#onbExamReqGroup .onb-radio-card.active');
                    const exams = [];
                    document.querySelectorAll('#onbExamsChips .onb-chip-toggle.active').forEach(ch => exams.push(ch.getAttribute('data-val')));
                    payload = {
                        application_method: activeApp ? activeApp.getAttribute('data-val') : 'Online application on website',
                        exam_required: activeExam ? activeExam.getAttribute('data-val') : 'Yes',
                        accepted_exams: exams,
                        interviews: document.getElementById('onbInterviews').value === '1',
                        group_discussions: document.getElementById('onbGd').value === '1',
                        rolling_admissions: document.getElementById('onbRolling').value === '1'
                    };
                    break;

                case 6: // Program Essentials
                    const essRows = document.querySelectorAll('.onb-ess-row');
                    const essProgs = [];
                    essRows.forEach(row => {
                        const id = row.getAttribute('data-prog-id');
                        const eligibility = row.querySelector('.ess-elig-input').value.trim();
                        const deadline = row.querySelector('.ess-deadline-input').value.trim();
                        const appFee = row.querySelector('.ess-fee-input').value.trim();
                        const appUrl = row.querySelector('.ess-url-input').value.trim();
                        essProgs.push({ id, eligibility, application_deadline: deadline, application_fee: appFee, application_url: appUrl });
                    });
                    payload = { programs: essProgs };
                    break;

                case 7: // Fees & Hostel
                    const feeRows = document.querySelectorAll('.onb-fee-row');
                    const feeProgs = [];
                    feeRows.forEach(row => {
                        const id = row.getAttribute('data-prog-id');
                        const name = row.getAttribute('data-prog-name');
                        const tuition = row.querySelector('.fee-tuition-input').value.trim();
                        const reg = row.querySelector('.fee-reg-input').value.trim();
                        const total = row.querySelector('.fee-total-input').value.trim();
                        feeProgs.push({ id, name, tuition_fee: tuition, registration_fee: reg, total_fee: total });
                    });
                    payload = {
                        programs: feeProgs,
                        hostel_available: document.getElementById('onbHostelAvailable').value === '1',
                        hostel_fee: document.getElementById('onbHostelFee').value.trim(),
                        food_included: document.getElementById('onbFoodIncluded').value === '1',
                        academic_year: document.getElementById('onbAcademicYear') ? document.getElementById('onbAcademicYear').value.trim() : '2026-27'
                    };
                    break;

                case 8: // Scholarships
                    payload = {
                        offer_scholarships: document.getElementById('onbOfferScholarships').value === '1',
                        scholarship_name: document.getElementById('onbScholarshipName').value.trim(),
                        benefit: document.getElementById('onbScholarshipBenefit').value.trim(),
                        eligibility: document.getElementById('onbScholarshipEligibility').value.trim()
                    };
                    break;

                case 9: // Campus Life
                    const facs = [];
                    document.querySelectorAll('#onbFacilitiesChips .onb-chip-toggle.active').forEach(ch => facs.push(ch.getAttribute('data-val')));
                    payload = {
                        campus_name: document.getElementById('onbCampusName').value.trim(),
                        transport_available: document.getElementById('onbTransportAvailable').value === '1',
                        facilities: facs
                    };
                    break;

                case 10: // Placements
                    payload = {
                        placement_rate: document.getElementById('onbPlacementRate').value.trim(),
                        avg_package: document.getElementById('onbAvgPackage').value.trim(),
                        highest_package: document.getElementById('onbHighestPackage').value.trim(),
                        placement_year: document.getElementById('onbPlacementYear').value.trim(),
                        top_recruiters: document.getElementById('onbTopRecruiters').value.trim()
                    };
                    break;

                case 12: // AI Assistant
                    payload = {
                        bot_name: document.getElementById('onbBotName').value.trim(),
                        primary_color: document.getElementById('onbBotColor').value,
                        welcome_message: document.getElementById('onbWelcomeMsg').value.trim()
                    };
                    break;

                case 13: // Human Handoff
                    payload = {
                        contact_name: document.getElementById('onbContactName').value.trim(),
                        contact_email: document.getElementById('onbContactEmail').value.trim(),
                        contact_phone: document.getElementById('onbContactPhone').value.trim()
                    };
                    break;

                default:
                    payload = {};
                    break;
            }

            try {
                const res = await fetch('/v1/onboarding/step', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ step: stepNum, data: payload })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    if (data.data && data.data.programs) {
                        onbDataState.programs = data.data.programs;
                    }
                    if (data.data && data.data.readiness_score !== undefined) {
                        onbDataState.readiness_score = data.data.readiness_score;
                        onbDataState.readiness_label = data.data.readiness_label;
                        onbDataState.readiness_breakdown = data.data.readiness_breakdown;
                    }
                    showToast(`Step ${stepNum} saved.`, 'success');
                    goToOnbStep(stepNum + 1);
                } else {
                    showToast(data.message || `Failed to save step ${stepNum}`, 'error');
                }
            } catch(e) {
                console.error(e);
                showToast(`Error saving step ${stepNum}`, 'error');
            }
        }

        function renderOnbProgramsList(programs) {
            const list = document.getElementById('onbProgramsList');
            if (!list) return;
            list.innerHTML = '';

            if (!programs || programs.length === 0) {
                // Add default MBA row
                addProgramOnbRow({ name: 'MBA / PGDM', program_type: 'postgraduate', duration: '2 years', mode: 'full_time', is_admissions_open: 1 });
                return;
            }

            programs.forEach(p => addProgramOnbRow(p));
        }

        function addProgramOnbRow(p = null) {
            const list = document.getElementById('onbProgramsList');
            if (!list) return;

            const name = p ? (p.name || '') : '';
            const type = p ? (p.program_type || 'undergraduate') : 'undergraduate';
            const duration = p ? (p.duration || '2 years') : '2 years';
            const mode = p ? (p.mode || 'full_time') : 'full_time';
            const isOpen = p ? (p.is_admissions_open !== undefined ? p.is_admissions_open : 1) : 1;

            const row = document.createElement('div');
            row.className = 'onb-prog-row';
            row.style.cssText = 'background: #F1F7F4; border: 1px solid #DDE9E3; border-radius: 12px; padding: 16px; display: grid; grid-template-columns: 2fr 1.2fr 1fr 1fr 1fr 40px; gap: 12px; align-items: center;';

            row.innerHTML = `
                <div>
                    <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Program Name</label>
                    <input type="text" class="brand-input prog-name-input" placeholder="e.g. MBA Management" value="${name}" style="height: 44px; font-size: 15px;" />
                </div>
                <div>
                    <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Degree Level</label>
                    <select class="brand-input prog-type-select" style="height: 44px; font-size: 15px;">
                        <option value="undergraduate" ${type==='undergraduate'?'selected':''}>Undergraduate</option>
                        <option value="postgraduate" ${type==='postgraduate'?'selected':''}>Postgraduate</option>
                        <option value="doctoral" ${type==='doctoral'?'selected':''}>Doctoral / PhD</option>
                        <option value="executive" ${type==='executive'?'selected':''}>Executive</option>
                        <option value="certificate" ${type==='certificate'?'selected':''}>Diploma / Cert</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Duration</label>
                    <input type="text" class="brand-input prog-duration-input" placeholder="2 years" value="${duration}" style="height: 44px; font-size: 15px;" />
                </div>
                <div>
                    <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Mode</label>
                    <select class="brand-input prog-mode-select" style="height: 44px; font-size: 15px;">
                        <option value="full_time" ${mode==='full_time'?'selected':''}>Full-Time</option>
                        <option value="part_time" ${mode==='part_time'?'selected':''}>Part-Time</option>
                        <option value="online" ${mode==='online'?'selected':''}>Online</option>
                        <option value="hybrid" ${mode==='hybrid'?'selected':''}>Hybrid</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Admissions</label>
                    <select class="brand-input prog-open-select" style="height: 44px; font-size: 15px;">
                        <option value="1" ${isOpen?'selected':''}>Open</option>
                        <option value="0" ${!isOpen?'selected':''}>Closed</option>
                    </select>
                </div>
                <div style="display: flex; align-items: flex-end; justify-content: center;">
                    <button type="button" onclick="this.closest('.onb-prog-row').remove()" style="background: none; border: none; color: var(--brand-rose-400); cursor: pointer; font-size: 20px; height: 44px; display: flex; align-items: center;" title="Remove Program">âœ•</button>
                </div>
            `;

            list.appendChild(row);
        }

        function renderProgramEssentials() {
            const list = document.getElementById('onbProgramEssentialsList');
            if (!list) return;
            list.innerHTML = '';

            const progs = onbDataState.programs || [];
            if (progs.length === 0) {
                list.innerHTML = '<div style="color: #648781; font-size: 15px;">No programs found. Please add programs in Step 4.</div>';
                return;
            }

            progs.forEach(p => {
                const card = document.createElement('div');
                card.className = 'onb-ess-row';
                card.setAttribute('data-prog-id', p.id);
                card.style.cssText = 'background: #F1F7F4; border: 1px solid #DDE9E3; border-radius: 14px; padding: 18px;';

                card.innerHTML = `
                    <div style="font-size: 16px; font-weight: 700; color: #ffffff; margin-bottom: 14px;">${p.name} (${p.program_type})</div>
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 14px;">
                        <div>
                            <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Eligibility Criteria</label>
                            <input type="text" class="brand-input ess-elig-input" placeholder="e.g. Min 50% aggregate in graduation from recognized university" value="${p.eligibility || ''}" style="height: 44px; font-size: 15px;" />
                        </div>
                        <div>
                            <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Application Deadline</label>
                            <input type="text" class="brand-input ess-deadline-input" placeholder="e.g. 30 June 2026" value="${p.application_deadline || ''}" style="height: 44px; font-size: 15px;" />
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 16px;">
                        <div>
                            <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Application Fee (â‚¹)</label>
                            <input type="text" class="brand-input ess-fee-input" placeholder="e.g. â‚¹1,500" value="${p.application_fee || ''}" style="height: 44px; font-size: 15px;" />
                        </div>
                        <div>
                            <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Application Form URL</label>
                            <input type="url" class="brand-input ess-url-input" placeholder="https://apply.college.edu" value="${p.application_url || ''}" style="height: 44px; font-size: 15px;" />
                        </div>
                    </div>
                `;

                list.appendChild(card);
            });
        }

        function renderProgramFees() {
            const list = document.getElementById('onbProgramFeesList');
            if (!list) return;
            list.innerHTML = '';

            const progs = onbDataState.programs || [];
            if (progs.length === 0) {
                list.innerHTML = '<div style="color: #648781; font-size: 15px;">No programs found. Please add programs in Step 4.</div>';
                return;
            }

            progs.forEach(p => {
                const card = document.createElement('div');
                card.className = 'onb-fee-row';
                card.setAttribute('data-prog-id', p.id);
                card.setAttribute('data-prog-name', p.name);
                card.style.cssText = 'background: #F1F7F4; border: 1px solid #DDE9E3; border-radius: 14px; padding: 18px;';

                card.innerHTML = `
                    <div style="font-size: 16px; font-weight: 700; color: #ffffff; margin-bottom: 14px;">${p.name} â€” Fee Structure</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                        <div>
                            <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Annual Tuition Fee (â‚¹)</label>
                            <input type="number" class="brand-input fee-tuition-input" placeholder="e.g. 450000" value="${p.tuition_fee || ''}" style="height: 44px; font-size: 15px;" />
                        </div>
                        <div>
                            <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Registration Fee (â‚¹)</label>
                            <input type="number" class="brand-input fee-reg-input" placeholder="e.g. 25000" value="${p.registration_fee || ''}" style="height: 44px; font-size: 15px;" />
                        </div>
                        <div>
                            <label style="font-size: 14px; font-weight: 600; color: #648781; margin-bottom: 4px; display: block;">Total Course Fee (â‚¹)</label>
                            <input type="number" class="brand-input fee-total-input" placeholder="e.g. 925000" value="${p.total_fee || ''}" style="height: 44px; font-size: 15px;" />
                        </div>
                    </div>
                `;

                list.appendChild(card);
            });
        }

        function toggleOnbScholarshipFields(val) {
            const fields = document.getElementById('onbScholarshipFields');
            if (fields) fields.style.display = val === '1' ? 'block' : 'none';
        }

        async function handleOnbFileSelect(input) {
            const file = input.files[0];
            if (!file) return;

            const statusEl = document.getElementById('onbUploadStatus');
            if (statusEl) {
                statusEl.style.display = 'block';
                statusEl.style.color = 'var(--brand-indigo-400)';
                statusEl.innerText = `â³ Uploading and indexing "${file.name}"...`;
            }

            const formData = new FormData();
            formData.append('file', file);
            formData.append('title', file.name.replace(/\.[^/.]+$/, ''));
            formData.append('category', 'brochure');

            try {
                const res = await fetch('/v1/onboarding/upload-document', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token },
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    if (statusEl) {
                        statusEl.style.color = '#10b981';
                        statusEl.innerHTML = `âœ“ <strong>${file.name}</strong> successfully uploaded and indexed into AI knowledge base!`;
                    }
                    showToast('Document uploaded & indexed!', 'success');
                } else {
                    if (statusEl) {
                        statusEl.style.color = 'var(--brand-rose-400)';
                        statusEl.innerText = `âœ• Upload failed: ${data.message || 'An error occurred'}`;
                    }
                    showToast(data.message || 'Upload failed', 'error');
                }
            } catch(e) {
                console.error(e);
                if (statusEl) {
                    statusEl.style.color = 'var(--brand-rose-400)';
                    statusEl.innerText = `âœ• Network error during upload: ${e.message}`;
                }
            }
        }

        // Live Test Chat Preview in Onboarding
        function initOnbChatPreview() {
            const bot = onbDataState.chatbot || {};
            const org = onbDataState.organization || {};
            const botName = bot.name || `${org.short_name || 'College'} Admissions Assistant`;
            const headerEl = document.getElementById('onbPreviewBotHeader');
            if (headerEl) headerEl.innerText = botName;

            const chatMessages = document.getElementById('onbChatMessages');
            if (chatMessages && chatMessages.children.length === 0) {
                const welcomeMsg = bot.welcome_message || `Hello! Welcome to ${org.name || 'our institution'} admissions desk. How can I help you today?`;
                appendOnbBubble('bot', welcomeMsg);
            }
        }

        let onbChatVisitorId = 'onb_preview_' + Math.random().toString(36).substring(2, 11);

        function resetOnbChat() {
            const chatMessages = document.getElementById('onbChatMessages');
            if (chatMessages) chatMessages.innerHTML = '';
            onbChatConversationId = null;
            onbChatVisitorId = 'onb_preview_' + Math.random().toString(36).substring(2, 11);
            initOnbChatPreview();
        }

        function sendOnbTestPrompt(promptText) {
            const inp = document.getElementById('onbChatInput');
            if (inp) {
                inp.value = promptText;
                sendOnbChatMessage();
            }
        }

        async function sendOnbChatMessage() {
            const inp = document.getElementById('onbChatInput');
            if (!inp) return;
            const text = inp.value.trim();
            if (!text) return;

            appendOnbBubble('user', text);
            inp.value = '';

            const bot = onbDataState.chatbot || {};
            const botToken = bot.bot_token;
            if (!botToken) {
                appendOnbBubble('bot', 'AI Assistant is initializing. Please complete earlier steps first.');
                return;
            }

            // Typing bubble
            const typingBubble = appendOnbBubble('bot', 'Thinking...', true);

            try {
                const res = await fetch('/v1/chat/completions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        bot_token: botToken,
                        visitor_id: onbChatVisitorId,
                        message: text,
                        is_test: 1
                    })
                });
                const data = await res.json();
                typingBubble.remove();

                if (data.status === 'success' && data.data) {
                    if (data.data.conversation_id) {
                        onbChatConversationId = data.data.conversation_id;
                    }
                    const reply = data.data.response || data.data.reply || 'I am ready to assist your prospective students.';
                    appendOnbBubble('bot', reply);
                } else {
                    appendOnbBubble('bot', data.message || 'I encountered an issue retrieving the response. Please try again.');
                }
            } catch(e) {
                typingBubble.remove();
                appendOnbBubble('bot', 'Connection error testing assistant. Please try again.');
            }
        }

        function appendOnbBubble(sender, text, isTyping = false) {
            const list = document.getElementById('onbChatMessages');
            if (!list) return null;

            const wrap = document.createElement('div');
            wrap.style.cssText = sender === 'user' 
                ? 'display: flex; justify-content: flex-end;' 
                : 'display: flex; justify-content: flex-start;';

            const bubble = document.createElement('div');
            bubble.className = 'onb-chat-bubble';
            if (isTyping) {
                bubble.className += ' edvora-typing-bubble';
                bubble.style.cssText = 'background: #E6F7D2; border: 1px solid #B9D7C7; padding: 6px 11px; border-radius: 10px 10px 10px 2px; display: inline-flex; align-items: center; justify-content: center; gap: 4px; min-height: 28px;';
                bubble.innerHTML = '<span class="edvora-typing-dot"></span><span class="edvora-typing-dot"></span><span class="edvora-typing-dot"></span>';
            } else {
                bubble.style.cssText = sender === 'user'
                    ? 'background: var(--brand-indigo-600); color: #ffffff; padding: 12px 18px; border-radius: 16px 16px 4px 16px; max-width: 82%; font-size: 15px; line-height: 1.55;'
                    : 'background: #FFFFFF; border: 1px solid #DDE9E3; color: #092F2E; padding: 12px 18px; border-radius: 16px 16px 16px 4px; max-width: 85%; font-size: 15px; line-height: 1.55;';
                bubble.innerText = text;
            }
            wrap.appendChild(bubble);
            list.appendChild(wrap);
            list.scrollTop = list.scrollHeight;
            return wrap;
        }

        function renderReadinessAudit() {
            const scoreNum = document.getElementById('onbReadinessScoreNum');
            const scoreLabel = document.getElementById('onbReadinessScoreLabel');
            const list = document.getElementById('onbReadinessAuditList');

            if (scoreNum) scoreNum.innerText = `${onbDataState.readiness_score}%`;
            if (scoreLabel) scoreLabel.innerText = onbDataState.readiness_label;

            if (list) {
                list.innerHTML = '';
                const bd = onbDataState.readiness_breakdown || {};
                Object.keys(bd).forEach(k => {
                    const item = bd[k];
                    const row = document.createElement('div');
                    row.style.cssText = 'background: #F1F7F4; border: 1px solid #DDE9E3; border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between;';

                    const icon = item.complete ? 'âœ“' : 'â—‹';
                    const iconColor = item.complete ? '#10b981' : 'var(--brand-text-muted)';
                    const labelColor = item.complete ? '#ffffff' : 'var(--brand-text-secondary)';

                    row.innerHTML = `
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span style="color: ${iconColor}; font-weight: 800; font-size: 17px;">${icon}</span>
                            <span style="font-size: 15px; font-weight: 600; color: ${labelColor};">${item.label}</span>
                        </div>
                        <span style="font-size: 14.5px; color: #648781;">${item.details}</span>
                    `;
                    list.appendChild(row);
                });
            }
        }

        function copyOnbSnippet() {
            const code = document.getElementById('onbEmbedScriptCode').innerText;
            navigator.clipboard.writeText(code).then(() => {
                showToast('Website embed snippet copied to clipboard!', 'success');
            });
        }

        function emailWebmasterSnippet() {
            const code = document.getElementById('onbEmbedScriptCode').innerText;
            const subject = encodeURIComponent('Edvora AI Chatbot Website Integration Snippet');
            const body = encodeURIComponent(`Hi Team,\n\nPlease paste the following script tag before the closing </body> tag on our official website:\n\n${code}\n\nThank you!`);
            window.location.href = `mailto:?subject=${subject}&body=${body}`;
        }

        async function completeOnboardingWizard() {
            try {
                const res = await fetch('/v1/onboarding/complete', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('ðŸŽ‰ Setup complete! Your AI Assistant is primed and ready.', 'success');
                    exitOnboardingToDashboard();
                } else {
                    showToast(data.message || 'Failed to complete onboarding', 'error');
                }
            } catch(e) {
                console.error(e);
                exitOnboardingToDashboard();
            }
        }


        // â”€â”€ SCHOLARSHIPS & FINANCIAL AID JS LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let currentScholarshipConfig = null;
        let currentScholarshipCourses = [];

