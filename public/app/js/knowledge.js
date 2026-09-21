// ═══════════════════════════════════════════════════════════════════
// KNOWLEDGE.JS - Knowledge hub, document editor, doc viewer, ingestion
// BUG AREAS:
//   Dept KS subtab        -> switchDeptSubtab() / switchDeptKsMode()
//   Dept KS list          -> renderDeptKsList() / addDeptKnowledge*()
//   Knowledge table       -> renderKnowledgeTablePage() / filterKnowledgeTable()
//   Knowledge health      -> loadKnowledgeHealthSummary() / applyCkhFilters()
//   Knowledge KPIs        -> updateDynamicKnowledgeKPIs()
//   Doc editor            -> populateKnowledgeEditor() / handleDocEditorSubmit()
//   Doc viewer            -> openKnowledgeView() / populateKnowledgeView()
//   Doc replace           -> openReplaceKnowledgeModal() / submitReplaceKnowledge()
//   Doc expiry/archive    -> extendKnowledgeExpiry() / archiveKnowledge()
//   Main KS ingestion     -> addMainKnowledgeText/File/Url()
//   Load knowledge        -> loadKnowledge()
//   Load assets           -> loadAssets() / renderAssetsTable()
// LOADED BY: index.html via <script src="js/knowledge.js">
// ═══════════════════════════════════════════════════════════════════
        function switchDeptSubtab(subtabKey) {
            document.querySelectorAll('.dept-subtab').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.dept-subtab-content').forEach(c => c.style.display = 'none');

            const targetBtn = document.querySelector(`.dept-subtab[data-subtab="${subtabKey}"]`);
            const targetContent = document.getElementById(`subtab-${subtabKey}`);
            if (targetBtn) targetBtn.classList.add('active');
            if (targetContent) targetContent.style.display = 'block';
        }

        function switchDeptKsMode(mode) {
            document.querySelectorAll('.dept-ks-mode-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.dept-ks-mode-panel').forEach(p => p.style.display = 'none');

            const activeBtn = document.querySelector(`.dept-ks-mode-btn[data-mode="${mode}"]`);
            if (activeBtn) activeBtn.classList.add('active');

            if (mode === 'text') document.getElementById('deptKsModeText').style.display = 'block';
            if (mode === 'file') document.getElementById('deptKsModeFile').style.display = 'block';
            if (mode === 'url') document.getElementById('deptKsModeUrl').style.display = 'block';
        }

        function renderDeptKsList(sources) {
            const container = document.getElementById('deptKsList');
            const badge = document.getElementById('deptKsCountBadge');

            if (!container) return;
            if (badge) badge.innerText = `${sources ? sources.length : 0} Document${(sources && sources.length === 1) ? '' : 's'}`;

            if (!sources || sources.length === 0) {
                container.innerHTML = `<div style="font-size: 12px; color: #648781; padding: 12px; text-align: center;">No knowledge documents added to this department yet. Add a prospectus document below.</div>`;
                return;
            }

            container.innerHTML = sources.map(s => {
                const icon = s.type === 'url' ? 'ðŸŒ' : (s.type === 'document' ? 'ðŸ“' : 'ðŸ“');
                return `
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; background: #F1F7F4; border: 1px solid #DDE9E3; border-radius: var(--brand-radius-sm);">
                        <div style="display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0;">
                            <span style="font-size: 18px; flex-shrink: 0;">${icon}</span>
                            <div style="flex: 1; min-width: 0;">
                                <strong style="font-size: 13px; color: #092F2E; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${s.title}</strong>
                                <span style="font-size: 10px; color: #648781; text-transform: uppercase;">${s.type} â€¢ Status: ${s.status}</span>
                            </div>
                        </div>
                        <button type="button" class="brand-btn-secondary brand-btn-sm" style="color: var(--brand-rose-400); flex-shrink: 0; padding: 4px 10px; font-size: 11px;" onclick="deleteDeptKnowledgeSource(${s.id})">
                            ðŸ—‘ï¸ Delete
                        </button>
                    </div>
                `;
            }).join('');
        }

        async function addDeptKnowledgeText() {
            const deptId = document.getElementById('editDeptId').value;
            if (!deptId) {
                alert('Please save the department basic details first before adding knowledge sources.');
                return;
            }
            const title = document.getElementById('deptKsTitleText').value.trim();
            const content = document.getElementById('deptKsContentText').value.trim();

            if (!title || !content) {
                alert('Please provide both document title and prospectus text content.');
                return;
            }

            try {
                const res = await fetch('/v1/knowledge/paste', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ title, content, department_id: deptId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('deptKsTitleText').value = '';
                    document.getElementById('deptKsContentText').value = '';
                    await refreshDeptKnowledgeSources(deptId);
                } else {
                    alert(data.message || 'Failed to add knowledge source');
                }
            } catch (err) {
                console.error(err);
                alert('Error adding text knowledge source');
            }
        }

        async function addDeptKnowledgeFile() {
            const deptId = document.getElementById('editDeptId').value;
            if (!deptId) {
                alert('Please save the department basic details first before adding knowledge sources.');
                return;
            }
            const title = document.getElementById('deptKsTitleFile').value.trim();
            const fileInput = document.getElementById('deptKsFileInput');
            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Please select a file to upload (.pdf, .docx, .txt).');
                return;
            }

            const formData = new FormData();
            formData.append('file', fileInput.files[0]);
            if (title) formData.append('title', title);
            formData.append('department_id', deptId);

            try {
                const res = await fetch('/v1/knowledge/upload', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token },
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('deptKsTitleFile').value = '';
                    fileInput.value = '';
                    await refreshDeptKnowledgeSources(deptId);
                } else {
                    alert(data.message || 'Failed to upload document');
                }
            } catch (err) {
                console.error(err);
                alert('Error uploading document');
            }
        }

        async function addDeptKnowledgeUrl() {
            const deptId = document.getElementById('editDeptId').value;
            if (!deptId) {
                alert('Please save the department basic details first before adding knowledge sources.');
                return;
            }
            const title = document.getElementById('deptKsTitleUrl').value.trim();
            const url = document.getElementById('deptKsUrlInput').value.trim();

            if (!url) {
                alert('Please enter a valid URL.');
                return;
            }

            try {
                const res = await fetch('/v1/knowledge/url', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ url, title, department_id: deptId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('deptKsTitleUrl').value = '';
                    document.getElementById('deptKsUrlInput').value = '';
                    await refreshDeptKnowledgeSources(deptId);
                } else {
                    alert(data.message || 'Failed to add web URL');
                }
            } catch (err) {
                console.error(err);
                alert('Error adding web URL');
            }
        }

        async function deleteDeptKnowledgeSource(ksId) {
            if (!confirm('Are you sure you want to delete this knowledge source from the department?')) return;
            const deptId = document.getElementById('editDeptId').value;
            try {
                const res = await fetch(`/v1/knowledge/${ksId}`, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    await refreshDeptKnowledgeSources(deptId);
                } else {
                    alert(data.message || 'Failed to delete knowledge source');
                }
            } catch (err) {
                console.error(err);
                alert('Error deleting knowledge source');
            }
        }

        // â”€â”€ MAIN KNOWLEDGE BASE & VALIDITY LIFECYCLE ENGINE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let allKnowledgeSources = [];
        let filteredKnowledgeSources = [];
        let currentKnowledgeHealthFilter = 'all';
        let knowledgeCurrentPage = 1;
        let knowledgePageSize = 10;
        let knowledgeTotalPages = 1;

        function goToKnowledgePage(page) {
            const p = parseInt(page, 10);
            if (isNaN(p) || p < 1 || p > knowledgeTotalPages) return;
            knowledgeCurrentPage = p;
            renderKnowledgeTablePage();
        }

        function changeKnowledgePageSize(size) {
            const s = parseInt(size, 10);
            if (isNaN(s) || s < 1) return;
            knowledgePageSize = s;
            knowledgeCurrentPage = 1;
            renderKnowledgeTablePage();
        }

        function switchMainKsMode(mode) {
            document.querySelectorAll('.main-ks-mode-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.main-ks-mode-panel').forEach(p => p.style.display = 'none');

            const activeBtn = document.querySelector(`.main-ks-mode-btn[data-mode="${mode}"]`);
            if (activeBtn) activeBtn.classList.add('active');

            if (mode === 'text') document.getElementById('mainKsModeText').style.display = 'block';
            if (mode === 'file') document.getElementById('mainKsModeFile').style.display = 'block';
            if (mode === 'url') document.getElementById('mainKsModeUrl').style.display = 'block';
        }

        function switchReplaceMode(mode) {
            document.querySelectorAll('.replace-mode-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.replace-mode-panel').forEach(p => p.style.display = 'none');

            const activeBtn = document.querySelector(`.replace-mode-btn[data-mode="${mode}"]`);
            if (activeBtn) activeBtn.classList.add('active');

            if (mode === 'file') document.getElementById('replaceModeFile').style.display = 'block';
            if (mode === 'text') document.getElementById('replaceModeText').style.display = 'block';
            if (mode === 'url') document.getElementById('replaceModeUrl').style.display = 'block';
        }

        function applyKsExpiryPreset(targetInputId, presetType, silent = false) {
            const input = document.getElementById(targetInputId);
            if (!input) return;

            const now = new Date();
            let targetDate = new Date();

            if (presetType === 'academic_year') {
                // Next July 31st (or current year's July 31st if we are before August)
                let year = now.getFullYear();
                if (now.getMonth() >= 7) { // Aug-Dec: next year's July 31
                    year += 1;
                }
                targetDate = new Date(year, 6, 31); // Month 6 = July (0-indexed)
            } else if (presetType === '1_year') {
                targetDate.setDate(targetDate.getDate() + 365);
            } else if (presetType === '6_months') {
                targetDate.setDate(targetDate.getDate() + 180);
            } else if (presetType === 'evergreen') {
                input.value = '';
                if (!silent) showToast('Set to Evergreen (No Expiration Date)', 'info');
                return;
            }

            const yyyy = targetDate.getFullYear();
            const mm = String(targetDate.getMonth() + 1).padStart(2, '0');
            const dd = String(targetDate.getDate()).padStart(2, '0');
            input.value = `${yyyy}-${mm}-${dd}`;
            if (!silent) showToast(`Expiry preset applied: ${input.value}`, 'info');
        }

        function updateDynamicKnowledgeKPIs(scopeDocs) {
            const list = scopeDocs || [];
            let total = list.length;
            let activeCount = 0;
            let soonCount = 0;
            let expiredCount = 0;
            let reviewCount = 0;
            let archivedCount = 0;
            let attentionCount = 0;

            list.forEach(item => {
                const isArchived = item.status === 'archived' || item.computed_status === 'archived';
                const isExpired = item.is_expired || item.status === 'expired' || item.computed_status === 'expired';
                const isExpiringSoon = item.is_expiring_soon || item.computed_status === 'expiring_soon';
                const isReviewDue = item.is_review_due || item.computed_status === 'needs_review';
                const noExpiry = !item.expires_on;

                if (isArchived) {
                    archivedCount++;
                } else if (isExpired) {
                    expiredCount++;
                } else if (isExpiringSoon) {
                    soonCount++;
                    activeCount++;
                } else if (item.status === 'active' || item.computed_status === 'active') {
                    activeCount++;
                }

                if (!isArchived && (isExpired || isExpiringSoon || isReviewDue || noExpiry)) {
                    attentionCount++;
                }
                if (isReviewDue) {
                    reviewCount++;
                }
            });

            // Update KPI Tiles
            const activeEl = document.getElementById('ksStatActive');
            if (activeEl) activeEl.innerText = activeCount;

            const soonEl = document.getElementById('ksStatExpiringSoon');
            if (soonEl) soonEl.innerText = soonCount;

            const expEl = document.getElementById('ksStatExpired');
            if (expEl) expEl.innerText = expiredCount;

            const revEl = document.getElementById('ksStatNeedsReview');
            if (revEl) revEl.innerText = reviewCount;

            const archEl = document.getElementById('ksStatArchived');
            if (archEl) archEl.innerText = archivedCount;

            // Update Toolbar Tab Badges
            const tAll = document.getElementById('ksTabCountAll');
            if (tAll) tAll.innerText = total;

            const tAttn = document.getElementById('ksTabCountNeedsAttention');
            if (tAttn) tAttn.innerText = attentionCount;

            const tAct = document.getElementById('ksTabCountActive');
            if (tAct) tAct.innerText = activeCount;

            const tSoon = document.getElementById('ksTabCountExpiringSoon');
            if (tSoon) tSoon.innerText = soonCount;

            const tExp = document.getElementById('ksTabCountExpired');
            if (tExp) tExp.innerText = expiredCount;

            const tRev = document.getElementById('ksTabCountNeedsReview');
            if (tRev) tRev.innerText = reviewCount;

            const tArch = document.getElementById('ksTabCountArchived');
            if (tArch) tArch.innerText = archivedCount;
        }

        function loadKnowledgeHealthSummary() {
            filterKnowledgeTable();
        }

        function setKnowledgeHealthFilter(filterKey, el) {
            currentKnowledgeHealthFilter = filterKey;

            // Update tab button active states
            document.querySelectorAll('.ckh-tab-btn').forEach(btn => {
                const btnAttr = btn.getAttribute('onclick') || '';
                if (btnAttr.includes(`'${filterKey}'`)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // Update KPI tile active states
            document.querySelectorAll('.ckh-kpi-tile').forEach(tile => {
                const tileAttr = tile.getAttribute('onclick') || '';
                if (tileAttr.includes(`'${filterKey}'`)) {
                    tile.classList.add('active-kpi');
                } else {
                    tile.classList.remove('active-kpi');
                }
            });

            document.querySelectorAll('.ks-filter-pill').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-filter') === filterKey);
            });

            filterKnowledgeTable();
        }

        function applyCkhFilters() {
            filterKnowledgeTable();
        }

        function showCkhToast(message) {
            if (typeof showToast === 'function') {
                showToast(message, 'info');
            }
        }

        function formatCkhDate(dateStr) {
            if (!dateStr) return '';
            try {
                const parts = dateStr.split('-');
                if (parts.length === 3) {
                    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    const m = parseInt(parts[1], 10) - 1;
                    const d = parseInt(parts[2], 10);
                    const y = parts[0];
                    return `${monthNames[m]} ${d}, ${y}`;
                }
                return dateStr;
            } catch(e) {
                return dateStr;
            }
        }

        function inspectKnowledgeChunks(ksId) {
            const item = allKnowledgeSources.find(s => s.id == ksId);
            if (!item) return;

            const modal = document.getElementById('ckhInspectModal');
            if (!modal) {
                alert(`Inspecting: ${item.title}\n\n${(item.processed_content || item.raw_content || '').substring(0, 600)}...`);
                return;
            }

            const iconEl = document.getElementById('ckhModalIcon');
            if (iconEl) {
                if (item.type === 'url') {
                    iconEl.innerText = 'URL';
                    iconEl.style.background = '#E0F2FE';
                    iconEl.style.color = '#0284C7';
                } else if (item.type === 'text_paste' || item.type === 'text') {
                    iconEl.innerText = 'TXT';
                    iconEl.style.background = '#FEF3C7';
                    iconEl.style.color = '#D97706';
                } else {
                    iconEl.innerText = 'PDF';
                    iconEl.style.background = '#FEF2F2';
                    iconEl.style.color = '#DC2626';
                }
            }

            const titleEl = document.getElementById('ckhModalTitle');
            if (titleEl) titleEl.innerText = item.title;

            const metaEl = document.getElementById('ckhModalMeta');
            if (metaEl) {
                const deptName = (item.departments && item.departments[0]) ? item.departments[0].name : 'Institution-wide';
                metaEl.innerText = `${deptName} â€¢ Cycle: ${item.academic_version || '2026-27'} â€¢ Category: ${item.category || 'Admissions'}`;
            }

            const pillsEl = document.getElementById('ckhModalPills');
            if (pillsEl) {
                let statusBadge = '';
                if (item.status === 'archived' || item.computed_status === 'archived') {
                    statusBadge = '<span class="ckh-status-pill ckh-status-archived">Archived History</span>';
                } else if (item.is_expired || item.status === 'expired' || item.computed_status === 'expired') {
                    statusBadge = '<span class="ckh-status-pill ckh-status-expired">Quarantined / Expired</span>';
                } else if (item.is_expiring_soon || item.computed_status === 'expiring_soon') {
                    statusBadge = '<span class="ckh-status-pill ckh-status-warn">Expiring Soon</span>';
                } else if (item.is_review_due || item.computed_status === 'needs_review') {
                    statusBadge = '<span class="ckh-status-pill ckh-status-review">Review Due</span>';
                } else {
                    statusBadge = '<span class="ckh-status-pill ckh-status-live"><span style="width:6px;height:6px;border-radius:50%;background:#047857;display:inline-block;"></span> Active & Live</span>';
                }
                pillsEl.innerHTML = `
                    ${statusBadge}
                    <span class="ckh-category-tag">${escapeHtml(item.category || 'General')}</span>
                    <span style="font-size: 11px; font-weight: 600; color: #4F7470; background: #F1F7F4; border: 1px solid #D8E7E1; padding: 3px 8px; border-radius: 6px;">Validity: ${formatCkhDate(item.effective_from)} &rarr; ${item.expires_on ? formatCkhDate(item.expires_on) : 'Permanent'}</span>
                `;
            }

            const contentEl = document.getElementById('ckhModalContent');
            if (contentEl) {
                contentEl.innerText = item.processed_content || item.raw_content || 'No extracted text chunks available.';
            }

            const kwEl = document.getElementById('ckhModalKeywords');
            if (kwEl) {
                kwEl.innerText = item.keywords || 'None';
            }

            modal.style.display = 'flex';
        }

        function closeCkhInspectModal() {
            const modal = document.getElementById('ckhInspectModal');
            if (modal) modal.style.display = 'none';
        }

        // Access Control & Badge Styling Helpers for Knowledge Hub
        function isOrgAdminUser() {
            if (!currentUser) return false;
            const r = (currentUser.role || '').toLowerCase();
            return r === 'admin' || r === 'owner' || r === 'org_admin' || r === 'superadmin' || currentUser.can_manage_structure == 1;
        }

        function canManageDoc(item) {
            if (!currentUser) return false;
            // Admins and content admins can manage global documents and all documents
            if (isOrgAdminUser()) return true;
            // Staff: can only manage documents that belong to their assigned departments.
            // For global documents (is_global: true) or out-of-scope docs, staff gets View Only.
            if (item.is_global) return false;
            const myDeptIds = new Set((currentUser.departments || []).map(d => String(d.id)));
            const myDeptNames = new Set((currentUser.departments || []).map(d => (d.name || '').toLowerCase().trim()));
            return (item.departments || []).some(d => myDeptIds.has(String(d.id)) || myDeptNames.has((d.name || '').toLowerCase().trim()));
        }

        const DEPT_BADGE_PALETTES = [
            { bg: '#EFF6FF', border: '#BFDBFE', color: '#1D4ED8' },
            { bg: '#ECFDF5', border: '#A7F3D0', color: '#047857' },
            { bg: '#FEF3C7', border: '#FDE68A', color: '#B45309' },
            { bg: '#F5F3FF', border: '#DDD6FE', color: '#6D28D9' },
            { bg: '#FFF1F2', border: '#FECDD3', color: '#BE123C' },
            { bg: '#F0FDFA', border: '#99F6E4', color: '#0F766E' }
        ];

        function getDeptBadgeStyle(deptId, deptName) {
            let hash = 0;
            const key = String(deptId || deptName || 'general');
            for (let i = 0; i < key.length; i++) {
                hash = (hash << 5) - hash + key.charCodeAt(i);
            }
            const idx = Math.abs(hash) % DEPT_BADGE_PALETTES.length;
            const p = DEPT_BADGE_PALETTES[idx];
            return {
                style: `background:${p.bg}; border-color:${p.border}; color:${p.color};`,
                stroke: p.color
            };
        }

        function filterKnowledgeTable() {
            const query = (document.getElementById('ksSearchInput')?.value || '').toLowerCase().trim();
            const filterKey = currentKnowledgeHealthFilter || 'all';
            const progFilter = (document.getElementById('ckhProgramFilter')?.value || document.getElementById('ckhDeptFilter')?.value || 'all');
            const catFilter = document.getElementById('ckhCategoryFilter')?.value || 'all';

            // 1. Filter by Academic Program, Category, Search Query (Current Active Scope)
            let scopeDocs = allKnowledgeSources.filter(item => {
                // Search query match
                if (query) {
                    const matchTitle = (item.title || '').toLowerCase().includes(query);
                    const matchCat = (item.category || '').toLowerCase().includes(query);
                    const matchVer = (item.academic_version || '').toLowerCase().includes(query);
                    const matchKw = (item.keywords || '').toLowerCase().includes(query);
                    const matchProg = (item.program_name || item.course_name || '').toLowerCase().includes(query);
                    const matchDepts = (item.departments || []).some(d => (d.name || '').toLowerCase().includes(query));
                    if (!matchTitle && !matchCat && !matchVer && !matchKw && !matchProg && !matchDepts) return false;
                }

                // Academic Program filter match
                if (progFilter === 'general') {
                    // Documents with no program assignment
                    if (item.program_id) return false;
                } else if (progFilter !== 'all') {
                    // Specific program chosen: match by program id or course name
                    const pId = String(item.program_id || '');
                    const pName = (item.program_name || item.course_name || '').toLowerCase().trim();
                    const filterLower = progFilter.toLowerCase().trim();
                    if (pId !== progFilter && pName !== filterLower && !pName.includes(filterLower)) {
                        return false;
                    }
                }

                // Category filter match
                if (catFilter !== 'all') {
                    const itemCat = (item.category || 'General').trim().toLowerCase();
                    const targetCat = catFilter.trim().toLowerCase();
                    if (itemCat !== targetCat) return false;
                }

                return true;
            });

            // 2. Dynamically update the 5 KPI Tiles & toolbar count badges to reflect current selection
            updateDynamicKnowledgeKPIs(scopeDocs);

            // 3. Filter scopeDocs by Health Filter for the table
            let filtered = scopeDocs.filter(item => {
                if (filterKey === 'active') {
                    return item.computed_status === 'active' || (item.status === 'active' && !item.is_expired && !item.is_expiring_soon);
                } else if (filterKey === 'expiring_soon') {
                    return item.is_expiring_soon || item.computed_status === 'expiring_soon';
                } else if (filterKey === 'expired') {
                    return item.is_expired || item.status === 'expired' || item.computed_status === 'expired';
                } else if (filterKey === 'needs_review') {
                    return item.is_review_due || item.computed_status === 'needs_review';
                } else if (filterKey === 'archived') {
                    return item.status === 'archived' || item.computed_status === 'archived';
                } else if (filterKey === 'needs_attention') {
                    return item.is_expired || item.is_expiring_soon || item.is_review_due || !item.expires_on;
                }

                return true;
            });

            filteredKnowledgeSources = filtered;
            knowledgeCurrentPage = 1;
            renderKnowledgeTablePage();
        }

        function updateKnowledgeProgramDropdown() {
            const selectEl = document.getElementById('ckhProgramFilter') || document.getElementById('ckhDeptFilter');
            if (!selectEl) return;

            const progMap = new Map();
            if (Array.isArray(window._allProgramsList)) {
                window._allProgramsList.forEach(p => {
                    if (p && p.course_name) progMap.set(String(p.id), p.course_name);
                });
            }
            if (Array.isArray(allKnowledgeSources)) {
                allKnowledgeSources.forEach(item => {
                    if (item.program_id && (item.program_name || item.course_name)) {
                        progMap.set(String(item.program_id), item.program_name || item.course_name);
                    }
                });
            }

            let html = '<option value="all">All Academic Programs</option>';
            html += '<option value="general">General / Non-Program Specific</option>';
            progMap.forEach((name, id) => {
                html += `<option value="${escapeHtml(id)}">${escapeHtml(name)}</option>`;
            });
            selectEl.innerHTML = html;

            if (window._userSelectedKnowledgeProgramFilter) {
                selectEl.value = window._userSelectedKnowledgeProgramFilter;
            } else {
                selectEl.value = 'all';
            }
        }

        function updateKnowledgeDeptDropdown() {
            updateKnowledgeProgramDropdown();
        }

        function updateKnowledgeCategoryDropdown() {
            const selectEl = document.getElementById('ckhCategoryFilter');
            if (!selectEl) return;

            const categorySet = new Set();
            if (Array.isArray(allKnowledgeSources)) {
                allKnowledgeSources.forEach(item => {
                    if (item && item.category && item.category.trim()) {
                        categorySet.add(item.category.trim());
                    }
                });
            }

            // Fallback standard categories if none present yet
            if (categorySet.size === 0) {
                ['General / Institutional', 'Admissions & Prospectus', 'Tuition & Fee Structure', 'Scholarships & Financial Aid', 'Eligibility & Criteria', 'Academic Curriculum & Programs', 'Hostel & Campus Life', 'Placements & Career Outcomes', 'Examinations & Policies'].forEach(c => categorySet.add(c));
            }

            // Always ensure General / Institutional is present
            categorySet.add('General / Institutional');

            // Sort categories alphabetically with General / Institutional first
            const sortedCategories = Array.from(categorySet).sort((a, b) => {
                if (a.toLowerCase().startsWith('general')) return -1;
                if (b.toLowerCase().startsWith('general')) return 1;
                return a.localeCompare(b);
            });

            let html = '<option value="all">All Categories</option>';
            sortedCategories.forEach(cat => {
                html += `<option value="${escapeHtml(cat)}">${escapeHtml(cat)}</option>`;
            });
            selectEl.innerHTML = html;

            if (window._userSelectedKnowledgeCatFilter && Array.from(categorySet).some(c => c.toLowerCase() === window._userSelectedKnowledgeCatFilter.toLowerCase())) {
                selectEl.value = window._userSelectedKnowledgeCatFilter;
            } else {
                selectEl.value = 'all';
            }
        }

        function goToKnowledgePage(page) {
            knowledgeCurrentPage = Math.max(1, Math.min(page, knowledgeTotalPages || 1));
            renderKnowledgeTablePage();
        }

        function changeKnowledgePageSize(size) {
            knowledgePageSize = parseInt(size, 10) || 10;
            knowledgeCurrentPage = 1;
            renderKnowledgeTablePage();
        }

        // â”€â”€ CENTRALIZED KNOWLEDGE DOCUMENT BADGE & EXTENSION RESOLVER â”€â”€â”€â”€â”€â”€â”€
        function getKnowledgeDocBadge(item, isArchived) {
            if (!item) return { badgeClass: 'ckh-file-pdf', badgeText: 'PDF', badgeStyle: '', metaText: '' };

            const addedDate = item.created_at ? formatCkhDate(item.created_at.split('T')[0]) : 'Sep 6, 2026';
            const versionStr = `v${escapeHtml(item.academic_version || '2.6')} Live`;
            const fileSize = item.file_size ? (item.file_size > 1048576 ? (item.file_size / 1048576).toFixed(1) + ' MB' : (item.file_size / 1024).toFixed(0) + ' KB') : '4.8 MB';

            if (isArchived) {
                const expDateStr = item.expires_on ? formatCkhDate(item.expires_on) : 'Superseded';
                return {
                    badgeClass: 'ckh-file-arc',
                    badgeText: 'ARC',
                    badgeStyle: '',
                    metaText: `<span>Archived Record &bull; Superseded</span> &bull; <span>Archived ${expDateStr}</span>`
                };
            }

            const type = (item.type || '').toLowerCase();
            const filePath = (item.file_path || '').toLowerCase();
            const title = (item.title || '').toLowerCase();
            const sourceUrl = (item.source_url || '').toLowerCase();

            // Detect extension from file_path or title
            let ext = '';
            if (filePath) {
                const cleanPath = filePath.split('?')[0].split('#')[0];
                const parts = cleanPath.split('.');
                if (parts.length > 1) ext = parts[parts.length - 1].toLowerCase();
            }
            if (!ext && title) {
                const cleanTitle = title.split('?')[0].split('#')[0];
                const parts = cleanTitle.split('.');
                if (parts.length > 1) ext = parts[parts.length - 1].toLowerCase();
            }

            // 1. Web Source (URL) - Warm Amber (#FEF3C7 / #D97706)
            if (type === 'url' || sourceUrl.startsWith('http')) {
                return {
                    badgeClass: 'ckh-file-web',
                    badgeText: 'WEB',
                    badgeStyle: '',
                    metaText: `<span>Web Source &bull; ${versionStr}</span> &bull; <span>Added ${addedDate}</span>`
                };
            }

            // 2. Word Documents (.docx, .doc) - Royal Blue (#EFF6FF / #2563EB)
            if (ext === 'doc' || ext === 'docx') {
                return {
                    badgeClass: 'ckh-file-doc',
                    badgeText: 'DOC',
                    badgeStyle: '',
                    metaText: `<span>${fileSize} &bull; ${versionStr}</span> &bull; <span>Added ${addedDate}</span>`
                };
            }

            // 3. Spreadsheets (.xlsx, .xls, .csv) - Emerald Green (#ECFDF5 / #059669)
            if (ext === 'xlsx' || ext === 'xls' || ext === 'csv') {
                return {
                    badgeClass: 'ckh-file-xls',
                    badgeText: ext === 'csv' ? 'CSV' : 'XLS',
                    badgeStyle: '',
                    metaText: `<span>${fileSize} &bull; ${versionStr}</span> &bull; <span>Added ${addedDate}</span>`
                };
            }

            // 4. Presentations (.pptx, .ppt) - Coral / Tangerine (#FFF7ED / #EA580C)
            if (ext === 'pptx' || ext === 'ppt') {
                return {
                    badgeClass: 'ckh-file-ppt',
                    badgeText: 'PPT',
                    badgeStyle: '',
                    metaText: `<span>${fileSize} &bull; ${versionStr}</span> &bull; <span>Added ${addedDate}</span>`
                };
            }

            // 5. Plain Text / Notes / Manual Memo (.txt, .rtf, text, text_paste) - Clean Slate (#F8FAFC / #475569)
            if (type === 'text' || type === 'text_paste' || ext === 'txt' || ext === 'rtf') {
                return {
                    badgeClass: 'ckh-file-txt',
                    badgeText: 'TXT',
                    badgeStyle: '',
                    metaText: `<span>Manual Memo &bull; ${versionStr}</span> &bull; <span>Added ${addedDate}</span>`
                };
            }

            // 6. Markdown (.md) - Purple / Violet (#F5F3FF / #7C3AED)
            if (ext === 'md' || ext === 'markdown') {
                return {
                    badgeClass: 'ckh-file-md',
                    badgeText: 'MD',
                    badgeStyle: '',
                    metaText: `<span>${fileSize} &bull; ${versionStr}</span> &bull; <span>Added ${addedDate}</span>`
                };
            }

            // 7. Default: PDF Document - Crimson Red (#FEF2F2 / #DC2626)
            return {
                badgeClass: 'ckh-file-pdf',
                badgeText: 'PDF',
                badgeStyle: '',
                metaText: `<span>${fileSize} &bull; ${versionStr}</span> &bull; <span>Added ${addedDate}</span>`
            };
        }

        function renderKnowledgeTablePage() {
            const tbody = document.getElementById('ckhDocTableBody') || document.getElementById('knowledgeTableBody');
            if (!tbody) return;

            const total = (filteredKnowledgeSources || []).length;
            knowledgeTotalPages = Math.max(1, Math.ceil(total / knowledgePageSize));
            if (knowledgeCurrentPage > knowledgeTotalPages) knowledgeCurrentPage = knowledgeTotalPages;
            if (knowledgeCurrentPage < 1) knowledgeCurrentPage = 1;

            const startIndex = (knowledgeCurrentPage - 1) * knowledgePageSize;
            const endIndex = Math.min(startIndex + knowledgePageSize, total);
            const pageData = (filteredKnowledgeSources || []).slice(startIndex, endIndex);

            // Update Pagination UI elements
            const pagInfo = document.getElementById('ckhPaginationInfo');
            if (pagInfo) {
                if (total === 0) {
                    pagInfo.innerText = 'Showing 0 of 0 documents';
                } else {
                    pagInfo.innerText = `Showing ${startIndex + 1} to ${endIndex} of ${total} documents`;
                }
            }

            const pagIndicator = document.getElementById('knowledgePageIndicator');
            if (pagIndicator) {
                pagIndicator.innerText = `Page ${knowledgeCurrentPage} of ${knowledgeTotalPages}`;
            }

            const perPageSelect = document.getElementById('knowledgePerPageSelect');
            if (perPageSelect && perPageSelect.value !== String(knowledgePageSize)) {
                perPageSelect.value = String(knowledgePageSize);
            }

            const btnFirst = document.getElementById('knowledgeFirstBtn') || document.getElementById('ksBtnFirst');
            const btnPrev = document.getElementById('knowledgePrevBtn') || document.getElementById('ksBtnPrev');
            const btnNext = document.getElementById('knowledgeNextBtn') || document.getElementById('ksBtnNext');
            const btnLast = document.getElementById('knowledgeLastBtn') || document.getElementById('ksBtnLast');

            if (btnFirst) btnFirst.disabled = (knowledgeCurrentPage <= 1);
            if (btnPrev) btnPrev.disabled = (knowledgeCurrentPage <= 1);
            if (btnNext) btnNext.disabled = (knowledgeCurrentPage >= knowledgeTotalPages);
            if (btnLast) btnLast.disabled = (knowledgeCurrentPage >= knowledgeTotalPages);

            const footerCountEl = document.getElementById('ckhFooterTotalCount');
            if (footerCountEl) {
                footerCountEl.innerText = `Showing ${pageData.length} of ${allKnowledgeSources.length} Institutional Documents`;
            }

            if (pageData.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 48px 16px; color: #648781;">
                            <div style="font-size: 28px; margin-bottom: 8px;">ðŸ“‚</div>
                            <div style="font-size: 14px; font-weight: 700; color: #063D3B;">No documents found</div>
                            <div style="font-size: 12px; margin-top: 4px;">Try refining your search keyword or switching the health status filter tab above.</div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = pageData.map(item => {
                const isArchived = item.status === 'archived' || item.computed_status === 'archived';
                const isExpired = item.is_expired || item.status === 'expired' || item.computed_status === 'expired';
                const isExpiringSoon = item.is_expiring_soon || item.computed_status === 'expiring_soon';
                const isReviewDue = item.is_review_due || item.computed_status === 'needs_review';

                // File badge - Cohesive and consistent resolution
                const docBadge = getKnowledgeDocBadge(item, isArchived);
                const fileBadgeClass = docBadge.badgeClass;
                const fileBadgeText = docBadge.badgeText;
                const fileBadgeStyle = docBadge.badgeStyle;
                const fileMetaText = docBadge.metaText;

                // Dynamic Academic Program Badge (from programs table via knowledge_sources.program_id)
                let progName = 'All Programs';
                let progBadgeStyle = 'background:#F0FDF4; border: 1px solid #BBF7D0; color:#166534;';
                let progStroke = '#166534';

                if (item.program_name || item.course_name) {
                    progName = item.program_name || item.course_name;
                    progBadgeStyle = 'background:#E8F5E9; border: 1px solid #A5D6A7; color:#1B5E20;';
                    progStroke = '#2E7D32';
                } else if (isArchived) {
                    progBadgeStyle = 'background:#F1F5F9; border: 1px solid #CBD5E1; color:#64748B;';
                    progStroke = '#64748B';
                }
                const progIconSvg = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="${progStroke}" stroke-width="2.3"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>`;

                // Category Tag
                const catTag = `<span class="ckh-category-tag"${isArchived ? ' style="opacity: 0.65;"' : ''}>${escapeHtml(item.category || 'General')}</span>`;

                // Effective & Expiry Validity with Review Cadence & Evergreen Badge
                let validityHtml = '';
                const effStr = formatCkhDate(item.effective_from);
                const expStr = formatCkhDate(item.expires_on);

                // Cadence & Evergreen text calculation (plain text, no badge box)
                let cadenceText = '';
                if (!item.expires_on) {
                    cadenceText = `<div style="font-size: 10px; color: #47726A; font-weight: 600; margin-top: 2px;">&infin; Evergreen Policy</div>`;
                } else if (item.type === 'url' && !isExpired && !isArchived) {
                    cadenceText = `<div style="font-size: 10px; color: #47726A; font-weight: 600; margin-top: 2px;">&infin; Evergreen Process</div>`;
                } else if (isReviewDue) {
                    cadenceText = `<div style="font-size: 10px; color: #6D28D9; font-weight: 600; margin-top: 2px;">Review: Every 6 Months</div>`;
                } else if (isExpiringSoon) {
                    cadenceText = `<div style="font-size: 10px; color: #B45309; font-weight: 600; margin-top: 2px;">Review: Every 6 Months</div>`;
                } else if (isArchived) {
                    cadenceText = `<div style="font-size: 10px; color: #64748B; font-weight: 600; margin-top: 2px;">Archived Record</div>`;
                } else if (isExpired) {
                    cadenceText = `<div style="font-size: 10px; color: #DC2626; font-weight: 600; margin-top: 2px;">Annual Review</div>`;
                } else {
                    const freqMonths = Math.round((item.review_frequency_days || 365) / 30);
                    const freqLabel = freqMonths >= 10 ? 'Annual Review' : `Every ${freqMonths} Months`;
                    cadenceText = `<div style="font-size: 10px; color: #47726A; font-weight: 600; margin-top: 2px;">${freqLabel}</div>`;
                }

                if (isArchived) {
                    validityHtml = `
                        <div style="font-weight: 700; color: #64748B; font-size: 11px;">${effStr} &rarr; ${expStr}</div>
                        ${cadenceText}
                    `;
                } else if (isExpired) {
                    validityHtml = `
                        <div style="font-weight: 700; color: #DC2626; font-size: 11px;">${effStr} &rarr; ${expStr}</div>
                        ${cadenceText}
                    `;
                } else if (isExpiringSoon) {
                    validityHtml = `
                        <div style="font-weight: 700; color: #B45309; font-size: 11px;">${effStr} &rarr; ${expStr}</div>
                        ${cadenceText}
                    `;
                } else if (!item.expires_on) {
                    validityHtml = `
                        <div style="font-weight: 700; color: #063D3B; font-size: 11px;">Effective: ${effStr || 'Permanent'}</div>
                        ${cadenceText}
                    `;
                } else {
                    validityHtml = `
                        <div style="font-weight: 700; color: #063D3B; font-size: 11px;">${effStr} &rarr; ${expStr}</div>
                        ${cadenceText}
                    `;
                }

                // AI Freshness Shield Status Pill
                let statusPillHtml = '';
                if (isArchived) {
                    statusPillHtml = `
                        <span class="ckh-status-pill ckh-status-archived">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect></svg>
                            Archived History
                        </span>
                    `;
                } else if (isExpired) {
                    statusPillHtml = `
                        <span class="ckh-status-pill ckh-status-expired">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            Expired (Hidden)
                        </span>
                    `;
                } else if (isExpiringSoon) {
                    statusPillHtml = `
                        <span class="ckh-status-pill ckh-status-warn">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #F59E0B;"></span>
                            Expiring Soon
                        </span>
                    `;
                } else if (isReviewDue) {
                    statusPillHtml = `
                        <span class="ckh-status-pill ckh-status-review">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #7C3AED;"></span>
                            Review Due
                        </span>
                    `;
                } else if (item.status === 'pending' || item.status === 'processing') {
                    statusPillHtml = `
                        <span class="ckh-status-pill" style="background: #EFF6FF; border: 1px solid #BFDBFE; color: #1D4ED8;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #2563EB; animation: pulse 1.5s infinite;"></span>
                            Indexing...
                        </span>
                    `;
                } else {
                    statusPillHtml = `
                        <span class="ckh-status-pill ckh-status-live">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #047857;"></span>
                            Active &amp; Live
                        </span>
                    `;
                }

                return `
                    <tr data-status="${escapeHtml(item.computed_status || item.status)}" data-category="${escapeHtml((item.category || '').toLowerCase())}">
                        <td>
                            <div class="ckh-doc-title-cell">
                                <div class="ckh-file-badge ${fileBadgeClass}" style="${fileBadgeStyle}">
                                    <span>${fileBadgeText}</span>
                                </div>
                                <div style="min-width: 0; flex: 1;">
                                    <div class="ckh-doc-title-text" style="cursor: pointer; ${isArchived ? 'color:#64748B; text-decoration: line-through;' : ''}" onclick="openKnowledgeView(${item.id})" title="Click to view document details">${escapeHtml(item.title)}</div>
                                    <div class="ckh-doc-meta-text">
                                        ${fileMetaText}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="ckh-dept-badge" style="${progBadgeStyle}">
                                ${progIconSvg}
                                <span>${escapeHtml(progName)}</span>
                            </span>
                        </td>
                        <td>${catTag}</td>
                        <td>${validityHtml}</td>
                        <td>${statusPillHtml}</td>
                        <td style="text-align: right;">
                            ${canManageDoc(item) ? `
                                <button onclick="openKnowledgeEditor(${item.id})" class="ckh-action-btn" style="background: #F0FDF4; border-color: #BBF7D0; color: #166534; font-weight: 700;" title="Edit details & manage document">
                                    <span>âœï¸ Manage</span>
                                </button>
                            ` : `
                                <button onclick="openKnowledgeView(${item.id})" class="ckh-action-btn" style="background: #F8FAFC; border-color: #CBD5E1; color: #64748B; font-weight: 600;" title="View document details (read-only)">
                                    <span>ðŸ‘ï¸ View Only</span>
                                </button>
                            `}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function loadKnowledge() {
            if (!token) return;

            const isStaff = !isOrgAdminUser();
            const addCard = document.getElementById('mainKsAddCard');

            if (isStaff) {
                if (addCard) addCard.style.display = 'none';
            } else {
                if (addCard) addCard.style.display = 'block';
            }

            try {
                // Ensure departments are populated for dropdown if not already loaded
                if (!currentDepartments || currentDepartments.length === 0) {
                    try {
                        const deptRes = await fetch('/v1/departments', { headers: { 'Authorization': 'Bearer ' + token } });
                        const deptData = await deptRes.json();
                        if (deptData.status === 'success') {
                            currentDepartments = deptData.data.departments || (Array.isArray(deptData.data) ? deptData.data : []);
                        }
                    } catch(e) {}
                }

                // Ensure academic programs are loaded for dropdown
                try {
                    const progRes = await fetch('/v1/programs', { headers: { 'Authorization': 'Bearer ' + token } });
                    const progData = await progRes.json();
                    if (progData.status === 'success' && progData.data) {
                        window._allProgramsList = progData.data.courses || (Array.isArray(progData.data) ? progData.data : []);
                    }
                } catch(e) {}

                const res = await fetch('/v1/knowledge', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();

                if (data.status === 'success' && data.data) {
                    allKnowledgeSources = data.data || [];
                    if (typeof updateKnowledgeProgramDropdown === 'function') {
                        updateKnowledgeProgramDropdown();
                    } else if (typeof updateKnowledgeDeptDropdown === 'function') {
                        updateKnowledgeDeptDropdown();
                    }
                    if (typeof updateKnowledgeCategoryDropdown === 'function') {
                        updateKnowledgeCategoryDropdown();
                    }
                    filterKnowledgeTable();
                }

                // Also update health metrics KPI strip
                await loadKnowledgeHealthSummary();
            } catch (err) {
                console.error('Error loading knowledge sources:', err);
            }
        }

        async function openReplaceKnowledgeModal(ksId) {
            try {
                let item = (Array.isArray(allKnowledgeSources) && allKnowledgeSources.find(s => s.id == ksId)) 
                    || (currentEditingDoc && currentEditingDoc.id == ksId ? currentEditingDoc : null)
                    || currentEditingDoc;
                
                if (!item && ksId) {
                    try {
                        const res = await fetch(`/v1/knowledge/${ksId}`, {
                            headers: { 'Authorization': 'Bearer ' + token }
                        });
                        const resData = await res.json();
                        if (resData.status === 'success' && resData.data) {
                            item = resData.data;
                            if (!currentEditingDoc) currentEditingDoc = item;
                        }
                    } catch (fetchErr) {
                        console.warn('[Edvora] Failed to fetch doc for replacement modal:', fetchErr);
                    }
                }

                if (!item) {
                    showToast('Unable to locate document details for replacement.', 'error');
                    return;
                }

                const srcIdEl = document.getElementById('replaceSourceId');
                if (srcIdEl) srcIdEl.value = item.id;
                
                const oldTitleEl = document.getElementById('replaceOldTitle');
                if (oldTitleEl) oldTitleEl.innerText = item.title || 'Untitled Document';
                
                const oldCatEl = document.getElementById('replaceOldCategory');
                if (oldCatEl) oldCatEl.innerText = item.category || 'Admissions';
                
                const oldVerEl = document.getElementById('replaceOldVersion');
                if (oldVerEl) oldVerEl.innerText = item.academic_version || '2025-26';
                
                const oldExpEl = document.getElementById('replaceOldExpiry');
                if (oldExpEl) oldExpEl.innerText = item.expires_on || 'None';

                // Next academic year suggestion
                let nextYear = '2026-27';
                if (item.academic_version && item.academic_version.includes('-')) {
                    const parts = item.academic_version.split('-');
                    const y1 = parseInt(parts[0]);
                    const y2 = parseInt(parts[1]);
                    if (!isNaN(y1) && !isNaN(y2)) {
                        nextYear = `${y1 + 1}-${y2 + 1}`;
                    }
                }

                const titleInp = document.getElementById('replaceTitleInput');
                if (titleInp) titleInp.value = item.title || '';
                
                const verInp = document.getElementById('replaceVersionInput');
                if (verInp) verInp.value = nextYear;
                
                const effInp = document.getElementById('replaceEffectiveFrom');
                if (effInp) effInp.value = new Date().toISOString().split('T')[0];
                
                // Default 1 year from now (silent = true to suppress toast on initial modal load)
                applyKsExpiryPreset('replaceExpiresOn', 'academic_year', true);

                switchReplaceMode(item.type === 'document' ? 'file' : (item.type === 'url' ? 'url' : 'text'));

                const modal = document.getElementById('replaceKnowledgeModal');
                if (modal) {
                    modal.style.setProperty('display', 'flex', 'important');
                    modal.style.setProperty('z-index', '99999', 'important');
                }
            } catch (modalErr) {
                console.error('[Edvora] openReplaceKnowledgeModal exception:', modalErr);
                showToast('An error occurred opening the replacement modal.', 'error');
            }
        }

        function closeReplaceKnowledgeModal() {
            document.getElementById('replaceKnowledgeModal').style.display = 'none';
            document.getElementById('replaceKnowledgeForm').reset();
        }

        async function submitReplaceKnowledge(event) {
            if (event) event.preventDefault();
            const sourceId = document.getElementById('replaceSourceId').value;
            if (!sourceId) return;

            const submitBtn = document.getElementById('replaceSubmitBtn');
            const originalText = submitBtn.innerText;
            submitBtn.innerText = 'Deploying New Version...';
            submitBtn.disabled = true;

            const activeMode = document.querySelector('.replace-mode-btn.active')?.getAttribute('data-mode') || 'file';
            const title = document.getElementById('replaceTitleInput').value.trim();
            const academic_version = document.getElementById('replaceVersionInput').value.trim();
            const effective_from = document.getElementById('replaceEffectiveFrom').value;
            const expires_on = document.getElementById('replaceExpiresOn').value;

            try {
                const formData = new FormData();
                formData.append('title', title);
                formData.append('academic_version', academic_version);
                if (effective_from) formData.append('effective_from', effective_from);
                if (expires_on) formData.append('expires_on', expires_on);

                if (activeMode === 'file') {
                    const fileInput = document.getElementById('replaceFileInput');
                    if (!fileInput.files || fileInput.files.length === 0) {
                        alert('Please select a replacement file to upload.');
                        submitBtn.innerText = originalText;
                        submitBtn.disabled = false;
                        return;
                    }
                    formData.append('file', fileInput.files[0]);
                } else if (activeMode === 'text') {
                    const content = document.getElementById('replaceContentInput').value.trim();
                    if (!content) {
                        alert('Please provide the updated text content.');
                        submitBtn.innerText = originalText;
                        submitBtn.disabled = false;
                        return;
                    }
                    formData.append('content', content);
                } else if (activeMode === 'url') {
                    const url = document.getElementById('replaceUrlInput').value.trim();
                    if (!url) {
                        alert('Please provide the updated webpage URL.');
                        submitBtn.innerText = originalText;
                        submitBtn.disabled = false;
                        return;
                    }
                    formData.append('url', url);
                }

                const res = await fetch(`/v1/knowledge/${sourceId}/replace`, {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token },
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeReplaceKnowledgeModal();
                    showToast('ðŸŽ‰ New document version deployed and previous version archived!', 'success');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to replace document version.');
                }
            } catch (err) {
                console.error('Error replacing version:', err);
                alert('Connection error replacing version.');
            } finally {
                submitBtn.innerText = originalText;
                submitBtn.disabled = false;
            }
        }

        async function extendKnowledgeExpiry(ksId) {
            const item = allKnowledgeSources.find(s => s.id == ksId);
            if (!item) return;

            const daysStr = prompt(`Extend validity for "${item.title}".\n\nEnter number of extension days (e.g. 180 for 6 months, 365 for 1 year):`, '180');
            if (daysStr === null) return;
            const days = parseInt(daysStr);
            if (isNaN(days) || days <= 0) {
                alert('Please enter a valid positive number of days.');
                return;
            }

            try {
                const res = await fetch(`/v1/knowledge/${ksId}/extend`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ extension_days: days })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(`âœ“ Document validity extended by ${days} days (New expiry: ${data.data.expires_on})`, 'success');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to extend validity.');
                }
            } catch (err) {
                console.error(err);
                alert('Connection error extending validity.');
            }
        }

        async function markKnowledgeReviewed(ksId) {
            try {
                const res = await fetch(`/v1/knowledge/${ksId}/review`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({})
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('âœ“ Document verified & marked as audited today!', 'success');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to record review.');
                }
            } catch (err) {
                console.error(err);
            }
        }

        async function archiveKnowledge(ksId) {
            if (!confirm('Archive this knowledge document? It will no longer be served by the visitor chatbot.')) return;
            try {
                const res = await fetch(`/v1/knowledge/${ksId}/archive`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Document moved to archived history.', 'info');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to archive document.');
                }
            } catch (err) {
                console.error(err);
            }
        }

        async function deleteMainKnowledgeSource(ksId) {
            if (!confirm('Are you sure you want to permanently delete this knowledge source?')) return;
            try {
                const res = await fetch(`/v1/knowledge/${ksId}`, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Knowledge source deleted.', 'info');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to delete knowledge source');
                }
            } catch (err) {
                console.error(err);
                alert('Error deleting knowledge source');
            }
        }

        // â”€â”€ KNOWLEDGE FAQ COLLAPSIBLE TOGGLE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        function toggleKnowledgeFaq() {
            const body = document.getElementById('knowledgeFaqBody');
            const chevron = document.getElementById('knowledgeFaqChevron');
            const toggleText = document.getElementById('knowledgeFaqToggleText');
            const hint = document.getElementById('knowledgeFaqHint');
            if (!body) return;

            const isHidden = body.style.display === 'none' || body.style.display === '';
            if (isHidden) {
                body.style.display = 'block';
                if (chevron) chevron.style.transform = 'rotate(180deg)';
                if (toggleText) toggleText.innerText = 'Hide Guide';
                if (hint) hint.innerText = 'Click to collapse guide';
            } else {
                body.style.display = 'none';
                if (chevron) chevron.style.transform = 'rotate(0deg)';
                if (toggleText) toggleText.innerText = 'Read Guide';
                if (hint) hint.innerText = 'Click to read full guide';
            }
        }

        // â”€â”€ KNOWLEDGE DOCUMENT MANAGEMENT & EDITING WORKSPACE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let currentEditingDoc = null;

        async function openKnowledgeEditor(ksId) {
            try {
                if (!ksId && window._activeEditingDocId) {
                    ksId = window._activeEditingDocId;
                }
                if (!ksId) {
                    const params = new URLSearchParams(window.location.hash.includes('?') ? window.location.hash.split('?')[1] : window.location.search);
                    ksId = params.get('id');
                }
                if (!ksId) {
                    ksId = sessionStorage.getItem('edvora_edit_doc_id');
                }
                if (!ksId) {
                    showToast('No document specified to edit.', 'warning');
                    switchNavTab('knowledge');
                    return;
                }

                window._activeEditingDocId = ksId;
                sessionStorage.setItem('edvora_edit_doc_id', ksId);

                // Update hash cleanly to #knowledge-edit?id=...
                try {
                    history.replaceState(null, '', '#knowledge-edit?id=' + encodeURIComponent(ksId));
                } catch(e) {}

                // Ensure tab template is loaded (loads tabs/knowledge-edit.html)
                await loadTabContent('knowledge-edit');

                // Switch active tab view
                document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
                const editTab = document.getElementById('tab-knowledge-edit') || document.getElementById('tab-knowledge-editor');
                if (editTab) editTab.classList.add('active');

                // Keep knowledge nav item highlighted
                document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                const kNavItem = document.querySelector(`.nav-item[data-tab="knowledge"]`);
                if (kNavItem) kNavItem.classList.add('active');

                const headerTitleEl = document.getElementById('headerTitle');
                if (headerTitleEl) headerTitleEl.innerText = 'Edit Knowledge Document';

                // Fetch full document details
                const res = await fetch(`/v1/knowledge/${ksId}`, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status !== 'success' || !data.data) {
                    showToast(data.message || 'Failed to load document details.', 'error');
                    closeKnowledgeEditor();
                    return;
                }

                currentEditingDoc = data.data;
                await populateKnowledgeEditor(currentEditingDoc);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (err) {
                console.error('Error opening knowledge editor:', err);
                showToast('Failed to open document editor.', 'error');
                closeKnowledgeEditor();
            }
        }

        function closeKnowledgeEditor() {
            window._activeEditingDocId = null;
            sessionStorage.removeItem('edvora_edit_doc_id');
            switchNavTab('knowledge');
        }

        // â”€â”€ KNOWLEDGE DOCUMENT READ-ONLY VIEWER WORKSPACE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let currentViewingDoc = null;

        async function openKnowledgeView(ksId) {
            try {
                // Ensure tab template is loaded
                await loadTabContent('knowledge-view');

                // Switch active tab view
                document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
                const viewTab = document.getElementById('tab-knowledge-view');
                if (viewTab) viewTab.classList.add('active');

                // Keep knowledge nav item highlighted
                document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                const kNavItem = document.querySelector(`.nav-item[data-tab="knowledge"]`);
                if (kNavItem) kNavItem.classList.add('active');

                // Fetch full document details
                const res = await fetch(`/v1/knowledge/${ksId}`, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status !== 'success' || !data.data) {
                    showToast(data.message || 'Failed to load document details.', 'error');
                    closeKnowledgeView();
                    return;
                }

                currentViewingDoc = data.data;
                populateKnowledgeView(currentViewingDoc);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (err) {
                console.error('Error opening knowledge viewer:', err);
                showToast('Failed to open document view.', 'error');
                closeKnowledgeView();
            }
        }

        function closeKnowledgeView() {
            switchNavTab('knowledge');
        }

        function viewDocGoToEditor() {
            if (currentViewingDoc && currentViewingDoc.id) {
                openKnowledgeEditor(currentViewingDoc.id);
            }
        }

        function populateKnowledgeView(doc) {
            if (!doc) return;

            const isArchived = doc.status === 'archived' || doc.computed_status === 'archived';
            const isExpired = doc.is_expired || doc.status === 'expired' || doc.computed_status === 'expired';
            const isExpiringSoon = doc.is_expiring_soon || doc.computed_status === 'expiring_soon';
            const isReviewDue = doc.is_review_due || doc.computed_status === 'needs_review';

            // Top Header Elements
            const titleEl = document.getElementById('viewDocHeaderTitle');
            if (titleEl) titleEl.innerText = doc.title || 'Untitled Document';

            const idTag = document.getElementById('viewDocIdTag');
            if (idTag) idTag.innerText = '#' + doc.id;

            const typeIcon = document.getElementById('viewDocTypeIcon');
            if (typeIcon) {
                const docBadge = getKnowledgeDocBadge(doc, isArchived);
                typeIcon.className = `ckh-file-badge ${docBadge.badgeClass}`;
                typeIcon.innerText = docBadge.badgeText;
                typeIcon.style.cssText = 'width: 32px; height: 32px; border-radius: 6px; font-size: 10px; font-weight: 800;';
            }

            // Status Badge
            const statusBadge = document.getElementById('viewDocStatusBadge');
            if (statusBadge) {
                if (isArchived) {
                    statusBadge.style.background = '#F1F5F9';
                    statusBadge.style.borderColor = '#CBD5E1';
                    statusBadge.style.color = '#64748B';
                    statusBadge.innerHTML = '<span style="width: 7px; height: 7px; border-radius: 50%; background: #64748B; display: inline-block;"></span><span>Archived Record</span>';
                } else if (isExpired) {
                    statusBadge.style.background = '#FEF2F2';
                    statusBadge.style.borderColor = '#FCA5A5';
                    statusBadge.style.color = '#DC2626';
                    statusBadge.innerHTML = '<span style="width: 7px; height: 7px; border-radius: 50%; background: #DC2626; display: inline-block;"></span><span>Expired (Quarantined)</span>';
                } else if (isExpiringSoon) {
                    statusBadge.style.background = '#FFFBEB';
                    statusBadge.style.borderColor = '#FCD34D';
                    statusBadge.style.color = '#B45309';
                    statusBadge.innerHTML = `<span style="width: 7px; height: 7px; border-radius: 50%; background: #F59E0B; display: inline-block;"></span><span>Expiring Soon (${doc.days_until_expiry || 14}d)</span>`;
                } else if (isReviewDue) {
                    statusBadge.style.background = '#F5F3FF';
                    statusBadge.style.borderColor = '#DDD6FE';
                    statusBadge.style.color = '#7C3AED';
                    statusBadge.innerHTML = '<span style="width: 7px; height: 7px; border-radius: 50%; background: #7C3AED; display: inline-block;"></span><span>Review Due</span>';
                } else {
                    statusBadge.style.background = '#ECFDF5';
                    statusBadge.style.borderColor = '#34D399';
                    statusBadge.style.color = '#047857';
                    statusBadge.innerHTML = '<span style="width: 7px; height: 7px; border-radius: 50%; background: #10B981; display: inline-block;"></span><span>Active &amp; Live</span>';
                }
            }

            // Access check: Can user manage this doc?
            const userCanManage = canManageDoc(doc);
            const btnEditTop = document.getElementById('btnViewDocEditTop');
            const btnEditBottom = document.getElementById('btnViewDocEditBottom');
            const noticeBanner = document.getElementById('viewOnlyNoticeBanner');
            const globalNoticeBanner = document.getElementById('viewGlobalNoticeBanner');
            const viewOnlyDeptName = document.getElementById('viewOnlyDeptName');

            if (userCanManage) {
                if (btnEditTop) btnEditTop.style.display = 'inline-flex';
                if (btnEditBottom) btnEditBottom.style.display = 'inline-flex';
                if (noticeBanner) noticeBanner.style.display = 'none';
                if (globalNoticeBanner) globalNoticeBanner.style.display = 'none';
            } else {
                if (btnEditTop) btnEditTop.style.display = 'none';
                if (btnEditBottom) btnEditBottom.style.display = 'none';
                if (doc.is_global) {
                    if (noticeBanner) noticeBanner.style.display = 'none';
                    if (globalNoticeBanner) globalNoticeBanner.style.display = 'flex';
                } else {
                    if (globalNoticeBanner) globalNoticeBanner.style.display = 'none';
                    if (noticeBanner) {
                        noticeBanner.style.display = 'flex';
                        const depts = doc.departments || [];
                        const deptNames = depts.map(d => d.name).filter(Boolean).join(', ') || 'another department';
                        if (viewOnlyDeptName) viewOnlyDeptName.innerText = deptNames;
                    }
                }
            }

            // Field Population
            const vTitle = document.getElementById('view_doc_title');
            if (vTitle) vTitle.innerText = doc.title || 'â€”';

            const vCat = document.getElementById('view_doc_category');
            if (vCat) vCat.innerText = doc.category || 'General';

            const vVer = document.getElementById('view_doc_academic_version');
            if (vVer) vVer.innerText = doc.academic_version || '2026-27';

            // Academic Program Mapped
            const progContainer = document.getElementById('viewDocProgramContainer') || document.getElementById('viewDocDeptContainer');
            if (progContainer) {
                const progName = doc.program_name || (doc.program && doc.program.name) || (doc.program && doc.program.course_name) || '';
                if (progName) {
                    progContainer.innerHTML = `<span class="badge" style="background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; font-size:12px; font-weight:600; padding:5px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:6px;">ðŸŽ“ ${escapeHtml(progName)}</span>`;
                } else {
                    progContainer.innerHTML = '<span style="font-size: 12px; color: #94A3B8;">â€” None (Global / Institution-wide)</span>';
                }
            }

            // Dates & Review
            const vEff = document.getElementById('view_doc_effective_from');
            if (vEff) vEff.innerText = doc.effective_from ? formatCkhDate(doc.effective_from) : 'Immediate';

            const vExp = document.getElementById('view_doc_expires_on');
            if (vExp) vExp.innerText = doc.expires_on ? formatCkhDate(doc.expires_on) : 'Evergreen (No expiration)';

            const vFreq = document.getElementById('view_doc_review_freq');
            if (vFreq) {
                const days = doc.review_frequency_days || 365;
                const months = Math.round(days / 30);
                vFreq.innerText = `${days} Days (${months} Months)`;
            }

            const vLastReview = document.getElementById('view_doc_last_reviewed');
            if (vLastReview) vLastReview.innerText = doc.last_reviewed_at ? formatCkhDate(doc.last_reviewed_at.split('T')[0]) : 'Pending First Cycle';

            // Word Count
            const raw = doc.raw_content || '';
            const words = raw.trim() ? raw.trim().split(/\s+/).length : 0;
            const wcBadge = document.getElementById('viewDocWordCountBadge');
            if (wcBadge) wcBadge.innerText = `${words.toLocaleString()} words`;

            // URL Row
            const urlRow = document.getElementById('viewDocUrlRow');
            const urlInput = document.getElementById('view_doc_source_url');
            const urlBtn = document.getElementById('btnViewDocOpenUrl');
            if (doc.type === 'url' && doc.source_url) {
                if (urlRow) urlRow.style.display = 'block';
                if (urlInput) urlInput.value = doc.source_url;
                if (urlBtn) urlBtn.href = doc.source_url;
            } else {
                if (urlRow) urlRow.style.display = 'none';
            }

            // Semantic Keywords
            const kwContainer = document.getElementById('viewDocKeywordsContainer');
            if (kwContainer) {
                const kwStr = doc.semantic_keywords || doc.keywords || '';
                const kwList = kwStr.split(',').map(s => s.trim()).filter(Boolean);
                if (kwList.length > 0) {
                    kwContainer.innerHTML = kwList.map(kw => `<span style="font-size:11px; padding:3px 8px; border-radius:4px; background:#F1F5F9; border:1px solid #CBD5E1; color:#334155;">#${escapeHtml(kw)}</span>`).join('');
                } else {
                    kwContainer.innerHTML = '<span style="font-size:12px; color:#94A3B8;">None specified</span>';
                }
            }

            // Raw Policy Content
            const rawEl = document.getElementById('view_doc_raw_content');
            if (rawEl) rawEl.innerText = raw || '(No content available)';
        }

        async function populateKnowledgeEditor(doc) {
            const idEl = document.getElementById('editDocId');
            if (idEl) idEl.value = doc.id;

            const idTag = document.getElementById('docEditorIdTag');
            if (idTag) idTag.textContent = '#' + doc.id;

            const headerTitle = document.getElementById('docEditorHeaderTitle');
            if (headerTitle) {
                headerTitle.textContent = doc.title || 'Untitled Document';
                headerTitle.title = doc.title || '';
            }

            // Icon by type - Consistent badge style
            const iconEl = document.getElementById('docEditorTypeIcon');
            if (iconEl) {
                const isDocArchived = doc.status === 'archived';
                const docBadge = getKnowledgeDocBadge(doc, isDocArchived);
                iconEl.className = `ckh-file-badge ${docBadge.badgeClass}`;
                iconEl.textContent = docBadge.badgeText;
                iconEl.style.cssText = 'width: 32px; height: 32px; border-radius: 6px; font-size: 10px; font-weight: 800;';
            }

            // Status Badge - Prominent Standout Pill
            const badgeEl = document.getElementById('docEditorStatusBadge');
            if (badgeEl) {
                const today = new Date().toISOString().split('T')[0];
                const isArchived = doc.status === 'archived';
                const isExpired = doc.status === 'expired' || (doc.expires_on && doc.expires_on < today);
                const isExpiringSoon = doc.status === 'expiring_soon' || (doc.expires_on && !isExpired && (new Date(doc.expires_on) - new Date(today)) <= 30 * 86400000);

                let bg, border, color, dotColor, label;

                if (isArchived) {
                    bg = '#F1F5F9';
                    border = '#CBD5E1';
                    color = '#475569';
                    dotColor = '#64748B';
                    label = 'Archived / Inactive';
                } else if (isExpired) {
                    bg = '#FEF2F2';
                    border = '#F87171';
                    color = '#B91C1C';
                    dotColor = '#EF4444';
                    label = 'Quarantined â€¢ Expired';
                } else if (isExpiringSoon) {
                    bg = '#FFFBEB';
                    border = '#FBBF24';
                    color = '#B45309';
                    dotColor = '#F59E0B';
                    label = 'Expiring Soon';
                } else {
                    bg = '#ECFDF5';
                    border = '#34D399';
                    color = '#047857';
                    dotColor = '#10B981';
                    label = 'Active & Live';
                }

                badgeEl.style.cssText = 'display: inline-flex !important; align-items: center !important; gap: 7px !important; padding: 5px 14px !important; border-radius: 9999px !important; font-size: 11.5px !important; font-weight: 800 !important; letter-spacing: 0.2px !important; background: ' + bg + ' !important; border: 1.5px solid ' + border + ' !important; color: ' + color + ' !important; box-shadow: 0 1px 3px rgba(0,0,0,0.06) !important; flex-shrink: 0 !important;';
                const shadowRgba = isExpired ? '239,68,68' : (isExpiringSoon ? '245,158,11' : (isArchived ? '100,116,139' : '16,185,129'));
                badgeEl.innerHTML = '<span style="width: 7px; height: 7px; border-radius: 50%; background: ' + dotColor + '; box-shadow: 0 0 0 2.5px rgba(' + shadowRgba + ', 0.25); display: inline-block;"></span> <span>' + label + '</span>';
            }

            // Inputs
            const titleInput = document.getElementById('edit_doc_title');
            if (titleInput) titleInput.value = doc.title || '';

            const catSelect = document.getElementById('edit_doc_category');
            const customWrap = document.getElementById('editDocCategoryCustomWrap');
            const customInp = document.getElementById('edit_doc_category_custom');
            if (catSelect) {
                const docCat = (doc.category || 'General / Institutional').trim();
                let matched = false;
                for (let i = 0; i < catSelect.options.length; i++) {
                    if (catSelect.options[i].value !== '__custom__' && catSelect.options[i].value.toLowerCase() === docCat.toLowerCase()) {
                        catSelect.selectedIndex = i;
                        matched = true;
                        break;
                    }
                }
                if (matched) {
                    if (customWrap) customWrap.style.display = 'none';
                    if (customInp) customInp.value = '';
                } else {
                    catSelect.value = '__custom__';
                    if (customWrap) customWrap.style.display = 'block';
                    if (customInp) customInp.value = docCat;
                }
            }

            const verInput = document.getElementById('edit_doc_academic_version');
            if (verInput) verInput.value = doc.academic_version || '2026-27';

            const effInput = document.getElementById('edit_doc_effective_from');
            if (effInput) effInput.value = doc.effective_from ? doc.effective_from.split('T')[0] : '';

            const expInput = document.getElementById('edit_doc_expires_on');
            if (expInput) expInput.value = doc.expires_on ? doc.expires_on.split('T')[0] : '';

            const freqInput = document.getElementById('edit_doc_review_freq');
            if (freqInput) freqInput.value = doc.review_frequency_days || 180;

            const revInput = document.getElementById('edit_doc_last_reviewed');
            if (revInput) revInput.value = doc.last_reviewed_at || (doc.created_at ? doc.created_at.substring(0, 10) : 'None');

            const semKeyInput = document.getElementById('edit_doc_semantic_keywords');
            if (semKeyInput) semKeyInput.value = doc.semantic_keywords || doc.keywords || '';

            const contentTextarea = document.getElementById('edit_doc_raw_content');
            const raw = doc.raw_content || doc.processed_content || '';
            if (contentTextarea) contentTextarea.value = raw;

            // Word Count
            const words = raw.trim() ? raw.trim().split(/\s+/).length : 0;
            const wcBadge = document.getElementById('docEditorWordCountBadge');
            if (wcBadge) wcBadge.textContent = words.toLocaleString() + ' words';
            const wcBadge2 = document.getElementById('docEditorWordCountBadge2');
            if (wcBadge2) wcBadge2.textContent = words.toLocaleString() + ' words';

            // Document Content Preview Pre-element
            const contentDisplay = document.getElementById('docEditorContentDisplay');
            if (contentDisplay) {
                contentDisplay.innerText = raw.trim() ? raw : '(No extracted text available for this knowledge item)';
            }

            // File Row Display & Download Button
            const fileRowDisplay = document.getElementById('docEditorFileRowDisplay');
            const fileNameDisplay = document.getElementById('docEditorFileNameDisplay');
            const topDownloadBtn = document.getElementById('btnDocEditorDownload');
            if (doc.file_path) {
                const fname = doc.file_path.split('/').pop();
                if (fileRowDisplay) fileRowDisplay.style.display = 'flex';
                if (fileNameDisplay) fileNameDisplay.textContent = fname || (doc.title + ' (Attachment)');
                if (topDownloadBtn) topDownloadBtn.style.display = 'inline-flex';
            } else {
                if (fileRowDisplay) fileRowDisplay.style.display = 'none';
                if (topDownloadBtn) {
                    // Even without file_path, document or text paste can be downloaded as .txt
                    topDownloadBtn.style.display = raw.trim() ? 'inline-flex' : 'none';
                }
            }

            // URL handling
            const urlRow = document.getElementById('docEditorUrlRow');
            const btnResync = document.getElementById('btnResyncUrl');
            const urlInput = document.getElementById('edit_doc_source_url');
            const openUrlBtn = document.getElementById('btnDocEditorOpenUrl');
            const urlRowDisplay = document.getElementById('docEditorUrlRowDisplay');
            const openUrlDisplay = document.getElementById('btnDocEditorOpenUrlDisplay');

            if (doc.type === 'url') {
                if (urlRow) urlRow.style.display = 'block';
                if (btnResync) btnResync.style.display = 'inline-flex';
                if (urlInput) urlInput.value = doc.source_url || '';
                if (openUrlBtn) openUrlBtn.href = doc.source_url || '#';
                if (urlRowDisplay) urlRowDisplay.style.display = 'block';
                if (openUrlDisplay) {
                    openUrlDisplay.href = doc.source_url || '#';
                    openUrlDisplay.textContent = doc.source_url || 'â€”';
                }
            } else {
                if (urlRow) urlRow.style.display = 'none';
                if (btnResync) btnResync.style.display = 'none';
                if (urlRowDisplay) urlRowDisplay.style.display = 'none';
            }

            // Archive button state
            const btnArchive = document.getElementById('btnDocEditorArchive');
            if (btnArchive) {
                if (doc.status === 'archived') {
                    btnArchive.innerHTML = '<span>â™»ï¸</span> Unarchive / Restore Document';
                    btnArchive.style.borderColor = '#A7F3D0';
                    btnArchive.style.color = '#047857';
                } else {
                    btnArchive.innerHTML = '<span>ðŸ“¦</span> Archive Document';
                    btnArchive.style.borderColor = '#FCA5A5';
                    btnArchive.style.color = '#991B1B';
                }
            }

            // Load and populate academic programs
            await loadDocEditorPrograms(doc.program_id);
        }

        async function loadDocEditorPrograms(selectedProgramId) {
            const container = document.getElementById('editDocProgramContainer');
            if (!container) return;

            try {
                const res = await fetch('/v1/programs', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                const programs = (data.status === 'success' && data.data) ? (data.data.courses || (Array.isArray(data.data) ? data.data : [])) : [];

                const isGlobal = !selectedProgramId;
                let html = `
                    <label style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; background: ${isGlobal ? '#E8F5F3' : '#FFFFFF'}; border: 1.5px solid ${isGlobal ? '#063D3B' : '#E2E8F0'}; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: ${isGlobal ? '#063D3B' : '#0F172A'}; user-select: none; transition: all 0.15s ease; ${isGlobal ? 'box-shadow: 0 0 0 1px #063D3B;' : ''}">
                        <input type="radio" name="edit_doc_program" value="" ${isGlobal ? 'checked' : ''} onchange="updateIngestProgramRadios('editDocProgramContainer')" style="cursor: pointer; accent-color: #063D3B;" />
                        <span>ðŸŒ</span>
                        <span>Entire Organization (All Programs)</span>
                    </label>
                `;

                if (programs.length > 0) {
                    html += programs.map(p => {
                        const isSelected = selectedProgramId && parseInt(selectedProgramId) === parseInt(p.id);
                        return `
                            <label style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; background: ${isSelected ? '#E8F5F3' : '#FFFFFF'}; border: 1.5px solid ${isSelected ? '#063D3B' : '#E2E8F0'}; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: ${isSelected ? '#063D3B' : '#0F172A'}; user-select: none; transition: all 0.15s ease; ${isSelected ? 'box-shadow: 0 0 0 1px #063D3B;' : ''}">
                                <input type="radio" name="edit_doc_program" value="${p.id}" ${isSelected ? 'checked' : ''} onchange="updateIngestProgramRadios('editDocProgramContainer')" style="cursor: pointer; accent-color: #063D3B;" />
                                <span>ðŸŽ“</span>
                                <span>${escapeHtml(p.course_name)}${p.course_code ? ' (' + escapeHtml(p.course_code) + ')' : ''}</span>
                            </label>
                        `;
                    }).join('');
                }

                container.innerHTML = html;
            } catch (e) {
                console.error('Error fetching academic programs for editor:', e);
                container.innerHTML = '<span style="font-size: 12px; color: #EF4444;">Failed to load academic programs.</span>';
            }
        }

        async function handleDocEditorSubmit(event) {
            if (event) event.preventDefault();
            if (!currentEditingDoc) return;

            const docId = currentEditingDoc.id;
            const title = document.getElementById('edit_doc_title').value.trim();
            if (!title) {
                alert('Document title is required.');
                return;
            }

            const progRadio = document.querySelector('input[name="edit_doc_program"]:checked');
            const program_id = progRadio && progRadio.value ? parseInt(progRadio.value) : null;
            let category = document.getElementById('edit_doc_category').value;
            if (category === '__custom__') {
                const customInp = document.getElementById('edit_doc_category_custom');
                const customVal = customInp ? customInp.value.trim() : '';
                if (!customVal) {
                    alert('Please specify a custom category name.');
                    if (customInp) customInp.focus();
                    return;
                }
                category = customVal;
            }
            const academic_version = document.getElementById('edit_doc_academic_version').value.trim();
            const effective_from = document.getElementById('edit_doc_effective_from').value || null;
            const expires_on = document.getElementById('edit_doc_expires_on').value || null;
            const review_frequency_days = parseInt(document.getElementById('edit_doc_review_freq').value) || 180;
            const semantic_keywords = document.getElementById('edit_doc_semantic_keywords').value.trim();
            const raw_content = document.getElementById('edit_doc_raw_content').value;

            const submitBtn = document.getElementById('docEditorFormSubmitBtn');
            const topBtn = document.getElementById('btnSaveDocTop');
            const origText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span>â³</span> Saving...';
            submitBtn.disabled = true;
            if (topBtn) topBtn.disabled = true;

            try {
                const res = await fetch(`/v1/knowledge/${docId}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({
                        title,
                        category,
                        academic_version,
                        effective_from,
                        expires_on,
                        review_frequency_days,
                        semantic_keywords,
                        raw_content,
                        program_id
                    })
                });

                const data = await res.json();
                if (data.status === 'success') {
                    showToast('âœ“ Document details and Freshness Shield updated successfully!', 'success');
                    await loadKnowledge();
                    // Refresh editor header
                    document.getElementById('docEditorHeaderTitle').textContent = title;
                } else {
                    alert(data.message || 'Failed to save document updates.');
                }
            } catch (err) {
                console.error('Error saving document:', err);
                alert('Connection error while saving document.');
            } finally {
                submitBtn.innerHTML = origText;
                submitBtn.disabled = false;
                if (topBtn) topBtn.disabled = false;
            }
        }

        async function docEditorMarkAuditedToday() {
            if (!currentEditingDoc) return;
            try {
                const res = await fetch(`/v1/knowledge/${currentEditingDoc.id}/review`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({})
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const today = new Date().toISOString().split('T')[0];
                    document.getElementById('edit_doc_last_reviewed').value = today;
                    showToast('âœ“ Document verified & marked as audited today!', 'success');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to record audit.');
                }
            } catch (err) {
                console.error(err);
            }
        }

        function docEditorSetExpiryDelta(days) {
            const d = new Date();
            d.setDate(d.getDate() + days);
            const iso = d.toISOString().split('T')[0];
            const el = document.getElementById('edit_doc_expires_on');
            if (el) el.value = iso;
            showToast(`Validity expiry set to ${iso} (+${days} days)`, 'info');
        }

        function docEditorClearExpiry() {
            const el = document.getElementById('edit_doc_expires_on');
            if (el) el.value = '';
            showToast('Document expiration cleared â€” set to Evergreen policy.', 'info');
        }

        async function docEditorToggleArchive() {
            if (!currentEditingDoc) return;
            const isArchived = currentEditingDoc.status === 'archived';
            if (isArchived) {
                // Unarchive / restore
                try {
                    const res = await fetch(`/v1/knowledge/${currentEditingDoc.id}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                        body: JSON.stringify({
                            title: currentEditingDoc.title,
                            status: 'active'
                        })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        currentEditingDoc.status = 'active';
                        showToast('âœ“ Document unarchived and restored to active AI service!', 'success');
                        await openKnowledgeEditor(currentEditingDoc.id);
                        await loadKnowledge();
                    }
                } catch (e) {
                    console.error(e);
                }
            } else {
                if (!confirm('Archive this document? It will be quarantined from the visitor chatbot.')) return;
                await archiveKnowledge(currentEditingDoc.id);
                await openKnowledgeEditor(currentEditingDoc.id);
            }
        }

        async function docEditorDeleteDoc() {
            if (!currentEditingDoc) return;
            if (!confirm(`Are you sure you want to permanently delete "${currentEditingDoc.title}"? This cannot be undone.`)) return;
            try {
                const res = await fetch(`/v1/knowledge/${currentEditingDoc.id}`, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Document permanently deleted.', 'info');
                    await loadKnowledge();
                    closeKnowledgeEditor();
                } else {
                    alert(data.message || 'Failed to delete document.');
                }
            } catch (err) {
                console.error(err);
                alert('Connection error deleting document.');
            }
        }

        async function docEditorResyncContent() {
            if (!currentEditingDoc || currentEditingDoc.type !== 'url') return;
            const btn = document.getElementById('btnResyncUrl');
            const orig = btn.innerHTML;
            btn.innerHTML = 'ðŸ”„ Syncing...';
            btn.disabled = true;
            try {
                const res = await fetch(`/v1/knowledge/${currentEditingDoc.id}/refresh`, {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('âœ“ Web content re-scraped and updated live!', 'success');
                    await openKnowledgeEditor(currentEditingDoc.id);
                } else {
                    alert(data.message || 'Failed to re-sync URL content.');
                }
            } catch (e) {
                console.error(e);
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        }

        function docEditorTriggerReplace() {
            const docId = (currentEditingDoc && currentEditingDoc.id)
                || window._activeEditingDocId
                || sessionStorage.getItem('edvora_edit_doc_id')
                || (new URLSearchParams(window.location.hash.includes('?') ? window.location.hash.split('?')[1] : window.location.search)).get('id');
            if (!docId) {
                showToast('No active document loaded to replace.', 'warning');
                return;
            }
            openReplaceKnowledgeModal(docId);
        }

        function docEditorDownloadOriginal() {
            if (!currentEditingDoc) return;
            const docId = currentEditingDoc.id;
            // Secure download using token
            const url = `/v1/knowledge/${docId}/download?token=${encodeURIComponent(token)}`;
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function docEditorToggleContentCard() {
            const card = document.getElementById('docEditorContentCard');
            const textEl = document.getElementById('btnDocEditorToggleContentText');
            if (!card) return;
            if (card.style.display === 'none') {
                card.style.display = 'block';
                if (textEl) textEl.textContent = 'Hide Content';
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function docEditorCopyContent() {
            const contentDisplay = document.getElementById('docEditorContentDisplay');
            if (!contentDisplay) return;
            const text = contentDisplay.innerText || '';
            if (!text || text.startsWith('(No extracted text')) {
                showToast('No content available to copy', 'warning');
                return;
            }
            navigator.clipboard.writeText(text).then(() => {
                showToast('âœ“ Document content copied to clipboard!', 'success');
            }).catch(() => {
                showToast('Failed to copy to clipboard', 'error');
            });
        }

        async function addMainKnowledgeText(e) {
            if (e) e.preventDefault();
            const title = document.getElementById('pasteTitle').value.trim();
            const content = document.getElementById('pasteContent').value.trim();
            const category = document.getElementById('pasteCategory').value;
            const academic_version = document.getElementById('pasteAcademicVersion').value.trim();
            const effective_from = document.getElementById('pasteEffectiveFrom').value;
            const expires_on = document.getElementById('pasteExpiresOn').value;
            const review_frequency_days = parseInt(document.getElementById('pasteReviewDays').value) || 180;

            if (!title || !content) {
                alert('Please fill in both Document Title and Prospectus Content.');
                return;
            }

            try {
                const res = await fetch('/v1/knowledge/paste', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({
                        title, content, category, academic_version, effective_from, expires_on, review_frequency_days
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('pasteTitle').value = '';
                    document.getElementById('pasteContent').value = '';
                    showToast('ðŸŽ‰ Knowledge content indexed and primed for AI!', 'success');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to add knowledge source');
                }
            } catch (err) {
                console.error(err);
                alert('Error adding text knowledge source');
            }
        }

        async function addMainKnowledgeFile(e) {
            if (e) e.preventDefault();
            const title = document.getElementById('mainKsTitleFile').value.trim();
            const fileInput = document.getElementById('mainKsFileInput');
            const category = document.getElementById('fileCategory').value;
            const academic_version = document.getElementById('fileAcademicVersion').value.trim();
            const effective_from = document.getElementById('fileEffectiveFrom').value;
            const expires_on = document.getElementById('fileExpiresOn').value;
            const review_frequency_days = parseInt(document.getElementById('fileReviewDays').value) || 180;

            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Please select a file to upload (.pdf, .docx, .txt).');
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
            if (title) formData.append('title', title);
            formData.append('category', category);
            formData.append('academic_version', academic_version);
            if (effective_from) formData.append('effective_from', effective_from);
            if (expires_on) formData.append('expires_on', expires_on);
            formData.append('review_frequency_days', review_frequency_days);

            try {
                const res = await fetch('/v1/knowledge/upload', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token },
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('mainKsTitleFile').value = '';
                    fileInput.value = '';
                    showToast('ðŸŽ‰ Document uploaded, extracted, and indexed!', 'success');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to upload document');
                }
            } catch (err) {
                console.error(err);
                alert('Error uploading document');
            }
        }

        async function addMainKnowledgeUrl(e) {
            if (e) e.preventDefault();
            const title = document.getElementById('mainKsTitleUrl').value.trim();
            const url = document.getElementById('mainKsUrlInput').value.trim();
            const category = document.getElementById('urlCategory').value;
            const academic_version = document.getElementById('urlAcademicVersion').value.trim();
            const effective_from = document.getElementById('urlEffectiveFrom').value;
            const expires_on = document.getElementById('urlExpiresOn').value;
            const review_frequency_days = parseInt(document.getElementById('urlReviewDays').value) || 180;

            if (!url) {
                alert('Please enter a valid Web Page URL.');
                return;
            }

            try {
                const res = await fetch('/v1/knowledge/url', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({
                        url, title, category, academic_version, effective_from, expires_on, review_frequency_days
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('mainKsTitleUrl').value = '';
                    document.getElementById('mainKsUrlInput').value = '';
                    showToast('ðŸŒ Web URL ingested and queued for crawling!', 'success');
                    await loadKnowledge();
                } else {
                    alert(data.message || 'Failed to add web URL');
                }
            } catch (err) {
                console.error(err);
                alert('Error adding web URL');
            }
        }

        // â”€â”€ LEAD-MAGNET ASSET MANAGEMENT JS LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let currentAssetsList = [];
        let userAssetContext = { is_admin: true, assigned_dept_ids: [], role: 'admin' };
        let activeEditingAssetId = null;

        const ASSET_BLUEPRINT_CONFIGS = {
            prospectus: {
                title: 'Official College Prospectus & Academic Handbook',
                category: 'brochure',
                trigger: 'prospectus, overview, admission brochure, courses list, eligibility',
                desc: 'Comprehensive overview of degrees, accreditation, campus facilities, faculty credentials, and admission timelines.'
            },
            fee_structure: {
                title: '2026-27 Comprehensive Fee Structure & Installment Payment Guide',
                category: 'fee_structure',
                trigger: 'fee structure, installments, education loan, semester fee, payment plans',
                desc: 'Detailed breakdown of tuition, lab, exam, hostel fees, installment schedule, and tie-up bank education loan terms.'
            },
            scholarship: {
                title: 'Merit & Need-Based Scholarship Eligibility Matrix',
                category: 'scholarship_guide',
                trigger: 'scholarship criteria, fee waiver, financial aid, merit discount, scholarship form',
                desc: 'Cutoffs and waiver percentages based on 12th/Graduation marks, entrance exam percentiles, sports quota, and sibling discounts.'
            },
            placement: {
                title: 'Annual Campus Placement & Top Recruiters Salary Report',
                category: 'placement_report',
                trigger: 'placement report, average salary, highest package, recruiters, hiring companies',
                desc: 'Highest and average CTC packages, Fortune 500 hiring partners list, branch-wise placement stats, and alumni career paths.'
            },
            hostel: {
                title: 'Hostel Amenities, Room Types & Mess Menu Handbook',
                category: 'hostel_guide',
                trigger: 'hostel fee, mess food, accommodation, room types, hostel rules',
                desc: 'AC/Non-AC room configurations, laundry, Wi-Fi, security protocols, weekly dining hall menu, and curfew timings for outstation students.'
            },
            curriculum: {
                title: 'Semester-wise Syllabus & Curriculum Structure',
                category: 'curriculum',
                trigger: 'syllabus, curriculum, subjects list, course modules, elective tracks',
                desc: 'Detailed elective tracks, lab modules, industry certifications, and capstone project guidelines mapped per department.'
            },
            cutoff: {
                title: 'Previous Year Cutoff Marks & Exam Prep Kit',
                category: 'exam_cutoff',
                trigger: 'cutoff marks, entrance exam syllabus, minimum score, opening closing rank',
                desc: 'Historical opening/closing ranks across categories (General, OBC, SC/ST, EWS) and sample question papers for entrance tests.'
            },
            international: {
                title: 'International Students & NRI Admissions Handbook',
                category: 'international_guide',
                trigger: 'NRI quota, international admission, foreign student visa, foreign fee',
                desc: 'Visa guidance, AIU equivalency verification, English proficiency criteria, foreign currency fee structure, and dedicated arrival support.'
            },
            research: {
                title: 'Research Facilities, Labs & Incubation Portfolio',
                category: 'other',
                trigger: 'research labs, phd guide, incubation center, startup grants, patents',
                desc: 'State-of-the-art laboratory infrastructure, sponsored grant projects, patent filings, and startup seed funding opportunities.'
            },
            sports: {
                title: 'Sports, Arts & Cultural Quota Admissions Guide',
                category: 'other',
                trigger: 'sports quota, trials date, cultural quota, extracurricular seat, trial venue',
                desc: 'Trial schedules, national/state certificate weightage, sports scholarship seats, and cultural society membership perks.'
            }
        };

        async function loadAssets() {
