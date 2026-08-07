<?php
/**
 * Shared database connection.
 *
 * Environment variables can override the checked-in development defaults on
 * deployment. Callers decide how to present a connection failure so that a
 * database outage does not corrupt a page with an unsolicited success.
 */
$hostname = getenv('DB_HOST') ?: "gateway01.ap-southeast-1.prod.aws.tidbcloud.com";
$port = (int) (getenv('DB_PORT') ?: 4000);
$username = getenv('DB_USERNAME') ?: "3sdV1BWxtCzY7YS.root";
$password = getenv('DB_PASSWORD') ?: "GNfDltJeN7paYMLq";
$database = getenv('DB_DATABASE') ?: "map_and_pump";

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($hostname, $username, $password, $database, $port);
$conn_error = null;

if ($conn && $conn->connect_error) {
    $conn_error = $conn->connect_error;
} elseif ($conn) {
    $conn->set_charset("utf8mb4");
}
?>
