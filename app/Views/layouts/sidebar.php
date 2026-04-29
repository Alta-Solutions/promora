<?php
// app/Views/layouts/sidebar.php
?>

<aside class="sidebar">
    <div class="sidebar-header">
        <h3>🛍️ <?= trans_e('auth.login_heading') ?></h3>
    </div>
    
    <nav class="sidebar-nav">
        <a href="?route=dashboard" class="sidebar-link <?= ($_GET['route'] ?? 'dashboard') === 'dashboard' ? 'active' : '' ?>">
            <span class="icon">📊</span>
            <span class="text"><?= trans_e('common.dashboard') ?></span>
        </a>
        
        <a href="?route=promotions" class="sidebar-link <?= ($_GET['route'] ?? '') === 'promotions' ? 'active' : '' ?>">
            <span class="icon">🏷️</span>
            <span class="text"><?= trans_e('common.promotions') ?></span>
        </a>
        
        <a href="?route=logs" class="sidebar-link <?= ($_GET['route'] ?? '') === 'logs' ? 'active' : '' ?>">
            <span class="icon">📋</span>
            <span class="text"><?= trans_e('common.logs') ?></span>
        </a>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e7eb;">
        
        <div class="sidebar-section">
            <div class="sidebar-section-title"><?= trans_e('sidebar.quick_actions') ?></div>
            
            <button onclick="quickSync()" class="sidebar-action-btn">
                <span class="icon">🔄</span>
                <span class="text"><?= trans_e('common.sync') ?></span>
            </button>
            
            <a href="?route=promotions&action=create" class="sidebar-action-btn">
                <span class="icon">➕</span>
                <span class="text"><?= trans_e('sidebar.new_promotion') ?></span>
            </a>
        </div>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e7eb;">
        
        <div class="sidebar-section">
            <div class="sidebar-section-title"><?= trans_e('sidebar.statistics') ?></div>
            
            <div class="sidebar-stat">
                <div class="sidebar-stat-label"><?= trans_e('sidebar.active') ?></div>
                <div class="sidebar-stat-value" id="active-promos-count">-</div>
            </div>
            
            <div class="sidebar-stat">
                <div class="sidebar-stat-label"><?= trans_e('sidebar.products') ?></div>
                <div class="sidebar-stat-value" id="active-products-count">-</div>
            </div>
            
            <div class="sidebar-stat">
                <div class="sidebar-stat-label"><?= trans_e('sidebar.last_sync') ?></div>
                <div class="sidebar-stat-value" id="last-sync-time" style="font-size: 11px;">-</div>
            </div>
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <div style="font-size: 12px; color: #6b7280; text-align: center;">
            <strong><?= trans_e('auth.login_heading') ?></strong><br>
            v1.0.0
        </div>
    </div>
</aside>

<script>
// Quick sync function
async function quickSync() {
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="icon">⏳</span><span class="text">' + appT('sidebar.syncing') + '</span>';
    
    try {
        const response = await fetch('?route=api&action=sync_all');
        const result = await response.json();
        
        if (result.success) {
            window.appUtils.toast.success('✅ ' + appT('sidebar.sync_success'));
            updateSidebarStats();
        } else {
            window.appUtils.toast.error('❌ ' + appT('sidebar.sync_error'));
        }
    } catch (error) {
        window.appUtils.toast.error('❌ ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// Update sidebar stats
async function updateSidebarStats() {
    try {
        const response = await fetch('?route=api&action=get_stats');
        const stats = await response.json();
        
        document.getElementById('active-promos-count').textContent = stats.active_promotions || 0;
        document.getElementById('active-products-count').textContent = stats.total_products || 0;
        
        if (stats.last_sync) {
            const date = new Date(stats.last_sync);
            const timeStr = date.toLocaleTimeString(window.appLocale || 'sr-RS', { hour: '2-digit', minute: '2-digit' });
            document.getElementById('last-sync-time').textContent = timeStr;
        }
    } catch (error) {
        console.error(appT('sidebar.stats_error'), error);
    }
}

// Auto-refresh stats every 30 seconds
if (document.querySelector('.sidebar')) {
    updateSidebarStats();
    setInterval(updateSidebarStats, 10000);
}
</script>
