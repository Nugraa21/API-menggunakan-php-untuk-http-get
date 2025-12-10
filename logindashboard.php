<?php
session_start();
require_once 'encryption.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$conn = new mysqli("localhost", "root", "081328nugra", "database_smk_4");
if ($conn->connect_error) die("Koneksi gagal");

$error = '';
if ($_POST) {
    $username = $conn->real_escape_string($_POST['username']);
    $pass = $_POST['password'];

    $res = $conn->query("SELECT id, password, nama_lengkap, role FROM users WHERE username = '$username' LIMIT 1");
    if ($res->num_rows > 0) {
        $user = $res->fetch_assoc();
        if (Encryption::decrypt($user['password']) === $pass) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nama'] = $user['nama_lengkap'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            header("Location: index.php");
            exit;
        } else $error = "Password salah!";
    } else $error = "Username tidak ditemukan!";
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login • Skaduta Presensi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
</head>
<body class="h-full bg-gradient-to-br from-teal-500 to-cyan-600 flex items-center justify-center">
<div class="bg-white rounded-2xl shadow-2xl p-10 w-full max-w-md">
    <div class="text-center mb-8">
        <i class="fas fa-building text-6xl text-teal-600 mb-4"></i>
        <h1 class="text-3xl font-bold text-gray-800">Skaduta Presensi</h1>
        <p class="text-gray-600">Sistem Presensi Digital Perusahaan</p>
    </div>
    <?php if($error): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= $error ?></div>
    <?php endif; ?>
    <form method="POST" class="space-y-6">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
            <input type="text" name="username" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
            <input type="password" name="password" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent">
        </div>
        <button type="submit" class="w-full bg-teal-600 text-white py-3 rounded-lg font-semibold hover:bg-teal-700 transition">
            Masuk ke Dashboard
        </button>
    </form>
    <p class="text-center text-xs text-gray-500 mt-8">© <?= date('Y') ?> Skaduta Presensi • All rights reserved</p>
</div>
</body>
</html>