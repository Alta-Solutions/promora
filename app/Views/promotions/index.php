<?php
$pagination = $pagination ?? [];
$promotionSearch = (string)($pagination['search'] ?? '');
$selectedPerPage = (int)($pagination['per_page'] ?? 25);
$perPageOptions = $pagination['per_page_options'] ?? [10, 25, 50, 100];
$currentPage = max(1, (int)($pagination['page'] ?? 1));
$totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
$totalPromotions = max(0, (int)($pagination['total'] ?? count($promotions ?? [])));
$resultsFrom = max(0, (int)($pagination['from'] ?? ($totalPromotions > 0 ? 1 : 0)));
$resultsTo = max(0, (int)($pagination['to'] ?? $totalPromotions));
$paginationStartPage = max(1, $currentPage - 2);
$paginationEndPage = min($totalPages, $currentPage + 2);

if (($paginationEndPage - $paginationStartPage) < 4) {
    if ($paginationStartPage === 1) {
        $paginationEndPage = min($totalPages, $paginationStartPage + 4);
    } else {
        $paginationStartPage = max(1, $paginationEndPage - 4);
    }
}

$promotionListUrl = static function(array $overrides = []) use ($promotionSearch, $selectedPerPage): string {
    $params = [
        'route' => 'promotions',
        'q' => $promotionSearch,
        'per_page' => $selectedPerPage,
        'page' => 1,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || ($key === 'page' && (int)$value <= 1)) {
            unset($params[$key]);
        }
    }

    return '?' . http_build_query($params);
};
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('promotions.index_title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('promotions.index_subtitle') ?></p>
    </div>
    <a href="?route=promotions&action=create" class="btn btn-primary">
        + <?= trans_e('promotions.new_promotion') ?>
    </a>
</div>

<form method="get" class="promotion-list-toolbar">
    <input type="hidden" name="route" value="promotions">

    <div class="promotion-list-search">
        <label for="promotion-search"><?= trans_e('promotions.search_label') ?></label>
        <input
            type="search"
            id="promotion-search"
            name="q"
            class="form-input"
            value="<?= htmlspecialchars($promotionSearch, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="<?= trans_e('promotions.search_placeholder') ?>"
        >
    </div>

    <div class="promotion-list-page-size">
        <label for="promotion-per-page"><?= trans_e('promotions.per_page_label') ?></label>
        <select id="promotion-per-page" name="per_page" class="form-select" onchange="this.form.submit()">
            <?php foreach ($perPageOptions as $option): ?>
                <option value="<?= (int)$option ?>" <?= (int)$option === $selectedPerPage ? 'selected' : '' ?>>
                    <?= (int)$option ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="promotion-list-toolbar-actions">
        <button type="submit" class="btn btn-primary"><?= trans_e('promotions.search_button') ?></button>
        <?php if ($promotionSearch !== ''): ?>
            <a href="<?= htmlspecialchars($promotionListUrl(['q' => null]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <?= trans_e('promotions.clear_search') ?>
            </a>
        <?php endif; ?>
    </div>
</form>

<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($promotions)): ?>
        <div class="empty-state">
            <span class="empty-icon">🏷️</span>
            <?php if ($promotionSearch !== ''): ?>
                <h3><?= trans_e('promotions.search_empty_title') ?></h3>
                <p><?= trans_e('promotions.search_empty_text') ?></p>
                <a href="<?= htmlspecialchars($promotionListUrl(['q' => null]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary" style="margin-top: 20px;"><?= trans_e('promotions.clear_search') ?></a>
            <?php else: ?>
                <h3><?= trans_e('promotions.empty_title') ?></h3>
                <p><?= trans_e('promotions.empty_text') ?></p>
                <a href="?route=promotions&action=create" class="btn btn-primary" style="margin-top: 20px;"><?= trans_e('promotions.create_promotion') ?></a>
            <?php endif; ?>
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

        <div class="promotion-list-pagination">
            <div class="promotion-list-range">
                <?= trans_e('promotions.results_range', [
                    'from' => $resultsFrom,
                    'to' => $resultsTo,
                    'total' => $totalPromotions,
                ]) ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="promotion-pagination-pages" aria-label="<?= trans_e('promotions.pagination_label') ?>">
                    <?php if ($currentPage > 1): ?>
                        <a class="promotion-pagination-link" href="<?= htmlspecialchars($promotionListUrl(['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>">
                            <?= trans_e('promotions.previous_page') ?>
                        </a>
                    <?php else: ?>
                        <span class="promotion-pagination-link is-disabled"><?= trans_e('promotions.previous_page') ?></span>
                    <?php endif; ?>

                    <?php if ($paginationStartPage > 1): ?>
                        <a class="promotion-pagination-link promotion-pagination-number" href="<?= htmlspecialchars($promotionListUrl(['page' => 1]), ENT_QUOTES, 'UTF-8') ?>">1</a>
                        <?php if ($paginationStartPage > 2): ?>
                            <span class="promotion-pagination-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($pageNumber = $paginationStartPage; $pageNumber <= $paginationEndPage; $pageNumber++): ?>
                        <?php if ($pageNumber === $currentPage): ?>
                            <span class="promotion-pagination-link promotion-pagination-number is-active"><?= $pageNumber ?></span>
                        <?php else: ?>
                            <a class="promotion-pagination-link promotion-pagination-number" href="<?= htmlspecialchars($promotionListUrl(['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($paginationEndPage < $totalPages): ?>
                        <?php if ($paginationEndPage < ($totalPages - 1)): ?>
                            <span class="promotion-pagination-ellipsis">...</span>
                        <?php endif; ?>
                        <a class="promotion-pagination-link promotion-pagination-number" href="<?= htmlspecialchars($promotionListUrl(['page' => $totalPages]), ENT_QUOTES, 'UTF-8') ?>"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a class="promotion-pagination-link" href="<?= htmlspecialchars($promotionListUrl(['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>">
                            <?= trans_e('promotions.next_page') ?>
                        </a>
                    <?php else: ?>
                        <span class="promotion-pagination-link is-disabled"><?= trans_e('promotions.next_page') ?></span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
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
