<?php
// <!-- absen_history.php -->
include "config.php";

$user_id = $_GET['user_id'];

$q = $conn->query("SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC");

$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;

echo json_encode(["status" => true, "data" => $data]);
