<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('logs.webhook_title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('logs.webhook_subtitle') ?></p>
    </div>
    <button onclick="location.reload()" class="btn btn-secondary" style="background: white; color: #374151; border: 1px solid #d1d5db;">
        🔄 <?= trans_e('common.refresh') ?>
    </button>
</div>

<div class="log-tabs">
    <a href="?route=logs" class="log-tab"><?= trans_e('common.sync_logs') ?></a>
    <a href="?route=logs&action=webhooks" class="log-tab active"><?= trans_e('common.webhook_events') ?></a>
</div>

<?php if (empty($logs)): ?>
    <div class="empty-state">
        <span class="empty-icon">📡</span>
        <h3><?= trans_e('logs.webhook_empty_title') ?></h3>
        <p><?= trans_e('logs.webhook_empty_text') ?></p>
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table class="logs-table">
            <thead>
                <tr>
                    <th style="width: 160px;"><?= trans_e('common.time') ?></th>
                    <th><?= trans_e('logs.scope_event') ?></th>
                    <th><?= trans_e('common.resource_id') ?></th>
                    <th><?= trans_e('common.status') ?></th>
                    <th><?= trans_e('common.payload') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): 
                    $payload = json_decode($log['payload'], true);
                    $isProcessed = (bool)$log['processed'];
                ?>
                <tr>
                    <td style="font-size: 0.8rem; color: #6b7280;">
                        <?= date('d.m.Y H:i:s', strtotime($log['received_at'])) ?>
                    </td>
                    <td>
                        <code style="background: #eff6ff; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 0.85em;">
                            <?= htmlspecialchars($log['scope']) ?>
                        </code>
                    </td>
                    <td>
                        <span style="font-weight: 600; color: #374151;">#<?= htmlspecialchars($log['resource_id']) ?></span>
                        <div style="font-size: 0.75rem; color: #9ca3af;"><?= htmlspecialchars($log['resource_type']) ?></div>
                    </td>
                    <td>
                        <?php if ($isProcessed): ?>
                            <span class="badge badge-success"><?= trans_e('logs.processed') ?></span>
                            <div style="font-size: 0.7rem; color: #10b981; margin-top: 2px;">
                                <?= $log['processed_at'] ? date('H:i:s', strtotime($log['processed_at'])) : '' ?>
                            </div>
                        <?php else: ?>
                            <span class="badge badge-warning"><?= trans_e('logs.pending') ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="width: 40%;">
                        <div class="log-message" style="max-height: 60px;"><?= htmlspecialchars(substr($log['payload'], 0, 200)) . (strlen($log['payload']) > 200 ? '...' : '') ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
