const surveyType = "SURVEY_TYPE_PLACEHOLDER";
const surveyId = "SURVEY_ID_PLACEHOLDER";
const hierarchyLevelMap = HIERARCHY_LEVELS_PLACEHOLDER;

// Simple Rich Text Editor Functions
function formatText(command, editorId) {
    const editor = document.getElementById(editorId);
    if (editor) {
        editor.focus();
        document.execCommand(command, false, null);
        updatePreview(editorId);
        updateToolbarStates();
    }
}

function changeTextSize(size, editorId) {
    const editor = document.getElementById(editorId);
    if (editor) {
        editor.focus();
        if (window.getSelection) {
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                const span = document.createElement('span');
                span.style.fontSize = size;
                
                try {
                    range.surroundContents(span);
                } catch (e) {
                    span.appendChild(range.extractContents());
                    range.insertNode(span);
                }
                selection.removeAllRanges();
            }
        }
        updatePreview(editorId);
    }
}

function changeTextColor(color, editorId) {
    const editor = document.getElementById(editorId);
    if (editor) {
        editor.focus();
        document.execCommand('foreColor', false, color);
        updatePreview(editorId);
    }
    
    // Hide color picker
    document.querySelectorAll('.color-picker').forEach(picker => picker.style.display = 'none');
}

function toggleColorPicker(pickerId) {
    const picker = document.getElementById(pickerId);
    if (picker) {
        const isVisible = picker.style.display === 'block';
        
        // Hide all color pickers first
        document.querySelectorAll('.color-picker').forEach(p => p.style.display = 'none');
        
        // Toggle the clicked one
        picker.style.display = isVisible ? 'none' : 'block';
    }
}

function updatePreview(editorId) {
    const content = document.getElementById(editorId);
    let targetElement;
    
    if (editorId === 'edit-subheading') {
        targetElement = document.getElementById('survey-subheading');
    } else if (editorId === 'edit-rating-instruction-1') {
        targetElement = document.getElementById('rating-instruction-1');
    } else if (editorId === 'edit-rating-instruction-2') {
        targetElement = document.getElementById('rating-instruction-2');
    }
    
    if (targetElement && content) {
        targetElement.innerHTML = content.innerHTML;
    }
}

function updateToolbarStates() {
    const buttons = document.querySelectorAll('.toolbar-button[data-command]');
    buttons.forEach(button => {
        const command = button.getAttribute('data-command');
        try {
            if (document.queryCommandState && document.queryCommandState(command)) {
                button.classList.add('active');
            } else {
                button.classList.remove('active');
            }
        } catch (e) {
            // Ignore errors for unsupported commands
        }
    });
}

// Toast notification function
function showToast(message, type = 'info') {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast');
    existingToasts.forEach(toast => toast.remove());

    // Create new toast
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
    `;

    // Add styles
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#f44336' : '#2196F3'};
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        max-width: 400px;
        animation: slideIn 0.3s ease;
    `;

    document.body.appendChild(toast);

    // Remove toast after 3 seconds
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Accordion functionality
function toggleAccordion(element) {
    const content = element.nextElementSibling;
    const icon = element.querySelector('.accordion-icon');
    
    if (content && icon) {
        if (content.style.display === 'none' || content.style.display === '') {
            content.style.display = 'block';
            icon.style.transform = 'rotate(180deg)';
            element.classList.add('active');
        } else {
            content.style.display = 'none';
            icon.style.transform = 'rotate(0deg)';
            element.classList.remove('active');
        }
    }
}

// Global functions for save/reset buttons
window.savePreviewSettings = async function() {
    try {
        const settings = {
            surveyId: surveyId,
            logoSrc: document.getElementById('logoImg')?.src || '',
            showLogo: document.getElementById('toggleLogo')?.checked || false,
            flagBlackColor: document.getElementById('flagBlackColorPicker')?.value || '#000000',
            flagYellowColor: document.getElementById('flagYellowColorPicker')?.value || '#FCD116',
            flagRedColor: document.getElementById('flagRedColorPicker')?.value || '#D21034',
            showFlagBar: document.getElementById('toggleFlagBar')?.checked || false,
            titleText: document.getElementById('editTitle')?.value || '',
            showTitle: document.getElementById('toggleTitle')?.checked || false,
            subheadingText: document.getElementById('editSubheading')?.innerHTML || '',
            showSubheading: document.getElementById('toggleSubheading')?.checked || false,
            showSubmitButton: document.getElementById('toggleSubmitButton')?.checked || false,
            ratingInstruction1Text: document.getElementById('editRatingInstruction1')?.innerHTML || '',
            ratingInstruction2Text: document.getElementById('editRatingInstruction2')?.innerHTML || '',
            showRatingInstructions: document.getElementById('toggleRatingInstructions')?.checked || false,
            showFacilitySection: surveyType === 'dhis2' || (document.getElementById('toggleFacilitySection')?.checked || false),
            showLocationRowGeneral: document.getElementById('toggleLocationRowGeneral')?.checked || false,
            showLocationRowPeriodAge: document.getElementById('toggleLocationRowPeriodAge')?.checked || false,
            showOwnershipSection: document.getElementById('toggleOwnershipSection')?.checked || false,
            republicTitleText: document.getElementById('editRepublicTitleShare')?.value || '',
            showRepublicTitleShare: document.getElementById('toggleRepublicTitleShare')?.checked || false,
            ministrySubtitleText: document.getElementById('editMinistrySubtitleShare')?.value || '',
            showMinistrySubtitleShare: document.getElementById('toggleMinistrySubtitleShare')?.checked || false,
            qrInstructionsText: document.getElementById('editQrInstructionsShare')?.value || '',
            showQrInstructionsShare: document.getElementById('toggleQrInstructionsShare')?.checked || false,
            footerNoteText: document.getElementById('editFooterNoteShare')?.value || '',
            showFooterNoteShare: document.getElementById('toggleFooterNoteShare')?.checked || false,
            selectedInstanceKey: document.getElementById('controlInstanceKeySelect')?.value || null,
            selectedHierarchyLevel: (() => {
                const select = document.getElementById('controlHierarchyLevelSelect');
                return select && select.value !== '' ? parseInt(select.value, 10) : null;
            })()
        };

        const response = await fetch('save_survey_settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(settings)
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'Failed to save settings due to a server error.');
        }

        showToast(data.message || 'Settings saved successfully!', 'success');

    } catch (error) {
        console.error('Error saving settings:', error);
        showToast(error.message || 'An unexpected error occurred while saving.', 'error');
    }
};

window.resetPreviewSettings = async function() {
    if (!confirm('Are you sure you want to reset all preview settings to their default values? This cannot be undone.')) {
        return;
    }

    try {
        const response = await fetch('reset_survey_settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `survey_id=${encodeURIComponent(surveyId)}`
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'Failed to reset settings due to a server error.');
        }

        showToast(data.message || 'Settings reset successfully!', 'success');
        
        setTimeout(() => {
            location.reload();
        }, 1000);

    } catch (error) {
        console.error('Error resetting settings:', error);
        showToast(error.message || 'An unexpected error occurred while resetting.', 'error');
    }
};

document.addEventListener('DOMContentLoaded', function() {
    // Initialize accordion dropdowns to be closed
    const accordionContents = document.querySelectorAll('.accordion-content');
    accordionContents.forEach(content => {
        content.style.display = 'none';
    });

    // Initialize accordion icons
    const accordionIcons = document.querySelectorAll('.accordion-icon');
    accordionIcons.forEach(icon => {
        icon.style.transform = 'rotate(0deg)';
    });

    // Setup rich text editor event listeners
    const editSubheading = document.getElementById('editSubheading');
    const editRatingInstruction1 = document.getElementById('editRatingInstruction1');
    const editRatingInstruction2 = document.getElementById('editRatingInstruction2');
    
    if (editSubheading) {
        editSubheading.addEventListener('input', () => updatePreview('edit-subheading'));
        editSubheading.addEventListener('keyup', updateToolbarStates);
        editSubheading.addEventListener('mouseup', updateToolbarStates);
    }
    if (editRatingInstruction1) {
        editRatingInstruction1.addEventListener('input', () => updatePreview('edit-rating-instruction-1'));
        editRatingInstruction1.addEventListener('keyup', updateToolbarStates);
        editRatingInstruction1.addEventListener('mouseup', updateToolbarStates);
    }
    if (editRatingInstruction2) {
        editRatingInstruction2.addEventListener('input', () => updatePreview('edit-rating-instruction-2'));
        editRatingInstruction2.addEventListener('keyup', updateToolbarStates);
        editRatingInstruction2.addEventListener('mouseup', updateToolbarStates);
    }

    // Setup toolbar button event listeners
    const toolbarButtons = document.querySelectorAll('.toolbar-button[data-command]');
    toolbarButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const command = button.getAttribute('data-command');
            const toolbar = button.closest('.rich-text-toolbar');
            const editor = toolbar ? toolbar.nextElementSibling : null;
            if (editor) {
                formatText(command, editor.id);
            }
        });
    });

    // Close color pickers when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.color-picker-wrapper')) {
            document.querySelectorAll('.color-picker').forEach(picker => {
                picker.style.display = 'none';
            });
        }
    });

    // Show/hide facility section based on survey type and checkbox
    const toggleFacilitySection = document.getElementById('toggleFacilitySection');
    
    function updateFacilitySectionVisibility() {
        const facilitySection = document.getElementById('facility-section');
        const instanceKeyFilterGroup = document.getElementById('instance-key-filter-group');
        const hierarchyLevelFilterGroup = document.getElementById('hierarchy-level-filter-group');
        
        if (surveyType === 'dhis2' || (toggleFacilitySection && toggleFacilitySection.checked)) {
            if (facilitySection) facilitySection.classList.remove('hidden-element');
            if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'block';
            if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'block';
        } else {
            if (facilitySection) facilitySection.classList.add('hidden-element');
            if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'none';
            if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'none';
        }
    }
    
    if (toggleFacilitySection) {
        toggleFacilitySection.addEventListener('change', updateFacilitySectionVisibility);
    }
    
    // Initial call to set correct visibility
    updateFacilitySectionVisibility();

    // Handle share button functionality  
    const shareButton = document.querySelector('button[onclick*="share_page.php"]');
    if (shareButton) {
        shareButton.addEventListener('click', async function(e) {
            e.preventDefault();
            try {
                // Save settings first, then navigate to share page
                await window.savePreviewSettings();
                const surveyUrl = window.location.origin + window.location.pathname.replace('preview_form.php', 'survey_page.php') + '?survey_id=' + surveyId;
                window.location.href = `share_page.php?survey_id=${surveyId}&url=${encodeURIComponent(surveyUrl)}`;
            } catch (error) {
                console.error('Share button error:', error);
                showToast('Error saving settings. Please try again.', 'error');
            }
        });
    }
});