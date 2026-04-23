<?php
// Entry point za BigCommerce Webhook-ove

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\WebhookService;

// Inicijalizacija servisa i obrada zahteva
$service = new WebhookService();
$service->processWebhook();
