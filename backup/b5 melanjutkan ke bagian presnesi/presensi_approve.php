<?php
include 'config.php';

$id = $_POST['id'];
$status = $_POST['status']; // APPROVED / REJECTED

$sql = "UPDATE presensi SET approve_status='$status' WHERE id='$id'";

if ($conn->query($sql)) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false]);
}
?>

