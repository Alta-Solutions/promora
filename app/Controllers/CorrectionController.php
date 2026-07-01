<?php
namespace App\Controllers;

use App\Models\Database;
use App\Services\PromotionApplicationCorrectionService;
use App\Support\Csrf;

class CorrectionController {
    private const TOKEN_SESSION_KEY = '_promotion_application_correction_tokens';
    private const TOKEN_TTL_SECONDS = 900;

    private $db;
    private $service;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->service = new PromotionApplicationCorrectionService($this->db);
    }

    public function index() {
        $storeHash = $this->requireStoreHash();
        $recentCorrections = $this->service->getRecentCorrections($storeHash);
        $preview = $_SESSION['correction_preview'] ?? null;
        $flash = $_SESSION['correction_flash'] ?? null;
        unset($_SESSION['correction_preview'], $_SESSION['correction_flash']);

        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/corrections/index.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }

    public function preview() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?route=corrections');
            exit;
        }

        if (!Csrf::validateRequest()) {
            http_response_code(403);
            echo \trans('common.csrf_invalid');
            return;
        }

        $storeHash = $this->requireStoreHash();
        $sku = trim((string)($_POST['sku'] ?? ''));
        $promotionId = $this->normalizePositiveInt($_POST['promotion_id'] ?? null);
        $productId = $this->normalizePositiveInt($_POST['product_id'] ?? null);
        $variantId = $this->normalizeNullableInt($_POST['variant_id'] ?? null);

        try {
            $preview = $this->service->previewBySku($storeHash, $sku, $promotionId, $productId, $variantId);
            if (($preview['status'] ?? null) === 'ready') {
                $preview['token'] = $this->issuePreviewToken($storeHash, $preview);
            }
            $_SESSION['correction_preview'] = $preview;
        } catch (\Throwable $e) {
            $_SESSION['correction_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        header('Location: ?route=corrections');
        exit;
    }

    public function apply() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?route=corrections');
            exit;
        }

        if (!Csrf::validateRequest()) {
            http_response_code(403);
            echo \trans('common.csrf_invalid');
            return;
        }

        $storeHash = $this->requireStoreHash();
        $token = (string)($_POST['preview_token'] ?? '');
        $productId = $this->normalizePositiveInt($_POST['product_id'] ?? null);
        $variantId = $this->normalizeNullableInt($_POST['variant_id'] ?? null);
        $promotionId = $this->normalizePositiveInt($_POST['promotion_id'] ?? null);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $visibilityConfirmed = !empty($_POST['visibility_confirmed']);

        try {
            if ($productId === null || $promotionId === null) {
                throw new \InvalidArgumentException(\trans('application_corrections.target_missing'));
            }

            $this->consumePreviewToken($storeHash, $token, $productId, $variantId, $promotionId);
            $result = $this->service->applyVoidCorrection(
                $storeHash,
                $productId,
                $variantId,
                $promotionId,
                $reason,
                $this->getActorContext(),
                $visibilityConfirmed,
                $token
            );

            $_SESSION['correction_flash'] = [
                'type' => 'success',
                'message' => \trans('application_corrections.correction_applied', [
                    'id' => (int)$result['correction_id'],
                ]),
            ];
        } catch (\Throwable $e) {
            $_SESSION['correction_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        header('Location: ?route=corrections');
        exit;
    }

    private function issuePreviewToken(string $storeHash, array $preview): string {
        $token = bin2hex(random_bytes(32));
        $activePromotion = $preview['active_promotion'] ?? [];

        $_SESSION[self::TOKEN_SESSION_KEY][$storeHash][$token] = [
            'product_id' => (int)($activePromotion['product_id'] ?? 0),
            'variant_id' => $this->normalizeNullableInt($activePromotion['variant_id'] ?? null),
            'promotion_id' => (int)($activePromotion['promotion_id'] ?? 0),
            'created_at' => time(),
        ];

        $this->purgeExpiredPreviewTokens($storeHash);

        return $token;
    }

    private function consumePreviewToken(string $storeHash, string $token, int $productId, ?int $variantId, int $promotionId): void {
        $this->purgeExpiredPreviewTokens($storeHash);

        $payload = $_SESSION[self::TOKEN_SESSION_KEY][$storeHash][$token] ?? null;
        if (!is_array($payload)) {
            throw new \InvalidArgumentException(\trans('application_corrections.preview_expired'));
        }

        unset($_SESSION[self::TOKEN_SESSION_KEY][$storeHash][$token]);

        if (
            (int)$payload['product_id'] !== $productId
            || $this->normalizeNullableInt($payload['variant_id'] ?? null) !== $variantId
            || (int)$payload['promotion_id'] !== $promotionId
        ) {
            throw new \InvalidArgumentException(\trans('application_corrections.preview_mismatch'));
        }
    }

    private function purgeExpiredPreviewTokens(string $storeHash): void {
        if (empty($_SESSION[self::TOKEN_SESSION_KEY][$storeHash])) {
            return;
        }

        $now = time();
        foreach ($_SESSION[self::TOKEN_SESSION_KEY][$storeHash] as $token => $payload) {
            $createdAt = (int)($payload['created_at'] ?? 0);
            if ($createdAt <= 0 || ($now - $createdAt) > self::TOKEN_TTL_SECONDS) {
                unset($_SESSION[self::TOKEN_SESSION_KEY][$storeHash][$token]);
            }
        }
    }

    private function getActorContext(): array {
        return [
            'actor_source' => $_SESSION['auth_source'] ?? (isset($_SESSION['username']) ? 'local_admin' : 'unknown'),
            'actor_user_id' => $_SESSION['user_id'] ?? null,
            'actor_email' => $_SESSION['user_email'] ?? ($_SESSION['username'] ?? null),
            'actor_is_owner' => !empty($_SESSION['is_owner']),
        ];
    }

    private function requireStoreHash(): string {
        $storeHash = trim((string)$this->db->getStoreContext());
        if ($storeHash === '') {
            throw new \RuntimeException(\trans('application_corrections.store_context_required'));
        }

        return $storeHash;
    }

    private function normalizePositiveInt($value): ?int {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private function normalizeNullableInt($value): ?int {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }
}
