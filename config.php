<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$host = "localhost";
$user = "root";
$pass = "081328nugra";
$db   = "database_smk_4";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Gagal koneksi database"]);
    exit;
}
?>