/**
 * IPManager Pro - Universal Search System
 */

const searchModal = document.getElementById('search-modal');
const searchInput = document.getElementById('search-input');
const searchResults = document.getElementById('search-results');
let debounceTimer;
let selectedIndex = -1;
let currentResults = [];

// Hotkeys
document.addEventListener('keydown', (e) => {
    // Cmd+K or Ctrl+K
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        openSearch();
    }
    
    // ESC
    if (e.key === 'Escape' && searchModal && searchModal.style.display !== 'none') {
        closeSearch();
    }

    // Navigation
    if (searchModal && searchModal.style.display !== 'none') {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            navigateResults(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            navigateResults(-1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedIndex >= 0 && currentResults[selectedIndex]) {
                selectResult(currentResults[selectedIndex]);
            }
        }
    }
});

function openSearch() {
    searchModal.style.display = 'flex';
    searchInput.focus();
    searchInput.value = '';
    renderInitial();
    if (window.lucide) lucide.createIcons();
}

function closeSearch() {
    searchModal.style.display = 'none';
    searchInput.blur();
}

function renderInitial() {
    searchResults.innerHTML = '<div class="search-empty">Type at least 2 characters to search, or <b>&gt;</b> for actions</div>';
    selectedIndex = -1;
}

searchInput.addEventListener('input', (e) => {
    clearTimeout(debounceTimer);
    const q = e.target.value.trim();
    
    // Command Actions (e.g. >logout)
    if (q.startsWith('>')) {
        renderActions(q.slice(1).trim());
        return;
    }

    // Normal Search
    if (q.length < 2) {
        renderInitial();
        return;
    }

    debounceTimer = setTimeout(() => {
        performSearch(q);
    }, 300);
});

function renderActions(query) {
    const actions = [
        { type: 'action', title: 'Logout Account', subtitle: 'Securely sign out', icon: 'log-out', url: 'logout' },
        { type: 'action', title: 'System Settings', subtitle: 'Manage configuration', icon: 'settings', url: 'settings' },
        { type: 'action', title: 'Add New Subnet', subtitle: 'Create network block', icon: 'plus-circle', url: 'add-subnet' },
        { type: 'action', title: 'Network Reports', subtitle: 'Full health analytics', icon: 'bar-chart-3', url: 'reports' },
        { type: 'action', title: 'Network Map', subtitle: 'Visual topology map', icon: 'map', url: 'topology' }
    ];

    const filtered = actions.filter(a => a.title.toLowerCase().includes(query.toLowerCase()));
    currentResults = filtered;
    renderResults(filtered);
}

async function performSearch(q) {
    // Double check that we aren't performing a command search
    if (q.startsWith('>')) return;
    
    searchResults.innerHTML = '<div class="search-empty"><i data-lucide="loader" class="spin"></i> Searching...</div>';
    if (window.lucide) lucide.createIcons();

    try {
        const response = await fetch(`api/universal-search.php?q=${encodeURIComponent(q)}`);
        
        // Handle non-200 responses
        if (!response.ok) {
            searchResults.innerHTML = `<div class="search-empty text-danger">Server error: ${response.status}</div>`;
            return;
        }
        
        const data = await response.json();
        currentResults = data;
        renderResults(data);
    } catch (err) {
        searchResults.innerHTML = '<div class="search-empty text-danger">Network error. Please try again.</div>';
    }
}

function renderResults(data) {
    if (data.length === 0) {
        searchResults.innerHTML = '<div class="search-empty">No results found matching your query.</div>';
        return;
    }

    let html = '';
    data.forEach((item, index) => {
        const icon = getIcon(item.type);
        const subtitle = item.subtitle || item.description || (item.subnet ? (item.subnet + '/' + item.mask) : '');
        html += `
            <div class="search-item" data-index="${index}" onclick="selectResult(currentResults[${index}])">
                <i data-lucide="${icon}"></i>
                <div class="search-item-info">
                    <span class="search-item-title">${item.title || item.ip_addr || 'Untitled'}</span>
                    <span class="search-item-subtitle">${subtitle}</span>
                </div>
                <span class="search-item-type">${item.type}</span>
            </div>
        `;
    });
    searchResults.innerHTML = html;
    selectedIndex = 0;
    updateSelection();
    if (window.lucide) lucide.createIcons();
}

function getIcon(type) {
    switch(type) {
        case 'asset': return 'server';
        case 'subnet': return 'layers';
        case 'switch': return 'vibrate';
        case 'action': return 'zap';
        default: return 'hash';
    }
}

function navigateResults(dir) {
    const items = searchResults.querySelectorAll('.search-item');
    if (items.length === 0) return;

    selectedIndex += dir;
    if (selectedIndex < 0) selectedIndex = items.length - 1;
    if (selectedIndex >= items.length) selectedIndex = 0;

    updateSelection();
}

function updateSelection() {
    const items = searchResults.querySelectorAll('.search-item');
    items.forEach((item, idx) => {
        if (idx === selectedIndex) {
            item.classList.add('selected');
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.classList.remove('selected');
        }
    });
}

function selectResult(item) {
    let url = '';
    switch(item.type) {
        case 'asset': url = 'server-assets'; break;
        case 'subnet': url = `subnet-details?id=${item.id}`; break;
        case 'switch': url = `switch-details?id=${item.id}`; break;
    }
    
    if (url) {
        closeSearch();
        window.location.href = url;
    }
}

// Close on outside click
searchModal.addEventListener('click', (e) => {
    if (e.target === searchModal) closeSearch();
});
