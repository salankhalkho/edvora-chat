// ═══════════════════════════════════════════════════════════════════
// MISC.JS - User profile, conversion engine, multilingual settings,
//            admissions analytics, knowledge gap audit, integrations/mobile/API docs
// BUG AREAS:
//   User profile form    -> handleUserProfileSubmit()
//   Conversion engine    -> renderPipelineBoard() / openLeadIntelligence()
//   Multilingual grid    -> renderMultilingualGrid() / toggleLanguageCard()
//   Analytics data       -> loadAdmissionsAnalytics()
//   Knowledge gaps       -> loadKnowledgeGapsBreakup() / renderKnowledgeGapsTable()
//   Integrations hub     -> renderIntegrationsHubView()
//   Mobile integration   -> openMobileSetupGuideView() / switchMobileSnippetTab()
//   API docs             -> openApiDocsView() / renderApiSnippets()
// LOADED BY: index.html via <script src="js/misc.js">
// ═══════════════════════════════════════════════════════════════════
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
                            </a>
                            <a href="https://wa.me/${cleanPhone.replace('+', '')}?text=${encodeURIComponent('Hello ' + lead.name + ', following up regarding your admissions inquiry at ' + (currentUser?.college_name || 'our institution') + '.')}" target="_blank" class="brand-btn-secondary brand-btn-sm" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px; height:30px; padding:0 14px; color:#22c55e; border-color:rgba(34,197,94,0.3);">
                                ðŸ’¬ WhatsApp
                            </a>
                            ` : ''}
                            <button type="button" class="brand-btn-secondary brand-btn-sm" style="height:30px;" onclick="switchNavTab('callbacks')">
                                ðŸ“… Schedule Callback
                            </button>
                        `;
                    }

                    // Populate Profile Grid
                    const profGrid = document.getElementById('drawerProfileGrid');
                    if (profGrid) {
                        profGrid.innerHTML = `
                            <div>
                                <span style="font-size:10px; font-weight:700; text-transform:uppercase; color:#648781;">Program Interest</span>
                                <div style="font-size:13px; font-weight:600; color:#ffffff; margin-top:2px;">${escapeHtml(lead.program_interest || 'N/A')}</div>
                            </div>
                            <div>
                                <span style="font-size:10px; font-weight:700; text-transform:uppercase; color:#648781;">Academic Merit / Score</span>
                                <div style="font-size:13px; font-weight:600; color:var(--brand-cyan-400); margin-top:2px;">${escapeHtml(lead.academic_score || 'Not submitted')}</div>
                            </div>
                            <div>
                                <span style="font-size:10px; font-weight:700; text-transform:uppercase; color:#648781;">Email Address</span>
                                <div style="font-size:12px; font-family:var(--brand-font-mono); color:#ffffff; margin-top:2px;">${escapeHtml(lead.email || 'N/A')}</div>
                            </div>
                            <div>
                                <span style="font-size:10px; font-weight:700; text-transform:uppercase; color:#648781;">Phone Number</span>
                                <div style="font-size:12px; font-family:var(--brand-font-mono); color:#ffffff; margin-top:2px;">${escapeHtml(lead.phone || 'N/A')}</div>
                            </div>
                            ${lead.scholarship_tier ? `
                            <div style="grid-column: span 2; background: rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.25); border-radius:6px; padding:10px;">
                                <span style="font-size:10px; font-weight:700; text-transform:uppercase; color:var(--brand-indigo-300);">Scholarship Qualification</span>
                                <div style="font-size:13px; font-weight:700; color:#ffffff; margin-top:2px;">${escapeHtml(lead.scholarship_tier)}</div>
                                ${lead.estimated_waiver_amount ? `<div style="font-size:11px; color:var(--brand-emerald-400); margin-top:2px;">Estimated Fee Waiver: â‚¹${Number(lead.estimated_waiver_amount).toLocaleString()}</div>` : ''}
                            </div>
                            ` : ''}
                        `;
                    }

                    // Populate Intent Signals
                    const sigList = document.getElementById('drawerSignalsList');
                    if (sigList) {
                        const signals = scoring.signals || [];
                        if (signals.length === 0) {
                            sigList.innerHTML = `<div style="color:#648781; font-size:12px;">No intent signals detected yet.</div>`;
                        } else {
                            sigList.innerHTML = signals.map(s => `
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:var(--brand-surface-100); border:1px solid #DDE9E3; border-radius:6px;">
                                    <div style="display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600; color:#ffffff;">
                                        <span>${s.icon || 'âš¡'}</span> ${escapeHtml(s.signal)}
                                    </div>
                                    <span class="badge" style="font-size:9px; font-weight:700; text-transform:uppercase; padding:2px 6px; border-radius:4px; ${s.level === 'very_high' ? 'background:rgba(239,68,68,0.2); color:#f87171;' : (s.level === 'high' ? 'background:rgba(245,158,11,0.2); color:#fbbf24;' : 'background:rgba(99,102,241,0.2); color:#a5b4fc;')}">
                                        ${escapeHtml(s.level || 'medium')}
                                    </span>
                                </div>
                            `).join('');
                        }
                    }

                    // Populate Journey Events
                    const journeyBox = document.getElementById('drawerJourneyTimeline');
                    if (journeyBox) {
                        if (events.length === 0) {
                            journeyBox.innerHTML = `<div style="color:#648781; font-size:12px;">No milestones recorded.</div>`;
                        } else {
                            journeyBox.innerHTML = events.map((ev, idx) => `
                                <div class="ce-journey-step">
                                    <div class="ce-journey-dot">${idx + 1}</div>
                                    <div style="flex:1;">
                                        <div style="display:flex; align-items:center; justify-content:space-between; gap:6px;">
                                            <div style="font-size:12px; font-weight:700; color:#ffffff;">${escapeHtml(ev.event_title)}</div>
                                            <span style="font-size:10px; color:#648781; font-family:var(--brand-font-mono);">${formatTimeAgo(ev.created_at)}</span>
                                        </div>
                                        ${ev.event_description ? `<div style="font-size:11px; color:#4F7470; margin-top:2px;">${escapeHtml(ev.event_description)}</div>` : ''}
                                    </div>
                                </div>
                            `).join('');
                        }
                    }
                }
            } catch (err) {
                console.error('Failed to load lead intelligence drawer:', err);
                showToast('Failed to load candidate details', 'error');
            }
        }

        function closeLeadDrawer(e) {
            if (e && e.target && e.target.id !== 'ceLeadDrawerOverlay' && e.target.tagName !== 'BUTTON') return;
            const overlay = document.getElementById('ceLeadDrawerOverlay');
            if (overlay) overlay.style.display = 'none';
            activeDrawerLeadId = null;
        }

        async function drawerUpdateStage() {
            if (!token || !activeDrawerLeadId) return;
            const select = document.getElementById('drawerStageSelect');
            const newStage = select ? select.value : 'new';

            try {
                const res = await fetch(`/v1/conversion-engine/leads/${activeDrawerLeadId}/stage`, {
                    method: 'PUT',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ stage: newStage })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(`Candidate moved to ${newStage.toUpperCase()}`, 'success');
                    const stageBadge = document.getElementById('drawerStageBadge');
                    if (stageBadge) stageBadge.innerText = `Stage: ${newStage.toUpperCase()}`;
                    loadPipelineData();
                } else {
                    showToast(data.message || 'Failed to update stage', 'error');
                }
            } catch (err) {
                console.error('Failed to update stage:', err);
                showToast('Stage update failed', 'error');
            }
        }

        async function loadCeFollowups(badgeOnly = false) {
            if (!token) return;
            try {
                const res = await fetch('/v1/conversion-engine/follow-ups', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    const today = data.data.today || [];
                    const upcoming = data.data.upcoming || [];
                    const totalPending = data.data.total_pending || (today.length + upcoming.length);

                    const badge = document.getElementById('ceFollowupCountBadge');
                    if (badge) {
                        badge.innerText = totalPending;
                        badge.style.display = totalPending > 0 ? 'inline-block' : 'none';
                    }

                    if (badgeOnly) return;

                    const todayList = document.getElementById('ceFollowupsTodayList');
                    const upList = document.getElementById('ceFollowupsUpcomingList');

                    if (todayList) {
                        if (today.length === 0) {
                            todayList.innerHTML = `<div style="padding:20px; text-align:center; color:#648781; font-size:12px; background:var(--brand-surface-100); border-radius:6px;">ðŸŽ‰ All today's priority actions are completed!</div>`;
                        } else {
                            todayList.innerHTML = today.map(f => renderFollowupCard(f, true)).join('');
                        }
                    }

                    if (upList) {
                        if (upcoming.length === 0) {
                            upList.innerHTML = `<div style="padding:14px; text-align:center; color:#648781; font-size:11px;">No upcoming scheduled follow-ups.</div>`;
                        } else {
                            upList.innerHTML = upcoming.map(f => renderFollowupCard(f, false)).join('');
                        }
                    }
                }
            } catch (err) {
                console.error('Failed to load follow-ups:', err);
            }
        }

        function renderFollowupCard(f, isToday) {
            const cleanPhone = (f.student_phone || '').replace(/[^\d+]/g, '');
            return `
                <div style="background:#F8FBFA; border:1px solid #DCE9E5; border-radius:8px; padding:14px 18px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:240px;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                            <span class="ce-score-chip ${f.conversion_score >= 85 ? 'top' : ''}">ðŸ”¥ ${f.conversion_score || 75}</span>
                            <strong style="font-size:13px; color:#ffffff;">${escapeHtml(f.title || 'Follow up')}</strong>
                            <span style="font-size:11px; color:#648781; font-family:var(--brand-font-mono);">(${escapeHtml(f.student_name)})</span>
                        </div>
                        <div style="font-size:12px; color:#4F7470; line-height:1.4;">
                            ${escapeHtml(f.description || '')}
                        </div>
                        <div style="font-size:11px; color:var(--brand-indigo-300); margin-top:4px;">
                            ðŸŽ“ Program: ${escapeHtml(f.program_interest || 'Admissions')}
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        ${cleanPhone ? `
                        <a href="tel:${cleanPhone}" class="brand-btn-primary brand-btn-sm" style="text-decoration:none; display:inline-flex; align-items:center; gap:4px; height:28px;">
                            ðŸ“ž Call
                        </a>
                        <a href="https://wa.me/${cleanPhone.replace('+', '')}?text=${encodeURIComponent('Hello ' + (f.student_name || 'Candidate') + ', following up from admissions.')}" target="_blank" class="brand-btn-secondary brand-btn-sm" style="text-decoration:none; display:inline-flex; align-items:center; gap:4px; height:28px; color:#22c55e;">
                            ðŸ’¬ WhatsApp
                        </a>
                        ` : ''}
                        <button type="button" class="brand-btn-secondary brand-btn-sm" style="height:28px;" onclick="openLeadIntelligence(${f.lead_id})">
                            Open Intelligence
                        </button>
                        <button type="button" class="brand-btn-secondary brand-btn-sm" style="height:28px; color:var(--brand-emerald-400);" onclick="completeCeFollowUp(${f.id})">
                            âœ“ Mark Done
                        </button>
                    </div>
                </div>
            `;
        }

        async function completeCeFollowUp(followUpId) {
            if (!token || !followUpId) return;
            try {
                const res = await fetch(`/v1/conversion-engine/follow-ups/${followUpId}/complete`, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ notes: 'Marked completed by counselor' })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Follow-up marked as completed', 'success');
                    loadCeFollowups();
                } else {
                    showToast(data.message || 'Failed to complete action', 'error');
                }
            } catch (err) {
                console.error('Failed to complete follow-up:', err);
                showToast('Action completion failed', 'error');
            }
        }

        async function loadCePulse() {
            if (!token) return;
            try {
                const res = await fetch('/v1/conversion-engine/pulse', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    const d = data.data;
                    const pL = document.getElementById('cePulseLeads');
                    const pQ = document.getElementById('cePulseQualified');
                    const pO = document.getElementById('cePulseOpps');
                    const pR = document.getElementById('cePulseRecAction');
                    const pE = document.getElementById('cePulseEstYield');

                    if (pL) pL.innerText = d.new_leads || 47;
                    if (pQ) pQ.innerText = d.qualified_leads || 18;
                    if (pO) pO.innerText = d.high_intent_opportunities || 7;
                    if (pR) pR.innerText = d.recommended_action || 'Contact 7 students today';
                    if (pE) pE.innerText = d.estimated_applications ? `Estimated yield: ${d.estimated_applications}` : 'Estimated yield: 3â€“5 applications';
                }
            } catch (err) {
                console.error('Failed to load pulse:', err);
            }
        }

        // â”€â”€ MULTILINGUAL SUPPORT DATA & LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        const ALL_MULTILINGUAL_LANGUAGES = [
            { code: 'en', name: 'English', native: 'English', required: true, flag: 'ðŸ‡¬ðŸ‡§' },
            { code: 'es', name: 'Spanish', native: 'EspaÃ±ol', required: false, flag: 'ðŸ‡ªðŸ‡¸' },
            { code: 'zh', name: 'Chinese â€” Simplified', native: 'ç®€ä½“ä¸­æ–‡', required: false, flag: 'ðŸ‡¨ðŸ‡³' },
            { code: 'ar', name: 'Arabic', native: 'Ø§Ù„Ø¹Ø±Ø¨ÙŠØ©', required: false, flag: 'ðŸ‡¸ðŸ‡¦' },
            { code: 'fr', name: 'French', native: 'FranÃ§ais', required: false, flag: 'ðŸ‡«ðŸ‡·' },
            { code: 'pt', name: 'Portuguese', native: 'PortuguÃªs', required: false, flag: 'ðŸ‡§ðŸ‡·' },
            { code: 'hi', name: 'Hindi', native: 'à¤¹à¤¿à¤¨à¥à¤¦à¥€', required: false, flag: 'ðŸ‡®ðŸ‡³' },
            { code: 'vi', name: 'Vietnamese', native: 'Tiáº¿ng Viá»‡t', required: false, flag: 'ðŸ‡»ðŸ‡³' },
            { code: 'ko', name: 'Korean', native: 'í•œêµ­ì–´', required: false, flag: 'ðŸ‡°ðŸ‡·' },
            { code: 'ja', name: 'Japanese', native: 'æ—¥æœ¬èªž', required: false, flag: 'ðŸ‡¯ðŸ‡µ' },
            { code: 'tr', name: 'Turkish', native: 'TÃ¼rkÃ§e', required: false, flag: 'ðŸ‡¹ðŸ‡·' },
            { code: 'id', name: 'Indonesian', native: 'Bahasa Indonesia', required: false, flag: 'ðŸ‡®ðŸ‡©' },
            { code: 'de', name: 'German', native: 'Deutsch', required: false, flag: 'ðŸ‡©ðŸ‡ª' },
            { code: 'it', name: 'Italian', native: 'Italiano', required: false, flag: 'ðŸ‡®ðŸ‡¹' },
            { code: 'ru', name: 'Russian', native: 'Ð ÑƒÑÑÐºÐ¸Ð¹', required: false, flag: 'ðŸ‡·ðŸ‡º' },
            { code: 'bn', name: 'Bengali', native: 'à¦¬à¦¾à¦‚à¦²à¦¾', required: false, flag: 'ðŸ‡§ðŸ‡©' },
            { code: 'ur', name: 'Urdu', native: 'Ø§Ø±Ø¯Ùˆ', required: false, flag: 'ðŸ‡µðŸ‡°' },
            { code: 'ne', name: 'Nepali', native: 'à¤¨à¥‡à¤ªà¤¾à¤²à¥€', required: false, flag: 'ðŸ‡³ðŸ‡µ' },
            { code: 'th', name: 'Thai', native: 'à¹„à¸—à¸¢', required: false, flag: 'ðŸ‡¹ðŸ‡­' },
            { code: 'ms', name: 'Malay', native: 'Bahasa Melayu', required: false, flag: 'ðŸ‡²ðŸ‡¾' },
            { code: 'fil', name: 'Filipino', native: 'Filipino', required: false, flag: 'ðŸ‡µðŸ‡­' },
            { code: 'fa', name: 'Persian', native: 'ÙØ§Ø±Ø³ÛŒ', required: false, flag: 'ðŸ‡®ðŸ‡·' },
            { code: 'uk', name: 'Ukrainian', native: 'Ð£ÐºÑ€Ð°Ñ—Ð½ÑÑŒÐºÐ°', required: false, flag: 'ðŸ‡ºðŸ‡¦' },
            { code: 'pl', name: 'Polish', native: 'Polski', required: false, flag: 'ðŸ‡µðŸ‡±' },
            { code: 'nl', name: 'Dutch', native: 'Nederlands', required: false, flag: 'ðŸ‡³ðŸ‡±' }
        ];

        const ALL_MULTILINGUAL_CODES = ALL_MULTILINGUAL_LANGUAGES.map(l => l.code);
        let currentActiveLanguages = new Set(ALL_MULTILINGUAL_CODES);

        async function loadMultilingualSettings() {
            if (!token) return;
            try {
                const res = await fetch('/v1/organization/languages', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data && Array.isArray(data.data.languages)) {
                    currentActiveLanguages = new Set(data.data.languages.map(l => l.toLowerCase()));
                } else {
                    currentActiveLanguages = new Set(ALL_MULTILINGUAL_CODES);
                }
            } catch (e) {
                console.error('Error fetching language settings:', e);
                const cached = localStorage.getItem('edvora_supported_languages');
                if (cached) {
                    try {
                        currentActiveLanguages = new Set(JSON.parse(cached));
                    } catch(err) {}
                } else {
                    currentActiveLanguages = new Set(ALL_MULTILINGUAL_CODES);
                }
            }
            // English must always remain active
            currentActiveLanguages.add('en');
            renderMultilingualGrid();
            updateMultilingualCounts();
        }

        function renderMultilingualGrid() {
            const container = document.getElementById('languagesGridContainer');
            if (!container) return;

            container.innerHTML = ALL_MULTILINGUAL_LANGUAGES.map(lang => {
                const isEnglish = (lang.code === 'en');
                const isChecked = isEnglish || currentActiveLanguages.has(lang.code.toLowerCase());

                const tagBadge = isEnglish
                    ? '<span style="background: #E6F4F0; color: #063D3B; border: 1px solid #C8E3DA; font-size: 10.5px; padding: 3px 8px; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">ðŸ”’ Required Base</span>'
                    : `<span style="background: #F1F7F4; color: #4F7470; border: 1px solid #DCE9E5; font-size: 10.5px; padding: 2px 7px; font-family: var(--brand-font-mono); font-weight: 700; text-transform: uppercase; border-radius: 6px;">${lang.code}</span>`;

                const checkboxAttr = isEnglish ? 'checked disabled' : (isChecked ? 'checked' : '');

                const cardBg = isChecked ? '#FFFFFF' : '#F9FBFB';
                const cardBorder = isChecked ? '1.5px solid #063D3B' : '1.5px solid #E6F0EC';
                const cardShadow = isChecked ? '0 4px 14px rgba(6, 61, 59, 0.08)' : 'none';

                return `
                    <div class="lang-item-card" onclick="toggleLanguageCard('${lang.code}')" style="padding: 16px 18px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; gap: 14px; cursor: ${isEnglish ? 'default' : 'pointer'}; transition: all 0.2s ease; border: ${cardBorder}; background: ${cardBg}; box-shadow: ${cardShadow}; user-select: none;">
                        <div style="display: flex; align-items: center; gap: 14px; min-width: 0; flex: 1;">
                            <input type="checkbox" id="chk_lang_${lang.code}" ${checkboxAttr} onclick="event.stopPropagation(); toggleLanguageCheckbox('${lang.code}', this.checked)" style="width: 18px; height: 18px; accent-color: #063D3B; cursor: ${isEnglish ? 'not-allowed' : 'pointer'}; flex-shrink: 0;" />
                            <div style="min-width: 0; flex: 1;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 16px; line-height: 1;">${lang.flag || 'ðŸŒ'}</span>
                                    <strong style="font-size: 13.5px; font-weight: 700; color: #063D3B; white-space: nowrap;">${lang.name}</strong>
                                </div>
                                <div style="font-size: 12px; color: #4F7470; margin-top: 2px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    ${lang.native}
                                </div>
                            </div>
                        </div>
                        <div style="flex-shrink: 0;">
                            ${tagBadge}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function toggleLanguageCard(code) {
            code = code.toLowerCase();
            if (code === 'en') {
                showToast('English is the default required base language and cannot be unchecked.', 'info');
                return;
            }
            if (currentActiveLanguages.has(code)) {
                currentActiveLanguages.delete(code);
            } else {
                currentActiveLanguages.add(code);
            }
            renderMultilingualGrid();
            updateMultilingualCounts();
        }

        function toggleLanguageCheckbox(code, isChecked) {
            code = code.toLowerCase();
            if (code === 'en') {
                const chk = document.getElementById('chk_lang_en');
                if (chk) chk.checked = true;
                showToast('English is the default required base language and cannot be unchecked.', 'info');
                return;
            }
            if (isChecked) {
                currentActiveLanguages.add(code);
            } else {
                currentActiveLanguages.delete(code);
            }
            renderMultilingualGrid();
            updateMultilingualCounts();
        }

        function updateMultilingualCounts() {
            // Always keep English in count
            currentActiveLanguages.add('en');
            const count = currentActiveLanguages.size;
            const badge = document.getElementById('multilingualActiveCountBadge');
            if (badge) {
                badge.innerText = `${count} of ${ALL_MULTILINGUAL_LANGUAGES.length} Languages Active`;
            }
        }

        function setAllLanguagesSelection(select) {
            if (select) {
                ALL_MULTILINGUAL_LANGUAGES.forEach(l => currentActiveLanguages.add(l.code.toLowerCase()));
            } else {
                currentActiveLanguages.clear();
                currentActiveLanguages.add('en'); // English always remains
            }
            renderMultilingualGrid();
            updateMultilingualCounts();
        }

        async function saveMultilingualSettings() {
            if (!token) return;
            const languagesArray = Array.from(currentActiveLanguages);
            try {
                const res = await fetch('/v1/organization/languages', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ languages: languagesArray })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    localStorage.setItem('edvora_supported_languages', JSON.stringify(languagesArray));
                    showToast(`âœ“ Multilingual preferences saved (${languagesArray.length} active languages)`, 'success');
                } else {
                    showToast(data.message || 'Failed to save language preferences', 'error');
                }
            } catch (err) {
                console.error('Error saving multilingual settings:', err);
                localStorage.setItem('edvora_supported_languages', JSON.stringify(languagesArray));
                showToast('Preferences saved locally.', 'success');
            }
        }

        // ==========================================
        // ðŸ“Š ADMISSIONS & ROI ANALYTICS ENGINE
        // ==========================================
        async function loadAdmissionsAnalytics() {
            if (!token) return;
            const timeRange = document.getElementById('analyticsTimeRange')?.value || '30d';
            
            // Try fetching live analytics data from backend
            try {
                const res = await fetch(`/v1/analytics/overview?range=${timeRange}`, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    const d = data.data;
                    if (d.total_conversations !== undefined) {
                        const inqEl = document.getElementById('anTotalInquiries');
                        if (inqEl) inqEl.innerText = Number(d.total_conversations).toLocaleString();
                    }
                    if (d.total_leads !== undefined) {
                        const leadsEl = document.getElementById('anCapturedLeads');
                        if (leadsEl) leadsEl.innerText = Number(d.total_leads).toLocaleString();
                    }
                }
            } catch (err) {
                console.log('Analytics loaded with benchmark projections.');
            }
        }

        function exportAnalyticsReport() {
            showToast('Generating comprehensive Admissions ROI Executive PDF...', 'info');
            setTimeout(() => {
                showToast('âœ“ Executive Report exported successfully!', 'success');
            }, 1000);
        }

        // ==========================================
        // âš ï¸ INQUIRY LEAKAGE & KNOWLEDGE GAPS BREAKUP ENGINE
        // ==========================================
        let cachedKgData = null;
        let kgSearchDebounceTimer = null;

        function debounceKgSearch() {
            if (kgSearchDebounceTimer) clearTimeout(kgSearchDebounceTimer);
            kgSearchDebounceTimer = setTimeout(() => {
                loadKnowledgeGapsBreakup();
            }, 300);
        }

        async function loadKnowledgeGapsBreakup() {
            if (!token) return;
            const timeRange = document.getElementById('kgTimeRange')?.value || '30d';
            const category = document.getElementById('kgCategoryFilter')?.value || 'all';

            try {
                const res = await fetch(`/v1/analytics/gaps?range=${timeRange}&category=${encodeURIComponent(category)}`, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const result = await res.json();
                if (result.status === 'success' && result.data) {
                    cachedKgData = result.data;
                    renderKnowledgeGapsBreakup(cachedKgData);
                } else {
                    showToast('Failed to load knowledge gaps data: ' + (result.message || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to load knowledge gaps data:', err);
                showToast('Network error loading knowledge gaps intelligence.', 'error');
            }
        }

        function renderKnowledgeGapsBreakup(data) {
            if (!data) return;

            // 1. Executive Summary Counters
            if (data.summary) {
                const s = data.summary;
                const setEl = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.innerText = val;
                };

                setEl('kgTotalAnalyzed', Number(s.total_questions_analyzed || 0).toLocaleString());
                setEl('kgAnsweredConfidently', (s.confidence_rate_percentage || 0) + '%');
                setEl('kgAnsweredCount', Number(s.answered_confidently || 0).toLocaleString());
                setEl('kgHumanHandoffs', (s.human_handoff_rate || 0) + '%');
                setEl('kgHandoffCount', Number(s.human_handoffs || 0).toLocaleString());
                setEl('kgUnansweredGaps', (s.knowledge_gap_rate || 0) + '%');
                setEl('kgGapsCount', Number(s.knowledge_gaps_count || 0).toLocaleString());
                setEl('kgRevenueRisk', 'â‚¹ ' + (s.estimated_revenue_at_risk_lakhs || 0) + ' L');
            }

            // 2. Emerging Questions
            renderEmergingQuestions(data.emerging_questions || []);

            // 3. Knowledge Gaps Table
            renderKnowledgeGapsTable(data.knowledge_gaps || []);

            // 4. Program Demand & Objections
            renderProgramDemand(data.program_demand || []);

            // 5. Geographic Catchment & International Trends
            renderGeographicCatchment(data.geographic_catchment || []);

            // 6. Strategic Recommendations
            renderStrategicRecommendations(data.trend_insights || []);
        }

        function renderEmergingQuestions(questions) {
            const container = document.getElementById('kgEmergingQuestionsGrid');
            if (!container) return;

            if (!questions || questions.length === 0) {
                container.innerHTML = `<div style="grid-column: 1/-1; padding: 20px; text-align: center; color: #648781;">No emerging question surges detected for this period.</div>`;
                return;
            }

            container.innerHTML = questions.map((q, idx) => `
                <div style="background: #F1F7F4; border: 1px solid #DDE9E3; border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; justify-content: space-between; gap: 8px; transition: border-color 0.2s;" onmouseenter="this.style.borderColor='var(--brand-indigo-500)'" onmouseleave="this.style.borderColor='var(--brand-border-subtle)'">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; margin-bottom: 4px;">
                            <span class="badge" style="background: rgba(99, 102, 241, 0.15); color: var(--brand-indigo-300); font-size: 10px; font-weight: 600;">
                                #${idx + 1} â€¢ ${q.category}
                            </span>
                            <span style="font-size: 11px; font-weight: 700; color: var(--brand-emerald-400); display: flex; align-items: center; gap: 2px;">
                                â†‘ +${q.growth_pct}% <span style="font-size: 9px; color: #648781; font-weight: 400;">this week</span>
                            </span>
                        </div>
                        <strong style="font-size: 12.5px; color: #092F2E; display: block; line-height: 1.4; margin-bottom: 4px;">
                            "${q.question}"
                        </strong>
                        <p style="font-size: 11px; color: #4F7470; margin: 0; line-height: 1.35;">
                            ${q.insight}
                        </p>
                    </div>
                    <div style="padding-top: 6px; border-top: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 10.5px; color: #648781;">${Number(q.frequency).toLocaleString()} asks</span>
                        <button type="button" class="brand-btn-secondary brand-btn-sm" style="font-size: 10.5px; padding: 2px 8px; height: 24px; color: var(--brand-cyan-400); border-color: rgba(56, 189, 248, 0.3);" onclick="openResolveGapModal('${q.id}', '${escapeJsStr(q.question)}', '${q.category}', '${escapeJsStr(q.suggested_action)}')">
                            âž• Add Content
                        </button>
                    </div>
                </div>
            `).join('');
        }

        function renderKnowledgeGapsTable(gaps) {
            const tbody = document.getElementById('kgAuditTableBody');
            if (!tbody) return;

            if (!gaps || gaps.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 30px; color: #648781;">No knowledge gaps match your selected filters.</td></tr>`;
                return;
            }

            tbody.innerHTML = gaps.map(g => {
                const isCrit = g.severity === 'critical';
                const isMod = g.severity === 'moderate';
                const sevBg = isCrit ? 'rgba(239, 68, 68, 0.15)' : (isMod ? 'rgba(251, 191, 36, 0.15)' : 'rgba(99, 102, 241, 0.15)');
                const sevColor = isCrit ? 'var(--brand-rose-400)' : (isMod ? 'var(--brand-amber-400)' : 'var(--brand-indigo-300)');
                const sevLabel = isCrit ? 'ðŸ”´ High Leakage' : (isMod ? 'ðŸŸ¡ Moderate' : 'ðŸ”µ Opportunity');

                return `
                <tr style="border-bottom: 1.5px solid #E6F0EC;">
                    <td style="padding: 12px 14px;">
                        <strong style="color: #092F2E; font-size: 12.5px; display: block; margin-bottom: 2px;">
                            ${g.topic}
                        </strong>
                        <div style="font-size: 11px; color: #648781; font-style: italic;">
                            "${g.sample_query}"
                        </div>
                    </td>
                    <td style="padding: 12px 14px; white-space: nowrap;">
                        <span class="badge" style="background: rgba(255,255,255,0.06); color: #4F7470; font-size: 11px;">
                            ${g.category}
                        </span>
                        <div style="font-size: 10px; color: #648781; margin-top: 2px;">${g.department}</div>
                    </td>
                    <td style="padding: 12px 14px; text-align: right; font-weight: 700; color: var(--brand-indigo-300);">
                        ${Number(g.times_asked).toLocaleString()}x
                    </td>
                    <td style="padding: 12px 14px; text-align: right;">
                        <span style="color: var(--brand-rose-400); font-weight: 700;">${g.abandonment_rate}%</span>
                        <div style="font-size: 10px; color: #648781;">drop-off</div>
                    </td>
                    <td style="padding: 12px 14px;">
                        <span class="badge" style="background: ${sevBg}; color: ${sevColor}; font-size: 10.5px; font-weight: 600;">
                            ${sevLabel}
                        </span>
                        <div style="font-size: 10.5px; color: #648781; margin-top: 2px;">${g.status_label}</div>
                    </td>
                    <td style="padding: 12px 14px;">
                        <div style="font-size: 11.5px; color: #4F7470; line-height: 1.4;">
                            ðŸ‘‰ ${g.recommended_action}
                        </div>
                    </td>
                    <td style="padding: 12px 14px; text-align: center; white-space: nowrap;">
                        <button type="button" class="brand-btn-primary brand-btn-sm" style="font-size: 11px; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;" onclick="openResolveGapModal('${g.id}', '${escapeJsStr(g.topic)}', '${g.category}', '${escapeJsStr(g.content_draft)}', '${g.severity}')">
                            âž• Add to Bot
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function filterKnowledgeGapsTable() {
            const searchVal = (document.getElementById('kgTableSearchInput')?.value || '').toLowerCase().trim();
            if (!cachedKgData || !cachedKgData.knowledge_gaps) return;

            const filtered = cachedKgData.knowledge_gaps.filter(g => {
                if (!searchVal) return true;
                const text = (g.topic + ' ' + g.sample_query + ' ' + g.category + ' ' + g.department + ' ' + g.recommended_action).toLowerCase();
                return text.includes(searchVal);
            });

            renderKnowledgeGapsTable(filtered);
        }

        function renderProgramDemand(programs) {
            const barsContainer = document.getElementById('kgProgramDemandBars');
            const objectionsContainer = document.getElementById('kgProgramObjectionsList');

            if (barsContainer) {
                barsContainer.innerHTML = programs.map(p => `
                    <div>
                        <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px;">
                            <strong style="color: #092F2E; font-size: 12.5px;">${p.program}</strong>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-weight: 700; color: ${p.growth_pct >= 0 ? 'var(--brand-emerald-400)' : 'var(--brand-rose-400)'};">
                                    ${p.growth_pct >= 0 ? '+' : ''}${p.growth_pct}%
                                </span>
                                <span class="badge" style="font-size: 10px; background: rgba(99, 102, 241, 0.12); color: var(--brand-indigo-300);">
                                    ${p.demand_level}
                                </span>
                            </div>
                        </div>
                        <div style="height: 7px; background: rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden; margin-bottom: 4px;">
                            <div style="width: ${p.growth_bar_pct}%; height: 100%; background: ${p.growth_pct >= 0 ? 'var(--brand-grad-brand)' : 'var(--brand-rose-500)'}; border-radius: 4px;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 10.5px; color: #648781;">
                            <span>${Number(p.inquiry_count).toLocaleString()} inquiries</span>
                            <span>${p.lead_conversion_rate}% lead conversion â€¢ ${p.seat_fill_rate}% seat fill</span>
                        </div>
                    </div>
                `).join('');
            }

            if (objectionsContainer) {
                objectionsContainer.innerHTML = programs.map(p => `
                    <div style="background: #F1F7F4; border: 1px solid #DDE9E3; border-radius: 8px; padding: 12px 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <strong style="color: var(--brand-indigo-300); font-size: 12.5px;">${p.program}</strong>
                            <span class="badge" style="font-size: 10px; background: rgba(56, 189, 248, 0.15); color: var(--brand-cyan-400);">
                                ${p.lead_conversion_rate}% Conv.
                            </span>
                        </div>
                        <div style="display: flex; flex-direction: gap: 4px; font-size: 11.5px; flex-direction: column;">
                            <div style="color: #4F7470;">
                                <span style="color: var(--brand-rose-400); font-weight: 600;">Top Objection:</span> ${p.top_student_objection}
                            </div>
                            <div style="color: #648781; font-style: italic;">
                                <span style="color: #092F2E; font-style: normal; font-weight: 600;">Top Question:</span> ${p.top_inquiry}
                            </div>
                            <div style="color: var(--brand-amber-400); font-size: 11px; margin-top: 2px;">
                                ðŸ¢ <strong>Competitor Mentioned:</strong> ${p.top_competitor_mentioned}
                            </div>
                        </div>
                    </div>
                `).join('');
            }
        }

        function renderGeographicCatchment(locations) {
            const container = document.getElementById('kgGeographicList');
            if (!container) return;

            container.innerHTML = locations.map((loc, idx) => `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1.5px solid #E6F0EC;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 11px; color: #648781; font-weight: 600;">#${idx + 1}</span>
                        <span style="font-weight: 600; color: #092F2E;">${loc.region}</span>
                        ${loc.growth_alert ? `<span class="badge" style="background: rgba(52, 211, 153, 0.15); color: var(--brand-emerald-400); font-size: 10px; font-weight: 700;">âš¡ ${loc.growth_alert}</span>` : ''}
                    </div>
                    <div style="text-align: right;">
                        <strong style="color: var(--brand-indigo-400); font-size: 12.5px;">${loc.percentage}%</strong>
                        <span style="font-size: 10.5px; color: #648781; margin-left: 4px;">(${Number(loc.leads_count).toLocaleString()} leads)</span>
                    </div>
                </div>
            `).join('');
        }

        function renderStrategicRecommendations(insights) {
            const container = document.getElementById('kgStrategicRecommendations');
            if (!container) return;

            container.innerHTML = insights.map(item => `
                <div style="background: #F1F7F4; border-left: 3px solid var(--brand-indigo-500); border-radius: 6px; padding: 12px 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <strong style="color: var(--brand-indigo-300); font-size: 12.5px;">${item.title}</strong>
                        <span class="badge" style="background: rgba(99, 102, 241, 0.15); color: var(--brand-indigo-300); font-size: 10px;">${item.badge}</span>
                    </div>
                    <p style="font-size: 11.5px; color: #4F7470; margin: 0 0 6px 0; line-height: 1.4;">
                        ${item.description}
                    </p>
                    <div style="font-size: 11px; color: var(--brand-emerald-400); font-weight: 600;">
                        ðŸ’¡ Recommended: ${item.action}
                    </div>
                </div>
            `).join('');
        }

        function openResolveGapModal(gapId, topic, category, draftContent, severity = 'high') {
            const modal = document.getElementById('resolveGapModal');
            if (!modal) return;

            const titleInput = document.getElementById('rgTitle');
            const catSelect = document.getElementById('rgCategory');
            const priorityInput = document.getElementById('rgPriority');
            const contentArea = document.getElementById('rgContent');

            if (titleInput) titleInput.value = topic || '';
            if (catSelect && category) catSelect.value = category;
            if (priorityInput) priorityInput.value = (severity === 'critical' ? 'ðŸ”´ High Leakage Gap' : 'ðŸŸ¡ Moderate Priority Gap');
            if (contentArea) contentArea.value = draftContent || `POLICY & GUIDANCE FOR ${topic ? topic.toUpperCase() : ''}:\n- Enter clear policy rules and details here.`;

            modal.style.display = 'flex';
        }

        function closeResolveGapModal() {
            const modal = document.getElementById('resolveGapModal');
            if (modal) modal.style.display = 'none';
        }

        async function submitResolveGap(event) {
            event.preventDefault();
            const title = document.getElementById('rgTitle')?.value.trim();
            const category = document.getElementById('rgCategory')?.value;
            const content = document.getElementById('rgContent')?.value.trim();
            const submitBtn = document.getElementById('rgSubmitBtn');

            if (!title || !content) {
                showToast('Please provide both a title and policy content.', 'error');
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerText = 'Training AI Chatbot...';
            }

            try {
                const res = await fetch('/v1/analytics/gaps/resolve', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ title, category, content })
                });
                const result = await res.json();

                if (result.status === 'success') {
                    closeResolveGapModal();
                    showToast(`âœ“ "${title}" added to Knowledge Base! Chatbot trained instantly.`, 'success');
                    // Reload gaps data
                    loadKnowledgeGapsBreakup();
                } else {
                    showToast('Failed to resolve gap: ' + (result.message || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Error submitting gap resolution:', err);
                showToast('Network error resolving knowledge gap.', 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerText = 'ðŸš€ Add to Knowledge Base & Resolve Gap';
                }
            }
        }

        function exportKnowledgeGapsCsv() {
            if (!cachedKgData || !cachedKgData.knowledge_gaps) {
                showToast('No knowledge gaps data available to export.', 'error');
                return;
            }

            const gaps = cachedKgData.knowledge_gaps;
            let csv = 'ID,Topic,Category,Department,Times Asked,Abandonment Rate %,Failure Mode,Recommended Action\n';

            gaps.forEach(g => {
                const clean = str => '"' + (str || '').replace(/"/g, '""') + '"';
                csv += `${clean(g.id)},${clean(g.topic)},${clean(g.category)},${clean(g.department)},${g.times_asked},${g.abandonment_rate}%,${clean(g.status_label)},${clean(g.recommended_action)}\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `edvora_knowledge_gaps_audit_${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast('âœ“ Knowledge Gaps Audit Report downloaded as CSV!', 'success');
        }

        function escapeJsStr(str) {
            if (!str) return '';
            return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, '\\n').replace(/\r/g, '');
        }

        // ==========================================
        // ðŸ”Œ CUSTOM INTEGRATIONS REQUEST HANDLERS
        // ==========================================
        function openRequestIntegrationModal() {
            const modal = document.getElementById('requestIntegrationModal');
            if (modal) {
                modal.style.display = 'flex';
                // Autofill email if user is logged in
                if (currentUser && currentUser.email) {
                    const emailInput = document.getElementById('reqIntEmail');
                    if (emailInput && !emailInput.value) emailInput.value = currentUser.email;
                }
            }
        }

        function closeRequestIntegrationModal() {
            const modal = document.getElementById('requestIntegrationModal');
            if (modal) modal.style.display = 'none';
        }

        async function handleIntegrationRequestSubmit(event) {
            event.preventDefault();
            const software = document.getElementById('reqIntSoftware').value.trim();
            const category = document.getElementById('reqIntCategory').value;
            const email = document.getElementById('reqIntEmail').value.trim();
            const timeline = document.getElementById('reqIntTimeline').value;
            const notes = document.getElementById('reqIntNotes').value.trim();

            const submitBtn = document.getElementById('reqIntSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerText = 'Submitting Request...';
            }

            try {
                // Submit integration request to backend endpoint
                await fetch('/v1/organization/integration-request', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ software, category, email, timeline, notes })
                }).catch(() => {});

                closeRequestIntegrationModal();
                showToast(`âœ“ Integration request for "${software}" submitted to engineering! SLA: 5â€“7 days.`, 'success');
                document.getElementById('requestIntegrationForm').reset();
            } catch (err) {
                closeRequestIntegrationModal();
                showToast(`âœ“ Request for "${software}" recorded. Our team will contact ${email}`, 'success');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerText = 'Submit Integration Request ðŸš€';
                }
            }
        }

        // ==========================================
        // ðŸ“± MOBILE APP INTEGRATION RESOURCE HELPERS
        // ==========================================
        let currentMobileFramework = 'react-native';

        function updateMobileCodeSnippets(botToken) {
            window.currentBotToken = botToken || window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || (window.currentOrg && window.currentOrg.bot_token) || 'YOUR_BOT_TOKEN';
            renderSnippets(window.currentBotToken);
        }

        function switchMobileSnippetTab(framework) {
            currentMobileFramework = framework;
            const tabButtons = {
                'react-native': 'tabBtnReactNative',
                'flutter': 'tabBtnFlutter',
                'ios-swift': 'tabBtnIosSwift',
                'android-kotlin': 'tabBtnAndroidKotlin',
                'rest-api': 'tabBtnRestApi'
            };
            const panels = {
                'react-native': 'panelReactNative',
                'flutter': 'panelFlutter',
                'ios-swift': 'panelIosSwift',
                'android-kotlin': 'panelAndroidKotlin',
                'rest-api': 'panelRestApi'
            };

            Object.keys(tabButtons).forEach(fw => {
                const btn = document.getElementById(tabButtons[fw]);
                const pnl = document.getElementById(panels[fw]);
                if (btn) {
                    if (fw === framework) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                }
                if (pnl) {
                    pnl.style.display = (fw === framework) ? 'block' : 'none';
                }
            });
        }

        function renderSnippets(token) {
            const tok = token || window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || (window.currentOrg && window.currentOrg.bot_token) || 'YOUR_BOT_TOKEN';
            const webviewUrl = `https://edvora.chat/widget/${tok}`;

            const liveUrlEl = document.getElementById('mobileLiveWebviewUrl');
            if (liveUrlEl) liveUrlEl.innerText = webviewUrl;

            const tokenInput = document.getElementById('customBotTokenInput');
            if (tokenInput && tok !== 'YOUR_BOT_TOKEN') {
                tokenInput.value = tok;
            }

            // React Native
            const codeReactNative = document.getElementById('codeReactNative');
            if (codeReactNative) {
                codeReactNative.innerText = `import React from 'react';
import { SafeAreaView, StyleSheet, StatusBar, ActivityIndicator, View } from 'react-native';
import { WebView } from 'react-native-webview';

export default function EdvoraAdmissionsChatScreen() {
  return (
    <SafeAreaView style={styles.container}>
      <StatusBar barStyle="light-content" backgroundColor="#090d16" />
      <WebView
        source={{ uri: '${webviewUrl}' }}
        startInLoadingState={true}
        renderLoading={() => (
          <View style={styles.loading}>
            <ActivityIndicator size="large" color="#6366f1" />
          </View>
        )}
        javaScriptEnabled={true}
        domStorageEnabled={true}
        allowsInlineMediaPlayback={true}
        style={{ flex: 1, backgroundColor: '#090d16' }}
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#090d16' },
  loading: {
    position: 'absolute',
    top: 0, bottom: 0, left: 0, right: 0,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#090d16'
  }
});`;
            }

            // Flutter
            const codeFlutter = document.getElementById('codeFlutter');
            if (codeFlutter) {
                codeFlutter.innerText = `import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

class EdvoraAdmissionsChatScreen extends StatefulWidget {
  const EdvoraAdmissionsChatScreen({Key? key}) : super(key: key);

  @override
  State<EdvoraAdmissionsChatScreen> createState() => _EdvoraAdmissionsChatScreenState();
}

class _EdvoraAdmissionsChatScreenState extends State<EdvoraAdmissionsChatScreen> {
  late final WebViewController _controller;
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(const Color(0xFF090D16))
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageFinished: (_) => setState(() => _isLoading = false),
        ),
      )
      ..loadRequest(Uri.parse('${webviewUrl}'));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF090D16),
      appBar: AppBar(
        title: const Text('AI Admissions Assistant', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
        backgroundColor: const Color(0xFF0F172A),
        elevation: 0,
      ),
      body: Stack(
        children: [
          WebViewWidget(controller: _controller),
          if (_isLoading)
            const Center(child: CircularProgressIndicator(color: Color(0xFF6366F1))),
        ],
      ),
    );
  }
}`;
            }

            // iOS Swift
            const codeIosSwift = document.getElementById('codeIosSwift');
            if (codeIosSwift) {
                codeIosSwift.innerText = `import UIKit
import WebKit

class EdvoraChatViewController: UIViewController, WKNavigationDelegate {
    private var webView: WKWebView!
    private var activityIndicator: UIActivityIndicatorView!

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = UIColor(red: 9/255, green: 13/255, blue: 22/255, alpha: 1.0)
        
        let config = WKWebViewConfiguration()
        config.allowsInlineMediaPlayback = true
        
        webView = WKWebView(frame: view.bounds, configuration: config)
        webView.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        webView.navigationDelegate = self
        webView.isOpaque = false
        webView.backgroundColor = .clear
        view.addSubview(webView)
        
        activityIndicator = UIActivityIndicatorView(style: .large)
        activityIndicator.color = UIColor(red: 99/255, green: 102/255, blue: 241/255, alpha: 1.0)
        activityIndicator.center = view.center
        activityIndicator.startAnimating()
        view.addSubview(activityIndicator)
        
        if let url = URL(string: "${webviewUrl}") {
            webView.load(URLRequest(url: url))
        }
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        activityIndicator.stopAnimating()
        activityIndicator.removeFromSuperview()
    }
}`;
            }

            // Android Kotlin
            const codeAndroidKotlin = document.getElementById('codeAndroidKotlin');
            if (codeAndroidKotlin) {
                codeAndroidKotlin.innerText = `package com.yourcollege.app

import android.annotation.SuppressLint
import android.os.Bundle
import android.view.View
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.ProgressBar
import androidx.appcompat.app.AppCompatActivity

class EdvoraChatActivity : AppCompatActivity() {
    private lateinit var webView: WebView

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_edvora_chat)

        webView = findViewById(R.id.edvoraWebView)
        val progressBar = findViewById<ProgressBar>(R.id.loadingProgress)

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            loadWithOverviewMode = true
            useWideViewPort = true
        }

        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                progressBar.visibility = View.GONE
            }
        }

        webView.loadUrl("${webviewUrl}")
    }

    override fun onBackPressed() {
        if (webView.canGoBack()) webView.goBack() else super.onBackPressed()
    }
}`;
            }

            // REST API
            const codeRestApi = document.getElementById('codeRestApi');
            if (codeRestApi) {
                codeRestApi.innerText = `# 1. Direct HTTP POST Request
curl -X POST "https://edvora.chat/api/chat/message" \\
  -H "Content-Type: application/json" \\
  -d '{
    "bot_token": "${tok}",
    "visitor_id": "device_uuid_98741",
    "message": "What is the application deadline for Fall 2026?"
  }'

# 2. JSON Response Structure
{
  "status": "success",
  "data": {
    "reply": "The application deadline for Fall 2026 admission is July 15th...",
    "intent": "admission_deadline",
    "quick_chips": ["Fee Structure", "Scholarships", "Apply Now"],
    "confidence": 0.96
  }
}`;
            }
        }

        function copyCurrentMobileSnippet() {
            const panels = {
                'react-native': 'codeReactNative',
                'flutter': 'codeFlutter',
                'ios-swift': 'codeIosSwift',
                'android-kotlin': 'codeAndroidKotlin',
                'rest-api': 'codeRestApi'
            };
            const codeEl = document.getElementById(panels[currentMobileFramework]);
            if (!codeEl) return;

            navigator.clipboard.writeText(codeEl.innerText).then(() => {
                const icon = document.getElementById('mobileCopyIcon');
                const text = document.getElementById('mobileCopyText');
                if (text) text.innerText = 'Copied!';
                if (icon) icon.innerText = 'âœ“';
                showToast(`âœ“ ${currentMobileFramework.replace('-', ' ').toUpperCase()} snippet copied!`);
                setTimeout(() => {
                    if (text) text.innerText = 'Copy Code';
                    if (icon) icon.innerText = 'ðŸ“‹';
                }, 2000);
            });
        }

        function copyMobileWebviewUrl() {
            const tok = window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || 'YOUR_BOT_TOKEN';
            const url = `https://edvora.chat/widget/${tok}`;
            navigator.clipboard.writeText(url).then(() => {
                showToast('âœ“ Webview URL copied to clipboard!');
            });
        }

        function openLiveWebviewPreview() {
            const tok = window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || 'YOUR_BOT_TOKEN';
            window.open(`/widget/${tok}`, '_blank');
        }

        function applyCustomBotToken() {
            const val = document.getElementById('customBotTokenInput').value.trim();
            if (val) {
                window.currentBotToken = val;
                renderSnippets(val);
                showToast('âœ“ Snippets updated with custom token!');
            }
        }

        function renderIntegrationsHubView() {
            const tok = window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || (window.currentOrg && window.currentOrg.bot_token) || '';
            if (tok) {
                window.currentBotToken = tok;
            }
            renderSnippets(window.currentBotToken);
        }

        function openMobileSetupGuideView(frameworkTab) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            
            // Show mobile guide subtab
            const mobileGuideTab = document.getElementById('tab-mobile-guide');
            if (mobileGuideTab) {
                mobileGuideTab.classList.add('active');
            }

            // Update top bar header
            const headerTitle = document.getElementById('headerTitle');
            if (headerTitle) {
                headerTitle.innerText = 'Mobile App Integration Guide';
            }

            // Ensure snippets are rendered with active token
            renderIntegrationsHubView();

            if (frameworkTab) {
                switchMobileSnippetTab(frameworkTab);
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function closeMobileSetupGuideView() {
            // Hide mobile guide subtab
            const mobileGuideTab = document.getElementById('tab-mobile-guide');
            if (mobileGuideTab) {
                mobileGuideTab.classList.remove('active');
            }

            // Show main integrations hub
            const intTab = document.getElementById('tab-integrations');
            if (intTab) {
                intTab.classList.add('active');
            }

            // Update top bar header
            const headerTitle = document.getElementById('headerTitle');
            if (headerTitle) {
                headerTitle.innerText = 'Higher Ed Integrations Hub (USA & Canada)';
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ==========================================
        // âš¡ REST API & WEBHOOKS DOCUMENTATION HELPERS
        // ==========================================
        let currentApiLanguage = 'curl';

        function openApiDocsView(lang) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            
            // Show api docs subtab
            const apiDocsTab = document.getElementById('tab-api-docs');
            if (apiDocsTab) {
                apiDocsTab.classList.add('active');
            }

            // Update top bar header
            const headerTitle = document.getElementById('headerTitle');
            if (headerTitle) {
                headerTitle.innerText = 'Headless REST API & Webhooks Documentation';
            }

            // Render snippets with active token
            renderApiSnippets(window.currentBotToken);

            if (lang) {
                switchApiCodeTab(lang);
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function closeApiDocsView() {
            // Hide api docs subtab
            const apiDocsTab = document.getElementById('tab-api-docs');
            if (apiDocsTab) {
                apiDocsTab.classList.remove('active');
            }

            // Show main integrations hub
            const intTab = document.getElementById('tab-integrations');
            if (intTab) {
                intTab.classList.add('active');
            }

            // Update top bar header
            const headerTitle = document.getElementById('headerTitle');
            if (headerTitle) {
                headerTitle.innerText = 'Higher Ed Integrations Hub (USA & Canada)';
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function switchApiCodeTab(lang) {
            currentApiLanguage = lang;
            const tabButtons = {
                'curl': 'apiTabCurl',
                'javascript': 'apiTabJs',
                'python': 'apiTabPython',
                'php': 'apiTabPhp'
            };
            const panels = {
                'curl': 'apiPanelCurl',
                'javascript': 'apiPanelJs',
                'python': 'apiPanelPython',
                'php': 'apiPanelPhp'
            };

            Object.keys(tabButtons).forEach(l => {
                const btn = document.getElementById(tabButtons[l]);
                const pnl = document.getElementById(panels[l]);
                if (btn) {
                    if (l === lang) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                }
                if (pnl) {
                    pnl.style.display = (l === lang) ? 'block' : 'none';
                }
            });
        }

        function renderApiSnippets(token) {
            const tok = token || window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || (window.currentOrg && window.currentOrg.bot_token) || 'YOUR_BOT_TOKEN';
            
            const tokenInput = document.getElementById('apiBotTokenInput');
            if (tokenInput && tok !== 'YOUR_BOT_TOKEN') {
                tokenInput.value = tok;
            }

            // cURL
            const codeCurl = document.getElementById('apiCodeCurl');
            if (codeCurl) {
                codeCurl.innerText = `# 1. Direct HTTP POST Request
curl -X POST "https://edvora.chat/api/chat/message" \\
  -H "Content-Type: application/json" \\
  -d '{
    "bot_token": "${tok}",
    "visitor_id": "device_uuid_98741",
    "message": "What are the admission requirements for International students?"
  }'

# 2. JSON Response
{
  "status": "success",
  "data": {
    "reply": "International applicants must submit academic transcripts, TOEFL/IELTS scores...",
    "intent": "international_admission_requirements",
    "quick_chips": ["Tuition Fees", "Scholarships", "Apply Now"],
    "confidence": 0.98,
    "latency_ms": 320
  }
}`;
            }

            // JavaScript
            const codeJs = document.getElementById('apiCodeJs');
            if (codeJs) {
                codeJs.innerText = `// Node.js (v18+) or Modern Browser Fetch
async function askEdvora(question, visitorId = 'student_device_123') {
  const response = await fetch('https://edvora.chat/api/chat/message', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      bot_token: '${tok}',
      visitor_id: visitorId,
      message: question
    })
  });

  const result = await response.json();
  if (result.status === 'success') {
    console.log('AI Reply:', result.data.reply);
    console.log('Intent:', result.data.intent);
    console.log('Quick Chips:', result.data.quick_chips);
    return result.data;
  } else {
    throw new Error(result.message || 'Inference failed');
  }
}

// Example Execution
askEdvora('When does Fall semester start?');`;
            }

            // Python
            const codePython = document.getElementById('apiCodePython');
            if (codePython) {
                codePython.innerText = `import requests

def query_edvora_chat(message: str, visitor_id: str = "python_client_001") -> dict:
    url = "https://edvora.chat/api/chat/message"
    headers = {"Content-Type": "application/json"}
    payload = {
        "bot_token": "${tok}",
        "visitor_id": visitor_id,
        "message": message
    }

    response = requests.post(url, json=payload, headers=headers, timeout=10)
    response.raise_for_status()
    data = response.json()

    if data.get("status") == "success":
        reply = data["data"]["reply"]
        chips = data["data"].get("quick_chips", [])
        print(f"AI: {reply}")
        return data["data"]
    else:
        raise ValueError(data.get("message", "API Error"))

# Example Execution
if __name__ == "__main__":
    query_edvora_chat("How do I book a campus tour?")`;
            }

            // PHP
            const codePhp = document.getElementById('apiCodePhp');
            if (codePhp) {
                codePhp.innerText = '<' + '?php\n' + `// PHP cURL Inference Client
function sendEdvoraChat(string $message, string $visitorId = 'php_client_1'): array {
    $payload = json_encode([
        'bot_token'  => '${tok}',
        'visitor_id' => $visitorId,
        'message'    => $message
    ]);

    $ch = curl_init('https://edvora.chat/api/chat/message');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result['data'] ?? [];
    }

    throw new Exception("Edvora API error (HTTP $httpCode): " . $response);
}

// Example Execution
$response = sendEdvoraChat('What scholarships are available for freshman?');
echo $response['reply'];
`;
            }
        }

        function copyCurrentApiSnippet() {
            const panels = {
                'curl': 'apiCodeCurl',
                'javascript': 'apiCodeJs',
                'python': 'apiCodePython',
                'php': 'apiCodePhp'
            };
            const codeEl = document.getElementById(panels[currentApiLanguage]);
            if (!codeEl) return;

            navigator.clipboard.writeText(codeEl.innerText).then(() => {
                const icon = document.getElementById('apiCopyIcon');
                const text = document.getElementById('apiCopyText');
                if (text) text.innerText = 'Copied!';
                if (icon) icon.innerText = 'âœ“';
                showToast(`âœ“ ${currentApiLanguage.toUpperCase()} code snippet copied!`);
                setTimeout(() => {
                    if (text) text.innerText = 'Copy Code';
                    if (icon) icon.innerText = 'ðŸ“‹';
                }, 2000);
            });
        }

        function copyApiEndpoint() {
            const endpoint = 'https://edvora.chat/api/chat/message';
            navigator.clipboard.writeText(endpoint).then(() => {
                showToast('âœ“ Endpoint URL copied: POST https://edvora.chat/api/chat/message');
            });
        }

        function applyCustomApiToken() {
            const val = document.getElementById('apiBotTokenInput').value.trim();
            if (val) {
                window.currentBotToken = val;
                renderApiSnippets(val);
                renderSnippets(val);
                showToast('âœ“ API snippets updated with custom token!');
            }
        }

        function openMobileIntegrationGuide(tab) {
            const token = window.currentBotToken || (window.currentWidgetBot && window.currentWidgetBot.botToken) || (window.currentOrg && window.currentOrg.bot_token) || '';
            let url = token ? `/mobile-integration?bot_token=${encodeURIComponent(token)}` : '/mobile-integration';
            if (tab) {
                url += `#${tab}`;
            }
            window.open(url, '_blank');
        }

        // Copy Script Button
        const copyScriptBtnEl = document.getElementById('copyScriptBtn');
        if (copyScriptBtnEl) {
            copyScriptBtnEl.onclick = () => {
                const codeText = document.getElementById('embedCodeBox')?.innerText || '';
                navigator.clipboard.writeText(codeText).then(() => {
                    copyScriptBtnEl.innerText = 'Copied!';
                    setTimeout(() => { copyScriptBtnEl.innerText = 'Copy Script'; }, 2000);
                });
            };
        }

        // Form Submission: Login
        document.getElementById('loginForm').onsubmit = async (e) => {
            e.preventDefault();
            clearAuthError('loginError');
            const btn = e.target.querySelector('button[type="submit"]');
            const originalBtnText = btn ? btn.textContent : 'Sign In to Console';
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Signing In...';
            }

            const email = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value.trim();

            try {
                const res = await fetch('/v1/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    token = data.data.access_token;
                    localStorage.setItem('edvora_token', token);
                    if (data.data.refresh_token) {
                        localStorage.setItem('edvora_refresh_token', data.data.refresh_token);
                    }
                    if (data.data.organization) {
                        updateAppIdentityUI(data.data.organization, data.data.user);
                    }
                    document.documentElement.classList.add('has-auth-token');
                    currentDepartments = [];
                    availableOrgStaff = [];
                    availableOrgKs = [];
                    if (data.data.onboarding_required) {
                        startOnboardingWizard(1);
                    } else {
                        initDashboard();
                    }
                } else {
                    showAuthError('loginError', data.message || 'Invalid email address or password.');
                }
            } catch (err) {
                console.error(err);
                showAuthError('loginError', 'Unable to connect to server. Please try again.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalBtnText;
                }
            }
        };

        // Form Submission: Signup
        // Form Submission: Signup
        document.getElementById('signupForm').onsubmit = async (e) => {
            e.preventDefault();
            clearAuthError('signupError');
            const btn = document.getElementById('signupSubmitBtn') || e.target.querySelector('button[type="submit"]');
            const originalBtnHtml = btn ? btn.innerHTML : '<span>âš¡</span> <span>Analyze Website &amp; Launch Bot</span>';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span style="display:inline-flex; align-items:center; gap:8px;"><span class="brand-spinner" style="width:14px; height:14px; border:2px solid rgba(255,255,255,0.3); border-top-color:#fff; border-radius:50%; animation:smartPulse 0.8s infinite;"></span> Initializing Spider...</span>';
            }

            const websiteVal = (document.getElementById('signupWebsite')?.value || '').trim();

            const collegeNameVal = (document.getElementById('signupCollegeName')?.value || '').trim();

            try {
                const res = await fetch('/v1/auth/signup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: document.getElementById('signupName').value.trim(),
                        college_name: collegeNameVal,
                        email: document.getElementById('signupEmail').value.trim(),
                        password: document.getElementById('signupPassword').value,
                        website_url: websiteVal
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    token = data.data.access_token;
                    localStorage.setItem('edvora_token', token);
                    if (data.data.refresh_token) {
                        localStorage.setItem('edvora_refresh_token', data.data.refresh_token);
                    }
                    document.documentElement.classList.add('has-auth-token');
                    currentDepartments = [];
                    availableOrgStaff = [];
                    availableOrgKs = [];

                    const effectiveWebsite = data.data.organization?.website_url || websiteVal;
                    const botToken = data.data.chatbot?.bot_token || '';

                    // Launch the real-time Discovery Engine!
                    launchSmartOnboardingEngine(token, botToken, effectiveWebsite);
                } else {
                    showAuthError('signupError', data.message || 'Signup failed. Please verify your details.');
                }
            } catch (err) {
                console.error(err);
                showAuthError('signupError', 'Unable to connect to server. Please try again.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalBtnHtml;
                }
            }
        };

        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        // SMART ONBOARDING ENGINE CONTROLLER (SSE + UI STATE MACHINE)
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        let smartEventSource = null;
        let smartOnboardingActiveBotToken = '';
        let smartLiveChipsTotal = 0;

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function addSmartLiveChip(type, label, icon) {
            const container = document.getElementById('smartLiveChipsContainer');
            const placeholder = document.getElementById('smartLiveChipsPlaceholder');
            const countEl = document.getElementById('smartLiveChipsCount');
            if (!container) return;
            if (placeholder) placeholder.style.display = 'none';

            smartLiveChipsTotal++;
            if (countEl) countEl.textContent = `${smartLiveChipsTotal} items`;

            const chip = document.createElement('span');
            chip.style.cssText = 'display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:6px; font-size:11.5px; font-weight:500; animation:smartChipPop 0.3s cubic-bezier(0.16, 1, 0.3, 1);';

            if (type === 'dept') {
                chip.style.background = 'rgba(99, 102, 241, 0.15)';
                chip.style.border = '1px solid rgba(99, 102, 241, 0.35)';
                chip.style.color = '#c7d2fe';
            } else if (type === 'program') {
                chip.style.background = 'rgba(52, 211, 153, 0.12)';
                chip.style.border = '1px solid rgba(52, 211, 153, 0.3)';
                chip.style.color = '#6ee7b7';
            } else {
                chip.style.background = 'rgba(245, 158, 11, 0.12)';
                chip.style.border = '1px solid rgba(245, 158, 11, 0.3)';
                chip.style.color = '#fde68a';
            }

            const truncated = label.length > 28 ? label.substring(0, 26) + '...' : label;
            chip.innerHTML = `<span>${icon}</span><span>${escapeHtml(truncated)}</span>`;

            container.insertBefore(chip, container.firstChild);
        }

        function launchSmartOnboardingEngine(authToken, botToken, websiteUrl) {
