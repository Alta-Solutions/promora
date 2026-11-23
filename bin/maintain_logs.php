<?php
require_once dirname(__DIR__) . '/config.php';
\App\Support\CronAuth::enforce();
if (PHP_SAPI === 'cli') {
    $options = getopt('', ['store:', 'apply', 'dry-run','archive-legacy']);
} else {
    header('Content-Type: application/json');
    $options = ['store'=>$_GET['store'] ?? ''];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'apply') {
        $options['apply'] = true;
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(400);
        exit('{"error":"Use GET for dry-run, or POST mode=apply"}');
    }
}
$store = $options['store'] ?? '';
if (!is_string($store) || $store === '' || (isset($options['archive-legacy']) && !isset($options['apply']))
    || (isset($options['apply']) && isset($options['dry-run']))) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Usage: php bin/maintain_logs.php --store=STORE [--dry-run|--apply [--archive-legacy]]\n");
    } else {
        http_response_code(400);
        echo '{"error":"A store is required"}';
    }
    exit(2);
}
$db = \App\Models\Database::getInstance();
$report = (new \App\Services\OperationalRetention($db))->run($store, isset($options['apply']));
$report['legacy_files'] = \App\Support\OperationalLog::legacyFiles(isset($options['archive-legacy']));
if (isset($options['apply'])) {
    \App\Support\OperationalLog::maintain();
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
