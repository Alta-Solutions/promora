<?php
$pagination = $pagination ?? [];
$archiveSearch = (string)($pagination['search'] ?? '');
$dateFrom = (string)($pagination['date_from'] ?? '');
$dateTo = (string)($pagination['date_to'] ?? '');
$selectedPerPage = (int)($pagination['per_page'] ?? 25);
$perPageOptions = $pagination['per_page_options'] ?? [10, 25, 50, 100];
$currentPage = max(1, (int)($pagination['page'] ?? 1));
$totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
$totalArchives = max(0, (int)($pagination['total'] ?? count($archives ?? [])));
$resultsFrom = max(0, (int)($pagination['from'] ?? ($totalArchives > 0 ? 1 : 0)));
$resultsTo = max(0, (int)($pagination['to'] ?? $totalArchives));
$paginationStartPage = max(1, $currentPage - 2);
$paginationEndPage = min($totalPages, $currentPage + 2);

if (($paginationEndPage - $paginationStartPage) < 4) {
    if ($paginationStartPage === 1) {
        $paginationEndPage = min($totalPages, $paginationStartPage + 4);
    } else {
        $paginationStartPage = max(1, $paginationEndPage - 4);
    }
}

$archiveListUrl = static function(array $overrides = []) use ($archiveSearch, $dateFrom, $dateTo, $selectedPerPage): string {
    $params = [
        'route' => 'logs',
        'action' => 'promotions',
        'q' => $archiveSearch,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
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
        <h2 class="page-title"><?= trans_e('logs.promotion_archive_title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('logs.promotion_archive_subtitle') ?></p>
    </div>
    <button onclick="location.reload()" class="btn btn-secondary" style="background: white; color: #374151; border: 1px solid #d1d5db;">
        <?= trans_e('common.refresh') ?>
    </button>
</div>

<div class="log-tabs">
    <a href="?route=logs" class="log-tab"><?= trans_e('common.sync_logs') ?></a>
    <a href="?route=logs&action=webhooks" class="log-tab"><?= trans_e('common.webhook_events') ?></a>
    <a href="?route=logs&action=corrections" class="log-tab"><?= trans_e('common.promotion_corrections') ?></a>
    <a href="?route=logs&action=promotions" class="log-tab active"><?= trans_e('common.promotion_archive') ?></a>
</div>

<form method="get" class="archive-toolbar">
    <input type="hidden" name="route" value="logs">
    <input type="hidden" name="action" value="promotions">

    <div class="archive-search">
        <label for="archive-search"><?= trans_e('logs.archive_search_label') ?></label>
        <input
            type="search"
            id="archive-search"
            name="q"
            class="form-input"
            value="<?= htmlspecialchars($archiveSearch, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="<?= trans_e('logs.archive_search_placeholder') ?>"
        >
    </div>

    <div class="archive-date">
        <label for="archive-date-from"><?= trans_e('logs.archive_date_from') ?></label>
        <input type="date" id="archive-date-from" name="date_from" class="form-input" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="archive-date">
        <label for="archive-date-to"><?= trans_e('logs.archive_date_to') ?></label>
        <input type="date" id="archive-date-to" name="date_to" class="form-input" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="archive-page-size">
        <label for="archive-per-page"><?= trans_e('logs.per_page_label') ?></label>
        <select id="archive-per-page" name="per_page" class="form-select" onchange="this.form.submit()">
            <?php foreach ($perPageOptions as $option): ?>
                <option value="<?= (int)$option ?>" <?= (int)$option === $selectedPerPage ? 'selected' : '' ?>>
                    <?= (int)$option ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="archive-toolbar-actions">
        <button type="submit" class="btn btn-primary"><?= trans_e('logs.search_button') ?></button>
        <?php if ($archiveSearch !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
            <a href="<?= htmlspecialchars($archiveListUrl(['q' => null, 'date_from' => null, 'date_to' => null]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <?= trans_e('logs.clear_filters') ?>
            </a>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($archives)): ?>
    <div class="empty-state">
        <h3><?= trans_e('logs.archive_empty_title') ?></h3>
        <p><?= trans_e('logs.archive_empty_text') ?></p>
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table class="logs-table">
            <thead>
                <tr>
                    <th style="width: 150px;"><?= trans_e('logs.archived_at') ?></th>
                    <th><?= trans_e('logs.promotion') ?></th>
                    <th><?= trans_e('logs.valid_period') ?></th>
                    <th><?= trans_e('logs.discount') ?></th>
                    <th><?= trans_e('logs.products_count') ?></th>
                    <th><?= trans_e('common.status') ?></th>
                    <th style="text-align: right;"><?= trans_e('common.details') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($archives as $archive): ?>
                    <?php
                        $archiveId = (int)$archive['id'];
                        $backfillStatus = (string)($archive['backfill_status'] ?? 'complete');
                    ?>
                    <tr>
                        <td style="font-size: 0.8rem; color: #6b7280;">
                            <?= date('d.m.Y H:i', strtotime($archive['archived_at'])) ?>
                        </td>
                        <td>
                            <a href="?route=logs&action=promotionArchive&id=<?= $archiveId ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                                <?= htmlspecialchars((string)$archive['name'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <div class="log-meta">#<?= (int)$archive['promotion_id'] ?></div>
                            <?php if (!empty($archive['custom_field_value'])): ?>
                                <div class="log-meta"><?= htmlspecialchars((string)$archive['custom_field_value'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= date('d.m.Y H:i', strtotime($archive['start_date'])) ?></div>
                            <div class="log-meta"><?= trans_e('logs.to_date') ?> <?= date('d.m.Y H:i', strtotime($archive['end_date'])) ?></div>
                        </td>
                        <td>
                            <span class="discount-change"><?= number_format((float)$archive['discount_percent'], 2) ?>%</span>
                        </td>
                        <td><?= (int)$archive['product_count'] ?></td>
                        <td>
                            <span class="log-type <?= $backfillStatus === 'partial' ? 'type-warning' : 'type-worker' ?>">
                                <?= $backfillStatus === 'partial' ? trans_e('logs.backfill_partial') : trans_e('logs.backfill_complete') ?>
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <a href="?route=logs&action=promotionArchive&id=<?= $archiveId ?>" class="btn btn-secondary">
                                <?= trans_e('logs.view_archive') ?>
                            </a>
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
                'total' => $totalArchives,
            ]) ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="archive-pagination-pages" aria-label="<?= trans_e('logs.pagination_label') ?>">
                <?php if ($currentPage > 1): ?>
                    <a class="archive-pagination-link" href="<?= htmlspecialchars($archiveListUrl(['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>"><?= trans_e('logs.previous_page') ?></a>
                <?php else: ?>
                    <span class="archive-pagination-link is-disabled"><?= trans_e('logs.previous_page') ?></span>
                <?php endif; ?>

                <?php if ($paginationStartPage > 1): ?>
                    <a class="archive-pagination-link archive-pagination-number" href="<?= htmlspecialchars($archiveListUrl(['page' => 1]), ENT_QUOTES, 'UTF-8') ?>">1</a>
                    <?php if ($paginationStartPage > 2): ?>
                        <span class="archive-pagination-ellipsis">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($pageNumber = $paginationStartPage; $pageNumber <= $paginationEndPage; $pageNumber++): ?>
                    <?php if ($pageNumber === $currentPage): ?>
                        <span class="archive-pagination-link archive-pagination-number is-active"><?= $pageNumber ?></span>
                    <?php else: ?>
                        <a class="archive-pagination-link archive-pagination-number" href="<?= htmlspecialchars($archiveListUrl(['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($paginationEndPage < $totalPages): ?>
                    <?php if ($paginationEndPage < ($totalPages - 1)): ?>
                        <span class="archive-pagination-ellipsis">...</span>
                    <?php endif; ?>
                    <a class="archive-pagination-link archive-pagination-number" href="<?= htmlspecialchars($archiveListUrl(['page' => $totalPages]), ENT_QUOTES, 'UTF-8') ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a class="archive-pagination-link" href="<?= htmlspecialchars($archiveListUrl(['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>"><?= trans_e('logs.next_page') ?></a>
                <?php else: ?>
                    <span class="archive-pagination-link is-disabled"><?= trans_e('logs.next_page') ?></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>
