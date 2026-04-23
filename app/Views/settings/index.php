<?php
$cacheService = new \App\Services\ProductCacheService();
$cacheStats = $cacheService->getCacheStats();
?>

<div class="page-header">
    <div>
        <h2 class="page-title">Podesavanja</h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;">Konfiguracija aplikacije i odrzavanje sistema</p>
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
                <h3 style="margin: 0; font-size: 1.1rem; color: #111827;">Konfiguracija Modula</h3>
                <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.85rem;">Podesavanja za pracenje cena i filtere za promocije.</p>
            </div>

            <div class="settings-body">
                <div class="settings-group" style="padding-bottom: 24px; margin-bottom: 24px; border-bottom: 1px solid #e5e7eb;">
                    <label class="settings-label">Omnibus Price Tracker</label>
                    <p class="settings-helper" style="margin-bottom: 16px;">
                        Prati promene cena i automatski prikazuje najnizu cenu u poslednjih 30 dana kao custom field pod nazivom <code>lowest_price_30d</code>.
                    </p>
                    <label class="switch">
                        <input type="checkbox" name="enable_omnibus" <?= $enableOmnibus ? 'checked' : '' ?>>
                        <span class="slider round"></span>
                    </label>
                    <span style="margin-left: 12px; vertical-align: middle; color: #374151; position: relative; top: -7px;">
                        Aktiviraj Omnibus Price Tracker
                    </span>

                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px dashed #d1d5db;">
                        <div style="display: flex; justify-content: space-between; gap: 16px; align-items: center; flex-wrap: wrap;">
                            <div>
                                <div class="settings-label" style="margin-bottom: 6px;">Rucni Omnibus Sync</div>
                                <div class="settings-helper" style="margin: 0;">
                                    Kreira queue job za osvezavanje <code>lowest_price_30d</code> za sve parent proizvode u ovoj instanci.
                                    Worker mora biti dostupan da bi job bio obradjen.
                                </div>
                            </div>

                            <button type="submit"
                                    formaction="index.php?route=settings&action=triggerOmnibusSync"
                                    formmethod="POST"
                                    class="btn btn-secondary"
                                    style="background: white; color: #374151; border: 1px solid #d1d5db;"
                                    <?= (!$enableOmnibus || !empty($activeOmnibusJob)) ? 'disabled' : '' ?>>
                                Pokreni Omnibus Sync
                            </button>
                        </div>

                        <?php if (!$enableOmnibus): ?>
                            <div class="settings-helper" style="margin-top: 12px; color: #991b1b;">
                                Rucni sync nije dostupan dok Omnibus Price Tracker nije aktiviran.
                            </div>
                        <?php elseif (!empty($activeOmnibusJob)): ?>
                            <?php
                            $jobPercentage = ((int)$activeOmnibusJob['total_items'] > 0)
                                ? round(((int)$activeOmnibusJob['processed_items'] / (int)$activeOmnibusJob['total_items']) * 100)
                                : 0;
                            ?>
                            <div style="margin-top: 12px; padding: 12px 14px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8;">
                                Omnibus job #<?= (int)$activeOmnibusJob['id'] ?> je trenutno
                                <strong><?= htmlspecialchars($activeOmnibusJob['status']) ?></strong>.
                                Obrada: <?= (int)$activeOmnibusJob['processed_items'] ?>/<?= (int)$activeOmnibusJob['total_items'] ?> (<?= $jobPercentage ?>%).
                            </div>
                        <?php else: ?>
                            <div class="settings-helper" style="margin-top: 12px;">
                                Trenutno nema aktivnog Omnibus sync job-a za ovu instancu.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="settings-group">
                    <label class="settings-label">Filteri za Promocije</label>
                    <input type="text"
                           name="custom_fields"
                           class="form-input"
                           value="<?= htmlspecialchars($allowedFiltersString ?? '') ?>"
                           placeholder="npr. Materijal, Sezona, Pol">

                    <div class="settings-helper">
                        Unesite nazive polja iz BigCommerce-a odvojene zarezom.<br>
                        <span style="display: inline-block; margin-top: 8px;">
                            Primer: <span class="tag">Materijal</span> <span class="tag">Boja</span> <span class="tag">Kolekcija</span>
                        </span>
                    </div>
                </div>

                <div class="settings-group" style="margin-top: 24px;">
                    <label class="settings-label">Naziv Promotion Custom Field-a</label>
                    <input type="text"
                           name="promotion_custom_field_name"
                           class="form-input"
                           value="<?= htmlspecialchars($promotionCustomFieldName ?? \Config::$CUSTOM_FIELD_NAME) ?>"
                           placeholder="<?= htmlspecialchars(\Config::$CUSTOM_FIELD_NAME) ?>">

                    <div class="settings-helper">
                        Naziv custom field-a koji se koristi na BigCommerce proizvodima za promocije.<br>
                        Ako ostane prazno, koristi se default iz <code>.env</code>: <code><?= htmlspecialchars(\Config::$CUSTOM_FIELD_NAME) ?></code>.<br>
                        Promena naziva nije dozvoljena dok postoje aktivne promocije za ovu instancu.
                    </div>
                </div>
            </div>

            <div style="background: #f9fafb; padding: 16px 24px; border-top: 1px solid #e5e7eb; text-align: right;">
                <button type="submit" class="btn btn-primary">
                    Sacuvaj izmene
                </button>
            </div>
        </div>
    </form>

    <div class="settings-card" style="margin-bottom: 30px;">
        <div class="settings-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #111827;">Product Cache</h3>
            <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.85rem;">Status lokalne baze proizvoda.</p>
        </div>

        <div class="settings-body">
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <div style="font-size: 0.75rem; color: #6b7280; text-transform: uppercase; font-weight: 600;">Ukupno</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #111827;"><?= number_format($cacheStats['total_products']) ?></div>
                </div>
                <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <div style="font-size: 0.75rem; color: #6b7280; text-transform: uppercase; font-weight: 600;">Vidljivi</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;"><?= number_format($cacheStats['visible_products']) ?></div>
                </div>
                <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <div style="font-size: 0.75rem; color: #6b7280; text-transform: uppercase; font-weight: 600;">Azurirano</div>
                    <div style="font-size: 1rem; font-weight: 600; color: #374151; margin-top: 5px;">
                        <?= $cacheStats['last_cached'] ? date('d.m. H:i', strtotime($cacheStats['last_cached'])) : '-' ?>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="?route=cache&action=fullSync" class="btn btn-primary" onclick="return confirm('Full sync moze trajati nekoliko minuta. Nastaviti?')">
                    Full Sync
                </a>
                <button onclick="quickSync()" class="btn btn-secondary" style="background: white; color: #374151; border: 1px solid #d1d5db;">
                    Quick Sync
                </button>
                <button onclick="clearCache()" class="btn btn-danger" style="margin-left: auto;">
                    Ocisti
                </button>
            </div>
        </div>
    </div>

    <div class="settings-card" style="margin-bottom: 30px;">
        <div class="settings-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; color: #111827;">Webhooks</h3>
                <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.85rem;">Automatska sinhronizacija izmena.</p>
            </div>
            <div>
                <a href="?route=cache&action=debugWebhooks" class="btn btn-sm btn-secondary" style="margin-right: 5px;">
                    Proveri na BC
                </a>
                <a href="?route=cache&action=registerWebhooks" class="btn btn-sm btn-success" onclick="return confirm('Registrovati webhook-ove?')">
                    Registruj
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
                            <th style="padding: 12px 24px; font-size: 0.75rem; color: #6b7280;">Scope</th>
                            <th style="padding: 12px 24px; font-size: 0.75rem; color: #6b7280;">Status</th>
                            <th style="padding: 12px 24px; font-size: 0.75rem; color: #6b7280; text-align: right;">Akcija</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($webhooks as $webhook): ?>
                            <tr>
                                <td style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb;">
                                    <code style="background: #eff6ff; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 0.85em;"><?= htmlspecialchars($webhook['scope']) ?></code>
                                </td>
                                <td style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb;">
                                    <span class="badge badge-success">Aktivan</span>
                                </td>
                                <td style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb; text-align: right;">
                                    <form action="?route=cache&action=unregisterWebhooks" method="POST" style="display: inline;">
                                        <input type="hidden" name="id" value="<?= (int)$webhook['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding: 4px 8px; font-size: 0.75rem;" onclick="return confirm('Obrisi?')">Obrisi</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #6b7280;">
                    Nema aktivnih webhook-ova.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="settings-card">
        <div class="settings-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #111827;">Sistemske informacije</h3>
        </div>
        <div class="settings-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 4px;">Store Hash</div>
                    <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px;"><?= $_SESSION['store_hash'] ?? '-' ?></code>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 4px;">PHP Verzija</div>
                    <div style="font-weight: 500;"><?= phpversion() ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 4px;">App URL</div>
                    <div style="font-size: 0.9rem; color: #374151; word-break: break-all;"><?= \Config::$APP_URL ?></div>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top: 30px; text-align: center; color: #9ca3af; font-size: 0.8rem;">
        Promotion Manager v1.0.2 | Powered by BigCommerce
    </div>
</div>

<script>
async function quickSync() {
    if (!confirm('Sinhronizovati izmenjene proizvode?')) return;

    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Sync...';

    try {
        const response = await fetch('?route=cache&action=quickSync');
        const result = await response.json();

        if (result.success) {
            alert('Sync zavrsen.\n\nAzurirano: ' + result.updated + ' proizvoda');
            location.reload();
        } else {
            alert('Greska: ' + result.error);
        }
    } catch (error) {
        alert('Greska: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function clearCache() {
    if (!confirm('Ovo ce obrisati sve kesirane proizvode. Nastaviti?')) return;

    try {
        const response = await fetch('?route=cache&action=clearCache', { method: 'POST' });
        const result = await response.json();

        if (result.success) {
            alert('Cache ociscen.');
            location.reload();
        }
    } catch (error) {
        alert('Greska: ' + error.message);
    }
}
</script>
