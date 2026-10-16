<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/PosApi.php';

use App\Database;
use App\PosApi;

$api = new PosApi(Database::connect(dirname(__DIR__) . '/database/pos.sqlite'));
$rawBody = file_get_contents('php://input') ?: '';
$payload = $rawBody !== '' ? json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR) : [];
$response = $api->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', getallheaders(), is_array($payload) ? $payload : []);

http_response_code($response['status']);
header('Content-Type: application/json');
echo json_encode($response['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
