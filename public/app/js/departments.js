// ═══════════════════════════════════════════════════════════════════
// DEPARTMENTS.JS - Department grid, create/edit modal, scholarship rules
// BUG AREAS:
//   Dept grid             -> renderDepartmentsGrid() / loadDepartments()
//   Dept create modal     -> openCreateDeptModal() / handleDeptFormSubmit()
//   Dept edit modal       -> openEditDeptModal() / closeDepartmentModal()
//   Dept emoji picker     -> toggleDeptEmojiPicker() / selectDeptEmoji()
//   Dept working hours    -> getDeptWorkingHoursFromForm() / setDeptWorkingHoursToForm()
//   Dept knowledge (modal)-> addDeptKnowledgeText/File/Url() / deleteDeptKnowledgeSource()
//   Focused card editors  -> openDeptCardEditor() / closeDeptCardEditor()
//   Focused dept KS       -> addFocusedDeptKnowledge*() / renderFocusedDeptKsList()
//   Preset catalog        -> openPresetsModal() / importSelectedPresets()
//   Course scholarships   -> openCourseScholarshipModal() / saveCourseScholarship()
//   Dept subtab switch    -> switchDeptSubtab()
// LOADED BY: index.html via <script src="js/departments.js">
// ═══════════════════════════════════════════════════════════════════
        async function loadScholarships() {
            if (!token) return;
            try {
                // 1. Fetch departments if not loaded so department dropdowns work
                if (!currentDepartments || currentDepartments.length === 0) {
                    try {
                        const deptRes = await fetch('/v1/departments', { headers: { 'Authorization': 'Bearer ' + token } });
                        const deptData = await deptRes.json();
                        if (deptData.status === 'success') {
                            currentDepartments = deptData.data.departments || [];
                        }
                    } catch(e) {}
                }

                // 2. Fetch Scholarship Config & Courses
                const res = await fetch('/v1/scholarships/config', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    currentScholarshipConfig = data.data;
                    const orgConfig = data.data.organization_config || {};
                    currentScholarshipCourses = data.data.courses || [];

                    // Populate Global Settings
                    const isEnabled = orgConfig.enabled !== false && orgConfig.enabled !== 0 && orgConfig.enabled !== '0';
                    const enabledCb = document.getElementById('orgScholarshipEnabled');
                    if (enabledCb) enabledCb.checked = isEnabled;

                    const boosters = orgConfig.booster_quotas || {};
                    const sportsEl = document.getElementById('orgSportsPct');
                    if (sportsEl) sportsEl.value = boosters.sports_pct ?? 5;
                    const girlEl = document.getElementById('orgGirlChildPct');
                    if (girlEl) girlEl.value = boosters.girl_child_pct ?? 5;
                    const defEl = document.getElementById('orgDefensePct');
                    if (defEl) defEl.value = boosters.defense_pct ?? 5;
                    const earlyEl = document.getElementById('orgEarlyBirdPct');
                    if (earlyEl) earlyEl.value = boosters.early_bird_pct ?? 5;

                    // Update Stats
                    const totalCourses = currentScholarshipCourses.length;
                    const activeMerit = currentScholarshipCourses.filter(c => c.has_scholarship == 1 || c.has_scholarship === true).length;
                    const fixedFee = totalCourses - activeMerit;

                    const cCountEl = document.getElementById('statScholarshipCoursesCount');
                    if (cCountEl) cCountEl.innerText = totalCourses;
                    const aCountEl = document.getElementById('statScholarshipActiveCount');
                    if (aCountEl) aCountEl.innerText = activeMerit;
                    const fCountEl = document.getElementById('statScholarshipFixedCount');
                    if (fCountEl) fCountEl.innerText = fixedFee;

                    const engineBadge = document.getElementById('statScholarshipEngineBadge');
                    const engineStatus = document.getElementById('statScholarshipEngineStatus');
                    if (engineBadge && engineStatus) {
                        if (isEnabled) {
                            engineBadge.innerText = 'Active';
                            engineBadge.style.color = 'var(--brand-emerald-400)';
                            engineStatus.innerText = 'Enabled';
                        } else {
                            engineBadge.innerText = 'Off';
                            engineBadge.style.color = 'var(--brand-amber-400)';
                            engineStatus.innerText = 'Disabled';
                        }
                    }

                    renderCourseScholarshipsTable();
                }
            } catch (err) {
                console.error('Error loading scholarships:', err);
            }
        }

        function renderCourseScholarshipsTable() {
            const tbody = document.getElementById('courseScholarshipsTableBody');
            if (!tbody) return;

            if (currentScholarshipCourses.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" style="text-align: center; color: #648781; padding: 36px;">
                            <div style="font-size: 28px; margin-bottom: 8px;">ðŸ’°</div>
                            <strong style="font-size: 13px; color: #092F2E; display: block;">No course scholarship rules configured</strong>
                            <span style="font-size: 12px; color: #648781;">Add undergraduate, postgraduate, or diploma programs to specify merit slabs and lead hooks.</span>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = currentScholarshipCourses.map(course => {
                const hasSch = (course.has_scholarship == 1 || course.has_scholarship === true);
                const deptName = course.department_name ? `ðŸ¢ ${course.department_name}` : 'ðŸ›ï¸ All / General';
                const degreeLabel = (course.degree_level || 'undergraduate').toUpperCase();

                const statusBadge = hasSch
                    ? `<span class="badge" style="background: rgba(52, 211, 153, 0.15); color: var(--brand-emerald-400); border: 1px solid rgba(52, 211, 153, 0.3); font-size: 10px; font-weight: 700; white-space: nowrap;">â— Merit Eligible</span>`
                    : `<span class="badge" style="background: rgba(56, 189, 248, 0.12); color: var(--brand-cyan-400); border: 1px solid rgba(56, 189, 248, 0.25); font-size: 10px; font-weight: 600; white-space: nowrap;">Fixed Fee Structure</span>`;

                let metricLabel = '12th Marks (%)';
                if (course.evaluation_metric === 'graduation_cgpa') metricLabel = 'Graduation CGPA';
                else if (course.evaluation_metric === 'entrance_exam') metricLabel = `Exam: ${course.exam_name || 'Entrance'}`;
                else if (course.evaluation_metric === 'merit_rank') metricLabel = 'State / Merit Rank';

                let slabsHtml = '';
                if (hasSch) {
                    const slabs = Array.isArray(course.slabs) ? course.slabs : [];
                    const slabsSummary = slabs.map(s => {
                        const minVal = s.min !== undefined ? s.min : (s.min_score !== undefined ? s.min_score : 0);
                        const maxVal = s.max !== undefined ? s.max : (s.max_score !== undefined ? s.max_score : 100);
                        const waiverVal = s.waiver_pct !== undefined ? s.waiver_pct : (s.waiver_percentage !== undefined ? s.waiver_percentage : 0);
                        return `<span class="badge" style="background: var(--brand-surface-200); font-size: 9px; margin-right: 4px; margin-bottom: 2px; display: inline-block;">${minVal}-${maxVal}: <strong>${waiverVal}%</strong></span>`;
                    }).join('');
                    const feeStr = course.annual_tuition_fee ? `â‚¹${Number(course.annual_tuition_fee).toLocaleString()} / yr` : 'Fee on Request';
                    slabsHtml = `<div><div style="font-size: 11px; font-weight: 600; color: #092F2E; margin-bottom: 2px;">${feeStr}</div><div>${slabsSummary || '<span style="font-size: 10px; color: #648781;">No slabs</span>'}</div></div>`;
                } else {
                    slabsHtml = `<div style="font-size: 11px; color: #648781; font-style: italic;">${course.no_scholarship_reason || 'Standard tuition fee â€¢ 0% EMI available'}</div>`;
                }

                return `
                    <tr>
                        <td style="font-weight: 600; color: #092F2E;">
                            <div>${course.course_name}</div>
                            ${course.course_code ? `<span style="font-size: 10px; color: #648781; font-family: var(--brand-font-mono);">${course.course_code}</span>` : ''}
                        </td>
                        <td><span class="badge" style="background: rgba(99, 102, 241, 0.12); color: var(--brand-indigo-300); font-size: 10px; font-weight: 600;">${degreeLabel}</span></td>
                        <td style="font-size: 12px; color: #4F7470;">${deptName}</td>
                        <td>${statusBadge}</td>
                        <td style="font-size: 11px; color: #4F7470;">${hasSch ? metricLabel : 'â€”'}</td>
                        <td>${slabsHtml}</td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="brand-btn-secondary brand-btn-sm" style="font-size: 11px; height: 28px; padding: 0 10px; margin-right: 4px;" onclick="openCourseScholarshipModal(${course.id})">
                                âœï¸ Edit
                            </button>
                            <button class="brand-btn-secondary brand-btn-sm" style="font-size: 11px; height: 28px; padding: 0 10px; color: var(--brand-rose-400);" onclick="deleteCourseScholarship(${course.id})">
                                ðŸ—‘ï¸
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function saveOrgScholarshipConfig(e) {
            if (e) e.preventDefault();
            const enabled = document.getElementById('orgScholarshipEnabled').checked;
            const sports_pct = parseFloat(document.getElementById('orgSportsPct').value) || 0;
            const girl_child_pct = parseFloat(document.getElementById('orgGirlChildPct').value) || 0;
            const defense_pct = parseFloat(document.getElementById('orgDefensePct').value) || 0;
            const early_bird_pct = parseFloat(document.getElementById('orgEarlyBirdPct').value) || 0;

            const payload = {
                enabled: enabled,
                booster_quotas: {
                    sports_pct,
                    girl_child_pct,
                    defense_pct,
                    early_bird_pct
                }
            };

            try {
                const res = await fetch('/v1/scholarships/config', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('ðŸŽ‰ Global scholarship policies and booster percentages saved successfully!');
                    await loadScholarships();
                } else {
                    alert(data.message || 'Failed to save scholarship configuration.');
                }
            } catch (err) {
                console.error(err);
                alert('Connection error saving scholarship settings.');
            }
        }

        function toggleCourseSchFormFields() {
            const hasSch = document.getElementById('courseSchHasScholarship').checked;
            const reasonGroup = document.getElementById('courseSchNoReasonGroup');
            const criteriaGroup = document.getElementById('courseSchCriteriaGroup');
            if (reasonGroup) reasonGroup.style.display = hasSch ? 'none' : 'block';
            if (criteriaGroup) criteriaGroup.style.display = hasSch ? 'flex' : 'none';
        }

        function toggleCourseSchMetricFields() {
            const metric = document.getElementById('courseSchMetric').value;
            const examGroup = document.getElementById('courseSchExamGroup');
            if (examGroup) {
                examGroup.style.display = (metric === 'entrance_exam') ? 'block' : 'none';
            }
        }

        function addCourseSchSlabRow(min = 85, max = 100, waiver = 50, label = 'Merit Waiver') {
            const container = document.getElementById('courseSchSlabsContainer');
            if (!container) return;
            const div = document.createElement('div');
            div.className = 'slab-row';
            div.style.cssText = 'display: grid; grid-template-columns: 80px 80px 90px 1fr 32px; gap: 8px; align-items: center;';
            div.innerHTML = `
                <div>
                    <span style="font-size: 9px; color: #648781; display: block;">Min Score</span>
                    <input type="number" class="brand-input slab-min" value="${min}" step="0.1" />
                </div>
                <div>
                    <span style="font-size: 9px; color: #648781; display: block;">Max Score</span>
                    <input type="number" class="brand-input slab-max" value="${max}" step="0.1" />
                </div>
                <div>
                    <span style="font-size: 9px; color: #648781; display: block;">Waiver (%)</span>
                    <input type="number" class="brand-input slab-waiver" value="${waiver}" min="1" max="100" />
                </div>
                <div>
                    <span style="font-size: 9px; color: #648781; display: block;">Tier Label</span>
                    <input type="text" class="brand-input slab-label" value="${label}" placeholder="e.g. 50% President Waiver" />
                </div>
                <button type="button" class="brand-btn-secondary brand-btn-sm" style="margin-top: 14px; height: 32px; color: var(--brand-rose-400);" onclick="this.parentElement.remove()">âœ•</button>
            `;
            container.appendChild(div);
        }

        function openCourseScholarshipModal(courseId = null) {
            const modal = document.getElementById('courseScholarshipModal');
            if (!modal) return;

            // Populate Department dropdown
            const deptSelect = document.getElementById('courseSchDeptSelect');
            if (deptSelect) {
                deptSelect.innerHTML = `<option value="">ðŸ›ï¸ All / General Institutional</option>` + 
                    currentDepartments.map(d => `<option value="${d.id}">${d.icon || 'ðŸ¢'} ${d.name}</option>`).join('');
            }

            const titleEl = document.getElementById('courseSchModalTitle');
            const editIdEl = document.getElementById('editCourseSchId');
            const nameEl = document.getElementById('courseSchName');
            const codeEl = document.getElementById('courseSchCode');
            const degreeEl = document.getElementById('courseSchDegreeLevel');
            const hasSchEl = document.getElementById('courseSchHasScholarship');
            const noReasonEl = document.getElementById('courseSchNoReason');
            const metricEl = document.getElementById('courseSchMetric');
            const examEl = document.getElementById('courseSchExamName');
            const feeEl = document.getElementById('courseSchAnnualFee');
            const slabsContainer = document.getElementById('courseSchSlabsContainer');

            slabsContainer.innerHTML = '';

            if (courseId) {
                const course = currentScholarshipCourses.find(c => c.id == courseId);
                if (!course) return;
                titleEl.innerText = 'Edit Course Scholarship Rule';
                editIdEl.value = course.id;
                nameEl.value = course.course_name || '';
                codeEl.value = course.course_code || '';
                if (deptSelect) deptSelect.value = course.department_id || '';
                degreeEl.value = course.degree_level || 'undergraduate';
                hasSchEl.checked = (course.has_scholarship == 1 || course.has_scholarship === true);
                noReasonEl.value = course.no_scholarship_reason || '';
                metricEl.value = course.evaluation_metric || 'percentage_12th';
                examEl.value = course.exam_name || '';
                feeEl.value = course.annual_tuition_fee || '';

                const slabs = Array.isArray(course.slabs) ? course.slabs : [];
                if (slabs.length > 0) {
                    slabs.forEach(s => {
                        const minVal = s.min !== undefined ? s.min : (s.min_score !== undefined ? s.min_score : 85);
                        const maxVal = s.max !== undefined ? s.max : (s.max_score !== undefined ? s.max_score : 100);
                        const waiverVal = s.waiver_pct !== undefined ? s.waiver_pct : (s.waiver_percentage !== undefined ? s.waiver_percentage : (s.waiver !== undefined ? s.waiver : 50));
                        const labelVal = s.label || s.tier_label || `${waiverVal}% Merit Waiver`;
                        addCourseSchSlabRow(minVal, maxVal, waiverVal, labelVal);
                    });
                } else {
                    addCourseSchSlabRow(95, 100, 100, '100% Chancellor Scholarship');
                    addCourseSchSlabRow(85, 94.9, 50, '50% Dean Merit Scholarship');
                }
            } else {
                titleEl.innerText = 'Add Course Scholarship Rule';
                editIdEl.value = '';
                nameEl.value = '';
                codeEl.value = '';
                if (deptSelect) deptSelect.value = '';
                degreeEl.value = 'undergraduate';
                hasSchEl.checked = true;
                noReasonEl.value = 'Fixed subsidized fee structure with 0% interest EMI options.';
                metricEl.value = 'percentage_12th';
                examEl.value = '';
                feeEl.value = '180000';

                addCourseSchSlabRow(95, 100, 100, '100% Chancellor Scholarship');
                addCourseSchSlabRow(85, 94.9, 50, '50% Dean Merit Scholarship');
                addCourseSchSlabRow(75, 84.9, 25, '25% Academic Excellence Waiver');
            }

            toggleCourseSchFormFields();
            toggleCourseSchMetricFields();
            modal.style.display = 'flex';
        }

        function closeCourseScholarshipModal() {
            const modal = document.getElementById('courseScholarshipModal');
            if (modal) modal.style.display = 'none';
        }

        async function saveCourseScholarship(e) {
            if (e) e.preventDefault();
            const id = document.getElementById('editCourseSchId').value;
            const course_name = document.getElementById('courseSchName').value.trim();
            const course_code = document.getElementById('courseSchCode').value.trim();
            const deptVal = document.getElementById('courseSchDeptSelect').value;
            const department_id = deptVal ? parseInt(deptVal) : null;
            const degree_level = document.getElementById('courseSchDegreeLevel').value;
            const has_scholarship = document.getElementById('courseSchHasScholarship').checked ? 1 : 0;
            const no_scholarship_reason = document.getElementById('courseSchNoReason').value.trim();
            const evaluation_metric = document.getElementById('courseSchMetric').value;
            const exam_name = document.getElementById('courseSchExamName').value.trim();
            const annual_tuition_fee = parseFloat(document.getElementById('courseSchAnnualFee').value) || 0;

            const slabs = [];
            document.querySelectorAll('.slab-row').forEach(row => {
                const min = parseFloat(row.querySelector('.slab-min').value);
                const max = parseFloat(row.querySelector('.slab-max').value);
                const waiver = parseFloat(row.querySelector('.slab-waiver').value);
                const label = row.querySelector('.slab-label').value.trim();
                if (!isNaN(min) && !isNaN(max) && !isNaN(waiver)) {
                    slabs.push({
                        min: min,
                        max: max,
                        waiver_pct: waiver,
                        label: label || `${waiver}% Waiver`
                    });
                }
            });

            const payload = {
                id: id ? parseInt(id) : null,
                course_name,
                course_code,
                department_id,
                degree_level,
                has_scholarship,
                no_scholarship_reason,
                evaluation_metric,
                exam_name,
                annual_tuition_fee,
                currency: 'INR',
                slabs,
                is_active: 1
            };

            try {
                const res = await fetch('/v1/scholarships/courses', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeCourseScholarshipModal();
                    await loadScholarships();
                } else {
                    alert(data.message || 'Failed to save course scholarship rule.');
                }
            } catch (err) {
                console.error(err);
                alert('Connection error saving course rule.');
            }
        }

        async function deleteCourseScholarship(id) {
            if (!confirm('Are you sure you want to delete this course scholarship rule?')) return;
            try {
                const res = await fetch('/v1/scholarships/courses/' + id, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    await loadScholarships();
                } else {
                    alert(data.message || 'Failed to delete course rule.');
                }
            } catch (err) {
                console.error(err);
                alert('Error deleting course rule.');
            }
        }

        // â”€â”€ DEPARTMENT MANAGEMENT JS LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let currentDepartments = [];
        let availableOrgStaff = [];
        let availableOrgKs = [];
        let availableOrgCampuses = [];

        async function loadDepartments() {
            const isFullAdmin = currentUser && (
                currentUser.role === 'owner' ||
                currentUser.role === 'superadmin' ||
                currentUser.role === 'super_admin' ||
                (currentUser.role === 'admin' && currentUser.can_manage_structure == 1)
            );
            const actions = document.getElementById('deptHeaderActions');
            if (actions) {
                actions.style.display = isFullAdmin ? 'flex' : 'none';
            }

            try {
                const res = await fetch('/v1/departments', { headers: { 'Authorization': 'Bearer ' + token } });
                const data = await res.json();
                if (data.status === 'success') {
                    const depts = data.data.departments || (Array.isArray(data.data) ? data.data : []);
                    currentDepartments = depts;
                    availableOrgStaff = data.data.available_staff || [];
                    availableOrgKs = data.data.available_knowledge_sources || [];
                    availableOrgCampuses = data.data.available_campuses || [];

                    updateDeptStats();
                    renderDepartmentsGrid();
                    if (typeof updateKnowledgeDeptDropdown === 'function') {
                        updateKnowledgeDeptDropdown();
                    }
                }
            } catch (err) {
                console.error('Failed to load departments:', err);
            }
        }

        function updateDeptStats() {
            if (!currentDepartments) return;
            const activeDepts = currentDepartments.filter(d => d.is_active == 1);
            let staffCount = 0;
            let ksCount = 0;
            let escCount = 0;

            currentDepartments.forEach(d => {
                if (d.staff) staffCount += d.staff.length;
                if (d.knowledge_sources) ksCount += d.knowledge_sources.length;
                if (d.escalation_rules && (d.escalation_rules.notify_email || d.escalation_rules.notify_whatsapp)) escCount++;
            });

            const statDepts = document.getElementById('statDeptsCount') || document.getElementById('statActiveDeptsCount');
            if (statDepts) statDepts.innerText = activeDepts.length;
            const statStaff = document.getElementById('statStaffCount') || document.getElementById('statAssignedStaffCount');
            if (statStaff) statStaff.innerText = staffCount;
            const statKs = document.getElementById('statDeptKsCount') || document.getElementById('statScopedDocsCount');
            if (statKs) statKs.innerText = ksCount;
            const statEsc = document.getElementById('statEscalationsCount') || document.getElementById('statAutoEscalationCount');
            if (statEsc) statEsc.innerText = escCount;
        }

        function renderDepartmentsGrid() {
            const container = document.getElementById('departmentsGrid') || document.getElementById('landingDeptCardsGrid');
            if (!container) return;

            if (typeof loadRealDashboardDepartments === 'function') {
                loadRealDashboardDepartments();
                return;
            }

            if (!currentDepartments || currentDepartments.length === 0) {
                container.innerHTML = `
                    <div class="dept-card-create" onclick="openCreateDeptModal()" style="grid-column: 1 / -1; min-height: 220px;">
                        <div class="dept-create-icon">+</div>
                        <h4 class="dept-card-create-title">No Departments Configured Yet</h4>
                        <p class="dept-card-create-desc">Launch instant admissions routing, knowledge base scoping, and escalation rules with our pre-built college department templates.</p>
                        <span class="dept-btn-secondary" style="font-size: 11.5px; padding: 5px 12px;">+ Custom Department</span>
                    </div>
                `;
                return;
            }

            var cardsHtml = currentDepartments.map(function(d, index) {
                var isPreset = (d.is_preset == 1);
                var isActive = (d.is_active == 1);
                var hasWidget = (d.enable_dedicated_widget != 0 && d.enable_dedicated_widget !== false);
                var key = d.slug || ('dept_' + d.id);

                var staffCount = (d.staff || []).length;
                var coursesCount = (d.courses && d.courses.length) ? d.courses.length : 0;
                var ksCount = (d.knowledge_sources || []).length;
                var faqsCount = (d.faqs || []).length;
                var leadsCount = (d.leads_count !== undefined && d.leads_count !== null) ? Number(d.leads_count) : 0;

                var statusBadgeHtml = isActive
                    ? (hasWidget
                        ? '<span class="dept-status-pill dept-status-active"><span class="w-1.5 h-1.5 rounded-full bg-[#10B981]"></span> Active &bull; Widget</span>'
                        : '<span class="dept-status-pill dept-status-main"><span class="w-1.5 h-1.5 rounded-full bg-[#0284C7]"></span> Active &bull; Main Bot</span>')
                    : '<span class="dept-status-pill" style="background:#FFFBEB; color:#B45309; border:1px solid #FDE68A;"><span class="w-1.5 h-1.5 rounded-full bg-[#F59E0B]"></span> Inactive</span>';

                var category = 'admissions';
                var lowerName = (d.name || '').toLowerCase();
                if (lowerName.indexOf('fee') !== -1 || lowerName.indexOf('aid') !== -1 || lowerName.indexOf('finance') !== -1 || lowerName.indexOf('bursar') !== -1 || lowerName.indexOf('scholarship') !== -1) category = 'finance';
                else if (lowerName.indexOf('tour') !== -1 || lowerName.indexOf('housing') !== -1 || lowerName.indexOf('affair') !== -1 || lowerName.indexOf('life') !== -1 || lowerName.indexOf('dean') !== -1 || lowerName.indexOf('compliance') !== -1) category = 'life';

                return `
                    <div class="dept-card-item" id="card-dept-${key}" onclick="typeof openDeptDeepDive === 'function' ? openDeptDeepDive('${key}') : (typeof openEditDeptModal === 'function' ? openEditDeptModal(${d.id}) : null)" data-category="${category}">
                        <div>
                            <div class="dept-card-header">
                                <div class="flex items-center gap-3">
                                    <div class="dept-icon-box">${d.icon || 'ðŸ«'}</div>
                                    <div>
                                        <h3 class="dept-card-title">${d.name}</h3>
                                        <span class="dept-template-tag">${isPreset ? 'Pre-Built Template' : 'Custom Department'}</span>
                                    </div>
                                </div>
                                ${statusBadgeHtml}
                            </div>
                            <p class="dept-card-desc">
                                ${d.description || 'Primary intake desk for admissions, cutoff policies, fee structures, and counselor routing.'}
                            </p>
                            <div class="dept-channels-row">
                                ${d.email ? `<span class="dept-channel-badge">ðŸ“§ ${d.email}</span>` : ''}
                                ${d.phone ? `<span class="dept-channel-badge">ðŸ“ž ${d.phone}</span>` : ''}
                                ${d.whatsapp ? `<span class="dept-channel-badge">ðŸ’¬ WA Connected</span>` : ''}
                            </div>
                        </div>

                        <div>
                            <div class="dept-card-stats">
                                <div class="dept-stat-unit">
                                    <span class="dept-stat-val">${coursesCount}</span>
                                    <span class="dept-stat-lbl">ðŸŽ“ Programs</span>
                                </div>
                                <div class="dept-stat-unit">
                                    <span class="dept-stat-val">${staffCount}</span>
                                    <span class="dept-stat-lbl">ðŸ‘¥ Staff</span>
                                </div>
                                <div class="dept-stat-unit">
                                    <span class="dept-stat-val">${ksCount}</span>
                                    <span class="dept-stat-lbl">ðŸ“š Docs</span>
                                </div>
                                <div class="dept-stat-unit">
                                    <span class="dept-stat-val">${faqsCount}</span>
                                    <span class="dept-stat-lbl">â“ FAQs</span>
                                </div>
                                <div class="dept-stat-unit">
                                    <span class="dept-stat-val">${leadsCount}</span>
                                    <span class="dept-stat-lbl">ðŸ“ˆ Leads</span>
                                </div>
                            </div>
                            <div class="dept-card-footer">
                                <span class="dept-card-action-text">
                                    <span>Click to open department console &rarr;</span>
                                </span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            cardsHtml += `
                <div class="dept-card-create" onclick="openCreateDeptModal()">
                    <div class="dept-create-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    </div>
                    <h4 class="dept-card-create-title">+ Custom Department</h4>
                    <p class="dept-card-create-desc">Define custom admissions sub-division, upload specific program documents, and assign staff counselors.</p>
                    <span class="dept-btn-secondary" style="font-size: 11.5px; padding: 5px 12px;">Launch Setup &rarr;</span>
                </div>
            `;

            container.innerHTML = cardsHtml;
        }

        // Toggle Department Active Status
        async function toggleDeptStatus(deptId, isActive) {
            try {
                await fetch('/v1/departments/' + deptId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ is_active: isActive ? 1 : 0 })
                });
                loadDepartments();
            } catch (err) {
                console.error(err);
            }
        }

        // Delete Department
        async function deleteDept(deptId) {
            if (!confirm('Are you sure you want to delete this department?')) return;
            try {
                await fetch('/v1/departments/' + deptId, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                loadDepartments();
            } catch (err) {
                console.error(err);
            }
        }

        // Open Presets Catalog Modal
        async function openPresetsModal() {
            try {
                const res = await fetch('/v1/departments/presets', { headers: { 'Authorization': 'Bearer ' + token } });
                const data = await res.json();
                if (data.status === 'success') {
                    const presets = data.data.presets || [];
                    const existingSlugs = currentDepartments.map(d => d.slug);

                    const grid = document.getElementById('presetsGrid');
                    grid.innerHTML = presets.map(p => {
                        const importedDept = currentDepartments.find(d => d.slug === p.slug);
                        const isImported = !!importedDept;
                        const isActive = importedDept ? (importedDept.is_active == 1) : false;

                        return `
                            <div class="preset-card-item" style="background: #F1F7F4; border: 1px solid ${isImported ? (isActive ? 'rgba(52, 211, 153, 0.3)' : 'rgba(251, 191, 36, 0.3)') : 'var(--brand-border-subtle)'}; border-radius: var(--brand-radius-sm); padding: 14px; position: relative; cursor: ${isImported ? 'default' : 'pointer'};" onclick="togglePresetCardClick(event, this)">
                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 6px;">
                                    <div style="display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0;">
                                        <span style="font-size: 20px; flex-shrink: 0;">${p.icon}</span>
                                        <strong style="font-size: 14px; color: #092F2E; line-height: 1.3;">${p.name}</strong>
                                    </div>
                                    ${isImported ? 
                                        (isActive ? 
                                            '<span style="font-size: 11px; font-weight: 600; color: var(--brand-emerald-400); background: rgba(52, 211, 153, 0.12); padding: 3px 8px; border-radius: 12px; border: 1px solid rgba(52, 211, 153, 0.3); white-space: nowrap; flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px;">â— Active</span>' 
                                            : 
                                            '<span style="font-size: 11px; font-weight: 600; color: var(--brand-amber-400); background: rgba(251, 191, 36, 0.12); padding: 3px 8px; border-radius: 12px; border: 1px solid rgba(251, 191, 36, 0.3); white-space: nowrap; flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px;">â—‹ Inactive â€¢ Setup Required</span>')
                                        : 
                                        `<input type="radio" name="preset_selection" class="preset-radio" value="${p.slug}" style="flex-shrink: 0; margin-top: 2px; cursor: pointer; accent-color: var(--brand-indigo-500); width: 16px; height: 16px;" onclick="event.stopPropagation()" />`
                                    }
                                </div>
                                <p style="font-size: 13px; color: #648781; line-height: 1.45; margin-bottom: 8px;">
                                    ${p.description}
                                </p>
                                <div style="font-size: 13px; color: var(--brand-indigo-400); background: rgba(99, 102, 241, 0.1); padding: 6px 10px; border-radius: 4px; line-height: 1.35;">
                                    "${p.greeting_message}"
                                </div>
                            </div>
                        `;
                    }).join('');

                    document.getElementById('presetsModal').style.display = 'flex';
                }
            } catch (err) {
                console.error(err);
            }
        }

        function togglePresetCardClick(event, cardEl) {
            if (event.target.tagName === 'INPUT' || event.target.tagName === 'LABEL') return;
            const radio = cardEl.querySelector('.preset-radio');
            if (radio && !radio.disabled) {
                radio.checked = true;
                document.querySelectorAll('.preset-card-item').forEach(c => c.style.borderColor = 'var(--brand-border-subtle)');
                cardEl.style.borderColor = 'var(--brand-indigo-500)';
            }
        }

        function closePresetsModal() {
            document.getElementById('presetsModal').style.display = 'none';
        }

        async function importSelectedPresets() {
            const selectedRadio = document.querySelector('.preset-radio:checked');
            if (!selectedRadio) {
                alert('Please select a preset department card to import.');
                return;
            }

            const selectedSlug = selectedRadio.value;

            try {
                const res = await fetch('/v1/departments/preset-import', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ selected_slugs: [selectedSlug] })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const newDeptId = data.data.department_id;
                    closePresetsModal();
                    await loadDepartments();

                    // Immediately open newly imported department in full page edit mode
                    if (newDeptId) {
                        openEditDeptModal(newDeptId);
                    }
                } else {
                    alert(data.message || 'Import failed');
                }
            } catch (err) {
                console.error(err);
                alert('Failed to import preset department');
            }
        }

        function updateEditorStatusDisplay(isActive) {
            const badge = document.getElementById('editorStatusBadge');
            const title = document.getElementById('editorStatusBoxTitle');
            const subtitle = document.getElementById('editorStatusBoxSubtitle');
            const box = document.getElementById('editorStatusBox');

            if (isActive) {
                if (badge) {
                    badge.innerText = 'â— Active';
                    badge.style.cssText = 'background: rgba(52, 211, 153, 0.12); color: var(--brand-emerald-400); border: 1px solid rgba(52, 211, 153, 0.3); font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 12px;';
                }
                if (title) title.innerHTML = 'âœ… Status: Active';
                if (subtitle) subtitle.innerHTML = 'Department knowledge and inquiries are active and live for student interactions. Enable to display an interactive department selection pill and starter FAQ chips directly inside the chatbot widget for student self-routing.';
                if (box) {
                    box.style.background = 'rgba(52, 211, 153, 0.08)';
                    box.style.borderColor = 'rgba(52, 211, 153, 0.3)';
                }
            } else {
                if (badge) {
                    badge.innerText = 'â—‹ Inactive â€¢ Setup Required';
                    badge.style.cssText = 'background: rgba(251, 191, 36, 0.12); color: var(--brand-amber-400); border: 1px solid rgba(251, 191, 36, 0.3); font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 12px;';
                }
                if (title) title.innerHTML = 'â³ Status: Inactive (Setup Required)';
                if (subtitle) subtitle.innerHTML = 'Enable to display an interactive department selection pill and starter FAQ chips directly inside the chatbot widget for student self-routing.';
                if (box) {
                    box.style.background = 'rgba(251, 191, 36, 0.08)';
                    box.style.borderColor = 'rgba(251, 191, 36, 0.25)';
                }
            }
        }

        // Department Icon Emoji Picker Engine with Contextual Tooltips (5 Rows x 8 Columns = 40 Emojis)
        const DEPARTMENT_EMOJIS = [
            // Row 1: Academic Disciplines & Core Schools (8)
            { emoji: 'ðŸ’¼', name: 'School of Business & Management' },
            { emoji: 'âš™ï¸', name: 'School of Engineering & Technology' },
            { emoji: 'ðŸ©º', name: 'School of Health Sciences, Medicine & Nursing' },
            { emoji: 'âš›ï¸', name: 'School of Physical Sciences & Physics' },
            { emoji: 'ðŸ§¬', name: 'School of Biological & Life Sciences' },
            { emoji: 'ðŸ“', name: 'School of Mathematics & Statistics' },
            { emoji: 'ðŸ“œ', name: 'School of Humanities, History & Literature' },
            { emoji: 'ðŸ§ ', name: 'School of Social Sciences & Psychology' },

            // Row 2: Applied Disciplines & Professional Schools (8)
            { emoji: 'ðŸ“–', name: 'School of Education & Teacher Training' },
            { emoji: 'ðŸ’»', name: 'Computer Science, AI & Information Tech' },
            { emoji: 'âš–ï¸', name: 'School of Law, Legal Studies & Policy' },
            { emoji: 'ðŸŽ¨', name: 'School of Design, Architecture & Fine Arts' },
            { emoji: 'ðŸ§ª', name: 'Chemistry, Pharmacy & Bio-Chemical Sciences' },
            { emoji: 'ðŸŽ­', name: 'Performing Arts, Theatre & Music' },
            { emoji: 'ðŸ“°', name: 'Journalism, Media & Mass Communication' },
            { emoji: 'ðŸŒ¿', name: 'Agriculture, Forestry & Environmental Studies' },

            // Row 3: Central Administration & Governance (8)
            { emoji: 'ðŸ«', name: 'Main Campus & Central Administration' },
            { emoji: 'ðŸŽ“', name: 'Admissions, Registrations & Degrees' },
            { emoji: 'ðŸ“š', name: 'University Library & Information Center' },
            { emoji: 'ðŸ’°', name: 'Finance, Accounts & Fee Management' },
            { emoji: 'ðŸ“', name: 'Examinations, Controller of Exams & Records' },
            { emoji: 'ðŸ¢', name: 'Deans Office & Academic Governance' },
            { emoji: 'ðŸ›¡ï¸', name: 'Campus Safety, Security & Vigilance' },
            { emoji: 'ðŸŒ', name: 'International Affairs & Global Exchange' },

            // Row 4: Campus Life, Facilities & Services (8)
            { emoji: 'ðŸ›ï¸', name: 'Campus Residence, Student Housing & Hostels' },
            { emoji: 'ðŸ½ï¸', name: 'Cafeteria, Dining Halls & Campus Mess' },
            { emoji: 'ðŸšŒ', name: 'Transportation, Bus Routes & Campus Shuttle' },
            { emoji: 'âš½', name: 'Sports, Physical Education & Athletics' },
            { emoji: 'ðŸ¥', name: 'Campus Hospital, Clinic & Health Center' },
            { emoji: 'ðŸ†', name: 'Scholarships, Financial Aid & Fellowships' },
            { emoji: 'ðŸ“', name: 'Campus Tours & Visitor Welcome Center' },
            { emoji: 'ðŸŒ', name: 'Global Studies & Foreign Language Center' },

            // Row 5: Student Support, Outreach & Career Launch (8)
            { emoji: 'ðŸ“ž', name: 'Student Support, Helpdesk & Inquiries' },
            { emoji: 'ðŸ¤', name: 'Student Welfare, Counseling & Affairs' },
            { emoji: 'ðŸŽ¯', name: 'Admissions Outreach & Candidate Recruitment' },
            { emoji: 'ðŸ“¢', name: 'Public Relations, News & Campus Events' },
            { emoji: 'ðŸ’¡', name: 'Innovation, Incubation & Entrepreneurship Cell' },
            { emoji: 'ðŸš€', name: 'Corporate Relations & Career Placements' },
            { emoji: 'ðŸ‘¥', name: 'Alumni Association & Community Affairs' },
            { emoji: 'ðŸ”¬', name: 'Advanced Research & Central Instrumentation Lab' }
        ];

        function initDeptEmojiPicker() {
            const grid = document.getElementById('deptEmojiGrid');
            if (!grid) return;
            grid.innerHTML = DEPARTMENT_EMOJIS.map(item => `
                <button type="button" class="dept-emoji-btn" title="${item.emoji} â€” ${item.name}" onclick="selectDeptEmoji('${item.emoji}')" onmouseenter="showDeptEmojiHover('${item.emoji}', '${item.name.replace(/'/g, "\\'")}')" onmouseleave="clearDeptEmojiHover()" style="font-size: 20px; height: 36px; display: flex; align-items: center; justify-content: center; background: #F1F7F4; border: 1px solid #DDE9E3; border-radius: 6px; cursor: pointer; transition: all 0.15s; outline: none; position: relative;" onmouseover="this.style.background='var(--brand-surface-200)'; this.style.borderColor='var(--brand-indigo-500)'; this.style.transform='scale(1.15)';" onmouseout="this.style.background='var(--brand-bg-900)'; this.style.borderColor='var(--brand-border-subtle)'; this.style.transform='scale(1)';">
                    ${item.emoji}
                </button>
            `).join('');
        }

        function showDeptEmojiHover(emoji, name) {
            const label = document.getElementById('deptEmojiHoverLabel');
            if (label) label.innerHTML = `<span style="font-size: 14px; margin-right: 4px;">${emoji}</span> <span style="color: #092F2E; font-weight: 600;">${name}</span>`;
        }

        function clearDeptEmojiHover() {
            const label = document.getElementById('deptEmojiHoverLabel');
            if (label) label.innerHTML = `<span style="color: #648781;">Hover an emoji to see suggested department</span>`;
        }

        function toggleDeptEmojiPicker(event) {
            if (event) event.stopPropagation();
            const popover = document.getElementById('deptEmojiPickerPopover');
            if (!popover) return;
            const isOpen = popover.style.display === 'block';
            if (isOpen) {
                popover.style.display = 'none';
            } else {
                initDeptEmojiPicker();
                clearDeptEmojiHover();
                popover.style.display = 'block';
                const customInput = document.getElementById('customEmojiInput');
                if (customInput) customInput.value = '';
            }
        }

        function closeDeptEmojiPicker() {
            const popover = document.getElementById('deptEmojiPickerPopover');
            if (popover) popover.style.display = 'none';
        }

        function selectDeptEmoji(emoji) {
            if (!emoji) return;
            const iconInput = document.getElementById('deptIconInput');
            if (iconInput) iconInput.value = emoji;
            const preview = document.getElementById('deptIconPreview');
            if (preview) preview.innerText = emoji;
            const topHeaderIcon = document.getElementById('modalDeptIcon');
            if (topHeaderIcon) topHeaderIcon.innerText = emoji;
            closeDeptEmojiPicker();
        }

        function onCustomEmojiEntered(val) {
            if (val && val.trim()) {
                selectDeptEmoji(val.trim());
            }
        }

        // Chatbot Menu Greeting Suggestion Templates
        const GREETING_PRESETS = {
            1: "Hello! I can help you with eligibility, course details, fees, and application deadlines. What would you like to explore?",
            2: "Welcome! Ask me anything about our curriculum, faculty, research labs, campus facilities, or how to get started.",
            3: "Hi there! I am your virtual department guide. Feel free to ask questions or request a callback from our counseling team."
        };

        function applyGreetingPreset(optionNum) {
            const input = document.getElementById('deptGreetingInput');
            if (!input) return;
            const deptName = (document.getElementById('deptNameInput')?.value || '').trim();
            let text = GREETING_PRESETS[optionNum] || '';
            
            if (deptName) {
                if (optionNum === 1) {
                    text = `Hello! Welcome to ${deptName}. I can help you with eligibility, course details, fees, and application deadlines. What would you like to explore?`;
                } else if (optionNum === 2) {
                    text = `Welcome to the ${deptName}! Ask me anything about our curriculum, faculty, research labs, campus facilities, or how to get started.`;
                } else if (optionNum === 3) {
                    text = `Hi there! I am your virtual guide for ${deptName}. Feel free to ask questions or request a callback from our counseling team.`;
                }
            }

            input.value = text;
            input.focus();
            
            // Subtle flash highlight on textarea to give clear user feedback
            input.style.transition = 'border-color 0.2s, box-shadow 0.2s';
            input.style.borderColor = 'var(--brand-indigo-500)';
            input.style.boxShadow = '0 0 0 2px rgba(99, 102, 241, 0.3)';
            setTimeout(() => {
                input.style.borderColor = '';
                input.style.boxShadow = '';
            }, 600);
        }

        // â”€â”€ DEPARTMENT OPERATING HOURS & AWAY AUTOMATION HELPERS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        const DEPT_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        function onDeptHourToggle(day) {
            const cb = document.getElementById('deptHourActive_' + day);
            const openEl = document.getElementById('deptHourOpen_' + day);
            const closeEl = document.getElementById('deptHourClose_' + day);
            const statusEl = document.getElementById('deptHourStatus_' + day);

            const isActive = cb ? cb.checked : false;
            if (openEl) {
                openEl.disabled = !isActive;
                openEl.style.opacity = isActive ? '1' : '0.6';
            }
            if (closeEl) {
                closeEl.disabled = !isActive;
                closeEl.style.opacity = isActive ? '1' : '0.6';
            }
            if (statusEl) {
                if (isActive) {
                    statusEl.textContent = 'Open';
                    statusEl.style.color = '#047857';
                    statusEl.style.background = '#ECFDF5';
                    statusEl.style.border = '1px solid #A7F3D0';
                } else {
                    statusEl.textContent = 'Closed';
                    statusEl.style.color = '#648781';
                    statusEl.style.background = '#F1F5F9';
                    statusEl.style.border = '1px solid #CBD5E1';
                }
            }
        }
        window.onDeptHourToggle = onDeptHourToggle;

        function applyDeptHoursPreset(presetType) {
            DEPT_DAYS.forEach(day => {
                const cb = document.getElementById('deptHourActive_' + day);
                const openEl = document.getElementById('deptHourOpen_' + day);
                const closeEl = document.getElementById('deptHourClose_' + day);

                if (presetType === 'mon_sat') {
                    const isMonSat = day !== 'sun';
                    if (cb) cb.checked = isMonSat;
                    if (openEl) openEl.value = '09:00';
                    if (closeEl) closeEl.value = '18:00';
                } else if (presetType === 'mon_fri') {
                    const isMonFri = day !== 'sat' && day !== 'sun';
                    if (cb) cb.checked = isMonFri;
                    if (openEl) openEl.value = '09:00';
                    if (closeEl) closeEl.value = '17:00';
                } else if (presetType === '24_7') {
                    if (cb) cb.checked = true;
                    if (openEl) openEl.value = '00:00';
                    if (closeEl) closeEl.value = '23:59';
                }
                onDeptHourToggle(day);
            });
        }
        window.applyDeptHoursPreset = applyDeptHoursPreset;

        function getDeptWorkingHoursFromForm() {
            const hours = {};
            DEPT_DAYS.forEach(day => {
                const cb = document.getElementById('deptHourActive_' + day);
                const openEl = document.getElementById('deptHourOpen_' + day);
                const closeEl = document.getElementById('deptHourClose_' + day);
                hours[day] = {
                    active: cb ? cb.checked : false,
                    open: openEl ? openEl.value : '09:00',
                    close: closeEl ? closeEl.value : '18:00'
                };
            });
            return hours;
        }
        window.getDeptWorkingHoursFromForm = getDeptWorkingHoursFromForm;

        function setDeptWorkingHoursToForm(workingHours) {
            let wh = workingHours;
            if (typeof wh === 'string') {
                try { wh = JSON.parse(wh); } catch(e) { wh = null; }
            }
            if (!wh || typeof wh !== 'object' || Object.keys(wh).length === 0) {
                applyDeptHoursPreset('mon_sat');
                return;
            }

            DEPT_DAYS.forEach(day => {
                const dayConfig = wh[day] || (wh[day.toUpperCase()] || null);
                const cb = document.getElementById('deptHourActive_' + day);
                const openEl = document.getElementById('deptHourOpen_' + day);
                const closeEl = document.getElementById('deptHourClose_' + day);

                if (dayConfig) {
                    const isActive = dayConfig.active === true || dayConfig.active === 1 || dayConfig.active === '1';
                    if (cb) cb.checked = isActive;
                    if (openEl && dayConfig.open) openEl.value = dayConfig.open;
                    if (closeEl && dayConfig.close) closeEl.value = dayConfig.close;
                } else {
                    if (cb) cb.checked = day !== 'sun';
                    if (openEl) openEl.value = '09:00';
                    if (closeEl) closeEl.value = '18:00';
                }
                onDeptHourToggle(day);
            });
        }
        window.setDeptWorkingHoursToForm = setDeptWorkingHoursToForm;

        function applyDeptAwayTemplate() {
            const name = (document.getElementById('deptNameInput') && document.getElementById('deptNameInput').value.trim()) || 'Department';
            const template = `Our ${name} desk is currently closed for the evening. Your inquiry has been prioritized. A counselor will reach out tomorrow morning at 09:15 AM.`;
            const awayEl = document.getElementById('deptAwayMessageInput');
            if (awayEl) {
                awayEl.value = template;
                awayEl.focus();
            }
        }
        window.applyDeptAwayTemplate = applyDeptAwayTemplate;

        // Department Editor Page Navigation
        async function openCreateDeptModal() {
            // 1. Switch view and ensure department-editor tab content is freshly loaded
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            const editorPane = document.getElementById('tab-department-editor');
            if (editorPane) editorPane.dataset.loaded = 'false';
            await loadTabContent('department-editor');
            const editorTab = document.getElementById('tab-department-editor');
            if (editorTab) editorTab.classList.add('active');

            // Ensure availableOrgStaff is populated for staff checklist
            if (!availableOrgStaff || availableOrgStaff.length === 0) {
                try {
                    const authToken = localStorage.getItem('edvora_token') || token;
                    const res = await fetch('/v1/departments', {
                        headers: { 'Authorization': 'Bearer ' + authToken }
                    });
                    const data = await res.json();
                    if (data.status === 'success' && data.data) {
                        availableOrgStaff = data.data.available_staff || [];
                        availableOrgKs = data.data.available_knowledge_sources || [];
                        availableOrgCampuses = data.data.available_campuses || [];
                    }
                } catch(e) {}
            }

            // 2. Clear inputs safely with defensive checks
            const editId = document.getElementById('editDeptId');
            if (editId) editId.value = '';
            const mIcon = document.getElementById('modalDeptIcon');
            if (mIcon) mIcon.innerText = 'ðŸ«';
            const mTitle = document.getElementById('modalDeptTitle');
            if (mTitle) mTitle.innerText = 'Add Custom Department';
            const mSubtitle = document.getElementById('modalDeptSubtitle');
            if (mSubtitle) mSubtitle.innerText = 'Create contact handles, staff assignments, knowledge scope & escalation rules';
            const noticeDeptName = document.getElementById('staffNoticeDeptName');
            if (noticeDeptName) noticeDeptName.innerText = 'this department';
            const iconInput = document.getElementById('deptIconInput');
            if (iconInput) iconInput.value = 'ðŸ«';
            const iconPreview = document.getElementById('deptIconPreview');
            if (iconPreview) iconPreview.innerText = 'ðŸ«';
            const nameInput = document.getElementById('deptNameInput');
            if (nameInput) nameInput.value = '';
            const descInput = document.getElementById('deptDescInput');
            if (descInput) descInput.value = '';
            const emailInput = document.getElementById('deptEmailInput');
            if (emailInput) emailInput.value = '';
            const phoneInput = document.getElementById('deptPhoneInput');
            if (phoneInput) phoneInput.value = '';
            const waInput = document.getElementById('deptWhatsappInput');
            if (waInput) waInput.value = '';
            const greetingInput = document.getElementById('deptGreetingInput');
            if (greetingInput) greetingInput.value = '';
            const tzInput = document.getElementById('deptTimezoneInput');
            if (tzInput) tzInput.value = 'America/New_York';
            const awayInput = document.getElementById('deptAwayMessageInput');
            if (awayInput) awayInput.value = 'Our department desk is currently closed for the evening. Your inquiry has been prioritized. A counselor will reach out tomorrow morning at 09:15 AM.';

            applyDeptHoursPreset('mon_sat');

            const emailCheck = document.getElementById('escalateEmailCheck');
            if (emailCheck) emailCheck.checked = true;
            const waCheck = document.getElementById('escalateWhatsappCheck');
            if (waCheck) waCheck.checked = true;
            const leadAssignSelect = document.getElementById('leadAssignmentSelect');
            if (leadAssignSelect) leadAssignSelect.value = 'round_robin';

            const activeCb = document.getElementById('deptIsActiveInput');
            if (activeCb) activeCb.checked = false;
            const widgetCb = document.getElementById('deptEnableDedicatedWidgetInput');
            if (widgetCb) widgetCb.checked = false;
            updateEditorStatusDisplay(false);

            renderDeptKsList([]);
            renderDeptStaffChecklist([]);
            renderFaqs([]);
            renderDeptCourses([]);

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        async function openEditDeptModal(deptId, targetSection) {
            // 1. Switch view and ensure department-editor tab content is loaded first
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            const editorPane = document.getElementById('tab-department-editor');
            if (editorPane) editorPane.dataset.loaded = 'false';
            await loadTabContent('department-editor');
            const editorTab = document.getElementById('tab-department-editor');
            if (editorTab) editorTab.classList.add('active');

            let dept = (currentDepartments || []).find(d => d.id == deptId);
            if (!dept && window.currentDepartments) {
                dept = window.currentDepartments.find(d => d.id == deptId);
            }
            if (!dept || !dept.courses || !availableOrgStaff || availableOrgStaff.length === 0) {
                try {
                    const authToken = localStorage.getItem('edvora_token') || token;
                    const res = await fetch('/v1/departments', {
                        headers: { 'Authorization': 'Bearer ' + authToken }
                    });
                    const data = await res.json();
                    if (data.status === 'success' && data.data) {
                        const depts = data.data.departments || (Array.isArray(data.data) ? data.data : []);
                        currentDepartments = depts;
                        window.currentDepartments = depts;
                        availableOrgStaff = data.data.available_staff || [];
                        availableOrgKs = data.data.available_knowledge_sources || [];
                        availableOrgCampuses = data.data.available_campuses || [];
                        dept = currentDepartments.find(d => d.id == deptId) || dept;
                    }
                } catch(e) {
                    console.warn('Failed to fetch departments in openEditDeptModal', e);
                }
            }
            if (!dept) {
                console.warn('Could not locate department with ID:', deptId);
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const isActive = dept.is_active == 1;
            const hasDedicatedWidget = (dept.enable_dedicated_widget !== 0 && dept.enable_dedicated_widget !== '0' && dept.enable_dedicated_widget !== false);
            const deptIcon = dept.icon || 'ðŸ«';

            const editId = document.getElementById('editDeptId');
            if (editId) editId.value = dept.id;
            const mIcon = document.getElementById('modalDeptIcon');
            if (mIcon) mIcon.innerText = deptIcon;
            const mTitle = document.getElementById('modalDeptTitle');
            if (mTitle) mTitle.innerText = 'Configure ' + dept.name;
            const noticeDeptName = document.getElementById('staffNoticeDeptName');
            if (noticeDeptName) noticeDeptName.innerText = dept.name;
            const mSubtitle = document.getElementById('modalDeptSubtitle');
            if (mSubtitle) mSubtitle.innerText = 'Edit contact info, staff assignments, knowledge scope & escalation rules';
            const iconInput = document.getElementById('deptIconInput');
            if (iconInput) iconInput.value = deptIcon;
            const iconPreview = document.getElementById('deptIconPreview');
            if (iconPreview) iconPreview.innerText = deptIcon;
            const nameInput = document.getElementById('deptNameInput');
            if (nameInput) nameInput.value = dept.name;
            const descInput = document.getElementById('deptDescInput');
            if (descInput) descInput.value = dept.description || '';
            const emailInput = document.getElementById('deptEmailInput');
            if (emailInput) emailInput.value = dept.email || '';
            const phoneInput = document.getElementById('deptPhoneInput');
            if (phoneInput) phoneInput.value = dept.phone || '';
            const waInput = document.getElementById('deptWhatsappInput');
            if (waInput) waInput.value = dept.whatsapp || '';
            const greetingInput = document.getElementById('deptGreetingInput');
            if (greetingInput) greetingInput.value = dept.greeting_message || '';
            const tzInput = document.getElementById('deptTimezoneInput');
            if (tzInput) tzInput.value = dept.timezone || 'America/New_York';
            const awayInput = document.getElementById('deptAwayMessageInput');
            if (awayInput) awayInput.value = dept.auto_away_message || (`Our ${dept.name} desk is currently closed for the evening. Your inquiry has been prioritized. A counselor will reach out tomorrow morning at 09:15 AM.`);

            // Operating Hours
            setDeptWorkingHoursToForm(dept.working_hours);

            const activeCb = document.getElementById('deptIsActiveInput');
            if (activeCb) activeCb.checked = isActive;
            const widgetCb = document.getElementById('deptEnableDedicatedWidgetInput');
            if (widgetCb) widgetCb.checked = hasDedicatedWidget;
            updateEditorStatusDisplay(isActive);

            // Escalation rules
            const escRules = typeof dept.escalation_rules === 'string' ? JSON.parse(dept.escalation_rules || '{}') : (dept.escalation_rules || {});
            const emailCheck = document.getElementById('escalateEmailCheck');
            if (emailCheck) emailCheck.checked = escRules.notify_email !== false;
            const waCheck = document.getElementById('escalateWhatsappCheck');
            if (waCheck) waCheck.checked = escRules.notify_whatsapp !== false;

            // Lead assignment rules
            const leadRules = typeof dept.lead_assignment_rules === 'string' ? JSON.parse(dept.lead_assignment_rules || '{}') : (dept.lead_assignment_rules || {});
            const leadAssignSelect = document.getElementById('leadAssignmentSelect');
            if (leadAssignSelect) leadAssignSelect.value = leadRules.method || 'round_robin';

            // Attached knowledge documents
            renderDeptKsList(dept.knowledge_sources || []);

            // Assigned staff checklist
            const assignedStaff = (dept.staff || []).map(s => s.user_id);
            renderDeptStaffChecklist(assignedStaff);

            // Starter FAQs & quick chips
            renderFaqs(dept.faqs || []);

            // Academic Programs & Offered Courses
            renderDeptCourses(dept.courses || []);

            if (targetSection === 'courses') {
                setTimeout(() => {
                    const el = document.getElementById('sectionDeptCourses');
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        el.style.transition = 'background 0.5s ease';
                        el.style.background = 'rgba(16, 185, 129, 0.08)';
                        setTimeout(() => { el.style.background = 'transparent'; }, 1600);
                    }
                }, 250);
            } else if (targetSection === 'hours') {
                setTimeout(() => {
                    const el = document.getElementById('sectionDeptOperatingHours');
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        el.style.transition = 'background 0.5s ease';
                        el.style.background = 'rgba(16, 185, 129, 0.08)';
                        setTimeout(() => { el.style.background = 'transparent'; }, 1600);
                    }
                }, 250);
            } else if (targetSection === 'escalations') {
                setTimeout(() => {
                    const el = document.getElementById('sectionDeptEscalations');
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        el.style.transition = 'background 0.5s ease';
                        el.style.background = 'rgba(217, 119, 6, 0.08)';
                        setTimeout(() => { el.style.background = 'transparent'; }, 1600);
                    }
                }, 250);
            } else {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        window.openCreateDeptModal = openCreateDeptModal;
        window.openEditDeptModal = openEditDeptModal;

        function closeDepartmentModal() {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            const deptTab = document.getElementById('tab-departments');
            if (deptTab) deptTab.classList.add('active');
            if (typeof loadDepartments === 'function') loadDepartments();
            if (typeof loadRealDashboardDepartments === 'function') loadRealDashboardDepartments();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        window.closeDepartmentModal = closeDepartmentModal;

        // â”€â”€ 5 DEDICATED FOCUSED DEPARTMENT EDITORS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        async function openDeptCardEditor(cardType, deptId) {
            if (!deptId) {
                deptId = window.currentLandingDeptId;
                if (!deptId && window.landingDeptData && window.currentLandingDeptKey && window.landingDeptData[window.currentLandingDeptKey]) {
                    deptId = window.landingDeptData[window.currentLandingDeptKey].id;
                }
                if (!deptId && window.currentDepartments && window.currentDepartments.length > 0) {
                    const curKey = window.currentLandingDeptKey || '';
                    const match = window.currentDepartments.find(d => d.slug === curKey || ('dept_' + d.id) === curKey || d.id == curKey);
                    if (match) deptId = match.id;
                }
                if (!deptId && window.currentDepartments && window.currentDepartments.length > 0) {
                    deptId = window.currentDepartments[0].id;
                }
            }



            // If "Documents & Sources" (knowledge) card was clicked:
            // Route directly to Central Knowledge Hub with this department pre-selected
            if (cardType === 'knowledge') {
                const targetDeptFilter = String(deptId);
                window._userSelectedKnowledgeDeptFilter = targetDeptFilter;
                window._skipKnowledgeFilterResetOnce = true;
                await switchNavTab('knowledge');
                const selectEl = document.getElementById('ckhDeptFilter');
                if (selectEl) {
                    selectEl.value = targetDeptFilter;
                }
                if (typeof filterKnowledgeTable === 'function') {
                    filterKnowledgeTable();
                }
                return;
            }

            const validTypes = ['courses', 'staff', 'hours', 'escalations'];
            const tabMap = {
                'courses': 'dept-courses-editor',
                'staff': 'dept-staff-editor',
                'hours': 'dept-hours-editor',
                'escalations': 'dept-escalations-editor'
            };
            const tabName = tabMap[cardType] || ('dept-' + cardType + '-editor');

            // 1. Switch views and ensure target editor tab content is loaded
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            const editorPane = document.getElementById('tab-' + tabName);
            if (editorPane) editorPane.dataset.loaded = 'false';
            await loadTabContent(tabName);
            const editorTab = document.getElementById('tab-' + tabName);
            if (editorTab) editorTab.classList.add('active');

            // 2. Locate department data
            let dept = (currentDepartments || []).find(d => d.id == deptId);
            if (!dept && window.currentDepartments) {
                dept = window.currentDepartments.find(d => d.id == deptId);
            }
            if (!dept || !dept.courses || !availableOrgStaff || availableOrgStaff.length === 0) {
                try {
                    const authToken = localStorage.getItem('edvora_token') || token;
                    const res = await fetch('/v1/departments', {
                        headers: { 'Authorization': 'Bearer ' + authToken }
                    });
                    const data = await res.json();
                    if (data.status === 'success' && data.data) {
                        const depts = data.data.departments || (Array.isArray(data.data) ? data.data : []);
                        currentDepartments = depts;
                        window.currentDepartments = depts;
                        availableOrgStaff = data.data.available_staff || [];
                        availableOrgKs = data.data.available_knowledge_sources || [];
                        availableOrgCampuses = data.data.available_campuses || [];
                        dept = currentDepartments.find(d => d.id == deptId) || dept;
                    }
                } catch(e) {
                    console.warn('Failed to fetch departments in openDeptCardEditor', e);
                }
            }

            if (!dept) {
                console.warn('Could not locate department with ID:', deptId);
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const deptIcon = dept.icon || 'ðŸ«';
            const deptName = dept.name || 'Department';

            // 3. Populate specific form based on cardType
            if (cardType === 'courses') {
                const idEl = document.getElementById('coursesEditorDeptId');
                if (idEl) idEl.value = dept.id;
                const iconEl = document.getElementById('coursesModalDeptIcon');
                if (iconEl) iconEl.innerText = deptIcon;
                const titleEl = document.getElementById('coursesModalDeptTitle');
                if (titleEl) titleEl.innerText = deptName + ' â€” Programs & Courses';
                renderFocusedDeptCourses(dept.courses || []);
            } else if (cardType === 'staff') {
                const idEl = document.getElementById('staffEditorDeptId');
                if (idEl) idEl.value = dept.id;
                const iconEl = document.getElementById('staffModalDeptIcon');
                if (iconEl) iconEl.innerText = deptIcon;
                const titleEl = document.getElementById('staffModalDeptTitle');
                if (titleEl) titleEl.innerText = deptName + ' â€” Assigned Staff';
                const noticeDept = document.getElementById('focusedStaffNoticeDeptName');
                if (noticeDept) noticeDept.innerText = deptName;
                const assignedStaff = (dept.staff || []).map(s => s.user_id);
                renderFocusedDeptStaff(assignedStaff);
            } else if (cardType === 'knowledge') {
                const idEl = document.getElementById('ksEditorDeptId');
                if (idEl) idEl.value = dept.id;
                const iconEl = document.getElementById('ksModalDeptIcon');
                if (iconEl) iconEl.innerText = deptIcon;
                const titleEl = document.getElementById('ksModalDeptTitle');
                if (titleEl) titleEl.innerText = deptName + ' â€” Knowledge Scope & Sources';
                if (typeof dismissFocusedDeptKsAlert === 'function') dismissFocusedDeptKsAlert();
                renderFocusedDeptKsList(dept.knowledge_sources || []);
            } else if (cardType === 'hours') {
                const idEl = document.getElementById('hoursEditorDeptId');
                if (idEl) idEl.value = dept.id;
                const iconEl = document.getElementById('hoursModalDeptIcon');
                if (iconEl) iconEl.innerText = deptIcon;
                const titleEl = document.getElementById('hoursModalDeptTitle');
                if (titleEl) titleEl.innerText = deptName + ' â€” Operating Hours & Auto-Away';
                const tzInput = document.getElementById('focusedDeptTimezoneInput');
                if (tzInput) tzInput.value = dept.timezone || 'America/New_York';
                const awayInput = document.getElementById('focusedDeptAwayMessageInput');
                if (awayInput) awayInput.value = dept.auto_away_message || (`Our ${deptName} desk is currently closed for the evening. Your inquiry has been prioritized. A counselor will reach out tomorrow morning at 09:15 AM.`);
                setFocusedDeptWorkingHoursToForm(dept.working_hours);
            } else if (cardType === 'escalations') {
                const idEl = document.getElementById('escEditorDeptId');
                if (idEl) idEl.value = dept.id;
                const iconEl = document.getElementById('escModalDeptIcon');
                if (iconEl) iconEl.innerText = deptIcon;
                const titleEl = document.getElementById('escModalDeptTitle');
                if (titleEl) titleEl.innerText = deptName + ' â€” Escalations & Assignment Policy';
                
                const escRules = typeof dept.escalation_rules === 'string' ? JSON.parse(dept.escalation_rules || '{}') : (dept.escalation_rules || {});
                const emailCheck = document.getElementById('focusedEscalateEmailCheck');
                if (emailCheck) emailCheck.checked = escRules.notify_email !== false;
                const waCheck = document.getElementById('focusedEscalateWhatsappCheck');
                if (waCheck) waCheck.checked = escRules.notify_whatsapp !== false;

                const leadRules = typeof dept.lead_assignment_rules === 'string' ? JSON.parse(dept.lead_assignment_rules || '{}') : (dept.lead_assignment_rules || {});
                const leadAssignSelect = document.getElementById('focusedLeadAssignmentSelect');
                if (leadAssignSelect) leadAssignSelect.value = leadRules.method || 'round_robin';
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        window.openDeptCardEditor = openDeptCardEditor;

        function closeDeptCardEditor() {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            const deptTab = document.getElementById('tab-departments');
            if (deptTab) deptTab.classList.add('active');

            const grid = document.getElementById('view-departments-grid');
            const detail = document.getElementById('view-departments-detail');
            if (grid) grid.style.display = 'none';
            if (detail) detail.style.display = 'block';

            if (typeof loadDepartments === 'function') loadDepartments();
            if (typeof loadRealDashboardDepartments === 'function') {
                loadRealDashboardDepartments().then(() => {
                    if (window.currentLandingDeptKey && typeof selectLandingDept === 'function') {
                        selectLandingDept(window.currentLandingDeptKey);
                    }
                });
            } else if (window.currentLandingDeptKey && typeof selectLandingDept === 'function') {
                selectLandingDept(window.currentLandingDeptKey);
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        window.closeDeptCardEditor = closeDeptCardEditor;

        // 1. COURSES HELPERS & SAVE
        function renderFocusedDeptCourses(courses) {
            const container = document.getElementById('focusedDeptCoursesContainer');
            if (!container) return;
            container.innerHTML = '';
            if (!courses || courses.length === 0) {
                addFocusedCourseRow();
                return;
            }
            courses.forEach(c => addFocusedCourseRow(c.id, c.course_name, c.course_code, c.campus_ids || []));
        }
        window.renderFocusedDeptCourses = renderFocusedDeptCourses;

        function addFocusedCourseRow(id = null, name = '', code = '', mappedCampusIds = []) {
            const container = document.getElementById('focusedDeptCoursesContainer');
            if (!container) return;

            const campuses = availableOrgCampuses || [];
            let campusChipsHtml = '';
            if (campuses.length > 0) {
                campusChipsHtml = campuses.map(c => {
                    const isChecked = Array.isArray(mappedCampusIds) && mappedCampusIds.includes(c.id);
                    return `
                        <label style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: #063D3B; cursor: pointer; background: #FFFFFF; border: 1.5px solid ${isChecked ? '#10B981' : '#D1E5DE'}; border-radius: 6px; padding: 3px 8px; user-select: none;">
                            <input type="checkbox" class="focused-course-campus-check" value="${c.id}" ${isChecked ? 'checked' : ''} onchange="this.parentElement.style.borderColor = this.checked ? '#10B981' : '#D1E5DE'" style="width: 13px; height: 13px; accent-color: #047857; cursor: pointer;" />
                            <span>${escapeHtml(c.short_name || c.name)}</span>
                        </label>
                    `;
                }).join('');
            } else {
                campusChipsHtml = `<span style="color: #94A3B8; font-style: italic; font-size: 10.5px;">No campuses registered.</span>`;
            }

            const row = document.createElement('div');
            row.className = 'focused-course-row';
            row.style.cssText = 'background: #F8FCFA; border: 1.5px solid #DCE9E5; border-radius: 8px; padding: 10px 12px; display: flex; flex-direction: column; gap: 8px;';
            row.innerHTML = `
                <div style="display: grid; grid-template-columns: 2fr 1fr 30px; gap: 8px; align-items: center;">
                    <input type="hidden" class="focused-course-id" value="${escapeHtml(String(id || ''))}" />
                    <input type="text" class="brand-input focused-course-name" placeholder="Course / Program Name (e.g. Master of Business Administration)..." value="${escapeHtml(name || '')}" style="height: 34px; font-size: 12px;" />
                    <input type="text" class="brand-input focused-course-code" placeholder="Code (e.g. MBA-01)..." value="${escapeHtml(code || '')}" style="height: 34px; font-size: 12px;" />
                    <button type="button" class="brand-btn-secondary brand-btn-sm" style="color: var(--brand-rose-400); height: 32px; font-size: 12px;" onclick="this.closest('.focused-course-row').remove()" title="Remove Course">âœ•</button>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11px; padding-top: 6px; border-top: 1px dashed #DCE9E5;">
                    <span style="font-weight: 700; color: #4F7470; display: inline-flex; align-items: center; gap: 4px;">
                        <span>ðŸ›ï¸</span> Available at:
                    </span>
                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                        ${campusChipsHtml}
                    </div>
                </div>
            `;
            container.appendChild(row);
        }
        window.addFocusedCourseRow = addFocusedCourseRow;

        async function saveDeptCoursesOnly() {
            const deptIdEl = document.getElementById('coursesEditorDeptId');
            if (!deptIdEl || !deptIdEl.value) {
                alert('No active department selected.');
                return;
            }
            const deptId = deptIdEl.value;
            const courses = [];
            document.querySelectorAll('.focused-course-row').forEach(row => {
                const idVal = row.querySelector('.focused-course-id') ? row.querySelector('.focused-course-id').value.trim() : '';
                const name = row.querySelector('.focused-course-name') ? row.querySelector('.focused-course-name').value.trim() : '';
                const code = row.querySelector('.focused-course-code') ? row.querySelector('.focused-course-code').value.trim() : '';
                const campusIds = Array.from(row.querySelectorAll('.focused-course-campus-check:checked')).map(cb => parseInt(cb.value));
                if (name) {
                    courses.push({
                        id: idVal ? parseInt(idVal) : null,
                        course_name: name,
                        course_code: code,
                        campus_ids: campusIds
                    });
                }
            });

            try {
                const res = await fetch(`/v1/departments/${deptId}/courses`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ courses })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeDeptCardEditor();
                } else {
                    alert(data.message || 'Failed to save courses.');
                }
            } catch (e) {
                console.error('Error saving courses:', e);
                alert('Error saving programs & courses.');
            }
        }
        window.saveDeptCoursesOnly = saveDeptCoursesOnly;

        // 2. STAFF HELPERS & SAVE
        function renderFocusedDeptStaff(assignedUserIds) {
            const container = document.getElementById('focusedDeptStaffChecklist');
            if (!container) return;
            if (availableOrgStaff.length === 0) {
                container.innerHTML = `<div style="font-size: 11px; color: #648781;">No staff users registered.</div>`;
                return;
            }

            container.innerHTML = availableOrgStaff.map(u => `
                <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #092F2E; cursor: pointer; padding: 4px 0;">
                    <input type="checkbox" class="focused-dept-staff-checkbox" value="${u.id}" ${assignedUserIds.includes(u.id) ? 'checked' : ''} style="width: 15px; height: 15px; accent-color: #047857;" />
                    <span>ðŸ‘¤ <strong>${escapeHtml(u.name)}</strong> <small style="color: #648781;">(${escapeHtml(u.email)} â€¢ ${escapeHtml(u.role)})</small></span>
                </label>
            `).join('');
        }
        window.renderFocusedDeptStaff = renderFocusedDeptStaff;

        async function saveDeptStaffOnly() {
            const deptIdEl = document.getElementById('staffEditorDeptId');
            if (!deptIdEl || !deptIdEl.value) {
                alert('No active department selected.');
                return;
            }
            const deptId = deptIdEl.value;
            const selectedStaff = Array.from(document.querySelectorAll('.focused-dept-staff-checkbox:checked')).map(cb => ({
                user_id: parseInt(cb.value),
                role: 'agent',
                is_on_duty: 1
            }));

            try {
                const res = await fetch(`/v1/departments/${deptId}/staff`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ staff: selectedStaff })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeDeptCardEditor();
                } else {
                    alert(data.message || 'Failed to save staff roster.');
                }
            } catch (e) {
                console.error('Error saving staff:', e);
                alert('Error saving staff roster.');
            }
        }
        window.saveDeptStaffOnly = saveDeptStaffOnly;

        // 3. KNOWLEDGE HELPERS
        function getDeptKsDocBadge(s) {
            const type = (s.type || '').toLowerCase();
            const fn = (s.file_path || s.filename || s.title || '').toLowerCase();

            if (type === 'url') {
                return `<span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 6px; background: #E0F2FE; color: #0369A1; font-size: 16px; border: 1.5px solid #BAE6FD; flex-shrink: 0;" title="Web URL">ðŸŒ</span>`;
            }
            if (type === 'text_paste' || type === 'text') {
                return `<span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 6px; background: #FEF3C7; color: #D97706; font-size: 16px; border: 1.5px solid #FDE68A; flex-shrink: 0;" title="Prospectus Text">ðŸ“</span>`;
            }
            // Document types: evaluate file extension
            if (fn.includes('.pdf') || fn.endsWith('.pdf')) {
                return `<span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 6px; background: #FEE2E2; color: #DC2626; font-size: 11px; font-weight: 800; border: 1.5px solid #FCA5A5; flex-shrink: 0; letter-spacing: -0.2px;" title="PDF Document">PDF</span>`;
            }
            if (fn.includes('.doc') || fn.includes('.docx')) {
                return `<span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 6px; background: #DBEAFE; color: #1D4ED8; font-size: 11px; font-weight: 800; border: 1.5px solid #93C5FD; flex-shrink: 0; letter-spacing: -0.2px;" title="Word Document">DOC</span>`;
            }
            if (fn.includes('.txt')) {
                return `<span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 6px; background: #F1F5F9; color: #475569; font-size: 11px; font-weight: 800; border: 1.5px solid #CBD5E1; flex-shrink: 0;" title="Plain Text File">TXT</span>`;
            }
            return `<span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 6px; background: #FEE2E2; color: #DC2626; font-size: 11px; font-weight: 800; border: 1.5px solid #FCA5A5; flex-shrink: 0;" title="Document">DOC</span>`;
        }
        window.getDeptKsDocBadge = getDeptKsDocBadge;

        function showFocusedDeptKsAlert(message, type = 'success') {
            const alertBox = document.getElementById('focusedDeptKsAlert');
            const msgEl = document.getElementById('focusedDeptKsAlertMsg');
            const iconEl = document.getElementById('focusedDeptKsAlertIcon');
            if (!alertBox || !msgEl) return;

            msgEl.textContent = message;
            if (type === 'success') {
                alertBox.style.background = '#ECFDF5';
                alertBox.style.border = '1.5px solid #10B981';
                alertBox.style.color = '#065F46';
                if (iconEl) {
                    iconEl.textContent = 'âœ“';
                    iconEl.style.background = '#D1FAE5';
                    iconEl.style.color = '#065F46';
                }
            } else if (type === 'error') {
                alertBox.style.background = '#FEF2F2';
                alertBox.style.border = '1.5px solid #EF4444';
                alertBox.style.color = '#991B1B';
                if (iconEl) {
                    iconEl.textContent = 'âœ•';
                    iconEl.style.background = '#FEE2E2';
                    iconEl.style.color = '#991B1B';
                }
            } else {
                alertBox.style.background = '#EFF6FF';
                alertBox.style.border = '1.5px solid #3B82F6';
                alertBox.style.color = '#1E40AF';
                if (iconEl) {
                    iconEl.textContent = 'â„¹';
                    iconEl.style.background = '#DBEAFE';
                    iconEl.style.color = '#1E40AF';
                }
            }

            alertBox.style.display = 'flex';
            alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            if (window._deptKsAlertTimeout) clearTimeout(window._deptKsAlertTimeout);
            window._deptKsAlertTimeout = setTimeout(() => {
                const b = document.getElementById('focusedDeptKsAlert');
                if (b) b.style.display = 'none';
            }, 8000);
        }
        window.showFocusedDeptKsAlert = showFocusedDeptKsAlert;

        function dismissFocusedDeptKsAlert() {
            const alertBox = document.getElementById('focusedDeptKsAlert');
            if (alertBox) alertBox.style.display = 'none';
        }
        window.dismissFocusedDeptKsAlert = dismissFocusedDeptKsAlert;

        function renderFocusedDeptKsList(sources) {
            const container = document.getElementById('focusedDeptKsList');
            const badge = document.getElementById('focusedDeptKsCountBadge');

            if (!container) return;
            if (badge) badge.innerText = `${sources ? sources.length : 0} Document${(sources && sources.length === 1) ? '' : 's'}`;

            if (!sources || sources.length === 0) {
                container.innerHTML = `<div style="font-size: 12px; color: #648781; padding: 14px; text-align: center;">No knowledge documents added to this department yet. Add a prospectus document or file below.</div>`;
                return;
            }

            container.innerHTML = sources.map(s => {
                const docBadge = getDeptKsDocBadge(s);
                return `
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 12px 16px; background: #DCE8E2; border: 1.5px solid #ABC4B8; border-radius: 8px; box-shadow: 0 1px 3px rgba(6, 61, 59, 0.08); transition: background 0.15s ease;">
                        <div style="display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0;">
                            ${docBadge}
                            <div style="flex: 1; min-width: 0;">
                                <strong style="font-size: 13px; font-weight: 800; color: #063D3B; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.35;">${escapeHtml(s.title)}</strong>
                                <div style="display: flex; align-items: center; gap: 8px; margin-top: 2px;">
                                    <span style="font-size: 10.5px; font-weight: 700; color: #1E4D45; text-transform: uppercase; letter-spacing: 0.3px;">${escapeHtml(s.type)}</span>
                                    <span style="color: #6C8E86; font-size: 10px;">â€¢</span>
                                    <span style="font-size: 10.5px; font-weight: 600; color: ${s.status === 'active' ? '#047857' : '#B45309'}; text-transform: uppercase;">STATUS: ${escapeHtml(s.status)}</span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="brand-btn-secondary" style="background: #FFFFFF; border: 1.5px solid #C2D8CE; color: #DC2626; font-weight: 700; flex-shrink: 0; padding: 5px 12px; font-size: 11px; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.04);" onclick="deleteFocusedDeptKnowledgeSource(${s.id})">
                            ðŸ—‘ï¸ Delete
                        </button>
                    </div>
                `;
            }).join('');
        }
        window.renderFocusedDeptKsList = renderFocusedDeptKsList;

        function switchFocusedDeptKsMode(mode) {
            document.querySelectorAll('.focused-dept-ks-mode-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.focused-dept-ks-mode-panel').forEach(p => p.style.display = 'none');

            const activeBtn = document.querySelector(`.focused-dept-ks-mode-btn[data-mode="${mode}"]`);
            if (activeBtn) activeBtn.classList.add('active');

            const targetPanel = document.getElementById(mode === 'text' ? 'focusedDeptKsModeText' : (mode === 'file' ? 'focusedDeptKsModeFile' : 'focusedDeptKsModeUrl'));
            if (targetPanel) targetPanel.style.display = 'block';
        }
        window.switchFocusedDeptKsMode = switchFocusedDeptKsMode;

        async function refreshFocusedDeptKnowledgeSources(deptId) {
            await loadDepartments();
            const updatedDept = currentDepartments.find(d => d.id == deptId);
            if (updatedDept) {
                renderFocusedDeptKsList(updatedDept.knowledge_sources || []);
            }
        }

        async function addFocusedDeptKnowledgeText() {
            const deptId = document.getElementById('ksEditorDeptId').value;
            if (!deptId) return alert('No department selected.');
            const title = document.getElementById('focusedDeptKsTitleText').value.trim();
            const content = document.getElementById('focusedDeptKsContentText').value.trim();
            if (!title || !content) return alert('Please provide both document title and prospectus text content.');

            try {
                const res = await fetch('/v1/knowledge/paste', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ title, content, department_id: deptId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('focusedDeptKsTitleText').value = '';
                    document.getElementById('focusedDeptKsContentText').value = '';
                    showFocusedDeptKsAlert('Prospectus text knowledge added and attached to department successfully!', 'success');
                    await refreshFocusedDeptKnowledgeSources(deptId);
                } else {
                    showFocusedDeptKsAlert(data.message || 'Failed to add knowledge source', 'error');
                }
            } catch (err) {
                console.error(err);
                showFocusedDeptKsAlert('Error adding text knowledge source', 'error');
            }
        }
        window.addFocusedDeptKnowledgeText = addFocusedDeptKnowledgeText;

        async function addFocusedDeptKnowledgeFile() {
            const deptId = document.getElementById('ksEditorDeptId').value;
            if (!deptId) return alert('No department selected.');
            const title = document.getElementById('focusedDeptKsTitleFile').value.trim();
            const fileInput = document.getElementById('focusedDeptKsFileInput');
            if (!fileInput.files || fileInput.files.length === 0) return alert('Please select a file to upload (.pdf, .docx, .txt).');

            const btn = document.getElementById('btnFocusedDeptKsUpload');
            const origText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span>Uploading &amp; Extracting...</span>';
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
                    const uploadedTitle = (data.data && data.data.title) ? data.data.title : (title || fileInput.files[0].name);
                    document.getElementById('focusedDeptKsTitleFile').value = '';
                    fileInput.value = '';
                    showFocusedDeptKsAlert(`Document "${uploadedTitle}" uploaded and processed successfully! It is now attached to this department.`, 'success');
                    await refreshFocusedDeptKnowledgeSources(deptId);
                } else {
                    showFocusedDeptKsAlert(data.message || 'Failed to upload document', 'error');
                }
            } catch (err) {
                console.error(err);
                showFocusedDeptKsAlert('Error uploading document: ' + (err.message || err), 'error');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
            }
        }
        window.addFocusedDeptKnowledgeFile = addFocusedDeptKnowledgeFile;

        async function addFocusedDeptKnowledgeUrl() {
            const deptId = document.getElementById('ksEditorDeptId').value;
            if (!deptId) return alert('No department selected.');
            const title = document.getElementById('focusedDeptKsTitleUrl').value.trim();
            const url = document.getElementById('focusedDeptKsUrlInput').value.trim();
            if (!url) return alert('Please enter a valid URL.');

            try {
                const res = await fetch('/v1/knowledge/url', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ url, title, department_id: deptId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('focusedDeptKsTitleUrl').value = '';
                    document.getElementById('focusedDeptKsUrlInput').value = '';
                    showFocusedDeptKsAlert('Web page scraped and attached to department successfully!', 'success');
                    await refreshFocusedDeptKnowledgeSources(deptId);
                } else {
                    showFocusedDeptKsAlert(data.message || 'Failed to add web URL', 'error');
                }
            } catch (err) {
                console.error(err);
                showFocusedDeptKsAlert('Error adding web URL', 'error');
            }
        }
        window.addFocusedDeptKnowledgeUrl = addFocusedDeptKnowledgeUrl;

        async function deleteFocusedDeptKnowledgeSource(ksId) {
            if (!confirm('Are you sure you want to delete this knowledge source?')) return;
            const deptId = document.getElementById('ksEditorDeptId').value;
            try {
                const res = await fetch(`/v1/knowledge/${ksId}`, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showFocusedDeptKsAlert('Knowledge document removed from this department.', 'info');
                    await refreshFocusedDeptKnowledgeSources(deptId);
                } else {
                    showFocusedDeptKsAlert(data.message || 'Failed to delete knowledge source', 'error');
                }
            } catch (err) {
                console.error(err);
                showFocusedDeptKsAlert('Error deleting knowledge source', 'error');
            }
        }
        window.deleteFocusedDeptKnowledgeSource = deleteFocusedDeptKnowledgeSource;

        // 4. OPERATING HOURS HELPERS & SAVE
        function onFocusedDeptHourToggle(day) {
            const cb = document.getElementById('focusedDeptHourActive_' + day);
            const openEl = document.getElementById('focusedDeptHourOpen_' + day);
            const closeEl = document.getElementById('focusedDeptHourClose_' + day);
            const statusEl = document.getElementById('focusedDeptHourStatus_' + day);

            if (!cb || !statusEl) return;
            const isActive = cb.checked;
            if (openEl) {
                openEl.disabled = !isActive;
                openEl.style.opacity = isActive ? '1' : '0.6';
            }
            if (closeEl) {
                closeEl.disabled = !isActive;
                closeEl.style.opacity = isActive ? '1' : '0.6';
            }
            if (isActive) {
                statusEl.innerText = 'Open';
                statusEl.style.color = '#047857';
                statusEl.style.background = '#ECFDF5';
                statusEl.style.border = '1px solid #A7F3D0';
            } else {
                statusEl.innerText = 'Closed';
                statusEl.style.color = '#648781';
                statusEl.style.background = '#F1F5F9';
                statusEl.style.border = '1px solid #CBD5E1';
            }
        }
        window.onFocusedDeptHourToggle = onFocusedDeptHourToggle;

        function applyFocusedDeptHoursPreset(presetType) {
            DEPT_DAYS.forEach(day => {
                const cb = document.getElementById('focusedDeptHourActive_' + day);
                const openEl = document.getElementById('focusedDeptHourOpen_' + day);
                const closeEl = document.getElementById('focusedDeptHourClose_' + day);

                if (presetType === 'mon_sat') {
                    const isMonSat = day !== 'sun';
                    if (cb) cb.checked = isMonSat;
                    if (openEl) openEl.value = '09:00';
                    if (closeEl) closeEl.value = '18:00';
                } else if (presetType === 'mon_fri') {
                    const isMonFri = day !== 'sat' && day !== 'sun';
                    if (cb) cb.checked = isMonFri;
                    if (openEl) openEl.value = '09:00';
                    if (closeEl) closeEl.value = '17:00';
                } else if (presetType === '24_7') {
                    if (cb) cb.checked = true;
                    if (openEl) openEl.value = '00:00';
                    if (closeEl) closeEl.value = '23:59';
                }
                onFocusedDeptHourToggle(day);
            });
        }
        window.applyFocusedDeptHoursPreset = applyFocusedDeptHoursPreset;

        function getFocusedDeptWorkingHoursFromForm() {
            const hours = {};
            DEPT_DAYS.forEach(day => {
                const cb = document.getElementById('focusedDeptHourActive_' + day);
                const openEl = document.getElementById('focusedDeptHourOpen_' + day);
                const closeEl = document.getElementById('focusedDeptHourClose_' + day);
                hours[day] = {
                    active: cb ? cb.checked : false,
                    open: openEl ? openEl.value : '09:00',
                    close: closeEl ? closeEl.value : '18:00'
                };
            });
            return hours;
        }
        window.getFocusedDeptWorkingHoursFromForm = getFocusedDeptWorkingHoursFromForm;

        function setFocusedDeptWorkingHoursToForm(workingHours) {
            let wh = workingHours;
            if (typeof wh === 'string') {
                try { wh = JSON.parse(wh); } catch(e) { wh = null; }
            }
            if (!wh || typeof wh !== 'object' || Object.keys(wh).length === 0) {
                applyFocusedDeptHoursPreset('mon_sat');
                return;
            }

            DEPT_DAYS.forEach(day => {
                const dayConfig = wh[day] || (wh[day.toUpperCase()] || null);
                const cb = document.getElementById('focusedDeptHourActive_' + day);
                const openEl = document.getElementById('focusedDeptHourOpen_' + day);
                const closeEl = document.getElementById('focusedDeptHourClose_' + day);

                if (dayConfig) {
                    const isActive = dayConfig.active === true || dayConfig.active === 1 || dayConfig.active === '1';
                    if (cb) cb.checked = isActive;
                    if (openEl && dayConfig.open) openEl.value = dayConfig.open;
                    if (closeEl && dayConfig.close) closeEl.value = dayConfig.close;
                } else {
                    if (cb) cb.checked = day !== 'sun';
                    if (openEl) openEl.value = '09:00';
                    if (closeEl) closeEl.value = '18:00';
                }
                onFocusedDeptHourToggle(day);
            });
        }
        window.setFocusedDeptWorkingHoursToForm = setFocusedDeptWorkingHoursToForm;

        function applyFocusedDeptAwayTemplate() {
            const template = `Our department desk is currently closed for the evening. Your inquiry has been prioritized. A counselor will reach out tomorrow morning at 09:15 AM.`;
            const awayEl = document.getElementById('focusedDeptAwayMessageInput');
            if (awayEl) awayEl.value = template;
        }
        window.applyFocusedDeptAwayTemplate = applyFocusedDeptAwayTemplate;

        async function saveDeptHoursOnly() {
            const deptIdEl = document.getElementById('hoursEditorDeptId');
            if (!deptIdEl || !deptIdEl.value) return alert('No active department selected.');
            const deptId = deptIdEl.value;

            const payload = {
                timezone: document.getElementById('focusedDeptTimezoneInput') ? document.getElementById('focusedDeptTimezoneInput').value : 'America/New_York',
                auto_away_message: document.getElementById('focusedDeptAwayMessageInput') ? document.getElementById('focusedDeptAwayMessageInput').value : '',
                working_hours: getFocusedDeptWorkingHoursFromForm()
            };

            try {
                const res = await fetch('/v1/departments/' + deptId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeDeptCardEditor();
                } else {
                    alert(data.message || 'Failed to save operating hours.');
                }
            } catch (e) {
                console.error('Error saving operating hours:', e);
                alert('Error saving operating hours.');
            }
        }
        window.saveDeptHoursOnly = saveDeptHoursOnly;

        // 5. ESCALATIONS HELPERS & SAVE
        async function saveDeptEscalationsOnly() {
            const deptIdEl = document.getElementById('escEditorDeptId');
            if (!deptIdEl || !deptIdEl.value) return alert('No active department selected.');
            const deptId = deptIdEl.value;

            const payload = {
                escalation_rules: {
                    notify_email: document.getElementById('focusedEscalateEmailCheck') ? document.getElementById('focusedEscalateEmailCheck').checked : false,
                    notify_whatsapp: document.getElementById('focusedEscalateWhatsappCheck') ? document.getElementById('focusedEscalateWhatsappCheck').checked : false
                },
                lead_assignment_rules: {
                    method: document.getElementById('focusedLeadAssignmentSelect') ? document.getElementById('focusedLeadAssignmentSelect').value : 'round_robin'
                }
            };

            try {
                const res = await fetch('/v1/departments/' + deptId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeDeptCardEditor();
                } else {
                    alert(data.message || 'Failed to save escalation policy.');
                }
            } catch (e) {
                console.error('Error saving escalations:', e);
                alert('Error saving escalation policy.');
            }
        }
        window.saveDeptEscalationsOnly = saveDeptEscalationsOnly;

        function switchDeptSubtab(subtabKey) {
