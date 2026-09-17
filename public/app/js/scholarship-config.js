/**
 * scholarship-config.js — Scholarship Configuration & Editor Controller
 * Supports #scholarship-configuration (list) and #scholarship-configuration-edit (create/edit)
 */

window._scholarshipRules = [];
window._scholarshipPrograms = [];
window._scholarshipFilter = 'all';

/**
 * Initialize Scholarship Configuration List Page
 */
async function initScholarshipConfiguration() {
    try {
        await Promise.all([loadScholarshipPrograms(), loadScholarshipRules()]);
    } catch (err) {
        console.error('Error initializing scholarships:', err);
    }
}

/**
 * Fetch available academic programs for mapping
 */
async function loadScholarshipPrograms() {
    try {
        const token = localStorage.getItem('edvora_token') || sessionStorage.getItem('edvora_token');
        const res = await fetch('/v1/programs', {
            headers: token ? { 'Authorization': 'Bearer ' + token } : {}
        });
        const json = await res.json();
        const rawList = (json.data && Array.isArray(json.data.courses)) ? json.data.courses : (Array.isArray(json.data) ? json.data : []);
        window._scholarshipPrograms = rawList;
    } catch (e) {
        console.warn('Failed to load academic programs for scholarship:', e);
        window._scholarshipPrograms = [];
    }
}

/**
 * Fetch all scholarship rules for organization
 */
async function loadScholarshipRules() {
    const tbody = document.getElementById('scholarshipsTableBody');
    if (tbody) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 36px 20px; color: #648781;">
                    <div style="display: flex; align-items: center; justify-content: center; gap: 8px; font-weight: 600; font-size: 12.5px;">
                        <span>Loading scholarship rules...</span>
                    </div>
                </td>
            </tr>
        `;
    }

    try {
        const token = localStorage.getItem('edvora_token') || sessionStorage.getItem('edvora_token');
        const res = await fetch('/v1/scholarship-rules', {
            headers: token ? { 'Authorization': 'Bearer ' + token } : {}
        });
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
            window._scholarshipRules = json.data;
            updateScholarshipStats();
            renderScholarshipsTable();
        } else {
            window._scholarshipRules = [];
            if (tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 36px 20px; color: #DC2626;">
                            Failed to load scholarships: ${escapeHtml(json.error || 'Unknown error')}
                        </td>
                    </tr>
                `;
            }
        }
    } catch (e) {
        console.error('Error fetching scholarship rules:', e);
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; padding: 36px 20px; color: #DC2626;">
                        Network error while fetching scholarships.
                    </td>
                </tr>
            `;
        }
    }
}

/**
 * Update stats and tab counts
 */
function updateScholarshipStats() {
    const rules = window._scholarshipRules || [];
    const total = rules.length;
    const active = rules.filter(r => Number(r.is_active) === 1).length;
    const inactive = total - active;
    const undergrad = rules.filter(r => (r.program_type || '').toLowerCase() === 'undergraduate').length;
    const postgrad = rules.filter(r => (r.program_type || '').toLowerCase() === 'postgraduate').length;

    const elTotal = document.getElementById('tabCountSchAll');
    if (elTotal) elTotal.textContent = total;

    const elActive = document.getElementById('tabCountSchActive');
    if (elActive) elActive.textContent = active;

    const elInactive = document.getElementById('tabCountSchInactive');
    if (elInactive) elInactive.textContent = inactive;

    const elUndergrad = document.getElementById('tabCountSchUndergrad');
    if (elUndergrad) elUndergrad.textContent = undergrad;

    const elPostgrad = document.getElementById('tabCountSchPostgrad');
    if (elPostgrad) elPostgrad.textContent = postgrad;

    const pill = document.getElementById('scholarshipsActivePillCount');
    if (pill) {
        pill.textContent = `Scholarships: ${active} Active / ${total} Total`;
    }
}

/**
 * Filter tabs switcher
 */
function setScholarshipFilter(filter, btnEl) {
    window._scholarshipFilter = filter;
    document.querySelectorAll('#scholarshipsTabsContainer .ckh-tab-btn').forEach(btn => btn.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    renderScholarshipsTable();
}

/**
 * Search input filter
 */
function filterScholarshipsTable() {
    renderScholarshipsTable();
}

/**
 * Render scholarships table matching #teams layout
 */
function renderScholarshipsTable() {
    const tbody = document.getElementById('scholarshipsTableBody');
    if (!tbody) return;

    const query = (document.getElementById('scholarshipsSearchInput')?.value || '').toLowerCase().trim();
    const filter = window._scholarshipFilter || 'all';

    let list = (window._scholarshipRules || []).filter(r => {
        // Tab filter
        if (filter === 'active' && Number(r.is_active) !== 1) return false;
        if (filter === 'inactive' && Number(r.is_active) === 1) return false;
        if (filter === 'undergraduate' && (r.program_type || '').toLowerCase() !== 'undergraduate') return false;
        if (filter === 'postgraduate' && (r.program_type || '').toLowerCase() !== 'postgraduate') return false;

        // Search text
        if (query) {
            const title = (r.title || '').toLowerCase();
            const code = (r.code || '').toLowerCase();
            const prog = (r.program_name || '').toLowerCase();
            const progCode = (r.program_code || '').toLowerCase();
            const exam = (r.exam_name || '').toLowerCase();
            if (!title.includes(query) && !code.includes(query) && !prog.includes(query) && !progCode.includes(query) && !exam.includes(query)) {
                return false;
            }
        }
        return true;
    });

    if (list.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 48px 20px; color: #648781;">
                    <div style="font-size: 32px; margin-bottom: 8px;">💰</div>
                    <strong style="font-size: 14px; color: #063D3B; display: block; margin-bottom: 4px;">No Scholarships Found</strong>
                    <span style="font-size: 12px; color: #648781; display: block; margin-bottom: 16px;">
                        ${query ? 'No scholarships matched your search criteria.' : 'No scholarship rules have been configured yet for this category.'}
                    </span>
                    <button type="button" class="brand-btn-primary" onclick="openCreateScholarshipPage()" style="height: 32px; padding: 0 14px; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                        <span>+</span> Create First Scholarship
                    </button>
                </td>
            </tr>
        `;
        return;
    }

    const metricLabels = {
        'percentage_12th': '12th Marks (%)',
        'graduation_cgpa': 'Graduation CGPA',
        'entrance_exam': 'Entrance Exam Score',
        'merit_rank': 'Rank / Percentile',
        'general_merit': 'General Merit'
    };

    tbody.innerHTML = list.map(r => {
        const isActive = Number(r.is_active) === 1;
        const statusBadge = isActive
            ? `<span style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: #ECFDF5; border: 1.5px solid #A7F3D0; color: #047857;"><span style="width: 6px; height: 6px; border-radius: 50%; background: #10B981; display: inline-block;"></span> Active</span>`
            : `<span style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: #F1F5F9; border: 1.5px solid #CBD5E1; color: #64748B;"><span style="width: 6px; height: 6px; border-radius: 50%; background: #94A3B8; display: inline-block;"></span> Inactive</span>`;

        let awardBadge = '';
        if (r.discount_type === 'fixed_amount') {
            const currency = r.program_currency || 'INR';
            const sym = currency === 'USD' ? '$' : '₹';
            awardBadge = `<span style="font-weight: 800; color: #047857; font-size: 13px;">${sym}${Number(r.discount_value).toLocaleString()} Fixed</span>`;
        } else {
            const val = Number(r.discount_value) || 0;
            const slabsCount = Array.isArray(r.slabs) ? r.slabs.length : 0;
            if (slabsCount > 0) {
                awardBadge = `<div><span style="font-weight: 800; color: #1D4ED8; font-size: 13px;">Up to ${Math.max(...r.slabs.map(s => Number(s.waiver_pct) || 0), val)}% Waiver</span><div style="font-size: 10.5px; color: #64748B;">${slabsCount} tiered slabs</div></div>`;
            } else {
                awardBadge = `<span style="font-weight: 800; color: #047857; font-size: 13px;">${val}% Tuition Waiver</span>`;
            }
        }

        const metricStr = metricLabels[r.evaluation_metric] || r.evaluation_metric;
        const examDetail = r.evaluation_metric === 'entrance_exam' && r.exam_name ? ` (${escapeHtml(r.exam_name)})` : '';

        const progName = r.program_name ? escapeHtml(r.program_name) : `<span style="color:#DC2626; font-style:italic;">Unassigned / Missing Program</span>`;
        const progType = r.program_type ? `<span style="display: inline-block; padding: 2px 7px; border-radius: 4px; background: #F1F5F9; color: #475569; font-size: 10.5px; font-weight: 600; text-transform: uppercase;">${escapeHtml(r.program_type)}</span>` : '';

        return `
            <tr style="cursor: default;">
                <td>
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <div style="width: 32px; height: 32px; border-radius: 6px; background: #FFFBEB; border: 1px solid #FDE68A; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; color: #D97706;">
                            💰
                        </div>
                        <div>
                            <div style="font-weight: 700; font-size: 13px; color: #063D3B; line-height: 1.3;">
                                ${escapeHtml(r.title)}
                            </div>
                            <div style="font-size: 11px; color: #648781; margin-top: 2px; font-family: monospace;">
                                ${r.code ? escapeHtml(r.code) : 'NO CODE'} &bull; Rule #${r.id}
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div style="font-weight: 600; font-size: 12.5px; color: #0F172A;">
                        ${progName}
                    </div>
                    <div style="margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                        ${progType}
                        ${r.program_code ? `<span style="font-size: 11px; color: #64748B; font-family: monospace;">${escapeHtml(r.program_code)}</span>` : ''}
                    </div>
                </td>
                <td>
                    <div style="font-size: 12px; font-weight: 600; color: #334155;">
                        ${metricStr}${escapeHtml(examDetail)}
                    </div>
                    <div style="font-size: 11px; color: #648781; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(r.eligibility_criteria || '')}">
                        ${r.eligibility_criteria ? escapeHtml(r.eligibility_criteria) : 'Standard eligibility'}
                    </div>
                </td>
                <td>
                    ${awardBadge}
                </td>
                <td>
                    ${statusBadge}
                </td>
                <td style="text-align: right; white-space: nowrap;">
                    <div style="display: inline-flex; align-items: center; gap: 6px;">
                        <button type="button" class="brand-btn-secondary" onclick="openEditScholarshipPage(${r.id})" style="height: 30px; padding: 0 10px; font-size: 11.5px; font-weight: 700; color: #063D3B; display: inline-flex; align-items: center; gap: 4px;" title="Edit Scholarship">
                            <span>✏️</span> <span>Edit</span>
                        </button>
                        <button type="button" class="brand-btn-secondary" onclick="deleteScholarshipRule(${r.id}, '${escapeHtml(r.title)}')" style="height: 30px; padding: 0 9px; font-size: 11.5px; font-weight: 700; color: #DC2626; border-color: #FCA5A5; background: #FEF2F2;" title="Delete Scholarship">
                            <span>🗑️</span>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * Navigate to create new scholarship page (NO MODALS)
 */
function openCreateScholarshipPage() {
    location.hash = '#scholarship-configuration-edit';
}

/**
 * Navigate to edit scholarship page (NO MODALS)
 */
function openEditScholarshipPage(id) {
    location.hash = `#scholarship-configuration-edit?id=${id}`;
}

/**
 * Return back to scholarships list
 */
function closeScholarshipEditor() {
    location.hash = '#scholarship-configuration';
}

/**
 * Initialize Dedicated Scholarship Editor Page
 */
async function initScholarshipEditor() {
    const rawHash = location.hash.replace('#', '').trim();
    const queryStr = rawHash.includes('?') ? rawHash.split('?')[1] : '';
    const params = new URLSearchParams(queryStr);
    const editId = params.get('id');

    // Ensure programs are loaded
    await loadScholarshipPrograms();
    populateProgramDropdown();

    if (editId) {
        await loadScholarshipRuleForEdit(editId);
    } else {
        setupScholarshipRuleForCreate();
    }
}

/**
 * Populate Program Selector Dropdown in Editor
 */
function populateProgramDropdown(selectedId = null) {
    const select = document.getElementById('sch_program_id');
    if (!select) return;

    const programs = window._scholarshipPrograms || [];
    if (programs.length === 0) {
        select.innerHTML = `<option value="">⚠️ No Academic Programs found. Please create programs first.</option>`;
        return;
    }

    let opts = `<option value="">-- Select Academic Program to Map Scholarship --</option>`;
    programs.forEach(p => {
        const feeStr = p.tuition_fee ? ` (${p.currency || 'INR'} ${Number(p.tuition_fee).toLocaleString()} tuition)` : '';
        const sel = (selectedId && Number(selectedId) === Number(p.id)) ? 'selected' : '';
        opts += `<option value="${p.id}" ${sel}>${escapeHtml(p.course_name)} [${escapeHtml(p.program_type || 'UG')}]${feeStr}</option>`;
    });

    select.innerHTML = opts;
}

/**
 * Handle Program Selection Change in Editor
 */
function handleSchProgramChange(progId) {
    const hint = document.getElementById('schProgramFeeHint');
    if (!hint) return;

    if (!progId) {
        hint.style.display = 'none';
        hint.innerHTML = '';
        return;
    }

    const prog = (window._scholarshipPrograms || []).find(p => Number(p.id) === Number(progId));
    if (prog) {
        hint.style.display = 'block';
        hint.innerHTML = `Mapped Program: <strong>${escapeHtml(prog.course_name)}</strong> &bull; Level: ${escapeHtml(prog.program_type || 'UG')} &bull; Annual Tuition: ${prog.tuition_fee ? `${prog.currency || 'INR'} ${Number(prog.tuition_fee).toLocaleString()}` : 'Not Specified'}`;
    }
}

/**
 * Setup empty form for new scholarship rule
 */
function setupScholarshipRuleForCreate() {
    document.getElementById('editSchId').value = '';
    document.getElementById('schEditorHeaderTitle').textContent = 'Create New Scholarship';
    document.getElementById('schEditorIdTag').textContent = 'New Record';
    document.getElementById('schSubmitBtnText').textContent = 'Create Scholarship Rule';

    const form = document.getElementById('scholarshipEditorForm');
    if (form) form.reset();

    populateProgramDropdown();
    toggleExamNameField('percentage_12th');
    toggleDiscountType('percentage');
    toggleSchActiveBadge(true);

    const slabsContainer = document.getElementById('schSlabsContainer');
    if (slabsContainer) slabsContainer.innerHTML = '';
}

/**
 * Load scholarship rule data for editing
 */
async function loadScholarshipRuleForEdit(id) {
    document.getElementById('editSchId').value = id;
    document.getElementById('schEditorHeaderTitle').textContent = `Loading Scholarship #${id}...`;
    document.getElementById('schEditorIdTag').textContent = `#${id}`;
    document.getElementById('schSubmitBtnText').textContent = 'Save Changes';

    try {
        const res = await fetch(`/v1/scholarship-rules/${id}`);
        const json = await res.json();
        if (json.success && json.data) {
            const r = json.data;
            document.getElementById('schEditorHeaderTitle').textContent = r.title || 'Edit Scholarship';
            document.getElementById('sch_title').value = r.title || '';
            document.getElementById('sch_code').value = r.code || '';
            document.getElementById('sch_description').value = r.description || '';

            populateProgramDropdown(r.program_id);
            handleSchProgramChange(r.program_id);

            const metricSelect = document.getElementById('sch_evaluation_metric');
            if (metricSelect) metricSelect.value = r.evaluation_metric || 'percentage_12th';
            toggleExamNameField(r.evaluation_metric);
            document.getElementById('sch_exam_name').value = r.exam_name || '';

            const discSelect = document.getElementById('sch_discount_type');
            if (discSelect) discSelect.value = r.discount_type || 'percentage';
            toggleDiscountType(r.discount_type);
            document.getElementById('sch_discount_value').value = r.discount_value || '';

            document.getElementById('sch_eligibility_criteria').value = r.eligibility_criteria || '';
            document.getElementById('sch_terms_conditions').value = r.terms_conditions || '';
            document.getElementById('sch_max_recipients').value = r.max_recipients || '';

            const isActive = Number(r.is_active) === 1;
            const activeCheckbox = document.getElementById('sch_is_active');
            if (activeCheckbox) activeCheckbox.checked = isActive;
            toggleSchActiveBadge(isActive);

            // Slabs
            renderSchSlabs(Array.isArray(r.slabs) ? r.slabs : []);
        } else {
            showToast(json.error || 'Failed to load scholarship rule', 'error');
            closeScholarshipEditor();
        }
    } catch (e) {
        console.error('Error loading scholarship rule:', e);
        showToast('Network error while loading scholarship rule', 'error');
    }
}

/**
 * Toggle exam name input visibility
 */
function toggleExamNameField(metric) {
    const wrap = document.getElementById('schExamNameWrap');
    if (!wrap) return;
    if (metric === 'entrance_exam') {
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }
}

/**
 * Toggle discount type label
 */
function toggleDiscountType(type) {
    const label = document.getElementById('schDiscountValueLabel');
    if (!label) return;
    if (type === 'fixed_amount') {
        label.textContent = 'Fixed Scholarship Amount (Flat ₹/USD)';
    } else {
        label.textContent = 'Base Waiver Percentage (%)';
    }
}

/**
 * Toggle active badge styling
 */
function toggleSchActiveBadge(isActive) {
    const badge = document.getElementById('schEditorStatusBadge');
    const label = document.getElementById('schActiveLabel');
    if (isActive) {
        if (badge) {
            badge.innerHTML = `<span style="width: 7px; height: 7px; border-radius: 50%; background: #10B981; display: inline-block;"></span> <span>Active</span>`;
            badge.style.background = '#ECFDF5';
            badge.style.borderColor = '#34D399';
            badge.style.color = '#047857';
        }
        if (label) {
            label.textContent = 'Active';
            label.style.color = '#047857';
        }
    } else {
        if (badge) {
            badge.innerHTML = `<span style="width: 7px; height: 7px; border-radius: 50%; background: #94A3B8; display: inline-block;"></span> <span>Inactive</span>`;
            badge.style.background = '#F1F5F9';
            badge.style.borderColor = '#CBD5E1';
            badge.style.color = '#64748B';
        }
        if (label) {
            label.textContent = 'Inactive';
            label.style.color = '#64748B';
        }
    }
}

/**
 * Render multi-tier slabs in editor
 */
function renderSchSlabs(slabs = []) {
    const container = document.getElementById('schSlabsContainer');
    if (!container) return;
    container.innerHTML = '';
    slabs.forEach(s => addSchSlabRow(s.min, s.max, s.waiver_pct, s.label));
}

/**
 * Add a bracket slab row
 */
function addSchSlabRow(min = '', max = '', waiver = '', label = '') {
    const container = document.getElementById('schSlabsContainer');
    if (!container) return;

    const row = document.createElement('div');
    row.className = 'sch-slab-row';
    row.style = 'display: grid; grid-template-columns: 1fr 1fr 1fr 2fr 36px; gap: 8px; align-items: center; background: #FFFFFF; padding: 8px 10px; border: 1px solid #E2E8F0; border-radius: 6px;';
    row.innerHTML = `
        <div>
            <label style="display:block; font-size:9.5px; font-weight:700; color:#64748B; text-transform:uppercase;">Min Score</label>
            <input type="number" step="0.1" class="brand-input sch-slab-min" value="${min !== '' ? min : ''}" placeholder="e.g. 90" style="height:32px; font-size:12px; width:100%; box-sizing:border-box;" />
        </div>
        <div>
            <label style="display:block; font-size:9.5px; font-weight:700; color:#64748B; text-transform:uppercase;">Max Score</label>
            <input type="number" step="0.1" class="brand-input sch-slab-max" value="${max !== '' ? max : ''}" placeholder="e.g. 100" style="height:32px; font-size:12px; width:100%; box-sizing:border-box;" />
        </div>
        <div>
            <label style="display:block; font-size:9.5px; font-weight:700; color:#64748B; text-transform:uppercase;">Waiver %</label>
            <input type="number" step="1" min="0" max="100" class="brand-input sch-slab-waiver" value="${waiver !== '' ? waiver : ''}" placeholder="e.g. 50" style="height:32px; font-size:12px; width:100%; box-sizing:border-box; font-weight:700; color:#047857;" />
        </div>
        <div>
            <label style="display:block; font-size:9.5px; font-weight:700; color:#64748B; text-transform:uppercase;">Bracket Label</label>
            <input type="text" class="brand-input sch-slab-label" value="${escapeHtml(label || '')}" placeholder="e.g. 90%+ Merit Bracket" style="height:32px; font-size:12px; width:100%; box-sizing:border-box;" />
        </div>
        <div style="padding-top:14px; text-align:center;">
            <button type="button" onclick="this.closest('.sch-slab-row').remove()" style="background:none; border:none; color:#DC2626; cursor:pointer; font-size:16px;" title="Remove Bracket">✕</button>
        </div>
    `;
    container.appendChild(row);
}

/**
 * Handle Scholarship Editor Form Submit (Create or Update)
 */
async function handleScholarshipEditorSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();

    const id = document.getElementById('editSchId').value;
    const programId = document.getElementById('sch_program_id').value;
    const title = document.getElementById('sch_title').value.trim();

    if (!programId) {
        showToast('Please select an Academic Program to map this scholarship.', 'warning');
        document.getElementById('sch_program_id').focus();
        return;
    }

    if (!title) {
        showToast('Please enter a Scholarship Name/Title.', 'warning');
        document.getElementById('sch_title').focus();
        return;
    }

    // Collect Slabs
    const slabs = [];
    document.querySelectorAll('.sch-slab-row').forEach(row => {
        const min = row.querySelector('.sch-slab-min')?.value;
        const max = row.querySelector('.sch-slab-max')?.value;
        const waiver = row.querySelector('.sch-slab-waiver')?.value;
        const label = row.querySelector('.sch-slab-label')?.value;
        if (min !== '' && waiver !== '') {
            slabs.push({
                min: parseFloat(min),
                max: max !== '' ? parseFloat(max) : 100,
                waiver_pct: parseFloat(waiver),
                label: (label || '').trim() || `${waiver}% Waiver`
            });
        }
    });

    const payload = {
        program_id: parseInt(programId, 10),
        title: title,
        code: document.getElementById('sch_code').value.trim(),
        description: document.getElementById('sch_description').value.trim(),
        evaluation_metric: document.getElementById('sch_evaluation_metric').value,
        exam_name: document.getElementById('sch_exam_name').value.trim(),
        discount_type: document.getElementById('sch_discount_type').value,
        discount_value: parseFloat(document.getElementById('sch_discount_value').value || 0),
        slabs: slabs,
        eligibility_criteria: document.getElementById('sch_eligibility_criteria').value.trim(),
        terms_conditions: document.getElementById('sch_terms_conditions').value.trim(),
        max_recipients: document.getElementById('sch_max_recipients').value ? parseInt(document.getElementById('sch_max_recipients').value, 10) : null,
        is_active: document.getElementById('sch_is_active').checked ? 1 : 0
    };

    const submitBtn = document.getElementById('schEditorFormSubmitBtn');
    if (submitBtn) submitBtn.disabled = true;

    try {
        const url = id ? `/v1/scholarship-rules/${id}` : '/v1/scholarship-rules';
        const method = id ? 'PUT' : 'POST';

        const res = await fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await res.json();
        if (json.success) {
            showToast(id ? 'Scholarship updated successfully!' : 'Scholarship created successfully!', 'success');
            closeScholarshipEditor();
        } else {
            showToast(json.error || 'Failed to save scholarship', 'error');
        }
    } catch (err) {
        console.error('Error saving scholarship:', err);
        showToast('Network error while saving scholarship', 'error');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

/**
 * Delete a scholarship rule
 */
async function deleteScholarshipRule(id, title) {
    if (!confirm(`Are you sure you want to delete the scholarship "${title}"? This action cannot be undone.`)) {
        return;
    }

    try {
        const res = await fetch(`/v1/scholarship-rules/${id}`, { method: 'DELETE' });
        const json = await res.json();
        if (json.success) {
            showToast('Scholarship deleted successfully', 'success');
            await loadScholarshipRules();
        } else {
            showToast(json.error || 'Failed to delete scholarship', 'error');
        }
    } catch (e) {
        console.error('Error deleting scholarship:', e);
        showToast('Network error while deleting scholarship', 'error');
    }
}

// Auto-attach globally
window.initScholarshipConfiguration = initScholarshipConfiguration;
window.initScholarshipEditor = initScholarshipEditor;
window.setScholarshipFilter = setScholarshipFilter;
window.filterScholarshipsTable = filterScholarshipsTable;
window.openCreateScholarshipPage = openCreateScholarshipPage;
window.openEditScholarshipPage = openEditScholarshipPage;
window.closeScholarshipEditor = closeScholarshipEditor;
window.handleSchProgramChange = handleSchProgramChange;
window.toggleExamNameField = toggleExamNameField;
window.toggleDiscountType = toggleDiscountType;
window.toggleSchActiveBadge = toggleSchActiveBadge;
window.addSchSlabRow = addSchSlabRow;
window.handleScholarshipEditorSubmit = handleScholarshipEditorSubmit;
window.deleteScholarshipRule = deleteScholarshipRule;
