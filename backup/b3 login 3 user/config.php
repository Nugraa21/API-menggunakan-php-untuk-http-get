<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "database_smk_1";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
