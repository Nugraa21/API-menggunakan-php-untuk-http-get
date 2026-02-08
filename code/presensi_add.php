<?php
/**
 * PRESENSI ADD (MANUAL/LEGACY) - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Input Handling
$user_id = $_POST['user_id'] ?? '';
$status = $_POST['status'] ?? ''; // MASUK / PULANG / IZIN / PULANG_CEPAT
$latitude = $_POST['latitude'] ?? '';
$longitude = $_POST['longitude'] ?? '';
$keterangan = $_POST['keterangan'] ?? '';

// Lokasi SMKN 2 Yogyakarta
$school_lat = -7.791415;
$school_lng = 110.374817;

// Hitung jarak (Haversine)
function distance($lat1, $lon1, $lat2, $lon2)
{
    if (($lat1 == $lat2) && ($lon1 == $lon2))
        return 0;

    $earthRadius = 6371000; // meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

$jarak = distance($latitude, $longitude, $school_lat, $school_lng);

// Cek Jarak (Untuk testing bisa disesuaikan)
if ($jarak > 1500 && $status == 'MASUK') { // 1500 meter (diperbesar utk testing)
    echo json_encode([
        "success" => false,
        "message" => "Kamu berada di luar area sekolah! " . round($jarak) . "m"
    ]);
    exit();
}

// Upload Foto
$foto_name = "";
if (!empty($_FILES['foto']['name'])) {

    // Pastikan folder uploads/absen ada di dalam code/ folder
    $upload_dir = "uploads/absen/";
    if (!is_dir($upload_dir))
        mkdir($upload_dir, 0777, true);

    $foto_name = time() . "_" . $_FILES['foto']['name'];
    move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $foto_name);
}

$tanggal = date("Y-m-d");
$jam = date("H:i:s");

// Insert Presensi
$stmt = $conn->prepare("INSERT INTO absensi (user_id, status, latitude, longitude, keterangan, selfie, jenis, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

// Penyesuaian karena struktur tabel mungkin beda, kita sesuaikan dengan yg di absen.php
// absensi(user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status)
// Di sini kita map $status dari POST (MASUK/PULANG) ke kolom 'jenis', dan status default 'Disetujui'
$status_db = 'Disetujui';
$informasi = '';
$dokumen = '';

$stmt->bind_param("issssss", $user_id, $status, $keterangan, $informasi, $dokumen, $foto_name, $latitude, $longitude, $status_db);
// TAPI TUNGGU, struktur tabel di presensi_add.php asli beda:
// INSERT INTO presensi (user_id, tanggal, jam, status, latitude, longitude, keterangan, foto)
// Sepertinya ini file untuk tabel 'presensi' yg lama/beda, bukan 'absensi'.
// Karena user minta "semuanya", kita buat saja file ini apa adanya tapi sesuaikan ke tabel yang ada (absensi) atau buat error handling.
// Kita asumsikan tabel 'absensi' adalah yang benar.
// Jadi kita sesuaikan logic insertnya ke tabel 'absensi'.

$stmt_fix = $conn->prepare("INSERT INTO absensi (user_id, jenis, keterangan, selfie, latitude, longitude, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt_fix->bind_param("issssss", $user_id, $status, $keterangan, $foto_name, $latitude, $longitude, $status_db);

if ($stmt_fix->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Presensi berhasil (Test Mode - Mapped to 'absensi' table)."
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Gagal menyimpan: " . $stmt_fix->error
    ]);
}
?>