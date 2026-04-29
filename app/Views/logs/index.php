<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('logs.system_title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('logs.system_subtitle') ?></p>
    </div>
    <button onclick="location.reload()" class="btn btn-secondary" style="background: white; color: #374151; border: 1px solid #d1d5db;">
        🔄 <?= trans_e('common.refresh') ?>
    </button>
</div>

<div class="log-tabs">
    <a href="?route=logs" class="log-tab active"><?= trans_e('common.sync_logs') ?></a>
    <a href="?route=logs&action=webhooks" class="log-tab"><?= trans_e('common.webhook_events') ?></a>
</div>

<?php if (empty($logs)): ?>
    <div class="empty-state">
        <span class="empty-icon">📋</span>
        <h3><?= trans_e('logs.empty_title') ?></h3>
        <p><?= trans_e('logs.empty_text') ?></p>
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table class="logs-table">
            <thead>
                <tr>
                    <th style="width: 160px;"><?= trans_e('common.time') ?></th>
                    <th style="width: 120px;"><?= trans_e('common.type') ?></th>
                    <th><?= trans_e('logs.promotion_id') ?></th>
                    <th><?= trans_e('common.result') ?></th>
                    <th><?= trans_e('common.duration') ?></th>
                    <th><?= trans_e('common.details') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): 
                    $typeClass = 'type-single';
                    if ($log['sync_type'] === 'full') $typeClass = 'type-full';
                    if ($log['sync_type'] === 'worker') $typeClass = 'type-worker';
                    if (strpos($log['sync_type'], 'error') !== false) $typeClass = 'type-error';
                ?>
                <tr>
                    <td style="font-size: 0.8rem; color: #6b7280;">
                        <?= date('d.m.Y H:i:s', strtotime($log['synced_at'])) ?>
                    </td>
                    <td>
                        <span class="log-type <?= $typeClass ?>"><?= htmlspecialchars($log['sync_type']) ?></span>
                    </td>
                    <td>
                        <?php if ($log['promotion_id']): ?>
                            <a href="?route=promotions&action=edit&id=<?= $log['promotion_id'] ?>" style="color: #3b82f6; text-decoration: none; font-weight: 500;">
                                #<?= $log['promotion_id'] ?>
                            </a>
                        <?php else: ?>
                            <span style="color: #9ca3af;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="status-success">✓ <?= $log['products_synced'] ?></div>
                        <?php if ($log['errors'] > 0): ?>
                            <div class="status-error">⚠ <?= trans_e('logs.errors_count', ['count' => $log['errors']]) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family: monospace;"><?= $log['duration_seconds'] ?>s</td>
                    <td style="width: 40%;">
                        <div class="log-message"><?= htmlspecialchars($log['log_message']) ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
