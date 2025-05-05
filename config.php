<?php
// Database Connection
$host = "localhost";
$user = "root";
$password = "pass";
$dbname = "name";
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Base URL (e.g., http://yourdomain.com)
define('BASE_URL', 'http://' . $_SERVER['HTTP_HOST']);

// Enable QR Code Generation (Requires GD Library)
define('QR_CODE_ENABLED', true);
?>
