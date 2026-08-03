<?php
/**
 * Shared database connection.
 *
 * Environment variables can override the checked-in development defaults on
 * deployment.  Callers decide how to present a connection failure so that a
 * database outage does not corrupt a page with an unsolicited "success".
 */
$hostname = getenv('DB_HOST') ?: "mysql-19032a73-bhonen94-b057.a.aivencloud.com";
$port = (int) (getenv('DB_PORT') ?: 27909);
$username = getenv('DB_USERNAME') ?: "avnadmin";
$password = getenv('DB_PASSWORD') ?: "AVNS_D_5WaEsuc_hdxjtXj1J";
$database = getenv('DB_DATABASE') ?: "defaultdb";

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($hostname, $username, $password, $database, $port);

if (!$conn->connect_error) {
    $conn->set_charset("utf8mb4");
}
?>
