<?php
// absen_admin_list.php (UPDATED: Include new fields informasi and dokumen in response, and JOIN with users)
include "config.php";
$q = $conn->query("SELECT absensi.*, users.nama_lengkap
                   FROM absensi
                   JOIN users ON users.id = absensi.user_id
                   ORDER BY absensi.id DESC");
$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;
echo json_encode(["status" => true, "data" => $data]);
?>