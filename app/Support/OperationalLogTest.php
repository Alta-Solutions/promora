<?php
namespace App\Support;

use PHPUnit\Framework\TestCase;

final class OperationalLogTest extends TestCase {
    public function testConcurrentRotationPreservesEveryEntry(): void {
        $dir = sys_get_temp_dir() . '/promora-writers-' . bin2hex(random_bytes(6));
        mkdir($dir,0700);
        file_put_contents($dir . '/summary.jsonl',"{}\n");
        touch($dir . '/summary.jsonl',time()-86400);
        $script = <<<'PHP'
require 'vendor/autoload.php';
$_ENV['PROMORA_LOG_DIR']=$argv[1];
for ($i=0;$i<20;$i++) {
    App\Support\OperationalLog::write('summary',['writer'=>$argv[2],'sequence'=>$i]);
}
PHP;
        $writers = [];
        try {
            foreach (['a','b'] as $writer) {
                $writers[] = proc_open([PHP_BINARY,'-r',$script,$dir,$writer],[],$pipes,dirname(__DIR__,2));
            }
            foreach ($writers as $process) {
                self::assertSame(0,proc_close($process));
            }
            $archives = glob($dir . '/summary.jsonl.*.gz');
            self::assertCount(1,$archives);
            self::assertSame("{}\n",file_get_contents('compress.zlib://' . $archives[0]));
            $keys = [];
            foreach (file($dir . '/summary.jsonl') as $line) {
                $row = json_decode($line,true,512,JSON_THROW_ON_ERROR);
                $keys[] = $row['writer'] . $row['sequence'];
            }
            self::assertCount(40,array_unique($keys));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
            rmdir($dir);
        }
    }

    public function testApiSummariesRedactQueryStringsAndBoundDetailedOutput(): void {
        $dir = sys_get_temp_dir() . '/promora-log-test-' . bin2hex(random_bytes(6));
        $old = $_ENV['PROMORA_LOG_DIR'] ?? null;
        $_ENV['PROMORA_LOG_DIR'] = $dir;
        try {
            OperationalLog::begin('fixture-job');
            for ($i = 0; $i < 3; $i++) {
                OperationalLog::api('store-a','GET','catalog/products/18?example=fixture-secret',429,10,51,$i);
            }
            OperationalLog::api('store-a','GET','catalog/products/35',200,14,2501,0);
            OperationalLog::api('store-b','GET','catalog/products/12',200,20,100,0);
            OperationalLog::flush();
            $summary = file($dir . '/summary.jsonl');
            self::assertCount(2,$summary);
            $counts = json_decode($summary[0],true)['operations']['GET catalog/products/{id} 429'];
            self::assertSame(3,$counts['calls']);
            self::assertSame(2,$counts['retries']);
            self::assertCount(1,file($dir . '/error.jsonl'));
            self::assertCount(1,file($dir . '/slow.jsonl'));
            foreach (glob($dir . '/*.jsonl') as $file) {
                self::assertStringNotContainsString('fixture-secret',file_get_contents($file));
            }
            touch($dir . '/summary.jsonl',time()-86400);
            OperationalLog::write('summary',['fixture'=>true]);
            $archives = glob($dir . '/summary.jsonl.*.gz');
            self::assertCount(1,$archives);
            self::assertSame(implode('',$summary),file_get_contents('compress.zlib://' . $archives[0]));
            touch($archives[0],time()-16*86400);
            OperationalLog::maintain();
            self::assertFileDoesNotExist($archives[0]);
        } finally {
            if ($old === null) { unset($_ENV['PROMORA_LOG_DIR']); } else { $_ENV['PROMORA_LOG_DIR'] = $old; }
            foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
            if (is_dir($dir)) { rmdir($dir); }
        }
    }
}
