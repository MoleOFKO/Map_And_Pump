<?php
/**
 * Shared database connection.
 *
 * Database credentials are supplied through environment variables. For local
 * development, values from the ignored .env.local file are loaded when a
 * matching system environment variable is not already set.
 */

if (!function_exists('map_pump_load_local_env')) {
    function map_pump_load_local_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $envPath = dirname(__DIR__) . '/.env.local';
        if (!is_file($envPath)) {
            return;
        }

        $values = parse_ini_file($envPath, false, INI_SCANNER_RAW);
        if (!is_array($values)) {
            error_log('Unable to parse .env.local.');
            return;
        }

        foreach ($values as $key => $value) {
            if (getenv($key) !== false) {
                continue;
            }
            $value = (string) $value;
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

if (!function_exists('map_pump_env')) {
    function map_pump_env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $_ENV[$key] ?? $default;
    }
}

$host = "gateway01.ap-southeast-1.prod.aws.tidbcloud.com";
$dbname = "map_and_pump";
$username = "3sdV1BWxtCzY7YS.root";
$password = "GNfDltJeN7paYMLq";
$port = 4000;

// map_pump_load_local_env();

// $host = map_pump_env('DB_HOST');
// $dbname = map_pump_env('DB_NAME');
// $username = map_pump_env('DB_USERNAME');
// $password = map_pump_env('DB_PASSWORD');
// $port = (int) (map_pump_env('DB_PORT', '4000'));

try {
    if (!$host || !$dbname || !$username || !$password || $port <= 0) {
        throw new RuntimeException('Database configuration is incomplete.');
    }

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
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    if (defined('MAP_PUMP_JSON_RESPONSE') && MAP_PUMP_JSON_RESPONSE) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'success' => false,
            'message' => 'Database connection failed. Please try again later.',
        ]);
        exit;
    }

    http_response_code(503);
    exit('Database connection is temporarily unavailable. Please try again later.');
}

?>

