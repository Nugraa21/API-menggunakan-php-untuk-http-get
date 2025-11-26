
<?php
// <!-- presensi_add.php -->
// include 'config.php';

// $user_id      = $_POST['user_id'];
// $status       = $_POST['status']; // MASUK / PULANG / IZIN / PULANG_CEPAT
// $latitude     = $_POST['latitude'];
// $longitude    = $_POST['longitude'];
// $keterangan   = $_POST['keterangan'];

// // Lokasi SMKN 2 Yogyakarta
// $school_lat = -7.791415;
// $school_lng = 110.374817;

// // Hitung jarak (Haversine)
// function distance($lat1, $lon1, $lat2, $lon2) {
//     $earthRadius = 6371000; // meter
//     $dLat = deg2rad($lat2 - $lat1);
//     $dLon = deg2rad($lon2 - $lon1);
//     $a = sin($dLat/2) * sin($dLat/2) +
//         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
//         sin($dLon/2) * sin($dLon/2);
//     $c = 2 * atan2(sqrt($a), sqrt(1-$a));
//     return $earthRadius * $c;
// }

// $jarak = distance($latitude, $longitude, $school_lat, $school_lng);

// if ($jarak > 150) { 
//     echo json_encode([
//         "success" => false,
//         "message" => "Kamu berada di luar area sekolah!"
//     ]);
//     exit();
// }

// // Upload Foto
// $foto_name = "";
// if (!empty($_FILES['foto']['name'])) {
//     $foto_name = time() . "_" . $_FILES['foto']['name'];
//     move_uploaded_file($_FILES['foto']['tmp_name'], "uploads/absen/" . $foto_name);
// }

// $tanggal = date("Y-m-d");
// $jam = date("H:i:s");

// // Insert Presensi
// $sql = "INSERT INTO presensi (user_id, tanggal, jam, status, latitude, longitude, keterangan, foto)
//         VALUES ('$user_id', '$tanggal', '$jam', '$status', '$latitude', '$longitude', '$keterangan', '$foto_name')";

// if ($conn->query($sql)) {
//     echo json_encode([
//         "success" => true,
//         "message" => "Presensi berhasil, menunggu persetujuan admin."
//     ]);
// } else {
//     echo json_encode([
//         "success" => false,
//         "message" => "Gagal menyimpan presensi"
//     ]);
// }

?>
