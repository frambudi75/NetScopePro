/**
 * Topology Manager Helper Functions
 * Moved to external file to comply with strict Content Security Policy (CSP)
 */

function toggleTargets() {
    const typeSelect = document.getElementById('targetType');
    if (!typeSelect) return;

    const type = typeSelect.value;
    const parentSelect = document.getElementsByName('parent_switch_id')[0];
    if (!parentSelect) return;

    const sourceId = parentSelect.value;
    
    const subnetDiv = document.getElementById('subnetList');
    const switchDiv = document.getElementById('switchList');

    if (subnetDiv) subnetDiv.style.display = type === 'subnet' ? 'block' : 'none';
    if (switchDiv) switchDiv.style.display = type === 'switch' ? 'block' : 'none';

    if (type === 'switch') {
        const switchSelect = document.getElementsByName('target_id_switch')[0];
        if (!switchSelect) return;

        let firstValidIndex = -1;

        for (let i = 0; i < switchSelect.options.length; i++) {
            if (switchSelect.options[i].value === sourceId && sourceId !== "") {
                switchSelect.options[i].disabled = true;
                switchSelect.options[i].hidden = true;
                if (switchSelect.selectedIndex === i) {
                    switchSelect.selectedIndex = (i + 1) % switchSelect.options.length;
                }
            } else {
                switchSelect.options[i].disabled = false;
                switchSelect.options[i].hidden = false;
                if (firstValidIndex === -1) firstValidIndex = i;
            }
        }
        
        if (switchSelect.value === sourceId && sourceId !== "" && firstValidIndex !== -1) {
            switchSelect.selectedIndex = firstValidIndex;
        }
    }
}

function prepareSubmit() {
    const typeSelect = document.getElementById('targetType');
    if (!typeSelect) return;

    const type = typeSelect.value;
    const selector = type === 'subnet' ? 'target_id_subnet' : 'target_id_switch';
    const targetSelect = document.getElementsByName(selector)[0];
    const finalTarget = document.getElementById('finalTargetId');

    if (targetSelect && finalTarget) {
        finalTarget.value = targetSelect.value;
    }
}

// Global Initialization
window.addEventListener('load', function() {
    toggleTargets();
});

document.addEventListener('DOMContentLoaded', function() {
    toggleTargets();
    // Re-initialize Lucide icons if available
    if (window.lucide) {
        window.lucide.createIcons();
    }
});
