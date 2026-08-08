<?php
/**
 * Shared database connection.
 *
 * Environment variables can override the checked-in development defaults on
 * deployment. Callers decide how to present a connection failure so that a
 * database outage does not corrupt a page with an unsolicited success.
 */
// 
$host = 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com'; // e.g., gateway01.us-west-2.prod.aws.tidbcloud.com
$dbname = 'map_and_pump';
$username = '3sdV1BWxtCzY7YS.root';
$password = 'GNfDltJeN7paYMLq';
$port = 4000; // Usually 4000 for TiDB, or 3306 for standard MySQL secure ports

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $certPath = __DIR__ . '/isrgrootx1.pem';
    if (!file_exists($certPath)) {
        $certPath = dirname(__DIR__) . '/isrgrootx1.pem';
    }

    // Required SSL options for cloud databases requiring secure transport.
    // The CA should resolve from the PHP source directory, not from whichever shell
    // directory the web server or CLI process started from.
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        PDO::MYSQL_ATTR_SSL_CA => $certPath,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    if (defined('MAP_PUMP_JSON_RESPONSE') && MAP_PUMP_JSON_RESPONSE) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'message' => 'Database connection failed. Please try again later.',
        ]);
        exit;
    }

    die("Database connection failed: " . $e->getMessage());
}

?>
