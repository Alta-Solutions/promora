<div class="page-header">
    <div>
        <h2 class="page-title">Promocije</h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;">Upravljajte popustima i kampanjama</p>
    </div>
    <a href="?route=promotions&action=create" class="btn btn-primary">
        + Nova promocija
    </a>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($promotions)): ?>
        <div class="empty-state">
            <span class="empty-icon">🏷️</span>
            <h3>Nema aktivnih promocija</h3>
            <p>Kreirajte prvu promociju da biste započeli sa popustima.</p>
            <a href="?route=promotions&action=create" class="btn btn-primary" style="margin-top: 20px;">Kreiraj promociju</a>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="promo-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Frontend value</th>
                        <th>Discount</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promotions as $promo): 
                        // Logika za status
                        $now = time();
                        $start = strtotime($promo['start_date']);
                        $end = strtotime($promo['end_date']);
                        
                        $statusClass = 'badge-active';
                        $statusLabel = 'Aktivna';
                        
                        if ($promo['status'] === 'expired' || $end < $now) {
                            $statusClass = 'badge-expired';
                            $statusLabel = 'Istekla';
                        } elseif ($start > $now) {
                            $statusClass = 'badge-scheduled';
                            $statusLabel = 'Zakazana';
                        }
                    ?>
                    <tr>
                        <td>
                            <div class="promo-name-cell">
                                <div class="color-indicator" style="background-color: <?= htmlspecialchars($promo['color']) ?>;"></div>
                                <div class="promo-info">
                                    <span class="promo-name"><?= htmlspecialchars($promo['name']) ?></span>
                                    <?php if (!empty($promo['description'])): ?>
                                        <span class="promo-desc"><?= htmlspecialchars($promo['description']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 0.9rem; color: #374151; max-width: 240px; word-break: break-word;">
                                <?= htmlspecialchars($promo['custom_field_value'] ?? $promo['name']) ?>
                            </div>
                        </td>
                        <td>
                            <span class="discount-value"><?= $promo['discount_percent'] ?>%</span>
                        </td>
                        <td>
                            <div style="font-size: 0.85rem; color: #374151;">
                                <?= date('d.m.Y H:i', $start) ?>
                            </div>
                            <div style="font-size: 0.75rem; color: #9ca3af;">
                                do <?= date('d.m.Y H:i', $end) ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                        </td>
                        <td>
                            <span style="font-weight: 600; color: #6b7280;"><?= $promo['priority'] ?></span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button onclick="syncSingle(<?= $promo['id'] ?>)" class="btn-icon" title="Sinhronizuj odmah">
                                    🔄
                                </button>
                                <a href="?route=promotions&action=edit&id=<?= $promo['id'] ?>" class="btn-icon" title="Izmeni">
                                    ✏️
                                </a>
                                <a href="?route=promotions&action=delete&id=<?= $promo['id'] ?>" class="btn-icon delete" title="Obriši" onclick="return confirm('Da li ste sigurni da želite da obrišete ovu promociju? Proizvodi će biti vraćeni na originalne cene.');">
                                    🗑️
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
async function syncSingle(id) {
    if(!confirm('Pokrenuti ručnu sinhronizaciju za ovu promociju?')) return;
    
    try {
        const response = await fetch('?route=sync&action=startSingle&id=' + id);
        const result = await response.json();
        if(result.success) {
            alert('✅ Sinhronizacija započeta! Posao ID: ' + result.job_id);
        } else {
            alert('❌ Greška: ' + (result.error || 'Nepoznata greška'));
        }
    } catch(e) {
        alert('❌ Greška u komunikaciji sa serverom.');
    }
}
</script>
