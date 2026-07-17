<?php

function getConnection(): PDO {
    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT');
    $db   = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASSWORD');
    $sslmode = getenv('DB_SSLMODE') ?: 'prefer'; // set to 'require' for managed/hosted Postgres

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=$sslmode";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements, not string-interpolated ones
    ]);
}