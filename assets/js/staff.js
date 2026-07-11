/**
 * MDT Service Public - JavaScript Panel Staff
 */

// Toggle sidebar on mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('show');
}

// Toggle notifications
function toggleNotifications() {
    // À implémenter selon vos besoins
    MDT.showNotification('Fonctionnalité en développement', 'info');
}

// Auto-refresh logs every 30 seconds
let logsRefreshInterval;

function startLogsAutoRefresh() {
    logsRefreshInterval = setInterval(() => {
        if (window.location.pathname.includes('dashboard.php') || window.location.pathname.includes('logs.php')) {
            refreshLogs();
        }
    }, 30000);
}

async function refreshLogs() {
    try {
        const response = await fetch(window.location.href, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (response.ok) {
            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newLogsList = doc.querySelector('.logs-list');
            const currentLogsList = document.querySelector('.logs-list');
            
            if (newLogsList && currentLogsList) {
                currentLogsList.innerHTML = newLogsList.innerHTML;
            }
        }
    } catch (error) {
        console.error('Error refreshing logs:', error);
    }
}

// Filter table by multiple columns
function filterTable(tableId, filters) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const rows = table.querySelectorAll('tbody tr');
    
    rows.forEach(row => {
        let show = true;
        
        Object.keys(filters).forEach(columnIndex => {
            const cell = row.cells[columnIndex];
            const filterValue = filters[columnIndex].toLowerCase();
            
            if (cell && filterValue) {
                const cellText = cell.textContent.toLowerCase();
                if (!cellText.includes(filterValue)) {
                    show = false;
                }
            }
        });
        
        row.style.display = show ? '' : 'none';
    });
}

// Export table to CSV
function exportTableToCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = Array.from(cols).map(col => {
            return '"' + col.textContent.replace(/"/g, '""') + '"';
        });
        csv.push(rowData.join(','));
    });
    
    downloadCSV(csv.join('\n'), filename);
}

function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    
    if (link.download !== undefined) {
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}

// Bulk actions for users
let selectedUsers = new Set();

function toggleUserSelection(userId, checkbox) {
    if (checkbox.checked) {
        selectedUsers.add(userId);
    } else {
        selectedUsers.delete(userId);
    }
    
    updateBulkActionsBar();
}

function toggleAllUsers(checkbox) {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
        toggleUserSelection(cb.dataset.userId, cb);
    });
}

function updateBulkActionsBar() {
    const bulkBar = document.getElementById('bulkActionsBar');
    const count = document.getElementById('selectedCount');
    
    if (bulkBar && count) {
        count.textContent = selectedUsers.size;
        bulkBar.style.display = selectedUsers.size > 0 ? 'flex' : 'none';
    }
}

function bulkAction(action) {
    if (selectedUsers.size === 0) {
        MDT.showNotification('Aucun utilisateur sélectionné', 'warning');
        return;
    }
    
    const userIds = Array.from(selectedUsers);
    
    switch (action) {
        case 'activate':
            confirmBulkActivate(userIds);
            break;
        case 'deactivate':
            confirmBulkDeactivate(userIds);
            break;
        case 'delete':
            confirmBulkDelete(userIds);
            break;
        default:
            MDT.showNotification('Action non reconnue', 'error');
    }
}

function confirmBulkActivate(userIds) {
    MDT.confirmAction(
        `Activer ${userIds.length} utilisateur(s) ?`,
        () => executeBulkAction('activate', userIds)
    );
}

function confirmBulkDeactivate(userIds) {
    MDT.confirmAction(
        `Désactiver ${userIds.length} utilisateur(s) ?`,
        () => executeBulkAction('deactivate', userIds)
    );
}

function confirmBulkDelete(userIds) {
    MDT.confirmAction(
        `ATTENTION : Supprimer définitivement ${userIds.length} utilisateur(s) ? Cette action est irréversible !`,
        () => executeBulkAction('delete', userIds)
    );
}

async function executeBulkAction(action, userIds) {
    try {
        const formData = new FormData();
        formData.append('bulk_action', action);
        formData.append('user_ids', JSON.stringify(userIds));
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        if (response.ok) {
            MDT.showNotification('Action effectuée avec succès', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            MDT.showNotification('Erreur lors de l\'action', 'error');
        }
    } catch (error) {
        console.error('Bulk action error:', error);
        MDT.showNotification('Erreur réseau', 'error');
    }
}

// Real-time search with debounce
function setupRealtimeSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    
    const debouncedSearch = MDT.debounce((value) => {
        MDT.searchTable(input, tableId);
    }, 300);
    
    input.addEventListener('input', (e) => {
        debouncedSearch(e.target.value);
    });
}

// Initialize tooltips for staff panel
function initStaffTooltips() {
    const tooltips = document.querySelectorAll('[data-tooltip]');
    
    tooltips.forEach(element => {
        element.addEventListener('mouseenter', showTooltip);
        element.addEventListener('mouseleave', hideTooltip);
    });
}

function showTooltip(e) {
    const element = e.currentTarget;
    const text = element.dataset.tooltip;
    
    const tooltip = document.createElement('div');
    tooltip.className = 'staff-tooltip';
    tooltip.textContent = text;
    tooltip.id = 'current-tooltip';
    
    document.body.appendChild(tooltip);
    
    const rect = element.getBoundingClientRect();
    tooltip.style.top = `${rect.bottom + 8}px`;
    tooltip.style.left = `${rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)}px`;
}

function hideTooltip() {
    const tooltip = document.getElementById('current-tooltip');
    if (tooltip) {
        tooltip.remove();
    }
}

// Stats animation on load
function animateStats() {
    const statValues = document.querySelectorAll('.stat-value');
    
    statValues.forEach(stat => {
        const finalValue = parseInt(stat.textContent.replace(/\D/g, ''));
        if (isNaN(finalValue)) return;
        
        let currentValue = 0;
        const increment = Math.ceil(finalValue / 50);
        const duration = 1000;
        const stepTime = duration / (finalValue / increment);
        
        const counter = setInterval(() => {
            currentValue += increment;
            if (currentValue >= finalValue) {
                currentValue = finalValue;
                clearInterval(counter);
            }
            stat.textContent = currentValue.toLocaleString();
        }, stepTime);
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    // Start auto-refresh for logs
    startLogsAutoRefresh();
    
    // Setup realtime search if exists
    if (document.getElementById('userSearch')) {
        setupRealtimeSearch('userSearch', 'usersTable');
    }
    
    // Initialize tooltips
    initStaffTooltips();
    
    // Animate stats on dashboard
    if (window.location.pathname.includes('dashboard.php')) {
        setTimeout(animateStats, 300);
    }
    
    // Close sidebar on mobile when clicking outside
    document.addEventListener('click', (e) => {
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        
        if (window.innerWidth <= 768 && 
            sidebar && 
            sidebar.classList.contains('show') &&
            !sidebar.contains(e.target) && 
            !toggle.contains(e.target)) {
            sidebar.classList.remove('show');
        }
    });
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (logsRefreshInterval) {
        clearInterval(logsRefreshInterval);
    }
});

// Export functions to window
window.StaffPanel = {
    toggleSidebar,
    toggleNotifications,
    refreshLogs,
    filterTable,
    exportTableToCSV,
    toggleUserSelection,
    toggleAllUsers,
    bulkAction
};
