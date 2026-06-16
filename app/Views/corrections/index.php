<?php
use App\Support\Csrf;

$preview = is_array($preview ?? null) ? $preview : null;
$flash = is_array($flash ?? null) ? $flash : null;
$recentCorrections = is_array($recentCorrections ?? null) ? $recentCorrections : [];

$formatMoney = static function ($value): string {
    return $value === null || $value === '' ? '-' : number_format((float)$value, 2, '.', '');
};

$variantLabel = static function ($variantId): string {
    return $variantId === null || $variantId === '' ? 'parent' : (string)(int)$variantId;
};
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('common.application_corrections') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;">Void an incorrectly applied active promotion by SKU.</p>
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
    <h3>Find SKU</h3>
    <form method="post" action="?route=corrections&action=preview" class="correction-form">
        <?= Csrf::inputField() ?>
        <div>
            <label for="correction-sku">SKU</label>
            <input id="correction-sku" name="sku" type="text" class="form-input" required value="<?= htmlspecialchars((string)($preview['product']['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
            <label for="correction-promotion-id">Promotion ID</label>
            <input id="correction-promotion-id" name="promotion_id" type="number" min="1" class="form-input" value="<?= htmlspecialchars((string)($preview['active_promotion']['promotion_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="correction-form-actions">
            <button type="submit" class="btn btn-primary">Preview</button>
        </div>
    </form>
</section>

<?php if ($preview): ?>
    <section class="archive-detail-section">
        <h3>Preview</h3>
        <?php if (($preview['status'] ?? '') === 'ambiguous'): ?>
            <p class="log-meta"><?= htmlspecialchars((string)$preview['message'], ENT_QUOTES, 'UTF-8') ?></p>
            <table class="logs-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>IDs</th>
                        <th>Price</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($preview['matches'] ?? []) as $match): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($match['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($match['sku'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                Product: <?= (int)$match['product_id'] ?><br>
                                <span class="log-meta">Variant: <?= htmlspecialchars($variantLabel($match['variant_id'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td>
                                <?= $formatMoney($match['price'] ?? null) ?><br>
                                <span class="log-meta">Sale: <?= $formatMoney($match['sale_price'] ?? null) ?></span>
                            </td>
                            <td>
                                <form method="post" action="?route=corrections&action=preview">
                                    <?= Csrf::inputField() ?>
                                    <input type="hidden" name="sku" value="<?= htmlspecialchars((string)($match['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="product_id" value="<?= (int)$match['product_id'] ?>">
                                    <input type="hidden" name="variant_id" value="<?= htmlspecialchars((string)($match['variant_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn-secondary">Select</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (($preview['status'] ?? '') !== 'ready'): ?>
            <p><?= htmlspecialchars((string)($preview['message'] ?? 'No correction target is available.'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <?php
                $active = $preview['active_promotion'];
                $reconcile = $preview['reconcile_preview'] ?? [];
                $replacement = $reconcile['replacement_promotion'] ?? null;
            ?>
            <div class="archive-summary">
                <div class="archive-summary-item">
                    <span>SKU</span>
                    <strong><?= htmlspecialchars((string)($active['sku'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="archive-summary-item">
                    <span>Product</span>
                    <strong><?= (int)$active['product_id'] ?> / <?= htmlspecialchars($variantLabel($active['variant_id'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="archive-summary-item">
                    <span>Wrong promotion</span>
                    <strong>#<?= (int)$active['promotion_id'] ?> <?= htmlspecialchars((string)($active['promotion_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="archive-summary-item">
                    <span>Wrong price</span>
                    <strong><?= $formatMoney($active['promo_price'] ?? null) ?></strong>
                </div>
                <div class="archive-summary-item">
                    <span>After correction</span>
                    <strong>
                        <?php if ($replacement): ?>
                            #<?= (int)$replacement['promotion_id'] ?> <?= htmlspecialchars((string)($replacement['promotion_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            restore regular price
                        <?php endif; ?>
                    </strong>
                </div>
            </div>

            <h3>History rows that will be ignored</h3>
            <?php if (empty($preview['history_rows'])): ?>
                <p>No matching price history rows were found for the current wrong sale price.</p>
            <?php else: ?>
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Price</th>
                            <th>Currency</th>
                            <th>Recorded at</th>
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

                <label for="correction-reason">Reason</label>
                <textarea id="correction-reason" name="reason" class="form-textarea" required maxlength="1000" rows="4"></textarea>

                <label class="correction-confirm">
                    <input type="checkbox" name="visibility_confirmed" value="1" required>
                    <span>I confirm that ignoring this wrong promotion price for Omnibus is approved for this store.</span>
                </label>

                <button type="submit" class="btn btn-danger">Apply correction</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="archive-detail-section">
    <h3>Recent application corrections</h3>
    <?php if (empty($recentCorrections)): ?>
        <p>No application corrections have been recorded.</p>
    <?php else: ?>
        <table class="logs-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Promotion</th>
                    <th>SKU</th>
                    <th>Reason</th>
                    <th>Actor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentCorrections as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            #<?= (int)$row['promotion_id'] ?>
                            <?= htmlspecialchars((string)($row['promotion_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?= htmlspecialchars((string)($row['sku_snapshot'] ?? '-'), ENT_QUOTES, 'UTF-8') ?><br>
                            <span class="log-meta">Product: <?= (int)$row['product_id'] ?> / Variant: <?= htmlspecialchars($variantLabel($row['variant_id'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
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
