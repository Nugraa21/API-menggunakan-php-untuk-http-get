<?php
// presensi_add.php - ENCRYPTED VERSION (JSON + Base64 Photo)
include 'config.php';
require_once "encryption.php";

header('Content-Type: application/json');

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$data = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    if ($decrypted === false) {
        $res = ["success" => false, "message" => "Gagal dekripsi data"];
        echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($res))]);
        exit;
    }
    $data = json_decode($decrypted, true);
} else {
    // Fallback if client sends raw parameters (unlikely if 'semuanya dienkripsi' is checked)
    $data = array_merge($_POST, $input_json ?? []);
}
// ---------------------

$user_id = $data['user_id'] ?? '';
$status = $data['status'] ?? '';
$latitude = $data['latitude'] ?? 0;
$longitude = $data['longitude'] ?? 0;
$keterangan = $data['keterangan'] ?? '';
$foto_base64 = $data['foto'] ?? ''; // Expect Base64 string for photo

$response = [];

// Lokasi SMKN 2 Yogyakarta
$school_lat = -7.791415;
$school_lng = 110.374817;

// Hitung jarak (Haversine)
function distance($lat1, $lon1, $lat2, $lon2)
{
    if ($lat1 == 0 || $lon1 == 0)
        return 999999;
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

$jarak = distance($latitude, $longitude, $school_lat, $school_lng);

if ($jarak > 150) {
    $response = [
        "success" => false,
        "message" => "Kamu berada di luar area sekolah! (Jarak: " . round($jarak) . "m)"
    ];
} else {
    // Upload Foto via Base64
    $foto_name = "";
    if (!empty($foto_base64)) {
        $foto_name = time() . "_absen.jpg";
        $upload_dir = "uploads/absen/";
        if (!is_dir($upload_dir))
            mkdir($upload_dir, 0777, true);

        $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $foto_base64));
        if ($decoded !== false) {
            file_put_contents($upload_dir . $foto_name, $decoded);
        }
    }

    $tanggal = date("Y-m-d");
    $jam = date("H:i:s");

    // Insert Presensi
    $sql = "INSERT INTO presensi (user_id, tanggal, jam, status, latitude, longitude, keterangan, foto)
            VALUES ('$user_id', '$tanggal', '$jam', '$status', '$latitude', '$longitude', '$keterangan', '$foto_name')";

    if ($conn->query($sql)) {
        $response = [
            "success" => true,
            "message" => "Presensi berhasil, menunggu persetujuan admin."
        ];
    } else {
        $response = [
            "success" => false,
            "message" => "Gagal menyimpan presensi: " . $conn->error
        ];
    }
}

// --- ENCRYPT OUTPUT ---
echo json_encode([
    "encrypted_data" => Encryption::encrypt(json_encode($response))
]);
?>