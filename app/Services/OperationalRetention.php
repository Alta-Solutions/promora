<?php
namespace App\Services;

use App\Models\Database;

final class OperationalRetention {
    public function __construct(private Database $db) {}

    public static function webhookTimeColumn(Database $db): string {
        foreach (['created_at', 'received_at'] as $name) {
            if ($db->fetchOne("SHOW COLUMNS FROM webhook_events LIKE '$name'")) {
                return $name;
            }
        }
        throw new \RuntimeException('Webhook event time column missing');
    }

    public function run(string $store, bool $apply, int $seconds = 30): array {
        if ($store === '') {
            throw new \InvalidArgumentException('Store required');
        }
        $time = self::webhookTimeColumn($this->db);
        $policies = [
            'completed_jobs' => ['sync_jobs', "status = 'completed' AND COALESCE(outcome,'success') <> 'partial'", 'COALESCE(updated_at,created_at)', 14],
            'failed_jobs' => ['sync_jobs', "(status = 'failed' OR (status='completed' AND outcome='partial'))", 'COALESCE(updated_at,created_at)', 90],
            'successful_sync' => ['sync_log', 'errors = 0', 'synced_at', 90],
            'failed_sync' => ['sync_log', 'errors > 0', 'synced_at', 180],
            'processed_webhooks' => ['webhook_events', 'processed = 1', $time, 90],
            'failed_webhooks' => ['webhook_events', 'processed = 0 AND terminal_at IS NOT NULL', 'terminal_at', 90],
        ];
        $deadline = microtime(true) + $seconds;
        $deleted = array_fill_keys(array_keys($policies), 0);
        do {
            $more = false;
            foreach ($policies as $key => [$table, $condition, $column, $days]) {
                if (!$apply || microtime(true) >= $deadline) {
                    break;
                }
                $count = $this->db->query("DELETE FROM $table WHERE store_hash = ? AND $condition
                    AND $column < DATE_SUB(NOW(), INTERVAL $days DAY)
                    ORDER BY $column, id LIMIT 1000", [$store])->rowCount();
                $deleted[$key] += $count;
                $more = $more || $count === 1000;
            }
        } while ($more && microtime(true) < $deadline);

        $report = [];
        foreach ($policies as $key => [$table, $condition, $column, $days]) {
            $row = $this->db->fetchOne("SELECT COUNT(*) AS remaining, MIN($column) AS oldest
                FROM $table WHERE store_hash = ? AND $condition AND $column < DATE_SUB(NOW(), INTERVAL $days DAY)", [$store]);
            $report[$key] = ['deleted' => $deleted[$key]] + $row;
        }
        $report['unresolved_webhooks'] = $this->db->fetchOne("SELECT COUNT(*) AS records,MIN($time) AS oldest
            FROM webhook_events WHERE store_hash=? AND processed=0 AND terminal_at IS NULL", [$store]);
        return $report;
    }
}
