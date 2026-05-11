<?php

use PHPUnit\Framework\TestCase;
use App\Controllers\PromotionController;

class PromotionControllerSubmissionTokenTest extends TestCase {
    private $previousSession;

    protected function setUp(): void {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['store_hash' => 'test-store'];
    }

    protected function tearDown(): void {
        $_SESSION = $this->previousSession;
    }

    public function testCreateSubmissionTokenCanOnlyBeConsumedOnce(): void {
        $controller = $this->createController();
        $token = $this->invokePrivate($controller, 'issueCreateSubmissionToken');

        $this->assertIsString($token);
        $this->assertTrue($this->invokePrivate($controller, 'consumeCreateSubmissionToken', [$token]));
        $this->assertFalse($this->invokePrivate($controller, 'consumeCreateSubmissionToken', [$token]));
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
