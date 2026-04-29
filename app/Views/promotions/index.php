<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('promotions.index_title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('promotions.index_subtitle') ?></p>
    </div>
    <a href="?route=promotions&action=create" class="btn btn-primary">
        + <?= trans_e('promotions.new_promotion') ?>
    </a>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($promotions)): ?>
        <div class="empty-state">
            <span class="empty-icon">🏷️</span>
            <h3><?= trans_e('promotions.empty_title') ?></h3>
            <p><?= trans_e('promotions.empty_text') ?></p>
            <a href="?route=promotions&action=create" class="btn btn-primary" style="margin-top: 20px;"><?= trans_e('promotions.create_promotion') ?></a>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="promo-table">
                <thead>
                    <tr>
                        <th><?= trans_e('promotions.table_name') ?></th>
                        <th><?= trans_e('promotions.table_frontend_value') ?></th>
                        <th><?= trans_e('promotions.table_discount') ?></th>
                        <th><?= trans_e('promotions.table_duration') ?></th>
                        <th><?= trans_e('promotions.table_status') ?></th>
                        <th><?= trans_e('promotions.table_priority') ?></th>
                        <th style="text-align: right;"><?= trans_e('promotions.table_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promotions as $promo): 
                        // Logika za status
                        $now = time();
                        $start = strtotime($promo['start_date']);
                        $end = strtotime($promo['end_date']);
                        
                        $statusClass = 'badge-active';
                        $statusLabel = trans('promotions.status_active');
                        
                        if ($promo['status'] === 'expired' || $end < $now) {
                            $statusClass = 'badge-expired';
                            $statusLabel = trans('promotions.status_expired');
                        } elseif ($start > $now) {
                            $statusClass = 'badge-scheduled';
                            $statusLabel = trans('promotions.status_scheduled');
                        }
                    ?>
                    <tr>
                        <td>
                            <div class="promo-name-cell">
                                <div class="color-indicator" style="background-color: <?= htmlspecialchars($promo['color']) ?>;"></div>
                                <div class="promo-info">
                                    <span class="promo-name"><?= htmlspecialchars($promo['name']) ?></span>
                                    <?php if (!empty($promo['description'])): ?>
                                        <span class="promo-desc"><?= htmlspecialchars($promo['description']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 0.9rem; color: #374151; max-width: 240px; word-break: break-word;">
                                <?= htmlspecialchars($promo['custom_field_value'] ?? $promo['name']) ?>
                            </div>
                        </td>
                        <td>
                            <span class="discount-value"><?= $promo['discount_percent'] ?>%</span>
                        </td>
                        <td>
                            <div style="font-size: 0.85rem; color: #374151;">
                                <?= date('d.m.Y H:i', $start) ?>
                            </div>
                            <div style="font-size: 0.75rem; color: #9ca3af;">
                                <?= trans_e('promotions.date_to') ?> <?= date('d.m.Y H:i', $end) ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td>
                            <span style="font-weight: 600; color: #6b7280;"><?= $promo['priority'] ?></span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button onclick="syncSingle(<?= $promo['id'] ?>)" class="btn-icon" title="<?= trans_e('promotions.sync_now') ?>">
                                    🔄
                                </button>
                                <a href="?route=promotions&action=duplicate&id=<?= $promo['id'] ?>" class="btn-icon" title="<?= trans_e('common.duplicate') ?>">
                                    &#x2398;
                                </a>
                                <a href="?route=promotions&action=edit&id=<?= $promo['id'] ?>" class="btn-icon" title="<?= trans_e('common.edit') ?>">
                                    ✏️
                                </a>
                                <a href="?route=promotions&action=delete&id=<?= $promo['id'] ?>" class="btn-icon delete" title="<?= trans_e('common.delete') ?>" onclick="return confirm(appT('promotions.delete_confirm'));">
                                    🗑️
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
async function syncSingle(id) {
    if(!confirm(appT('promotions.confirm_manual_sync'))) return;
    
    try {
        const response = await fetch('?route=sync&action=startSingle&id=' + id);
        const result = await response.json();
        if(result.success) {
            alert('✅ ' + appT('promotions.sync_started', { job_id: result.job_id }));
        } else {
            alert('❌ ' + appT('common.error') + ': ' + (result.error || appT('common.unknown_error')));
        }
    } catch(e) {
        alert('❌ ' + appT('promotions.communication_error'));
    }
}
</script>
