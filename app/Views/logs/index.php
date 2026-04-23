<div class="page-header">
    <div>
        <h2 class="page-title">Sistemski Logovi</h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;">Istorija sinhronizacija i grešaka (Poslednjih 100 zapisa)</p>
    </div>
    <button onclick="location.reload()" class="btn btn-secondary" style="background: white; color: #374151; border: 1px solid #d1d5db;">
        🔄 Osveži
    </button>
</div>

<div class="log-tabs">
    <a href="?route=logs" class="log-tab active">Sync Logs</a>
    <a href="?route=logs&action=webhooks" class="log-tab">Webhook Events</a>
</div>

<?php if (empty($logs)): ?>
    <div class="empty-state">
        <span class="empty-icon">📋</span>
        <h3>Nema zapisa u logovima</h3>
        <p>Sinhronizacije će se pojaviti ovde nakon izvršavanja.</p>
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table class="logs-table">
            <thead>
                <tr>
                    <th style="width: 160px;">Vreme</th>
                    <th style="width: 120px;">Tip</th>
                    <th>Promocija ID</th>
                    <th>Rezultat</th>
                    <th>Trajanje</th>
                    <th>Detalji</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): 
                    $typeClass = 'type-single';
                    if ($log['sync_type'] === 'full') $typeClass = 'type-full';
                    if ($log['sync_type'] === 'worker') $typeClass = 'type-worker';
                    if (strpos($log['sync_type'], 'error') !== false) $typeClass = 'type-error';
                ?>
                <tr>
                    <td style="font-size: 0.8rem; color: #6b7280;">
                        <?= date('d.m.Y H:i:s', strtotime($log['synced_at'])) ?>
                    </td>
                    <td>
                        <span class="log-type <?= $typeClass ?>"><?= htmlspecialchars($log['sync_type']) ?></span>
                    </td>
                    <td>
                        <?php if ($log['promotion_id']): ?>
                            <a href="?route=promotions&action=edit&id=<?= $log['promotion_id'] ?>" style="color: #3b82f6; text-decoration: none; font-weight: 500;">
                                #<?= $log['promotion_id'] ?>
                            </a>
                        <?php else: ?>
                            <span style="color: #9ca3af;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="status-success">✓ <?= $log['products_synced'] ?></div>
                        <?php if ($log['errors'] > 0): ?>
                            <div class="status-error">⚠ <?= $log['errors'] ?> grešaka</div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family: monospace;"><?= $log['duration_seconds'] ?>s</td>
                    <td style="width: 40%;">
                        <div class="log-message"><?= htmlspecialchars($log['log_message']) ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
