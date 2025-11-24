<?php
include "config.php";

$id = $_POST["id"] ?? '';
$nama = $_POST["nama_lengkap"] ?? '';
$username = $_POST["username"] ?? '';

if ($id == "" || $nama == "" || $username == "") {
    echo json_encode(["status" => "error"]);
    exit;
}

$update = mysqli_query($conn, "
    UPDATE users SET 
    username='$username',
    nama_lengkap='$nama'
    WHERE id='$id'
");

if ($update) {
    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "error"]);
}
?>
