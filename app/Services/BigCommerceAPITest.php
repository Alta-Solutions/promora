<?php

use PHPUnit\Framework\TestCase;
use App\Services\BigCommerceAPI;

class TestableBigCommerceAPI extends BigCommerceAPI {
    private $stubResponse;

    public function __construct(array $stubResponse) {
        $this->stubResponse = $stubResponse;
    }

    protected function request($method, $endpoint, $data = null) {
        return $this->stubResponse;
    }
}

class BigCommerceAPITest extends TestCase {
    public function testDeleteWebhookTreats200AsSuccess() {
        $api = new TestableBigCommerceAPI(['status' => 200]);

        $result = $api->deleteWebhook(123);

        $this->assertTrue($result);
    }

    public function testDeleteWebhookTreats204AsSuccess() {
        $api = new TestableBigCommerceAPI(['status' => 204]);

        $result = $api->deleteWebhook(123);

        $this->assertTrue($result);
    }
}
