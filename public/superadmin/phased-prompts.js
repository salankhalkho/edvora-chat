/**
 * Phased Master Prompts Manager for Superadmin
 * Supports 4 distinct conversational phases:
 * 1. Phase 1: Discovery & Rapport (Program Unknown)
 * 2. Phase 2: Program Deep-Dive & Lead Enrichment (Program Known)
 * 3. Specialized: Financial Aid & Scholarships
 * 4. Phase 3: Post-Lead Application Advisory
 * Plus Legacy Fallback Master Prompt
 */

let phasedPromptsData = {
    master_prompt_phase_1: '',
    master_prompt_phase_2: '',
    master_prompt_scholarship: '',
    master_prompt_phase_3: '',
    master_prompt: ''
};

let activePromptPhase = 'master_prompt_phase_1';
let isPromptsLoading = false;

const phaseDescriptions = {
    master_prompt_phase_1: 'Phase 1: Discovery & Rapport — Used when the visitor has NOT yet chosen a specific program. Friendly, warm, chatty, probing. Answers general questions and catalog requests. Zero lead pitching.',
    master_prompt_phase_2: 'Phase 2: Consideration & Lead Enrichment — Used once a specific program is identified (e.g. Executive MBA). Deep answers on the program with alternating offers (Brochure → Campus Visit → Counselor Callback).',
    master_prompt_scholarship: 'Specialized Phase: Financial Aid & Scholarships — Triggered whenever the visitor asks about tuition, fees, costs, or scholarships. Empathetic, transparent, reassuring. Offers the official Scholarship Calculator.',
    master_prompt_phase_3: 'Phase 3: Post-Lead Application Advisory — Used when the visitor\'s contact details are already captured. Acts as a personal mentor for application milestones. Zero contact badgering.',
    master_prompt: 'Legacy Master Prompt — Used as a safety fallback if any of the above phase prompts are left blank.'
};

async function loadPhasedPrompts() {
    if (typeof apiFetch !== 'function') {
        setTimeout(loadPhasedPrompts, 150);
        return;
    }

    if (isPromptsLoading) return;
    isPromptsLoading = true;

    try {
        const res = await apiFetch('/v1/superadmin/prompt');
        if (res && res.status === 'success' && res.data) {
            phasedPromptsData = {
                master_prompt_phase_1: res.data.master_prompt_phase_1 || '',
                master_prompt_phase_2: res.data.master_prompt_phase_2 || '',
                master_prompt_scholarship: res.data.master_prompt_scholarship || '',
                master_prompt_phase_3: res.data.master_prompt_phase_3 || '',
                master_prompt: res.data.master_prompt || ''
            };

            renderCurrentPromptTab();

            if (res.data.updated_at) {
                const el = document.getElementById('promptLastUpdated');
                if (el) el.innerText = `Last updated: ${new Date(res.data.updated_at).toLocaleString()}`;
            }
        } else {
            console.warn('[PhasedPrompts] Unexpected response:', res);
        }
    } catch (err) {
        console.error('[PhasedPrompts] Failed to load prompts:', err);
        if (typeof showToast === 'function') {
            showToast('Error loading prompts: ' + err.message, 'error');
        }
    } finally {
        isPromptsLoading = false;
    }
}

function renderCurrentPromptTab() {
    // 1. Update active tab button styles
    document.querySelectorAll('.phase-tab-btn').forEach(btn => {
        if (btn.getAttribute('data-phase') === activePromptPhase) {
            btn.classList.add('active');
            btn.style.borderBottom = '2px solid var(--brand-primary, #2563eb)';
            btn.style.fontWeight = '600';
            btn.style.color = 'var(--brand-primary, #2563eb)';
        } else {
            btn.classList.remove('active');
            btn.style.borderBottom = 'none';
            btn.style.fontWeight = '500';
            btn.style.color = 'var(--brand-text-muted, #64748b)';
        }
    });

    // 2. Update description banner
    const descEl = document.getElementById('phaseDescriptionText');
    if (descEl) {
        descEl.innerText = phaseDescriptions[activePromptPhase] || '';
    }

    // 3. Set textarea value
    const textarea = document.getElementById('promptText');
    if (textarea) {
        textarea.value = phasedPromptsData[activePromptPhase] || '';
        updatePromptCharCount(textarea.value);
        if (typeof autoResizePromptText === 'function') {
            autoResizePromptText();
            setTimeout(autoResizePromptText, 60);
        }
    }
}

function switchPromptPhase(phaseKey) {
    if (!phaseKey) return;

    // Sync any unsaved text in current textarea to local in-memory buffer before switching
    const textarea = document.getElementById('promptText');
    if (textarea && activePromptPhase) {
        phasedPromptsData[activePromptPhase] = textarea.value;
    }

    activePromptPhase = phaseKey;
    renderCurrentPromptTab();
}

function updatePromptCharCount(text) {
    const el = document.getElementById('promptCharCount');
    if (el) {
        const len = (text || '').length;
        const estTokens = Math.round(len / 4);
        el.innerText = `${len.toLocaleString()} characters (~${estTokens.toLocaleString()} tokens)`;
    }
}

async function saveCurrentPhasePrompt(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }

    const textarea = document.getElementById('promptText');
    if (!textarea) return false;

    const newText = textarea.value.trim();
    if (!newText) {
        if (typeof showToast === 'function') {
            showToast('Prompt text cannot be empty.', 'error');
        } else {
            alert('Prompt text cannot be empty.');
        }
        return false;
    }

    const saveBtn = document.getElementById('savePromptBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerText = 'Saving...';
    }

    try {
        const payload = {
            key: activePromptPhase,
            prompt: newText
        };
        const res = await apiFetch('/v1/superadmin/prompt', {
            method: 'PUT',
            body: JSON.stringify(payload)
        });

        if (res && res.status === 'success') {
            phasedPromptsData[activePromptPhase] = newText;
            const phaseTitle = activePromptPhase.replace('master_prompt_', '').replace(/_/g, ' ').toUpperCase();
            if (typeof showToast === 'function') {
                showToast(`Prompt for ${phaseTitle} saved successfully!`, 'success');
            }
            const updatedEl = document.getElementById('promptLastUpdated');
            if (updatedEl) {
                updatedEl.innerText = `Last updated: ${new Date().toLocaleString()}`;
            }
        } else {
            const msg = (res && res.message) ? res.message : 'Failed to save prompt.';
            if (typeof showToast === 'function') {
                showToast(msg, 'error');
            } else {
                alert(msg);
            }
        }
    } catch (err) {
        console.error('[PhasedPrompts] Save error:', err);
        if (typeof showToast === 'function') {
            showToast('Save error: ' + err.message, 'error');
        }
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerText = '💾 Save Active Phase Prompt';
        }
    }

    return false;
}

function setupPhasedPromptsListeners() {
    const textarea = document.getElementById('promptText');
    if (textarea) {
        textarea.addEventListener('input', (e) => {
            phasedPromptsData[activePromptPhase] = e.target.value;
            updatePromptCharCount(e.target.value);
        });
    }

    const form = document.getElementById('promptForm');
    if (form) {
        form.onsubmit = function(e) {
            e.preventDefault();
            saveCurrentPhasePrompt(e);
            return false;
        };
    }

    const saveBtn = document.getElementById('savePromptBtn');
    if (saveBtn) {
        saveBtn.onclick = function(e) {
            e.preventDefault();
            saveCurrentPhasePrompt(e);
            return false;
        };
    }

    // Refresh prompt when clicking the Master System Prompt nav tab
    document.querySelectorAll('.nav-link[data-tab="prompt"]').forEach(link => {
        link.addEventListener('click', () => {
            loadPhasedPrompts();
        });
    });
}

function initPhasedPrompts() {
    setupPhasedPromptsListeners();
    loadPhasedPrompts();
}

// Ensure execution whether DOM is loading or already parsed
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPhasedPrompts);
} else {
    initPhasedPrompts();
}

// Global window exposure for inline HTML onclick handlers
window.loadPhasedPrompts = loadPhasedPrompts;
window.loadMasterPrompt = loadPhasedPrompts;
window.switchPromptPhase = switchPromptPhase;
window.saveCurrentPhasePrompt = saveCurrentPhasePrompt;
