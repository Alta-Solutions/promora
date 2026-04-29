<?php
$cacheService = new \App\Services\ProductCacheService();
$cacheStats = $cacheService->getCacheStats();
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><?= trans_e('settings.title') ?></h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?= trans_e('settings.subtitle') ?></p>
    </div>
</div>

<?php if (!empty($flashMessage)): ?>
    <?php $isErrorFlash = ($flashType ?? 'success') === 'error'; ?>
    <div class="alert <?= $isErrorFlash ? 'alert-danger' : 'alert-success' ?>" style="margin-bottom: 20px; background-color: <?= $isErrorFlash ? '#fee2e2' : '#dcfce7' ?>; border-color: <?= $isErrorFlash ? '#f87171' : '#4ade80' ?>; color: <?= $isErrorFlash ? '#991b1b' : '#166534' ?>;">
        <?= htmlspecialchars($flashMessage) ?>
    </div>
<?php endif; ?>

<div class="settings-container">
    <form action="index.php?route=settings&action=save" method="POST">
        <?= \App\Support\Csrf::inputField() ?>
        <div class="settings-card" style="margin-bottom: 30px;">
            <div class="settings-header">
                <h3 style="margin: 0; font-size: 1.1rem; color: #111827;"><?= trans_e('settings.module_configuration') ?></h3>
                <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.85rem;"><?= trans_e('settings.module_configuration_help') ?></p>
            </div>

            <div class="settings-body">
                <div class="settings-group" style="padding-bottom: 24px; margin-bottom: 24px; border-bottom: 1px solid #e5e7eb;">
                    <label class="settings-label" for="settings-language"><?= trans_e('settings.language_label') ?></label>
                    <select id="settings-language" name="language" class="form-input" style="max-width: 280px;">
                        <?php foreach (($availableLanguages ?? []) as $languageCode => $language): ?>
                            <option value="<?= htmlspecialchars($languageCode, ENT_QUOTES, 'UTF-8') ?>" <?= ($currentLanguage ?? '') === $languageCode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($language['native_name'] ?? $language['name'] ?? strtoupper($languageCode), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="settings-helper" style="margin-top: 8px;">
                        <?= trans_e('settings.language_helper') ?>
                    </div>
                </div>

                <div class="settings-group" style="padding-bottom: 24px; margin-bottom: 24px; border-bottom: 1px solid #e5e7eb;">
                    <label class="settings-label"><?= trans_e('settings.omnibus_tracker') ?></label>
                    <p class="settings-helper" style="margin-bottom: 16px;">
                        <?= trans_e('settings.omnibus_help') ?> <code>lowest_price_30d</code>.
                    </p>
                    <label class="switch">
                        <input type="checkbox" name="enable_omnibus" <?= $enableOmnibus ? 'checked' : '' ?>>
                        <span class="slider round"></span>
                    </label>
                    <span style="margin-left: 12px; vertical-align: middle; color: #374151; position: relative; top: -7px;">
                        <?= trans_e('settings.enable_omnibus') ?>
                    </span>

                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px dashed #d1d5db;">
                        <div style="display: flex; justify-content: space-between; gap: 16px; align-items: center; flex-wrap: wrap;">
                            <div>
                                <div class="settings-label" style="margin-bottom: 6px;"><?= trans_e('settings.manual_omnibus_sync') ?></div>
                                <div class="settings-helper" style="margin: 0;">
                                    <?= trans_e('settings.manual_omnibus_sync_help') ?>
                                </div>
                            </div>

                            <button type="submit"
                                    formaction="index.php?route=settings&action=triggerOmnibusSync"
                                    formmethod="POST"
                                    class="btn btn-secondary"
                                    style="background: white; color: #374151; border: 1px solid #d1d5db;"
                                    <?= (!$enableOmnibus || !empty($activeOmnibusJob)) ? 'disabled' : '' ?>>
                                <?= trans_e('settings.start_omnibus_sync') ?>
                            </button>
                        </div>

                        <?php if (!$enableOmnibus): ?>
                            <div class="settings-helper" style="margin-top: 12px; color: #991b1b;">
                                <?= trans_e('settings.manual_sync_disabled') ?>
                            </div>
                        <?php elseif (!empty($activeOmnibusJob)): ?>
                            <?php
                            $jobPercentage = ((int)$activeOmnibusJob['total_items'] > 0)
                                ? round(((int)$activeOmnibusJob['processed_items'] / (int)$activeOmnibusJob['total_items']) * 100)
                                : 0;
                            ?>
                            <div style="margin-top: 12px; padding: 12px 14px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8;">
                                <?= trans_e('settings.job_status', ['id' => (int)$activeOmnibusJob['id']]) ?>
                                <strong><?= htmlspecialchars($activeOmnibusJob['status']) ?></strong>.
                                <?= trans_e('settings.job_progress', [
                                    'processed' => (int)$activeOmnibusJob['processed_items'],
                                    'total' => (int)$activeOmnibusJob['total_items'],
                                    'percentage' => $jobPercentage,
                                ]) ?>
                            </div>
                        <?php else: ?>
                            <div class="settings-helper" style="margin-top: 12px;">
                                <?= trans_e('settings.no_active_job') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="settings-group">
                    <label class="settings-label"><?= trans_e('settings.promotion_filters') ?></label>
                    <?php $selectedAllowedFilters = $settings['allowed_filters'] ?? []; ?>
                    <select
                        id="settings-custom-fields"
                        name="custom_fields[]"
                        multiple
                        class="settings-custom-fields-select"
                        placeholder="<?= trans_e('settings.custom_fields_placeholder') ?>">
                        <?php foreach (($availableCustomFieldFilters ?? []) as $filter): ?>
                            <?php
                            $filterName = (string)($filter['name'] ?? '');
                            if ($filterName === '') {
                                continue;
                            }
                            ?>
                            <option
                                value="<?= htmlspecialchars($filterName) ?>"
                                data-count="<?= (int)($filter['product_count'] ?? 0) ?>"
                                data-data="<?= htmlspecialchars(json_encode(['count' => (int)($filter['product_count'] ?? 0)]), ENT_QUOTES, 'UTF-8') ?>"
                                <?= in_array($filterName, $selectedAllowedFilters, true) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($filterName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="settings-helper">
                        <?= trans_e('settings.custom_fields_helper') ?>
                    </div>
                </div>

                <div class="settings-group" style="margin-top: 24px;">
                    <label class="settings-label"><?= trans_e('settings.promotion_custom_field_name') ?></label>
                    <input type="text"
                           name="promotion_custom_field_name"
                           class="form-input"
                           value="<?= htmlspecialchars($promotionCustomFieldName ?? \Config::$CUSTOM_FIELD_NAME) ?>"
                           placeholder="<?= htmlspecialchars(\Config::$CUSTOM_FIELD_NAME) ?>">

                    <div class="settings-helper">
                        <?= trans_e('settings.promotion_custom_field_helper_1') ?><br>
                        <?= trans_e('settings.promotion_custom_field_helper_2') ?> <code><?= htmlspecialchars(\Config::$CUSTOM_FIELD_NAME) ?></code>.<br>
                        <?= trans_e('settings.promotion_custom_field_helper_3') ?>
                    </div>
                </div>
            </div>

            <div style="background: #f9fafb; padding: 16px 24px; border-top: 1px solid #e5e7eb; text-align: right;">
                <button type="submit" class="btn btn-primary">
                    <?= trans_e('common.save_changes') ?>
                </button>
            </div>
        </div>
    </form>

    <div class="settings-card" style="margin-bottom: 30px;">
        <div class="settings-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #111827;"><?= trans_e('settings.product_cache') ?></h3>
            <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.85rem;"><?= trans_e('settings.product_cache_help') ?></p>
        </div>

        <div class="settings-body">
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <div style="font-size: 0.75rem; color: #6b7280; text-transform: uppercase; font-weight: 600;"><?= trans_e('settings.total') ?></div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #111827;"><?= number_format($cacheStats['total_products']) ?></div>
                </div>
                <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <div style="font-size: 0.75rem; color: #6b7280; text-transform: uppercase; font-weight: 600;"><?= trans_e('settings.visible') ?></div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;"><?= number_format($cacheStats['visible_products']) ?></div>
                </div>
                <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <div style="font-size: 0.75rem; color: #6b7280; text-transform: uppercase; font-weight: 600;"><?= trans_e('settings.updated') ?></div>
                    <div style="font-size: 1rem; font-weight: 600; color: #374151; margin-top: 5px;">
                        <?= $cacheStats['last_cached'] ? date('d.m. H:i', strtotime($cacheStats['last_cached'])) : '-' ?>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="?route=cache&action=fullSync" class="btn btn-primary" onclick="return confirm(appT('settings.confirm_full_sync'))">
                    <?= trans_e('settings.full_sync') ?>
                </a>
                <button onclick="quickSync()" class="btn btn-secondary" style="background: white; color: #374151; border: 1px solid #d1d5db;">
                    <?= trans_e('settings.quick_sync') ?>
                </button>
                <button onclick="clearCache()" class="btn btn-danger" style="margin-left: auto;">
                    <?= trans_e('settings.clear') ?>
                </button>
            </div>
        </div>
    </div>

    <div class="settings-card" style="margin-bottom: 30px;">
        <div class="settings-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; color: #111827;"><?= trans_e('settings.webhooks') ?></h3>
                <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.85rem;"><?= trans_e('settings.webhooks_help') ?></p>
            </div>
            <div>
                <a href="?route=cache&action=debugWebhooks" class="btn btn-sm btn-secondary" style="margin-right: 5px;">
                    <?= trans_e('settings.check_on_bc') ?>
                </a>
                <a href="?route=cache&action=registerWebhooks" class="btn btn-sm btn-success" onclick="return confirm(appT('settings.confirm_register_webhooks'))">
                    <?= trans_e('settings.register') ?>
                </a>
            </div>
        </div>

        <div class="settings-body" style="padding: 0;">
            <?php
            $db = \App\Models\Database::getInstance();
            $webhooks = $db->fetchAll("SELECT * FROM webhooks WHERE is_active = 1 AND store_hash = ?", [$db->getStoreContext()]);
            ?>

            <?php if (!empty($webhooks)): ?>
                <table class="table" style="margin: 0;">
                    <thead style="background: #f9fafb;">
                        <tr>
                            <th style="padding: 12px 24px; font-size: 0.75rem; color: #6b7280;"><?= trans_e('common.scope') ?></th>
                            <th style="padding: 12px 24px; font-size: 0.75rem; color: #6b7280;"><?= trans_e('common.status') ?></th>
                            <th style="padding: 12px 24px; font-size: 0.75rem; color: #6b7280; text-align: right;"><?= trans_e('settings.action') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($webhooks as $webhook): ?>
                            <tr>
                                <td style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb;">
                                    <code style="background: #eff6ff; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 0.85em;"><?= htmlspecialchars($webhook['scope']) ?></code>
                                </td>
                                <td style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb;">
                                    <span class="badge badge-success"><?= trans_e('settings.active') ?></span>
                                </td>
                                <td style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb; text-align: right;">
                                    <form action="?route=cache&action=unregisterWebhooks" method="POST" style="display: inline;">
                                        <input type="hidden" name="id" value="<?= (int)$webhook['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding: 4px 8px; font-size: 0.75rem;" onclick="return confirm(appT('settings.confirm_delete'))"><?= trans_e('common.delete') ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #6b7280;">
                    <?= trans_e('settings.no_webhooks') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="settings-card">
        <div class="settings-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #111827;"><?= trans_e('settings.system_information') ?></h3>
        </div>
        <div class="settings-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 4px;"><?= trans_e('common.store_hash') ?></div>
                    <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px;"><?= $_SESSION['store_hash'] ?? '-' ?></code>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 4px;"><?= trans_e('common.php_version') ?></div>
                    <div style="font-weight: 500;"><?= phpversion() ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 4px;"><?= trans_e('common.app_url') ?></div>
                    <div style="font-size: 0.9rem; color: #374151; word-break: break-all;"><?= \Config::$APP_URL ?></div>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top: 30px; text-align: center; color: #9ca3af; font-size: 0.8rem;">
        <?= trans_e('settings.footer') ?>
    </div>
</div>

<script>
async function quickSync() {
    if (!confirm(appT('settings.confirm_quick_sync'))) return;

    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = appT('settings.sync_loading');

    try {
        const response = await fetch('?route=cache&action=quickSync');
        const result = await response.json();

        if (result.success) {
            alert(appT('settings.sync_done', { updated: result.updated }));
            location.reload();
        } else {
            alert(appT('common.error') + ': ' + result.error);
        }
    } catch (error) {
        alert(appT('common.error') + ': ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function clearCache() {
    if (!confirm(appT('settings.confirm_clear_cache'))) return;

    try {
        const response = await fetch('?route=cache&action=clearCache', { method: 'POST' });
        const result = await response.json();

        if (result.success) {
            alert(appT('settings.cache_cleared'));
            location.reload();
        }
    } catch (error) {
        alert(appT('common.error') + ': ' + error.message);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const customFieldsSelect = document.getElementById('settings-custom-fields');
    if (!customFieldsSelect || typeof TomSelect === 'undefined') {
        return;
    }

    const customFieldCounts = {};
    customFieldsSelect.querySelectorAll('option').forEach(option => {
        customFieldCounts[option.value] = Number(option.dataset.count || 0);
    });

    const selectedCustomFields = new Set(Array.from(customFieldsSelect.selectedOptions).map(option => option.value));

    const syncCustomFieldCheckboxes = function(tomSelect) {
        tomSelect.dropdown_content
            .querySelectorAll('.settings-custom-field-checkbox')
            .forEach(checkbox => {
                const option = checkbox.closest('[data-value]');
                checkbox.checked = option ? selectedCustomFields.has(option.dataset.value) : false;
            });
    };

    const customFieldsTomSelect = new TomSelect(customFieldsSelect, {
        plugins: {
            remove_button: {
                title: appT('settings.remove_filter')
            }
        },
        copyClassesToDropdown: false,
        create: false,
        closeAfterSelect: false,
        hideSelected: false,
        maxItems: null,
        placeholder: customFieldsSelect.getAttribute('placeholder') || appT('settings.custom_fields_placeholder'),
        onChange: function(values) {
            selectedCustomFields.clear();
            (Array.isArray(values) ? values : [values]).forEach(value => {
                if (value !== null && value !== undefined && value !== '') {
                    selectedCustomFields.add(String(value));
                }
            });
            syncCustomFieldCheckboxes(this);
        },
        onDropdownOpen: function() {
            syncCustomFieldCheckboxes(this);
        },
        onType: function() {
            window.requestAnimationFrame(() => syncCustomFieldCheckboxes(this));
        },
        render: {
            option: function(data, escape) {
                const count = Number(data.count || customFieldCounts[data.value] || 0);
                const countHtml = count > 0
                    ? `<span class="settings-custom-field-count">${escape(appT('settings.products_count', { count }))}</span>`
                    : '';
                const checked = selectedCustomFields.has(String(data.value)) ? 'checked' : '';

                return `
                    <div class="settings-custom-field-option">
                        <input type="checkbox" class="settings-custom-field-checkbox" tabindex="-1" aria-hidden="true" ${checked}>
                        <span class="settings-custom-field-label">${escape(data.text)}</span>
                        ${countHtml}
                    </div>
                `;
            },
            item: function(data, escape) {
                return `<div>${escape(data.text)}</div>`;
            }
        }
    });

    syncCustomFieldCheckboxes(customFieldsTomSelect);
});
</script>
