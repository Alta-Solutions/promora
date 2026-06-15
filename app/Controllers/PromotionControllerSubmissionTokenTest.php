<?php

use PHPUnit\Framework\TestCase;
use App\Controllers\PromotionController;

class PromotionControllerSubmissionTokenTest extends TestCase {
    private $previousSession;
    private $previousGet;
    private $previousPost;

    protected function setUp(): void {
        $this->previousSession = $_SESSION ?? [];
        $this->previousGet = $_GET ?? [];
        $this->previousPost = $_POST ?? [];
        $_SESSION = ['store_hash' => 'test-store'];
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void {
        $_SESSION = $this->previousSession;
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
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

    public function testFormDataDoesNotIncludeLegacyTargetCategoryColumn(): void {
        $controller = $this->createController();

        $_POST = [
            'name' => 'Summer Sale',
            'custom_field_value' => 'Summer Sale',
            'discount_percent' => '15',
            'start_date' => '2026-06-01 00:00:00',
            'end_date' => '2026-06-30 23:59:59',
            'priority' => '10',
            'filters' => json_encode(['categories:in' => ['123']]),
            'color' => '#3b82f6',
            'description' => 'Promo description',
            'target_category_id' => '123',
        ];

        $formData = $this->invokePrivate($controller, 'getFormData');

        $this->assertArrayNotHasKey('target_category_id', $formData);
        $this->assertSame(['123'], $formData['filters']['categories:in']);
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
