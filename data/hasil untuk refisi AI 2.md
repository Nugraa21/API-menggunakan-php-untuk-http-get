```php
<?php
// config.php (NO CHANGES - remains the same)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
$host = "localhost";
$user = "root";
$pass = "";
$db = "database_smk_2";
// $db = "database_smk_3";
// $db = "skaduta_presensi";
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
// absen.php (UPDATED: Expanded jenis ENUM support, added 'informasi' and 'dokumen' fields, auto-set status based on jenis (biasa: Disetujui, others: Pending), skip distance check for Penugasan types, added dokumen upload similar to selfie)
include "config.php";
// ================================
// KOORDINAT SEKOLAH (SINKRON SAMA FLUTTER)
$sekolah_lat = -7.777047019078815;
$sekolah_lng = 110.3671540164373;
$max_distance = 100; // Meter, sinkron sama Flutter
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lat2 - $lat1);
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
$informasi = trim($input['informasi'] ?? ''); // NEW: For Penugasan
$dokumen_base64 = $input['dokumenBase64'] ?? $input['dokumen'] ?? ''; // NEW: Dokumen upload
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
// NEW: Validasi informasi & dokumen wajib untuk semua Penugasan types
if (strpos($jenis, 'Penugasan') !== false) {
    if (empty($informasi)) {
        echo json_encode(["status" => false, "message" => "Informasi penugasan wajib diisi untuk $jenis!"]);
        exit;
    }
    if (empty($dokumen_base64)) {
        echo json_encode(["status" => false, "message" => "Dokumen penugasan wajib diunggah untuk $jenis!"]);
        exit;
    }
}
$distance = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
error_log("DEBUG: Distance: " . round($distance) . "m");
// CEK RADIUS (sinkron sama Flutter) - SKIP untuk Penugasan
$is_penugasan = strpos($jenis, 'Penugasan') !== false;
if (!$is_penugasan && ($distance > $max_distance || $distance == 0)) {
    echo json_encode(["status" => false, "message" => "Di luar jangkauan sekolah! Jarak: " . round($distance) . "m"]);
    exit;
}
// CEK 2x ABSEN / HARI (hanya Masuk/Pulang biasa, adjust for Penugasan)
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
// NEW: Auto-determine status based on jenis
$status = (in_array($jenis, ['Masuk', 'Pulang', 'Pulang Cepat'])) ? 'Disetujui' : 'Pending';
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
// NEW: UPLOAD DOKUMEN (similar to selfie, only if provided)
$dokumen_name = '';
if (!empty($dokumen_base64)) {
    $dokumen_dir = "dokumen/";
    if (!file_exists($dokumen_dir)) {
        mkdir($dokumen_dir, 0777, true);
    }
    $dokumen_name = "dokumen_" . $user_id . "_" . time() . ".pdf"; // Assume PDF, or jpg if image
    $dokumen_path = $dokumen_dir . $dokumen_name;
    $dokumen_decoded = base64_decode($dokumen_base64);
    if ($dokumen_decoded && file_put_contents($dokumen_path, $dokumen_decoded)) {
        error_log("DEBUG: Dokumen uploaded OK");
    } else {
        unlink($dokumen_path);
        $dokumen_name = '';
    }
}
// INSERT DATA (escape basic, ganti prepared kalau bisa) - include new fields
$q = $conn->query("INSERT INTO absensi
(user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status, created_at)
VALUES
('$user_id', '$jenis', '$keterangan', '$informasi', '$dokumen_name', '$image_name', '$lat', '$lng', '$status', NOW())");
if ($q) {
    $absen_id = $conn->insert_id;
    echo json_encode([
        "status" => true,
        "message" => "Presensi $jenis berhasil! ID: $absen_id",
        "data" => ["id" => $absen_id, "jenis" => $jenis, "timestamp" => date('Y-m-d H:i:s')]
    ]);
} else {
    if ($image_name) unlink($image_path);
    if ($dokumen_name) unlink($dokumen_path); // NEW: Cleanup dokumen if fail
    echo json_encode(["status" => false, "message" => "Gagal simpan data: " . $conn->error]);
}
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

```sql
-- Schema (UPDATED: Expanded ENUM for jenis to include Penugasan types; added 'informasi' TEXT and 'dokumen' VARCHAR(255) to absensi; no new tables needed)
DROP TABLE IF EXISTS absensi;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(255) NOT NULL,
  nip_nisn VARCHAR(255) DEFAULT NULL,  -- Optional for karyawan, required for guru (validated in PHP/Flutter)
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin','superadmin') DEFAULT 'user',
  PRIMARY KEY (id)
);

CREATE TABLE absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  jenis ENUM('Masuk','Pulang','Izin','Pulang Cepat','Penugasan_Masuk','Penugasan_Pulang','Penugasan_Full'),
  keterangan TEXT,
  informasi TEXT,  -- NEW: For Penugasan details
  dokumen VARCHAR(255),  -- NEW: Path to uploaded dokumen
  selfie VARCHAR(255),
  latitude VARCHAR(100),
  longitude VARCHAR(100),
  status ENUM('Pending','Disetujui','Ditolak') DEFAULT 'Pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

```dart
// main.dart (UPDATED: Added routes for specific presensi types if needed, but using arguments)
import 'package:flutter/material.dart';
import 'pages/login_page.dart';
import 'pages/register_page.dart';
import 'pages/dashboard_page.dart';
import 'pages/user_management_page.dart';
// PAGE BARU PRESENSI
import 'pages/presensi_page.dart';
import 'pages/history_page.dart';
import 'pages/admin_presensi_page.dart';
// PAGE BARU ADMIN USER
import 'pages/admin_user_list_page.dart';
import 'pages/admin_user_detail_page.dart';
import 'models/user_model.dart';
void main() {
  runApp(const SkadutaApp());
}
class SkadutaApp extends StatelessWidget {
  const SkadutaApp({super.key});
  @override
  Widget build(BuildContext context) {
    final theme = ThemeData(
      colorSchemeSeed: Colors.orange,
      useMaterial3: true,
      scaffoldBackgroundColor: const Color(0xFFF5F5F5),
      appBarTheme: const AppBarTheme(centerTitle: true, elevation: 0),
    );
    return MaterialApp(
      title: 'Skaduta Presensi',
      debugShowCheckedModeBanner: false,
      theme: theme,
      initialRoute: '/login',
      routes: {
        '/login': (context) => const LoginPage(),
        '/register': (context) => const RegisterPage(),
        // admin presensi tidak butuh argumen
        '/admin-presensi': (context) => const AdminPresensiPage(),
      },
      onGenerateRoute: (settings) {
        if (settings.name == '/dashboard') {
          final user = settings.arguments as UserModel;
          return MaterialPageRoute(builder: (_) => DashboardPage(user: user));
        }
        if (settings.name == '/user-management') {
          return MaterialPageRoute(builder: (_) => const UserManagementPage());
        }
        if (settings.name == '/presensi') {
          final args = settings.arguments as Map<String, dynamic>;
          final user = args['user'] as UserModel;
          final jenis = args['jenis'] as String; // NEW: Pass jenis
          return MaterialPageRoute(
            builder: (_) => PresensiPage(user: user, initialJenis: jenis),
          );
        }
        if (settings.name == '/history') {
          final user = settings.arguments as UserModel;
          return MaterialPageRoute(builder: (_) => HistoryPage(user: user));
        }
        if (settings.name == '/admin-user-list') {
          return MaterialPageRoute(builder: (_) => const AdminUserListPage());
        }
        return null;
      },
    );
  }
}
```

```dart
// api/api_service.dart (UPDATED: Added 'informasi' and 'dokumenBase64' to submitPresensi; register now sends 'is_karyawan')
import 'dart:convert';
import 'package:http/http.dart' as http;
class ApiService {
  static const String baseUrl = "http://192.168.0.102/backendapk";
  // LOGIN
  static Future<Map<String, dynamic>> login({
    required String input,
    required String password,
  }) async {
    final res = await http.post(
      Uri.parse("$baseUrl/login.php"),
      body: {"input": input, "password": password},
    );
    return jsonDecode(res.body);
  }
  // REGISTER (UPDATED: Add is_karyawan)
  static Future<Map<String, dynamic>> register({
    required String username,
    required String namaLengkap,
    required String nipNisn,
    required String password,
    required String role,
    required bool isKaryawan, // NEW
  }) async {
    final res = await http.post(
      Uri.parse("$baseUrl/register.php"),
      body: {
        "username": username,
        "nama_lengkap": namaLengkap,
        "nip_nisn": nipNisn,
        "password": password,
        "role": role,
        "is_karyawan": isKaryawan ? '1' : '0', // NEW
      },
    );
    return jsonDecode(res.body);
  }
  // GET ALL USERS (SUPERADMIN)
  static Future<List<dynamic>> getUsers() async {
    final res = await http.get(Uri.parse("$baseUrl/get_users.php"));
    final data = jsonDecode(res.body);
    if (data["status"] == "success") {
      return data["data"] as List<dynamic>;
    }
    return [];
  }
  // DELETE USER
  static Future<Map<String, dynamic>> deleteUser(String id) async {
    final res = await http.post(
      Uri.parse("$baseUrl/delete_user.php"),
      body: {"id": id},
    );
    return jsonDecode(res.body);
  }
  // UPDATE USER (Tambah password optional)
  static Future<Map<String, dynamic>> updateUser({
    required String id,
    required String username,
    required String namaLengkap,
    String? password, // Optional
  }) async {
    final body = {"id": id, "username": username, "nama_lengkap": namaLengkap};
    if (password != null && password.isNotEmpty) {
      body["password"] = password;
    }
    final res = await http.post(
      Uri.parse("$baseUrl/update_user.php"),
      body: body,
    );
    return jsonDecode(res.body);
  }
  // PRESENSI SUBMIT (UPDATED: Added informasi and dokumenBase64)
  static Future<Map<String, dynamic>> submitPresensi({
    required String userId,
    required String jenis,
    required String keterangan,
    required String informasi, // NEW
    required String dokumenBase64, // NEW
    required String latitude,
    required String longitude,
    required String base64Image,
  }) async {
    final body = {
      "userId": userId,
      "jenis": jenis,
      "keterangan": keterangan,
      "informasi": informasi, // NEW
      "dokumenBase64": dokumenBase64, // NEW
      "latitude": latitude,
      "longitude": longitude,
      "base64Image": base64Image,
    };
    print('DEBUG API: Request body: ${jsonEncode(body)}');
    final res = await http.post(
      Uri.parse("$baseUrl/absen.php"),
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: body,
    );
    print('DEBUG API: Status code: ${res.statusCode}');
    print('DEBUG API: Response body: ${res.body}');
    return jsonDecode(res.body);
  }
  // GET USER HISTORY
  static Future<List<dynamic>> getUserHistory(String userId) async {
    final res = await http.get(
      Uri.parse("$baseUrl/absen_history.php?user_id=$userId"),
    );
    final data = jsonDecode(res.body);
    if (data["status"] == true) {
      return data["data"] as List<dynamic>;
    }
    return [];
  }
  // GET ALL PRESENSI (ADMIN) - Ganti ke absen_admin_list.php untuk konsistensi
  static Future<List<dynamic>> getAllPresensi() async {
    final res = await http.get(Uri.parse("$baseUrl/absen_admin_list.php"));
    print('DEBUG API: Presensi response status: ${res.statusCode}');
    print(
      'DEBUG API: Presensi response body preview: ${res.body.substring(0, 200)}...',
    );
    try {
      final data = jsonDecode(res.body);
      if (data["status"] == true) {
        return data["data"] as List<dynamic>;
      } else if (data['error'] != null) {
        throw Exception('PHP Error: ${data['error']}');
      }
      return [];
    } catch (e) {
      print('DEBUG API: JSON Parse Error: $e');
      throw Exception('Response bukan JSON valid: $e. Cek server log.');
    }
  }
  // UPDATE PRESENSI STATUS (FIX: Debug detail untuk approve, handle 500)
  static Future<Map<String, dynamic>> updatePresensiStatus({
    required String id,
    required String status,
  }) async {
    final body = {"id": id, "status": status};
    print('DEBUG API UPDATE: Request body: ${jsonEncode(body)}');
    final res = await http.post(
      Uri.parse("$baseUrl/presensi_approve.php"),
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: body,
    );
    print('DEBUG API UPDATE: Status code: ${res.statusCode}');
    print(
      'DEBUG API UPDATE: Response body raw: "${res.body}"',
    ); // Raw body untuk debug
    if (res.statusCode != 200) {
      throw Exception(
        'HTTP Error ${res.statusCode}: ${res.body}',
      ); // Fix: Include body in exception
    }
    try {
      final data = jsonDecode(res.body);
      print('DEBUG API UPDATE: Parsed JSON: ${jsonEncode(data)}');
      return data;
    } catch (e) {
      print('DEBUG API UPDATE: JSON Parse Error: $e');
      throw Exception(
        'Response bukan JSON valid: ${res.body}. Cek PHP approve.',
      );
    }
  }
}
```

```dart
// models/user_model.dart (NO CHANGES)
class UserModel {
  final String id;
  final String username;
  final String namaLengkap;
  final String nipNisn;
  final String role;
  UserModel({
    required this.id,
    required this.username,
    required this.namaLengkap,
    required this.nipNisn,
    required this.role,
  });
  factory UserModel.fromJson(Map<String, dynamic> json) {
    return UserModel(
      id: json['id'].toString(),
      username: json['username'] ?? '',
      namaLengkap: json['nama_lengkap'] ?? '',
      nipNisn: json['nip_nisn'] ?? '',
      role: json['role'] ?? 'user',
    );
  }
}
```

```dart
// pages/admin_presensi_page.dart (UPDATED: Handle new fields informasi and dokumen in dialog/list)
import 'package:flutter/material.dart';
import '../api/api_service.dart';
class AdminPresensiPage extends StatefulWidget {
  const AdminPresensiPage({super.key});
  @override
  State<AdminPresensiPage> createState() => _AdminPresensiPageState();
}
class _AdminPresensiPageState extends State<AdminPresensiPage> {
  bool _loading = false;
  List<dynamic> _items = [];
  String _filterStatus = 'All'; // All, Pending, Disetujui, Ditolak
  @override
  void initState() {
    super.initState();
    _loadPresensi();
  }
  Future<void> _loadPresensi() async {
    setState(() => _loading = true);
    try {
      final data = await ApiService.getAllPresensi();
      setState(() => _items = data ?? []);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Gagal ambil data presensi: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }
  List<dynamic> get _filteredItems {
    if (_filterStatus == 'All') return _items;
    return _items
        .where((item) => (item['status'] ?? '') == _filterStatus)
        .toList();
  }
  Future<void> _showDetailDialog(dynamic item) async {
    final status = item['status'] ?? 'Pending';
    final baseUrl = ApiService.baseUrl;
    final fotoUrl = item['selfie'] != null && item['selfie'].isNotEmpty
        ? '$baseUrl/selfie/${item['selfie']}'
        : null;
    final dokumenUrl = item['dokumen'] != null && item['dokumen'].isNotEmpty
        ? '$baseUrl/dokumen/${item['dokumen']}' // NEW: Dokumen URL
        : null;
    showDialog(
      context: context,
      builder: (context) => AnimatedPadding(
        padding: EdgeInsets.all(MediaQuery.of(context).size.width * 0.05),
        duration: const Duration(milliseconds: 300),
        child: AlertDialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          title: Row(
            children: [
              Icon(
                status == 'Disetujui'
                    ? Icons.check_circle
                    : status == 'Ditolak'
                    ? Icons.cancel
                    : Icons.pending,
                color: status == 'Disetujui'
                    ? Colors.green
                    : status == 'Ditolak'
                    ? Colors.red
                    : Colors.orange,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text('${item['nama_lengkap']} - ${item['jenis']}'),
              ),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Tanggal: ${item['created_at'] ?? ''}'),
                const SizedBox(height: 8),
                Text('Keterangan: ${item['keterangan'] ?? '-'}'),
                if (item['informasi'] != null && item['informasi'].isNotEmpty) ...[ // NEW
                  const SizedBox(height: 8),
                  Text(
                    'Informasi Penugasan: ${item['informasi']}',
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ],
                const SizedBox(height: 8),
                if (fotoUrl != null) ...[
                  const Text(
                    'Foto Presensi:',
                    style: TextStyle(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 8),
                  GestureDetector(
                    onTap: () => _showFullPhoto(fotoUrl),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: Image.network(
                        fotoUrl,
                        height: 250,
                        width: double.infinity,
                        fit: BoxFit.cover,
                        loadingBuilder: (context, child, loadingProgress) {
                          if (loadingProgress == null) return child;
                          return const Center(
                            child: CircularProgressIndicator(),
                          );
                        },
                        errorBuilder: (context, error, stackTrace) => Container(
                          height: 250,
                          decoration: BoxDecoration(
                            color: Colors.grey[200],
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: const Icon(
                            Icons.error,
                            size: 50,
                            color: Colors.grey,
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                ] else
                  Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: Colors.grey[100],
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Row(
                      children: [
                        Icon(Icons.image_not_supported, color: Colors.grey),
                        SizedBox(width: 8),
                        Text(
                          'Tidak ada foto',
                          style: TextStyle(color: Colors.grey),
                        ),
                      ],
                    ),
                  ),
                if (dokumenUrl != null) ...[ // NEW: Dokumen display
                  const SizedBox(height: 8),
                  const Text(
                    'Dokumen Penugasan:',
                    style: TextStyle(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 8),
                  GestureDetector(
                    onTap: () => _showFullDokumen(dokumenUrl), // NEW: Separate viewer for dokumen
                    child: Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.blue[50],
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: Colors.blue),
                      ),
                      child: Row(
                        children: [
                          Icon(Icons.attachment, color: Colors.blue),
                          const SizedBox(width: 8),
                          Text('Lihat Dokumen (${item['dokumen']})'),
                        ],
                      ),
                    ),
                  ),
                ],
                const SizedBox(height: 8),
                Text(
                  'Status Saat Ini: $status',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    color: status == 'Disetujui'
                        ? Colors.green
                        : status == 'Ditolak'
                        ? Colors.red
                        : Colors.orange,
                  ),
                ),
              ],
            ),
          ),
          actions: [
            if (status == 'Pending') ...[
              TextButton(
                onPressed: () {
                  Navigator.pop(context);
                  _updateStatus(item['id'].toString(), 'Disetujui');
                },
                child: const Text(
                  'Setujui',
                  style: TextStyle(color: Colors.green),
                ),
              ),
              TextButton(
                onPressed: () {
                  Navigator.pop(context);
                  _updateStatus(item['id'].toString(), 'Ditolak');
                },
                child: const Text('Tolak', style: TextStyle(color: Colors.red)),
              ),
            ] else
              TextButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Tutup'),
              ),
          ],
        ),
      ),
    );
  }
  void _showFullPhoto(String url) {
    showDialog(
      context: context,
      builder: (context) => Dialog(
        backgroundColor: Colors.black,
        child: Stack(
          children: [
            Center(
              child: InteractiveViewer(
                child: Image.network(url, fit: BoxFit.contain),
              ),
            ),
            Positioned(
              top: 40,
              right: 20,
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white),
                onPressed: () => Navigator.pop(context),
              ),
            ),
          ],
        ),
      ),
    );
  }
  // NEW: Viewer for dokumen (simple, assume image or open in browser if PDF)
  void _showFullDokumen(String url) {
    showDialog(
      context: context,
      builder: (context) => Dialog(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AppBar(title: const Text('Dokumen'), actions: [IconButton(icon: const Icon(Icons.open_in_browser), onPressed: () {/* Open URL */})]),
            Expanded(
              child: Image.network(url, fit: BoxFit.contain), // Assume image; for PDF use webview
            ),
          ],
        ),
      ),
    );
  }
  Future<void> _updateStatus(String id, String status) async {
    try {
      final res = await ApiService.updatePresensiStatus(id: id, status: status);
      if (res['status'] == true) { // Konsisten dengan PHP response
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Status diperbarui'),
            backgroundColor: Colors.green,
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'Gagal update status')),
        );
      }
      _loadPresensi();
    } catch (e) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('Error: $e')));
    }
  }
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Persetujuan Presensi'),
        backgroundColor: cs.primary,
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          DropdownButton<String>(
            value: _filterStatus,
            items: [
              'All',
              'Pending',
              'Disetujui',
              'Ditolak',
            ].map((s) => DropdownMenuItem(value: s, child: Text(s))).toList(),
            onChanged: (v) => setState(() => _filterStatus = v ?? 'All'),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              'Total: ${_filteredItems.length}',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : RefreshIndicator(
                    onRefresh: _loadPresensi,
                    child: ListView.builder(
                      padding: const EdgeInsets.all(16),
                      itemCount: _filteredItems.length,
                      itemBuilder: (ctx, i) {
                        final item = _filteredItems[i];
                        final status = item['status'] ?? 'Pending';
                        Color statusColor;
                        if (status == 'Disetujui') {
                          statusColor = Colors.green;
                        } else if (status == 'Ditolak') {
                          statusColor = Colors.red;
                        } else {
                          statusColor = Colors.orange;
                        }
                        final baseUrl = ApiService.baseUrl;
                        final fotoUrl =
                            item['selfie'] != null && item['selfie'].isNotEmpty
                            ? '$baseUrl/selfie/${item['selfie']}'
                            : null;
                        return Card(
                          elevation: 2,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                          margin: const EdgeInsets.symmetric(vertical: 8),
                          child: ListTile(
                            onTap: () => _showDetailDialog(item),
                            title: Text(
                              '${item['nama_lengkap'] ?? ''} - ${item['jenis'] ?? ''}',
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            subtitle: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text('Tgl: ${item['created_at'] ?? ''}'),
                                Text('Ket: ${item['keterangan'] ?? '-'}'),
                                if (item['informasi'] != null && item['informasi'].isNotEmpty) // NEW
                                  Text('Info: ${item['informasi'].substring(0, 50)}...'),
                                Text(
                                  'Status: $status',
                                  style: TextStyle(
                                    fontSize: 12,
                                    fontWeight: FontWeight.bold,
                                    color: statusColor,
                                  ),
                                ),
                                if (fotoUrl != null) ...[
                                  const SizedBox(height: 4),
                                  GestureDetector(
                                    onTap: () => _showFullPhoto(fotoUrl),
                                    child: ClipRRect(
                                      borderRadius: BorderRadius.circular(8),
                                      child: Image.network(
                                        fotoUrl,
                                        height: 60,
                                        width: 60,
                                        fit: BoxFit.cover,
                                        loadingBuilder:
                                            (context, child, loadingProgress) {
                                              if (loadingProgress == null)
                                                return child;
                                              return Container(
                                                height: 60,
                                                width: 60,
                                                color: Colors.grey[200],
                                                child: const Center(
                                                  child:
                                                      CircularProgressIndicator(
                                                        strokeWidth: 2,
                                                      ),
                                                ),
                                              );
                                            },
                                        errorBuilder:
                                            (context, error, stackTrace) =>
                                                Container(
                                                  height: 60,
                                                  width: 60,
                                                  decoration: BoxDecoration(
                                                    color: Colors.grey[200],
                                                    borderRadius:
                                                        BorderRadius.circular(
                                                          8,
                                                        ),
                                                  ),
                                                  child: const Icon(
                                                    Icons.image_not_supported,
                                                    size: 30,
                                                    color: Colors.grey,
                                                  ),
                                                ),
                                      ),
                                    ),
                                  ),
                                ],
                              ],
                            ),
                            trailing: status == 'Pending'
                                ? const Icon(
                                    Icons.arrow_forward_ios,
                                    size: 16,
                                    color: Colors.orange,
                                  )
                                : Icon(
                                    status == 'Disetujui'
                                        ? Icons.check_circle
                                        : Icons.cancel,
                                    color: statusColor,
                                  ),
                          ),
                        );
                      },
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}
```

```dart
// pages/admin_user_detail_page.dart (UPDATED: Handle new fields in tabs)
import 'dart:convert';
import 'package:flutter/material.dart';
import '../api/api_service.dart';
class AdminUserDetailPage extends StatefulWidget {  // FIXED: Removed circular import
  final String userId;
  final String userName;
  const AdminUserDetailPage({
    super.key,
    required this.userId,
    required this.userName,
  });
  @override
  State<AdminUserDetailPage> createState() => _AdminUserDetailPageState();
}
class _AdminUserDetailPageState extends State<AdminUserDetailPage>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  bool _loading = false;
  List<dynamic> _history = [];
  List<dynamic> _pendingPresensi = [];
  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _loadData();
  }
  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }
  Future<void> _loadData() async {
    setState(() => _loading = true);
    try {
      final historyData = await ApiService.getUserHistory(widget.userId);
      setState(() => _history = historyData ?? []);
      final allPresensi = await ApiService.getAllPresensi();
      final pending = allPresensi
          .where(
            (p) =>
                p['user_id'] == widget.userId &&
                (p['status'] ?? '') == 'Pending',
          )
          .toList();
      setState(() => _pendingPresensi = pending);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('Gagal memuat data: $e')));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }
  Future<void> _updateStatus(String id, String status) async {
    try {
      print('DEBUG UPDATE: Starting approve for ID=$id, status=$status');
      final res = await ApiService.updatePresensiStatus(id: id, status: status);
      print('DEBUG UPDATE: Full response received: ${jsonEncode(res)}');
      if (res['status'] == true) { // Konsisten
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Status diperbarui'),
            backgroundColor: Colors.green,
          ),
        );
        _loadData(); // Reload tab
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'Gagal update status')),
        );
      }
    } catch (e) {
      print('DEBUG UPDATE: Exception caught: $e');
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('Error approve: $e')));
    }
  }
  void _showFullPhoto(String? url) {
    if (url == null || url.isEmpty) return;
    showDialog(
      context: context,
      builder: (context) => Dialog(
        backgroundColor: Colors.black,
        child: Stack(
          children: [
            Center(
              child: InteractiveViewer(
                child: Image.network(
                  url,
                  fit: BoxFit.contain,
                  loadingBuilder: (context, child, loadingProgress) {
                    if (loadingProgress == null) return child;
                    return const Center(
                      child: CircularProgressIndicator(color: Colors.white),
                    );
                  },
                  errorBuilder: (context, error, stackTrace) => const Center(
                    child: Icon(Icons.error, color: Colors.white, size: 50),
                  ),
                ),
              ),
            ),
            Positioned(
              top: 40,
              right: 20,
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white),
                onPressed: () => Navigator.pop(context),
              ),
            ),
          ],
        ),
      ),
    );
  }
  // NEW: Dokumen viewer
  void _showFullDokumen(String? url) {
    if (url == null || url.isEmpty) return;
    showDialog(
      context: context,
      builder: (context) => Dialog(
        child: Image.network(url, fit: BoxFit.contain), // Simple for now
      ),
    );
  }
  Widget _buildHistoryTab() {
    if (_loading) {
      return ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: 5,
        itemBuilder: (ctx, i) => const Card(
          child: ListTile(
            leading: CircularProgressIndicator(strokeWidth: 2),
            title: Text('Loading...'),
          ),
        ),
      );
    }
    if (_history.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.history, size: 64, color: Colors.grey),
            const SizedBox(height: 16),
            Text(
              'Belum ada riwayat presensi',
              style: TextStyle(fontSize: 16, color: Colors.grey),
            ),
          ],
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: _loadData,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _history.length,
        itemBuilder: (ctx, i) {
          final item = _history[i];
          final status = item['status'] ?? 'Pending';
          Color statusColor = Colors.orange;
          if (status == 'Disetujui') statusColor = Colors.green;
          if (status == 'Ditolak') statusColor = Colors.red;
          final baseUrl = ApiService.baseUrl;
          final fotoUrl = item['selfie'] != null && item['selfie'].isNotEmpty
              ? '$baseUrl/selfie/${item['selfie']}'
              : null;
          final dokumenUrl = item['dokumen'] != null && item['dokumen'].isNotEmpty // NEW
              ? '$baseUrl/dokumen/${item['dokumen']}'
              : null;
          return Card(
            elevation: 2,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
            ),
            margin: const EdgeInsets.symmetric(vertical: 8),
            child: ListTile(
              title: Text(
                item['jenis'] ?? '',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Tgl: ${item['created_at'] ?? ''}'),
                  Text('Ket: ${item['keterangan'] ?? '-'}'),
                  if (item['informasi'] != null && item['informasi'].isNotEmpty) // NEW
                    Text('Info: ${item['informasi']}'),
                  Text('Status: $status', style: TextStyle(color: statusColor)),
                  if (fotoUrl != null) ...[
                    const SizedBox(height: 4),
                    GestureDetector(
                      onTap: () => _showFullPhoto(fotoUrl),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: Image.network(
                          fotoUrl,
                          height: 60,
                          width: 60,
                          fit: BoxFit.cover,
                          loadingBuilder: (context, child, loadingProgress) {
                            if (loadingProgress == null) return child;
                            return Container(
                              height: 60,
                              width: 60,
                              color: Colors.grey[200],
                              child: const Center(
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              ),
                            );
                          },
                          errorBuilder: (context, error, stackTrace) =>
                              Container(
                                height: 60,
                                width: 60,
                                decoration: BoxDecoration(
                                  color: Colors.grey[200],
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: const Icon(
                                  Icons.image_not_supported,
                                  size: 30,
                                  color: Colors.grey,
                                ),
                              ),
                        ),
                      ),
                    ),
                  ],
                  if (dokumenUrl != null) ...[ // NEW
                    const SizedBox(height: 4),
                    GestureDetector(
                      onTap: () => _showFullDokumen(dokumenUrl),
                      child: Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: Colors.blue[50],
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: const Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(Icons.attachment, color: Colors.blue, size: 20),
                            SizedBox(width: 4),
                            Text('Dokumen', style: TextStyle(color: Colors.blue)),
                          ],
                        ),
                      ),
                    ),
                  ],
                ],
              ),
              trailing: Icon(
                status == 'Disetujui'
                    ? Icons.check_circle
                    : status == 'Ditolak'
                    ? Icons.cancel
                    : Icons.pending,
                color: statusColor,
              ),
            ),
          );
        },
      ),
    );
  }
  Widget _buildPendingTab() {
    if (_loading) {
      return ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: 3,
        itemBuilder: (ctx, i) => const Card(
          child: ListTile(
            leading: CircularProgressIndicator(strokeWidth: 2),
            title: Text('Loading...'),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [Icon(Icons.check), Icon(Icons.close)],
            ),
          ),
        ),
      );
    }
    if (_pendingPresensi.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.pending_actions, size: 64, color: Colors.grey),
            const SizedBox(height: 16),
            Text(
              'Tidak ada presensi pending',
              style: TextStyle(fontSize: 16, color: Colors.grey),
            ),
          ],
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: _loadData,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _pendingPresensi.length,
        itemBuilder: (ctx, i) {
          final item = _pendingPresensi[i];
          final baseUrl = ApiService.baseUrl;
          final fotoUrl = item['selfie'] != null && item['selfie'].isNotEmpty
              ? '$baseUrl/selfie/${item['selfie']}'
              : null;
          final dokumenUrl = item['dokumen'] != null && item['dokumen'].isNotEmpty // NEW
              ? '$baseUrl/dokumen/${item['dokumen']}'
              : null;
          return Card(
            elevation: 2,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
            ),
            margin: const EdgeInsets.symmetric(vertical: 8),
            child: ListTile(
              title: Text(
                item['jenis'] ?? '',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Tgl: ${item['created_at'] ?? ''}'),
                  Text('Ket: ${item['keterangan'] ?? '-'}'),
                  if (item['informasi'] != null && item['informasi'].isNotEmpty) // NEW
                    Text('Info: ${item['informasi']}'),
                  const Text('Status: Pending'),
                  if (fotoUrl != null) ...[
                    const SizedBox(height: 4),
                    GestureDetector(
                      onTap: () => _showFullPhoto(fotoUrl),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: Image.network(
                          fotoUrl,
                          height: 60,
                          width: 60,
                          fit: BoxFit.cover,
                          loadingBuilder: (context, child, loadingProgress) {
                            if (loadingProgress == null) return child;
                            return Container(
                              height: 60,
                              width: 60,
                              color: Colors.grey[200],
                              child: const Center(
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              ),
                            );
                          },
                          errorBuilder: (context, error, stackTrace) =>
                              Container(
                                height: 60,
                                width: 60,
                                decoration: BoxDecoration(
                                  color: Colors.grey[200],
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: const Icon(
                                  Icons.image_not_supported,
                                  size: 30,
                                  color: Colors.grey,
                                ),
                              ),
                        ),
                      ),
                    ),
                  ],
                  if (dokumenUrl != null) ...[ // NEW
                    const SizedBox(height: 4),
                    GestureDetector(
                      onTap: () => _showFullDokumen(dokumenUrl),
                      child: Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: Colors.blue[50],
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: const Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(Icons.attachment, color: Colors.blue, size: 20),
                            SizedBox(width: 4),
                            Text('Dokumen', style: TextStyle(color: Colors.blue)),
                          ],
                        ),
                      ),
                    ),
                  ],
                ],
              ),
              trailing: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  IconButton(
                    icon: const Icon(Icons.check, color: Colors.green),
                    onPressed: () =>
                        _updateStatus(item['id'].toString(), 'Disetujui'),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close, color: Colors.red),
                    onPressed: () =>
                        _updateStatus(item['id'].toString(), 'Ditolak'),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.userName),
        backgroundColor: cs.primary,
        foregroundColor: Colors.white,
        elevation: 0,
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: Colors.white,
          labelColor: Colors.white,
          tabs: const [
            Tab(text: 'Riwayat Presensi'),
            Tab(text: 'Konfirmasi Pending'),
          ],
        ),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _loadData),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : TabBarView(
              controller: _tabController,
              children: [_buildHistoryTab(), _buildPendingTab()],
            ),
    );
  }
}
```

```dart
// pages/admin_user_list_page.dart (NO CHANGES - already handles nip_nisn display)
import 'dart:convert';
import 'package:flutter/material.dart';
import '../api/api_service.dart';
import 'admin_user_detail_page.dart';
class AdminUserListPage extends StatefulWidget {
  const AdminUserListPage({super.key});
  @override
  State<AdminUserListPage> createState() => _AdminUserListPageState();
}
class _AdminUserListPageState extends State<AdminUserListPage> {
  bool _loading = false;
  List<dynamic> _users = [];
  List<dynamic> _filteredUsers = [];
  final TextEditingController _searchC = TextEditingController();
  @override
  void initState() {
    super.initState();
    _loadUsers();
    _searchC.addListener(_filterUsers);
  }
  @override
  void dispose() {
    _searchC.dispose();
    super.dispose();
  }
  Future<void> _loadUsers() async {
    setState(() => _loading = true);
    try {
      final data = await ApiService.getUsers();
      if (data == null || data.isEmpty) {
        setState(() {
          _users = [];
          _filteredUsers = [];
        });
        return;
      }
      final filteredUsers = data.where((u) {
        final role = u['role']?.toString().toLowerCase() ?? '';
        final id = u['id']?.toString();
        final nama = u['nama_lengkap'] ?? u['nama'] ?? '';
        return role == 'user' && id != null && id.isNotEmpty && nama.isNotEmpty;
      }).toList();
      setState(() {
        _users = filteredUsers;
        _filteredUsers = filteredUsers;
      });
    } catch (e) {
      debugPrint('Error loading users: $e');
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('Gagal memuat list user: $e')));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }
  void _filterUsers() {
    final query = _searchC.text.toLowerCase().trim();
    if (query.isEmpty) {
      setState(() => _filteredUsers = _users);
      return;
    }
    setState(() {
      _filteredUsers = _users.where((u) {
        final nama = (u['nama_lengkap'] ?? u['nama'] ?? '').toLowerCase();
        final username = (u['username'] ?? '').toLowerCase();
        return nama.contains(query) || username.contains(query);
      }).toList();
    });
  }
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Kelola User Presensi'),
        backgroundColor: cs.primary,
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: _loadUsers,
          ),
        ],
      ),
      body: Column(
        children: [
          Container(
            padding: const EdgeInsets.all(16),
            child: TextField(
              controller: _searchC,
              decoration: InputDecoration(
                hintText: 'Cari user berdasarkan nama atau username...',
                prefixIcon: Icon(Icons.search, color: cs.primary),
                suffixIcon: _searchC.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear),
                        onPressed: () {
                          _searchC.clear();
                          _filterUsers();
                        },
                      )
                    : null,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide(color: cs.primary.withOpacity(0.5)),
                ),
                filled: true,
                fillColor: Colors.grey[50],
              ),
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        CircularProgressIndicator(),
                        SizedBox(height: 16),
                        Text('Memuat users...'),
                      ],
                    ),
                  )
                : RefreshIndicator(
                    onRefresh: _loadUsers,
                    child: _filteredUsers.isEmpty
                        ? Center(
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(
                                  Icons.people_outline,
                                  size: 80,
                                  color: Colors.grey[400],
                                ),
                                const SizedBox(height: 16),
                                Text(
                                  _searchC.text.isNotEmpty
                                      ? 'Tidak ditemukan user'
                                      : 'Belum ada user terdaftar',
                                  style: TextStyle(
                                    fontSize: 18,
                                    color: Colors.grey[600],
                                  ),
                                ),
                                if (_searchC.text.isEmpty) ...[
                                  const SizedBox(height: 8),
                                  ElevatedButton(
                                    onPressed: _loadUsers,
                                    child: const Text('Refresh'),
                                  ),
                                ],
                              ],
                            ),
                          )
                        : ListView.separated(
                            padding: const EdgeInsets.all(16),
                            itemCount: _filteredUsers.length,
                            separatorBuilder: (ctx, i) =>
                                const SizedBox(height: 8),
                            itemBuilder: (ctx, i) {
                              final u = _filteredUsers[i];
                              final nama =
                                  u['nama_lengkap'] ?? u['nama'] ?? 'Unknown';
                              final username = u['username'] ?? '';
                              final nip = u['nip_nisn']?.toString() ?? '';
                              final userId = u['id']?.toString() ?? '';
                              return Card(
                                elevation: 4,
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(16),
                                ),
                                child: InkWell(
                                  borderRadius: BorderRadius.circular(16),
                                  onTap: userId.isNotEmpty
                                      ? () {
                                          Navigator.push(
                                            context,
                                            MaterialPageRoute(
                                              builder: (_) =>
                                                  AdminUserDetailPage(
                                                    userId: userId,
                                                    userName: nama,
                                                  ),
                                            ),
                                          );
                                        }
                                      : null,
                                  child: Padding(
                                    padding: const EdgeInsets.all(16),
                                    child: Row(
                                      children: [
                                        CircleAvatar(
                                          radius: 30,
                                          backgroundColor: cs.primary
                                              .withOpacity(0.1),
                                          child: Text(
                                            username.isNotEmpty
                                                ? username[0].toUpperCase()
                                                : 'U',
                                            style: TextStyle(
                                              fontSize: 20,
                                              fontWeight: FontWeight.bold,
                                              color: cs.primary,
                                            ),
                                          ),
                                        ),
                                        const SizedBox(width: 16),
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment:
                                                CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                nama,
                                                style: const TextStyle(
                                                  fontSize: 18,
                                                  fontWeight: FontWeight.bold,
                                                ),
                                              ),
                                              const SizedBox(height: 4),
                                              Text(
                                                'Username: $username',
                                                style: TextStyle(
                                                  color: Colors.grey[600],
                                                ),
                                              ),
                                              if (nip.isNotEmpty)
                                                Text(
                                                  'NIP/NISN: $nip',
                                                  style: TextStyle(
                                                    color: Colors.grey[600],
                                                  ),
                                                ),
                                            ],
                                          ),
                                        ),
                                        Icon(
                                          Icons.arrow_forward_ios,
                                          color: cs.primary,
                                          size: 20,
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              );
                            },
                          ),
                  ),
          ),
        ],
      ),
    );
  }
}
```

```dart
// pages/dashboard_page.dart (UPDATED: For user role, replaced dropdown with 5 buttons: 4 biasa + 1 Penugasan; Penugasan shows bottom sheet with 3 sub-buttons)
import 'package:flutter/material.dart';
import '../models/user_model.dart';
class DashboardPage extends StatelessWidget {
  final UserModel user;
  const DashboardPage({super.key, required this.user});
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Dashboard'),
        backgroundColor: cs.primary,
        foregroundColor: Colors.white,
        elevation: 0,
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [cs.primary, cs.primary.withOpacity(0.8)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
          ),
        ),
        actions: [
          Center(
            child: Padding(
              padding: const EdgeInsets.only(right: 8.0),
              child: Chip(
                label: Text(
                  user.role.toUpperCase(),
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                backgroundColor: cs.primary.withOpacity(0.15),
                side: BorderSide(color: cs.primary, width: 1.5),
                avatar: Icon(Icons.shield, size: 16, color: cs.primary),
              ),
            ),
          ),
          IconButton(
            icon: const Icon(Icons.logout),
            onPressed: () {
              Navigator.pushNamedAndRemoveUntil(
                context,
                '/login',
                (route) => false,
              );
            },
          ),
        ],
      ),
      body: CustomScrollView(
        slivers: [
          SliverToBoxAdapter(
            child: Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [cs.primary.withOpacity(0.05), Colors.transparent],
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Halo, ${user.namaLengkap}',
                    style: TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.bold,
                      color: cs.primary,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Selamat datang di sistem presensi Skaduta',
                    style: TextStyle(color: Colors.grey[700], fontSize: 16),
                  ),
                  const SizedBox(height: 30),
                ],
              ),
            ),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (user.role == 'user') _buildUserSection(context),
                  if (user.role == 'admin') _buildAdminSection(context),
                  if (user.role == 'superadmin')
                    _buildSuperAdminSection(context),
                  const SizedBox(height: 20),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
  Widget _card({
    required IconData icon,
    required String title,
    required String subtitle,
    required VoidCallback onTap,
    Color? color,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      child: Material(
        elevation: 4,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          splashColor: color?.withOpacity(0.1) ?? Colors.blue.withOpacity(0.1),
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: (color ?? Colors.blue).withOpacity(0.1),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(icon, size: 32, color: color ?? Colors.blue),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        subtitle,
                        style: TextStyle(fontSize: 14, color: Colors.grey[600]),
                      ),
                    ],
                  ),
                ),
                const Icon(
                  Icons.arrow_forward_ios,
                  size: 16,
                  color: Colors.grey,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
  Widget _buildUserSection(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // UPDATED: 4 biasa buttons + 1 Penugasan
        _card(
          icon: Icons.login,
          title: 'Absen Masuk Biasa',
          subtitle: 'Absen masuk harian (otomatis disetujui)',
          onTap: () => _navigateToPresensi(context, 'Masuk'),
          color: Colors.green,
        ),
        _card(
          icon: Icons.logout,
          title: 'Absen Pulang Biasa',
          subtitle: 'Absen pulang harian (otomatis disetujui)',
          onTap: () => _navigateToPresensi(context, 'Pulang'),
          color: Colors.orange,
        ),
        _card(
          icon: Icons.fast_forward,
          title: 'Pulang Cepat Biasa',
          subtitle: 'Pulang lebih awal (otomatis disetujui)',
          onTap: () => _navigateToPresensi(context, 'Pulang Cepat'),
          color: Colors.blue,
        ),
        _card(
          icon: Icons.block,
          title: 'Izin Tidak Masuk',
          subtitle: 'Ajukan izin (perlu persetujuan admin)',
          onTap: () => _navigateToPresensi(context, 'Izin'),
          color: Colors.red,
        ),
        _card(
          icon: Icons.assignment,
          title: 'Penugasan',
          subtitle: 'Ajukan penugasan khusus (perlu persetujuan admin)',
          onTap: () => _showPenugasanSheet(context), // NEW: Show sub-options
          color: Colors.purple,
        ),
        _card(
          icon: Icons.history,
          title: 'Riwayat Presensi',
          subtitle: 'Lihat riwayat presensi kamu',
          onTap: () {
            Navigator.pushNamed(context, '/history', arguments: user);
          },
        ),
      ],
    );
  }
  // NEW: Navigate helper
  void _navigateToPresensi(BuildContext context, String jenis) {
    Navigator.pushNamed(
      context,
      '/presensi',
      arguments: {'user': user, 'jenis': jenis},
    );
  }
  // NEW: Bottom sheet for Penugasan sub-options
  void _showPenugasanSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Pilih Jenis Penugasan',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 20),
            _subCard(
              icon: Icons.login,
              title: 'Absen Masuk Penugasan',
              onTap: () {
                Navigator.pop(ctx);
                _navigateToPresensi(ctx, 'Penugasan_Masuk');
              },
              color: Colors.green,
            ),
            _subCard(
              icon: Icons.logout,
              title: 'Absen Pulang Penugasan',
              onTap: () {
                Navigator.pop(ctx);
                _navigateToPresensi(ctx, 'Penugasan_Pulang');
              },
              color: Colors.orange,
            ),
            _subCard(
              icon: Icons.assignment_turned_in,
              title: 'Penugasan Full Day',
              onTap: () {
                Navigator.pop(ctx);
                _navigateToPresensi(ctx, 'Penugasan_Full');
              },
              color: Colors.purple,
            ),
          ],
        ),
      ),
    );
  }
  Widget _subCard({
    required IconData icon,
    required String title,
    required VoidCallback onTap,
    required Color color,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: color.withOpacity(0.1),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(icon, color: color),
                ),
                const SizedBox(width: 16),
                Expanded(child: Text(title, style: const TextStyle(fontSize: 16))),
                const Icon(Icons.arrow_forward_ios, size: 16),
              ],
            ),
          ),
        ),
      ),
    );
  }
  Widget _buildAdminSection(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _card(
          icon: Icons.list_alt,
          title: 'Kelola User Presensi',
          subtitle: 'Lihat list user, histori per user, dan konfirmasi absensi',
          onTap: () {
            Navigator.pushNamed(context, '/admin-user-list');
          },
        ),
        _card(
          icon: Icons.verified_outlined,
          title: 'Konfirmasi Absensi',
          subtitle: 'Setujui / tolak presensi user secara global',
          onTap: () {
            Navigator.pushNamed(context, '/admin-presensi');
          },
        ),
      ],
    );
  }
  Widget _buildSuperAdminSection(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _card(
          icon: Icons.supervisor_account_outlined,
          title: 'Kelola User & Admin',
          subtitle: 'CRUD akun user dan admin, edit info, ganti password',
          onTap: () {
            Navigator.pushNamed(context, '/user-management');
          },
        ),
        _card(
          icon: Icons.list_alt,
          title: 'Kelola User Presensi',
          subtitle: 'Lihat list user, histori per user, dan konfirmasi absensi',
          onTap: () {
            Navigator.pushNamed(context, '/admin-user-list');
          },
        ),
        _card(
          icon: Icons.verified_outlined,
          title: 'Konfirmasi Absensi',
          subtitle: 'Setujui / tolak presensi user secara global',
          onTap: () {
            Navigator.pushNamed(context, '/admin-presensi');
          },
        ),
      ],
    );
  }
}
```

```dart
// pages/history_page.dart (UPDATED: Handle new fields informasi and dokumen)
import 'package:flutter/material.dart';
import '../api/api_service.dart';
import '../models/user_model.dart';
class HistoryPage extends StatefulWidget {
  final UserModel user;
  const HistoryPage({super.key, required this.user});
  @override
  State<HistoryPage> createState() => _HistoryPageState();
}
class _HistoryPageState extends State<HistoryPage> {
  bool _loading = false;
  List<dynamic> _items = [];
  String _filterJenis = 'All'; // All, Masuk, Pulang, Izin, Pulang Cepat, Penugasan_*
  @override
  void initState() {
    super.initState();
    _loadHistory();
  }
  Future<void> _loadHistory() async {
    setState(() => _loading = true);
    try {
      final data = await ApiService.getUserHistory(widget.user.id);
      setState(() => _items = data ?? []);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('Gagal ambil histori: $e')));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }
  List<dynamic> get _filteredItems {
    if (_filterJenis == 'All') return _items;
    return _items
        .where((item) => (item['jenis'] ?? '') == _filterJenis)
        .toList();
  }
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Riwayat Presensi'),
        backgroundColor: cs.primary,
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          DropdownButton<String>(
            value: _filterJenis,
            items: [
              'All',
              'Masuk',
              'Pulang',
              'Izin',
              'Pulang Cepat',
              'Penugasan_Masuk',
              'Penugasan_Pulang',
              'Penugasan_Full',
            ].map((j) => DropdownMenuItem(value: j, child: Text(j))).toList(),
            onChanged: (v) => setState(() => _filterJenis = v ?? 'All'),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _loadHistory,
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      'Total: ${_filteredItems.length}',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                  ),
                  Expanded(
                    child: _filteredItems.isEmpty
                        ? Center(
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(
                                  Icons.history,
                                  size: 64,
                                  color: Colors.grey,
                                ),
                                const SizedBox(height: 16),
                                Text(
                                  'Belum ada riwayat presensi',
                                  style: TextStyle(
                                    fontSize: 16,
                                    color: Colors.grey,
                                  ),
                                ),
                              ],
                            ),
                          )
                        : ListView.builder(
                            padding: const EdgeInsets.all(16),
                            itemCount: _filteredItems.length,
                            itemBuilder: (ctx, i) {
                              final item = _filteredItems[i];
                              final status = item['status'] ?? 'Pending';
                              Color statusColor = Colors.orange;
                              if (status == 'Disetujui')
                                statusColor = Colors.green;
                              if (status == 'Ditolak') statusColor = Colors.red;
                              final baseUrl = ApiService.baseUrl;
                              final fotoUrl = item['selfie'] != null && item['selfie'].isNotEmpty
                                  ? '$baseUrl/selfie/${item['selfie']}'
                                  : null;
                              final dokumenUrl = item['dokumen'] != null && item['dokumen'].isNotEmpty // NEW
                                  ? '$baseUrl/dokumen/${item['dokumen']}'
                                  : null;
                              return Card(
                                elevation: 2,
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                margin: const EdgeInsets.symmetric(vertical: 8),
                                child: ListTile(
                                  leading: Icon(
                                    _getIconForJenis(item['jenis'] ?? ''),
                                    color: _getColorForJenis(
                                      item['jenis'] ?? '',
                                    ),
                                  ),
                                  title: Text(
                                    item['jenis'] ?? '',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                  subtitle: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text('Tgl: ${item['created_at'] ?? ''}'),
                                      Text('Ket: ${item['keterangan'] ?? '-'}'),
                                      if (item['informasi'] != null && item['informasi'].isNotEmpty) // NEW
                                        Text('Info: ${item['informasi']}'),
                                      Text(
                                        'Status: $status',
                                        style: TextStyle(color: statusColor),
                                      ),
                                      if (dokumenUrl != null) ...[ // NEW
                                        const SizedBox(height: 4),
                                        GestureDetector(
                                          onTap: () => _showDokumen(dokumenUrl),
                                          child: Container(
                                            padding: const EdgeInsets.all(4),
                                            decoration: BoxDecoration(
                                              color: Colors.blue[50],
                                              borderRadius: BorderRadius.circular(4),
                                            ),
                                            child: const Row(
                                              mainAxisSize: MainAxisSize.min,
                                              children: [
                                                Icon(Icons.attachment, size: 16, color: Colors.blue),
                                                SizedBox(width: 4),
                                                Text('Dokumen', style: TextStyle(fontSize: 12, color: Colors.blue)),
                                              ],
                                            ),
                                          ),
                                        ),
                                      ],
                                    ],
                                  ),
                                  trailing: Icon(
                                    status == 'Disetujui'
                                        ? Icons.check_circle
                                        : status == 'Ditolak'
                                        ? Icons.cancel
                                        : Icons.pending,
                                    color: statusColor,
                                  ),
                                ),
                              );
                            },
                          ),
                  ),
                ],
              ),
            ),
    );
  }
  // NEW: Simple dokumen viewer
  void _showDokumen(String url) {
    showDialog(
      context: context,
      builder: (context) => Dialog(child: Image.network(url, fit: BoxFit.contain)),
    );
  }
  IconData _getIconForJenis(String jenis) {
    switch (jenis) {
      case 'Masuk':
      case 'Penugasan_Masuk':
        return Icons.login;
      case 'Pulang':
      case 'Penugasan_Pulang':
        return Icons.logout;
      case 'Izin':
        return Icons.block;
      case 'Pulang Cepat':
        return Icons.fast_forward;
      case 'Penugasan_Full':
        return Icons.assignment_turned_in;
      default:
        return Icons.schedule;
    }
  }
  Color _getColorForJenis(String jenis) {
    switch (jenis) {
      case 'Masuk':
      case 'Penugasan_Masuk':
        return Colors.green;
      case 'Pulang':
      case 'Penugasan_Pulang':
        return Colors.orange;
      case 'Izin':
        return Colors.red;
      case 'Pulang Cepat':
        return Colors.blue;
      case 'Penugasan_Full':
        return Colors.purple;
      default:
        return Colors.grey;
    }
  }
}
```

```dart
// pages/login_page.dart (NO CHANGES)
import 'package:flutter/material.dart';
import '../api/api_service.dart';
import '../models/user_model.dart';
class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}
class _LoginPageState extends State<LoginPage> {
  final _formKey = GlobalKey<FormState>();
  final _inputController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isLoading = false;
  bool _obscure = true;
  Future<void> _handleLogin() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _isLoading = true);
    try {
      final res = await ApiService.login(
        input: _inputController.text.trim(),
        password: _passwordController.text.trim(),
      );
      if (res['status'] == 'success') {
        final user = UserModel.fromJson(res['data']);
        if (!mounted) return;
        Navigator.pushReplacementNamed(context, '/dashboard', arguments: user);
      } else {
        _showSnack(res['message'] ?? 'Login gagal');
      }
    } catch (e) {
      _showSnack('Terjadi error: $e');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }
  void _showSnack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
  }
  @override
  void dispose() {
    _inputController.dispose();
    _passwordController.dispose();
    super.dispose();
  }
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [
              cs.primary,
              cs.primary.withOpacity(0.8),
              cs.secondary.withOpacity(0.7),
            ],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    // Header with logo/icon
                    const SizedBox(height: 24),
                    Text(
                      'Skaduta Presensi',
                      style: TextStyle(
                        fontSize: 32,
                        fontWeight: FontWeight.bold,
                        color: Colors.white,
                        shadows: [
                          Shadow(
                            offset: const Offset(1, 1),
                            blurRadius: 4,
                            color: Colors.black.withOpacity(0.3),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Silakan login untuk melanjutkan',
                      style: TextStyle(
                        fontSize: 16,
                        color: Colors.white.withOpacity(0.9),
                      ),
                    ),
                    const SizedBox(height: 32),
                    Card(
                      elevation: 12,
                      shadowColor: Colors.black.withOpacity(0.2),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Container(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(20),
                          gradient: LinearGradient(
                            colors: [Colors.white, cs.surface.withOpacity(0.8)],
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                          ),
                        ),
                        padding: const EdgeInsets.all(28),
                        child: Form(
                          key: _formKey,
                          child: Column(
                            children: [
                              TextFormField(
                                controller: _inputController,
                                decoration: InputDecoration(
                                  labelText: 'Username / NIP / NISN',
                                  prefixIcon: Icon(
                                    Icons.person_outline,
                                    color: cs.primary,
                                  ),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                  fillColor: Colors.white.withOpacity(0.6),
                                ),
                                validator: (v) {
                                  if (v == null || v.trim().isEmpty) {
                                    return 'Tidak boleh kosong';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 20),
                              TextFormField(
                                controller: _passwordController,
                                obscureText: _obscure,
                                decoration: InputDecoration(
                                  labelText: 'Password',
                                  prefixIcon: Icon(
                                    Icons.lock_outline,
                                    color: cs.primary,
                                  ),
                                  suffixIcon: IconButton(
                                    icon: Icon(
                                      _obscure
                                          ? Icons.visibility_off
                                          : Icons.visibility,
                                      color: cs.primary,
                                    ),
                                    onPressed: () {
                                      setState(() => _obscure = !_obscure);
                                    },
                                  ),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                  fillColor: Colors.white.withOpacity(0.6),
                                ),
                                validator: (v) {
                                  if (v == null || v.isEmpty) {
                                    return 'Password wajib diisi';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 28),
                              SizedBox(
                                width: double.infinity,
                                child: ElevatedButton(
                                  onPressed: _isLoading ? null : _handleLogin,
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: cs.primary,
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(
                                      vertical: 16,
                                    ),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(16),
                                    ),
                                    elevation: 6,
                                    shadowColor: cs.primary.withOpacity(0.4),
                                  ),
                                  child: _isLoading
                                      ? const SizedBox(
                                          width: 20,
                                          height: 20,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                            valueColor:
                                                AlwaysStoppedAnimation<Color>(
                                                  Colors.white,
                                                ),
                                          ),
                                        )
                                      : const Text(
                                          'Masuk Sekarang',
                                          style: TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 16,
                                          ),
                                        ),
                                ),
                              ),
                              const SizedBox(height: 16),
                              Align(
                                alignment: Alignment.centerRight,
                                child: TextButton(
                                  onPressed: () {
                                    Navigator.pushNamed(context, '/register');
                                  },
                                  child: Text(
                                    'Belum punya akun? Daftar di sini',
                                    style: TextStyle(color: cs.primary),
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 24),
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Column(
                        children: [
                          Text(
                            'Catatan:',
                            style: TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.bold,
                              color: Colors.white,
                            ),
                          ),
                          const SizedBox(height: 8),
                          RichText(
                            text: TextSpan(
                              style: TextStyle(
                                fontSize: 12,
                                color: Colors.white.withOpacity(0.9),
                              ),
                              children: const [
                                TextSpan(
                                  text:
                                      '• Login bisa pakai Username / NIP / NISN\n',
                                ),
                                TextSpan(
                                  text: '• Karyawan cukup pakai username',
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
```

```dart
// pages/presensi_page.dart (UPDATED: Accept initialJenis from args; conditional fields based on jenis (e.g., informasi/dokumen for Penugasan); skip radius for Penugasan; use updated API call)
import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:latlong2/latlong.dart';
import '../api/api_service.dart';
import '../models/user_model.dart';
class PresensiPage extends StatefulWidget {
  final UserModel user;
  final String initialJenis; // NEW: From dashboard
  const PresensiPage({super.key, required this.user, required this.initialJenis});
  @override
  State<PresensiPage> createState() => _PresensiPageState();
}
class _PresensiPageState extends State<PresensiPage> {
  Position? _position;
  late String _jenis; // NEW: Set from initial
  final TextEditingController _ketC = TextEditingController();
  final TextEditingController _infoC = TextEditingController(); // NEW: For informasi
  File? _selfieFile;
  File? _dokumenFile; // NEW: For dokumen
  bool _loading = false;
  final ImagePicker _picker = ImagePicker();
  // Koordinat SMK N 2 YK (sinkron sama PHP)
  final double sekolahLat = -7.777047019078815;
  final double sekolahLng = 110.3671540164373;
  final double maxRadius = 100; // meter
  // For sheet drag effects
  final DraggableScrollableController _sheetController =
      DraggableScrollableController();
  double _darkenValue = 0.0;
  static const double _initialSheetSize = 0.45;
  static const double _maxDarken = 0.15; // Subtle dark overlay
  @override
  void initState() {
    super.initState();
    _jenis = widget.initialJenis; // NEW
    _initLocation();
    _sheetController.addListener(() {
      final extent = _sheetController.size;
      final normalized =
          (extent - _initialSheetSize) / (1.0 - _initialSheetSize);
      setState(() {
        _darkenValue = (_maxDarken * normalized).clamp(0.0, _maxDarken);
      });
    });
  }
  @override
  void dispose() {
    _ketC.dispose();
    _infoC.dispose(); // NEW
    _sheetController.dispose();
    super.dispose();
  }
  Future<void> _initLocation() async {
    bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      _showSnack('Location service off, aktifkan dulu ya');
      return;
    }
    LocationPermission perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) {
      perm = await Geolocator.requestPermission();
      if (perm == LocationPermission.denied) {
        _showSnack('Izin lokasi ditolak');
        return;
      }
    }
    if (perm == LocationPermission.deniedForever) {
      _showSnack('Izin lokasi permanent ditolak, cek pengaturan hp');
      return;
    }
    final pos = await Geolocator.getCurrentPosition(
      desiredAccuracy: LocationAccuracy.high,
    );
    setState(() {
      _position = pos;
    });
  }
  double _distanceToSchool() {
    if (_position == null) return 999999;
    return Geolocator.distanceBetween(
      _position!.latitude,
      _position!.longitude,
      sekolahLat,
      sekolahLng,
    );
  }
  Future<void> _pickSelfie() async {
    final XFile? img = await _picker.pickImage(
      source: ImageSource.camera,
      preferredCameraDevice: CameraDevice.front,
      imageQuality: 70,
    );
    if (img != null) {
      setState(() {
        _selfieFile = File(img.path);
      });
      print('DEBUG: Selfie OK, size: ${await File(img.path).length()} bytes');
    }
  }
  // NEW: Pick dokumen (use gallery for doc/image)
  Future<void> _pickDokumen() async {
    final XFile? doc = await _picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 70,
    );
    if (doc != null) {
      setState(() {
        _dokumenFile = File(doc.path);
      });
      print('DEBUG: Dokumen OK, size: ${await File(doc.path).length()} bytes');
    }
  }
  Future<void> _submitPresensi() async {
    if (_position == null && !_isPenugasan) { // NEW: Skip location for Penugasan
      _showSnack('Lokasi belum terbaca');
      return;
    }
    final jarak = _distanceToSchool();
    final isInRadius = jarak <= maxRadius;
    if (!isInRadius && !_isPenugasan) { // NEW: Skip check for Penugasan
      _showSnack(
        'Kamu di luar jangkauan sekolah (±${jarak.toStringAsFixed(1)}m)',
      );
      return;
    }
    // Validasi keterangan hanya buat Izin/Pulang Cepat
    if ((_jenis == 'Izin' || _jenis == 'Pulang Cepat') &&
        _ketC.text.trim().isEmpty) {
      _showSnack('Keterangan wajib diisi untuk $_jenis!');
      return;
    }
    // NEW: Validasi informasi & dokumen for Penugasan
    if (_isPenugasan) {
      if (_infoC.text.trim().isEmpty) {
        _showSnack('Informasi penugasan wajib diisi!');
        return;
      }
      if (_dokumenFile == null) {
        _showSnack('Dokumen penugasan wajib diunggah!');
        return;
      }
    }
    setState(() => _loading = true);
    try {
      String base64Image = '';
      if (_selfieFile != null) {
        final bytes = await _selfieFile!.readAsBytes();
        base64Image = base64Encode(bytes);
        print('DEBUG: Base64 image length: ${base64Image.length}');
      } else {
        print('DEBUG: No selfie, sending empty');
      }
      String dokumenBase64 = ''; // NEW
      if (_dokumenFile != null) {
        final docBytes = await _dokumenFile!.readAsBytes();
        dokumenBase64 = base64Encode(docBytes);
        print('DEBUG: Base64 dokumen length: ${dokumenBase64.length}');
      }
      final res = await ApiService.submitPresensi(
        userId: widget.user.id,
        jenis: _jenis,
        keterangan: _ketC.text.trim(),
        informasi: _infoC.text.trim(), // NEW
        dokumenBase64: dokumenBase64, // NEW
        latitude: _position?.latitude.toString() ?? '0',
        longitude: _position?.longitude.toString() ?? '0',
        base64Image: base64Image,
      );
      print('DEBUG SUBMIT: Full response: ${jsonEncode(res)}');
      if (res['status'] == true) {
        _showSnack(res['message'] ?? 'Presensi berhasil!');
        _resetForm();
      } else {
        _showSnack(res['message'] ?? 'Gagal presensi');
      }
    } catch (e) {
      print('DEBUG SUBMIT: Error: $e');
      _showSnack('Error: $e');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }
  bool get _isPenugasan => _jenis.startsWith('Penugasan'); // NEW: Helper
  void _resetForm() {
    setState(() {
      _ketC.clear();
      _infoC.clear(); // NEW
      _selfieFile = null;
      _dokumenFile = null; // NEW
      // Keep jenis as is
    });
  }
  void _showSnack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: msg.contains('berhasil') ? Colors.green : Colors.red,
        duration: const Duration(seconds: 3),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
      ),
    );
  }
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final jarak = _distanceToSchool();
    final isInRadius = jarak <= maxRadius;
    final progress = (maxRadius - jarak.clamp(0, maxRadius)) / maxRadius;
    return Scaffold(
      extendBodyBehindAppBar: true,
      backgroundColor: Colors.transparent,
      appBar: AppBar( // NEW: Show jenis in title
        title: Text(_jenis.replaceAll('_', ' ')),
        backgroundColor: cs.primary,
        foregroundColor: Colors.white,
      ),
      body: _position == null && !_isPenugasan // NEW: Skip map for Penugasan if no location needed
          ? Container(
              color: Colors.grey[50],
              child: Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    CircularProgressIndicator(color: cs.primary),
                    const SizedBox(height: 16),
                    Text(
                      'Mendapatkan lokasi...',
                      style: TextStyle(color: Colors.black87),
                    ),
                  ],
                ),
              ),
            )
          : Stack(
              children: [
                if (!_isPenugasan) // NEW: Show map only if not Penugasan
                  Positioned.fill(
                    child: FlutterMap(
                      options: MapOptions(
                        initialCenter: LatLng(
                          -7.777047019078815,
                          110.3671540164373,
                        ),
                        initialZoom: 17.0,
                        interactionOptions: const InteractionOptions(
                          flags: InteractiveFlag.all,
                        ),
                      ),
                      children: [
                        TileLayer(
                          urlTemplate:
                              'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                        ),
                        MarkerLayer(
                          markers: [
                            Marker(
                              point: const LatLng(
                                -7.777047019078815,
                                110.3671540164373,
                              ),
                              width: 40,
                              height: 40,
                              child: Container(
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  color: cs.primary,
                                  boxShadow: [
                                    BoxShadow(
                                      color: cs.primary.withOpacity(0.3),
                                      blurRadius: 8,
                                    ),
                                  ],
                                ),
                                child: Icon(
                                  Icons.school,
                                  color: Colors.white,
                                  size: 20,
                                ),
                              ),
                            ),
                            Marker(
                              point: LatLng(
                                _position!.latitude,
                                _position!.longitude,
                              ),
                              width: 40,
                              height: 40,
                              child: Container(
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  color: isInRadius ? Colors.green : Colors.red,
                                  boxShadow: [
                                    BoxShadow(
                                      color:
                                          (isInRadius ? Colors.green : Colors.red)
                                              .withOpacity(0.3),
                                      blurRadius: 8,
                                    ),
                                  ],
                                ),
                                child: Icon(
                                  Icons.my_location,
                                  color: Colors.white,
                                  size: 20,
                                ),
                              ),
                            ),
                          ],
                        ),
                        // Polygon for school area
                        PolygonLayer(
                          polygons: [
                            Polygon(
                              points: [
                                LatLng(sekolahLat - 0.001, sekolahLng - 0.001),
                                LatLng(sekolahLat - 0.001, sekolahLng + 0.001),
                                LatLng(sekolahLat + 0.001, sekolahLng + 0.001),
                                LatLng(sekolahLat + 0.001, sekolahLng - 0.001),
                              ],
                              color: cs.primary.withOpacity(0.2),
                              borderColor: cs.primary,
                              borderStrokeWidth: 2,
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                // ✨ DARK OVERLAY saat sheet dragged up (Ignore pointers to allow map interaction)
                IgnorePointer(
                  ignoring: true,
                  child: Positioned.fill(
                    child: AnimatedOpacity(
                      duration: const Duration(milliseconds: 300),
                      curve: Curves.easeInOut,
                      opacity: _darkenValue > 0 ? 1.0 : 0.0,
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 300),
                        curve: Curves.easeInOut,
                        color: Colors.black.withOpacity(_darkenValue),
                      ),
                    ),
                  ),
                ),
                // 📱 DRAGGABLE SHEET FOR FORM (Bottom popup) - with smoother physics
                DraggableScrollableSheet(
                  controller: _sheetController,
                  initialChildSize: _initialSheetSize,
                  minChildSize: 0.4,
                  maxChildSize: 0.95,
                  snap: true,
                  snapSizes: const [0.45, 0.95],
                  builder: (context, scrollController) {
                    return Container(
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: const BorderRadius.vertical(
                          top: Radius.circular(24),
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withOpacity(0.08),
                            blurRadius: 25,
                            offset: const Offset(0, -8),
                          ),
                        ],
                      ),
                      child: SingleChildScrollView(
                        controller: scrollController,
                        physics: const ClampingScrollPhysics(),
                        padding: const EdgeInsets.all(20.0),
                        child: Column(
                          children: [
                            // Handle bar for drag - cooler design
                            Container(
                              margin: const EdgeInsets.symmetric(vertical: 8),
                              height: 5,
                              width: 50,
                              decoration: BoxDecoration(
                                color: Colors.grey[300],
                                borderRadius: BorderRadius.circular(3),
                              ),
                            ),
                            if (!_isPenugasan) // NEW: Show radius only if not Penugasan
                              _buildRadiusCard(jarak, isInRadius, progress, cs),
                            const SizedBox(height: 20),
                            if (!(_jenis == 'Izin' || _jenis == 'Pulang Cepat' || _isPenugasan)) // NEW: No jenis dropdown, fixed from args
                              const Text('Jenis: ${_jenis}'), // Show fixed jenis
                            if (_jenis == 'Izin' || _jenis == 'Pulang Cepat') ...[
                              _buildKeterangan(cs),
                              const SizedBox(height: 20),
                            ],
                            if (_isPenugasan) ...[ // NEW: Informasi field
                              _buildInformasi(cs),
                              const SizedBox(height: 20),
                            ],
                            _buildSelfie(cs),
                            if (_isPenugasan) ...[ // NEW: Dokumen field
                              const SizedBox(height: 20),
                              _buildDokumen(cs),
                            ],
                            const SizedBox(height: 28),
                            _buildSubmitButtons(cs),
                            const SizedBox(height: 30), // Extra space
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ],
            ),
    );
  }
  // ================== WIDGETS WITH LIGHT GLASSMORPHISM ==================
  BoxDecoration _glassDecoration() {
    return BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(20),
      border: Border.all(color: Colors.grey[200]!),
      boxShadow: [
        BoxShadow(
          color: Colors.black.withOpacity(0.04),
          blurRadius: 12,
          offset: const Offset(0, 4),
        ),
      ],
    );
  }
  Widget _buildRadiusCard(
    double jarak,
    bool isInRadius,
    double progress,
    ColorScheme cs,
  ) {
    return Container(
      width: double.infinity,
      decoration: _glassDecoration(),
      padding: const EdgeInsets.all(20),
      child: Column(
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: (isInRadius ? Colors.green : Colors.red).withOpacity(
                    0.1,
                  ),
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: isInRadius ? Colors.green : Colors.red,
                    width: 2,
                  ),
                ),
                child: Icon(
                  isInRadius ? Icons.check_circle : Icons.cancel,
                  color: isInRadius ? Colors.green : Colors.red,
                  size: 28,
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isInRadius ? 'Dalam Area Sekolah' : 'Di Luar Area',
                      style: const TextStyle(
                        color: Colors.black87,
                        fontWeight: FontWeight.bold,
                        fontSize: 18,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Jarak: ${jarak.toStringAsFixed(1)} m',
                      style: TextStyle(color: Colors.black54, fontSize: 14),
                    ),
                  ],
                ),
              ),
            ],
          ),
          // progress bar
          // const SizedBox(height: 16),
          // SizedBox(
          // height: 6,
          // child: ClipRRect(
          // borderRadius: BorderRadius.circular(4),
          // child: LinearProgressIndicator(
          // value: progress,
          // backgroundColor: Colors.grey[200],
          // valueColor: AlwaysStoppedAnimation<Color>(
          // isInRadius ? Colors.green : Colors.red,
          // ),
          // ),
          // ),
          // ),
        ],
      ),
    );
  }
  // REMOVED: Jenis dropdown - now fixed
  Widget _buildKeterangan(ColorScheme cs) {
    return Container(
      decoration: _glassDecoration(),
      padding: const EdgeInsets.all(16),
      child: TextField(
        controller: _ketC,
        maxLines: 3,
        style: const TextStyle(color: Colors.black87),
        decoration: InputDecoration(
          labelText: 'Keterangan (alasan)',
          labelStyle: const TextStyle(color: Colors.black54),
          helperText: 'Wajib diisi untuk jenis ini',
          helperStyle: const TextStyle(color: Colors.black54),
          border: InputBorder.none,
          prefixIcon: Icon(Icons.note, color: cs.primary),
          filled: true,
          fillColor: Colors.transparent,
        ),
      ),
    );
  }
  // NEW: Informasi field for Penugasan
  Widget _buildInformasi(ColorScheme cs) {
    return Container(
      decoration: _glassDecoration(),
      padding: const EdgeInsets.all(16),
      child: TextField(
        controller: _infoC,
        maxLines: 4,
        style: const TextStyle(color: Colors.black87),
        decoration: InputDecoration(
          labelText: 'Informasi Penugasan (detail tugas)',
          labelStyle: const TextStyle(color: Colors.black54),
          helperText: 'Wajib diisi, termasuk deskripsi penugasan',
          helperStyle: const TextStyle(color: Colors.black54),
          border: InputBorder.none,
          prefixIcon: Icon(Icons.info_outline, color: cs.primary),
          filled: true,
          fillColor: Colors.transparent,
        ),
      ),
    );
  }
  Widget _buildSelfie(ColorScheme cs) {
    return Container(
      decoration: _glassDecoration(),
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          OutlinedButton.icon(
            onPressed: _pickSelfie,
            style: OutlinedButton.styleFrom(
              side: BorderSide(color: cs.primary, width: 2),
              foregroundColor: cs.primary,
              padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
            ),
            icon: Icon(Icons.camera_alt_outlined, color: cs.primary),
            label: Text(
              'Ambil Selfie (Opsional)',
              style: TextStyle(color: cs.primary),
            ),
          ),
          if (_selfieFile != null) ...[
            const SizedBox(height: 16),
            ClipRRect(
              borderRadius: BorderRadius.circular(16),
              child: Image.file(
                _selfieFile!,
                height: 200,
                width: double.infinity,
                fit: BoxFit.cover,
              ),
            ),
          ],
        ],
      ),
    );
  }
  // NEW: Dokumen picker for Penugasan
  Widget _buildDokumen(ColorScheme cs) {
    return Container(
      decoration: _glassDecoration(),
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          OutlinedButton.icon(
            onPressed: _pickDokumen,
            style: OutlinedButton.styleFrom(
              side: BorderSide(color: cs.primary, width: 2),
              foregroundColor: cs.primary,
              padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
            ),
            icon: Icon(Icons.attachment, color: cs.primary),
            label: const Text(
              'Unggah Dokumen Penugasan (Wajib)',
              style: TextStyle(color: Colors.red), // Emphasize required
            ),
          ),
          if (_dokumenFile != null) ...[
            const SizedBox(height: 16),
            ClipRRect(
              borderRadius: BorderRadius.circular(16),
              child: Image.file(
                _dokumenFile!,
                height: 200,
                width: double.infinity,
                fit: BoxFit.cover,
              ),
            ),
          ],
        ],
      ),
    );
  }
  Widget _buildSubmitButtons(ColorScheme cs) {
    return Row(
      children: [
        Expanded(
          child: AnimatedScale(
            scale: _loading ? 0.98 : 1.0,
            duration: const Duration(milliseconds: 200),
            child: FilledButton.icon(
              onPressed: _loading ? null : _submitPresensi,
              icon: _loading
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                      ),
                    )
                  : Icon(Icons.send, color: Colors.white),
              label: Text(
                _loading ? 'Mengirim...' : 'Kirim Presensi',
                style: const TextStyle(color: Colors.white),
              ),
              style: FilledButton.styleFrom(
                backgroundColor: cs.primary,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16),
                ),
                elevation: 3,
              ),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Container(
          decoration: BoxDecoration(
            color: Colors.grey[100],
            borderRadius: BorderRadius.circular(12),
          ),
          child: IconButton(
            onPressed: _resetForm,
            icon: Icon(Icons.refresh, color: cs.primary),
            style: IconButton.styleFrom(
              backgroundColor: Colors.transparent,
              padding: const EdgeInsets.all(12),
            ),
            tooltip: 'Reset Form',
          ),
        ),
      ],
    );
  }
}
```

```dart
// pages/register_page.dart (UPDATED: Send isKaryawan to API; NIP required if not karyawan)
import 'package:flutter/material.dart';
import '../api/api_service.dart';
class RegisterPage extends StatefulWidget {
  const RegisterPage({super.key});
  @override
  State<RegisterPage> createState() => _RegisterPageState();
}
class _RegisterPageState extends State<RegisterPage> {
  final _formKey = GlobalKey<FormState>();
  final _usernameC = TextEditingController();
  final _namaC = TextEditingController();
  final _nipNisnC = TextEditingController();
  final _passwordC = TextEditingController();
  String _role = 'user';
  bool _isKaryawan = false; // Controls NIP requirement
  bool _isLoading = false;
  bool _obscure = true;
  Future<void> _handleRegister() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _isLoading = true);
    try {
      final res = await ApiService.register(
        username: _usernameC.text.trim(),
        namaLengkap: _namaC.text.trim(),
        nipNisn: _isKaryawan ? '' : _nipNisnC.text.trim(), // Empty if karyawan
        password: _passwordC.text.trim(),
        role: _role,
        isKaryawan: _isKaryawan, // NEW: Send to PHP
      );
      if (res['status'] == 'success') {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Registrasi berhasil, silakan login')),
        );
        Navigator.pop(context);
      } else {
        _showSnack(res['message'] ?? 'Gagal mendaftar');
      }
    } catch (e) {
      _showSnack('Terjadi error: $e');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }
  void _showSnack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
  }
  @override
  void dispose() {
    _usernameC.dispose();
    _namaC.dispose();
    _nipNisnC.dispose();
    _passwordC.dispose();
    super.dispose();
  }
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text('Daftar Akun'),
        elevation: 0,
        backgroundColor: Colors.transparent,
        foregroundColor: Colors.white,
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [cs.primary, cs.primary.withOpacity(0.8)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [
              cs.surfaceVariant.withOpacity(0.5),
              cs.surface,
              cs.surfaceVariant.withOpacity(0.3),
            ],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 500),
                child: Column(
                  children: [
                    // Header with icon
                    Hero(
                      tag: 'register_header',
                      child: Container(
                        padding: const EdgeInsets.all(24),
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            colors: [cs.primary, cs.secondary],
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                          ),
                          borderRadius: BorderRadius.circular(10),
                          boxShadow: [
                            BoxShadow(
                              color: cs.primary.withOpacity(0.3),
                              blurRadius: 20,
                              offset: const Offset(0, 10),
                            ),
                          ],
                        ),
                        child: Column(
                          children: [
                            const SizedBox(height: 8),
                            Text(
                              'Buat Akun Baru',
                              style: TextStyle(
                                fontSize: 24,
                                fontWeight: FontWeight.bold,
                                color: Colors.white,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Bergabunglah dengan Skaduta Presensi',
                              style: TextStyle(
                                fontSize: 14,
                                color: Colors.white.withOpacity(0.9),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 24),
                    Card(
                      elevation: 8,
                      shadowColor: cs.primary.withOpacity(0.2),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Container(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(20),
                          gradient: LinearGradient(
                            colors: [Colors.white, cs.surface.withOpacity(0.5)],
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                          ),
                        ),
                        padding: const EdgeInsets.all(24),
                        child: Form(
                          key: _formKey,
                          child: Column(
                            children: [
                              TextFormField(
                                controller: _usernameC,
                                decoration: InputDecoration(
                                  labelText: 'Username',
                                  prefixIcon: Icon(
                                    Icons.person_outline,
                                    color: cs.primary,
                                  ),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                  fillColor: Colors.white.withOpacity(0.7),
                                ),
                                validator: (v) {
                                  if (v == null || v.trim().isEmpty) {
                                    return 'Username wajib diisi';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 16),
                              TextFormField(
                                controller: _namaC,
                                decoration: InputDecoration(
                                  labelText: 'Nama Lengkap',
                                  prefixIcon: Icon(
                                    Icons.badge_outlined,
                                    color: cs.primary,
                                  ),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                  fillColor: Colors.white.withOpacity(0.7),
                                ),
                                validator: (v) {
                                  if (v == null || v.trim().isEmpty) {
                                    return 'Nama wajib diisi';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 16),
                              Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: Colors.white.withOpacity(0.7),
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(
                                    color: cs.primary.withOpacity(0.2),
                                  ),
                                ),
                                child: CheckboxListTile(
                                  value: _isKaryawan,
                                  onChanged: (val) {
                                    setState(() => _isKaryawan = val ?? false);
                                  },
                                  title: Text(
                                    'Saya Karyawan',
                                    style: TextStyle(color: cs.primary),
                                  ),
                                  subtitle: const Text(
                                    'Jika karyawan, NIP/NISN boleh dikosongkan',
                                    style: TextStyle(fontSize: 12),
                                  ),
                                  controlAffinity:
                                      ListTileControlAffinity.leading,
                                  contentPadding: EdgeInsets.zero,
                                  dense: true,
                                ),
                              ),
                              if (!_isKaryawan) ...[
                                const SizedBox(height: 8),
                                TextFormField(
                                  controller: _nipNisnC,
                                  decoration: InputDecoration(
                                    labelText: 'NIP / NISN',
                                    prefixIcon: Icon(
                                      Icons.credit_card_outlined,
                                      color: cs.primary,
                                    ),
                                    border: OutlineInputBorder(
                                      borderRadius: BorderRadius.circular(12),
                                    ),
                                    filled: true,
                                    fillColor: Colors.white.withOpacity(0.7),
                                  ),
                                  validator: (v) {
                                    if (!_isKaryawan) {
                                      if (v == null || v.trim().isEmpty) {
                                        return 'NIP/NISN wajib untuk guru';
                                      }
                                    }
                                    return null;
                                  },
                                ),
                              ],
                              const SizedBox(height: 16),
                              TextFormField(
                                controller: _passwordC,
                                obscureText: _obscure,
                                decoration: InputDecoration(
                                  labelText: 'Password',
                                  prefixIcon: Icon(
                                    Icons.lock_outline,
                                    color: cs.primary,
                                  ),
                                  suffixIcon: IconButton(
                                    icon: Icon(
                                      _obscure
                                          ? Icons.visibility_off
                                          : Icons.visibility,
                                      color: cs.primary,
                                    ),
                                    onPressed: () {
                                      setState(() => _obscure = !_obscure);
                                    },
                                  ),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                  fillColor: Colors.white.withOpacity(0.7),
                                ),
                                validator: (v) {
                                  if (v == null || v.isEmpty) {
                                    return 'Password wajib diisi';
                                  }
                                  if (v.length < 4) {
                                    return 'Minimal 4 karakter';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 16),
                              DropdownButtonFormField<String>(
                                value: _role,
                                decoration: InputDecoration(
                                  labelText: 'Role',
                                  prefixIcon: Icon(
                                    Icons.security_outlined,
                                    color: cs.primary,
                                  ),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                  fillColor: Colors.white.withOpacity(0.7),
                                ),
                                items: const [
                                  DropdownMenuItem(
                                    value: 'user',
                                    child: Text('User (Guru / Karyawan)'),
                                  ),
                                  DropdownMenuItem(
                                    value: 'admin',
                                    child: Text('Admin'),
                                  ),
                                  DropdownMenuItem(
                                    value: 'superadmin',
                                    child: Text('Super Admin'),
                                  ),
                                ],
                                onChanged: (val) {
                                  if (val == null) return;
                                  setState(() => _role = val);
                                },
                              ),
                              const SizedBox(height: 24),
                              SizedBox(
                                width: double.infinity,
                                child: ElevatedButton(
                                  onPressed: _isLoading
                                      ? null
                                      : _handleRegister,
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: cs.primary,
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(
                                      vertical: 16,
                                    ),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(16),
                                    ),
                                    elevation: 4,
                                    shadowColor: cs.primary.withOpacity(0.3),
                                  ),
                                  child: _isLoading
                                      ? const SizedBox(
                                          width: 20,
                                          height: 20,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                            valueColor:
                                                AlwaysStoppedAnimation<Color>(
                                                  Colors.white,
                                                ),
                                          ),
                                        )
                                      : const Text(
                                          'Daftar Sekarang',
                                          style: TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 16,
                                          ),
                                        ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
```

```dart
// pages/user_management_page.dart (NO CHANGES)
import 'dart:convert';
import 'package:flutter/material.dart';
import '../api/api_service.dart';
class UserManagementPage extends StatefulWidget {
  const UserManagementPage({super.key});
  @override
  State<UserManagementPage> createState() => _UserManagementPageState();
}
class _UserManagementPageState extends State<UserManagementPage> {
  bool _isLoading = false;
  List<dynamic> _users = [];
  @override
  void initState() {
    super.initState();
    _loadUsers();
  }
  Future<void> _loadUsers() async {
    setState(() => _isLoading = true);
    try {
      final data = await ApiService.getUsers();
      setState(() => _users = data);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('Gagal memuat user: $e')));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }
  Future<void> _deleteUser(String id, String role) async {
    if (role == 'superadmin') {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Tidak boleh menghapus superadmin')),
      );
      return;
    }
    final confirm = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Hapus User'),
        content: const Text('Yakin ingin menghapus user ini?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
    if (confirm != true) return;
    final res = await ApiService.deleteUser(id);
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(res['message'] ?? 'User dihapus')));
    _loadUsers();
  }
  Future<void> _editUser(Map<String, dynamic> user) async {
    final usernameC = TextEditingController(text: user['username']);
    final namaC = TextEditingController(text: user['nama_lengkap']);
    final passwordC = TextEditingController(); // Baru untuk password
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        final bottom = MediaQuery.of(ctx).viewInsets.bottom;
        return Padding(
          padding: EdgeInsets.fromLTRB(16, 16, 16, bottom + 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'Edit User',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: usernameC,
                decoration: const InputDecoration(
                  labelText: 'Username',
                  prefixIcon: Icon(Icons.person_outline),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: namaC,
                decoration: const InputDecoration(
                  labelText: 'Nama Lengkap',
                  prefixIcon: Icon(Icons.badge_outlined),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: passwordC,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Password Baru (kosongkan jika tidak ganti)',
                  prefixIcon: Icon(Icons.lock_outline),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () async {
                    final res = await ApiService.updateUser(
                      id: user['id'].toString(),
                      username: usernameC.text.trim(),
                      namaLengkap: namaC.text.trim(),
                      password: passwordC.text.trim(),
                    );
                    final ok = res['status'] == 'success';
                    if (ctx.mounted) {
                      Navigator.pop(ctx, ok);
                    }
                  },
                  child: const Text('Simpan Perubahan'),
                ),
              ),
            ],
          ),
        );
      },
    );
    if (saved == true) {
      _loadUsers();
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('User diperbarui')));
    }
  }
  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Kelola User & Admin'),
        backgroundColor: cs.primary,
        foregroundColor: Colors.white,
        elevation: 0,
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _loadUsers,
              child: ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: _users.length,
                itemBuilder: (ctx, index) {
                  final u = _users[index];
                  final role = (u['role'] ?? '').toString();
                  Color badgeColor;
                  String roleLabel;
                  switch (role) {
                    case 'admin':
                      badgeColor = Colors.blue;
                      roleLabel = 'ADMIN';
                      break;
                    case 'superadmin':
                      badgeColor = Colors.red;
                      roleLabel = 'SUPERADMIN';
                      break;
                    default:
                      badgeColor = Colors.green;
                      roleLabel = 'USER';
                  }
                  return Card(
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16),
                    ),
                    elevation: 2,
                    margin: const EdgeInsets.symmetric(vertical: 8),
                    child: ListTile(
                      leading: CircleAvatar(
                        backgroundColor: badgeColor.withOpacity(0.2),
                        child: Text(
                          (u['username'] ?? '?')
                              .toString()
                              .substring(0, 1)
                              .toUpperCase(),
                          style: TextStyle(color: badgeColor),
                        ),
                      ),
                      title: Text(u['nama_lengkap'] ?? ''),
                      subtitle: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(u['username'] ?? ''),
                          if ((u['nip_nisn'] ?? '').toString().isNotEmpty)
                            Text(
                              'NIP/NISN: ${u['nip_nisn']}',
                              style: const TextStyle(fontSize: 11),
                            ),
                        ],
                      ),
                      trailing: Wrap(
                        spacing: 4,
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 4,
                            ),
                            decoration: BoxDecoration(
                              color: badgeColor.withOpacity(0.15),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Text(
                              roleLabel,
                              style: TextStyle(
                                fontSize: 10,
                                color: badgeColor,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                          IconButton(
                            icon: const Icon(Icons.edit_outlined),
                            onPressed: () => _editUser(u),
                          ),
                          IconButton(
                            icon: const Icon(Icons.delete_outline),
                            onPressed: () =>
                                _deleteUser(u['id'].toString(), role),
                            color: cs.error,
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
            ),
    );
  }
}
```

```sql
-- Schema (FINAL: Validated - Expanded ENUM for jenis to include Penugasan types; added 'informasi' TEXT and 'dokumen' VARCHAR(255) to absensi; no new tables needed. Compatible with MySQL.)
-- Note: ENUM for jenis uses strings like 'Penugasan_Masuk' for easy filtering. Status auto-set in PHP for biasa types.

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

-- Example insert for testing (optional):
-- INSERT INTO users (username, nama_lengkap, nip_nisn, password, role) VALUES ('testuser', 'Test User', '123456', '$2y$10$examplehash', 'user');
-- INSERT INTO absensi (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status) VALUES (1, 'Masuk', '', '', '', '', '-7.777', '110.367', 'Disetujui');
```