<?php
$filters = json_decode($promotion['filters'] ?? '{}', true);
?>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e5e7eb;">
        <h3 class="card-title" style="font-size: 1.25rem; font-weight: 600; color: #111827; margin: 0;">Izmeni promociju</h3>
        <a href="?route=promotions" class="btn btn-secondary" style="background: white; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 0.9rem;">← Nazad</a>
    </div>
    
    <form method="POST" action="?route=promotions&action=edit&id=<?= $promotion['id'] ?>" id="promotionForm">
        <?= \App\Support\Csrf::inputField() ?>
        <!-- Osnovne informacije -->
        <div style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; margin-bottom: 20px;">
            <h4 style="margin-top: 0; margin-bottom: 15px; font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Osnovne informacije</h4>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Interni naziv promocije <span style="color: #ef4444">*</span></label>
                <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($promotion['name']) ?>" required placeholder="npr. Letnja rasprodaja" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                <small style="color: #6b7280; font-size: 0.85em; display: block; margin-top: 4px;">Ovaj naziv se koristi samo unutar aplikacije.</small>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Vrijednost za BigCommerce custom field <span style="color: #ef4444">*</span></label>
                <input type="text" name="custom_field_value" class="form-input" value="<?= htmlspecialchars($promotion['custom_field_value'] ?? $promotion['name']) ?>" required placeholder="npr. Akcija -20%" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                <small style="color: #6b7280; font-size: 0.85em; display: block; margin-top: 4px;">Ova vrijednost se šalje na BigCommerce kao sadržaj promotion custom field-a.</small>
            </div>

            <div class="form-group">
                <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Opis</label>
                <textarea name="description" class="form-textarea" placeholder="Interna napomena..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; min-height: 80px; font-family: inherit;"><?= htmlspecialchars($promotion['description'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Podešavanja -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <h4 style="margin-top: 0; margin-bottom: 15px; font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Detalji popusta</h4>
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Popust (%) <span style="color: #ef4444">*</span></label>
                    <div style="position: relative;">
                        <input type="number" name="discount_percent" id="promo-discount" class="form-input" 
                               value="<?= $promotion['discount_percent'] ?>"
                               min="0" max="100" step="0.01" required style="width: 100%; padding: 10px; padding-right: 30px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1.1em; font-weight: bold; color: #10b981;">
                        <span style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #6b7280;">%</span>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Prioritet</label>
                    <input type="number" name="priority" class="form-input" value="<?= $promotion['priority'] ?>" min="0" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <small style="color: #6b7280; font-size: 0.85em; display: block; margin-top: 4px;">Veći broj = viši prioritet primene.</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Oznaka boje</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="color" name="color" class="color-picker" value="<?= $promotion['color'] ?>" style="height: 40px; width: 60px; padding: 0; border: none; border-radius: 4px; cursor: pointer;">
                        <span style="font-size: 0.9em; color: #6b7280;">Koristi se za prikaz u kalendaru/listi.</span>
                    </div>
                </div>
            </div>

            <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <h4 style="margin-top: 0; margin-bottom: 15px; font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Trajanje</h4>
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Početak <span style="color: #ef4444">*</span></label>
                    <input type="datetime-local" name="start_date" class="form-input" 
                           value="<?= date('Y-m-d\TH:i', strtotime($promotion['start_date'])) ?>" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Kraj <span style="color: #ef4444">*</span></label>
                    <input type="datetime-local" name="end_date" class="form-input" 
                           value="<?= date('Y-m-d\TH:i', strtotime($promotion['end_date'])) ?>" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
        </div>

        <!-- Filteri -->
        <div class="form-group">
            <label class="form-label" style="display: block; margin-bottom: 10px; font-weight: 600; color: #111827; font-size: 1.1em;">Koji proizvodi su na akciji?</label>
            <div class="filter-builder" style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <div id="filter-list"></div>
                <button type="button" onclick="addFilter()" class="btn btn-secondary btn-sm" style="width: 100%; margin-top: 15px; border: 1px dashed #d1d5db; background: white; color: #6b7280; padding: 10px; border-radius: 6px; cursor: pointer; transition: all 0.2s;">+ Dodaj uslov</button>
            </div>
        </div>
        
        <input type="hidden" name="filters" id="filters-json">

        <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            <a href="?route=promotions" class="btn btn-secondary" style="padding: 10px 20px; background: white; border: 1px solid #d1d5db; color: #374151; border-radius: 6px; text-decoration: none;">Otkaži</a>
            <button type="submit" class="btn btn-primary" style="padding: 10px 25px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);">Sačuvaj izmene</button>
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
const csrfToken = <?= json_encode(\App\Support\Csrf::token()) ?>;
let tomSelectInstances = {};
let customFieldOptionsCache = {};
let productOptionsCache = {};
let isDestroyingTomSelectInstances = false;
const PRODUCT_SKU_FILTER_TYPE = 'sku:in';
const PRODUCT_SELECT_ALL_VALUE = '__promotion_select_all_product_search__';
const PRODUCT_PARENT_SELECT_ALL_PREFIX = '__promotion_select_all_parent_variants__:';
const STATIC_TOM_SELECT_FILTER_TYPES = ['categories:in', 'brand_id', PRODUCT_SKU_FILTER_TYPE];

function isCustomFieldFilter(type) {
    return String(type).startsWith('custom_field:');
}

function isTomSelectFilter(type) {
    return STATIC_TOM_SELECT_FILTER_TYPES.includes(type) || isCustomFieldFilter(type);
}

function isProductSkuFilter(type) {
    return type === PRODUCT_SKU_FILTER_TYPE;
}

function isProductPseudoValue(value) {
    return value === PRODUCT_SELECT_ALL_VALUE || String(value).startsWith(PRODUCT_PARENT_SELECT_ALL_PREFIX);
}

function getCustomFieldName(type) {
    return isCustomFieldFilter(type) ? String(type).slice(13) : '';
}

function getDefaultFilterValue(type) {
    if (isTomSelectFilter(type)) {
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
    params.append('limit', '100');

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
        const filterType = select.dataset.filterType || '';
        const placeholder = select.dataset.placeholder || 'Izaberite vrednosti';
        const removeTitle = select.dataset.removeTitle || 'Ukloni stavku';
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
                if (isDestroyingTomSelectInstances || !currentFilters[index] || currentFilters[index].type !== filterType) {
                    return;
                }

                updateFilterValue(index, normalizeTomSelectValues(filterType, this.getValue())
                    .filter(value => !isProductPseudoValue(value)));
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
                                    label: 'Izaberi sve',
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
                    updateFilterValue(index, normalizeTomSelectValues(filterType, this.getValue()));
                },
                render: {
                    option: function(data, escape) {
                        if (data.is_select_all) {
                            return `
                                <div class="promotion-product-select-all-option">
                                    <span>Izaberi sve</span>
                                    <span>${escape(String(data.current_count || 0))} iz trenutne pretrage</span>
                                </div>
                            `;
                        }

                        if (data.is_parent_select_all) {
                            return `
                                <div class="promotion-product-parent-option">
                                    <span>${escape(data.name || data.label || '')}</span>
                                    <span>Izaberi sve varijante (${escape(String(data.variant_count || 0))})</span>
                                </div>
                            `;
                        }

                        const regularPrice = formatProductPrice(data.regular_price);
                        const salePrice = formatProductPrice(data.sale_price);
                        const salePriceHtml = salePrice
                            ? `<span class="promotion-product-option-sale">Sale: ${escape(salePrice)}</span>`
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
                                <span>Ucitavanje proizvoda...</span>
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
                            ? `<span style="margin-left: auto; color: #6b7280; font-size: 12px;">${escape(String(data.count))}</span>`
                            : '';
                        return `<div style="display: flex; align-items: center; gap: 10px; width: 100%;"><span>${escape(data.label)}</span>${countHtml}</div>`;
                    },
                    loading: function() {
                        return `
                            <div class="promotion-filter-loading">
                                <span class="promotion-filter-loading-spinner" aria-hidden="true"></span>
                                <span>Učitavanje vrednosti...</span>
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

        tomSelectInstances[index] = instance;
    });
}

currentFilters = currentFilters.map(filter => ({
    ...filter,
    type: normalizeCustomFieldFilterType(filter.type),
    value: isTomSelectFilter(normalizeCustomFieldFilterType(filter.type))
        ? normalizeTomSelectValues(normalizeCustomFieldFilterType(filter.type), filter.value)
        : filter.value
}));

function addFilter() {
    currentFilters.push({ type: 'is_visible', value: getDefaultFilterValue('is_visible') });
    renderFilters();
    schedulePreviewUpdate();
}

function removeFilter(index) {
    currentFilters.splice(index, 1);
    renderFilters();
    schedulePreviewUpdate();
}

function updateFilterType(index, type) {
    if (!currentFilters[index]) {
        return;
    }

    currentFilters[index].type = type;
    currentFilters[index].value = getDefaultFilterValue(type);
    renderFilters();
    schedulePreviewUpdate();
}

function updateFilterValue(index, value) {
    if (!currentFilters[index]) {
        return;
    }

    currentFilters[index].value = value;
    schedulePreviewUpdate();
}

function renderFilters() {
    destroyTomSelectInstances();

    let filterTypes = [
        { value: 'categories:in', label: 'Kategorije' },
        { value: 'brand_id', label: 'Brend' },
        { value: 'price:min', label: 'Minimalna cena' },
        { value: 'price:max', label: 'Maksimalna cena' },
        { value: 'is_visible', label: 'Vidljiv' },
        { value: 'is_featured', label: 'Istaknut' },
        { value: 'inventory_level:min', label: 'Minimalna zaliha' },
        { value: 'sku:in', label: 'SKU proizvoda' }
    ];

    if (allowedCustomFields.length > 0) {
        allowedCustomFields.forEach(fieldName => {
            filterTypes.push({
                value: 'custom_field:' + fieldName, 
                label: 'Atribut: ' + fieldName
            });
        });
    }

    const html = currentFilters.map((filter, index) => {
        let inputHtml = '';
        
        if (isTomSelectFilter(filter.type)) {
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
                ? 'Izaberite kategorije'
                : (filter.type === 'brand_id'
                    ? 'Izaberite brendove'
                    : (isProductSkuFilter(filter.type) ? 'Pretrazi proizvode po nazivu ili SKU-u' : 'Pretraži i izaberite vrednosti'));
            const removeTitle = filter.type === 'categories:in'
                ? 'Ukloni kategoriju'
                : (filter.type === 'brand_id'
                    ? 'Ukloni brend'
                    : (isProductSkuFilter(filter.type) ? 'Ukloni proizvod' : 'Ukloni vrednost'));

            inputHtml = `
                <select
                    multiple
                    class="js-filter-multiselect"
                    data-filter-index="${index}"
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
            inputHtml = `<select onchange="updateFilterValue(${index}, this.value === 'true')" class="form-input">
                <option value="true" ${String(filter.value) === 'true' ? 'selected' : ''}>Da</option>
                <option value="false" ${String(filter.value) === 'false' ? 'selected' : ''}>Ne</option>
            </select>`;
        } else if (filter.type.includes('custom_field:') || filter.type === 'sku:in'){
            inputHtml = `<input type="text" value="${filter.value || ''}" onchange="updateFilterValue(${index}, this.value)" class="form-input">`;
        } else {
            inputHtml = `<input type="number" value="${filter.value || ''}" onchange="updateFilterValue(${index}, this.value)" class="form-input">`;
        }
        
        return `
            <div class="filter-item promotion-filter-item" style="display: grid; grid-template-columns: 200px minmax(0, 1fr) 32px; gap: 10px; align-items: start; margin-bottom: 10px; background: white; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                <div>
                    <label style="font-size: 11px; font-weight: 600; color: #6b7280; display: block; margin-bottom: 4px;">Tip uslova</label>
                    <select onchange="updateFilterType(${index}, this.value)" class="form-input">
                        ${filterTypes.map(t => `
                            <option value="${t.value}" ${filter.type === t.value ? 'selected' : ''}>
                                ${t.label}
                            </option>
                        `).join('')}
                    </select>
                </div>
                <div style="min-width: 0;">
                    <label style="font-size: 11px; font-weight: 600; color: #6b7280; display: block; margin-bottom: 4px;">Vrednost</label>
                    ${inputHtml}
                </div>
                <div style="padding-top: 22px;">
                    <button type="button" onclick="removeFilter(${index})" class="btn btn-danger btn-sm" style="width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center;" title="Ukloni">
                        ×
                    </button>
                </div>
            </div>
        `;
    }).join('');

    document.getElementById('filter-list').innerHTML = html;
    initializeTomSelectInstances();
}

document.getElementById('promotionForm').addEventListener('submit', function(e) {
    const filtersObj = {};
    currentFilters.forEach(filter => {
        filtersObj[filter.type] = filter.value;
    });
    document.getElementById('filters-json').value = JSON.stringify(filtersObj);
});

// Initialize
renderFilters();
</script>

<div class="card" style="margin-top: 20px; background: #f9fafb;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h4>📦 Pregled proizvoda (<span id="preview-count">0</span>)</h4>
        <div id="preview-total-savings" style="font-size: 14px; color: #10b981; font-weight: bold;"></div>
    </div>
    
    <div style="max-height: 400px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; background: white;">
        <table class="table" style="margin: 0; font-size: 13px;">
            <thead style="position: sticky; top: 0; background: #f3f4f6; z-index: 1;">
                <tr>
                    <th>Naziv / SKU</th>
                    <th style="text-align: right;">Cena</th>
                    <th style="text-align: right;">Nova cena</th>
                    <th style="text-align: center;">Zaliha</th>
                </tr>
            </thead>
            <tbody id="preview-table-body">
                <tr><td colspan="4" style="text-align: center; padding: 20px; color: #6b7280;">Učitavanje...</td></tr>
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 10px; text-align: right;">
        <button type="button" onclick="updatePreview()" class="btn btn-secondary btn-sm">
            🔄 Osveži listu
        </button>
    </div>
</div>

<script>
// Auto-update preview when filters change
let previewTimeout;
function schedulePreviewUpdate() {
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(updatePreview, 1000); // Update after 1s of inactivity
}

async function updatePreview() {
    const filtersObj = {};
    currentFilters.forEach(filter => {
        filtersObj[filter.type] = filter.value;
    });
    
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
        const tbody = document.getElementById('preview-table-body');
        
        if (result.success) {
            const data = result.data;
            const products = data.products || [];
            
            document.getElementById('preview-count').textContent = data.total_products;
            
            if (products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 20px; color: #6b7280;">Nema proizvoda koji odgovaraju filterima.</td></tr>';
                return;
            }

            tbody.innerHTML = products.map(p => `
                <tr>
                    <td>
                        <div style="font-weight: 600;">${p.name}</div>
                        <div style="color: #6b7280; font-size: 11px;">SKU: ${p.sku}</div>
                    </td>
                    <td style="text-align: right;">${parseFloat(p.original_price).toFixed(2)}</td>
                    <td style="text-align: right; color: #10b981; font-weight: bold;">
                        ${parseFloat(p.promo_price).toFixed(2)}
                        <div style="font-size: 10px; color: #ef4444;">-${parseFloat(p.savings).toFixed(2)}</div>
                    </td>
                    <td style="text-align: center;">${p.inventory}</td>
                </tr>
            `).join('');
        }
    } catch (error) {
        console.error('Preview error:', error);
        document.getElementById('preview-table-body').innerHTML = '<tr><td colspan="4" style="text-align: center; color: red;">Greška pri učitavanju.</td></tr>';
    }
}

setTimeout(updatePreview, 500);
</script>
