<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('logs.corrections_title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('logs.corrections_subtitle') ?></p>
    </div>
    <button onclick="location.reload()" class="btn btn-secondary" style="background: white; color: #374151; border: 1px solid #d1d5db;">
        <?= trans_e('common.refresh') ?>
    </button>
</div>

<div class="log-tabs">
    <a href="?route=logs" class="log-tab"><?= trans_e('common.sync_logs') ?></a>
    <a href="?route=logs&action=webhooks" class="log-tab"><?= trans_e('common.webhook_events') ?></a>
    <a href="?route=logs&action=corrections" class="log-tab active"><?= trans_e('common.promotion_corrections') ?></a>
</div>

<?php if (empty($logs)): ?>
    <div class="empty-state">
        <h3><?= trans_e('logs.corrections_empty_title') ?></h3>
        <p><?= trans_e('logs.corrections_empty_text') ?></p>
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table class="logs-table">
            <thead>
                <tr>
                    <th style="width: 160px;"><?= trans_e('common.time') ?></th>
                    <th><?= trans_e('logs.promotion') ?></th>
                    <th><?= trans_e('logs.discount_change') ?></th>
                    <th><?= trans_e('logs.changed_by') ?></th>
                    <th><?= trans_e('logs.correction_reason') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                        $promotionName = trim((string)($log['promotion_name'] ?? ''));
                        $actorEmail = trim((string)($log['actor_email'] ?? ''));
                        $actorUserId = trim((string)($log['actor_user_id'] ?? ''));
                        $actorLabel = $actorEmail !== '' ? $actorEmail : ($actorUserId !== '' ? $actorUserId : '-');
                    ?>
                    <tr>
                        <td style="font-size: 0.8rem; color: #6b7280;">
                            <?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?>
                        </td>
                        <td>
                            <a href="?route=promotions&action=edit&id=<?= (int)$log['promotion_id'] ?>" style="color: #3b82f6; text-decoration: none; font-weight: 500;">
                                #<?= (int)$log['promotion_id'] ?>
                            </a>
                            <?php if ($promotionName !== ''): ?>
                                <div class="log-meta"><?= htmlspecialchars($promotionName, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="discount-change">
                                <?= number_format((float)$log['old_discount_percent'], 2) ?>%
                                &rarr;
                                <?= number_format((float)$log['new_discount_percent'], 2) ?>%
                            </span>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($actorLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if ($actorEmail !== '' && $actorUserId !== ''): ?>
                                <div class="log-meta">ID: <?= htmlspecialchars($actorUserId, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if (!empty($log['actor_is_owner'])): ?>
                                <div class="log-meta"><?= trans_e('logs.store_owner') ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="width: 36%;">
                            <div class="log-message"><?= htmlspecialchars((string)$log['reason'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
