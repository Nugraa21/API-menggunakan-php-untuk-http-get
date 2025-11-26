<!-- update_user.php -->

<?php
include "config.php";

$id = $_POST["id"] ?? '';
$nama = $_POST["nama_lengkap"] ?? '';
$username = $_POST["username"] ?? '';
$password = $_POST["password"] ?? ''; // Optional

if (empty($id) || empty($nama) || empty($username)) {
    echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
    exit;
}

$sql = "UPDATE users SET username='$username', nama_lengkap='$nama'";
if (!empty($password)) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $sql .= ", password='$hashed'";
}
$sql .= " WHERE id='$id'";

$update = mysqli_query($conn, $sql);

if ($update) {
    echo json_encode(["status" => "success", "message" => "User diperbarui"]);
} else {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
}
?>