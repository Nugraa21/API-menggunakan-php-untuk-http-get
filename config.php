<!-- config.php -->

<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$host = "localhost";
$user = "root";
$pass = "";
$db   = "database_smk_2";
// $db   = "database_smk_3";
// $db   = "skaduta_presensi";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    echo json_encode([
        "status" => "error",
        "message" => "Gagal koneksi database"
    ]);
    exit;
}
?>

