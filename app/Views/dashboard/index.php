<div class="page-header">
    <div>
        <h2 class="page-title">Dashboard</h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;">Pregled stanja prodavnice i aktivnih akcija</p>
    </div>
    <div style="font-size: 0.85rem; color: #6b7280; background: white; padding: 6px 12px; border-radius: 20px; border: 1px solid #e5e7eb;">
        📅 <?= date('d.m.Y') ?>
    </div>
</div>

<!-- Statistika -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Ukupno promocija</div>
        <div class="stat-value"><?= $stats['total_promotions'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label" style="color: #10b981;">Aktivne promocije</div>
        <div class="stat-value" style="color: #10b981;"><?= $stats['active_promotions'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Proizvoda u promociji</div>
        <div class="stat-value"><?= $stats['total_products'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Poslednja sinhronizacija</div>
        <div class="stat-value" style="font-size: 1.2rem; margin-top: 8px;">
            <?= $stats['last_sync'] ? date('d.m.Y H:i', strtotime($stats['last_sync'])) : 'Nikad' ?>
        </div>
    </div>
</div>

<!-- Sync Progress (Hidden by default) -->
<div id="sync-progress-container" class="sync-progress">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="spinner" style="width: 20px; height: 20px; border-width: 2px; margin: 0;"></div>
            <h4 id="sync-title" style="margin: 0; font-size: 1rem;">Sinhronizacija u toku...</h4>
        </div>
        <span id="sync-stats" style="font-weight: bold; color: #3b82f6;">0 / 0</span>
    </div>
    
    <div class="progress-track">
        <div id="sync-bar" class="progress-fill"></div>
    </div>
    <p id="sync-msg" style="font-size: 12px; color: #6b7280; margin-top: 8px;">Proces neće biti prekinut ukoliko zatvorite aplikaciju.</p>
</div>

<!-- Glavni sadržaj -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    
    <!-- Lista aktivnih promocija -->
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 16px; color: #374151;">Aktivne promocije</h3>
        <div class="active-promos-list">
            <?php if (!empty($activePromotions)): ?>
                <?php foreach ($activePromotions as $promo): ?>
                    <div class="promo-item">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 4px; height: 32px; background: <?= htmlspecialchars($promo['color']) ?>; border-radius: 2px;"></div>
                            <div>
                                <div style="font-weight: 600; color: #111827;"><?= htmlspecialchars($promo['name']) ?></div>
                                <div style="font-size: 0.75rem; color: #6b7280;">
                                    <?= date('d.m.', strtotime($promo['start_date'])) ?> - <?= date('d.m.Y', strtotime($promo['end_date'])) ?>
                                </div>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-weight: 700; color: #10b981; font-size: 1.1rem;"><?= $promo['discount_percent'] ?>%</div>
                            <div style="font-size: 0.75rem; color: #6b7280;">Prioritet: <?= $promo['priority'] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 40px; text-align: center; color: #6b7280;">
                    <div style="font-size: 2rem; margin-bottom: 10px;">💤</div>
                    Trenutno nema aktivnih promocija.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Brze akcije -->
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 16px; color: #374151;">Brze akcije</h3>
        <div class="action-card" style="flex-direction: column; align-items: flex-start; gap: 15px;">
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Globalna sinhronizacija</div>
                <p style="font-size: 0.85rem; color: #6b7280; margin: 0;">Osvežite cene za sve aktivne promocije odjednom.</p>
            </div>
            <button onclick="syncAll()" class="btn btn-primary" style="width: 100%; justify-content: center; display: flex; align-items: center; gap: 8px;">
                🔄 Sinhronizuj sve
            </button>
        </div>
        
        <div class="action-card" style="margin-top: 20px; flex-direction: column; align-items: flex-start; gap: 15px;">
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Nova promocija</div>
                <p style="font-size: 0.85rem; color: #6b7280; margin: 0;">Kreirajte novu kampanju sa popustima.</p>
            </div>
            <a href="?route=promotions&action=create" class="btn btn-secondary" style="width: 100%; text-align: center; background: white; color: #374151; border: 1px solid #d1d5db;">
                + Kreiraj
            </a>
        </div>
    </div>
</div>

<script>
let syncInterval = null;

async function syncAll() {
    if (!confirm('Da li želite da sinhronizujete sve aktivne promocije?')) return;
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Pokretanje...';
    
    try {
        const response = await fetch('?route=api&action=sync_all');
        const result = await response.json();
        
        if (result.success) {
            alert('✅ ' + result.result.message + '\n\nPoslovi su dodati u red i biće obrađeni u pozadini.');
            startPolling(); // Restartujemo proveru statusa
        }
    } catch (error) {
        alert('❌ Greška: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

function startPolling() {
    if (syncInterval) clearInterval(syncInterval);
    checkSyncStatus();
    syncInterval = setInterval(checkSyncStatus, 3000);
}

async function checkSyncStatus() {
    try {
        const response = await fetch('?route=sync&action=getActiveJobStatus');
        const data = await response.json();

        const container = document.getElementById('sync-progress-container');
        
        if (data.active) {
            // Prikaži bar i ažuriraj ga
            container.style.display = 'block';
            
            // Izračunaj procenat
            let percent = 0;
            if (data.total > 0) {
                percent = Math.round((data.processed / data.total) * 100);
            }
            
            document.getElementById('sync-bar').style.width = percent + '%';
            document.getElementById('sync-stats').textContent = `${data.processed} / ${data.total} (${percent}%)`;
            
            let statusText = 'Sinhronizacija u toku...';
            let barColor = '#3b82f6'; // Blue

            if (data.status === 'pending') {
                statusText = '⏳ Na čekanju...';
                barColor = '#f59e0b'; // Orange
            } else if (data.status === 'processing') {
                statusText = '🔄 Sinhronizacija u toku...';
                barColor = '#3b82f6'; // Blue
            } else if (data.status === 'completed') {
                statusText = '✅ Sinhronizacija završena!';
                barColor = '#10b981'; // Green
            }
            
            document.getElementById('sync-title').textContent = statusText;
            document.getElementById('sync-bar').style.background = barColor;

        } else {
            container.style.display = 'none';
            if (syncInterval) {
                clearInterval(syncInterval);
                syncInterval = null;
            }
        }
    } catch (e) {
        console.error('Greška pri proveri statusa:', e);
    }
}

// Pokreni proveru odmah pri učitavanju
startPolling();
</script>