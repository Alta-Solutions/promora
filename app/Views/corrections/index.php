<?php
use App\Support\Csrf;

$preview = is_array($preview ?? null) ? $preview : null;
$flash = is_array($flash ?? null) ? $flash : null;
$recentCorrections = is_array($recentCorrections ?? null) ? $recentCorrections : [];

$formatMoney = static function ($value): string {
    return $value === null || $value === '' ? '-' : number_format((float)$value, 2, '.', '');
};

$variantLabel = static function ($variantId): string {
    return $variantId === null || $variantId === '' ? trans('application_corrections.parent') : (string)(int)$variantId;
};

$previewMessage = static function (array $preview): string {
    $status = (string)($preview['status'] ?? '');
    $key = 'application_corrections.preview_status_' . $status;
    $message = trans($key);

    if ($message !== $key) {
        return $message;
    }

    return (string)($preview['message'] ?? trans('application_corrections.no_target_available'));
};

$statusLabel = static function ($status): string {
    $status = (string)$status;
    $key = 'application_corrections.status_' . $status;
    $label = trans($key);

    return $label !== $key ? $label : $status;
};
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('common.application_corrections') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('application_corrections.subtitle') ?></p>
    </div>
    <a href="?route=promotions" class="btn btn-secondary">
        <?= trans_e('common.promotions') ?>
    </a>
</div>

<?php if ($flash): ?>
    <div class="correction-alert correction-alert-<?= htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<section class="archive-detail-section">
    <h3><?= trans_e('application_corrections.find_sku') ?></h3>
    <form method="post" action="?route=corrections&action=preview" class="correction-form">
        <?= Csrf::inputField() ?>
        <div>
            <label for="correction-sku"><?= trans_e('application_corrections.sku') ?></label>
            <input id="correction-sku" name="sku" type="text" class="form-input" required value="<?= htmlspecialchars((string)($preview['product']['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
            <label for="correction-promotion-id"><?= trans_e('application_corrections.promotion_id') ?></label>
            <input id="correction-promotion-id" name="promotion_id" type="number" min="1" class="form-input" value="<?= htmlspecialchars((string)($preview['active_promotion']['promotion_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="correction-form-actions">
            <button type="submit" class="btn btn-primary"><?= trans_e('application_corrections.preview') ?></button>
        </div>
    </form>
</section>

<?php if ($preview): ?>
    <section class="archive-detail-section">
        <h3><?= trans_e('application_corrections.preview') ?></h3>
        <?php if (($preview['status'] ?? '') === 'ambiguous'): ?>
            <p class="log-meta"><?= htmlspecialchars($previewMessage($preview), ENT_QUOTES, 'UTF-8') ?></p>
            <table class="logs-table">
                <thead>
                    <tr>
                        <th><?= trans_e('application_corrections.product') ?></th>
                        <th><?= trans_e('application_corrections.sku') ?></th>
                        <th><?= trans_e('application_corrections.ids') ?></th>
                        <th><?= trans_e('application_corrections.price') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($preview['matches'] ?? []) as $match): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($match['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($match['sku'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= trans_e('application_corrections.product') ?>: <?= (int)$match['product_id'] ?><br>
                                <span class="log-meta"><?= trans_e('application_corrections.variant') ?>: <?= htmlspecialchars($variantLabel($match['variant_id'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td>
                                <?= $formatMoney($match['price'] ?? null) ?><br>
                                <span class="log-meta"><?= trans_e('application_corrections.sale') ?>: <?= $formatMoney($match['sale_price'] ?? null) ?></span>
                            </td>
                            <td>
                                <form method="post" action="?route=corrections&action=preview">
                                    <?= Csrf::inputField() ?>
                                    <input type="hidden" name="sku" value="<?= htmlspecialchars((string)($match['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="product_id" value="<?= (int)$match['product_id'] ?>">
                                    <input type="hidden" name="variant_id" value="<?= htmlspecialchars((string)($match['variant_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn-secondary"><?= trans_e('application_corrections.select') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (($preview['status'] ?? '') !== 'ready'): ?>
            <p><?= htmlspecialchars($previewMessage($preview), ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <?php
                $active = $preview['active_promotion'];
                $reconcile = $preview['reconcile_preview'] ?? [];
                $replacement = $reconcile['replacement_promotion'] ?? null;
            ?>
            <div class="archive-summary">
                <div class="archive-summary-item">
                    <span><?= trans_e('application_corrections.sku') ?></span>
                    <strong><?= htmlspecialchars((string)($active['sku'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="archive-summary-item">
                    <span><?= trans_e('application_corrections.product') ?></span>
                    <strong><?= (int)$active['product_id'] ?> / <?= htmlspecialchars($variantLabel($active['variant_id'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="archive-summary-item">
                    <span><?= trans_e('application_corrections.wrong_promotion') ?></span>
                    <strong>#<?= (int)$active['promotion_id'] ?> <?= htmlspecialchars((string)($active['promotion_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="archive-summary-item">
                    <span><?= trans_e('application_corrections.wrong_price') ?></span>
                    <strong><?= $formatMoney($active['promo_price'] ?? null) ?></strong>
                </div>
                <div class="archive-summary-item">
                    <span><?= trans_e('application_corrections.after_correction') ?></span>
                    <strong>
                        <?php if ($replacement): ?>
                            #<?= (int)$replacement['promotion_id'] ?> <?= htmlspecialchars((string)($replacement['promotion_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <?= trans_e('application_corrections.restore_regular_price') ?>
                        <?php endif; ?>
                    </strong>
                </div>
            </div>

            <h3><?= trans_e('application_corrections.history_rows_title') ?></h3>
            <?php if (empty($preview['history_rows'])): ?>
                <p><?= trans_e('application_corrections.no_history_rows') ?></p>
            <?php else: ?>
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th><?= trans_e('application_corrections.id') ?></th>
                            <th><?= trans_e('application_corrections.price') ?></th>
                            <th><?= trans_e('application_corrections.currency') ?></th>
                            <th><?= trans_e('application_corrections.recorded_at') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['history_rows'] as $row): ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td><?= $formatMoney($row['price'] ?? null) ?></td>
                                <td><?= htmlspecialchars((string)($row['currency'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($row['recorded_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <form method="post" action="?route=corrections&action=apply" class="correction-apply-form">
                <?= Csrf::inputField() ?>
                <input type="hidden" name="preview_token" value="<?= htmlspecialchars((string)$preview['token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="product_id" value="<?= (int)$active['product_id'] ?>">
                <input type="hidden" name="variant_id" value="<?= htmlspecialchars((string)($active['variant_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="promotion_id" value="<?= (int)$active['promotion_id'] ?>">

                <label for="correction-reason"><?= trans_e('application_corrections.reason') ?></label>
                <textarea id="correction-reason" name="reason" class="form-textarea" required maxlength="1000" rows="4"></textarea>

                <label class="correction-confirm">
                    <input type="checkbox" name="visibility_confirmed" value="1" required>
                    <span><?= trans_e('application_corrections.visibility_confirm') ?></span>
                </label>

                <button type="submit" class="btn btn-danger"><?= trans_e('application_corrections.apply_correction') ?></button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="archive-detail-section">
    <h3><?= trans_e('application_corrections.recent_title') ?></h3>
    <?php if (empty($recentCorrections)): ?>
        <p><?= trans_e('application_corrections.recent_empty') ?></p>
    <?php else: ?>
        <table class="logs-table">
            <thead>
                <tr>
                    <th><?= trans_e('application_corrections.time') ?></th>
                    <th><?= trans_e('application_corrections.status') ?></th>
                    <th><?= trans_e('application_corrections.promotion') ?></th>
                    <th><?= trans_e('application_corrections.sku') ?></th>
                    <th><?= trans_e('application_corrections.reason') ?></th>
                    <th><?= trans_e('application_corrections.actor') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentCorrections as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($statusLabel($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            #<?= (int)$row['promotion_id'] ?>
                            <?= htmlspecialchars((string)($row['promotion_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?= htmlspecialchars((string)($row['sku_snapshot'] ?? '-'), ENT_QUOTES, 'UTF-8') ?><br>
                            <span class="log-meta"><?= trans_e('application_corrections.product') ?>: <?= (int)$row['product_id'] ?> / <?= trans_e('application_corrections.variant') ?>: <?= htmlspecialchars($variantLabel($row['variant_id'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td><?= nl2br(htmlspecialchars((string)($row['reason'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td>
                        <td>
                            <?= htmlspecialchars((string)($row['actor_email'] ?? $row['actor_user_id'] ?? $row['actor_source'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            <div class="log-meta"><?= htmlspecialchars((string)($row['actor_source'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
