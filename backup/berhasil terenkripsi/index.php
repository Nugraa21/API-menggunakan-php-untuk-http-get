<?php
$conn = new mysqli("localhost", "root", "081328nugra", "database_smk_4");
if ($conn->connect_error) die("Koneksi database gagal!");

date_default_timezone_set('Asia/Jakarta');
$today = date('Y-m-d');
$this_month = date('Y-m');
$year = date('Y');

// === STATISTIK UTAMA ===
$total_karyawan = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetch_row()[0] ?? 0;
$total_admin = $conn->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin')")->fetch_row()[0] ?? 0;
$total_pengguna = $total_karyawan + $total_admin;

$absen_hari_ini = $conn->query("SELECT COUNT(*) FROM absensi WHERE DATE(created_at) = '$today'")->fetch_row()[0] ?? 0;
$absen_bulan_ini = $conn->query("SELECT COUNT(*) FROM absensi WHERE DATE_FORMAT(created_at, '%Y-%m') = '$this_month'")->fetch_row()[0] ?? 0;
$total_absensi = $conn->query("SELECT COUNT(*) FROM absensi")->fetch_row()[0] ?? 0;

$pending = $conn->query("SELECT COUNT(*) FROM absensi WHERE status = 'Pending'")->fetch_row()[0] ?? 0;
$disetujui = $conn->query("SELECT COUNT(*) FROM absensi WHERE status = 'Disetujui'")->fetch_row()[0] ?? 0;
$ditolak = $conn->query("SELECT COUNT(*) FROM absensi WHERE status = 'Ditolak'")->fetch_row()[0] ?? 0;

// === REKAP JENIS ABSEN ===
$jenis = $conn->query("SELECT jenis, COUNT(*) as jml FROM absensi GROUP BY jenis ORDER BY jml DESC");

// === 7 HARI TERAKHIR ===
$chart7 = []; 
$label7 = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $label7[] = date('d/m', strtotime($d));
    $c = $conn->query("SELECT COUNT(*) FROM absensi WHERE DATE(created_at)='$d'")->fetch_row()[0] ?? 0;
    $c = (int)$c;
    $chart7[] = $c;
}

// === TOP 5 KARYAWAN RAJIN BULAN INI ===
$top5 = $conn->query("SELECT u.nama_lengkap, COUNT(*) as total 
    FROM absensi a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.jenis='Masuk' AND a.status='Disetujui' AND DATE_FORMAT(a.created_at,'%Y-%m')='$this_month'
    GROUP BY u.id ORDER BY total DESC LIMIT 5");

// === HEATMAP JAM HARI INI ===
$heatmap = [];
for ($h = 6; $h <= 18; $h++) {
    $start = sprintf("%02d:00:00", $h);
    $end = sprintf("%02d:59:59", $h);
    $count = $conn->query("SELECT COUNT(*) FROM absensi WHERE DATE(created_at)='$today' AND TIME(created_at) BETWEEN '$start' AND '$end'")->fetch_row()[0] ?? 0;
    $heatmap[] = [$h . ":00", (int)$count];
}
$maxHeat = !empty($heatmap) ? max(array_column($heatmap, 1)) : 0;
if ($maxHeat <= 0) $maxHeat = 1;

// === ABSENSI TERBARU ===
$recent = $conn->query("SELECT a.*, u.nama_lengkap FROM absensi a JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC LIMIT 12");
?>

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skaduta Presensi • Monitoring Real-Time</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
        }
        .card-hover { 
            transition: all 0.25s ease; 
        }
        .card-hover:hover { 
            transform: translateY(-6px); 
            box-shadow: 0 18px 40px rgba(0,0,0,0.13); 
        }
        .heatmap-bar { 
            transition: all 0.25s; 
        }
        .heatmap-bar:hover { 
            transform: scaleY(1.18); 
            opacity: 1 !important; 
        }
    </style>
</head>
<body class="bg-gradient-to-br from-orange-50 via-white to-orange-100 min-h-screen text-gray-800">

<!-- HEADER -->
<header class="sticky top-0 z-50 border-b border-orange-200/70 bg-white/80 backdrop-blur-xl shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div class="h-11 w-11 rounded-2xl bg-gradient-to-br from-orange-500 to-amber-400 flex items-center justify-center shadow-lg">
                <i class="fas fa-building text-white text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-orange-600 tracking-tight">
                    Skaduta Presensi
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">Dashboard Monitoring Presensi Real-Time</p>
            </div>
        </div>
        <div class="text-right">
            <div class="text-xs sm:text-sm text-gray-500 mb-1"><?= date('l, d F Y') ?></div>
            <div class="text-2xl sm:text-3xl font-mono font-bold text-orange-600" id="liveClock">--:--:--</div>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 sm:px-6 py-8 sm:py-10 space-y-10">

    <!-- TITLE + REFRESH INFO -->
    <section class="text-center space-y-3">
        <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-orange-700 drop-shadow-sm">
            Monitoring Presensi Real-Time
        </h2>
        <p class="text-base sm:text-lg text-gray-600">
            Data otomatis diperbarui setiap 
            <span class="font-semibold text-orange-600">30 detik</span> • 
            Terakhir diperbarui: 
            <span id="lastUpdate" class="font-mono font-semibold text-gray-800">
                <?= date('H:i:s') ?>
            </span> WIB
        </p>
    </section>

    <!-- STATISTIK UTAMA -->
    <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 sm:gap-6">
        <div class="bg-white/90 rounded-2xl p-4 sm:p-5 text-center card-hover border border-orange-100 shadow-md">
            <div class="flex justify-center">
                <div class="h-10 w-10 rounded-xl bg-orange-100 flex items-center justify-center mb-3">
                    <i class="fas fa-users text-orange-500 text-xl"></i>
                </div>
            </div>
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Total Pengguna</p>
            <p class="text-2xl sm:text-3xl font-extrabold text-gray-900">
                <?= number_format($total_pengguna) ?>
            </p>
        </div>

        <div class="bg-white/90 rounded-2xl p-4 sm:p-5 text-center card-hover border border-orange-100 shadow-md">
            <div class="flex justify-center">
                <div class="h-10 w-10 rounded-xl bg-orange-100 flex items-center justify-center mb-3">
                    <i class="fas fa-user-tie text-orange-500 text-xl"></i>
                </div>
            </div>
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Karyawan</p>
            <p class="text-2xl sm:text-3xl font-extrabold text-gray-900">
                <?= number_format($total_karyawan) ?>
            </p>
        </div>

        <div class="bg-white/90 rounded-2xl p-4 sm:p-5 text-center card-hover border border-orange-100 shadow-md">
            <div class="flex justify-center">
                <div class="h-10 w-10 rounded-xl bg-orange-100 flex items-center justify-center mb-3">
                    <i class="fas fa-user-shield text-orange-500 text-xl"></i>
                </div>
            </div>
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Admin & Superadmin</p>
            <p class="text-2xl sm:text-3xl font-extrabold text-gray-900">
                <?= number_format($total_admin) ?>
            </p>
        </div>

        <div class="bg-white/90 rounded-2xl p-4 sm:p-5 text-center card-hover border border-orange-100 shadow-md">
            <div class="flex justify-center">
                <div class="h-10 w-10 rounded-xl bg-orange-100 flex items-center justify-center mb-3">
                    <i class="fas fa-calendar-check text-orange-500 text-xl"></i>
                </div>
            </div>
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Absen Hari Ini</p>
            <p class="text-2xl sm:text-3xl font-extrabold text-gray-900">
                <?= number_format($absen_hari_ini) ?>
            </p>
        </div>

        <div class="bg-white/90 rounded-2xl p-4 sm:p-5 text-center card-hover border border-orange-100 shadow-md">
            <div class="flex justify-center">
                <div class="h-10 w-10 rounded-xl bg-orange-100 flex items-center justify-center mb-3">
                    <i class="fas fa-clock text-orange-500 text-xl"></i>
                </div>
            </div>
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Pending</p>
            <p class="text-2xl sm:text-3xl font-extrabold text-gray-900">
                <?= number_format($pending) ?>
            </p>
        </div>

        <div class="bg-white/90 rounded-2xl p-4 sm:p-5 text-center card-hover border border-orange-100 shadow-md">
            <div class="flex justify-center">
                <div class="h-10 w-10 rounded-xl bg-orange-100 flex items-center justify-center mb-3">
                    <i class="fas fa-clipboard-list text-orange-500 text-xl"></i>
                </div>
            </div>
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Total Absensi</p>
            <p class="text-2xl sm:text-3xl font-extrabold text-gray-900">
                <?= number_format($total_absensi) ?>
            </p>
        </div>
    </section>

    <!-- CHART & HEATMAP -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
        
        <!-- GRAFIK 7 HARI -->
        <div class="lg:col-span-2 bg-white rounded-2xl p-5 sm:p-6 shadow-xl border border-orange-100">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center gap-2">
                    <span class="h-8 w-8 rounded-xl bg-orange-100 flex items-center justify-center">
                        <i class="fas fa-chart-area text-orange-500"></i>
                    </span>
                    Aktivitas 7 Hari Terakhir
                </h3>
                <span class="text-xs px-2.5 py-1 rounded-full bg-orange-50 text-orange-700 border border-orange-100">
                    Periode: <?= date('d/m', strtotime('-6 days')) ?> - <?= date('d/m') ?>
                </span>
            </div>
            <canvas id="chart7" class="mt-2"></canvas>
        </div>

        <!-- HEATMAP JAM HARI INI -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 shadow-xl border border-orange-100">
            <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center gap-2 mb-4">
                <span class="h-8 w-8 rounded-xl bg-orange-100 flex items-center justify-center">
                    <i class="fas fa-fire text-orange-500"></i>
                </span>
                Heatmap Jam (Hari Ini)
            </h3>
            <div class="space-y-2.5">
                <?php foreach($heatmap as $h): 
                    $val = $h[1];
                    $height = $val == 0 ? 6 : ($val / $maxHeat * 100);
                    $color = $val == 0 ? 'bg-gray-200' : ($val < 3 ? 'bg-yellow-300' : ($val < 7 ? 'bg-orange-400' : 'bg-red-500'));
                ?>
                <div class="flex items-center text-xs sm:text-sm">
                    <span class="font-medium text-gray-600 w-14"><?= $h[0] ?></span>
                    <div class="flex-1 mx-3">
                        <div class="heatmap-bar <?= $color ?> rounded-full" style="height: <?= $height ?>px; opacity: 0.85;"></div>
                    </div>
                    <span class="font-bold text-gray-800 w-8 text-right"><?= $val ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- TOP 5 & REKAP JENIS -->
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8">
        
        <!-- TOP 5 KARYAWAN -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 shadow-xl border border-orange-100">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center gap-2">
                    <span class="h-8 w-8 rounded-xl bg-orange-100 flex items-center justify-center">
                        <i class="fas fa-trophy text-amber-500"></i>
                    </span>
                    Top 5 Karyawan Terajin (<?= date('F Y') ?>)
                </h3>
            </div>
            <ol class="space-y-3 sm:space-y-4">
                <?php 
                $rank = 1; 
                if ($top5 && $top5->num_rows > 0):
                    while($t = $top5->fetch_assoc()): ?>
                <li class="flex items-center justify-between p-3 sm:p-4 bg-gradient-to-r from-orange-50 to-amber-50 rounded-2xl border border-orange-100 card-hover">
                    <div class="flex items-center gap-3 sm:gap-4">
                        <span class="text-xl sm:text-2xl font-extrabold text-orange-500 w-8 text-center">
                            #<?= $rank++ ?>
                        </span>
                        <div>
                            <p class="font-bold text-gray-900 text-sm sm:text-base">
                                <?= htmlspecialchars($t['nama_lengkap']) ?>
                            </p>
                            <p class="text-xs text-gray-500">Hadir tepat waktu & konsisten</p>
                        </div>
                    </div>
                    <span class="text-2xl sm:text-3xl font-extrabold text-orange-600">
                        <?= $t['total'] ?>x
                    </span>
                </li>
                <?php 
                    endwhile; 
                else: ?>
                <p class="text-sm text-gray-500 italic">Belum ada data absensi disetujui bulan ini.</p>
                <?php endif; ?>
            </ol>
        </div>

        <!-- REKAP JENIS ABSENSI -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 shadow-xl border border-orange-100">
            <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center gap-2 mb-4">
                <span class="h-8 w-8 rounded-xl bg-orange-100 flex items-center justify-center">
                    <i class="fas fa-tasks text-orange-500"></i>
                </span>
                Rekap Jenis Absensi
            </h3>
            <div class="space-y-3 sm:space-y-4">
                <?php if ($jenis && $jenis->num_rows > 0): ?>
                    <?php while($j = $jenis->fetch_assoc()): 
                        $icon = $j['jenis']=='Masuk' ? 'fa-sign-in-alt text-emerald-500' : 
                               ($j['jenis']=='Pulang' ? 'fa-sign-out-alt text-blue-500' : 
                               ($j['jenis']=='Izin' ? 'fa-calendar-times text-orange-500' : 'fa-clock text-purple-500'));
                    ?>
                    <div class="flex items-center justify-between p-3 sm:p-4 bg-orange-50/60 rounded-2xl border border-orange-100 card-hover">
                        <div class="flex items-center gap-3 sm:gap-4">
                            <div class="h-9 w-9 rounded-xl bg-white flex items-center justify-center shadow-sm">
                                <i class="fas <?= $icon ?> text-lg"></i>
                            </div>
                            <span class="text-sm sm:text-base font-semibold text-gray-800"><?= $j['jenis'] ?></span>
                        </div>
                        <span class="text-2xl sm:text-3xl font-extrabold text-orange-600">
                            <?= number_format($j['jml']) ?>
                        </span>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="text-sm text-gray-500 italic">Belum ada data rekap jenis absensi.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- TABEL ABSENSI TERBARU -->
    <section class="bg-white rounded-2xl shadow-2xl border border-orange-100 overflow-hidden">
        <div class="px-5 sm:px-6 py-4 sm:py-5 bg-gradient-to-r from-orange-500 to-amber-400 text-white flex items-center justify-between">
            <h3 class="text-lg sm:text-xl font-bold flex items-center gap-2">
                <i class="fas fa-history"></i> 
                12 Absensi Terbaru
            </h3>
            <span class="hidden sm:inline-flex items-center gap-2 text-xs bg-white/10 px-3 py-1 rounded-full border border-white/20">
                <span class="h-2 w-2 rounded-full bg-emerald-300 animate-pulse"></span>
                Live Monitoring
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-orange-50 border-b border-orange-100">
                    <tr>
                        <th class="px-4 sm:px-6 py-3 sm:py-4 text-left text-xs font-bold text-orange-700 uppercase tracking-wide">Waktu</th>
                        <th class="px-4 sm:px-6 py-3 sm:py-4 text-left text-xs font-bold text-orange-700 uppercase tracking-wide">Nama</th>
                        <th class="px-4 sm:px-6 py-3 sm:py-4 text-left text-xs font-bold text-orange-700 uppercase tracking-wide">Jenis</th>
                        <th class="px-4 sm:px-6 py-3 sm:py-4 text-left text-xs font-bold text-orange-700 uppercase tracking-wide">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if ($recent && $recent->num_rows > 0): ?>
                        <?php while($r = $recent->fetch_assoc()): ?>
                        <tr class="hover:bg-orange-50/60 transition">
                            <td class="px-4 sm:px-6 py-3 sm:py-4 text-gray-600 whitespace-nowrap">
                                <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                            </td>
                            <td class="px-4 sm:px-6 py-3 sm:py-4 font-semibold text-gray-900">
                                <?= htmlspecialchars($r['nama_lengkap']) ?>
                            </td>
                            <td class="px-4 sm:px-6 py-3 sm:py-4">
                                <span class="inline-flex items-center gap-2 text-gray-700">
                                    <span class="h-7 w-7 rounded-full bg-orange-50 flex items-center justify-center border border-orange-100">
                                        <i class="fas <?= $r['jenis']=='Masuk'
                                            ? 'fa-sign-in-alt text-emerald-500'
                                            : ($r['jenis']=='Pulang'
                                                ? 'fa-sign-out-alt text-blue-500'
                                                : 'fa-tasks text-purple-500') ?> text-xs"></i>
                                    </span>
                                    <span class="text-sm font-medium"><?= $r['jenis'] ?></span>
                                </span>
                            </td>
                            <td class="px-4 sm:px-6 py-3 sm:py-4">
                                <?php if($r['status']=='Disetujui'): ?>
                                    <span class="px-3 py-1.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 uppercase tracking-wide">
                                        Disetujui
                                    </span>
                                <?php elseif($r['status']=='Pending'): ?>
                                    <span class="px-3 py-1.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800 border border-amber-200 uppercase tracking-wide">
                                        Pending
                                    </span>
                                <?php else: ?>
                                    <span class="px-3 py-1.5 rounded-full text-[11px] font-bold bg-red-100 text-red-800 border border-red-200 uppercase tracking-wide">
                                        Ditolak
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="px-4 sm:px-6 py-6 text-center text-gray-500 text-sm">
                                Belum ada data absensi.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<footer class="text-center py-8 text-gray-500 text-xs sm:text-sm">
    © <?= date('Y') ?> <strong>Skaduta Presensi</strong> — Monitoring Presensi Real-Time
</footer>

<script>
// Jam & Update Otomatis
function updateClock() {
    const now = new Date();
    const opt = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
    document.getElementById('liveClock').textContent = now.toLocaleTimeString('id-ID', opt);
    document.getElementById('lastUpdate').textContent = now.toLocaleTimeString('id-ID', opt);
}
setInterval(updateClock, 1000);
updateClock();

// Auto Refresh setiap 30 detik
setInterval(() => location.reload(), 0);

// Chart 7 Hari
new Chart(document.getElementById('chart7'), {
    type: 'line',
    data: {
        labels: <?= json_encode($label7) ?>,
        datasets: [{
            label: 'Jumlah Absensi',
            data: <?= json_encode($chart7) ?>,
            borderColor: '#f97316',
            backgroundColor: 'rgba(249, 115, 22, 0.12)',
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#fb923c',
            pointBorderColor: '#f97316',
            pointRadius: 5,
            pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true,
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(17,24,39,0.95)',
                padding: 10,
                cornerRadius: 8
            }
        },
        scales: { 
            y: { 
                beginAtZero: true, 
                ticks: { stepSize: 1 } 
            } 
        }
    }
});
</script>

</body>
</html>
