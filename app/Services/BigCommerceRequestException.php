<?php
namespace App\Services;

final class BigCommerceRequestException extends \RuntimeException {
    public function __construct(int $status, string $endpoint, private string $responseBody) {
        $path = explode('?', $endpoint, 2)[0];
        parent::__construct("BigCommerce API Error (Status: {$status}, Endpoint: {$path})", $status);
    }

    /** Only the batch response parser needs the original body. */
    public function batchDiagnostic(): string {
        return $this->getMessage() . ': Body: ' . $this->responseBody;
    }
}
