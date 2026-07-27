<?php
// Database connection file
$hostname = "mysql-19032a73-bhonen94-b057.a.aivencloud.com";
$port = 27909;
$username = "avnadmin";
$password = "AVNS_D_5WaEsuc_hdxjtXj1J";
$database = "defaultdb"; // Change this to your database name if different

// Create connection
$conn = new mysqli($hostname, $username, $password, $database, $port);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "success";
// Set charset to utf8
$conn->set_charset("utf8");

?>
