<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$radioName = $_GET['radio'] ?? '';
$validRadios = array_column(get_stations(), 'location');
if (!in_array($radioName, $validRadios, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown radio']);
    exit;
}

$db = get_db();
$reservedBy = get_current_hour_conflict($db, $radioName, $_SESSION['call_sign']);

echo json_encode([
    'reserved' => $reservedBy !== null,
    'reserved_by' => $reservedBy,
]);
