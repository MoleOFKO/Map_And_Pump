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
    // PHP 8.5 moved the MySQL PDO constants from PDO::MYSQL_ATTR_* to
    // Pdo\Mysql::*. Resolve the modern names without evaluating the
    // deprecated constants on PHP 8.5+, while retaining older-PHP support.
    $sslVerifyAttribute = defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
        ? constant('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
        : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
    $sslCaAttribute = defined('Pdo\\Mysql::ATTR_SSL_CA')
        ? constant('Pdo\\Mysql::ATTR_SSL_CA')
        : PDO::MYSQL_ATTR_SSL_CA;

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
        $sslVerifyAttribute => true,
        $sslCaAttribute => $certPath,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);

    // The admin registry uses mysqli prepared statements, while the older
    // customer/auth pages use the PDO handle above. Keep both handles backed
    // by the same map_and_pump SSL connection so loading the shared file does
    // not leave the admin pages without their expected $conn variable.
    if (!function_exists('mysqli_init')) {
        error_log('MySQLi extension is unavailable; PDO remains available.');
    } else {
        $conn = mysqli_init();
        if ($conn === false) {
            error_log('MySQLi initialization failed; PDO remains available.');
        } else {
            $conn->ssl_set(null, null, $certPath, null, null);
            if (!@$conn->real_connect($host, $username, $password, $dbname, $port, null, MYSQLI_CLIENT_SSL)) {
                error_log('MySQLi connection failed; PDO remains available: ' . $conn->connect_error);
            }
        }
    }
} catch (PDOException $e) {
    if (defined('MAP_PUMP_JSON_RESPONSE') && MAP_PUMP_JSON_RESPONSE) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'success' => false,
            'message' => 'Database connection failed. Please try again later.',
        ]);
        exit;
    }

    die("Database connection failed: " . $e->getMessage());
}

?>

