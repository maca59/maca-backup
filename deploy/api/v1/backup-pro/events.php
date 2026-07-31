<?php

declare(strict_types=1);

/**
 * POST https://api.maca.se/v1/backup-pro/events.php
 *
 * Telemetry from maca BackUp (WordPress). No auth — public ingest.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/maca_restu_telemetry.php';

mcbil_cors();
mcbil_handle_options();
mcbil_json_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$config = mcbil_load_config();
$pdo = mcbil_pdo($config['db']);

$body = mcbil_get_json_body();
if ($body === []) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_body']);
    exit;
}

try {
    $recorded = maca_plugin_telemetry_record_event($pdo, $body);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
    exit;
}

echo json_encode([
    'ok' => true,
    'recorded' => $recorded,
], JSON_UNESCAPED_UNICODE);
