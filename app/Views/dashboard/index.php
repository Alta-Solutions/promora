<?php
$promotionAttention = $promotionAttention ?? ['summary' => [], 'items' => []];
$attentionSummary = array_merge([
    'expires_today' => 0,
    'expires_soon' => 0,
    'starts_soon' => 0,
    'never_synced' => 0,
], $promotionAttention['summary'] ?? []);
$attentionItems = $promotionAttention['items'] ?? [];
$attentionLabels = [
    'expires_today' => trans('dashboard.attention_expires_today'),
    'expires_soon' => trans('dashboard.attention_expires_soon'),
    'starts_soon' => trans('dashboard.attention_starts_soon'),
    'never_synced' => trans('dashboard.attention_never_synced'),
];
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('dashboard.title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('dashboard.subtitle') ?></p>
    </div>
    <div style="font-size: 0.85rem; color: #6b7280; background: white; padding: 6px 12px; border-radius: 20px; border: 1px solid #e5e7eb;">
        <?= date('d.m.Y') ?>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label"><?= trans_e('dashboard.stats_total_promotions') ?></div>
        <div class="stat-value"><?= (int)$stats['total_promotions'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label" style="color: #10b981;"><?= trans_e('dashboard.stats_active_promotions') ?></div>
        <div class="stat-value" style="color: #10b981;"><?= (int)$stats['active_promotions'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= trans_e('dashboard.stats_products_in_promotion') ?></div>
        <div class="stat-value"><?= (int)$stats['total_products'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= trans_e('dashboard.stats_last_sync') ?></div>
        <div class="stat-value" style="font-size: 1.2rem; margin-top: 8px;">
            <?= $stats['last_sync'] ? date('d.m.Y H:i', strtotime($stats['last_sync'])) : trans_e('common.never') ?>
        </div>
    </div>
</div>

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

<div class="dashboard-main-grid">
    <div>
        <div class="dashboard-section-header">
            <div>
                <h3><?= trans_e('dashboard.attention_title') ?></h3>
                <p><?= trans_e('dashboard.attention_subtitle') ?></p>
            </div>
            <a href="?route=promotions" class="dashboard-section-link"><?= trans_e('dashboard.view_all_promotions') ?></a>
        </div>

        <div class="promotion-attention-panel">
            <div class="promotion-attention-summary">
                <div class="promotion-attention-stat is-danger">
                    <span><?= (int)$attentionSummary['expires_today'] ?></span>
                    <strong><?= trans_e('dashboard.attention_expiring_today_label') ?></strong>
                </div>
                <div class="promotion-attention-stat is-warning">
                    <span><?= (int)$attentionSummary['expires_soon'] ?></span>
                    <strong><?= trans_e('dashboard.attention_expiring_week_label') ?></strong>
                </div>
                <div class="promotion-attention-stat is-info">
                    <span><?= (int)$attentionSummary['starts_soon'] ?></span>
                    <strong><?= trans_e('dashboard.attention_scheduled_week_label') ?></strong>
                </div>
                <div class="promotion-attention-stat is-muted">
                    <span><?= (int)$attentionSummary['never_synced'] ?></span>
                    <strong><?= trans_e('dashboard.attention_unsynced_label') ?></strong>
                </div>
            </div>

            <div class="promotion-attention-list">
                <?php if (!empty($attentionItems)): ?>
                    <?php foreach ($attentionItems as $promo): ?>
                        <?php
                            $attentionType = $promo['attention_type'] ?? 'expires_soon';
                            $attentionClass = 'attention-' . str_replace('_', '-', $attentionType);
                            $attentionAt = strtotime((string)($promo['attention_at'] ?? $promo['end_date'] ?? ''));
                            $dateLabel = $attentionType === 'starts_soon'
                                ? trans('dashboard.attention_starts_at', ['date' => $attentionAt ? date('d.m.Y H:i', $attentionAt) : '-'])
                                : trans('dashboard.attention_ends_at', ['date' => $attentionAt ? date('d.m.Y H:i', $attentionAt) : '-']);

                            if ($attentionType === 'never_synced') {
                                $dateLabel = trans('dashboard.attention_never_synced_detail');
                            }
                        ?>
                        <a href="?route=promotions&action=edit&id=<?= (int)$promo['id'] ?>" class="promotion-attention-item <?= htmlspecialchars($attentionClass, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="promotion-attention-color" style="background: <?= htmlspecialchars($promo['color'] ?: '#3b82f6', ENT_QUOTES, 'UTF-8') ?>;"></span>
                            <span class="promotion-attention-main">
                                <span class="promotion-attention-name"><?= htmlspecialchars($promo['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="promotion-attention-meta"><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                            <span class="promotion-attention-side">
                                <span class="promotion-attention-badge"><?= htmlspecialchars($attentionLabels[$attentionType] ?? $attentionType, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="promotion-attention-discount"><?= htmlspecialchars((string)$promo['discount_percent'], ENT_QUOTES, 'UTF-8') ?>%</span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="promotion-attention-empty">
                        <strong><?= trans_e('dashboard.attention_empty_title') ?></strong>
                        <span><?= trans_e('dashboard.attention_empty_text') ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 16px; color: #374151;"><?= trans_e('dashboard.quick_actions') ?></h3>
        <div class="action-card" style="flex-direction: column; align-items: flex-start; gap: 15px;">
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;"><?= trans_e('dashboard.global_sync') ?></div>
                <p style="font-size: 0.85rem; color: #6b7280; margin: 0;"><?= trans_e('dashboard.global_sync_help') ?></p>
            </div>
            <button onclick="syncAll(event)" class="btn btn-primary" style="width: 100%; justify-content: center; display: flex; align-items: center; gap: 8px;">
                <?= trans_e('dashboard.sync_all') ?>
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

async function syncAll(event) {
    if (!confirm(appT('dashboard.confirm_sync_all'))) return;

    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = appT('dashboard.starting');

    try {
        const response = await fetch('?route=api&action=sync_all');
        const result = await response.json();

        if (result.success) {
            alert(result.result.message + '\n\n' + appT('dashboard.sync_all_added'));
            startPolling();
        }
    } catch (error) {
        alert(appT('common.error') + ': ' + error.message);
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
            container.style.display = 'block';

            let percent = 0;
            if (data.total > 0) {
                percent = Math.round((data.processed / data.total) * 100);
            }

            document.getElementById('sync-bar').style.width = percent + '%';
            document.getElementById('sync-stats').textContent = `${data.processed} / ${data.total} (${percent}%)`;

            let statusText = appT('dashboard.sync_in_progress');
            let barColor = '#3b82f6';

            if (data.status === 'pending') {
                statusText = appT('dashboard.status_pending');
                barColor = '#f59e0b';
            } else if (data.status === 'processing') {
                statusText = appT('dashboard.status_processing');
                barColor = '#3b82f6';
            } else if (data.status === 'completed') {
                statusText = appT('dashboard.status_completed');
                barColor = '#10b981';
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

startPolling();
</script>
