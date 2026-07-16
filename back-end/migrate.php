<?php

require __DIR__ . '/src/Database.php';

$pdo = getConnection();

$pdo->exec("CREATE TABLE IF NOT EXISTS items (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
)");

// Only seed sample data if the table is actually empty --
// prevents duplicate rows every time the container restarts.
$count = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
if ($count == 0) {
    $pdo->exec("INSERT INTO items (name) VALUES ('Sample item 1'), ('Sample item 2')");
}

echo "Migration complete\n";