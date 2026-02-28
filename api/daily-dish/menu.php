<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        // Last-resort safe fallback.
        echo '{"success":false,"message":"Failed to encode JSON response."}';
        return;
    }
    echo $json;
}

// This file is hosted on the public website domain.
// It proxies the Daily Dish menu from the internal dashboard (Laravel) API.

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
            if ($line === '' || substr($line, 0, 1) === '#') {
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

try {
    $dashboardBase = rtrim((string) (env_local('DASHBOARD_BASE_URL') ?: ''), '/');
    if ($dashboardBase === '') {
        respond_json(['success' => false, 'message' => 'Dashboard base URL not configured.'], 500);
        exit;
    }
    // Normalize accidental whitespace (e.g. "http://localhost: 8000")
    $dashboardBase = preg_replace('/\s+/', '', $dashboardBase) ?? $dashboardBase;
    // If scheme is missing, PHP treats it like a local file path. Default to http (localhost/dev).
    if (!preg_match('#^https?://#i', $dashboardBase)) {
        $dashboardBase = 'http://' . $dashboardBase;
    }

    $branchId = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 1;
    $from = isset($_GET['from']) ? (string) $_GET['from'] : date('Y-m-01');
    $to = isset($_GET['to']) ? (string) $_GET['to'] : date('Y-m-t', strtotime('+1 month'));

    $url = $dashboardBase.'/api/public/daily-dish/menus?branch_id='.urlencode((string) $branchId)
        .'&from='.urlencode($from)
        .'&to='.urlencode($to);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'header' => "Accept: application/json\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        $last = error_get_last();
        throw new RuntimeException($last['message'] ?? 'Failed to fetch dashboard menu.');
    }

    $json = json_decode($raw, true);
    if (! is_array($json) || ! ($json['success'] ?? false) || ! isset($json['data']) || ! is_array($json['data'])) {
        throw new RuntimeException('Invalid dashboard menu response.');
    }

    // Convert dashboard response into the shape expected by daily-dish.php.
    $data = array_map(static function ($day) {
        $mains = [];
        $mainItems = [];
        if (isset($day['mains']) && is_array($day['mains'])) {
            foreach ($day['mains'] as $m) {
                if (is_array($m) && isset($m['name'])) {
                    $name = (string) $m['name'];
                    $mains[] = $name;
                    $mainItems[] = [
                        'id' => isset($m['id']) ? (int) $m['id'] : null,
                        'name' => $name,
                        'arabic_name' => isset($m['arabic_name']) ? (string) $m['arabic_name'] : null,
                    ];
                } elseif (is_string($m)) {
                    $mains[] = $m;
                    $mainItems[] = [
                        'id' => null,
                        'name' => $m,
                        'arabic_name' => null,
                    ];
                }
            }
        }

        $saladItem = null;
        if (isset($day['salad_item']) && is_array($day['salad_item'])) {
            $saladItem = [
                'id' => isset($day['salad_item']['id']) ? (int) $day['salad_item']['id'] : null,
                'name' => isset($day['salad_item']['name']) ? (string) $day['salad_item']['name'] : null,
                'arabic_name' => isset($day['salad_item']['arabic_name']) ? (string) $day['salad_item']['arabic_name'] : null,
            ];
        }

        $dessertItem = null;
        if (isset($day['dessert_item']) && is_array($day['dessert_item'])) {
            $dessertItem = [
                'id' => isset($day['dessert_item']['id']) ? (int) $day['dessert_item']['id'] : null,
                'name' => isset($day['dessert_item']['name']) ? (string) $day['dessert_item']['name'] : null,
                'arabic_name' => isset($day['dessert_item']['arabic_name']) ? (string) $day['dessert_item']['arabic_name'] : null,
            ];
        }

        return [
            'key' => $day['key'] ?? null,
            'enDay' => $day['enDay'] ?? null,
            'arDay' => $day['arDay'] ?? null,
            'salad' => $day['salad'] ?? null,
            'dessert' => $day['dessert'] ?? null,
            'mains' => $mains,
            // Backward-compatible additions with Arabic labels.
            'mains_items' => $mainItems,
            'salad_item' => $saladItem,
            'dessert_item' => $dessertItem,
        ];
    }, $json['data']);

    respond_json(['success' => true, 'data' => $data], 200);
} catch (Throwable $e) {
    respond_json([
        'success' => false,
        'message' => 'Failed to load menu.',
        'error' => $e->getMessage(),
    ], 500);
}
