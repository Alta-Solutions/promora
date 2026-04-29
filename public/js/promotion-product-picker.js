(function() {
    const PAGE_SIZE = 50;
    const MODAL_ID = 'promotion-product-picker-modal';
    const SEARCH_DEBOUNCE_MS = 250;

    const state = {
        filterIndex: null,
        scope: 'include',
        page: 1,
        total: 0,
        totalPages: 1,
        search: '',
        groups: [],
        products: [],
        selected: new Set(),
        collapsedProductIds: new Set(),
        loading: false,
        selectAllSearchLoading: false,
        error: '',
        searchTimer: null
    };

    function html(value) {
        if (typeof escapeHtml === 'function') {
            return escapeHtml(value);
        }

        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function jsArg(value) {
        return JSON.stringify(String(value ?? ''))
            .replace(/&/g, '\\u0026')
            .replace(/</g, '\\u003C')
            .replace(/>/g, '\\u003E')
            .replace(/"/g, '&quot;');
    }

    function formatMoney(value) {
        if (typeof formatProductPrice === 'function') {
            return formatProductPrice(value);
        }

        const numericValue = Number(value);
        return Number.isFinite(numericValue) ? numericValue.toFixed(2) : '';
    }

    function normalizeSkuValues(value) {
        if (Array.isArray(value)) {
            return value.map(item => String(item)).filter(item => item !== '');
        }

        if (value === null || value === undefined || value === '') {
            return [];
        }

        return String(value)
            .split(',')
            .map(item => item.trim())
            .filter(item => item !== '');
    }

    function getFilter(index, scope) {
        if (typeof getFilterCollection !== 'function') {
            return null;
        }

        return getFilterCollection(scope)[index] || null;
    }

    function getCurrentSkus(index, scope) {
        const filter = getFilter(index, scope);
        return normalizeSkuValues(filter ? filter.value : []);
    }

    function setFilterSkus(index, scope, skus) {
        const values = normalizeSkuValues(skus);

        if (typeof updateFilterValue === 'function') {
            updateFilterValue(index, values, scope);
        } else {
            const filter = getFilter(index, scope);
            if (filter) {
                filter.value = values;
            }
        }

        if (typeof renderFilters === 'function') {
            renderFilters();
        }
    }

    function productSubtitle(product) {
        const parts = [];

        if (product.option_label) {
            parts.push(product.option_label);
        }

        if (product.is_variant && !product.option_label) {
            parts.push(appT('product_picker.variant'));
        }

        return parts.join(' | ');
    }

    function selectedSummary(count) {
        if (count === 0) {
            return appT('product_picker.none_selected');
        }

        if (count === 1) {
            return appT('product_picker.one_selected');
        }

        return appT('product_picker.many_selected', { count });
    }

    function selectedChipHtml(index, scope, sku) {
        return `
            <span class="promotion-product-picker-chip">
                <span>${html(sku)}</span>
                <button type="button" onclick="removeProductSkuSelection(${index}, ${jsArg(scope)}, ${jsArg(sku)})" aria-label="${html(appT('product_picker.remove_sku', { sku }))}">x</button>
            </span>
        `;
    }

    function renderProductSkuPickerControl(index, scope, value) {
        const selectedSkus = normalizeSkuValues(value);
        const visibleSkus = selectedSkus.slice(0, 8);
        const hiddenCount = selectedSkus.length - visibleSkus.length;
        const chipsHtml = selectedSkus.length > 0
            ? `
                <div class="promotion-product-picker-chips">
                    ${visibleSkus.map(sku => selectedChipHtml(index, scope, sku)).join('')}
                    ${hiddenCount > 0 ? `<span class="promotion-product-picker-more">+${hiddenCount}</span>` : ''}
                    <button type="button" class="promotion-product-picker-clear" onclick="clearProductSkuSelection(${index}, ${jsArg(scope)})">${html(appT('product_picker.clear'))}</button>
                </div>
            `
            : '';

        return `
            <div class="promotion-product-picker-field">
                <button type="button" class="promotion-product-picker-trigger" onclick="openProductSkuPicker(${index}, ${jsArg(scope)})">
                    <span class="promotion-product-picker-trigger-main">${html(selectedSummary(selectedSkus.length))}</span>
                    <span class="promotion-product-picker-trigger-sub">${html(appT('product_picker.trigger_subtitle'))}</span>
                </button>
                ${chipsHtml}
            </div>
        `;
    }

    function ensureModal() {
        let modal = document.getElementById(MODAL_ID);
        if (modal) {
            return modal;
        }

        modal = document.createElement('div');
        modal.id = MODAL_ID;
        modal.className = 'promotion-product-picker-modal';
        modal.hidden = true;
        modal.innerHTML = `
            <div class="promotion-product-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="promotion-product-picker-title">
                <div class="promotion-product-picker-header">
                    <h3 id="promotion-product-picker-title">${html(appT('product_picker.title'))}</h3>
                    <button type="button" class="promotion-product-picker-close" data-product-picker-close aria-label="${html(appT('product_picker.close'))}">x</button>
                </div>
                <div class="promotion-product-picker-search-wrap">
                    <span class="promotion-product-picker-search-icon" aria-hidden="true"></span>
                    <input type="search" id="promotion-product-picker-search" class="promotion-product-picker-search" placeholder="${html(appT('product_picker.search_placeholder'))}" autocomplete="off">
                </div>
                <div class="promotion-product-picker-meta">
                    <span id="promotion-product-picker-count">${html(appT('product_picker.count', { count: 0 }))}</span>
                    <div class="promotion-product-picker-bulk-actions">
                        <button type="button" id="promotion-product-picker-select-page" class="promotion-product-picker-bulk-button">${html(appT('product_picker.select_page'))}</button>
                        <button type="button" id="promotion-product-picker-select-search" class="promotion-product-picker-bulk-button">${html(appT('product_picker.select_search'))}</button>
                    </div>
                    <div class="promotion-product-picker-pager">
                        <span id="promotion-product-picker-range">${html(appT('product_picker.range', { start: 0, end: 0, total: 0 }))}</span>
                        <button type="button" id="promotion-product-picker-prev" class="promotion-product-picker-page-button" aria-label="${html(appT('product_picker.previous_page'))}">&lsaquo;</button>
                        <button type="button" id="promotion-product-picker-next" class="promotion-product-picker-page-button" aria-label="${html(appT('product_picker.next_page'))}">&rsaquo;</button>
                    </div>
                </div>
                <div id="promotion-product-picker-list" class="promotion-product-picker-list"></div>
                <div class="promotion-product-picker-footer">
                    <button type="button" class="promotion-product-picker-cancel" data-product-picker-close>${html(appT('product_picker.cancel'))}</button>
                    <button type="button" class="promotion-product-picker-apply" id="promotion-product-picker-apply">${html(appT('product_picker.apply'))}</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        modal.addEventListener('click', event => {
            if (event.target === modal || event.target.closest('[data-product-picker-close]')) {
                closeProductSkuPicker();
            }
        });

        modal.querySelector('#promotion-product-picker-search').addEventListener('input', event => {
            window.clearTimeout(state.searchTimer);
            state.search = event.target.value.trim();
            state.searchTimer = window.setTimeout(() => {
                state.page = 1;
                fetchProductPage();
            }, SEARCH_DEBOUNCE_MS);
        });

        modal.querySelector('#promotion-product-picker-prev').addEventListener('click', () => {
            if (state.page > 1) {
                state.page -= 1;
                fetchProductPage();
            }
        });

        modal.querySelector('#promotion-product-picker-next').addEventListener('click', () => {
            if (state.page < state.totalPages) {
                state.page += 1;
                fetchProductPage();
            }
        });

        modal.querySelector('#promotion-product-picker-select-page').addEventListener('click', () => {
            addSkusToSelection(getCurrentPageSkus());
            renderModal();
        });

        modal.querySelector('#promotion-product-picker-select-search').addEventListener('click', selectAllCurrentSearch);

        modal.querySelector('#promotion-product-picker-apply').addEventListener('click', () => {
            if (state.filterIndex === null) {
                return;
            }

            setFilterSkus(state.filterIndex, state.scope, Array.from(state.selected));
            closeProductSkuPicker();
        });

        modal.querySelector('#promotion-product-picker-list').addEventListener('change', event => {
            const checkbox = event.target.closest('.promotion-product-picker-checkbox');
            if (!checkbox) {
                return;
            }

            if (checkbox.checked) {
                state.selected.add(checkbox.value);
            } else {
                state.selected.delete(checkbox.value);
            }

            renderFooterState();
        });

        modal.querySelector('#promotion-product-picker-list').addEventListener('click', event => {
            const expandButton = event.target.closest('.promotion-product-picker-expand');
            if (expandButton) {
                event.preventDefault();
                const productId = String(expandButton.dataset.productId || '');
                if (productId === '') {
                    return;
                }

                if (state.collapsedProductIds.has(productId)) {
                    state.collapsedProductIds.delete(productId);
                } else {
                    state.collapsedProductIds.add(productId);
                }

                renderModal();
                return;
            }

            if (event.target.closest('.promotion-product-picker-checkbox')) {
                return;
            }

            const row = event.target.closest('.promotion-product-picker-row');
            if (!row) {
                return;
            }

            const checkbox = row.querySelector('.promotion-product-picker-checkbox');
            if (!checkbox || checkbox.disabled) {
                return;
            }

            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });

        document.addEventListener('keydown', event => {
            if (!modal.hidden && event.key === 'Escape') {
                closeProductSkuPicker();
            }
        });

        return modal;
    }

    function openProductSkuPicker(index, scope) {
        const modal = ensureModal();
        const searchInput = modal.querySelector('#promotion-product-picker-search');

        state.filterIndex = index;
        state.scope = scope || 'include';
        state.page = 1;
        state.total = 0;
        state.totalPages = 1;
        state.search = '';
        state.groups = [];
        state.products = [];
        state.error = '';
        state.selected = new Set(getCurrentSkus(index, state.scope));
        state.collapsedProductIds = new Set();

        searchInput.value = '';
        modal.hidden = false;
        document.body.classList.add('promotion-product-picker-open');
        renderModal();
        fetchProductPage();
        window.setTimeout(() => searchInput.focus(), 0);
    }

    function closeProductSkuPicker() {
        const modal = document.getElementById(MODAL_ID);
        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('promotion-product-picker-open');
        window.clearTimeout(state.searchTimer);
    }

    async function fetchProductPage() {
        state.loading = true;
        state.error = '';
        renderModal();

        const params = new URLSearchParams();
        params.append('_csrf_token', typeof csrfToken !== 'undefined' ? csrfToken : '');
        params.append('q', state.search);
        params.append('page', String(state.page));
        params.append('per_page', String(PAGE_SIZE));

        try {
            const response = await fetch('?route=promotions&action=productOptions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result?.error || `HTTP ${response.status}`);
            }

            const data = result.data || {};
            state.groups = Array.isArray(data.groups) ? data.groups : [];
            state.products = Array.isArray(data.products) ? data.products : [];
            state.total = Number(data.total || 0);
            state.page = Number(data.page || state.page || 1);
            state.totalPages = Math.max(1, Number(data.total_pages || 1));
        } catch (error) {
            state.groups = [];
            state.products = [];
            state.total = 0;
            state.totalPages = 1;
            state.error = appT('product_picker.error_loading_products');
            console.error(appT('product_picker.error_console'), error);
        } finally {
            state.loading = false;
            renderModal();
        }
    }

    function renderModal() {
        const modal = ensureModal();
        const list = modal.querySelector('#promotion-product-picker-list');

        modal.querySelector('#promotion-product-picker-count').textContent = appT('product_picker.count', { count: state.total });
        modal.querySelector('#promotion-product-picker-prev').disabled = state.loading || state.page <= 1;
        modal.querySelector('#promotion-product-picker-next').disabled = state.loading || state.page >= state.totalPages;
        renderBulkActionState();

        if (state.loading) {
            list.innerHTML = `
                <div class="promotion-product-picker-empty">
                    <span class="promotion-filter-loading-spinner" aria-hidden="true"></span>
                    <span>${html(appT('product_picker.loading_products'))}</span>
                </div>
            `;
            renderFooterState();
            return;
        }

        if (state.error) {
            list.innerHTML = `<div class="promotion-product-picker-empty promotion-product-picker-error">${html(state.error)}</div>`;
            renderFooterState();
            return;
        }

        if (state.groups.length === 0 && state.products.length === 0) {
            list.innerHTML = `<div class="promotion-product-picker-empty">${html(appT('product_picker.empty'))}</div>`;
            renderFooterState();
            return;
        }

        list.innerHTML = state.groups.length > 0
            ? state.groups.map(group => renderProductGroup(group)).join('')
            : state.products.map(product => renderProductRow(product)).join('');
        renderFooterState();
    }

    function getCurrentPageSkus() {
        const skus = [];

        if (state.groups.length > 0) {
            state.groups.forEach(group => {
                if (Array.isArray(group.skus)) {
                    group.skus.forEach(sku => skus.push(String(sku)));
                    return;
                }

                collectProductSku(group.parent).forEach(sku => skus.push(sku));
                (Array.isArray(group.variants) ? group.variants : []).forEach(variant => {
                    collectProductSku(variant).forEach(sku => skus.push(sku));
                });
            });
        } else {
            state.products.forEach(product => {
                collectProductSku(product).forEach(sku => skus.push(sku));
            });
        }

        return Array.from(new Set(skus.filter(sku => sku !== '')));
    }

    function collectProductSku(product) {
        if (!product || product.is_selectable === false) {
            return [];
        }

        const sku = String(product.sku || product.value || '').trim();
        return sku === '' ? [] : [sku];
    }

    function addSkusToSelection(skus) {
        skus.forEach(sku => {
            const normalizedSku = String(sku || '').trim();
            if (normalizedSku !== '') {
                state.selected.add(normalizedSku);
            }
        });
    }

    async function selectAllCurrentSearch() {
        if (state.selectAllSearchLoading) {
            return;
        }

        state.selectAllSearchLoading = true;
        renderBulkActionState();

        const params = new URLSearchParams();
        params.append('_csrf_token', typeof csrfToken !== 'undefined' ? csrfToken : '');
        params.append('q', state.search);
        params.append('select_all_search', '1');

        try {
            const response = await fetch('?route=promotions&action=productOptions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result?.error || `HTTP ${response.status}`);
            }

            addSkusToSelection(Array.isArray(result.data?.skus) ? result.data.skus : []);
        } catch (error) {
            state.error = appT('product_picker.error_selecting_search');
            console.error(appT('product_picker.select_all_error_console'), error);
        } finally {
            state.selectAllSearchLoading = false;
            renderModal();
        }
    }

    function renderProductGroup(group) {
        const variants = Array.isArray(group.variants) ? group.variants : [];
        const parent = group.parent || variants[0] || null;

        if (!parent) {
            return '';
        }

        const productId = String(group.product_id || parent.product_id || '');
        const hasVariants = variants.length > 0;
        const collapsed = hasVariants && state.collapsedProductIds.has(productId);

        return `
            <div class="promotion-product-picker-group ${hasVariants ? 'promotion-product-picker-group-has-variants' : ''}">
                ${renderProductRow(parent, {
                    isParent: true,
                    hasVariants: hasVariants,
                    collapsed: collapsed,
                    productId: productId
                })}
                ${hasVariants && !collapsed ? variants.map(variant => renderProductRow(variant, {
                    isVariant: true,
                    productId: productId
                })).join('') : ''}
            </div>
        `;
    }

    function renderProductRow(product, context = {}) {
        const sku = String(product.sku || product.value || '');
        const isSelectable = product.is_selectable !== false && sku !== '';
        const checked = isSelectable && state.selected.has(sku) ? 'checked' : '';
        const disabled = isSelectable ? '' : 'disabled';
        const price = product.sale_price !== null && product.sale_price !== undefined && product.sale_price !== ''
            ? product.sale_price
            : product.regular_price;
        const subtitle = productSubtitle(product);
        const imageHtml = product.image_url
            ? `<img src="${html(product.image_url)}" alt="" loading="lazy">`
            : `<span>${html(appT('product_picker.image_coming_soon')).replace(/\n/g, '<br>')}</span>`;
        const productId = String(context.productId || product.product_id || '');
        const expandHtml = context.hasVariants
            ? `
                <button
                    type="button"
                    class="promotion-product-picker-expand"
                    data-product-id="${html(productId)}"
                    aria-expanded="${context.collapsed ? 'false' : 'true'}"
                    aria-label="${html(context.collapsed ? appT('product_picker.show_variants') : appT('product_picker.hide_variants'))}"
                >${context.collapsed ? '+' : '-'}</button>
            `
            : '<span class="promotion-product-picker-expand-spacer" aria-hidden="true"></span>';

        return `
            <div class="promotion-product-picker-row ${context.isParent ? 'promotion-product-picker-row-parent' : ''} ${context.isVariant ? 'promotion-product-picker-row-variant' : ''} ${!isSelectable ? 'promotion-product-picker-row-disabled' : ''}">
                <span class="promotion-product-picker-cell promotion-product-picker-checkbox-cell">
                    <input type="checkbox" class="promotion-product-picker-checkbox" value="${html(sku)}" ${checked} ${disabled}>
                </span>
                <span class="promotion-product-picker-cell promotion-product-picker-expand-cell">${expandHtml}</span>
                <span class="promotion-product-picker-cell promotion-product-picker-image">${imageHtml}</span>
                <span class="promotion-product-picker-cell promotion-product-picker-product">
                    <span class="promotion-product-picker-name">${html(product.name || product.label || '')}</span>
                    ${subtitle ? `<span class="promotion-product-picker-subtitle">${html(subtitle)}</span>` : ''}
                </span>
                <span class="promotion-product-picker-cell promotion-product-picker-sku">${html(sku)}</span>
                <span class="promotion-product-picker-cell promotion-product-picker-price">${html(formatMoney(price))}</span>
            </div>
        `;
    }

    function renderFooterState() {
        const modal = ensureModal();
        const start = state.total === 0 ? 0 : ((state.page - 1) * PAGE_SIZE) + 1;
        const end = state.total === 0 ? 0 : Math.min(state.page * PAGE_SIZE, state.total);
        const applyButton = modal.querySelector('#promotion-product-picker-apply');

        modal.querySelector('#promotion-product-picker-range').textContent = appT('product_picker.range', {
            start,
            end,
            total: state.total
        });
        applyButton.textContent = state.selected.size > 0
            ? appT('product_picker.apply_with_count', { count: state.selected.size })
            : appT('product_picker.apply');
    }

    function renderBulkActionState() {
        const modal = ensureModal();
        const selectPageButton = modal.querySelector('#promotion-product-picker-select-page');
        const selectSearchButton = modal.querySelector('#promotion-product-picker-select-search');

        if (!selectPageButton || !selectSearchButton) {
            return;
        }

        const currentPageSkus = getCurrentPageSkus();
        selectPageButton.disabled = state.loading || currentPageSkus.length === 0;
        selectSearchButton.disabled = state.loading || state.selectAllSearchLoading || state.total === 0;
        selectSearchButton.textContent = state.selectAllSearchLoading
            ? appT('product_picker.selecting')
            : appT('product_picker.select_search_action');
    }

    function removeProductSkuSelection(index, scope, sku) {
        const selectedSkus = getCurrentSkus(index, scope).filter(value => value !== String(sku));
        setFilterSkus(index, scope, selectedSkus);
    }

    function clearProductSkuSelection(index, scope) {
        setFilterSkus(index, scope, []);
    }

    window.renderProductSkuPickerControl = renderProductSkuPickerControl;
    window.openProductSkuPicker = openProductSkuPicker;
    window.closeProductSkuPicker = closeProductSkuPicker;
    window.removeProductSkuSelection = removeProductSkuSelection;
    window.clearProductSkuSelection = clearProductSkuSelection;
})();
