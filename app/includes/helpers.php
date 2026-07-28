<?php
/**
 * helpers.php
 * Small stateless-ish utility functions shared by every module/template.
 */

declare(strict_types=1);

if (!function_exists('e')) {
    /** Shorthand HTML-escape for output in templates. */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        return APP_URL . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** URL to a file under /public, e.g. asset('css/style.css') */
    function asset(string $path): string
    {
        return base_url('public/' . ltrim($path, '/'));
    }
}

if (!function_exists('page_url')) {
    /** URL for a front-controller route, e.g. page_url('reservation') */
    function page_url(string $page, array $params = []): string
    {
        $query = array_merge(['page' => $page], $params);
        return base_url('index.php') . '?' . http_build_query($query);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('t8_session_start')) {
    function t8_session_start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => str_starts_with(strtolower((string) (defined('APP_URL') ? APP_URL : '')), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

if (!function_exists('t8_flash_set')) {
    function t8_flash_set(string $type, string $message): void
    {
        $_SESSION['t8_flash'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('t8_flash_get')) {
    /** Pulls (and clears) all flash messages queued for this request. */
    function t8_flash_get(): array
    {
        $flashes = $_SESSION['t8_flash'] ?? [];
        unset($_SESSION['t8_flash']);
        return $flashes;
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $datetime, string $format = 'M d, Y'): string
    {
        if (!$datetime) {
            return '—';
        }
        $ts = strtotime($datetime);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('current_page')) {
    /** Reads the whitelisted ?page= for the current request. */
    function current_page(): string
    {
        return $_GET['page'] ?? 'dashboard';
    }
}

if (!function_exists('t8_csrf_token')) {
    /** Generates (or reuses) a per-session CSRF token. Call t8_session_start() first. */
    function t8_csrf_token(): string
    {
        if (empty($_SESSION['t8_csrf'])) {
            $_SESSION['t8_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['t8_csrf'];
    }
}

if (!function_exists('t8_csrf_field')) {
    /** Ready-to-echo hidden input for forms: <?= t8_csrf_field() ?> */
    function t8_csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(t8_csrf_token()) . '">';
    }
}

if (!function_exists('t8_csrf_verify')) {
    function t8_csrf_verify(?string $submittedToken): bool
    {
        return !empty($_SESSION['t8_csrf'])
            && !empty($submittedToken)
            && hash_equals($_SESSION['t8_csrf'], $submittedToken);
    }
}
