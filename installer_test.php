<?php
// Direct installer test endpoint
header('Content-Type: application/json');

// Log the request
$log = date('Y-m-d H:i:s') . " - Direct test accessed\n";
$log .= "  Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$log .= "  User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'not set') . "\n";
file_put_contents('/workspace/installer_test.log', $log, FILE_APPEND);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Only POST allowed']);
    exit;
}

// Get request data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Simple response
echo json_encode([
    'status' => 'test-ok',
    'message' => 'Direct test successful',
    'received' => $data
]);