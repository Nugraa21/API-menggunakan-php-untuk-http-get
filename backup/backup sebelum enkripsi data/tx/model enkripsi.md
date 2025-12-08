```sql
DROP TABLE IF EXISTS absensi;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(255) NOT NULL,
  nip_nisn VARCHAR(255) DEFAULT NULL,  -- Optional for karyawan (validated in PHP/Flutter), required for guru
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin','superadmin') DEFAULT 'user',
  PRIMARY KEY (id)
);

CREATE TABLE absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  jenis ENUM('Masuk','Pulang','Izin','Pulang Cepat','Penugasan_Masuk','Penugasan_Pulang','Penugasan_Full'),
  keterangan TEXT,
  informasi TEXT,  -- For Penugasan details (wajib)
  dokumen VARCHAR(255),  -- Path to uploaded dokumen (wajib for Penugasan)
  selfie VARCHAR(255),
  latitude VARCHAR(100),
  longitude VARCHAR(100),
  status ENUM('Pending','Disetujui','Ditolak') DEFAULT 'Pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);


```


```php
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
```

```php
<?php
// absen_approve.php (NO CHANGES - remains the same, used for manual approve if needed)
include "config.php";
$id = $_POST['id'];
$status = $_POST['status']; // Disetujui / Ditolak
$q = $conn->query("UPDATE absensi SET status='$status' WHERE id='$id'");
echo json_encode(["status" => true, "message" => "Status diperbarui"]);
?>
```

```php
<?php
// absen_history.php (UPDATED: Include new fields informasi and dokumen in response)
include "config.php";
$user_id = $_GET['user_id'];
$q = $conn->query("SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC");
$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;
echo json_encode(["status" => true, "data" => $data]);
?>
```

```php
<?php
// absen.php
// FINAL VERSION – 100% SESUAI DENGAN FLUTTER PRESENSI TERBARU (Izin tanpa lokasi, Penugasan wajib dokumen, status otomatis)

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

```php
<?php
// <!-- config.php -->
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$host = "localhost";
$user = "root";
$pass = "081328nugra";
// $db   = "database_smk_2";
$db   = "database_smk_4";
// $db   = "skaduta_presensi";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    echo json_encode([
        "status" => "error",
        "message" => "Gagal koneksi database"
    ]);
    exit;
}
?>


```

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

```php
<?php
// get_users.php (NO CHANGES)
include "config.php";
header('Content-Type: application/json');
ini_set('display_errors', 0);
$sql = "SELECT id, username, nama_lengkap, nip_nisn, role FROM users ORDER BY id DESC";
$run = mysqli_query($conn, $sql);
if (!$run) {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
    exit;
}
$data = [];
while ($row = mysqli_fetch_assoc($run)) {
    $data[] = $row;
}
echo json_encode([
    "status" => "success",
    "message" => "Data user berhasil dimuat",
    "data" => $data
]);
?>
```

```php
<?php
// login.php (NO CHANGES)
include "config.php";
$input = $_POST["input"] ?? '';
$password = $_POST["password"] ?? '';
if ($input == "" || $password == "") {
    echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
    exit;
}
$sql = "SELECT * FROM users
        WHERE username='$input'
        OR nip_nisn='$input'";
$run = mysqli_query($conn, $sql);
if (mysqli_num_rows($run) == 0) {
    echo json_encode(["status" => "error", "message" => "Akun tidak ditemukan"]);
    exit;
}
$user = mysqli_fetch_assoc($run);
// verifikasi password
if (!password_verify($password, $user["password"])) {
    echo json_encode(["status" => "error", "message" => "Password salah"]);
    exit;
}
echo json_encode([
    "status" => "success",
    "message" => "Login berhasil",
    "data" => [
        "id" => $user["id"],
        "username" => $user["username"],
        "nama_lengkap" => $user["nama_lengkap"],
        "nip_nisn" => $user["nip_nisn"],
        "role" => $user["role"]
    ]
]);
?>
```

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

```php
<?php
// presensi_approve.php (NO CHANGES - now used for approving Izin and Penugasan types)
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

```php
<?php
// presensi_pending.php (UPDATED: Include new fields, filter only Pending)
include 'config.php';
header('Content-Type: application/json');
ini_set('display_errors', 0);
$sql = "SELECT p.*, u.nama_lengkap
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        WHERE p.status='Pending'";
$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["status" => false, "error" => "Query gagal: " . mysqli_error($conn)]);
    exit;
}
$data = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo json_encode(["status" => true, "data" => $data]);
?>
```

```php
<?php
// presensi_rekap.php (UPDATED: Include new fields informasi and dokumen)
include 'config.php';
// Header JSON
header('Content-Type: application/json');
// Suppress HTML errors
ini_set('display_errors', 0);
$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC";
$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["status" => false, "error" => "Query gagal: " . mysqli_error($conn)]);
    exit;
}
$data = [];
while ($row = $result->fetch_assoc()) {
    // Gunakan status langsung
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo json_encode(["status" => true, "data" => $data]);
?>
```

```php
<?php
// presensi_user_history.php (DEPRECATED - use absen_history.php, NO CHANGES if kept)
include 'config.php';
$user_id = $_GET['user_id'];
$sql = "SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC";
$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo json_encode(["status" => true, "data" => $data]);
?>
```

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

Ini adalah code php untuk backend dan kamu apakah bisa tambahkan untuk code ini di encripsi dan berberapa penambahan keamanan ini kan aku baru kirim cde php kamu bisa sesuwaikan di phpnya kalau sudah baru aku kirim code flutternnya 


- AES Encryption ( Enkripsi data JSON sebelum dikirim ) key nya (nugra21)
- Delay Request (sleep/usleep) Memperlambat bot brute-force
- Error Random (Fake Error) Kadang kirim error palsu
- Validasi Input Ketat Cek panjang username, email valid, karakter aman Menghindari input sampah dari bot
- POST Only (No GET) Endpoint sensitif hanya pakai POST Cegah eksploitasi via URL	
- Anti SQL Injection Menggunakan prepared statement Mencegah query manipulasi
- Session Flood Protection Simpan request timestamp per IP 	Cegah spam cepat
- Rate Limiting Anti-spam & anti-DDOS ringan
- Device ID Limit 	Batasi login per device 

jadi untuk encripsi itu kayak end to end di server data jsonya di encripsi nah di flutternya membuka encripsinya jadi agar datanya juga bisa terbaca gitu tau lah untuk key nya (nugra21)

untuk ini kamu bisa langsung ketkan php nya kalau sudah nanti baru flutternya aku akan kirim code flutternya satu per satu nanti 