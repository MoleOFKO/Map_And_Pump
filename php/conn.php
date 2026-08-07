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
    
    // Required SSL options for cloud databases requiring secure transport
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true, // Set to true if you are supplying a specific CA bundle
        // If your cloud provider requires a specific CA certificate file, uncomment below:
         PDO::MYSQL_ATTR_SSL_CA => '../isrgrootx1.pem', // Path to the CA certificate file
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

?>
