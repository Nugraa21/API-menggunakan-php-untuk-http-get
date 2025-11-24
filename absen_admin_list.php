<?php
include "config.php";

$q = $conn->query("SELECT absensi.*, users.nama_lengkap 
                   FROM absensi 
                   JOIN users ON users.id = absensi.user_id
                   ORDER BY absensi.id DESC");

$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;

echo json_encode(["status" => true, "data" => $data]);
