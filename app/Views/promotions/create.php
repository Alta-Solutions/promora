<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e5e7eb;">
        <h3 class="card-title" style="font-size: 1.25rem; font-weight: 600; color: #111827; margin: 0;">Nova promocija</h3>
        <a href="?route=promotions" class="btn btn-secondary" style="background: white; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 0.9rem;">← Nazad</a>
    </div>
    
    <form method="POST" action="?route=promotions&action=create" id="promotionForm">
        <?= \App\Support\Csrf::inputField() ?>
        <!-- Osnovne informacije -->
        <div style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; margin-bottom: 20px;">
            <h4 style="margin-top: 0; margin-bottom: 15px; font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Osnovne informacije</h4>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Interni naziv promocije <span style="color: #ef4444">*</span></label>
                <input type="text" name="name" class="form-input" required placeholder="npr. Letnja rasprodaja" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                <small style="color: #6b7280; font-size: 0.85em; display: block; margin-top: 4px;">Ovaj naziv se koristi samo unutar aplikacije.</small>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Vrijednost za BigCommerce custom field <span style="color: #ef4444">*</span></label>
                <input type="text" name="custom_field_value" class="form-input" required placeholder="npr. Akcija -20%" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                <small style="color: #6b7280; font-size: 0.85em; display: block; margin-top: 4px;">Ova vrijednost se šalje na BigCommerce kao sadržaj promotion custom field-a.</small>
            </div>

            <div class="form-group">
                <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Opis</label>
                <textarea name="description" class="form-textarea" placeholder="Interna napomena..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; min-height: 80px; font-family: inherit;"></textarea>
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
                               min="0" max="100" step="0.01" required style="width: 100%; padding: 10px; padding-right: 30px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1.1em; font-weight: bold; color: #10b981;">
                        <span style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #6b7280;">%</span>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Prioritet</label>
                    <input type="number" name="priority" class="form-input" value="0" min="0" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <small style="color: #6b7280; font-size: 0.85em; display: block; margin-top: 4px;">Veći broj = viši prioritet primene.</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Oznaka boje</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="color" name="color" class="color-picker" value="#3b82f6" style="height: 40px; width: 60px; padding: 0; border: none; border-radius: 4px; cursor: pointer;">
                        <span style="font-size: 0.9em; color: #6b7280;">Koristi se za prikaz u kalendaru/listi.</span>
                    </div>
                </div>
            </div>

            <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <h4 style="margin-top: 0; margin-bottom: 15px; font-size: 0.85rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Trajanje</h4>
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Početak <span style="color: #ef4444">*</span></label>
                    <input type="datetime-local" name="start_date" class="form-input" 
                           value="<?= date('Y-m-d\TH:i') ?>" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="display: block; margin-bottom: 5px; font-weight: 500; color: #374151;">Kraj <span style="color: #ef4444">*</span></label>
                    <input type="datetime-local" name="end_date" class="form-input" 
                           value="<?= date('Y-m-d\TH:i', strtotime('+7 days')) ?>" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
        </div>

        <!-- Filteri -->
        <div class="form-group" style="margin-bottom: 20px;">
            <label class="form-label" style="display: block; margin-bottom: 10px; font-weight: 600; color: #111827; font-size: 1.1em;">Koji proizvodi su na akciji?</label>
            <div class="filter-builder" style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <div id="filter-list"></div>
                <button type="button" onclick="addFilter()" class="btn btn-secondary btn-sm" style="width: 100%; margin-top: 15px; border: 1px dashed #d1d5db; background: white; color: #6b7280; padding: 10px; border-radius: 6px; cursor: pointer; transition: all 0.2s;">+ Dodaj uslov</button>
            </div>
        </div>
        
        <input type="hidden" name="filters" id="filters-json">

        <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            <a href="?route=promotions" class="btn btn-secondary" style="padding: 10px 20px; background: white; border: 1px solid #d1d5db; color: #374151; border-radius: 6px; text-decoration: none;">Otkaži</a>
            <button type="submit" class="btn btn-primary" style="padding: 10px 25px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);">Kreiraj promociju</button>
        </div>
    </form>
</div>

<script>
let categories = <?= json_encode($categories) ?>;
let brands = <?= json_encode($brands) ?>;
let allowedCustomFields = <?= json_encode($allowedCustomFields) ?>;
let currentFilters = [];
const csrfToken = <?= json_encode(\App\Support\Csrf::token()) ?>;

function addFilter() {
    currentFilters.push({ type: 'is_visible', value: true });
    renderFilters();
}

function removeFilter(index) {
    currentFilters.splice(index, 1);
    renderFilters();
}

function updateFilterType(index, type) {
    currentFilters[index].type = type;
    currentFilters[index].value = '';
    renderFilters();
}

function updateFilterValue(index, value) {
    currentFilters[index].value = value;
}

function renderFilters() {
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
                // Ključ pravimo kao prefiks + naziv (npr. custom_field:Materijal)
                value: 'custom_field:' + fieldName, 
                label: 'Atribut: ' + fieldName
            });
        });
    }

    const html = currentFilters.map((filter, index) => {
        let inputHtml = '';
        
        if (filter.type === 'categories:in') {
            inputHtml = `<select multiple onchange="updateFilterValue(${index}, Array.from(this.selectedOptions).map(o => o.value))" class="form-input" style="height: 80px;">
                ${categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('')}
            </select>`;
        } else if (filter.type === 'brand_id') {
            inputHtml = `<select onchange="updateFilterValue(${index}, this.value)" class="form-input">
                <option value="">Izaberite brend</option>
                ${brands.map(b => `<option value="${b.id}">${b.name}</option>`).join('')}
            </select>`;
        } else if (filter.type === 'is_visible' || filter.type === 'is_featured') {
            inputHtml = `<select onchange="updateFilterValue(${index}, this.value === 'true')" class="form-input">
                <option value="true">Da</option>
                <option value="false">Ne</option>
            </select>`;
        } else if (filter.type.includes('custom_field:') || filter.type === 'sku:in'){
            inputHtml = `<input type="text" value="${filter.value || ''}" onchange="updateFilterValue(${index}, this.value)" class="form-input">`;
        } else {
            inputHtml = `<input type="number" value="${filter.value || ''}" onchange="updateFilterValue(${index}, this.value)" class="form-input">`;
        }
        
        return `
            <div class="filter-item" style="display: grid; grid-template-columns: 200px 1fr 32px; gap: 10px; align-items: start; margin-bottom: 10px; background: white; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
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
                <div>
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

// Trigger preview update on filter change
function addFilter() {
    currentFilters.push({ type: 'is_visible', value: true });
    renderFilters();
    schedulePreviewUpdate();
}

function removeFilter(index) {
    currentFilters.splice(index, 1);
    renderFilters();
    schedulePreviewUpdate();
}

function updateFilterType(index, type) {
    currentFilters[index].type = type;
    currentFilters[index].value = '';
    renderFilters();
    schedulePreviewUpdate();
}

function updateFilterValue(index, value) {
    currentFilters[index].value = value;
    schedulePreviewUpdate();
}

// Initial preview
setTimeout(updatePreview, 500);
</script>
