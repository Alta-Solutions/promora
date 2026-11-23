<h2><?= trans_e('price_conflicts.title') ?></h2>
<p><?= trans_e('price_conflicts.intro') ?></p>
<?php if (!$rows): ?>
<p><?= trans_e('price_conflicts.empty') ?></p>
<?php else: ?>
<table class="logs-table">
    <thead><tr>
        <?php foreach (['item','promotion','expected','observed','decision'] as $column): ?>
            <th><?= trans_e('price_conflicts.' . $column) ?></th>
        <?php endforeach ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= (int)$row['product_id'] ?><?= $row['variant_key'] ? ' / ' . (int)$row['variant_key'] : '' ?></td>
            <td><?= (int)$row['promotion_id'] ?></td>
            <td><?= htmlspecialchars($row['expected_price'] ?? '—') ?></td>
            <td><?= htmlspecialchars($row['observed_price'] ?? '—') ?></td>
            <td><form method="post">
                <?= \App\Support\Csrf::inputField() ?>
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="revision" value="<?= (int)$row['revision'] ?>">
                <button class="btn btn-secondary" name="decision" value="keep"><?= trans_e('price_conflicts.keep') ?></button>
                <?php if ($row['allow_apply']): ?>
                <button class="btn btn-primary" name="decision" value="apply"><?= trans_e('price_conflicts.apply') ?></button>
                <?php endif ?>
            </form></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
<?php endif ?>
