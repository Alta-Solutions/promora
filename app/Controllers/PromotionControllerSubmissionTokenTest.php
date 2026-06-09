<?php

use PHPUnit\Framework\TestCase;
use App\Controllers\PromotionController;

class PromotionControllerSubmissionTokenTest extends TestCase {
    private $previousSession;
    private $previousGet;

    protected function setUp(): void {
        $this->previousSession = $_SESSION ?? [];
        $this->previousGet = $_GET ?? [];
        $_SESSION = ['store_hash' => 'test-store'];
        $_GET = [];
    }

    protected function tearDown(): void {
        $_SESSION = $this->previousSession;
        $_GET = $this->previousGet;
    }

    public function testCreateSubmissionTokenCanOnlyBeConsumedOnce(): void {
        $controller = $this->createController();
        $token = $this->invokePrivate($controller, 'issueCreateSubmissionToken');

        $this->assertIsString($token);
        $this->assertTrue($this->invokePrivate($controller, 'consumeCreateSubmissionToken', [$token]));
        $this->assertFalse($this->invokePrivate($controller, 'consumeCreateSubmissionToken', [$token]));
    }

    public function testPromotionIndexStatusFilterDefaultsToCurrent(): void {
        $controller = $this->createController();

        $this->assertSame('current', $this->invokePrivate($controller, 'getPromotionIndexStatusFilter'));

        $_GET['status'] = 'expired';
        $this->assertSame('expired', $this->invokePrivate($controller, 'getPromotionIndexStatusFilter'));

        $_GET['status'] = 'all';
        $this->assertSame('all', $this->invokePrivate($controller, 'getPromotionIndexStatusFilter'));

        $_GET['status'] = 'unexpected';
        $this->assertSame('current', $this->invokePrivate($controller, 'getPromotionIndexStatusFilter'));
    }

    private function createController(): PromotionController {
        $reflection = new ReflectionClass(PromotionController::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    private function invokePrivate(object $object, string $methodName, array $args = []) {
        $method = new ReflectionMethod($object, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }
}
