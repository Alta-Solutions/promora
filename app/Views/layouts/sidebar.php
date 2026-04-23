<?php
// app/Views/layouts/sidebar.php
?>

<aside class="sidebar">
    <div class="sidebar-header">
        <h3>🛍️ Promo Manager</h3>
    </div>
    
    <nav class="sidebar-nav">
        <a href="?route=dashboard" class="sidebar-link <?= ($_GET['route'] ?? 'dashboard') === 'dashboard' ? 'active' : '' ?>">
            <span class="icon">📊</span>
            <span class="text">Dashboard</span>
        </a>
        
        <a href="?route=promotions" class="sidebar-link <?= ($_GET['route'] ?? '') === 'promotions' ? 'active' : '' ?>">
            <span class="icon">🏷️</span>
            <span class="text">Promocije</span>
        </a>
        
        <a href="?route=logs" class="sidebar-link <?= ($_GET['route'] ?? '') === 'logs' ? 'active' : '' ?>">
            <span class="icon">📋</span>
            <span class="text">Logovi</span>
        </a>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e7eb;">
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">Brze akcije</div>
            
            <button onclick="quickSync()" class="sidebar-action-btn">
                <span class="icon">🔄</span>
                <span class="text">Sinhronizuj</span>
            </button>
            
            <a href="?route=promotions&action=create" class="sidebar-action-btn">
                <span class="icon">➕</span>
                <span class="text">Nova promocija</span>
            </a>
        </div>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e7eb;">
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">Statistika</div>
            
            <div class="sidebar-stat">
                <div class="sidebar-stat-label">Aktivne</div>
                <div class="sidebar-stat-value" id="active-promos-count">-</div>
            </div>
            
            <div class="sidebar-stat">
                <div class="sidebar-stat-label">Proizvoda</div>
                <div class="sidebar-stat-value" id="active-products-count">-</div>
            </div>
            
            <div class="sidebar-stat">
                <div class="sidebar-stat-label">Poslednji sync</div>
                <div class="sidebar-stat-value" id="last-sync-time" style="font-size: 11px;">-</div>
            </div>
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <div style="font-size: 12px; color: #6b7280; text-align: center;">
            <strong>Promo Manager</strong><br>
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
    btn.innerHTML = '<span class="icon">⏳</span><span class="text">Sinhronizacija...</span>';
    
    try {
        const response = await fetch('?route=api&action=sync_all');
        const result = await response.json();
        
        if (result.success) {
            window.appUtils.toast.success('✅ Sinhronizacija uspešna!');
            updateSidebarStats();
        } else {
            window.appUtils.toast.error('❌ Greška pri sinhronizaciji');
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
            const timeStr = date.toLocaleTimeString('sr-RS', { hour: '2-digit', minute: '2-digit' });
            document.getElementById('last-sync-time').textContent = timeStr;
        }
    } catch (error) {
        console.error('Error updating stats:', error);
    }
}

// Auto-refresh stats every 30 seconds
if (document.querySelector('.sidebar')) {
    updateSidebarStats();
    setInterval(updateSidebarStats, 10000);
}
</script>