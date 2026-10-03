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

const phaseDescriptions = {
    master_prompt_phase_1: 'Phase 1: Discovery & Rapport — Used when the visitor has NOT yet chosen a specific program. Friendly, warm, chatty, probing. Answers general questions and catalog requests. Zero lead pitching.',
    master_prompt_phase_2: 'Phase 2: Consideration & Lead Enrichment — Used once a specific program is identified (e.g. Executive MBA). Deep answers on the program with alternating offers (Brochure → Campus Visit → Counselor Callback).',
    master_prompt_scholarship: 'Specialized Phase: Financial Aid & Scholarships — Triggered whenever the visitor asks about tuition, fees, costs, or scholarships. Empathetic, transparent, reassuring. Offers the official Scholarship Calculator.',
    master_prompt_phase_3: 'Phase 3: Post-Lead Application Advisory — Used when the visitor\'s contact details are already captured. Acts as a personal mentor for application milestones. Zero contact badgering.',
    master_prompt: 'Legacy Master Prompt — Used as a safety fallback if any of the above phase prompts are left blank.'
};

async function loadPhasedPrompts() {
    try {
        const res = await apiFetch('/v1/superadmin/prompt');
        if (res.status === 'success') {
            phasedPromptsData = {
                master_prompt_phase_1: res.data.master_prompt_phase_1 || '',
                master_prompt_phase_2: res.data.master_prompt_phase_2 || '',
                master_prompt_scholarship: res.data.master_prompt_scholarship || '',
                master_prompt_phase_3: res.data.master_prompt_phase_3 || '',
                master_prompt: res.data.master_prompt || ''
            };

            switchPromptPhase(activePromptPhase);
            if (res.data.updated_at) {
                const el = document.getElementById('promptLastUpdated');
                if (el) el.innerText = `Last updated: ${new Date(res.data.updated_at).toLocaleString()}`;
            }
        }
    } catch (err) {
        console.error('Failed to load phased prompts:', err);
        showToast('Error loading prompts: ' + err.message, 'error');
    }
}

function switchPromptPhase(phaseKey) {
    activePromptPhase = phaseKey;

    // Update active tab buttons
    document.querySelectorAll('.phase-tab-btn').forEach(btn => {
        if (btn.getAttribute('data-phase') === phaseKey) {
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

    // Update description banner
    const descEl = document.getElementById('phaseDescriptionText');
    if (descEl) {
        descEl.innerText = phaseDescriptions[phaseKey] || '';
    }

    // Set textarea content
    const textarea = document.getElementById('promptText');
    if (textarea) {
        textarea.value = phasedPromptsData[phaseKey] || '';
        updatePromptCharCount(textarea.value);
        if (typeof autoResizePromptText === 'function') {
            autoResizePromptText();
        }
    }
}

function updatePromptCharCount(text) {
    const el = document.getElementById('promptCharCount');
    if (el) {
        el.innerText = `${(text || '').length.toLocaleString()} characters (~${Math.round((text || '').length / 4).toLocaleString()} tokens)`;
    }
}

async function saveCurrentPhasePrompt(e) {
    if (e) e.preventDefault();
    const textarea = document.getElementById('promptText');
    if (!textarea) return;

    const newText = textarea.value.trim();
    if (!newText) {
        showToast('Prompt text cannot be empty.', 'error');
        return;
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

        if (res.status === 'success') {
            phasedPromptsData[activePromptPhase] = newText;
            showToast(`Prompt for ${activePromptPhase.replace('master_prompt_', '').toUpperCase()} saved successfully!`, 'success');
        } else {
            showToast(res.message || 'Failed to save prompt.', 'error');
        }
    } catch (err) {
        showToast('Save error: ' + err.message, 'error');
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerText = '💾 Save Active Phase Prompt';
        }
    }
}

// Keep textarea changes in local buffer
document.addEventListener('DOMContentLoaded', () => {
    const textarea = document.getElementById('promptText');
    if (textarea) {
        textarea.addEventListener('input', (e) => {
            phasedPromptsData[activePromptPhase] = e.target.value;
            updatePromptCharCount(e.target.value);
        });
    }

    const form = document.getElementById('promptForm');
    if (form) {
        form.onsubmit = saveCurrentPhasePrompt;
    }
});

// Global window exposure for inline onclick handlers
window.loadPhasedPrompts = loadPhasedPrompts;
window.loadMasterPrompt = loadPhasedPrompts;
window.switchPromptPhase = switchPromptPhase;
window.saveCurrentPhasePrompt = saveCurrentPhasePrompt;
