<?php
$host = "localhost"; 
$user = "root";      
$pass = "";          
$db   = "skaduta_presensi";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die(json_encode([
        "status" => false,
        "message" => "Gagal konek database"
    ]));
}
?>
