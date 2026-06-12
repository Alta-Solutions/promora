<?php
$pagination = $pagination ?? [];
$productSearch = (string)($pagination['search'] ?? '');
$selectedPerPage = (int)($pagination['per_page'] ?? 50);
$perPageOptions = $pagination['per_page_options'] ?? [10, 25, 50, 100];
$currentPage = max(1, (int)($pagination['page'] ?? 1));
$totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
$totalProducts = max(0, (int)($pagination['total'] ?? count($products ?? [])));
$resultsFrom = max(0, (int)($pagination['from'] ?? ($totalProducts > 0 ? 1 : 0)));
$resultsTo = max(0, (int)($pagination['to'] ?? $totalProducts));
$archiveId = (int)$archive['id'];
$paginationStartPage = max(1, $currentPage - 2);
$paginationEndPage = min($totalPages, $currentPage + 2);

if (($paginationEndPage - $paginationStartPage) < 4) {
    if ($paginationStartPage === 1) {
        $paginationEndPage = min($totalPages, $paginationStartPage + 4);
    } else {
        $paginationStartPage = max(1, $paginationEndPage - 4);
    }
}

$detailUrl = static function(array $overrides = []) use ($archiveId, $productSearch, $selectedPerPage): string {
    $params = [
        'route' => 'logs',
        'action' => 'promotionArchive',
        'id' => $archiveId,
        'q' => $productSearch,
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

$filtersText = trim((string)($archive['filters_text'] ?? ''));
$backfillStatus = (string)($archive['backfill_status'] ?? 'complete');
?>

<div class="page-header">
    <div>
        <a href="?route=logs&action=promotions" class="archive-back-link"><?= trans_e('logs.back_to_archive') ?></a>
        <h2 class="page-title"><?= htmlspecialchars((string)$archive['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;">
            #<?= (int)$archive['promotion_id'] ?> &middot; <?= trans_e('logs.archived_at') ?> <?= date('d.m.Y H:i', strtotime($archive['archived_at'])) ?>
        </p>
    </div>
</div>

<div class="log-tabs">
    <a href="?route=logs" class="log-tab"><?= trans_e('common.sync_logs') ?></a>
    <a href="?route=logs&action=webhooks" class="log-tab"><?= trans_e('common.webhook_events') ?></a>
    <a href="?route=logs&action=corrections" class="log-tab"><?= trans_e('common.promotion_corrections') ?></a>
    <a href="?route=logs&action=promotions" class="log-tab active"><?= trans_e('common.promotion_archive') ?></a>
</div>

<div class="archive-summary">
    <div class="archive-summary-item">
        <span><?= trans_e('logs.valid_period') ?></span>
        <strong><?= date('d.m.Y H:i', strtotime($archive['start_date'])) ?> - <?= date('d.m.Y H:i', strtotime($archive['end_date'])) ?></strong>
    </div>
    <div class="archive-summary-item">
        <span><?= trans_e('logs.discount') ?></span>
        <strong><?= number_format((float)$archive['discount_percent'], 2) ?>%</strong>
    </div>
    <div class="archive-summary-item">
        <span><?= trans_e('common.priority') ?></span>
        <strong><?= (int)$archive['priority'] ?></strong>
    </div>
    <div class="archive-summary-item">
        <span><?= trans_e('logs.products_count') ?></span>
        <strong><?= (int)$archive['product_count'] ?></strong>
    </div>
    <div class="archive-summary-item">
        <span><?= trans_e('common.status') ?></span>
        <strong><?= $backfillStatus === 'partial' ? trans_e('logs.backfill_partial') : trans_e('logs.backfill_complete') ?></strong>
    </div>
</div>

<div class="archive-detail-section">
    <h3><?= trans_e('logs.promotion_terms') ?></h3>
    <div class="archive-terms">
        <?= $filtersText !== '' ? htmlspecialchars($filtersText, ENT_QUOTES, 'UTF-8') : trans_e('logs.no_terms_recorded') ?>
    </div>
    <?php if (!empty($archive['description'])): ?>
        <div class="archive-description"><?= htmlspecialchars((string)$archive['description'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</div>

<form method="get" class="archive-toolbar">
    <input type="hidden" name="route" value="logs">
    <input type="hidden" name="action" value="promotionArchive">
    <input type="hidden" name="id" value="<?= $archiveId ?>">

    <div class="archive-search">
        <label for="archive-product-search"><?= trans_e('logs.product_search_label') ?></label>
        <input
            type="search"
            id="archive-product-search"
            name="q"
            class="form-input"
            value="<?= htmlspecialchars($productSearch, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="<?= trans_e('logs.product_search_placeholder') ?>"
        >
    </div>

    <div class="archive-page-size">
        <label for="archive-product-per-page"><?= trans_e('logs.per_page_label') ?></label>
        <select id="archive-product-per-page" name="per_page" class="form-select" onchange="this.form.submit()">
            <?php foreach ($perPageOptions as $option): ?>
                <option value="<?= (int)$option ?>" <?= (int)$option === $selectedPerPage ? 'selected' : '' ?>>
                    <?= (int)$option ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="archive-toolbar-actions">
        <button type="submit" class="btn btn-primary"><?= trans_e('logs.search_button') ?></button>
        <?php if ($productSearch !== ''): ?>
            <a href="<?= htmlspecialchars($detailUrl(['q' => null]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <?= trans_e('logs.clear_filters') ?>
            </a>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($products)): ?>
    <div class="empty-state">
        <h3><?= trans_e('logs.archive_products_empty_title') ?></h3>
        <p><?= trans_e('logs.archive_products_empty_text') ?></p>
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table class="logs-table">
            <thead>
                <tr>
                    <th><?= trans_e('logs.product') ?></th>
                    <th><?= trans_e('logs.sku') ?></th>
                    <th><?= trans_e('logs.product_ids') ?></th>
                    <th><?= trans_e('logs.price_change') ?></th>
                    <th><?= trans_e('logs.active_interval') ?></th>
                    <th><?= trans_e('logs.removal_reason') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <?php
                        $removedAt = $product['removed_at'] ?? null;
                        $reason = trim((string)($product['removal_reason'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight: 600; color: #111827;">
                                <?= htmlspecialchars((string)($product['product_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="log-meta"><?= htmlspecialchars((string)($product['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td><?= htmlspecialchars((string)($product['sku'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <div><?= trans_e('logs.product_id') ?>: <?= (int)$product['product_id'] ?></div>
                            <div class="log-meta"><?= trans_e('logs.variant_id') ?>: <?= $product['variant_id'] !== null ? (int)$product['variant_id'] : '-' ?></div>
                        </td>
                        <td>
                            <div><?= number_format((float)$product['original_price'], 2) ?> &rarr; <?= number_format((float)$product['promo_price'], 2) ?></div>
                            <?php if ($product['discount_percent'] !== null): ?>
                                <div class="log-meta"><?= number_format((float)$product['discount_percent'], 2) ?>%</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= date('d.m.Y H:i', strtotime($product['applied_at'])) ?></div>
                            <div class="log-meta">
                                <?= $removedAt ? date('d.m.Y H:i', strtotime($removedAt)) : trans_e('logs.not_removed') ?>
                            </div>
                        </td>
                        <td>
                            <?= $reason !== '' ? htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="archive-pagination">
        <div class="archive-range">
            <?= trans_e('logs.results_range', [
                'from' => $resultsFrom,
                'to' => $resultsTo,
                'total' => $totalProducts,
            ]) ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="archive-pagination-pages" aria-label="<?= trans_e('logs.pagination_label') ?>">
                <?php if ($currentPage > 1): ?>
                    <a class="archive-pagination-link" href="<?= htmlspecialchars($detailUrl(['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>"><?= trans_e('logs.previous_page') ?></a>
                <?php else: ?>
                    <span class="archive-pagination-link is-disabled"><?= trans_e('logs.previous_page') ?></span>
                <?php endif; ?>

                <?php if ($paginationStartPage > 1): ?>
                    <a class="archive-pagination-link archive-pagination-number" href="<?= htmlspecialchars($detailUrl(['page' => 1]), ENT_QUOTES, 'UTF-8') ?>">1</a>
                    <?php if ($paginationStartPage > 2): ?>
                        <span class="archive-pagination-ellipsis">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($pageNumber = $paginationStartPage; $pageNumber <= $paginationEndPage; $pageNumber++): ?>
                    <?php if ($pageNumber === $currentPage): ?>
                        <span class="archive-pagination-link archive-pagination-number is-active"><?= $pageNumber ?></span>
                    <?php else: ?>
                        <a class="archive-pagination-link archive-pagination-number" href="<?= htmlspecialchars($detailUrl(['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($paginationEndPage < $totalPages): ?>
                    <?php if ($paginationEndPage < ($totalPages - 1)): ?>
                        <span class="archive-pagination-ellipsis">...</span>
                    <?php endif; ?>
                    <a class="archive-pagination-link archive-pagination-number" href="<?= htmlspecialchars($detailUrl(['page' => $totalPages]), ENT_QUOTES, 'UTF-8') ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a class="archive-pagination-link" href="<?= htmlspecialchars($detailUrl(['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>"><?= trans_e('logs.next_page') ?></a>
                <?php else: ?>
                    <span class="archive-pagination-link is-disabled"><?= trans_e('logs.next_page') ?></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>
