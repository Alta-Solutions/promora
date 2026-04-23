<div class="page-header">
    <div>
        <h2 class="page-title">Webhook Događaji</h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;">Real-time notifikacije od BigCommerce-a</p>
    </div>
    <button onclick="location.reload()" class="btn btn-secondary" style="background: white; color: #374151; border: 1px solid #d1d5db;">
        🔄 Osveži
    </button>
</div>

<div class="log-tabs">
    <a href="?route=logs" class="log-tab">Sync Logs</a>
    <a href="?route=logs&action=webhooks" class="log-tab active">Webhook Events</a>
</div>

<?php if (empty($logs)): ?>
    <div class="empty-state">
        <span class="empty-icon">📡</span>
        <h3>Nema zabeleženih webhook-ova</h3>
        <p>Promenite neki proizvod na BigCommerce-u da biste videli događaj ovde.</p>
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table class="logs-table">
            <thead>
                <tr>
                    <th style="width: 160px;">Vreme</th>
                    <th>Scope (Događaj)</th>
                    <th>Resource ID</th>
                    <th>Status</th>
                    <th>Payload</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): 
                    $payload = json_decode($log['payload'], true);
                    $isProcessed = (bool)$log['processed'];
                ?>
                <tr>
                    <td style="font-size: 0.8rem; color: #6b7280;">
                        <?= date('d.m.Y H:i:s', strtotime($log['received_at'])) ?>
                    </td>
                    <td>
                        <code style="background: #eff6ff; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 0.85em;">
                            <?= htmlspecialchars($log['scope']) ?>
                        </code>
                    </td>
                    <td>
                        <span style="font-weight: 600; color: #374151;">#<?= htmlspecialchars($log['resource_id']) ?></span>
                        <div style="font-size: 0.75rem; color: #9ca3af;"><?= htmlspecialchars($log['resource_type']) ?></div>
                    </td>
                    <td>
                        <?php if ($isProcessed): ?>
                            <span class="badge badge-success">Obrađeno</span>
                            <div style="font-size: 0.7rem; color: #10b981; margin-top: 2px;">
                                <?= $log['processed_at'] ? date('H:i:s', strtotime($log['processed_at'])) : '' ?>
                            </div>
                        <?php else: ?>
                            <span class="badge badge-warning">Na čekanju</span>
                        <?php endif; ?>
                    </td>
                    <td style="width: 40%;">
                        <div class="log-message" style="max-height: 60px;"><?= htmlspecialchars(substr($log['payload'], 0, 200)) . (strlen($log['payload']) > 200 ? '...' : '') ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>