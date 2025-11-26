ini code php aku untuk backend 

```php
<!-- absen_admin_list.php -->

<?php
include "config.php";

$q = $conn->query("SELECT absensi.*, users.nama_lengkap 
                   FROM absensi 
                   JOIN users ON users.id = absensi.user_id
                   ORDER BY absensi.id DESC");

$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;

echo json_encode(["status" => true, "data" => $data]);

```

```php
<!-- absen_approve.php -->

<?php
include "config.php";

$id = $_POST['id'];
$status = $_POST['status']; // Disetujui / Ditolak

$q = $conn->query("UPDATE absensi SET status='$status' WHERE id='$id'");

echo json_encode(["status" => true, "message" => "Status diperbarui"]);

```

```php
<!-- absen_history.php -->

<?php
include "config.php";

$user_id = $_GET['user_id'];

$q = $conn->query("SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC");

$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;

echo json_encode(["status" => true, "data" => $data]);

```

```php
<!-- absen.php -->

<?php
include "config.php";

// ================================
// KOORDINAT SEKOLAH (SINKRON SAMA FLUTTER)
$sekolah_lat = -7.777047019078815;
$sekolah_lng = 110.3671540164373;
$max_distance = 100; // Meter, sinkron sama Flutter

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * asin(sqrt($a));
    return $earth_radius * $c;
}

// DEBUG: Log raw input (JSON atau POST)
$rawInput = file_get_contents('php://input');
error_log("DEBUG: Raw input length: " . strlen($rawInput));
error_log("DEBUG: Raw input preview: " . substr($rawInput, 0, 500));

// Fallback: Coba JSON dulu, kalau gagal pake $_POST
$input = json_decode($rawInput, true);
if (!$input || json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}
error_log("DEBUG: Input data: " . json_encode($input));

// Ambil data (support kedua key: lama & baru)
$user_id = $input['userId'] ?? $input['user_id'] ?? '';
$jenis = $input['jenis'] ?? '';
$keterangan = trim($input['keterangan'] ?? '');
$lat = floatval($input['latitude'] ?? $input['lat'] ?? 0);
$lng = floatval($input['longitude'] ?? $input['lng'] ?? 0);
$base64Image = $input['base64Image'] ?? $input['image'] ?? '';

// DEBUG: Log extracted
error_log("DEBUG: user_id=$user_id, jenis=$jenis, lat=$lat, lng=$lng, image_length=" . strlen($base64Image));

// VALIDASI: Cek wajib (skip image buat test)
if (empty($user_id) || empty($jenis)) {
    echo json_encode(["status" => false, "message" => "Data tidak lengkap! userId/jenis kosong."]);
    exit;
}

// Validasi keterangan hanya untuk Izin & Pulang Cepat
if (($jenis == 'Izin' || $jenis == 'Pulang Cepat') && empty($keterangan)) {
    echo json_encode(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
    exit;
}

$distance = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
error_log("DEBUG: Distance: " . round($distance) . "m");

// CEK RADIUS (sinkron sama Flutter)
if ($distance > $max_distance || $distance == 0) {
    echo json_encode(["status" => false, "message" => "Di luar jangkauan sekolah! Jarak: " . round($distance) . "m"]);
    exit;
}

// CEK 2x ABSEN / HARI (hanya Masuk/Pulang)
$date = date("Y-m-d");
if ($jenis == 'Masuk' || $jenis == 'Pulang') {
    $check = $conn->query("SELECT COUNT(*) AS jml FROM absensi 
                           WHERE user_id='$user_id' AND DATE(created_at)='$date' 
                           AND jenis IN ('Masuk', 'Pulang')");
    $row = $check->fetch_assoc();
    if ($row['jml'] >= 2) {
        echo json_encode(["status" => false, "message" => "Sudah absen Masuk & Pulang hari ini!"]);
        exit;
    }
}

// UPLOAD FOTO (skip kalau kosong buat test, pake dummy)
$target_dir = "selfie/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$image_name = "selfie_" . $user_id . "_" . time() . ".jpg";
$image_path = $target_dir . $image_name;

if (!empty($base64Image)) {
    $decoded = base64_decode($base64Image);
    if ($decoded && file_put_contents($image_path, $decoded)) {
        error_log("DEBUG: Foto uploaded OK");
    } else {
        unlink($image_path); // Hapus kalau gagal
        $image_name = ''; // Skip foto kalau gagal
    }
} else {
    // Buat test: Dummy empty
    file_put_contents($image_path, ""); // File kosong
    error_log("DEBUG: No image, dummy created");
}

// INSERT DATA (escape basic, ganti prepared kalau bisa)
$q = $conn->query("INSERT INTO absensi 
(user_id, jenis, keterangan, selfie, latitude, longitude, created_at) 
VALUES 
('$user_id', '$jenis', '$keterangan', '$image_name', '$lat', '$lng', NOW())");

if ($q) {
    $absen_id = $conn->insert_id;
    echo json_encode([
        "status" => true, 
        "message" => "Presensi $jenis berhasil! ID: $absen_id", 
        "data" => ["id" => $absen_id, "jenis" => $jenis, "timestamp" => date('Y-m-d H:i:s')]
    ]);
} else {
    if ($image_name) unlink($image_path);
    echo json_encode(["status" => false, "message" => "Gagal simpan data: " . $conn->error]);
}
?>
```

```php
<!-- config.php -->

<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$host = "localhost";
$user = "root";
$pass = "";
$db   = "database_smk_2";
// $db   = "database_smk_3";
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
<!-- delete_user.php -->

<?php
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
<!-- get_users.php -->

<?php
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
<!-- login.php -->

<?php
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
<!-- presensi_approve.php -->

<?php
include 'config.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$id = trim($_POST['id'] ?? '');
$status = trim($_POST['status'] ?? '');  // Disetujui / Ditolak

if (empty($id) || empty($status)) {
    echo json_encode(["success" => false, "message" => "ID atau status kosong"]);
    exit;
}

// Cek ID ada atau tidak
$check = mysqli_query($conn, "SELECT id FROM absensi WHERE id = '$id'");
if (!$check) {
    echo json_encode(["success" => false, "message" => "Query check gagal: " . mysqli_error($conn)]);
    exit;
}
if (mysqli_num_rows($check) == 0) {
    echo json_encode(["success" => false, "message" => "ID '$id' tidak ditemukan"]);
    exit;
}

// Update sesuai kolom yang ADA di tabel
$sql = "UPDATE absensi SET status = '$status' WHERE id = '$id'";

if ($conn->query($sql)) {
    echo json_encode(["success" => true, "message" => "Status berhasil diupdate ke '$status'"]);
} else {
    echo json_encode(["success" => false, "message" => "Query update gagal: " . mysqli_error($conn)]);
}
?>

```

```php
<!-- presensi_pending.php -->

<?php
include 'config.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);

$sql = "SELECT p.*, u.nama_lengkap 
        FROM absensi p 
        JOIN users u ON p.user_id = u.id
        WHERE p.approve_status='PENDING'";  /* Line 9: Filter pending, samain table */

$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["error" => "Query gagal: " . mysqli_error($conn)]);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    // Fix Line ~14: Ganti ?? dengan isset
    $row['status'] = isset($row['approve_status']) ? $row['approve_status'] : 'Pending';
    unset($row['approve_status']);
    $data[] = $row;
}

echo json_encode($data);
?>
```

```php
<!-- presensi_rekap.php -->

<?php
include 'config.php';

// Header JSON
header('Content-Type: application/json');

// Suppress HTML errors
ini_set('display_errors', 0);

$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p  /* Line 9: Samain table absensi */
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC";  /* Line 10: Field created_at */

$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["error" => "Query gagal: " . mysqli_error($conn)]);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    // Fix Line 13: Ganti ?? dengan isset untuk kompatibilitas PHP lama
    $row['status'] = isset($row['approve_status']) ? $row['approve_status'] : 'Pending';
    unset($row['approve_status']);  /* Line 14: Hapus field lama */
    $data[] = $row;
}

echo json_encode($data);  /* Line 16: Pure JSON */
?>
```

```php
<!-- presensi_user_history.php -->

<?php
include 'config.php';

$user_id = $_GET['user_id'];

$sql = "SELECT * FROM presensi WHERE user_id='$user_id' ORDER BY id DESC";
$result = $conn->query($sql);

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
?>

```

```php
<!-- register.php -->

<?php
include "config.php";

$username = $_POST["username"] ?? '';
$nama = $_POST["nama_lengkap"] ?? '';
$nip_nisn = $_POST["nip_nisn"] ?? '';
$password_raw = $_POST["password"] ?? '';
$role = $_POST["role"] ?? 'user';

if ($username == "" || $nama == "" || $password_raw == "") {
    echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
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
<!-- update_user.php -->

<?php
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

```

```php
```

```php
```

```php
```

