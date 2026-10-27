<?php
// Secure PDO connection. Use it anywhere with: $pdo = db();
function db(): PDO
{
    static $pdo = null;          // reuse one connection per request
    if ($pdo !== null) {
        return $pdo;
    }

    $c = require __DIR__ . '/../config/config.php';
    $dsn = "mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset={$c['charset']}";

    try {
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Database connection failed. Please contact the administrator.');
    }
    return $pdo;
}