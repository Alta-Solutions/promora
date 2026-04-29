<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('dashboard.title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('dashboard.subtitle') ?></p>
    </div>
    <div style="font-size: 0.85rem; color: #6b7280; background: white; padding: 6px 12px; border-radius: 20px; border: 1px solid #e5e7eb;">
        📅 <?= date('d.m.Y') ?>
    </div>
</div>

<!-- Statistika -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label"><?= trans_e('dashboard.stats_total_promotions') ?></div>
        <div class="stat-value"><?= $stats['total_promotions'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label" style="color: #10b981;"><?= trans_e('dashboard.stats_active_promotions') ?></div>
        <div class="stat-value" style="color: #10b981;"><?= $stats['active_promotions'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= trans_e('dashboard.stats_products_in_promotion') ?></div>
        <div class="stat-value"><?= $stats['total_products'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= trans_e('dashboard.stats_last_sync') ?></div>
        <div class="stat-value" style="font-size: 1.2rem; margin-top: 8px;">
            <?= $stats['last_sync'] ? date('d.m.Y H:i', strtotime($stats['last_sync'])) : trans_e('common.never') ?>
        </div>
    </div>
</div>

<!-- Sync Progress (Hidden by default) -->
<div id="sync-progress-container" class="sync-progress">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="spinner" style="width: 20px; height: 20px; border-width: 2px; margin: 0;"></div>
            <h4 id="sync-title" style="margin: 0; font-size: 1rem;"><?= trans_e('dashboard.sync_in_progress') ?></h4>
        </div>
        <span id="sync-stats" style="font-weight: bold; color: #3b82f6;">0 / 0</span>
    </div>
    
    <div class="progress-track">
        <div id="sync-bar" class="progress-fill"></div>
    </div>
    <p id="sync-msg" style="font-size: 12px; color: #6b7280; margin-top: 8px;"><?= trans_e('dashboard.sync_not_interrupted') ?></p>
</div>

<!-- Glavni sadržaj -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    
    <!-- Lista aktivnih promocija -->
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 16px; color: #374151;"><?= trans_e('dashboard.active_promotions') ?></h3>
        <div class="active-promos-list">
            <?php if (!empty($activePromotions)): ?>
                <?php foreach ($activePromotions as $promo): ?>
                    <div class="promo-item">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 4px; height: 32px; background: <?= htmlspecialchars($promo['color']) ?>; border-radius: 2px;"></div>
                            <div>
                                <div style="font-weight: 600; color: #111827;"><?= htmlspecialchars($promo['name']) ?></div>
                                <div style="font-size: 0.75rem; color: #6b7280;">
                                    <?= date('d.m.', strtotime($promo['start_date'])) ?> - <?= date('d.m.Y', strtotime($promo['end_date'])) ?>
                                </div>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-weight: 700; color: #10b981; font-size: 1.1rem;"><?= $promo['discount_percent'] ?>%</div>
                            <div style="font-size: 0.75rem; color: #6b7280;"><?= trans_e('dashboard.priority_label', ['priority' => $promo['priority']]) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 40px; text-align: center; color: #6b7280;">
                    <div style="font-size: 2rem; margin-bottom: 10px;">💤</div>
                    <?= trans_e('dashboard.no_active_promotions') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Brze akcije -->
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 16px; color: #374151;"><?= trans_e('dashboard.quick_actions') ?></h3>
        <div class="action-card" style="flex-direction: column; align-items: flex-start; gap: 15px;">
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;"><?= trans_e('dashboard.global_sync') ?></div>
                <p style="font-size: 0.85rem; color: #6b7280; margin: 0;"><?= trans_e('dashboard.global_sync_help') ?></p>
            </div>
            <button onclick="syncAll()" class="btn btn-primary" style="width: 100%; justify-content: center; display: flex; align-items: center; gap: 8px;">
                🔄 <?= trans_e('dashboard.sync_all') ?>
            </button>
        </div>
        
        <div class="action-card" style="margin-top: 20px; flex-direction: column; align-items: flex-start; gap: 15px;">
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;"><?= trans_e('dashboard.new_promotion') ?></div>
                <p style="font-size: 0.85rem; color: #6b7280; margin: 0;"><?= trans_e('dashboard.new_promotion_help') ?></p>
            </div>
            <a href="?route=promotions&action=create" class="btn btn-secondary" style="width: 100%; text-align: center; background: white; color: #374151; border: 1px solid #d1d5db;">
                + <?= trans_e('common.create') ?>
            </a>
        </div>
    </div>
</div>

<script>
let syncInterval = null;

async function syncAll() {
    if (!confirm(appT('dashboard.confirm_sync_all'))) return;
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ ' + appT('dashboard.starting');
    
    try {
        const response = await fetch('?route=api&action=sync_all');
        const result = await response.json();
        
        if (result.success) {
            alert('✅ ' + result.result.message + '\n\n' + appT('dashboard.sync_all_added'));
            startPolling(); // Restartujemo proveru statusa
        }
    } catch (error) {
        alert('❌ ' + appT('common.error') + ': ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

function startPolling() {
    if (syncInterval) clearInterval(syncInterval);
    checkSyncStatus();
    syncInterval = setInterval(checkSyncStatus, 3000);
}

async function checkSyncStatus() {
    try {
        const response = await fetch('?route=sync&action=getActiveJobStatus');
        const data = await response.json();

        const container = document.getElementById('sync-progress-container');
        
        if (data.active) {
            // Prikaži bar i ažuriraj ga
            container.style.display = 'block';
            
            // Izračunaj procenat
            let percent = 0;
            if (data.total > 0) {
                percent = Math.round((data.processed / data.total) * 100);
            }
            
            document.getElementById('sync-bar').style.width = percent + '%';
            document.getElementById('sync-stats').textContent = `${data.processed} / ${data.total} (${percent}%)`;
            
            let statusText = appT('dashboard.sync_in_progress');
            let barColor = '#3b82f6'; // Blue

            if (data.status === 'pending') {
                statusText = '⏳ ' + appT('dashboard.status_pending');
                barColor = '#f59e0b'; // Orange
            } else if (data.status === 'processing') {
                statusText = '🔄 ' + appT('dashboard.status_processing');
                barColor = '#3b82f6'; // Blue
            } else if (data.status === 'completed') {
                statusText = '✅ ' + appT('dashboard.status_completed');
                barColor = '#10b981'; // Green
            }
            
            document.getElementById('sync-title').textContent = statusText;
            document.getElementById('sync-bar').style.background = barColor;

        } else {
            container.style.display = 'none';
            if (syncInterval) {
                clearInterval(syncInterval);
                syncInterval = null;
            }
        }
    } catch (e) {
        console.error(appT('dashboard.status_check_error'), e);
    }
}

// Pokreni proveru odmah pri učitavanju
startPolling();
</script>
