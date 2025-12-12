[code program](../../absen_admin_list.php)
```php
<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$q = $conn->query("SELECT absensi.*, users.nama_lengkap
                   FROM absensi
                   JOIN users ON users.id = absensi.user_id
                   ORDER BY absensi.id DESC");

$data = [];
while ($r = $q->fetch_assoc()) {
    $data[] = $r;
}

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

[code program](../../absen_approve.php)
```php
<?php
// absen_approve.php 
include "config.php";
$id = $_POST['id'];
$status = $_POST['status']; // Disetujui / Ditolak
$q = $conn->query("UPDATE absensi SET status='$status' WHERE id='$id'");
echo json_encode(["status" => true, "message" => "Status diperbarui"]);
?>
```

[code program](../../absen_history.php)
```php
<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? '';
if (empty($user_id)) {
    echo json_encode(["status" => false, "message" => "user_id required"]);
    exit;
}

$q = $conn->query("SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC");
$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

[code program](../../absen.php)
```php
<?php
// absen.php

include "config.php";

// ================================
// KOORDINAT SEKOLAH & RADIUS (WAJIB SAMA DENGAN FLUTTER!)
$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 200; // meter (sama persis dengan Flutter)

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// Log input untuk debug
$raw = file_get_contents('php://input');
error_log("RAW INPUT: " . $raw);

$input = json_decode($raw, true);
if (!$input || json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}

$userId     = $input['userId'] ?? $input['user_id'] ?? '';
$jenis      = $input['jenis'] ?? '';
$keterangan = trim($input['keterangan'] ?? '');
$informasi  = trim($input['informasi'] ?? '');
$dokumen64  = $input['dokumenBase64'] ?? '';
$lat        = floatval($input['latitude'] ?? 0);
$lng        = floatval($input['longitude'] ?? 0);
$selfie64   = $input['base64Image'] ?? '';

// Validasi wajib
if (empty($userId) || empty($jenis)) {
    echo json_encode(["status" => false, "message" => "User ID atau jenis presensi kosong!"]);
    exit;
}

// Validasi khusus
if (in_array($jenis, ['Izin', 'Pulang Cepat']) && empty($keterangan)) {
    echo json_encode(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
    exit;
}

if (strpos($jenis, 'Penugasan') === 0) { // starts with 'Penugasan'
    if (empty($informasi)) {
        echo json_encode(["status" => false, "message" => "Informasi penugasan wajib diisi!"]);
        exit;
    }
    if (empty($dokumen64)) {
        echo json_encode(["status" => false, "message" => "Dokumen penugasan wajib diunggah!"]);
        exit;
    }
}

// Cek jarak HANYA untuk Masuk & Pulang
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        echo json_encode(["status" => false, "message" => "Lokasi tidak terdeteksi! Nyalakan GPS."]);
        exit;
    }
    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
    if ($jarak > $max_distance) {
        echo json_encode(["status" => false, "message" => "Di luar radius sekolah! Jarak: " . round($jarak, 1) . "m"]);
        exit;
    }
}

// Cek absen ganda (hanya Masuk & Pulang)
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    $today = date('Y-m-d');
    $check = $conn->query("SELECT jenis FROM absensi WHERE user_id = '$userId' AND DATE(created_at) = '$today' AND jenis IN ('Masuk', 'Pulang')");
    $absen_hari_ini = [];
    while ($row = $check->fetch_assoc()) {
        $absen_hari_ini[] = $row['jenis'];
    }
    if ($jenis == 'Masuk' && in_array('Masuk', $absen_hari_ini)) {
        echo json_encode(["status" => false, "message" => "Kamu sudah absen Masuk hari ini!"]);
        exit;
    }
    if ($jenis == 'Pulang' && in_array('Pulang', $absen_hari_ini)) {
        echo json_encode(["status" => false, "message" => "Kamu sudah absen Pulang hari ini!"]);
        exit;
    }
}

// Tentukan status otomatis
$status = (in_array($jenis, ['Masuk', 'Pulang'])) ? 'Disetujui' : 'Pending';

// Upload Selfie (opsional)
$selfie_name = '';
if (!empty($selfie64)) {
    $selfie_dir = "selfie/";
    if (!is_dir($selfie_dir)) mkdir($selfie_dir, 0777, true);
    $selfie_name = "selfie_" . $userId . "_" . time() . ".jpg";
    $selfie_path = $selfie_dir . $selfie_name;
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $selfie64));
    if ($decoded && file_put_contents($selfie_path, $decoded)) {
        error_log("Selfie berhasil disimpan: $selfie_path");
    } else {
        $selfie_name = '';
        error_log("Gagal upload selfie");
    }
}

// Upload Dokumen (wajib untuk Penugasan)
$dokumen_name = '';
if (!empty($dokumen64)) {
    $dokumen_dir = "dokumen/";
    if (!is_dir($dokumen_dir)) mkdir($dokumen_dir, 0777, true);
    $ext = strpos($dokumen64, 'data:image') === 0 ? 'jpg' : 'pdf';
    $dokumen_name = "dokumen_" . $userId . "_" . time() . "." . $ext;
    $dokumen_path = $dokumen_dir . $dokumen_name;
    $decoded = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64));
    if ($decoded && file_put_contents($dokumen_path, $decoded)) {
        error_log("Dokumen berhasil disimpan: $dokumen_path");
    } else {
        $dokumen_name = '';
        echo json_encode(["status" => false, "message" => "Gagal upload dokumen!"]);
        exit;
    }
}

// Simpan ke database
$sql = "INSERT INTO absensi 
        (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status, created_at) 
        VALUES 
        ('$userId', '$jenis', '$keterangan', '$informasi', '$dokumen_name', '$selfie_name', '$lat', '$lng', '$status', NOW())";

if ($conn->query($sql) === TRUE) {
    $id = $conn->insert_id;
    echo json_encode([
        "status" => true,
        "message" => "Presensi $jenis berhasil dikirim!",
        "data" => [
            "id" => $id,
            "jenis" => $jenis,
            "status" => $status,
            "jarak" => $jenis == 'Masuk' || $jenis == 'Pulang' ? round(calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng), 1) . "m" : null
        ]
    ]);
} else {
    // Hapus file jika gagal insert
    if ($selfie_name && file_exists("selfie/$selfie_name")) unlink("selfie/$selfie_name");
    if ($dokumen_name && file_exists("dokumen/$dokumen_name")) unlink("dokumen/$dokumen_name");
    
    echo json_encode(["status" => false, "message" => "Gagal simpan data: " . $conn->error]);
}

$conn->close();
?>
```

[code program](../../config.php)
```php
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$host = "localhost";
$user = "root";
$pass = "081328nugra";
$db   = "database_smk_4";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Gagal koneksi database"]);
    exit;
}
?>
```

[code program](../../delete_user.php)
```php
<?php
// delete_user.php (NO CHANGES)
include "config.php";
header('Content-Type: application/json');
ini_set('display_errors', 0);
$id = $_POST["id"] ?? '';
if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "ID kosong"]);
    exit;
}
$cek = mysqli_query($conn, "SELECT role FROM users WHERE id='$id'");
if (mysqli_num_rows($cek) == 0) {
    echo json_encode(["status" => "error", "message" => "User tidak ditemukan"]);
    exit;
}
$user = mysqli_fetch_assoc($cek);
// Superadmin tidak bisa hapus dirinya sendiri
if ($user["role"] == "superadmin") {
    echo json_encode(["status" => "error", "message" => "Tidak boleh hapus superadmin"]);
    exit;
}
$del = mysqli_query($conn, "DELETE FROM users WHERE id='$id'");
if ($del) {
    echo json_encode(["status" => "success", "message" => "User dihapus berhasil"]);
} else {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
}
?>
```

[text](../../encryption.php)
```php
<?php
// encryption.php — VERSI 1000% JALAN!
class Encryption {
    private static $key = "SkadutaPresensi2025SecureKey1234"; // 32 karakter

    public static function encrypt($data) {
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', self::$key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt($data) {
        $data = base64_decode($data);
        if ($data === false) return false;
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', self::$key, OPENSSL_RAW_DATA, $iv);
    }
}
?>
```
[text](../../get_users.php)
```php
<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$sql = "SELECT id, username, nama_lengkap, nip_nisn, role FROM users ORDER BY id DESC";
$run = mysqli_query($conn, $sql);
$data = [];
while ($row = mysqli_fetch_assoc($run)) {
    $data[] = $row;
}

$response = ["status" => "success", "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

[text](../../presensi_add.php)
```php
<?php
// <!-- presensi_add.php -->
include 'config.php';

$user_id      = $_POST['user_id'];
$status       = $_POST['status']; // MASUK / PULANG / IZIN / PULANG_CEPAT
$latitude     = $_POST['latitude'];
$longitude    = $_POST['longitude'];
$keterangan   = $_POST['keterangan'];

// Lokasi SMKN 2 Yogyakarta
$school_lat = -7.791415;
$school_lng = 110.374817;

// Hitung jarak (Haversine)
function distance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c;
}

$jarak = distance($latitude, $longitude, $school_lat, $school_lng);

if ($jarak > 150) { 
    echo json_encode([
        "success" => false,
        "message" => "Kamu berada di luar area sekolah!"
    ]);
    exit();
}

// Upload Foto
$foto_name = "";
if (!empty($_FILES['foto']['name'])) {
    $foto_name = time() . "_" . $_FILES['foto']['name'];
    move_uploaded_file($_FILES['foto']['tmp_name'], "uploads/absen/" . $foto_name);
}

$tanggal = date("Y-m-d");
$jam = date("H:i:s");

// Insert Presensi
$sql = "INSERT INTO presensi (user_id, tanggal, jam, status, latitude, longitude, keterangan, foto)
        VALUES ('$user_id', '$tanggal', '$jam', '$status', '$latitude', '$longitude', '$keterangan', '$foto_name')";

if ($conn->query($sql)) {
    echo json_encode([
        "success" => true,
        "message" => "Presensi berhasil, menunggu persetujuan admin."
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Gagal menyimpan presensi"
    ]);
}

?>
```

[text](../../presensi_approve.php)
```php
<?php
// presensi_approve.php 
include 'config.php';
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);
$id = trim($_POST['id'] ?? '');
$status = trim($_POST['status'] ?? ''); // Disetujui / Ditolak
if (empty($id) || empty($status)) {
    echo json_encode(["status" => false, "message" => "ID atau status kosong"]);
    exit;
}
// Cek ID ada atau tidak
$check = mysqli_query($conn, "SELECT id FROM absensi WHERE id = '$id'");
if (!$check) {
    echo json_encode(["status" => false, "message" => "Query check gagal: " . mysqli_error($conn)]);
    exit;
}
if (mysqli_num_rows($check) == 0) {
    echo json_encode(["status" => false, "message" => "ID '$id' tidak ditemukan"]);
    exit;
}
// Update sesuai kolom yang ADA di tabel
$sql = "UPDATE absensi SET status = '$status' WHERE id = '$id'";
if ($conn->query($sql)) {
    echo json_encode(["status" => true, "message" => "Status berhasil diupdate ke '$status'"]);
} else {
    echo json_encode(["status" => false, "message" => "Query update gagal: " . mysqli_error($conn)]);
}
?>
```

[text](../../presensi_pending.php)
```php
<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$sql = "SELECT p.*, u.nama_lengkap
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        WHERE p.status='Pending'";

$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>

```

[text](../../presensi_rekap.php)
```php
<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC";

$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

[text](../../presensi_user_history.php)
```php
<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? '';
$sql = "SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC";
$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

[text](../../register.php)
```php
<?php
// register.php (UPDATED: NIP/NISN now required for 'guru' but optional for 'karyawan' - but since role is 'user', assume checkbox handles it; no schema change needed)
include "config.php";
$username = $_POST["username"] ?? '';
$nama = $_POST["nama_lengkap"] ?? '';
$nip_nisn = $_POST["nip_nisn"] ?? '';
$password_raw = $_POST["password"] ?? '';
$role = $_POST["role"] ?? 'user';
$is_karyawan = $_POST["is_karyawan"] ?? false; // NEW: From Flutter checkbox
if ($username == "" || $nama == "" || $password_raw == "") {
    echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
    exit;
}
// NEW: Validate NIP/NISN - required if not karyawan
if (!$is_karyawan && empty($nip_nisn)) {
    echo json_encode(["status" => "error", "message" => "NIP/NISN wajib untuk guru!"]);
    exit;
}
$password = password_hash($password_raw, PASSWORD_DEFAULT);
// Cek admin hanya boleh 1
if ($role == "admin") {
    $cek = mysqli_query($conn, "SELECT id FROM users WHERE role='admin'");
    if (mysqli_num_rows($cek) > 0) {
        echo json_encode(["status" => "error", "message" => "Admin sudah ada"]);
        exit;
    }
}
// Cek superadmin hanya boleh 1
if ($role == "superadmin") {
    $cek = mysqli_query($conn, "SELECT id FROM users WHERE role='superadmin'");
    if (mysqli_num_rows($cek) > 0) {
        echo json_encode(["status" => "error", "message" => "Superadmin sudah ada"]);
        exit;
    }
}
$sql = "INSERT INTO users (username, nama_lengkap, nip_nisn, password, role)
        VALUES ('$username', '$nama', '$nip_nisn', '$password', '$role')";
if (mysqli_query($conn, $sql)) {
    echo json_encode(["status" => "success", "message" => "Akun berhasil dibuat"]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Gagal daftar: " . mysqli_error($conn)
    ]);
}
?>
```
[text](../../update_password.php)
```php
<?php
// <!-- update_user.php -->
include "config.php";

header('Content-Type: application/json');
ini_set('display_errors', 0);

$id = $_POST["id"] ?? '';
$password = $_POST["password"] ?? '';

if (empty($id) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "ID atau password kosong"]);
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);
$update = mysqli_query($conn, "UPDATE users SET password='$hashed' WHERE id='$id'");

if ($update) {
    echo json_encode(["status" => "success", "message" => "Password diperbarui"]);
} else {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
}
?>
```

[text](../../update_user.php)
```php
<?php
// update_user.php (NO CHANGES)
include "config.php";
header('Content-Type: application/json');
ini_set('display_errors', 0);
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
```


```md
# Aku ada code PHP untuk API dan ingin kamu tambahkan fitur keamanan berikut:

## 1. Delay Request (sleep/usleep)
- Tambahkan delay 0.3–1 detik sebelum memproses request.
- Tujuan: memperlambat brute-force, mengurangi spam request.

## 2. Validasi Input Ketat
- Validasi username (karakter aman), email valid, panjang input minimum.
- Tujuan: mencegah input sampah dari bot dan mengurangi trafik tidak penting.

## 3. Anti SQL Injection
- Semua query wajib menggunakan prepared statement.
- Tujuan: mencegah manipulasi query database.

## 4. API Secret Key / Token (WAJIB)
- Flutter mengirim header: X-App-Key
- Server memverifikasi key tersebut sebelum memproses request.
- Tujuan: hanya aplikasi resmi yang bisa akses API.

## 5. Server Timeout Control
- Batasi waktu eksekusi script PHP.
- Tujuan: mencegah server freeze atau beban tinggi.

## 6. Persistent Login / Auto Login (Flutter)
- Tambahkan sistem token login agar pengguna tetap login walau aplikasi ditutup.
- Server mengirim token login.
- Flutter menyimpannya di shared_preferences.
- Tidak perlu login ulang selama token masih valid.

---

### Catatan:
- Karena aplikasinya **mobile**, fitur “CORS Safe Policy” tidak perlu ditambahkan.
- Jika ada fitur yang butuh kode Flutter/Dart, beri tahu saya, nanti saya minta file Flutter yang diperlukan.


Ini ada penambahan untuk keamananya kamu bisa langsung ketikan semua code yang ada perubahan jadi code yang ada perubahan setiap codenya ketikan semuanya kalau code yang tidak ada perubahan tidak usah di ketikan kalau code yang membutuhkan flutter /  dart kasih tau aku akan kirim code flutternya 

```