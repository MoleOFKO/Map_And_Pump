<?php
/**
 * Local development database connection.
 *
 * Update these values if your local MySQL/MariaDB installation uses different
 * credentials. Environment variables take precedence, so no credentials need
 * to be committed for another local setup.
 */
$host = getenv('MAP_PUMP_DB_HOST') ?: '127.0.0.1';
$dbname = getenv('MAP_PUMP_DB_NAME') ?: 'map_and_pump';
$username = getenv('MAP_PUMP_DB_USER') ?: 'root';
$password = getenv('MAP_PUMP_DB_PASSWORD') ?: '';
$port = (int) (getenv('MAP_PUMP_DB_PORT') ?: 3306);

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    if (defined('MAP_PUMP_JSON_RESPONSE') && MAP_PUMP_JSON_RESPONSE) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Local database connection failed. Check php/localconn.php settings.',
        ]);
        exit;
    }

    die('Local database connection failed. Check php/localconn.php settings.');
}
