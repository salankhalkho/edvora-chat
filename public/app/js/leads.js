// ═══════════════════════════════════════════════════════════════════
// LEADS.JS - Student leads, counselor callbacks, campus tours management
// BUG AREAS:
//   Leads table render    -> renderLeadsTablePage()
//   Lead export           -> exportLeadsFile() (CSV/Excel)
//   Lead modal            -> openLeadModal() / saveLeadModalChanges()
//   Lead delete           -> deleteLead() / deleteActiveLeadRecord()
//   Leads filters         -> filterLeadsTable() / setLeadsStatusFilter()
//   Stats cards           -> updateLeadsCardsStats()
//   Callbacks queue       -> loadCallbacks() / updateCallbackStatus()
//   Callback modal        -> openCallbackModal() / saveCallbackModalChanges()
//   Campus tours          -> loadCampusTours() / updateTourStatus()
//   Tour feedback         -> triggerTourFeedback()
//   WhatsApp URL builder  -> buildWhatsAppUrl()
// LOADED BY: index.html via <script src="js/leads.js">
// ═══════════════════════════════════════════════════════════════════
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
