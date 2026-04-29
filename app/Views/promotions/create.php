<?php
    $promotionDefaults = $duplicatePromotion ?? [];
    $isDuplicate = !empty($promotionDefaults);
    $defaultStartDateTime = strtotime('now');
    $defaultEndDateTime = strtotime(date('Y-m-d', strtotime('+7 days')) . ' 23:59:00');
    $startDateTime = strtotime($promotionDefaults['start_date'] ?? '') ?: $defaultStartDateTime;
    $endDateTime = strtotime($promotionDefaults['end_date'] ?? '') ?: $defaultEndDateTime;
    $filters = json_decode($promotionDefaults['filters'] ?? '{}', true);

    if (!is_array($filters)) {
        $filters = [];
    }

    $excludeFilters = [];
    if (isset($filters['exclude']) && is_array($filters['exclude'])) {
        $excludeFilters = $filters['exclude'];
        unset($filters['exclude']);
    }

    $nameValue = $promotionDefaults['name'] ?? '';
    $customFieldValue = $promotionDefaults['custom_field_value'] ?? $nameValue;
    $descriptionValue = $promotionDefaults['description'] ?? '';
    $discountValue = $promotionDefaults['discount_percent'] ?? 0;
    $priorityValue = $promotionDefaults['priority'] ?? 0;
    $colorValue = $promotionDefaults['color'] ?? '#3b82f6';
?>

<div class="promotion-create-page">
    <div class="promotion-page-heading">
        <h2><?= trans_e($isDuplicate ? 'promotions.form.duplicate_title' : 'promotions.form.create_title') ?></h2>
        <a href="?route=promotions" class="promotion-back-link">← <?= trans_e('common.back') ?></a>
    </div>

    <form method="POST" action="?route=promotions&action=create" id="promotionForm" class="promotion-create-form">
        <?= \App\Support\Csrf::inputField() ?>

        <section class="promotion-card promotion-setup-card">
            <div class="promotion-setup-grid">
                <div class="promotion-setup-column">
                    <h4 class="promotion-section-title"><?= trans_e('promotions.form.basic_info') ?></h4>

                    <div class="form-group promotion-field">
                        <label class="form-label"><?= trans_e('promotions.form.internal_name') ?> <span class="required-marker">*</span></label>
                        <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($nameValue) ?>" required placeholder="<?= trans_e('promotions.form.internal_name_placeholder') ?>">
                        <small class="promotion-field-help"><?= trans_e('promotions.form.internal_name_help') ?></small>
                    </div>

                    <div class="form-group promotion-field">
                        <label class="form-label"><?= trans_e('promotions.form.custom_field_value') ?> <span class="required-marker">*</span></label>
                        <input type="text" name="custom_field_value" class="form-input" value="<?= htmlspecialchars($customFieldValue) ?>" required placeholder="<?= trans_e('promotions.form.custom_field_value_placeholder') ?>">
                        <small class="promotion-field-help"><?= trans_e('promotions.form.custom_field_value_help') ?></small>
                    </div>

                    <div class="form-group promotion-field">
                        <label class="form-label"><?= trans_e('promotions.form.description') ?></label>
                        <textarea name="description" class="form-textarea promotion-description-input" placeholder="<?= trans_e('promotions.form.description_placeholder') ?>"><?= htmlspecialchars($descriptionValue) ?></textarea>
                    </div>
                </div>

                <div class="promotion-setup-column promotion-technical-column">
                    <h4 class="promotion-section-title"><?= trans_e('promotions.form.technical_settings') ?></h4>

                    <div class="promotion-technical-grid">
                        <div class="form-group promotion-field">
                            <label class="form-label"><?= trans_e('promotions.form.discount_percent') ?> <span class="required-marker">*</span></label>
                            <div class="promotion-input-suffix">
                                <input type="number" name="discount_percent" id="promo-discount" class="form-input" min="0" max="100" step="0.01" value="<?= htmlspecialchars($discountValue) ?>" required>
                                <span>%</span>
                            </div>
                        </div>

                        <div class="form-group promotion-field">
                            <label class="form-label"><?= trans_e('common.priority') ?></label>
                            <input type="number" name="priority" class="form-input" value="<?= htmlspecialchars($priorityValue) ?>" min="0">
                        </div>

                        <div class="form-group promotion-field promotion-full-field">
                            <label class="form-label"><?= trans_e('promotions.form.color_label') ?></label>
                            <div class="promotion-color-row">
                                <input type="color" name="color" class="color-picker promotion-color-input" value="<?= htmlspecialchars($colorValue) ?>">
                                <span class="promotion-color-value" id="promotion-color-value"><?= htmlspecialchars(strtoupper($colorValue)) ?></span>
                            </div>
                        </div>

                        <div class="form-group promotion-field promotion-full-field">
                            <label class="form-label"><?= trans_e('promotions.form.start') ?> <span class="required-marker">*</span></label>
                            <input type="hidden" name="start_date" id="start-date" value="<?= date('Y-m-d\TH:i', $startDateTime) ?>">
                            <div class="promotion-datetime-picker js-promotion-datetime-picker" data-target="start-date">
                                <input type="date" class="form-input js-promotion-date" value="<?= date('Y-m-d', $startDateTime) ?>" required>
                                <select class="form-input promotion-time-select js-promotion-hour" data-selected="<?= date('H', $startDateTime) ?>" aria-label="<?= trans_e('promotions.form.start_hour_aria') ?>"></select>
                                <span class="promotion-time-separator">:</span>
                                <select class="form-input promotion-time-select js-promotion-minute" data-selected="<?= date('i', $startDateTime) ?>" aria-label="<?= trans_e('promotions.form.start_minute_aria') ?>"></select>
                            </div>
                        </div>

                        <div class="form-group promotion-field promotion-full-field">
                            <label class="form-label"><?= trans_e('promotions.form.end') ?> <span class="required-marker">*</span></label>
                            <input type="hidden" name="end_date" id="end-date" value="<?= date('Y-m-d\TH:i', $endDateTime) ?>">
                            <div class="promotion-datetime-picker js-promotion-datetime-picker" data-target="end-date">
                                <input type="date" class="form-input js-promotion-date" value="<?= date('Y-m-d', $endDateTime) ?>" required>
                                <select class="form-input promotion-time-select js-promotion-hour" data-selected="<?= date('H', $endDateTime) ?>" aria-label="<?= trans_e('promotions.form.end_hour_aria') ?>"></select>
                                <span class="promotion-time-separator">:</span>
                                <select class="form-input promotion-time-select js-promotion-minute" data-selected="<?= date('i', $endDateTime) ?>" aria-label="<?= trans_e('promotions.form.end_minute_aria') ?>"></select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="promotion-workspace-grid">
            <section class="promotion-card promotion-filter-card">
                <h3 class="promotion-card-title"><?= trans_e('promotions.filters.products') ?></h3>

                <div class="promotion-filter-box">
                    <div class="promotion-filter-box-header">
                        <span><?= trans_e('promotions.filters.include_question') ?></span>
                        <span class="promotion-info-dot" title="<?= trans_e('promotions.filters.include_help') ?>">i</span>
                    </div>
                    <div id="filter-list"></div>
                    <button type="button" onclick="addFilter()" class="promotion-add-filter-button">
                        <?= trans_e('promotions.filters.add_condition') ?>
                    </button>
                </div>

                <div class="promotion-filter-box promotion-filter-box-exclude">
                    <div class="promotion-filter-box-header">
                        <span><?= trans_e('promotions.filters.exclude_rules') ?></span>
                        <span class="promotion-info-dot" title="<?= trans_e('promotions.filters.exclude_help') ?>">i</span>
                    </div>
                    <div id="exclude-filter-list"></div>
                    <button type="button" onclick="addFilter('exclude')" class="promotion-add-filter-button promotion-add-filter-button-exclude">
                        <?= trans_e('promotions.filters.add_exclude_condition') ?>
                    </button>
                </div>

                <input type="hidden" name="filters" id="filters-json">

                <div class="promotion-form-actions">
                    <a href="?route=promotions" class="btn btn-secondary"><?= trans_e('common.cancel') ?></a>
                    <button type="submit" class="btn btn-primary"><?= trans_e($isDuplicate ? 'promotions.form.create_duplicate_submit' : 'promotions.form.create_submit') ?></button>
                </div>
            </section>

            <section class="promotion-card promotion-preview-card">
                <div class="promotion-preview-header">
                    <h3 class="promotion-card-title"><?= trans_e('promotions.preview.title') ?> (<span id="preview-count">0</span>)</h3>
                    <button type="button" onclick="updatePreview()" class="btn btn-secondary promotion-refresh-button">
                        <?= trans_e('promotions.preview.refresh_list') ?>
                    </button>
                </div>

                <div class="promotion-preview-toolbar">
                    <input type="search" id="preview-search" class="form-input promotion-preview-search" placeholder="<?= trans_e('promotions.preview.search_placeholder') ?>">
                </div>

                <div class="promotion-preview-table-wrap">
                    <table class="table promotion-preview-table">
                        <thead>
                            <tr>
                                <th><?= trans_e('promotions.preview.name_sku') ?></th>
                                <th><?= trans_e('promotions.preview.price') ?></th>
                                <th><?= trans_e('promotions.preview.new_price') ?></th>
                                <th><?= trans_e('promotions.preview.stock') ?></th>
                            </tr>
                        </thead>
                        <tbody id="preview-table-body">
                            <tr><td colspan="4" class="promotion-preview-empty"><?= trans_e('promotions.preview.empty_loading') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </form>
</div>

<script>
let categories = <?= json_encode($categories) ?>;
let brands = <?= json_encode($brands) ?>;
let allowedCustomFields = <?= json_encode($allowedCustomFields) ?>;
let currentFilters = <?= json_encode(array_map(function($key, $value) {
    return ['type' => $key, 'value' => $value];
}, array_keys($filters), array_values($filters))) ?>;
let currentExcludeFilters = <?= json_encode(array_map(function($key, $value) {
    return ['type' => $key, 'value' => $value];
}, array_keys($excludeFilters), array_values($excludeFilters))) ?>;
const csrfToken = <?= json_encode(\App\Support\Csrf::token()) ?>;
let tomSelectInstances = {};
let customFieldOptionsCache = {};
let productOptionsCache = {};
let isDestroyingTomSelectInstances = false;
const PRODUCT_SKU_FILTER_TYPE = 'sku:in';
const PRODUCT_SELECT_ALL_VALUE = '__promotion_select_all_product_search__';
const PRODUCT_PARENT_SELECT_ALL_PREFIX = '__promotion_select_all_parent_variants__:';
const STATIC_TOM_SELECT_FILTER_TYPES = ['categories:in', 'brand_id'];

function padDateTimePart(value) {
    return String(value).padStart(2, '0');
}

function populatePromotionTimeSelect(select, maxValue) {
    if (!select || select.options.length > 0) {
        return;
    }

    const selectedValue = padDateTimePart(select.dataset.selected || 0);
    for (let value = 0; value <= maxValue; value++) {
        const optionValue = padDateTimePart(value);
        const option = new Option(optionValue, optionValue, false, optionValue === selectedValue);
        select.add(option);
    }
}

function syncPromotionDateTimePicker(picker) {
    const target = document.getElementById(picker.dataset.target || '');
    const dateInput = picker.querySelector('.js-promotion-date');
    const hourSelect = picker.querySelector('.js-promotion-hour');
    const minuteSelect = picker.querySelector('.js-promotion-minute');

    if (!target || !dateInput || !hourSelect || !minuteSelect) {
        return;
    }

    target.value = dateInput.value
        ? `${dateInput.value}T${hourSelect.value}:${minuteSelect.value}`
        : '';
}

function initializePromotionDateTimePickers() {
    const pickers = document.querySelectorAll('.js-promotion-datetime-picker');

    pickers.forEach(picker => {
        const dateInput = picker.querySelector('.js-promotion-date');
        const hourSelect = picker.querySelector('.js-promotion-hour');
        const minuteSelect = picker.querySelector('.js-promotion-minute');

        populatePromotionTimeSelect(hourSelect, 23);
        populatePromotionTimeSelect(minuteSelect, 59);
        syncPromotionDateTimePicker(picker);

        [dateInput, hourSelect, minuteSelect].forEach(control => {
            if (!control || control.dataset.datetimeBound === '1') {
                return;
            }

            control.addEventListener('change', () => syncPromotionDateTimePicker(picker));
            control.addEventListener('input', () => syncPromotionDateTimePicker(picker));
            control.dataset.datetimeBound = '1';
        });
    });

    const form = document.getElementById('promotionForm');
    if (form && form.dataset.datetimeBound !== '1') {
        form.addEventListener('submit', () => {
            pickers.forEach(syncPromotionDateTimePicker);
        });
        form.dataset.datetimeBound = '1';
    }
}

initializePromotionDateTimePickers();

const promotionColorInput = document.querySelector('.promotion-color-input');
const promotionColorValue = document.getElementById('promotion-color-value');

if (promotionColorInput && promotionColorValue) {
    const syncPromotionColorValue = () => {
        promotionColorValue.textContent = promotionColorInput.value.toUpperCase();
    };

    promotionColorInput.addEventListener('input', syncPromotionColorValue);
    syncPromotionColorValue();
}

function isCustomFieldFilter(type) {
    return String(type).startsWith('custom_field:');
}

function isTomSelectFilter(type) {
    return STATIC_TOM_SELECT_FILTER_TYPES.includes(type) || isCustomFieldFilter(type);
}

function isProductSkuFilter(type) {
    return type === PRODUCT_SKU_FILTER_TYPE;
}

function isMultiValueFilter(type) {
    return isTomSelectFilter(type) || isProductSkuFilter(type);
}

function isProductPseudoValue(value) {
    return value === PRODUCT_SELECT_ALL_VALUE || String(value).startsWith(PRODUCT_PARENT_SELECT_ALL_PREFIX);
}

function getCustomFieldName(type) {
    return isCustomFieldFilter(type) ? String(type).slice(13) : '';
}

function getDefaultFilterValue(type) {
    if (isMultiValueFilter(type)) {
        return [];
    }

    if (type === 'is_visible' || type === 'is_featured') {
        return true;
    }

    return '';
}

function normalizeTomSelectValues(type, value) {
    if (Array.isArray(value)) {
        return value.map(item => String(item)).filter(item => item !== '');
    }

    if (value === null || value === undefined || value === '') {
        return [];
    }

    const normalizedValue = String(value).trim();
    if (normalizedValue === '') {
        return [];
    }

    if (isCustomFieldFilter(type)) {
        return [normalizedValue];
    }

    return normalizedValue
        .split(',')
        .map(item => item.trim())
        .filter(item => item !== '');
}

function normalizeCustomFieldFilterType(type) {
    if (!String(type).startsWith('custom_field:')) {
        return type;
    }

    return 'custom_field:' + String(type.slice(13)).replace(/\\u([0-9a-fA-F]{4})/g, function(_, hex) {
        return String.fromCharCode(parseInt(hex, 16));
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatProductPrice(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const numericValue = Number(value);
    return Number.isFinite(numericValue) ? numericValue.toFixed(2) : String(value);
}

function cloneProductOption(option) {
    return {
        ...option,
        variant_values: Array.isArray(option.variant_values) ? [...option.variant_values] : option.variant_values,
        parent_variant_values: Array.isArray(option.parent_variant_values) ? [...option.parent_variant_values] : option.parent_variant_values
    };
}

function parseProductSearchTerms(search) {
    return String(search || '')
        .split(/[\s,]+/)
        .map(term => term.trim().toLowerCase())
        .filter(term => term !== '');
}

async function fetchCustomFieldOptions(fieldName, query = '') {

    const cacheKey = JSON.stringify([fieldName, query.trim().toLowerCase()]);
    if (customFieldOptionsCache[cacheKey]) {
        return customFieldOptionsCache[cacheKey];
    }

    const params = new URLSearchParams();
    params.append('_csrf_token', csrfToken);
    params.append('field_name', fieldName);
    params.append('q', query);
    params.append('limit', '50');

    const response = await fetch('?route=promotions&action=customFieldOptions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    });

    let result;
    try {
        result = await response.json();
    } catch (error) {
        console.error('Invalid custom field options response', { fieldName, query, error });
        throw error;
    }

    if (!response.ok || !result.success) {
        console.error('Failed to load custom field options', {
            fieldName,
            query,
            status: response.status,
            error: result?.error || 'Unknown error'
        });
        throw new Error(result?.error || `HTTP ${response.status}`);
    }

    const options = result.data?.options || [];
    customFieldOptionsCache[cacheKey] = options;
    return options;
}

async function fetchProductOptions(query = '') {
    const cacheKey = query.trim().toLowerCase();
    if (productOptionsCache[cacheKey]) {
        return productOptionsCache[cacheKey].map(cloneProductOption);
    }

    const params = new URLSearchParams();
    params.append('_csrf_token', csrfToken);
    params.append('q', query);
    params.append('limit', '200');

    const response = await fetch('?route=promotions&action=productOptions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    });

    let result;
    try {
        result = await response.json();
    } catch (error) {
        console.error('Invalid product options response', { query, error });
        throw error;
    }

    if (!response.ok || !result.success) {
        console.error('Failed to load product options', {
            query,
            status: response.status,
            error: result?.error || 'Unknown error'
        });
        throw new Error(result?.error || `HTTP ${response.status}`);
    }

    const options = result.data?.options || [];
    productOptionsCache[cacheKey] = options.map(cloneProductOption);
    return options.map(cloneProductOption);
}

function destroyTomSelectInstances() {
    isDestroyingTomSelectInstances = true;

    try {
        Object.values(tomSelectInstances).forEach(instance => {
            if (!instance || typeof instance.destroy !== 'function') {
                return;
            }

            if (typeof instance.close === 'function') {
                instance.close();
            }

            if (typeof instance.clear === 'function') {
                instance.clear(true);
            }

            if (typeof instance.setTextboxValue === 'function') {
                instance.setTextboxValue('');
            }

            instance.destroy();
        });
    } finally {
        tomSelectInstances = {};
        isDestroyingTomSelectInstances = false;
    }
}

function initializeTomSelectInstances() {
    if (typeof TomSelect === 'undefined') {
        return;
    }

    document.querySelectorAll('.js-filter-multiselect').forEach(select => {
        const index = Number(select.dataset.filterIndex);
        const scope = select.dataset.filterScope || 'include';
        const filterCollection = getFilterCollection(scope);
        const instanceKey = `${scope}:${index}`;
        const filterType = select.dataset.filterType || '';
        const placeholder = select.dataset.placeholder || appT('promotions.filters.values_placeholder');
        const removeTitle = select.dataset.removeTitle || appT('promotions.filters.remove_value');
        let currentProductSearchValues = [];
        const commonConfig = {
            plugins: {
                checkbox_options: {},
                remove_button: {
                    title: removeTitle
                }
            },
            copyClassesToDropdown: false,
            create: false,
            closeAfterSelect: false,
            hideSelected: false,
            loadingClass: 'promotion-filter-multiselect-loading',
            maxItems: null,
            placeholder: placeholder,
            loadThrottle: 300,
            onChange: function() {
                if (isDestroyingTomSelectInstances || !filterCollection[index] || filterCollection[index].type !== filterType) {
                    return;
                }

                updateFilterValue(index, normalizeTomSelectValues(filterType, this.getValue())
                    .filter(value => !isProductPseudoValue(value)), scope);
            }
        };

        let instance;

        if (isProductSkuFilter(filterType)) {
            instance = new TomSelect(select, {
                ...commonConfig,
                preload: 'focus',
                valueField: 'value',
                labelField: 'label',
                searchField: ['name', 'sku'],
                splitOn: /a^/,
                maxOptions: 100,
                score: function(search) {
                    const defaultScore = this.getScoreFunction(search);
                    const normalizedSearch = String(search || '').toLowerCase();
                    const searchTerms = parseProductSearchTerms(search);
                    const shouldUseTermScore = normalizedSearch.includes(',') || searchTerms.length > 1;

                    return function(item) {
                        if (item.is_select_all) {
                            return 1;
                        }

                        if (item.is_parent_select_all) {
                            const parentName = String(item.name || item.label || '').toLowerCase();
                            const variantValues = Array.isArray(item.variant_values)
                                ? item.variant_values.map(value => String(value).toLowerCase())
                                : [];

                            if (searchTerms.some(term => variantValues.some(value => value === term || value.startsWith(term) || value.includes(term)))) {
                                return 1;
                            }

                            return searchTerms.length === 0 || searchTerms.every(term => parentName.includes(term))
                                ? 1
                                : defaultScore(item);
                        }

                        if (!shouldUseTermScore) {
                            return defaultScore(item);
                        }

                        const sku = String(item.sku || item.value || '').toLowerCase();
                        const name = String(item.name || item.label || '').toLowerCase();
                        const parentVariantValues = Array.isArray(item.parent_variant_values)
                            ? item.parent_variant_values.map(value => String(value).toLowerCase())
                            : [];

                        if (searchTerms.some(term => sku === term || sku.startsWith(term) || sku.includes(term))) {
                            return 1;
                        }

                        if (item.is_variant && searchTerms.some(term => parentVariantValues.some(value => value === term || value.startsWith(term) || value.includes(term)))) {
                            return 0.75;
                        }

                        return searchTerms.every(term => name.includes(term)) ? 0.5 : 0;
                    };
                },
                load: function(query, callback) {
                    fetchProductOptions(query)
                        .then(options => {
                            currentProductSearchValues = options
                                .map(option => String(option.value || option.sku || ''))
                                .filter(value => value !== '' && !isProductPseudoValue(value));

                            callback(currentProductSearchValues.length > 0
                                ? [{
                                    value: PRODUCT_SELECT_ALL_VALUE,
                                    label: appT('promotions.filters.select_all'),
                                    is_select_all: true,
                                    current_count: currentProductSearchValues.length
                                }, ...options]
                                : options);
                        })
                        .catch(() => callback([]));
                },
                onItemAdd: function(value) {
                    let shouldCloseDropdown = true;

                    if (value === PRODUCT_SELECT_ALL_VALUE) {
                        this.removeItem(PRODUCT_SELECT_ALL_VALUE, true);
                        currentProductSearchValues.forEach(productValue => {
                            if (!this.items.includes(productValue)) {
                                this.addItem(productValue, true);
                            }
                        });
                    } else if (String(value).startsWith(PRODUCT_PARENT_SELECT_ALL_PREFIX)) {
                        shouldCloseDropdown = false;
                        const parentOption = this.options[value] || {};
                        const variantValues = Array.isArray(parentOption.variant_values)
                            ? parentOption.variant_values
                            : [];

                        this.removeItem(value, true);
                        variantValues.forEach(productValue => {
                            if (productValue && !this.items.includes(productValue)) {
                                this.addItem(String(productValue), true);
                            }
                        });
                    } else {
                        return;
                    }

                    this.setTextboxValue('');
                    this.lastQuery = null;
                    this.refreshItems();
                    this.refreshOptions(false);
                    if (shouldCloseDropdown) {
                        this.close();
                    }
                    updateFilterValue(index, normalizeTomSelectValues(filterType, this.getValue()), scope);
                },
                render: {
                    option: function(data, escape) {
                        if (data.is_select_all) {
                            return `
                                <div class="promotion-product-select-all-option">
                                    <span>${escape(appT('promotions.filters.select_all'))}</span>
                                    <span>${escape(appT('promotions.filters.current_search_count', { count: data.current_count || 0 }))}</span>
                                </div>
                            `;
                        }

                        if (data.is_parent_select_all) {
                            return `
                                <div class="promotion-product-parent-option">
                                    <span>${escape(data.name || data.label || '')}</span>
                                    <span>${escape(appT('promotions.filters.select_all_variants', { count: data.variant_count || 0 }))}</span>
                                </div>
                            `;
                        }

                        const regularPrice = formatProductPrice(data.regular_price);
                        const salePrice = formatProductPrice(data.sale_price);
                        const salePriceHtml = salePrice
                            ? `<span class="promotion-product-option-sale">${escape(appT('promotions.filters.sale'))}: ${escape(salePrice)}</span>`
                            : '';

                        return `
                            <div class="promotion-product-option ${data.is_variant ? 'promotion-product-variant-option' : ''}">
                                <span class="promotion-product-option-name">${escape(data.name || data.label || '')}</span>
                                <span class="promotion-product-option-sku">${escape(data.sku || data.value || '')}</span>
                                <span class="promotion-product-option-price">${escape(regularPrice)}</span>
                                ${salePriceHtml}
                            </div>
                        `;
                    },
                    item: function(data, escape) {
                        return `<div>${escape(data.sku || data.value || data.text || '')}</div>`;
                    },
                    loading: function() {
                        return `
                            <div class="promotion-filter-loading">
                                <span class="promotion-filter-loading-spinner" aria-hidden="true"></span>
                                <span>${escape(appT('promotions.filters.loading_products'))}</span>
                            </div>
                        `;
                    }
                }
            });
        } else if (isCustomFieldFilter(filterType)) {
            instance = new TomSelect(select, {
                ...commonConfig,
                preload: 'focus',
                valueField: 'value',
                labelField: 'label',
                searchField: ['label'],
                maxOptions: 50,
                load: function(query, callback) {
                    fetchCustomFieldOptions(getCustomFieldName(filterType), query)
                        .then(callback)
                        .catch(() => callback([]));
                },
                render: {
                    option: function(data, escape) {
                        const countHtml = typeof data.count === 'number'
                            ? `<span class="promotion-filter-option-count">${escape(String(data.count))}</span>`
                            : '';
                        return `<div class="promotion-filter-option-row"><span>${escape(data.label)}</span>${countHtml}</div>`;
                    },
                    loading: function() {
                        return `
                            <div class="promotion-filter-loading">
                                <span class="promotion-filter-loading-spinner" aria-hidden="true"></span>
                                <span>${escape(appT('promotions.filters.loading_values'))}</span>
                            </div>
                        `;
                    }
                }
            });
        } else {
            instance = new TomSelect(select, {
                ...commonConfig,
                maxOptions: null
            });
        }

        instance.wrapper.classList.add('promotion-filter-multiselect-wrapper');
        instance.dropdown.classList.add('promotion-filter-multiselect-dropdown');

        tomSelectInstances[instanceKey] = instance;
    });
}

currentFilters = currentFilters.map(filter => ({
    ...filter,
    type: normalizeCustomFieldFilterType(filter.type),
    value: isMultiValueFilter(normalizeCustomFieldFilterType(filter.type))
        ? normalizeTomSelectValues(normalizeCustomFieldFilterType(filter.type), filter.value)
        : filter.value
}));

currentExcludeFilters = currentExcludeFilters.map(filter => ({
    ...filter,
    type: normalizeCustomFieldFilterType(filter.type),
    value: isMultiValueFilter(normalizeCustomFieldFilterType(filter.type))
        ? normalizeTomSelectValues(normalizeCustomFieldFilterType(filter.type), filter.value)
        : filter.value
}));

function getFilterCollection(scope = 'include') {
    return scope === 'exclude' ? currentExcludeFilters : currentFilters;
}

function addFilter(scope = 'include') {
    getFilterCollection(scope).push({ type: 'is_visible', value: getDefaultFilterValue('is_visible') });
    renderFilters();
    schedulePreviewUpdate();
}

function removeFilter(index, scope = 'include') {
    getFilterCollection(scope).splice(index, 1);
    renderFilters();
    schedulePreviewUpdate();
}

function updateFilterType(index, type, scope = 'include') {
    const filterCollection = getFilterCollection(scope);
    if (!filterCollection[index]) {
        return;
    }

    filterCollection[index].type = type;
    filterCollection[index].value = getDefaultFilterValue(type);
    renderFilters();
    schedulePreviewUpdate();
}

function updateFilterValue(index, value, scope = 'include') {
    const filterCollection = getFilterCollection(scope);
    if (!filterCollection[index]) {
        return;
    }

    filterCollection[index].value = value;
    schedulePreviewUpdate();
}

function renderFilters() {
    destroyTomSelectInstances();

    let filterTypes = [
        { value: 'categories:in', label: appT('promotions.filters.categories') },
        { value: 'brand_id', label: appT('promotions.filters.brand') },
        { value: 'price:min', label: appT('promotions.filters.price_min') },
        { value: 'price:max', label: appT('promotions.filters.price_max') },
        { value: 'is_visible', label: appT('promotions.filters.visible') },
        { value: 'is_featured', label: appT('promotions.filters.featured') },
        { value: 'inventory_level:min', label: appT('promotions.filters.inventory_min') },
        { value: 'sku:in', label: appT('promotions.filters.sku') }
    ];

    if (allowedCustomFields.length > 0) {
        allowedCustomFields.forEach(fieldName => {
            filterTypes.push({
                // Ključ pravimo kao prefiks + naziv (npr. custom_field:Materijal)
                value: 'custom_field:' + fieldName, 
                label: appT('promotions.filters.attribute_prefix') + fieldName
            });
        });
    }

    const renderFilterItems = (filterCollection, scope = 'include') => {
        const scopeAttribute = escapeHtml(scope);

        return filterCollection.map((filter, index) => {
        let inputHtml = '';
        
        if (isProductSkuFilter(filter.type)) {
            const selectedSkus = normalizeTomSelectValues(filter.type, filter.value);
            inputHtml = typeof renderProductSkuPickerControl === 'function'
                ? renderProductSkuPickerControl(index, scopeAttribute, selectedSkus)
                : `<input type="text" value="${escapeHtml(selectedSkus.join(', '))}" onchange="updateFilterValue(${index}, this.value, '${scopeAttribute}')" class="form-input">`;
        } else if (isTomSelectFilter(filter.type)) {
            const selectedIds = new Set(normalizeTomSelectValues(filter.type, filter.value));
            const options = filter.type === 'categories:in'
                ? categories
                : (filter.type === 'brand_id' ? brands : selectedIds.size > 0
                    ? Array.from(selectedIds).map(value => ({
                        id: value,
                        name: value,
                        value: value,
                        label: value,
                        sku: value
                    }))
                    : []);
            const placeholder = filter.type === 'categories:in'
                ? appT('promotions.filters.categories_placeholder')
                : (filter.type === 'brand_id'
                    ? appT('promotions.filters.brands_placeholder')
                    : (isProductSkuFilter(filter.type) ? appT('promotions.filters.products_placeholder') : appT('promotions.filters.values_placeholder')));
            const removeTitle = filter.type === 'categories:in'
                ? appT('promotions.filters.remove_category')
                : (filter.type === 'brand_id'
                    ? appT('promotions.filters.remove_brand')
                    : (isProductSkuFilter(filter.type) ? appT('promotions.filters.remove_product') : appT('promotions.filters.remove_value')));

            inputHtml = `
                <select
                    multiple
                    class="js-filter-multiselect"
                    data-filter-index="${index}"
                    data-filter-scope="${scopeAttribute}"
                    data-filter-type="${escapeHtml(filter.type)}"
                    data-placeholder="${escapeHtml(placeholder)}"
                    data-remove-title="${escapeHtml(removeTitle)}"
                >
                    ${options.map(option => `
                        <option value="${escapeHtml(String(option.id))}" ${selectedIds.has(String(option.id)) ? 'selected' : ''}>
                            ${escapeHtml(option.name)}
                        </option>
                    `).join('')}
                </select>
            `;
        } else if (filter.type === 'is_visible' || filter.type === 'is_featured') {
            inputHtml = `<select onchange="updateFilterValue(${index}, this.value === 'true', '${scopeAttribute}')" class="form-input">
                <option value="true" ${String(filter.value) === 'true' ? 'selected' : ''}>${escapeHtml(appT('common.yes'))}</option>
                <option value="false" ${String(filter.value) === 'false' ? 'selected' : ''}>${escapeHtml(appT('common.no'))}</option>
            </select>`;
        } else if (filter.type.includes('custom_field:') || filter.type === 'sku:in'){
            inputHtml = `<input type="text" value="${escapeHtml(filter.value || '')}" onchange="updateFilterValue(${index}, this.value, '${scopeAttribute}')" class="form-input">`;
        } else {
            inputHtml = `<input type="number" value="${escapeHtml(filter.value || '')}" onchange="updateFilterValue(${index}, this.value, '${scopeAttribute}')" class="form-input">`;
        }
        
        return `
            <div class="filter-item promotion-filter-item">
                <div class="promotion-filter-type-field">
                    <label class="promotion-filter-item-label">${escapeHtml(appT('promotions.filters.type_label'))}</label>
                    <select onchange="updateFilterType(${index}, this.value, '${scopeAttribute}')" class="form-input">
                        ${filterTypes.map(t => `
                            <option value="${escapeHtml(t.value)}" ${filter.type === t.value ? 'selected' : ''}>
                                ${escapeHtml(t.label)}
                            </option>
                        `).join('')}
                    </select>
                </div>
                <div class="promotion-filter-value-field">
                    <label class="promotion-filter-item-label">${escapeHtml(appT('promotions.filters.value_label'))}</label>
                    ${inputHtml}
                </div>
                <div class="promotion-filter-remove-field">
                    <button type="button" onclick="removeFilter(${index}, '${scopeAttribute}')" class="promotion-filter-remove-button" title="${escapeHtml(appT('promotions.filters.remove'))}">
                        ×
                    </button>
                </div>
            </div>
        `;
    }).join('');
    };

    document.getElementById('filter-list').innerHTML = renderFilterItems(currentFilters, 'include');
    document.getElementById('exclude-filter-list').innerHTML = renderFilterItems(currentExcludeFilters, 'exclude');
    initializeTomSelectInstances();
}

function buildFiltersPayload() {
    const filtersObj = {};
    currentFilters.forEach(filter => {
        filtersObj[filter.type] = filter.value;
    });

    const excludeFiltersObj = {};
    currentExcludeFilters.forEach(filter => {
        excludeFiltersObj[filter.type] = filter.value;
    });

    if (Object.keys(excludeFiltersObj).length > 0) {
        filtersObj.exclude = excludeFiltersObj;
    }

    return filtersObj;
}

document.getElementById('promotionForm').addEventListener('submit', function(e) {
    const filtersObj = buildFiltersPayload();
    document.getElementById('filters-json').value = JSON.stringify(filtersObj);
});

// Initialize
renderFilters();
</script>

<script>
// Auto-update preview when filters change
let previewTimeout;
let previewProducts = [];

function schedulePreviewUpdate() {
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(updatePreview, 1000); // Update after 1s of inactivity
}

function formatPreviewMoney(value) {
    const numericValue = Number(value);
    return Number.isFinite(numericValue) ? numericValue.toFixed(2) : '0.00';
}

function renderPreviewRows(products) {
    const tbody = document.getElementById('preview-table-body');
    const searchInput = document.getElementById('preview-search');
    const searchTerm = (searchInput?.value || '').trim().toLowerCase();

    if (!tbody) {
        return;
    }

    const visibleProducts = searchTerm
        ? products.filter(product => `${product.name || ''} ${product.sku || ''}`.toLowerCase().includes(searchTerm))
        : products;

    if (visibleProducts.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="promotion-preview-empty">${escapeHtml(appT('promotions.preview.empty_filtered'))}</td></tr>`;
        return;
    }

    tbody.innerHTML = visibleProducts.map(product => `
        <tr>
            <td>
                <div class="promotion-preview-name">${escapeHtml(product.name || '')}</div>
                <div class="promotion-preview-sku">${escapeHtml(appT('promotions.preview.sku_label', { sku: product.sku || '' }))}</div>
            </td>
            <td class="promotion-preview-number">${formatPreviewMoney(product.original_price)}</td>
            <td class="promotion-preview-number promotion-preview-new-price">
                ${formatPreviewMoney(product.promo_price)}
                <div class="promotion-preview-saving">-${formatPreviewMoney(product.savings)}</div>
            </td>
            <td class="promotion-preview-stock">${escapeHtml(product.inventory ?? '')}</td>
        </tr>
    `).join('');
}

function applyPreviewSearch() {
    renderPreviewRows(previewProducts);
}

async function updatePreview() {
    const filtersObj = buildFiltersPayload();
    
    const discount = document.getElementById('promo-discount').value || 0;
    
    try {
        const params = new URLSearchParams();
        params.append('_csrf_token', csrfToken);
        params.append('filters', JSON.stringify(filtersObj));
        params.append('discount_percent', discount);

        const response = await fetch('?route=promotions&action=preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params
        });
        
        const result = await response.json();
        
        if (result.success) {
            const data = result.data;
            const products = data.products || [];
            
            document.getElementById('preview-count').textContent = data.total_products;
            previewProducts = products;
            renderPreviewRows(previewProducts);
        }
    } catch (error) {
        console.error(appT('promotions.preview.preview_error'), error);
        document.getElementById('preview-table-body').innerHTML = `<tr><td colspan="4" class="promotion-preview-empty promotion-preview-error">${escapeHtml(appT('promotions.preview.load_error'))}</td></tr>`;
    }
}

document.getElementById('preview-search')?.addEventListener('input', applyPreviewSearch);
document.getElementById('promo-discount')?.addEventListener('input', schedulePreviewUpdate);

// Initial preview
setTimeout(updatePreview, 500);
</script>
