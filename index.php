<?php
// Koneksi database
$conn = new mysqli("localhost", "root", "081328nugra", "database_smk_4");
if ($conn->connect_error) {
    die("Koneksi database gagal!");
}

date_default_timezone_set('Asia/Jakarta');

// Ambil data statistik
$total_pengguna = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?? 0;
$total_absensi = $conn->query("SELECT COUNT(*) FROM absensi")->fetch_row()[0] ?? 0;
$absen_hari_ini = $conn->query("SELECT COUNT(*) FROM absensi WHERE DATE(created_at) = CURDATE()")->fetch_row()[0] ?? 0;
$pending = $conn->query("SELECT COUNT(*) FROM absensi WHERE status = 'Pending'")->fetch_row()[0] ?? 0;
$disetujui = $conn->query("SELECT COUNT(*) FROM absensi WHERE status = 'Disetujui'")->fetch_row()[0] ?? 0;
$ditolak = $conn->query("SELECT COUNT(*) FROM absensi WHERE status = 'Ditolak'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skaduta Presensi - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fb 0%, #e4edf7 100%);
            min-height: 100vh;
            color: #2d2d2d;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container { padding: 60px 20px; }
        .header-title {
            text-align: center;
            margin-bottom: 50px;
        }
        .header-title h1 {
            font-size: 2.8rem;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 10px;
        }
        .header-title p {
            font-size: 1.2rem;
            color: #555;
            font-weight: 400;
        }
        .card-stat {
            border: none;
            border-radius: 18px;
            padding: 30px 20px;
            background: white;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
        }
        .card-stat:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.12);
        }
        .card-stat i {
            font-size: 3.2rem;
            margin-bottom: 18px;
            background: linear-gradient(45deg, #ff7b00, #ff9a3d);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .card-stat h2 {
            font-size: 2.4rem;
            font-weight: 700;
            color: #222;
            margin: 12px 0 8px;
        }
        .card-stat p {
            margin: 0;
            font-size: 1.05rem;
            color: #666;
            font-weight: 500;
        }
        footer {
            text-align: center;
            margin-top: 90px;
            padding: 20px;
            color: #777;
            font-size: 0.95rem;
        }
        .badge-pending { background: #ffc107; color: #000; }
        .badge-approved { background: #28a745; }
        .badge-rejected { background: #dc3545; }
        @media (max-width: 768px) {
            .header-title h1 { font-size: 2.3rem; }
            .card-stat h2 { font-size: 2rem; }
            .card-stat i { font-size: 2.8rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header-title">
            <h1>Skaduta Presensi</h1>
            <p>Sistem Presensi Digital Modern & Efisien</p>
        </div>

        <!-- Statistik Cards -->
        <div class="row g-4">
            <div class="col-md-4 col-12">
                <div class="card-stat">
                    <i class="fas fa-users"></i>
                    <h2><?= number_format($total_pengguna) ?></h2>
                    <p>Total Pengguna</p>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="card-stat">
                    <i class="fas fa-clipboard-check"></i>
                    <h2><?= number_format($total_absensi) ?></h2>
                    <p>Total Absensi</p>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="card-stat">
                    <i class="fas fa-calendar-day"></i>
                    <h2><?= number_format($absen_hari_ini) ?></h2>
                    <p>Absen Hari Ini</p>
                </div>
            </div>

            <div class="col-md-4 col-12">
                <div class="card-stat">
                    <i class="fas fa-check-circle"></i>
                    <h2><?= number_format($disetujui) ?></h2>
                    <p><span class="badge badge-approved">Disetujui</span></p>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="card-stat">
                    <i class="fas fa-clock"></i>
                    <h2><?= number_format($pending) ?></h2>
                    <p><span class="badge badge-pending">Pending</span></p>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="card-stat">
                    <i class="fas fa-times-circle"></i>
                    <h2><?= number_format($ditolak) ?></h2>
                    <p><span class="badge badge-rejected">Ditolak</span></p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer>
            <p><strong>© <?= date("Y") ?> Skaduta Presensi</strong> — 
            </p>
        </footer>
    </div>
</body>
</html>