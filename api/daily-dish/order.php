<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// This file is hosted on the public website domain.
// It proxies order creation to the internal dashboard (Laravel) API.

function env_local(string $key): string
{
    $v = (string) (getenv($key) ?: '');
    if ($v !== '') {
        return $v;
    }

    // XAMPP/plain PHP doesn’t auto-load .env like Laravel does; fall back to a local .env file.
    $candidates = [
        dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.env', // project root (api/daily-dish/*)
        dirname(__DIR__).DIRECTORY_SEPARATOR.'.env',    // api/.env
        __DIR__.DIRECTORY_SEPARATOR.'.env',             // api/daily-dish/.env
    ];

    foreach ($candidates as $p) {
        if (!is_file($p)) {
            continue;
        }
        $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $val] = array_map('trim', explode('=', $line, 2));
            if ($k !== $key) {
                continue;
            }
            $val = trim($val);
            if (($val[0] ?? '') === '"' && str_ends_with($val, '"')) {
                $val = substr($val, 1, -1);
            } elseif (($val[0] ?? '') === "'" && str_ends_with($val, "'")) {
                $val = substr($val, 1, -1);
            }
            return $val;
        }
    }

    return '';
}

$dashboardBase = rtrim((string) (env_local('DASHBOARD_BASE_URL') ?: ''), '/');
if ($dashboardBase === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Dashboard base URL not configured.']);
    exit;
}
// Normalize accidental whitespace (e.g. "http://localhost: 8000")
$dashboardBase = preg_replace('/\s+/', '', $dashboardBase) ?? $dashboardBase;
// If scheme is missing, PHP treats it like a local file path. Default to http (localhost/dev).
if (!preg_match('#^https?://#i', $dashboardBase)) {
    $dashboardBase = 'http://' . $dashboardBase;
}

$raw = file_get_contents('php://input');
$payload = [];
if (! empty($raw)) {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $payload = $decoded;
    }
}
if (empty($payload) && ! empty($_POST)) {
    $payload = $_POST;
}

// Minimal validation: dashboard will validate fully.
// reCAPTCHA can be disabled server-side in the dashboard; do not hard-require the token here.
if (empty($payload['items']) || ! is_array($payload['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No order items found.']);
    exit;
}

$url = $dashboardBase.'/api/public/daily-dish/orders';
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'timeout' => 12,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode($payload),
        // Let us read response body even on 4xx/5xx so we can pass it through.
        'ignore_errors' => true,
    ],
]);

try {
    $resp = @file_get_contents($url, false, $context);
    if ($resp === false) {
        $last = error_get_last();
        throw new RuntimeException($last['message'] ?? 'Dashboard order endpoint unreachable.');
    }

    $json = json_decode($resp, true);
    if (! is_array($json)) {
        throw new RuntimeException('Invalid dashboard response.');
    }

    // Pass through dashboard response.
    // Also pass through HTTP status code if available.
    $statusCode = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $statusCode = (int) $m[1];
                break;
            }
        }
    }
    http_response_code($statusCode);
    echo json_encode($json);
} catch (Throwable $e) {
    http_response_code(500);
echo json_encode([
        'success' => false,
        'message' => 'Failed to submit order.',
        'debug' => $e->getMessage(),
]);
}
