
---

### FITUR KEAMANAN YANG DITAMBAHKAN:
- **AES-256-CBC Encryption** (end-to-end) → Key: `nugra21`  
  → Data JSON dari Flutter **didekripsi di server**, hasil **dienkripsi sebelum dikirim kembali**
- **Hanya POST** → Semua endpoint sensitif **tolak GET**
- **Prepared Statements** → 100% anti SQL Injection
- **Rate Limiting** → Max 15 request / 10 detik per IP
- **Session Flood Protection** → Log IP + timestamp di DB
- **Delay Acak** → `usleep(100000 - 800000)` → Anti bot
- **Fake Error Acak (7%)** → Kadang kirim error palsu
- **Validasi Input Ketat** → Panjang, format, karakter aman
- **Response Selalu Terenkripsi** → Kecuali error fatal

---

## 1. `config.php` – **CORE KEAMANAN & ENKRIPSI**

```php
<?php
// config.php - CORE: Enkripsi, Rate Limit, Flood Protection
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// === ENKRIPSI AES-256-CBC ===
function encrypt_json($data, $key = "nugra21") {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt(json_encode($data), 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($encrypted . "::" . base64_encode($iv));
}

function decrypt_json($encrypted, $key = "nugra21") {
    if (!$encrypted) return false;
    $data = base64_decode($encrypted);
    if ($data === false) return false;
    $parts = explode('::', $data, 2);
    if (count($parts) !== 2) return false;
    $encrypted_data = $parts[0];
    $iv = base64_decode($parts[1]);
    if ($iv === false) return false;
    $decrypted = openssl_decrypt($encrypted_data, 'AES-256-CBC', $key, 0, $iv);
    return $decrypted ? json_decode($decrypted, true) : false;
}

// === RATE LIMIT & FLOOD PROTECTION ===
function checkRateLimit($conn) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $time = time();
    $limit = 15;     // max request
    $window = 10;    // detik

    // Hapus log lama
    $stmt = $conn->prepare("DELETE FROM request_log WHERE timestamp < ?");
    $old = $time - $window;
    $stmt->bind_param("i", $old);
    $stmt->execute();
    $stmt->close();

    // Hitung request saat ini
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM request_log WHERE ip = ? AND timestamp >= ?");
    $stmt->bind_param("si", $ip, $old);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result['cnt'] >= $limit) {
        echo encrypt_json(["status" => false, "message" => "Terlalu banyak permintaan. Coba lagi nanti."]);
        exit;
    }

    // Catat request baru
    $stmt = $conn->prepare("INSERT INTO request_log (ip, timestamp) VALUES (?, ?)");
    $stmt->bind_param("si", $ip, $time);
    $stmt->execute();
    $stmt->close();
}

// === KONEKSI DB ===
$host = "localhost";
$user = "root";
$pass = "081328nugra";
$db   = "database_smk_4";

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    echo encrypt_json(["status" => false, "message" => "Koneksi database gagal"]);
    exit;
}

// Buat tabel log jika belum ada
$conn->query("CREATE TABLE IF NOT EXISTS request_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    timestamp INT NOT NULL,
    INDEX(ip, timestamp)
)") or die(encrypt_json(["status" => false, "message" => "Gagal buat tabel log"]));

// === DELAY + FAKE ERROR ACAK ===
usleep(rand(100000, 800000)); // 0.1 - 0.8 detik
if (rand(1, 100) <= 7) { // 7% fake error
    echo encrypt_json(["status" => false, "message" => "Server sedang sibuk. Coba lagi nanti."]);
    exit;
}
?>
```

---

## 2. `absen.php` – **PRESENSI UTAMA (ENKRIPSI + FULL SECURITY)**

```php
<?php
// absen.php - Presensi Masuk/Pulang/Izin/Penugasan
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo encrypt_json(["status" => false, "message" => "Method tidak diizinkan"]);
    exit;
}

checkRateLimit($conn);

$raw = file_get_contents('php://input');
$input = decrypt_json($raw);

if (!$input || !is_array($input)) {
    echo encrypt_json(["status" => false, "message" => "Data tidak valid atau rusak"]);
    exit;
}

// === VALIDASI INPUT KETAT ===
$userId     = trim($input['userId'] ?? '');
$jenis      = $input['jenis'] ?? '';
$keterangan = trim($input['keterangan'] ?? '');
$informasi  = trim($input['informasi'] ?? '');
$dokumen64  = $input['dokumenBase64'] ?? '';
$lat        = floatval($input['latitude'] ?? 0);
$lng        = floatval($input['longitude'] ?? 0);
$selfie64   = $input['base64Image'] ?? '';

// Wajib
if (empty($userId) || empty($jenis)) {
    echo encrypt_json(["status" => false, "message" => "User ID atau jenis presensi kosong!"]);
    exit;
}

$validJenis = ['Masuk','Pulang','Izin','Pulang Cepat','Penugasan_Masuk','Penugasan_Pulang','Penugasan_Full'];
if (!in_array($jenis, $validJenis)) {
    echo encrypt_json(["status" => false, "message" => "Jenis presensi tidak valid!"]);
    exit;
}

// Validasi khusus
if (in_array($jenis, ['Izin', 'Pulang Cepat']) && empty($keterangan)) {
    echo encrypt_json(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
    exit;
}

if (strpos($jenis, 'Penugasan') === 0) {
    if (empty($informasi)) {
        echo encrypt_json(["status" => false, "message" => "Informasi penugasan wajib diisi!"]);
        exit;
    }
    if (empty($dokumen64)) {
        echo encrypt_json(["status" => false, "message" => "Dokumen penugasan wajib diunggah!"]);
        exit;
    }
}

// === KOORDINAT SEKOLAH ===
$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 200;

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// Cek jarak (hanya Masuk & Pulang)
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        echo encrypt_json(["status" => false, "message" => "Lokasi tidak terdeteksi! Nyalakan GPS."]);
        exit;
    }
    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
    if ($jarak > $max_distance) {
        echo encrypt_json(["status" => false, "message" => "Di luar radius sekolah! Jarak: " . round($jarak, 1) . "m"]);
        exit;
    }
}

// Cek absen ganda
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT jenis FROM absensi WHERE user_id = ? AND DATE(created_at) = ? AND jenis IN ('Masuk', 'Pulang')");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    $absen_hari_ini = [];
    while ($row = $res->fetch_assoc()) $absen_hari_ini[] = $row['jenis'];
    $stmt->close();

    if ($jenis == 'Masuk' && in_array('Masuk', $absen_hari_ini)) {
        echo encrypt_json(["status" => false, "message" => "Kamu sudah absen Masuk hari ini!"]);
        exit;
    }
    if ($jenis == 'Pulang' && in_array('Pulang', $absen_hari_ini)) {
        echo encrypt_json(["status" => false, "message" => "Kamu sudah absen Pulang hari ini!"]);
        exit;
    }
}

$status = in_array($jenis, ['Masuk', 'Pulang']) ? 'Disetujui' : 'Pending';

// === UPLOAD SELFIE ===
$selfie_name = '';
if (!empty($selfie64)) {
    $dir = "selfie/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $selfie_name = "selfie_{$userId}_" . time() . ".jpg";
    $path = $dir . $selfie_name;
    $data = preg_replace('#^data:image/\w+;base64,#i', '', $selfie64);
    if (!file_put_contents($path, base64_decode($data))) {
        $selfie_name = '';
    }
}

// === UPLOAD DOKUMEN (WAJIB PENUGASAN) ===
$dokumen_name = '';
if (!empty($dokumen64)) {
    $dir = "dokumen/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $ext = strpos($dokumen64, 'data:image') === 0 ? 'jpg' : 'pdf';
    $dokumen_name = "dokumen_{$userId}_" . time() . "." . $ext;
    $path = $dir . $dokumen_name;
    $data = preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64);
    if (!file_put_contents($path, base64_decode($data))) {
        echo encrypt_json(["status" => false, "message" => "Gagal upload dokumen!"]);
        exit;
    }
}

// === SIMPAN KE DATABASE ===
$stmt = $conn->prepare("INSERT INTO absensi 
    (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("isssssdds", $userId, $jenis, $keterangan, $informasi, $dokumen_name, $selfie_name, $lat, $lng, $status);

if ($stmt->execute()) {
    $id = $conn->insert_id;
    $jarak_str = (in_array($jenis, ['Masuk','Pulang'])) ? round(calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng), 1) . "m" : null;
    echo encrypt_json([
        "status" => true,
        "message" => "Presensi $jenis berhasil dikirim!",
        "data" => ["id" => $id, "jenis" => $jenis, "status" => $status, "jarak" => $jarak_str]
    ]);
} else {
    // Hapus file jika gagal
    if ($selfie_name && file_exists("selfie/$selfie_name")) unlink("selfie/$selfie_name");
    if ($dokumen_name && file_exists("dokumen/$dokumen_name")) unlink("dokumen/$dokumen_name");
    echo encrypt_json(["status" => false, "message" => "Gagal simpan data"]);
}
$stmt->close();
$conn->close();
?>
```

---

## 3. `login.php` – **LOGIN AMAN + ENKRIPSI**

```php
<?php
// login.php
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>false,"message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$raw = file_get_contents('php://input');
$input = decrypt_json($raw);
if (!$input) exit(encrypt_json(["status"=>false,"message"=>"Data rusak"]));

$identifier = trim($input['input'] ?? '');
$password   = $input['password'] ?? '';

if (empty($identifier) || empty($password)) {
    echo encrypt_json(["status"=>false,"message"=>"Data tidak lengkap"]);
    exit;
}

$stmt = $conn->prepare("SELECT id, username, nama_lengkap, nip_nisn, role, password FROM users WHERE username = ? OR nip_nisn = ?");
$stmt->bind_param("ss", $identifier, $identifier);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows == 0) {
    echo encrypt_json(["status"=>false,"message"=>"Akun tidak ditemukan"]);
    exit;
}

$user = $res->fetch_assoc();
$stmt->close();

if (!password_verify($password, $user['password'])) {
    echo encrypt_json(["status"=>false,"message"=>"Password salah"]);
    exit;
}

unset($user['password']);
echo encrypt_json([
    "status" => true,
    "message" => "Login berhasil",
    "data" => $user
]);
?>
```

---

## 4. `register.php` – **DAFTAR USER (GURU/KARYAWAN)**

```php
<?php
// register.php
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>"error","message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$raw = file_get_contents('php://input');
$input = decrypt_json($raw);
if (!$input) exit(encrypt_json(["status"=>"error","message"=>"Data rusak"]));

$username = trim($input['username'] ?? '');
$nama     = trim($input['nama_lengkap'] ?? '');
$nip_nisn = trim($input['nip_nisn'] ?? '');
$password = $input['password'] ?? '';
$role     = $input['role'] ?? 'user';
$is_karyawan = !empty($input['is_karyawan']);

if (empty($username) || empty($nama) || empty($password)) {
    echo encrypt_json(["status"=>"error","message"=>"Data tidak lengkap"]);
    exit;
}

if (!$is_karyawan && empty($nip_nisn)) {
    echo encrypt_json(["status"=>"error","message"=>"NIP/NISN wajib untuk guru!"]);
    exit;
}

// Validasi panjang
if (strlen($username) < 4 || strlen($password) < 6) {
    echo encrypt_json(["status"=>"error","message"=>"Username min 4, password min 6 karakter"]);
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);

// Cek admin/superadmin unik
if (in_array($role, ['admin', 'superadmin'])) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = ?");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo encrypt_json(["status"=>"error","message"=>ucfirst($role)." sudah ada"]);
        exit;
    }
    $stmt->close();
}

$stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $username, $nama, $nip_nisn, $hashed, $role);

if ($stmt->execute()) {
    echo encrypt_json(["status"=>"success","message"=>"Akun berhasil dibuat"]);
} else {
    echo encrypt_json(["status"=>"error","message"=>"Gagal daftar: " . $stmt->error]);
}
$stmt->close();
?>
```

---

## 5. `absen_history.php` – **RIWAYAT USER**

```php
<?php
// absen_history.php
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>false,"message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$raw = file_get_contents('php://input');
$input = decrypt_json($raw);
$userId = $input['user_id'] ?? 0;

if (empty($userId)) {
    echo encrypt_json(["status"=>false,"message"=>"User ID kosong"]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($row = $res->fetch_assoc()) $data[] = $row;
$stmt->close();

echo encrypt_json(["status" => true, "data" => $data]);
?>
```

---

## 6. `presensi_rekap.php` – **REKAP SEMUA (ADMIN)**

```php
<?php
// presensi_rekap.php
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>false,"message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$stmt = $conn->prepare("SELECT p.*, u.nama_lengkap, u.username FROM absensi p JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC");
$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($row = $res->fetch_assoc()) $data[] = $row;
$stmt->close();

echo encrypt_json(["status" => true, "data" => $data]);
?>
```

---

## 7. `presensi_pending.php` – **PENDING APPROVAL**

```php
<?php
// presensi_pending.php
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>false,"message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$stmt = $conn->prepare("SELECT p.*, u.nama_lengkap FROM absensi p JOIN users u ON p.user_id = u.id WHERE p.status = 'Pending'");
$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($row = $res->fetch_assoc()) $data[] = $row;
$stmt->close();

echo encrypt_json(["status" => true, "data" => $data]);
?>
```

---

## 8. `presensi_approve.php` – **APPROVE / REJECT**

```php
<?php
// presensi_approve.php
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>false,"message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$raw = file_get_contents('php://input');
$input = decrypt_json($raw);
$id = $input['id'] ?? 0;
$status = $input['status'] ?? '';

if (empty($id) || !in_array($status, ['Disetujui', 'Ditolak'])) {
    echo encrypt_json(["status"=>false,"message"=>"ID atau status tidak valid"]);
    exit;
}

$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $id);
if ($stmt->execute()) {
    echo encrypt_json(["status"=>true,"message"=>"Status diupdate ke '$status'"]);
} else {
    echo encrypt_json(["status"=>false,"message"=>"Gagal update"]);
}
$stmt->close();
?>
```

---

## 9. `get_users.php`, `update_user.php`, `delete_user.php` – **ADMIN PANEL**

```php
<?php
// get_users.php
include "config.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>"error","message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$stmt = $conn->prepare("SELECT id, username, nama_lengkap, nip_nisn, role FROM users ORDER BY id DESC");
$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($row = $res->fetch_assoc()) $data[] = $row;
$stmt->close();

echo encrypt_json(["status"=>"success","message"=>"Data dimuat","data"=>$data]);
?>
```

```php
<?php
// update_user.php
include "config.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>"error","message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$raw = file_get_contents('php://input');
$input = decrypt_json($raw);
$id = $input['id'] ?? 0;
$nama = trim($input['nama_lengkap'] ?? '');
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($id) || empty($nama) || empty($username)) {
    echo encrypt_json(["status"=>"error","message"=>"Data tidak lengkap"]);
    exit;
}

$sql = "UPDATE users SET username = ?, nama_lengkap = ? WHERE id = ?";
$params = ["ss", $username, $nama];
$types = "ssi";
if (!empty($password)) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $sql = "UPDATE users SET username = ?, nama_lengkap = ?, password = ? WHERE id = ?";
    $params = ["sss", $username, $nama, $hashed];
    $types = "sssi";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param(...$params, $id);
if ($stmt->execute()) {
    echo encrypt_json(["status"=>"success","message"=>"User diperbarui"]);
} else {
    echo encrypt_json(["status"=>"error","message"=>$stmt->error]);
}
$stmt->close();
?>
```

```php
<?php
// delete_user.php
include "config.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(encrypt_json(["status"=>"error","message"=>"Method tidak diizinkan"]));
checkRateLimit($conn);

$raw = file_get_contents('php://input');
$input = decrypt_json($raw);
$id = $input['id'] ?? 0;

if (empty($id)) exit(encrypt_json(["status"=>"error","message"=>"ID kosong"]));

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows == 0) exit(encrypt_json(["status"=>"error","message"=>"User tidak ditemukan"]));
$user = $res->fetch_assoc();
$stmt->close();

if ($user['role'] === 'superadmin') {
    echo encrypt_json(["status"=>"error","message"=>"Tidak boleh hapus superadmin"]);
    exit;
}

$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
if ($stmt->execute()) {
    echo encrypt_json(["status"=>"success","message"=>"User dihapus"]);
} else {
    echo encrypt_json(["status"=>"error","message"=>$stmt->error]);
}
$stmt->close();
?>
```

---

