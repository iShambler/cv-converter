<?php
/**
 * CV Converter - Security helpers
 * CSRF, Rate limiting, MIME validation
 */
class Security
{
    /**
     * Start secure session
     */
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');

        session_start();
    }

    // =========================================================================
    // CSRF
    // =========================================================================

    /**
     * Generate CSRF token and store in session
     */
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token from request
     */
    public static function validateCsrf(): void
    {
        $token = $_POST['csrf_token']
            ?? $_GET['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';

        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
            exit;
        }
    }

    // =========================================================================
    // Rate limiting (session-based, simple)
    // =========================================================================

    /**
     * Enforce rate limit — abort if exceeded
     */
    public static function enforceRateLimit(string $action, int $maxRequests = 10, int $windowSeconds = 60): void
    {
        $key = "rate_limit_{$action}";
        $now = time();

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }

        $_SESSION[$key] = array_filter($_SESSION[$key], fn($t) => $t > $now - $windowSeconds);

        if (count($_SESSION[$key]) >= $maxRequests) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Demasiadas peticiones. Espera un momento.']);
            exit;
        }

        $_SESSION[$key][] = $now;
    }

    // =========================================================================
    // MIME validation
    // =========================================================================

    /**
     * Validate file MIME type matches expected extensions
     */
    public static function validateMimeType(string $filePath, string $extension): bool
    {
        $allowedMimes = [
            'pdf'  => ['application/pdf'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'doc'  => ['application/msword', 'application/octet-stream'],
            'txt'  => ['text/plain'],
            'rtf'  => ['text/rtf', 'application/rtf'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'webp' => ['image/webp'],
        ];

        if (!isset($allowedMimes[$extension])) return false;

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);

        return in_array($mime, $allowedMimes[$extension], true);
    }
}
