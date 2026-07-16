<?php

require __DIR__ . '/../src/Database.php';

$allowedOrigin = getenv('FRONTEND_URL') ?: '*'; // '*' only as a local-dev fallback
header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

try {
    $pdo = getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

if ($path === 'api/items' && $method === 'GET') {
    $stmt = $pdo->query('SELECT id, name, created_at FROM items ORDER BY id');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($path === 'api/items' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $name = trim($body['name'] ?? '');

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'name is required']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO items (name) VALUES (:name) RETURNING id, name, created_at');
    $stmt->execute(['name' => $name]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
