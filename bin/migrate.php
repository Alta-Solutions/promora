<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

use App\Support\MigrationRunner;

$runner = new MigrationRunner();
$command = $argv[1] ?? '--status';

try {
    if ($command === '--up') {
        $ran = $runner->up();
        if (empty($ran)) {
            echo "No pending migrations.\n";
            exit(0);
        }

        foreach ($ran as $migration) {
            echo "Applied {$migration}\n";
        }
        exit(0);
    }

    if ($command === '--status') {
        foreach ($runner->status() as $row) {
            echo ($row['applied'] ? '[x] ' : '[ ] ') . $row['migration'] . "\n";
        }
        exit(0);
    }

    fwrite(STDERR, "Usage: php bin/migrate.php [--status|--up]\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
