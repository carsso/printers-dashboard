<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(['printers' => printers()], JSON_UNESCAPED_SLASHES);
