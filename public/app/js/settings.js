// ═══════════════════════════════════════════════════════════════════
// SETTINGS.JS - Org settings, campus management, team/staff management
// BUG AREAS:
//   Lead-magnet assets    -> loadAssets() / renderAssetsTable() / handleAssetFormSubmit()
//   Asset upload modal    -> openAssetUploadModal() / openAssetEditModal()
//   Asset toggle/delete   -> toggleAssetActive() / deleteAsset()
//   Staff registration    -> registerAndAddStaff() / registerOrgUser()
//   College profile       -> loadCollegeProfile() / handleInstitutionSettingsSubmit()
//   Campus list           -> loadCampuses() / renderCampusesTable()
//   Campus editor         -> initCampusEditorPage() / handleCampusPageSubmit()
//   Campus courses        -> loadCampusEditorCourses() / renderCampusEditorCoursesGroups()
//   Team members          -> loadTeamsWorkspace() / renderTeamsRosterTableRows()
//   Add team member       -> openAddTeamMemberModal() / submitAddTeamMemberModal()
//   Edit team member      -> openEditTeamMemberModalById() / submitEditTeamMemberModal()
//   Remove team user      -> removeTeamUser() / promptResetUserPassword()
// LOADED BY: index.html via <script src="js/settings.js">
// ═══════════════════════════════════════════════════════════════════
        async function loadAssets() {
            if (!token) return;
            try {
                // Ensure departments are loaded so dropdowns work
                if (!currentDepartments || currentDepartments.length === 0) {
                    try {
                        const deptRes = await fetch('/v1/departments', { headers: { 'Authorization': 'Bearer ' + token } });
                        const deptData = await deptRes.json();
                        if (deptData.status === 'success') {
                            currentDepartments = deptData.data.departments || [];
                        }
                    } catch(e) {}
                }

                const res = await fetch('/v1/assets', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    currentAssetsList = data.data.assets || [];
                    if (data.data.user_context) {
                        userAssetContext = data.data.user_context;
                    }

                    // Update staff banner if staff
                    const staffNotice = document.getElementById('staffAssetNotice');
                    const staffNoticeText = document.getElementById('staffAssetNoticeText');
                    if (staffNotice && staffNoticeText) {
                        if (!userAssetContext.is_admin) {
                            staffNotice.style.display = 'block';
                            const assignedDepts = currentDepartments.filter(d => userAssetContext.assigned_dept_ids.includes(d.id));
                            const names = assignedDepts.map(d => `${d.icon || 'ðŸ¢'} ${d.name}`).join(', ');
                            staffNoticeText.innerHTML = `You are managing assets for: <strong>${names || 'your assigned departments'}</strong>. You can also view Org-wide institutional assets.`;
                        } else {
                            staffNotice.style.display = 'none';
                        }
                    }

                    // Update Department Filter Select
                    updateAssetDeptFilterOptions();

                    // Update Stats
                    updateAssetStats();

                    // Render table with current filters
                    filterAssetsList();
                }
            } catch (err) {
                console.error('Error loading assets:', err);
            }
        }

        function updateAssetStats() {
            const total = currentAssetsList.length;
            const orgWide = currentAssetsList.filter(a => a.is_org_wide).length;
            const deptMapped = currentAssetsList.filter(a => !a.is_org_wide).length;
            const totalDownloads = currentAssetsList.reduce((acc, a) => acc + (parseInt(a.downloads_count) || 0), 0);

            const statTotalEl = document.getElementById('statTotalAssets');
            if (statTotalEl) statTotalEl.innerText = total;

            const statOrgEl = document.getElementById('statOrgWideAssets');
            if (statOrgEl) statOrgEl.innerText = orgWide;

            const statDeptEl = document.getElementById('statDeptAssets');
            if (statDeptEl) statDeptEl.innerText = deptMapped;

            const statDlEl = document.getElementById('statTotalDownloads');
            if (statDlEl) statDlEl.innerText = totalDownloads;

            const countBadge = document.getElementById('assetCountBadge');
            if (countBadge) countBadge.innerText = `${total} Asset${total === 1 ? '' : 's'}`;
        }

        function updateAssetDeptFilterOptions() {
            const filterSel = document.getElementById('assetDeptFilter');
            if (!filterSel) return;
            const curVal = filterSel.value;
            let options = `<option value="">ðŸ¢ All Scopes</option><option value="org">ðŸŒ Org-wide Only</option>`;
            
            const deptsToShow = userAssetContext.is_admin 
                ? currentDepartments 
                : currentDepartments.filter(d => userAssetContext.assigned_dept_ids.includes(d.id));

            deptsToShow.forEach(d => {
                options += `<option value="${d.id}">${d.icon || 'ðŸ¢'} ${d.name}</option>`;
            });

            filterSel.innerHTML = options;
            if (curVal) filterSel.value = curVal;
        }

        function filterAssetsList() {
            const query = (document.getElementById('assetSearchInput')?.value || '').toLowerCase().trim();
            const cat = document.getElementById('assetCategoryFilter')?.value || '';
            const dept = document.getElementById('assetDeptFilter')?.value || '';

            let filtered = currentAssetsList.filter(a => {
                // Search query matching
                if (query) {
                    const matchTitle = (a.title || '').toLowerCase().includes(query);
                    const matchDesc = (a.description || '').toLowerCase().includes(query);
                    const matchTrigger = (a.lead_intent_trigger || '').toLowerCase().includes(query);
                    const matchFile = (a.file_name || '').toLowerCase().includes(query);
                    const matchDept = (a.department_name || '').toLowerCase().includes(query);
                    if (!matchTitle && !matchDesc && !matchTrigger && !matchFile && !matchDept) return false;
                }

                // Category filter
                if (cat && a.category !== cat) return false;

                // Department filter
                if (dept === 'org') {
                    if (!a.is_org_wide) return false;
                } else if (dept) {
                    if (a.department_id != dept) return false;
                }

                return true;
            });

            renderAssetsTable(filtered);
        }

        function renderAssetsTable(assets) {
            const tbody = document.getElementById('assetsTableBody');
            if (!tbody) return;

            if (assets.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; color: #648781; padding: 36px 24px;">
                            <div style="font-size: 32px; margin-bottom: 8px;">ðŸ“</div>
                            <strong style="font-size: 14px; color: #092F2E; display: block;">No lead-magnet assets found</strong>
                            <span style="font-size: 12px; color: #648781;">
                                Upload institutional brochures, fee schedules, or placement reports to automatically generate verified admissions leads.
                            </span>
                            <div style="margin-top: 14px;">
                                <button type="button" class="brand-btn-primary brand-btn-sm" onclick="openAssetUploadModal()">âž• Upload New Asset</button>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = assets.map(a => {
                const isOrgWide = a.is_org_wide;
                const deptBadge = isOrgWide
                    ? `<span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: var(--brand-indigo-300); background: rgba(99, 102, 241, 0.12); border: 1px solid rgba(99, 102, 241, 0.3); padding: 2px 8px; border-radius: 12px; white-space: nowrap;">
                        <span>ðŸŒ</span> <span>Org-wide (General)</span>
                       </span>`
                    : `<span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: var(--brand-cyan-400); background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.25); padding: 2px 8px; border-radius: 12px; white-space: nowrap;">
                        <span>${a.department_icon || 'ðŸ¢'}</span> <span>${a.department_name || 'Department'}</span>
                       </span>`;

                const categoryLabels = {
                    brochure: 'ðŸ“„ Brochure / Prospectus',
                    fee_structure: 'ðŸ’° Fee Structure',
                    scholarship_guide: 'ðŸ† Scholarship Matrix',
                    placement_report: 'ðŸ“ˆ Placement Report',
                    curriculum: 'ðŸ“š Syllabus & Curriculum',
                    hostel_guide: 'ðŸ¡ Hostel & Mess',
                    exam_cutoff: 'ðŸŽ¯ Cutoff / Exam Prep',
                    international_guide: 'ðŸŒ International Guide',
                    other: 'ðŸ“‘ Other Document'
                };

                const catLabel = categoryLabels[a.category] || 'ðŸ“‘ Resource';

                const isActive = (a.is_active == 1 || a.is_active === true);
                const statusBadge = isActive
                    ? '<span class="badge" style="background: rgba(52, 211, 153, 0.12); color: var(--brand-emerald-400); border: 1px solid rgba(52, 211, 153, 0.25); white-space: nowrap;">â— Active</span>'
                    : '<span class="badge" style="background: rgba(244, 63, 94, 0.12); color: var(--brand-rose-400); border: 1px solid rgba(244, 63, 94, 0.25); white-space: nowrap;">â—‹ Inactive</span>';

                const canEdit = userAssetContext.is_admin || (a.department_id && userAssetContext.assigned_dept_ids.includes(parseInt(a.department_id)));

                const actionBtns = `
                    <div style="display: flex; align-items: center; justify-content: flex-end; gap: 6px;">
                        <a href="/v1/assets/${a.id}/download" target="_blank" class="brand-btn-secondary brand-btn-sm" style="font-size: 11px; height: 28px; padding: 0 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; color: var(--brand-cyan-400); border-color: rgba(56, 189, 248, 0.3);">
                            <span>ðŸ“¥</span> Download
                        </a>
                        ${canEdit ? `
                            <button type="button" class="brand-btn-secondary brand-btn-sm" style="font-size: 11px; height: 28px; padding: 0 10px; display: inline-flex; align-items: center; gap: 4px;" onclick="openAssetEditModal(${a.id})">
                                <span>âœï¸</span> Edit
                            </button>
                            <button type="button" class="brand-btn-secondary brand-btn-sm" style="font-size: 11px; height: 28px; padding: 0 8px; color: var(--brand-rose-400); border-color: rgba(244, 63, 94, 0.3);" onclick="deleteAsset(${a.id})">
                                <span>ðŸ—‘ï¸</span>
                            </button>
                        ` : `
                            <span style="font-size: 10px; color: #648781; font-style: italic; padding: 0 4px;">ðŸ”’ Read-Only</span>
                        `}
                    </div>
                `;

                const triggerChip = a.lead_intent_trigger
                    ? `<span style="font-size: 11px; font-family: var(--brand-font-mono); color: var(--brand-cyan-400); background: rgba(56, 189, 248, 0.08); padding: 2px 6px; border-radius: 4px; border: 1px dashed rgba(56, 189, 248, 0.25); display: inline-block; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${a.lead_intent_trigger}">âš¡ ${a.lead_intent_trigger}</span>`
                    : `<span style="font-size: 11px; color: #648781; font-style: italic;">Auto-matched</span>`;

                return `
                    <tr>
                        <td style="max-width: 260px;">
                            <div style="font-weight: 700; color: #092F2E; font-size: 13px; margin-bottom: 2px;">
                                ${a.title}
                            </div>
                            ${a.description ? `<div style="font-size: 11px; color: #648781; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${a.description}</div>` : ''}
                        </td>
                        <td>
                            <span style="font-size: 11px; color: #4F7470; white-space: nowrap;">${catLabel}</span>
                        </td>
                        <td>${deptBadge}</td>
                        <td style="font-size: 11px; color: #4F7470; white-space: nowrap;">
                            <div style="font-family: var(--brand-font-mono); color: #092F2E;">${a.file_size_formatted || 'â€”'}</div>
                            <div style="color: #648781; font-size: 10px; max-width: 140px; overflow: hidden; text-overflow: ellipsis;" title="${a.file_name}">${a.file_name}</div>
                        </td>
                        <td>${triggerChip}</td>
                        <td>
                            ${canEdit ? `
                                <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px;" title="Toggle AI Bot Delivery">
                                    <input type="checkbox" ${isActive ? 'checked' : ''} onchange="toggleAssetActive(${a.id}, this.checked)" style="accent-color: var(--brand-emerald-400);" />
                                    ${statusBadge}
                                </label>
                            ` : statusBadge}
                        </td>
                        <td style="font-weight: 700; font-family: var(--brand-font-mono); font-size: 12px; color: var(--brand-indigo-300);">
                            ${a.downloads_count || 0}
                        </td>
                        <td style="text-align: right;">${actionBtns}</td>
                    </tr>
                `;
            }).join('');
        }

        function populateAssetDeptDropdown(selectedDeptId = null) {
            const wrapper = document.getElementById('assetFormDeptWrapper');
            const lockedBadge = document.getElementById('assetFormDeptLockedBadge');
            if (!wrapper) return;

            if (userAssetContext.is_admin) {
                if (lockedBadge) lockedBadge.style.display = 'none';
                let html = `<select id="assetFormDept" class="brand-input" style="height: 38px; background-color: #FFFFFF; color: #063D3B; border: 1px solid #D1E5DE;">
                    <option value="org" ${!selectedDeptId ? 'selected' : ''}>ðŸŒ Org-wide (All Departments / General)</option>`;
                
                currentDepartments.forEach(d => {
                    html += `<option value="${d.id}" ${selectedDeptId == d.id ? 'selected' : ''}>${d.icon || 'ðŸ¢'} ${d.name}</option>`;
                });
                html += `</select>`;
                wrapper.innerHTML = html;
            } else {
                // Staff member: check assigned departments
                const assigned = currentDepartments.filter(d => userAssetContext.assigned_dept_ids.includes(d.id));
                if (assigned.length === 1) {
                    // Only 1 department assigned: lock to this department
                    const singleDept = assigned[0];
                    wrapper.innerHTML = `
                        <select id="assetFormDept" class="brand-input" style="height: 38px; background-color: #FFFFFF; color: #063D3B; border: 1px solid #D1E5DE;">
                            <option value="${singleDept.id}" selected>${singleDept.icon || 'ðŸ¢'} ${singleDept.name}</option>
                        </select>
                    `;
                    if (lockedBadge) {
                        lockedBadge.innerText = `ðŸ”’ Scoped to assigned: ${singleDept.name}`;
                        lockedBadge.style.display = 'block';
                    }
                } else if (assigned.length > 1) {
                    // Multiple assigned departments
                    let html = `<select id="assetFormDept" class="brand-input" style="height: 38px; background-color: #FFFFFF; color: #063D3B; border: 1px solid #D1E5DE;">`;
                    assigned.forEach(d => {
                        html += `<option value="${d.id}" ${selectedDeptId == d.id ? 'selected' : ''}>${d.icon || 'ðŸ¢'} ${d.name}</option>`;
                    });
                    html += `</select>`;
                    wrapper.innerHTML = html;
                    if (lockedBadge) {
                        lockedBadge.innerText = `ðŸ”’ Limited to your ${assigned.length} assigned departments`;
                        lockedBadge.style.display = 'block';
                    }
                } else {
                    wrapper.innerHTML = `<select id="assetFormDept" class="brand-input" disabled style="height: 38px;"><option value="">No Assigned Department</option></select>`;
                    if (lockedBadge) {
                        lockedBadge.innerText = 'âš ï¸ Please contact Admin to assign you to a department first.';
                        lockedBadge.style.display = 'block';
                    }
                }
            }
        }

        function openAssetUploadModal(prefillData = null) {
            activeEditingAssetId = null;
            document.getElementById('editAssetId').value = '';
            document.getElementById('assetModalTitle').innerText = 'Upload Lead-Magnet Document';
            document.getElementById('assetFormSubmitBtn').innerText = 'Deploy Asset';
            
            const fileGroup = document.getElementById('assetFormFileGroup');
            if (fileGroup) fileGroup.style.display = 'block';
            const fileInput = document.getElementById('assetFormFile');
            if (fileInput) fileInput.value = '';

            document.getElementById('assetFormTitle').value = prefillData?.title || '';
            document.getElementById('assetFormCategory').value = prefillData?.category || 'brochure';
            document.getElementById('assetFormTrigger').value = prefillData?.trigger || '';
            document.getElementById('assetFormDesc').value = prefillData?.desc || '';
            document.getElementById('assetFormIsActive').checked = true;

            populateAssetDeptDropdown(prefillData?.department_id || null);

            document.getElementById('assetUploadModal').style.display = 'flex';
        }

        function openAssetEditModal(assetId) {
            const asset = currentAssetsList.find(a => a.id == assetId);
            if (!asset) return;

            activeEditingAssetId = asset.id;
            document.getElementById('editAssetId').value = asset.id;
            document.getElementById('assetModalTitle').innerText = 'Edit Asset Details';
            document.getElementById('assetFormSubmitBtn').innerText = 'Save Changes';

            // Hide file input during metadata edit
            const fileGroup = document.getElementById('assetFormFileGroup');
            if (fileGroup) fileGroup.style.display = 'none';

            document.getElementById('assetFormTitle').value = asset.title || '';
            document.getElementById('assetFormCategory').value = asset.category || 'brochure';
            document.getElementById('assetFormTrigger').value = asset.lead_intent_trigger || '';
            document.getElementById('assetFormDesc').value = asset.description || '';
            document.getElementById('assetFormIsActive').checked = (asset.is_active == 1 || asset.is_active === true);

            populateAssetDeptDropdown(asset.department_id || null);

            document.getElementById('assetUploadModal').style.display = 'flex';
        }

        function closeAssetModal() {
            document.getElementById('assetUploadModal').style.display = 'none';
            activeEditingAssetId = null;
        }

        function applyAssetBlueprint(blueprintKey) {
            const bp = ASSET_BLUEPRINT_CONFIGS[blueprintKey];
            if (!bp) return;
            openAssetUploadModal(bp);
            showToast(`Loaded blueprint: "${bp.title}". Attach your file to deploy.`, 'info');
        }

        async function handleAssetFormSubmit(e) {
            if (e) e.preventDefault();
            const submitBtn = document.getElementById('assetFormSubmitBtn');
            const originalText = submitBtn.innerText;
            submitBtn.innerText = 'Processing...';
            submitBtn.disabled = true;

            const editId = document.getElementById('editAssetId').value;
            const title = document.getElementById('assetFormTitle').value.trim();
            const category = document.getElementById('assetFormCategory').value;
            const deptVal = document.getElementById('assetFormDept')?.value || 'org';
            const lead_intent_trigger = document.getElementById('assetFormTrigger').value.trim();
            const description = document.getElementById('assetFormDesc').value.trim();
            const is_active = document.getElementById('assetFormIsActive').checked ? 1 : 0;
            const department_id = (deptVal && deptVal !== 'org' && deptVal !== '0') ? parseInt(deptVal) : null;

            try {
                if (editId) {
                    // Update Metadata (PUT)
                    const res = await fetch('/v1/assets/' + editId, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + token
                        },
                        body: JSON.stringify({
                            title, category, department_id, lead_intent_trigger, description, is_active
                        })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        closeAssetModal();
                        showToast('âœ“ Asset details updated successfully!', 'success');
                        await loadAssets();
                    } else {
                        alert(data.message || 'Failed to update asset.');
                    }
                } else {
                    // Upload New File (POST FormData)
                    const fileInput = document.getElementById('assetFormFile');
                    if (!fileInput.files || fileInput.files.length === 0) {
                        alert('Please select a document file (.pdf, .docx, etc.) to upload.');
                        submitBtn.innerText = originalText;
                        submitBtn.disabled = false;
                        return;
                    }

                    const formData = new FormData();
                    formData.append('file', fileInput.files[0]);
                    formData.append('title', title);
                    formData.append('category', category);
                    if (department_id) formData.append('department_id', department_id);
                    formData.append('lead_intent_trigger', lead_intent_trigger);
                    formData.append('description', description);

                    const res = await fetch('/v1/assets/upload', {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer ' + token
                        },
                        body: formData
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        closeAssetModal();
                        showToast('ðŸŽ‰ Lead-magnet asset deployed successfully!', 'success');
                        await loadAssets();
                    } else {
                        alert(data.message || 'Failed to upload asset.');
                    }
                }
            } catch (err) {
                console.error('Error in asset form submission:', err);
                alert('Connection error while saving asset.');
            } finally {
                submitBtn.innerText = originalText;
                submitBtn.disabled = false;
            }
        }

        async function toggleAssetActive(assetId, isActive) {
            if (!token || !assetId) return;
            try {
                const res = await fetch('/v1/assets/' + assetId, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ is_active: isActive ? 1 : 0 })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(isActive ? 'Asset activated for chatbot delivery.' : 'Asset deactivated.', 'info');
                    await loadAssets();
                } else {
                    alert(data.message || 'Failed to toggle status.');
                }
            } catch (err) {
                console.error(err);
            }
        }

        async function deleteAsset(assetId) {
            if (!confirm('Are you sure you want to delete this lead-magnet asset? The file will be removed from the server.')) return;
            try {
                const res = await fetch('/v1/assets/' + assetId, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast('Asset deleted successfully.', 'success');
                    await loadAssets();
                } else {
                    alert(data.message || 'Failed to delete asset.');
                }
            } catch (err) {
                console.error(err);
                alert('Error deleting asset.');
            }
        }

        async function refreshDeptKnowledgeSources(deptId) {
            await loadDepartments();
            const updatedDept = currentDepartments.find(d => d.id == deptId);
            if (updatedDept) {
                renderDeptKsList(updatedDept.knowledge_sources || []);
            }
        }

        function renderDeptStaffChecklist(assignedUserIds) {
            const container = document.getElementById('deptStaffChecklist');
            if (availableOrgStaff.length === 0) {
                container.innerHTML = `<div style="font-size: 11px; color: #648781;">No staff users registered.</div>`;
                return;
            }

            container.innerHTML = availableOrgStaff.map(u => `
                <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #092F2E; cursor: pointer;">
                    <input type="checkbox" class="dept-staff-checkbox" value="${u.id}" ${assignedUserIds.includes(u.id) ? 'checked' : ''} />
                    <span>ðŸ‘¤ ${u.name} <small style="color: #648781;">(${u.email} - ${u.role})</small></span>
                </label>
            `).join('');
        }

        function renderFaqs(faqs) {
            const container = document.getElementById('deptFaqsContainer');
            if (!container) return;
            container.innerHTML = '';
            if (!faqs || faqs.length === 0) {
                addFaqRow();
                return;
            }
            faqs.forEach(f => addFaqRow(f.question, f.answer));
        }

        function addFaqRow(q = '', a = '') {
            const container = document.getElementById('deptFaqsContainer');
            if (!container) return;
            const row = document.createElement('div');
            row.className = 'faq-row';
            row.style.cssText = 'display: grid; grid-template-columns: 1fr 1fr 30px; gap: 8px; align-items: center;';
            row.innerHTML = `
                <input type="text" class="brand-input faq-q" placeholder="Question chip..." value="${escapeHtml(q || '')}" />
                <input type="text" class="brand-input faq-a" placeholder="Preset Answer..." value="${escapeHtml(a || '')}" />
                <button type="button" class="brand-btn-secondary brand-btn-sm" style="color: var(--brand-rose-400);" onclick="this.parentElement.remove()">âœ•</button>
            `;
            container.appendChild(row);
        }
        window.addFaqRow = addFaqRow;
        window.renderFaqs = renderFaqs;

        function renderDeptCourses(courses) {
            const container = document.getElementById('deptCoursesContainer');
            if (!container) return;
            container.innerHTML = '';
            if (!courses || courses.length === 0) {
                addCourseRow();
                return;
            }
            courses.forEach(c => addCourseRow(c.id, c.course_name, c.course_code, c.campus_ids || []));
        }

        function addCourseRow(id = null, name = '', code = '', mappedCampusIds = []) {
            const container = document.getElementById('deptCoursesContainer');
            if (!container) return;

            const campuses = availableOrgCampuses || [];
            let campusChipsHtml = '';
            if (campuses.length > 0) {
                campusChipsHtml = campuses.map(c => {
                    const isChecked = Array.isArray(mappedCampusIds) && mappedCampusIds.includes(c.id);
                    return `
                        <label style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: #063D3B; cursor: pointer; background: #FFFFFF; border: 1.5px solid ${isChecked ? '#10B981' : '#D1E5DE'}; border-radius: 6px; padding: 3px 8px; user-select: none;">
                            <input type="checkbox" class="course-campus-check" value="${c.id}" ${isChecked ? 'checked' : ''} onchange="this.parentElement.style.borderColor = this.checked ? '#10B981' : '#D1E5DE'" style="width: 13px; height: 13px; accent-color: #047857; cursor: pointer;" />
                            <span>${escapeHtml(c.short_name || c.name)}</span>
                        </label>
                    `;
                }).join('');
            } else {
                campusChipsHtml = `<span style="color: #94A3B8; font-style: italic; font-size: 10.5px;">No campuses registered. Configure campuses in the Campuses tab to map locations.</span>`;
            }

            const row = document.createElement('div');
            row.className = 'course-row';
            row.style.cssText = 'background: #F8FCFA; border: 1.5px solid #DCE9E5; border-radius: 8px; padding: 10px 12px; display: flex; flex-direction: column; gap: 8px;';
            row.innerHTML = `
                <div style="display: grid; grid-template-columns: 2fr 1fr 30px; gap: 8px; align-items: center;">
                    <input type="hidden" class="course-id" value="${escapeHtml(String(id || ''))}" />
                    <input type="text" class="brand-input course-name" placeholder="Course / Program Name (e.g. Master of Business Administration)..." value="${escapeHtml(name || '')}" style="height: 34px; font-size: 12px;" />
                    <input type="text" class="brand-input course-code" placeholder="Code (e.g. MBA-01)..." value="${escapeHtml(code || '')}" style="height: 34px; font-size: 12px;" />
                    <button type="button" class="brand-btn-secondary brand-btn-sm" style="color: var(--brand-rose-400); height: 32px; font-size: 12px;" onclick="this.closest('.course-row').remove()" title="Remove Course">âœ•</button>
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
        window.addCourseRow = addCourseRow;
        window.renderDeptCourses = renderDeptCourses;

        // Department Form Submit Handler
        async function handleDeptFormSubmit(e) {
            if (e) e.preventDefault();
            const editIdEl = document.getElementById('editDeptId');
            if (!editIdEl) return;
            const deptId = editIdEl.value;
            const payload = {
                icon: document.getElementById('deptIconInput') ? document.getElementById('deptIconInput').value : '',
                name: document.getElementById('deptNameInput') ? document.getElementById('deptNameInput').value : '',
                description: document.getElementById('deptDescInput') ? document.getElementById('deptDescInput').value : '',
                email: document.getElementById('deptEmailInput') ? document.getElementById('deptEmailInput').value : '',
                phone: document.getElementById('deptPhoneInput') ? document.getElementById('deptPhoneInput').value : '',
                whatsapp: document.getElementById('deptWhatsappInput') ? document.getElementById('deptWhatsappInput').value : '',
                greeting_message: document.getElementById('deptGreetingInput') ? document.getElementById('deptGreetingInput').value : '',
                timezone: document.getElementById('deptTimezoneInput') ? document.getElementById('deptTimezoneInput').value : 'America/New_York',
                auto_away_message: document.getElementById('deptAwayMessageInput') ? document.getElementById('deptAwayMessageInput').value : '',
                working_hours: typeof getDeptWorkingHoursFromForm === 'function' ? getDeptWorkingHoursFromForm() : {},
                is_active: (document.getElementById('deptIsActiveInput') && document.getElementById('deptIsActiveInput').checked) ? 1 : 0,
                enable_dedicated_widget: (document.getElementById('deptEnableDedicatedWidgetInput') && document.getElementById('deptEnableDedicatedWidgetInput').checked) ? 1 : 0,
                escalation_rules: {
                    notify_email: document.getElementById('escalateEmailCheck') ? document.getElementById('escalateEmailCheck').checked : false,
                    notify_whatsapp: document.getElementById('escalateWhatsappCheck') ? document.getElementById('escalateWhatsappCheck').checked : false
                },
                lead_assignment_rules: {
                    method: document.getElementById('leadAssignmentSelect') ? document.getElementById('leadAssignmentSelect').value : 'round_robin'
                }
            };

            try {
                let currentDeptId = deptId;
                if (deptId) {
                    await fetch('/v1/departments/' + deptId, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                        body: JSON.stringify(payload)
                    });
                } else {
                    const res = await fetch('/v1/departments', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                        body: JSON.stringify(payload)
                    });
                    const resData = await res.json();
                    if (resData.status === 'success') {
                        currentDeptId = resData.data.id;
                    }
                }

                if (currentDeptId) {
                    // Sync Staff
                    const selectedStaff = Array.from(document.querySelectorAll('.dept-staff-checkbox:checked')).map(cb => ({
                        user_id: parseInt(cb.value),
                        role: 'agent',
                        is_on_duty: 1
                    }));
                    await fetch(`/v1/departments/${currentDeptId}/staff`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                        body: JSON.stringify({ staff: selectedStaff })
                    });

                    // Sync FAQs
                    const faqs = [];
                    document.querySelectorAll('.faq-row').forEach(row => {
                        const q = row.querySelector('.faq-q') ? row.querySelector('.faq-q').value.trim() : '';
                        const a = row.querySelector('.faq-a') ? row.querySelector('.faq-a').value.trim() : '';
                        if (q && a) faqs.push({ question: q, answer: a });
                    });
                    await fetch(`/v1/departments/${currentDeptId}/faqs`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                        body: JSON.stringify({ faqs })
                    });

                    // Sync Courses with campus mappings
                    const courses = [];
                    document.querySelectorAll('.course-row').forEach(row => {
                        const idVal = row.querySelector('.course-id') ? row.querySelector('.course-id').value.trim() : '';
                        const name = row.querySelector('.course-name') ? row.querySelector('.course-name').value.trim() : '';
                        const code = row.querySelector('.course-code') ? row.querySelector('.course-code').value.trim() : '';
                        const campusIds = Array.from(row.querySelectorAll('.course-campus-check:checked')).map(cb => parseInt(cb.value));
                        if (name) {
                            courses.push({
                                id: idVal ? parseInt(idVal) : null,
                                course_name: name,
                                course_code: code,
                                campus_ids: campusIds
                            });
                        }
                    });
                    await fetch(`/v1/departments/${currentDeptId}/courses`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                        body: JSON.stringify({ courses })
                    });
                }

                closeDepartmentModal();
                loadDepartments();
            } catch (err) {
                console.error(err);
                alert('Failed to save department configuration.');
            }
        }

        document.addEventListener('submit', function(e) {
            if (e.target && e.target.id === 'deptForm') {
                e.preventDefault();
                handleDeptFormSubmit(e);
            }
        });

        async function registerAndAddStaff() {
            const name = document.getElementById('newStaffName').value.trim();
            const email = document.getElementById('newStaffEmail').value.trim();
            const password = document.getElementById('newStaffPassword').value;
            const role = document.getElementById('newStaffRole').value;
            const deptId = document.getElementById('editDeptId').value;

            if (!name || !email || !password) {
                alert('Please provide staff member Name, Email, and Password.');
                return;
            }

            try {
                const res = await fetch('/v1/organization/staff', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ name, email, password, role, department_id: deptId || null })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('newStaffName').value = '';
                    document.getElementById('newStaffEmail').value = '';
                    document.getElementById('newStaffPassword').value = '';
                    
                    // Reload org staff list & refresh checklist UI
                    if (deptId) {
                        const resDept = await fetch('/v1/departments', {
                            headers: { 'Authorization': 'Bearer ' + token }
                        });
                        const dData = await resDept.json();
                        if (dData.status === 'success') {
                            availableOrgStaff = dData.meta?.available_staff || [];
                            const activeDept = dData.data.find(d => d.id == deptId);
                            renderDeptStaffChecklist(availableOrgStaff, activeDept ? activeDept.staff : []);
                        }
                    } else {
                        alert('Staff member registered successfully!');
                    }
                } else {
                    alert(data.message || 'Failed to register staff member.');
                }
            } catch (err) {
                console.error(err);
                alert('Error registering staff member.');
            }
        }

        async function registerOrgUser() {
            const name = document.getElementById('orgAdminName').value.trim();
            const email = document.getElementById('orgAdminEmail').value.trim();
            const password = document.getElementById('orgAdminPassword').value;
            const role = document.getElementById('orgAdminRole').value;

            if (!name || !email || !password) {
                alert('Please fill in Name, Email, and Password.');
                return;
            }

            try {
                const res = await fetch('/v1/organization/staff', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ name, email, password, role })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert(`User account for ${name} (${role === 'admin' ? 'Administrator' : 'Staff'}) created successfully!`);
                    document.getElementById('orgAdminName').value = '';
                    document.getElementById('orgAdminEmail').value = '';
                    document.getElementById('orgAdminPassword').value = '';
                } else {
                    alert(data.message || 'Failed to create user account.');
                }
            } catch (err) {
                console.error(err);
                alert('Error creating user account.');
            }
        }

        // â”€â”€ COLLEGE PROFILE LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        async function loadCollegeProfile() {
            if (!token) return;
            try {
                const res = await fetch('/v1/organization/profile', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    document.getElementById('orgNameInput').value = data.data.name || '';
                    document.getElementById('orgColorInput').value = data.data.primary_color || '#2563EB';
                    const userCollegeEl = document.getElementById('userCollege');
                    if (userCollegeEl && data.data.name) {
                        userCollegeEl.innerText = data.data.name;
                    }
                }
            } catch (err) {
                console.error('Error loading college profile:', err);
            }
        }

        const orgProfileFormEl = document.getElementById('orgProfileForm');
        if (orgProfileFormEl) {
            orgProfileFormEl.onsubmit = async (e) => {
                e.preventDefault();
                const name = document.getElementById('orgNameInput').value.trim();
                const primary_color = document.getElementById('orgColorInput').value;

                if (!name) {
                    alert('College / University name is required.');
                    return;
                }

                try {
                    const res = await fetch('/v1/organization/profile', {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                        body: JSON.stringify({ name, primary_color })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        const userCollegeEl = document.getElementById('userCollege');
                        if (userCollegeEl) userCollegeEl.innerText = name;
                        alert('ðŸŽ‰ Success! College Profile updated successfully.');
                    } else {
                        alert(data.message || 'Failed to update profile.');
                    }
                } catch (err) {
                    console.error(err);
                    alert('Error updating college profile.');
                }
            };
        }

        // â”€â”€ INSTITUTION SETTINGS LOGIC (#settings) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        async function loadSettingsData() {
            if (!token) return;
            try {
                const res = await fetch('/v1/organization/profile', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    const org = data.data;
                    window.currentOrgProfile = org;
                    window.currentOrgName = org.name || '';
                    window.currentOrgWebsite = org.website_url || '';

                    const setVal = (id, val) => {
                        const el = document.getElementById(id);
                        if (el) el.value = val || '';
                    };

                    setVal('settings_name', org.name);
                    setVal('settings_short_name', org.short_name);
                    setVal('settings_website_url', org.website_url);
                    setVal('settings_founded_year', org.founded_year);
                    setVal('settings_institution_type', org.institution_type);
                    setVal('settings_institution_category', org.institution_category);
                    setVal('settings_academic_year', org.academic_year || 'Fall 2026');
                    setVal('settings_address_line', org.address_line);
                    setVal('settings_city', org.city);
                    setVal('settings_state', org.state);
                    setVal('settings_country', org.country || 'India');
                    setVal('settings_pincode', org.pincode);
                    setVal('settings_institution_description', org.institution_description);

                    const topbarEl = document.getElementById('topbarOrgName');
                    if (topbarEl && org.name) topbarEl.textContent = org.name;
                    const userCollegeEl = document.getElementById('userCollege');
                    if (userCollegeEl && org.name) userCollegeEl.innerText = org.name;
                }
            } catch (err) {
                console.error('Error loading institution settings:', err);
            }
        }
        window.loadSettingsData = loadSettingsData;

        async function handleInstitutionSettingsSubmit(e) {
            if (e) e.preventDefault();
            if (!token) return;

            const btn = document.getElementById('btnSaveSettings');
            const origText = btn ? btn.innerHTML : 'Save Institution Settings';
            if (btn) { btn.innerHTML = '<span>â³</span> Saving...'; btn.disabled = true; }

            const getVal = (id) => {
                const el = document.getElementById(id);
                return el ? el.value.trim() : '';
            };

            const payload = {
                name: getVal('settings_name'),
                short_name: getVal('settings_short_name'),
                website_url: getVal('settings_website_url'),
                founded_year: getVal('settings_founded_year'),
                institution_type: getVal('settings_institution_type'),
                institution_category: getVal('settings_institution_category'),
                academic_year: getVal('settings_academic_year'),
                primary_color: (window.currentOrgProfile && window.currentOrgProfile.primary_color) ? window.currentOrgProfile.primary_color : '#2563EB',
                address_line: document.getElementById('settings_address_line') ? getVal('settings_address_line') : ((window.currentOrgProfile && window.currentOrgProfile.address_line) || ''),
                city: document.getElementById('settings_city') ? getVal('settings_city') : ((window.currentOrgProfile && window.currentOrgProfile.city) || ''),
                state: document.getElementById('settings_state') ? getVal('settings_state') : ((window.currentOrgProfile && window.currentOrgProfile.state) || ''),
                country: document.getElementById('settings_country') ? (getVal('settings_country') || 'India') : ((window.currentOrgProfile && window.currentOrgProfile.country) || 'India'),
                pincode: document.getElementById('settings_pincode') ? getVal('settings_pincode') : ((window.currentOrgProfile && window.currentOrgProfile.pincode) || ''),
                institution_description: getVal('settings_institution_description')
            };

            if (!payload.name) {
                if (typeof showToast === 'function') showToast('College / University name is required.', 'error');
                else alert('College / University name is required.');
                if (btn) { btn.innerHTML = origText; btn.disabled = false; }
                return;
            }

            try {
                const res = await fetch('/v1/organization/profile', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    window.currentOrgProfile = { ...(window.currentOrgProfile || {}), ...payload };
                    window.currentOrgName = payload.name;
                    window.currentOrgWebsite = payload.website_url;

                    const topbarEl = document.getElementById('topbarOrgName');
                    if (topbarEl) {
                        topbarEl.textContent = payload.name;
                        topbarEl.style.cursor = 'default';
                        topbarEl.onclick = null;
                    }
                    const bannerEl = document.getElementById('missingOrgNameBanner');
                    if (bannerEl) bannerEl.style.display = 'none';

                    const userCollegeEl = document.getElementById('userCollege');
                    if (userCollegeEl) userCollegeEl.innerText = payload.name;

                    // Sync widget preview if active
                    if (typeof applyWcToPreview === 'function' && window.wcState && window.wcState.config) {
                        applyWcToPreview(window.wcState.config);
                    }

                    if (typeof showToast === 'function') showToast('Institution settings saved successfully! âœ“', 'success');
                    else alert('Institution settings saved successfully!');
                } else {
                    if (typeof showToast === 'function') showToast(data.message || 'Failed to save settings.', 'error');
                    else alert(data.message || 'Failed to save settings.');
                }
            } catch (err) {
                console.error('Error saving institution settings:', err);
                if (typeof showToast === 'function') showToast('Network error saving settings.', 'error');
                else alert('Network error saving settings.');
            } finally {
                if (btn) { btn.innerHTML = origText; btn.disabled = false; }
            }
        }
        window.handleInstitutionSettingsSubmit = handleInstitutionSettingsSubmit;

        // â”€â”€ ðŸ« CAMPUSES MANAGEMENT WORKSPACE LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        let _campusesCache = [];
        let _campusCanManage = false;

        async function loadCampuses() {
            if (!token) return;

            const tbody = document.getElementById('campusesTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" style="padding: 36px 18px; text-align: center; color: #648781;">
                            <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                <span style="font-size: 24px;">â³</span>
                                <span style="font-weight: 600;">Fetching institutional campuses...</span>
                            </div>
                        </td>
                    </tr>
                `;
            }

            try {
                const res = await fetch('/v1/campuses', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const json = await res.json();

                if (json.status === 'success' && json.data) {
                    _campusesCache = json.data.campuses || [];
                    
                    // Determine manage privilege: either server response can_manage or client-side role check
                    const user = window.currentUser || {};
                    const isClientFullAdmin = user.role === 'owner' || user.role === 'superadmin' || 
                        ((user.role === 'admin' || user.role === 'org_admin') && (user.can_manage_structure == 1 || user.can_manage_structure === undefined));
                    
                    _campusCanManage = (json.data.can_manage !== undefined) ? !!json.data.can_manage : isClientFullAdmin;

                    // Update Top Add Button and Read-Only Banner
                    const btnAddTop = document.getElementById('btnAddCampusTop');
                    if (btnAddTop) {
                        btnAddTop.style.display = _campusCanManage ? 'inline-flex' : 'none';
                    }

                    const readOnlyNotice = document.getElementById('campusReadOnlyNotice');
                    if (readOnlyNotice) {
                        readOnlyNotice.style.display = _campusCanManage ? 'none' : 'flex';
                    }

                    // Update KPI Strip
                    updateCampusKpis(_campusesCache);

                    // Render Table
                    renderCampusesTable(_campusesCache);
                } else {
                    if (tbody) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="7" style="padding: 30px 18px; text-align: center; color: #EF4444; font-weight: 600;">
                                    âš ï¸ ${escapeHtmlString(json.message || 'Failed to load campuses.')}
                                </td>
                            </tr>
                        `;
                    }
                }
            } catch (err) {
                console.error('[Edvora Campuses] Load error:', err);
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="7" style="padding: 30px 18px; text-align: center; color: #EF4444; font-weight: 600;">
                                âš ï¸ Network connection error loading campuses.
                            </td>
                        </tr>
                    `;
                }
            }
        }
        window.loadCampuses = loadCampuses;

        function updateCampusKpis(campuses) {
            const total = campuses.length;
            const primary = campuses.find(c => c.is_primary == 1);
            const hostels = campuses.filter(c => c.has_hostel == 1).length;
            const virtualTours = campuses.filter(c => c.virtual_tour_url && c.virtual_tour_url.trim().length > 0).length;

            const setTxt = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.innerText = val;
            };

            setTxt('kpiTotalCampuses', total);
            setTxt('kpiPrimaryCampusName', primary ? (primary.name || 'Main Campus') : 'None Designated');
            setTxt('kpiHostelCampuses', hostels);
            setTxt('kpiVirtualTours', virtualTours);
            setTxt('campusTotalBadgeCount', `Total Campuses: ${total}`);
            setTxt('campusTableSubCount', `${total} Location${total === 1 ? '' : 's'} Listed`);
        }

        function renderCampusesTable(campuses) {
            const tbody = document.getElementById('campusesTableBody');
            if (!tbody) return;

            if (!campuses || campuses.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="padding: 40px 18px; text-align: center; color: #648781;">
                            <div style="font-size: 28px; margin-bottom: 8px;">ðŸ«</div>
                            <div style="font-weight: 700; color: #063D3B; font-size: 13px;">No campuses configured yet.</div>
                            <div style="font-size: 11.5px; margin-top: 4px;">Click <strong>+ Add Campus</strong> above to register your primary campus grounds.</div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = campuses.map(c => {
                const isPrimary = c.is_primary == 1;
                const hasHostel = c.has_hostel == 1;
                const status = c.status || 'active';
                const area = c.campus_area ? escapeHtmlString(c.campus_area) : '<span style="color: #94A3B8; font-style: italic;">Not specified</span>';
                const cityState = [c.city, c.state].filter(Boolean).join(', ') || (c.country || 'India');
                const hasTour = c.virtual_tour_url && c.virtual_tour_url.trim().length > 0;
                const coursesCount = c.courses_count || 0;
                const derivedDepts = c.derived_departments || [];
                const deptsStr = derivedDepts.map(d => d.name).join(', ');

                let actionButtonsHtml = '';
                if (_campusCanManage) {
                    actionButtonsHtml = `
                        <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 6px;">
                            <button type="button" onclick="navigateToCampusEditor(${c.id})" class="brand-btn-secondary" style="height: 28px; padding: 0 10px; font-size: 11px; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;" title="Edit Campus Information">
                                âœï¸ Edit
                            </button>
                            <button type="button" onclick="handleDeleteCampus(${c.id}, '${escapeJsString(c.name)}')" class="brand-btn-secondary" style="height: 28px; padding: 0 9px; font-size: 11px; font-weight: 700; border-radius: 6px; color: #DC2626; border-color: #FECACA; background: #FEF2F2;" title="Delete Campus">
                                ðŸ—‘ï¸
                            </button>
                        </div>
                    `;
                } else {
                    actionButtonsHtml = `
                        <div style="display: inline-flex; align-items: center; justify-content: flex-end;">
                            <button type="button" onclick="openViewCampusModal(${c.id})" class="brand-btn-secondary" style="height: 28px; padding: 0 12px; font-size: 11px; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;" title="View Campus Details">
                                ðŸ‘ï¸ View Details
                            </button>
                        </div>
                    `;
                }

                return `
                    <tr style="border-bottom: 1px solid #E6F0EC; transition: background 0.15s ease;" onmouseover="this.style.background='#FBFDFD'" onmouseout="this.style.background='transparent'">
                        <td style="padding: 14px 18px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 34px; height: 34px; border-radius: 8px; background: ${isPrimary ? 'linear-gradient(135deg, #10B981 0%, #047857 100%)' : '#F4FAF7'}; color: ${isPrimary ? '#FFFFFF' : '#047857'}; border: 1.5px solid ${isPrimary ? 'transparent' : '#D1E5DE'}; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 800; flex-shrink: 0;">
                                    ${isPrimary ? 'â­' : 'ðŸ›ï¸'}
                                </div>
                                <div>
                                    <div style="font-weight: 800; color: #063D3B; font-size: 13px; display: flex; align-items: center; gap: 6px;">
                                        <span>${escapeHtmlString(c.name)}</span>
                                        ${c.short_name ? `<span style="font-size: 10px; font-weight: 700; color: #648781; background: #F4FAF7; padding: 2px 6px; border-radius: 4px; border: 1px solid #D1E5DE;">${escapeHtmlString(c.short_name)}</span>` : ''}
                                    </div>
                                    <div style="font-size: 11px; color: #648781; margin-top: 2px;">
                                        ${c.contact_email ? `âœ‰ï¸ ${escapeHtmlString(c.contact_email)}` : ''}
                                        ${c.contact_phone ? ` â€¢ ðŸ“ž ${escapeHtmlString(c.contact_phone)}` : ''}
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td style="padding: 14px 16px;">
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                ${isPrimary 
                                    ? `<span class="ckh-status-pill" style="background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; font-size: 10.5px; font-weight: 700; align-self: flex-start;">â­ Primary Flagship</span>` 
                                    : `<span class="ckh-status-pill" style="background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; font-size: 10.5px; font-weight: 700; align-self: flex-start;">Branch Campus</span>`
                                }
                                <span style="font-size: 10.5px; color: ${status === 'active' ? '#059669' : '#94A3B8'}; font-weight: 600;">
                                    â— ${status === 'active' ? 'Operational' : 'Inactive'}
                                </span>
                            </div>
                        </td>

                        <td style="padding: 14px 16px;">
                            <div style="display: flex; flex-direction: column; gap: 3px;">
                                <span style="font-weight: 700; color: #047857; font-size: 11.5px;">
                                    ðŸŽ“ ${coursesCount} Program${coursesCount === 1 ? '' : 's'}
                                </span>
                                <span style="font-size: 10.5px; color: #648781; max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtmlString(deptsStr || 'No departments derived')}">
                                    ðŸ›ï¸ ${deptsStr ? escapeHtmlString(deptsStr) : '<span style="color:#94A3B8; font-style:italic;">No depts</span>'}
                                </span>
                            </div>
                        </td>

                        <td style="padding: 14px 16px; font-weight: 700; color: #063D3B;">
                            ${area}
                        </td>

                        <td style="padding: 14px 16px;">
                            ${hasHostel 
                                ? `<span class="ckh-status-pill" style="background: #F5F3FF; color: #7C3AED; border: 1px solid #DDD6FE; font-size: 10.5px; font-weight: 700;">ðŸ›ï¸ Residential Hostels</span>` 
                                : `<span style="color: #94A3B8; font-size: 11px; font-style: italic;">Day Scholar Only</span>`
                            }
                        </td>

                        <td style="padding: 14px 16px; color: #334155;">
                            <div style="font-weight: 600;">${escapeHtmlString(cityState)}</div>
                            ${c.address_line ? `<div style="font-size: 10.5px; color: #648781; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtmlString(c.address_line)}</div>` : ''}
                        </td>

                        <td style="padding: 14px 16px;">
                            ${hasTour 
                                ? `<a href="${escapeHtmlString(c.virtual_tour_url)}" target="_blank" class="org-ref-chip org-ref-chip-blue" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px;" title="Open Virtual 3D Tour">
                                    ðŸŒ View 3D Tour &UpperRightArrow;
                                   </a>` 
                                : `<span style="color: #94A3B8; font-size: 11px; font-style: italic;">No link</span>`
                            }
                        </td>

                        <td style="padding: 14px 18px; text-align: right;">
                            ${actionButtonsHtml}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function filterCampusesList() {
            const query = (document.getElementById('campusSearchInput')?.value || '').toLowerCase().trim();
            if (!query) {
                renderCampusesTable(_campusesCache);
                return;
            }

            const filtered = _campusesCache.filter(c => {
                const name = (c.name || '').toLowerCase();
                const shortName = (c.short_name || '').toLowerCase();
                const city = (c.city || '').toLowerCase();
                const state = (c.state || '').toLowerCase();
                const address = (c.address_line || '').toLowerCase();
                return name.includes(query) || shortName.includes(query) || city.includes(query) || state.includes(query) || address.includes(query);
            });

            renderCampusesTable(filtered);
        }
        window.filterCampusesList = filterCampusesList;

        let _activeEditingCampusId = null;

        function navigateToCampusEditor(id) {
            if (!_campusCanManage) {
                if (typeof showToast === 'function') showToast('Only Admin (full access) can edit campuses.', 'error');
                return;
            }
            _activeEditingCampusId = id ? parseInt(id, 10) : null;
            switchNavTab('campus-editor');
        }
        window.navigateToCampusEditor = navigateToCampusEditor;

        function closeCampusEditor() {
            _activeEditingCampusId = null;
            switchNavTab('campuses');
        }
        window.closeCampusEditor = closeCampusEditor;

        async function initCampusEditorPage() {
            const form = document.getElementById('campusPageForm');
            if (form) form.reset();

            const titleEl = document.getElementById('campusPageHeaderTitle');
            const iconEl = document.getElementById('campusPageHeaderIcon');
            const idTagEl = document.getElementById('campusPageIdTag');
            const badgeEl = document.getElementById('campusPageTypeBadge');
            const submitBtn = document.getElementById('campusPageFormSubmitBtn');
            const saveBtnTop = document.getElementById('btnSaveCampusPageTop');

            const id = _activeEditingCampusId;
            document.getElementById('campus_page_id').value = id || '';

            if (!id) {
                // ADD MODE
                if (titleEl) titleEl.innerText = 'Add New Campus Location';
                if (iconEl) iconEl.innerText = 'âž•';
                if (idTagEl) idTagEl.innerText = 'New Draft';
                if (badgeEl) {
                    badgeEl.style.background = '#EFF6FF';
                    badgeEl.style.color = '#1D4ED8';
                    badgeEl.style.borderColor = '#BFDBFE';
                    badgeEl.innerText = 'â— New Record';
                }
                if (submitBtn) submitBtn.innerText = 'Create Campus';
                if (saveBtnTop) saveBtnTop.innerHTML = '<span>âž•</span> Create Campus';

                const isPrimaryCheck = document.getElementById('cp_is_primary');
                if (isPrimaryCheck) {
                    isPrimaryCheck.checked = (_campusesCache.length === 0);
                }

                await loadCampusEditorCourses(null);
                return;
            }

            // EDIT MODE
            let campus = _campusesCache.find(c => c.id == id);
            if (!campus && token) {
                try {
                    const res = await fetch(`/v1/campuses/${id}`, {
                        headers: { 'Authorization': 'Bearer ' + token }
                    });
                    const d = await res.json();
                    if (d.status === 'success' && d.data && d.data.campus) {
                        campus = d.data.campus;
                    }
                } catch(e) {}
            }

            if (!campus) {
                if (typeof showToast === 'function') showToast('Campus not found.', 'error');
                closeCampusEditor();
                return;
            }

            if (titleEl) titleEl.innerText = 'Edit Campus: ' + (campus.name || 'Location');
            if (iconEl) iconEl.innerText = 'ðŸ«';
            if (idTagEl) idTagEl.innerText = '#' + campus.id;
            if (badgeEl) {
                const isActive = campus.status === 'active';
                badgeEl.style.background = isActive ? '#F0FDF4' : '#F8FAFC';
                badgeEl.style.color = isActive ? '#166534' : '#64748B';
                badgeEl.style.borderColor = isActive ? '#BBF7D0' : '#E2E8F0';
                badgeEl.innerText = isActive ? 'â— Operational' : 'â—‹ Inactive';
            }
            if (submitBtn) submitBtn.innerText = 'Save Campus Changes';
            if (saveBtnTop) saveBtnTop.innerHTML = '<span>ðŸ’¾</span> Save Campus Changes';

            document.getElementById('cp_name').value = campus.name || '';
            document.getElementById('cp_short_name').value = campus.short_name || '';
            document.getElementById('cp_is_primary').checked = (campus.is_primary == 1);
            document.getElementById('cp_status').value = campus.status || 'active';
            document.getElementById('cp_area').value = campus.campus_area || '';
            document.getElementById('cp_has_hostel').checked = (campus.has_hostel == 1);
            document.getElementById('cp_virtual_tour_url').value = campus.virtual_tour_url || '';
            document.getElementById('cp_address_line').value = campus.address_line || '';
            document.getElementById('cp_city').value = campus.city || '';
            document.getElementById('cp_state').value = campus.state || '';
            document.getElementById('cp_pincode').value = campus.pincode || '';
            document.getElementById('cp_country').value = campus.country || 'India';
            document.getElementById('cp_contact_email').value = campus.contact_email || '';
            document.getElementById('cp_contact_phone').value = campus.contact_phone || '';

            await loadCampusEditorCourses(id);
        }
        window.initCampusEditorPage = initCampusEditorPage;

        let _currentCampusCoursesData = [];

        async function loadCampusEditorCourses(campusId) {
            const container = document.getElementById('campusCoursesGroupContainer');
            if (!container) return;

            container.innerHTML = `
                <div style="text-align: center; padding: 24px; color: #648781; font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <span style="display:inline-block; width:14px; height:14px; border:2px solid #047857; border-top-color:transparent; border-radius:50%; animation: spin 0.8s linear infinite;"></span>
                    <span>Loading courses catalog &amp; campus mappings...</span>
                </div>
            `;

            try {
                if (campusId) {
                    const res = await fetch(`/v1/campuses/${campusId}/courses`, {
                        headers: { 'Authorization': 'Bearer ' + token }
                    });
                    const d = await res.json();
                    if (d.status === 'success' && d.data) {
                        _currentCampusCoursesData = d.data.departments || [];
                        renderCampusEditorCoursesGroups(_currentCampusCoursesData);
                        return;
                    }
                }

                // If adding new campus, load all departments with courses
                const res = await fetch('/v1/departments', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const d = await res.json();
                if (d.status === 'success' && d.data) {
                    const depts = d.data.departments || [];
                    _currentCampusCoursesData = depts.map(dept => ({
                        id: dept.id,
                        name: dept.name,
                        icon: dept.icon || 'ðŸ«',
                        courses: (dept.courses || []).map(c => ({
                            id: c.id,
                            course_name: c.course_name,
                            course_code: c.course_code,
                            is_offered: false
                        }))
                    }));
                    renderCampusEditorCoursesGroups(_currentCampusCoursesData);
                }
            } catch (err) {
                console.error('Error loading campus editor courses:', err);
                container.innerHTML = `<div style="color: #EF4444; font-size: 12px; padding: 16px; text-align: center;">Failed to load courses catalog.</div>`;
            }
        }

        function renderCampusEditorCoursesGroups(departments) {
            const container = document.getElementById('campusCoursesGroupContainer');
            if (!container) return;

            if (!departments || departments.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #648781; font-size: 12px;">
                        No academic departments or courses found in catalog.<br/>
                        <a href="javascript:void(0)" onclick="switchNavTab('departments')" style="color: #047857; font-weight: 700; text-decoration: underline; margin-top: 6px; display: inline-block;">+ Create Departments &amp; Courses First</a>
                    </div>
                `;
                updateCampusDerivedDeptsDisplay();
                return;
            }

            container.innerHTML = departments.map(dept => {
                const courses = dept.courses || [];
                const deptIcon = dept.icon || 'ðŸ«';
                const deptName = escapeHtml(dept.name);

                if (courses.length === 0) {
                    return `
                        <div style="background: #F8FCFA; border: 1px solid #E6F0EC; border-radius: 8px; padding: 12px 14px;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <div style="font-weight: 700; color: #063D3B; font-size: 12.5px; display: flex; align-items: center; gap: 7px;">
                                    <span>${deptIcon}</span>
                                    <span>${deptName}</span>
                                </div>
                                <span style="font-size: 11px; color: #94A3B8; font-style: italic;">No programs configured under this department</span>
                            </div>
                        </div>
                    `;
                }

                const coursesHtml = courses.map(c => {
                    const isChecked = !!c.is_offered;
                    const codeBadge = c.course_code ? `<span style="font-size: 10px; font-weight: 700; color: #648781; background: #FFFFFF; border: 1px solid #D1E5DE; border-radius: 4px; padding: 1px 5px;">${escapeHtml(c.course_code)}</span>` : '';

                    return `
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #063D3B; cursor: pointer; padding: 7px 10px; background: #FFFFFF; border: 1px solid ${isChecked ? '#10B981' : '#D1E5DE'}; border-radius: 6px; transition: all 0.15s; user-select: none;">
                            <input type="checkbox" class="campus-course-checkbox" data-dept-id="${dept.id}" data-dept-name="${deptName}" data-dept-icon="${deptIcon}" value="${c.id}" ${isChecked ? 'checked' : ''} onchange="onCampusCourseCheckboxChange(this)" style="width: 15px; height: 15px; accent-color: #047857; cursor: pointer; flex-shrink: 0;" />
                            <span style="font-weight: 600; flex: 1;">${escapeHtml(c.course_name)}</span>
                            ${codeBadge}
                        </label>
                    `;
                }).join('');

                const offeredCount = courses.filter(c => c.is_offered).length;

                return `
                    <div class="campus-dept-group" data-dept-id="${dept.id}" style="background: #FAFCFB; border: 1.5px solid #E6F0EC; border-radius: 8px; padding: 14px; display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 16px;">${deptIcon}</span>
                                <strong style="font-size: 13px; color: #063D3B;">${deptName}</strong>
                                <span class="dept-group-count-badge" style="font-size: 10px; font-weight: 700; color: #047857; background: #ECFDF5; border: 1px solid #A7F3D0; padding: 2px 7px; border-radius: 4px;">
                                    ${offeredCount}/${courses.length} Offered
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <button type="button" class="brand-btn-secondary" style="height: 24px; padding: 0 8px; font-size: 10.5px; font-weight: 600; border-radius: 4px;" onclick="toggleDeptAllCourses(${dept.id}, true)">Select All</button>
                                <button type="button" class="brand-btn-secondary" style="height: 24px; padding: 0 8px; font-size: 10.5px; font-weight: 600; border-radius: 4px;" onclick="toggleDeptAllCourses(${dept.id}, false)">Deselect All</button>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px;">
                            ${coursesHtml}
                        </div>
                    </div>
                `;
            }).join('');

            updateCampusDerivedDeptsDisplay();
        }

        function toggleDeptAllCourses(deptId, select) {
            const group = document.querySelector(`.campus-dept-group[data-dept-id="${deptId}"]`);
            if (!group) return;
            group.querySelectorAll('.campus-course-checkbox').forEach(cb => {
                cb.checked = select;
                cb.parentElement.style.borderColor = select ? '#10B981' : '#D1E5DE';
            });
            updateCampusDerivedDeptsDisplay();
        }
        window.toggleDeptAllCourses = toggleDeptAllCourses;

        function onCampusCourseCheckboxChange(cb) {
            cb.parentElement.style.borderColor = cb.checked ? '#10B981' : '#D1E5DE';
            updateCampusDerivedDeptsDisplay();
        }
        window.onCampusCourseCheckboxChange = onCampusCourseCheckboxChange;

        function updateCampusDerivedDeptsDisplay() {
            const allChecked = Array.from(document.querySelectorAll('.campus-course-checkbox:checked'));
            const totalOffered = allChecked.length;

            const badge = document.getElementById('campusEditorCoursesBadge');
            if (badge) {
                badge.innerText = `${totalOffered} Course${totalOffered === 1 ? '' : 's'} Offered`;
                badge.style.color = totalOffered > 0 ? '#047857' : '#648781';
                badge.style.background = totalOffered > 0 ? '#ECFDF5' : '#F1F5F9';
                badge.style.borderColor = totalOffered > 0 ? '#A7F3D0' : '#E2E8F0';
            }

            // Update each department's group badge count
            document.querySelectorAll('.campus-dept-group').forEach(group => {
                const totalInDept = group.querySelectorAll('.campus-course-checkbox').length;
                const checkedInDept = group.querySelectorAll('.campus-course-checkbox:checked').length;
                const countBadge = group.querySelector('.dept-group-count-badge');
                if (countBadge) {
                    countBadge.innerText = `${checkedInDept}/${totalInDept} Offered`;
                    countBadge.style.color = checkedInDept > 0 ? '#047857' : '#648781';
                    countBadge.style.background = checkedInDept > 0 ? '#ECFDF5' : '#F1F5F9';
                    countBadge.style.borderColor = checkedInDept > 0 ? '#A7F3D0' : '#E2E8F0';
                }
            });

            // Calculate derived departments
            const deptsMap = {};
            allChecked.forEach(cb => {
                const deptId = cb.dataset.deptId;
                const deptName = cb.dataset.deptName;
                const deptIcon = cb.dataset.deptIcon || 'ðŸ›ï¸';
                if (!deptsMap[deptId]) {
                    deptsMap[deptId] = { name: deptName, icon: deptIcon, count: 0 };
                }
                deptsMap[deptId].count++;
            });

            const derivedListEl = document.getElementById('campusDerivedDeptsList');
            if (derivedListEl) {
                const deptKeys = Object.keys(deptsMap);
                if (deptKeys.length === 0) {
                    derivedListEl.innerHTML = `<span style="color: #94A3B8; font-style: italic;">No departments derived yet. Check courses below to establish campus departments.</span>`;
                } else {
                    derivedListEl.innerHTML = deptKeys.map(k => {
                        const d = deptsMap[k];
                        return `
                            <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: #063D3B; background: #FFFFFF; border: 1px solid #D1E5DE; border-radius: 6px; padding: 3px 9px;">
                                <span>${d.icon}</span>
                                <span>${escapeHtml(d.name)}</span>
                                <span style="font-size: 10px; color: #047857; background: #ECFDF5; border-radius: 4px; padding: 1px 5px; font-weight: 800;">${d.count}</span>
                            </span>
                        `;
                    }).join('');
                }
            }
        }
        window.updateCampusDerivedDeptsDisplay = updateCampusDerivedDeptsDisplay;

        async function handleCampusPageSubmit(e) {
            if (e) e.preventDefault();
            if (!_campusCanManage) {
                if (typeof showToast === 'function') showToast('Unauthorized action.', 'error');
                return;
            }

            const btn = document.getElementById('campusPageFormSubmitBtn');
            const btnTop = document.getElementById('btnSaveCampusPageTop');
            const origText = btn ? btn.innerHTML : 'Save Campus';
            if (btn) { btn.innerHTML = 'â³ Saving...'; btn.disabled = true; }
            if (btnTop) { btnTop.innerHTML = 'â³ Saving...'; btnTop.disabled = true; }

            const id = document.getElementById('campus_page_id').value;
            const payload = {
                name: (document.getElementById('cp_name')?.value || '').trim(),
                short_name: (document.getElementById('cp_short_name')?.value || '').trim(),
                is_primary: document.getElementById('cp_is_primary')?.checked ? 1 : 0,
                status: document.getElementById('cp_status')?.value || 'active',
                campus_area: (document.getElementById('cp_area')?.value || '').trim(),
                has_hostel: document.getElementById('cp_has_hostel')?.checked ? 1 : 0,
                virtual_tour_url: (document.getElementById('cp_virtual_tour_url')?.value || '').trim(),
                address_line: (document.getElementById('cp_address_line')?.value || '').trim(),
                city: (document.getElementById('cp_city')?.value || '').trim(),
                state: (document.getElementById('cp_state')?.value || '').trim(),
                pincode: (document.getElementById('cp_pincode')?.value || '').trim(),
                country: (document.getElementById('cp_country')?.value || 'India').trim(),
                contact_email: (document.getElementById('cp_contact_email')?.value || '').trim(),
                contact_phone: (document.getElementById('cp_contact_phone')?.value || '').trim()
            };

            if (!payload.name) {
                if (typeof showToast === 'function') showToast('Campus name is required.', 'error');
                if (btn) { btn.innerHTML = origText; btn.disabled = false; }
                if (btnTop) { btnTop.innerHTML = '<span>ðŸ’¾</span> Save Campus Changes'; btnTop.disabled = false; }
                return;
            }

            try {
                const url = id ? `/v1/campuses/${id}` : '/v1/campuses';
                const method = id ? 'PUT' : 'POST';

                const res = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.status === 'success') {
                    const targetCampusId = id ? parseInt(id) : parseInt(data.data?.id || 0);

                    if (targetCampusId > 0) {
                        const checkedCourseIds = Array.from(document.querySelectorAll('.campus-course-checkbox:checked')).map(cb => parseInt(cb.value));
                        await fetch(`/v1/campuses/${targetCampusId}/courses`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                            body: JSON.stringify({ course_ids: checkedCourseIds })
                        });
                    }

                    if (typeof showToast === 'function') showToast(id ? 'Campus updated successfully! âœ“' : 'New campus created successfully! âœ“', 'success');
                    closeCampusEditor();
                    await loadCampuses();
                } else {
                    if (typeof showToast === 'function') showToast(data.message || 'Failed to save campus.', 'error');
                    else alert(data.message || 'Failed to save campus.');
                }
            } catch (err) {
                console.error('[Edvora Campuses] Save error:', err);
                if (typeof showToast === 'function') showToast('Network error saving campus.', 'error');
            } finally {
                if (btn) { btn.innerHTML = origText; btn.disabled = false; }
                if (btnTop) { btnTop.innerHTML = '<span>ðŸ’¾</span> Save Campus Changes'; btnTop.disabled = false; }
            }
        }
        window.handleCampusPageSubmit = handleCampusPageSubmit;

        function openAddCampusModal() {
            navigateToCampusEditor('');
        }
        window.openAddCampusModal = openAddCampusModal;

        function openEditCampusModal(id) {
            navigateToCampusEditor(id);
        }
        window.openEditCampusModal = openEditCampusModal;

        function closeCampusModal() {
            closeCampusEditor();
        }
        window.closeCampusModal = closeCampusModal;

        function openViewCampusModal(id) {
            const campus = _campusesCache.find(c => c.id == id);
            if (!campus) return;

            const isPrimary = campus.is_primary == 1;
            const hasHostel = campus.has_hostel == 1;

            const setEl = (elemId, val) => {
                const el = document.getElementById(elemId);
                if (el) el.innerText = val;
            };

            setEl('viewCampusName', campus.name || 'Campus Details');
            setEl('viewCampusTypeBadge', isPrimary ? 'â­ Primary Flagship Campus' : 'ðŸ›ï¸ Branch / Satellite Campus');
            setEl('viewCampusArea', campus.campus_area || 'Not specified');
            setEl('viewCampusHostel', hasHostel ? 'âœ“ Residential Hostels Available On-Campus' : 'âœ— Day Scholar / Non-Residential');

            const fullAddr = [campus.address_line, campus.city, campus.state, campus.pincode, campus.country].filter(Boolean).join(', ');
            setEl('viewCampusAddress', fullAddr || 'No physical address recorded.');
            setEl('viewCampusEmail', campus.contact_email || 'Not specified');
            setEl('viewCampusPhone', campus.contact_phone || 'Not specified');

            // Populate derived departments and offered courses
            const deptCountEl = document.getElementById('viewCampusDeptCount');
            const coursesCountEl = document.getElementById('viewCampusCoursesCount');
            const derivedDeptsEl = document.getElementById('viewCampusDerivedDepts');
            const coursesListEl = document.getElementById('viewCampusCoursesList');

            if (deptCountEl) deptCountEl.innerText = (campus.derived_departments || []).length;
            if (coursesCountEl) coursesCountEl.innerText = `${campus.courses_count || 0} Programs Offered`;

            if (derivedDeptsEl) {
                const depts = campus.derived_departments || [];
                if (depts.length === 0) {
                    derivedDeptsEl.innerHTML = `<span style="color: #94A3B8; font-style: italic;">No departments derived. Map courses to associate departments.</span>`;
                } else {
                    derivedDeptsEl.innerHTML = depts.map(d => `
                        <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 700; color: #063D3B; background: #FFFFFF; border: 1px solid #D1E5DE; border-radius: 5px; padding: 3px 8px; margin: 2px;">
                            <span>${d.icon || 'ðŸ›ï¸'}</span>
                            <span>${escapeHtml(d.name)}</span>
                        </span>
                    `).join('');
                }
            }

            // Fetch detailed list of courses for this campus
            if (coursesListEl) {
                coursesListEl.innerHTML = `<div style="color: #648781; font-size: 11px;">Loading offered courses...</div>`;
                fetch(`/v1/campuses/${id}/courses`, {
                    headers: { 'Authorization': 'Bearer ' + token }
                })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success' && res.data) {
                        const depts = (res.data.departments || []).filter(d => d.offered_courses_count > 0);
                        if (depts.length === 0) {
                            coursesListEl.innerHTML = `<span style="color: #94A3B8; font-style: italic;">No programs currently offered at this campus location.</span>`;
                            return;
                        }
                        coursesListEl.innerHTML = depts.map(d => {
                            const offered = (d.courses || []).filter(c => c.is_offered);
                            return `
                                <div style="margin-bottom: 8px;">
                                    <div style="font-weight: 700; color: #063D3B; font-size: 11.5px; margin-bottom: 4px;">
                                        ${d.icon || 'ðŸ›ï¸'} ${escapeHtml(d.name)}
                                    </div>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px; padding-left: 8px;">
                                        ${offered.map(c => `
                                            <span style="font-size: 11px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 4px; padding: 2px 7px; color: #334155;">
                                                ${escapeHtml(c.course_name)} ${c.course_code ? `<strong style="color: #648781;">(${escapeHtml(c.course_code)})</strong>` : ''}
                                            </span>
                                        `).join('')}
                                    </div>
                                </div>
                            `;
                        }).join('');
                    }
                })
                .catch(() => {
                    coursesListEl.innerHTML = `<span style="color: #EF4444;">Failed to load offered courses.</span>`;
                });
            }

            const tourSection = document.getElementById('viewCampusTourSection');
            const tourLinkEl = document.getElementById('viewCampusTourLink');
            if (campus.virtual_tour_url && campus.virtual_tour_url.trim()) {
                if (tourSection) tourSection.style.display = 'block';
                if (tourLinkEl) {
                    tourLinkEl.innerHTML = `<a href="${escapeHtmlString(campus.virtual_tour_url)}" target="_blank" style="color: #2563EB; font-weight: 700; text-decoration: underline;">Launch 3D Virtual Campus Tour &UpperRightArrow;</a>`;
                }
            } else {
                if (tourSection) tourSection.style.display = 'block';
                if (tourLinkEl) tourLinkEl.innerHTML = `<span style="color: #94A3B8; font-style: italic;">No virtual tour URL configured.</span>`;
            }

            const modal = document.getElementById('modalCampusView');
            if (modal) modal.style.display = 'flex';
        }
        window.openViewCampusModal = openViewCampusModal;

        function closeCampusViewModal() {
            const modal = document.getElementById('modalCampusView');
            if (modal) modal.style.display = 'none';
        }
        window.closeCampusViewModal = closeCampusViewModal;

        async function handleSaveCampus(e) {
            handleCampusPageSubmit(e);
        }
        window.handleSaveCampus = handleSaveCampus;

        async function handleDeleteCampus(id, name) {
            if (!_campusCanManage) {
                if (typeof showToast === 'function') showToast('Only Admin (full access) can delete campuses.', 'error');
                return;
            }

            if (!confirm(`Are you sure you want to delete the campus "${name}"?\nThis action cannot be undone.`)) {
                return;
            }

            try {
                const res = await fetch(`/v1/campuses/${id}`, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();

                if (data.status === 'success') {
                    if (typeof showToast === 'function') showToast('Campus deleted successfully.', 'success');
                    await loadCampuses();
                } else {
                    if (typeof showToast === 'function') showToast(data.message || 'Could not delete campus.', 'error');
                    else alert(data.message || 'Could not delete campus.');
                }
            } catch (err) {
                console.error('[Edvora Campuses] Delete error:', err);
                if (typeof showToast === 'function') showToast('Network error deleting campus.', 'error');
            }
        }
        window.handleDeleteCampus = handleDeleteCampus;

        // â”€â”€ TEAMS WORKSPACE LOGIC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        window.teamsCachedUsers = [];
        window.teamsCachedDepts = [];
        window.teamsCurrentRoleFilter = 'all';

        function escapeJsString(str) {
            if (!str) return '';
            return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
        }

        function escapeHtmlString(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function toggleAddAdminPrivPanel() {
            const roleSelect = document.getElementById('addMemberRole');
            const panel = document.getElementById('addAdminPrivPanel');
            if (roleSelect && panel) {
                panel.style.display = (roleSelect.value === 'admin') ? 'block' : 'none';
            }
        }

        function toggleEditAdminPrivPanel() {
            const roleSelect = document.getElementById('editMemberRole');
            const panel = document.getElementById('editAdminPrivPanel');
            if (roleSelect && panel) {
                panel.style.display = (roleSelect.value === 'admin') ? 'block' : 'none';
            }
        }

        function openAddTeamMemberModal() {
            const modal = document.getElementById('addTeamMemberModal');
            if (!modal) return;

            // Reset inputs
            const nameEl = document.getElementById('addMemberName');
            const emailEl = document.getElementById('addMemberEmail');
            const passEl = document.getElementById('addMemberPassword');
            const roleEl = document.getElementById('addMemberRole');

            if (nameEl) nameEl.value = '';
            if (emailEl) emailEl.value = '';
            if (passEl) passEl.value = '';
            if (roleEl) roleEl.value = 'team';

            toggleAddAdminPrivPanel();

            // Populate departments checklist
            const checklist = document.getElementById('addMemberDeptChecklist');
            if (checklist) {
                const depts = window.teamsCachedDepts || [];
                if (depts.length === 0) {
                    checklist.innerHTML = `<span style="font-size: 11.5px; color: #648781;">No departments available yet.</span>`;
                } else {
                    checklist.innerHTML = depts.map(d => `
                        <label style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: #FFFFFF; border: 1.5px solid #D1E5DE; border-radius: 6px; font-size: 11.5px; color: #063D3B; cursor: pointer;">
                            <input type="checkbox" class="add-dept-checkbox" value="${d.id}" style="accent-color: #063D3B;" />
                            <span>${d.icon || 'ðŸ«'} ${escapeHtmlString(d.name)}</span>
                        </label>
                    `).join('');
                }
            }

            modal.classList.add('open');
            setTimeout(() => { if (nameEl) nameEl.focus(); }, 100);
        }

        function closeAddTeamMemberModal() {
            const modal = document.getElementById('addTeamMemberModal');
            if (modal) modal.classList.remove('open');
        }

        async function submitAddTeamMemberModal() {
            const nameEl = document.getElementById('addMemberName');
            const emailEl = document.getElementById('addMemberEmail');
            const passEl = document.getElementById('addMemberPassword');
            const roleEl = document.getElementById('addMemberRole');

            const name = nameEl ? nameEl.value.trim() : '';
            const email = emailEl ? emailEl.value.trim() : '';
            const password = passEl ? passEl.value : '';
            const role = roleEl ? roleEl.value : 'team';

            if (!name || !email || !password) {
                alert('Please provide Full Name, Email Address, and Password.');
                return;
            }

            if (password.length < 6) {
                alert('Password must be at least 6 characters long.');
                return;
            }

            let can_manage_structure = 1;
            if (role === 'admin') {
                const selectedPriv = document.querySelector('input[name="add_admin_privilege"]:checked');
                can_manage_structure = selectedPriv ? parseInt(selectedPriv.value) : 1;
            } else {
                can_manage_structure = 0;
            }

            const department_ids = Array.from(document.querySelectorAll('.add-dept-checkbox:checked')).map(cb => parseInt(cb.value));

            try {
                const res = await fetch('/v1/organization/staff', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ name, email, password, role, can_manage_structure, department_ids })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const roleLabel = (role === 'admin') ? (can_manage_structure == 1 ? 'Full Admin' : 'Content Admin') : 'Team Member';
                    alert(`ðŸŽ‰ Success! ${name} has been added as a ${roleLabel}.`);
                    closeAddTeamMemberModal();
                    await loadTeamsWorkspace();
                } else {
                    alert(data.message || 'Failed to add team member.');
                }
            } catch (err) {
                console.error(err);
                alert('Error adding team member.');
            }
        }

        function openEditTeamMemberModalById(userId) {
            const user = (window.teamsCachedUsers || []).find(u => String(u.id) === String(userId));
            if (!user) {
                alert('Team member details not found.');
                return;
            }

            const modal = document.getElementById('editTeamMemberModal');
            if (!modal) return;

            const idEl = document.getElementById('editMemberId');
            const nameEl = document.getElementById('editMemberName');
            const emailEl = document.getElementById('editMemberEmail');
            const passEl = document.getElementById('editMemberPassword');
            const roleEl = document.getElementById('editMemberRole');

            if (idEl) idEl.value = user.id;
            if (nameEl) nameEl.value = user.name || '';
            if (emailEl) emailEl.value = user.email || '';
            if (passEl) passEl.value = '';

            const isAdmin = user.role === 'admin' || user.role === 'owner' || user.role === 'org_admin' || user.role === 'superadmin';
            if (roleEl) roleEl.value = isAdmin ? 'admin' : 'team';

            // Check the correct privilege radio
            const privRadios = document.querySelectorAll('input[name="edit_admin_privilege"]');
            const isFull = (user.can_manage_structure == 1 || user.role === 'owner' || user.role === 'superadmin');
            privRadios.forEach(r => {
                r.checked = (r.value === (isFull ? '1' : '0'));
            });

            toggleEditAdminPrivPanel();

            // Populate departments checklist with existing assignments checked
            const userDeptIds = new Set((user.departments || []).map(d => String(d.id)));
            const checklist = document.getElementById('editMemberDeptChecklist');
            if (checklist) {
                const depts = window.teamsCachedDepts || [];
                if (depts.length === 0) {
                    checklist.innerHTML = `<span style="font-size: 11.5px; color: #648781;">No departments available.</span>`;
                } else {
                    checklist.innerHTML = depts.map(d => {
                        const isChecked = userDeptIds.has(String(d.id)) ? 'checked' : '';
                        return `
                            <label style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: #FFFFFF; border: 1.5px solid #D1E5DE; border-radius: 6px; font-size: 11.5px; color: #063D3B; cursor: pointer;">
                                <input type="checkbox" class="edit-dept-checkbox" value="${d.id}" ${isChecked} style="accent-color: #063D3B;" />
                                <span>${d.icon || 'ðŸ«'} ${escapeHtmlString(d.name)}</span>
                            </label>
                        `;
                    }).join('');
                }
            }

            modal.classList.add('open');
            setTimeout(() => { if (nameEl) nameEl.focus(); }, 100);
        }

        function closeEditTeamMemberModal() {
            const modal = document.getElementById('editTeamMemberModal');
            if (modal) modal.classList.remove('open');
        }

        async function submitEditTeamMemberModal() {
            const idEl = document.getElementById('editMemberId');
            const nameEl = document.getElementById('editMemberName');
            const emailEl = document.getElementById('editMemberEmail');
            const passEl = document.getElementById('editMemberPassword');
            const roleEl = document.getElementById('editMemberRole');

            const userId = idEl ? idEl.value : '';
            const name = nameEl ? nameEl.value.trim() : '';
            const email = emailEl ? emailEl.value.trim() : '';
            const password = passEl ? passEl.value.trim() : '';
            const role = roleEl ? roleEl.value : 'team';

            if (!userId || !name || !email) {
                alert('Full Name and Email Address are required.');
                return;
            }

            if (password && password.length < 6) {
                alert('Password must be at least 6 characters long.');
                return;
            }

            let can_manage_structure = 1;
            if (role === 'admin') {
                const selectedPriv = document.querySelector('input[name="edit_admin_privilege"]:checked');
                can_manage_structure = selectedPriv ? parseInt(selectedPriv.value) : 1;
            } else {
                can_manage_structure = 0;
            }

            const department_ids = Array.from(document.querySelectorAll('.edit-dept-checkbox:checked')).map(cb => parseInt(cb.value));

            const payload = { name, email, role, can_manage_structure, department_ids };
            if (password) {
                payload.password = password;
            }

            try {
                const res = await fetch('/v1/organization/staff/' + userId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert(`ðŸŽ‰ Success! Team member details updated successfully.`);
                    closeEditTeamMemberModal();
                    await loadTeamsWorkspace();
                } else {
                    alert(data.message || 'Failed to update team member.');
                }
            } catch (err) {
                console.error(err);
                alert('Error updating team member.');
            }
        }

        function setTeamsRoleFilter(filter, el) {
            window.teamsCurrentRoleFilter = filter;

            // Update Tab Buttons
            document.querySelectorAll('#teamsTabsContainer .ckh-tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            const tabBtn = document.getElementById({
                'all': 'teamsTabBtnAll',
                'full_admin': 'teamsTabBtnFullAdmin',
                'content_admin': 'teamsTabBtnContentAdmin',
                'team_member': 'teamsTabBtnTeamMember'
            }[filter]);
            if (tabBtn) tabBtn.classList.add('active');

            // Update KPI Tiles
            document.querySelectorAll('.ckh-kpi-ribbon .ckh-kpi-tile').forEach(tile => {
                tile.classList.remove('active-kpi');
            });
            const kpiTile = document.getElementById({
                'all': 'teamsKpiTileAll',
                'full_admin': 'teamsKpiTileFullAdmin',
                'content_admin': 'teamsKpiTileContentAdmin',
                'team_member': 'teamsKpiTileTeamMember'
            }[filter]);
            if (kpiTile) kpiTile.classList.add('active-kpi');

            filterTeamsRosterTable();
        }

        function filterTeamsRosterTable() {
            const searchInput = document.getElementById('teamsSearchInput');
            const deptFilter = document.getElementById('teamsDeptFilter');
            const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
            const deptVal = deptFilter ? deptFilter.value : 'all';

            const filtered = (window.teamsCachedUsers || []).filter(u => {
                const isAdmin = u.role === 'owner' || u.role === 'org_admin' || u.role === 'admin' || u.role === 'superadmin';
                const isFullAdmin = isAdmin && (u.can_manage_structure == 1 || u.role === 'owner' || u.role === 'superadmin');
                const isContentAdmin = isAdmin && !isFullAdmin;
                const isTeamMember = !isAdmin;

                // Role check
                if (window.teamsCurrentRoleFilter === 'full_admin' && !isFullAdmin) return false;
                if (window.teamsCurrentRoleFilter === 'content_admin' && !isContentAdmin) return false;
                if (window.teamsCurrentRoleFilter === 'team_member' && !isTeamMember) return false;

                // Dept check
                if (deptVal !== 'all') {
                    const hasDept = (u.departments || []).some(d => String(d.id) === String(deptVal));
                    if (!hasDept) return false;
                }

                // Query search
                if (query) {
                    const name = (u.name || '').toLowerCase();
                    const email = (u.email || '').toLowerCase();
                    const deptsText = (u.departments || []).map(d => (d.name || '').toLowerCase()).join(' ');
                    const roleText = isFullAdmin ? 'admin full access' : (isContentAdmin ? 'admin content access' : 'team member counselor');
                    if (!name.includes(query) && !email.includes(query) && !deptsText.includes(query) && !roleText.includes(query)) {
                        return false;
                    }
                }

                return true;
            });

            renderTeamsRosterTableRows(filtered);
        }

        async function loadTeamsWorkspace() {
            if (!token) return;

            const isCurrentFullAdmin = !currentUser || (
                currentUser.role === 'owner' ||
                currentUser.role === 'superadmin' ||
                currentUser.role === 'super_admin' ||
                (currentUser.role === 'admin' && currentUser.can_manage_structure == 1)
            );

            const addMemberBtn = document.getElementById('teamsAddMemberBtn');
            if (addMemberBtn) {
                addMemberBtn.style.display = isCurrentFullAdmin ? 'inline-flex' : 'none';
            }

            const restrictedNotice = document.getElementById('teamsRestrictedNotice');
            if (restrictedNotice) {
                restrictedNotice.style.display = isCurrentFullAdmin ? 'none' : 'block';
            }

            const actionColHeader = document.getElementById('teamsActionColHeader');
            if (actionColHeader) {
                actionColHeader.style.display = isCurrentFullAdmin ? '' : 'none';
            }

            try {
                // Fetch organization users & departments concurrently
                const [resUsers, resDepts] = await Promise.all([
                    fetch('/v1/organization/staff', { headers: { 'Authorization': 'Bearer ' + token } }),
                    fetch('/v1/departments', { headers: { 'Authorization': 'Bearer ' + token } })
                ]);
                const usersData = await resUsers.json();
                const deptsData = await resDepts.json();

                const users = (usersData.status === 'success' && usersData.data) ? usersData.data : [];
                const depts = (deptsData.status === 'success' && deptsData.data)
                    ? (Array.isArray(deptsData.data) ? deptsData.data : (deptsData.data.departments || []))
                    : [];

                window.teamsCachedUsers = users;
                window.teamsCachedDepts = depts;

                // Update Header Pill
                const pillCount = document.getElementById('teamsActivePillCount');
                if (pillCount) {
                    pillCount.innerText = `${users.length} Active Staff Member${users.length === 1 ? '' : 's'}`;
                }

                // Compute KPI metrics
                let fullAdminsCount = 0;
                let contentAdminsCount = 0;
                let teamMembersCount = 0;
                const assignedDeptIds = new Set();

                users.forEach(u => {
                    const isAdmin = u.role === 'owner' || u.role === 'org_admin' || u.role === 'admin' || u.role === 'superadmin';
                    if (isAdmin && (u.can_manage_structure == 1 || u.role === 'owner' || u.role === 'superadmin')) {
                        fullAdminsCount++;
                    } else if (isAdmin) {
                        contentAdminsCount++;
                    } else {
                        teamMembersCount++;
                    }

                    if (u.departments && Array.isArray(u.departments)) {
                        u.departments.forEach(d => assignedDeptIds.add(d.id));
                    }
                });

                // Update KPI Tiles numbers
                const setElText = (id, txt) => {
                    const el = document.getElementById(id);
                    if (el) el.innerText = txt;
                };
                setElText('statTotalMembers', users.length);
                setElText('statFullAdmins', fullAdminsCount);
                setElText('statContentAdmins', contentAdminsCount);
                setElText('statTeamMembers', teamMembersCount);
                setElText('statAssignedDepts', assignedDeptIds.size);

                // Update Tab Count Badges
                setElText('tabCountAll', users.length);
                setElText('tabCountFullAdmin', fullAdminsCount);
                setElText('tabCountContentAdmin', contentAdminsCount);
                setElText('tabCountTeamMember', teamMembersCount);

                // Populate Department Filter Dropdown
                const deptFilter = document.getElementById('teamsDeptFilter');
                if (deptFilter) {
                    const currentVal = deptFilter.value;
                    deptFilter.innerHTML = `<option value="all">All Departments</option>` + depts.map(d => `
                        <option value="${d.id}">${d.icon || 'ðŸ«'} ${escapeHtmlString(d.name)}</option>
                    `).join('');
                    deptFilter.value = currentVal || 'all';
                }

                // Initial render of filtered table
                filterTeamsRosterTable();

            } catch (err) {
                console.error('[Edvora Teams] Load error:', err);
                const tbody = document.getElementById('teamsRosterTableBody');
                if (tbody) {
                    const colSpan = isCurrentFullAdmin ? 5 : 4;
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="${colSpan}" style="text-align: center; padding: 40px 20px; color: #DC2626;">
                                <div style="font-size: 13.5px; font-weight: 700; margin-bottom: 6px;">Failed to load team roster</div>
                                <button class="ckh-action-btn" onclick="loadTeamsWorkspace()" style="margin-top: 6px; padding: 4px 12px; background: #FFFFFF; font-weight: 700;">â†» Try Again</button>
                            </td>
                        </tr>
                    `;
                }
            }
        }

        function renderTeamsRosterTableRows(users) {
            const tbody = document.getElementById('teamsRosterTableBody');
            if (!tbody) return;

            const isCurrentFullAdmin = !currentUser || (
                currentUser.role === 'owner' ||
                currentUser.role === 'superadmin' ||
                currentUser.role === 'super_admin' ||
                (currentUser.role === 'admin' && currentUser.can_manage_structure == 1)
            );

            const actionColHeader = document.getElementById('teamsActionColHeader');
            if (actionColHeader) {
                actionColHeader.style.display = isCurrentFullAdmin ? '' : 'none';
            }

            const colSpan = isCurrentFullAdmin ? 5 : 4;
            if (users.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="${colSpan}" style="text-align: center; padding: 40px 20px; color: #648781;">
                            <div style="font-size: 14px; font-weight: 700; color: #063D3B; margin-bottom: 4px;">No Team Members Found</div>
                            <div style="font-size: 11.5px; color: #648781;">Try clearing filters or search keywords.</div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = users.map(u => {
                const isAdmin = u.role === 'owner' || u.role === 'org_admin' || u.role === 'admin' || u.role === 'superadmin';
                const isFullAdmin = isAdmin && (u.can_manage_structure == 1 || u.role === 'owner' || u.role === 'superadmin');
                const isSelf = currentUser && (currentUser.id == u.id || (currentUser.email && currentUser.email.toLowerCase() === (u.email || '').toLowerCase()));

                let roleBadgeHtml = '';
                if (isFullAdmin) {
                    roleBadgeHtml = `
                        <span class="ckh-status-pill" style="background: #F5F3FF; color: #7C3AED; border: 1px solid #DDD6FE;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <span>Admin (Full Access)</span>
                        </span>`;
                } else if (isAdmin) {
                    roleBadgeHtml = `
                        <span class="ckh-status-pill" style="background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                            <span>Admin (Content Access)</span>
                        </span>`;
                } else {
                    roleBadgeHtml = `
                        <span class="ckh-status-pill" style="background: #E0F2FE; color: #0284C7; border: 1px solid #BAE6FD;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <span>Team Member</span>
                        </span>`;
                }

                const deptBadges = (u.departments && u.departments.length > 0)
                    ? u.departments.map(d => `<span class="ckh-dept-badge">${d.icon || 'ðŸ«'} ${escapeHtmlString(d.name)}</span>`).join(' ')
                    : `<span style="font-size: 11px; color: #94A3B8; font-style: italic;">No departments assigned</span>`;

                const initial = u.name ? u.name.charAt(0).toUpperCase() : 'U';
                const safeName = escapeJsString(u.name || '');

                const actionCell = isCurrentFullAdmin ? `
                    <td style="text-align: right;">
                        <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 6px;">
                            <button class="ckh-action-btn" title="Edit team member" onclick="openEditTeamMemberModalById(${u.id})">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                <span>Edit</span>
                            </button>
                            <button class="ckh-action-btn" title="Reset password" onclick="promptResetUserPassword(${u.id}, '${safeName}')">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 2l-2 2m-1.5 1.5L10 13l-4 4-2-2-4 4 2 2 4-4 2 2 7.5-7.5M19 4l1 1"/></svg>
                                <span>Reset</span>
                            </button>
                            ${!isSelf ? `
                            <button class="ckh-action-btn" style="color: #DC2626; border-color: #FECDD3;" onmouseover="this.style.background='#FEF2F2'" onmouseout="this.style.background='transparent'" title="Remove from organization" onclick="removeTeamUser(${u.id}, '${safeName}')">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                <span>Delete</span>
                            </button>
                            ` : ''}
                        </div>
                    </td>
                ` : '';

                return `
                    <tr>
                        <td>
                            <div class="ckh-doc-title-cell">
                                <div class="ckh-file-badge" style="background: #E6F7D2; color: #063D3B; border: 1px solid #B9D7C7; font-size: 13px; font-weight: 800; border-radius: 8px;">
                                    <span>${initial}</span>
                                </div>
                                <div>
                                    <div class="ckh-doc-title-text" style="font-weight: 700; color: #063D3B; font-size: 12.5px;">
                                        ${escapeHtmlString(u.name || 'Unnamed User')}
                                        ${isSelf ? '<span style="font-size: 9.5px; background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; border-radius: 4px; padding: 1px 5px; margin-left: 6px; font-weight: 700;">YOU</span>' : ''}
                                    </div>
                                    <div class="ckh-doc-meta-text">
                                        <span>${escapeHtmlString(u.email || '')}</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            ${roleBadgeHtml}
                        </td>
                        <td>
                            <div style="display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
                                ${deptBadges}
                            </div>
                        </td>
                        <td>
                            <span class="ckh-status-pill ckh-status-live">
                                <span style="width: 6px; height: 6px; border-radius: 50%; background: #047857;"></span>
                                Active &amp; Verified
                            </span>
                        </td>
                        ${actionCell}
                    </tr>
                `;
            }).join('');
        }

        // Compatibility shim
        function renderTeamsRoster(users) {
            renderTeamsRosterTableRows(users);
        }

        async function removeTeamUser(userId, userName) {
            if (!confirm(`Are you sure you want to remove ${userName} from the organization?`)) return;

            try {
                const res = await fetch('/v1/organization/staff/' + userId, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    await loadTeamsWorkspace();
                } else {
                    alert(data.message || 'Failed to remove team member.');
                }
            } catch (err) {
                console.error(err);
                alert('Error removing team member.');
            }
        }

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            const iconEye = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
            const iconEyeOff = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

            const textSpan = btn.querySelector('.toggle-text');
            const iconSpan = btn.querySelector('.toggle-icon');

            if (textSpan) {
                textSpan.textContent = isPassword ? 'Hide' : 'Show';
            }
            if (iconSpan) {
                iconSpan.innerHTML = isPassword ? iconEyeOff : iconEye;
            } else if (!textSpan) {
                btn.innerHTML = isPassword ? iconEyeOff : iconEye;
            }
        }

        async function promptResetUserPassword(userId, userName) {
            const newPass = prompt(`Set a new password for ${userName} (minimum 6 characters):`);
            if (newPass === null) return;
            const trimmed = newPass.trim();
            if (trimmed.length < 6) {
                alert('Password must be at least 6 characters long.');
                return;
            }

            try {
                const res = await fetch(`/v1/organization/staff/${userId}/password`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ password: trimmed })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert(`ðŸŽ‰ Success! Password for ${userName} has been updated.`);
                } else {
                    alert(data.message || 'Failed to update password.');
                }
            } catch (err) {
                console.error(err);
                alert('Error resetting user password.');
            }
        }

        function switchStudioSubtab(tabName, saveStorage = true) {
