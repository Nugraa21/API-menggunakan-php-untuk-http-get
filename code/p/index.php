<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backend API - Testing Interface (Encrypted)</title>
    <style>
        :root {
            --primary: #2563eb;
            --bg: #f8fafc;
            --surface: #ffffff;
            --text: #334155;
            --border: #e2e8f0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 40px 20px;
            line-height: 1.5;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        header {
            margin-bottom: 40px;
            text-align: center;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0f172a;
        }

        p.subtitle {
            color: #64748b;
            font-size: 0.95rem;
        }

        .section {
            background: var(--surface);
            border-radius: 8px;
            border: 1px solid var(--border);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .section-header {
            padding: 16px 20px;
            background: #f1f5f9;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #475569;
        }

        .item-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            transition: background 0.15s;
        }

        .item:last-child {
            border-bottom: none;
        }

        .item:hover {
            background-color: #f8fafc;
        }

        .method {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 4px;
            width: 50px;
            text-align: center;
            margin-right: 15px;
            text-transform: uppercase;
        }

        .post {
            background: #dcfce7;
            color: #166534;
        }

        .get {
            background: #dbeafe;
            color: #1e40af;
        }

        .link {
            text-decoration: none;
            color: #334155;
            font-weight: 500;
            flex-grow: 1;
        }

        .link:hover {
            color: var(--primary);
        }

        .desc {
            font-size: 0.85rem;
            color: #94a3b8;
        }

        /* Simple SVG Icons */
        .icon {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <h1>API Testing Interface</h1>
            <p class="subtitle">Versi Enkripsi (Secure Version)</p>
            <div
                style="margin-top: 10px; padding: 10px; background: #fef9c3; border: 1px solid #fde047; border-radius: 6px;">
                <strong>INFO:</strong> <a href="../index.php"
                    style="color: #ca8a04; text-decoration: none; font-weight: bold;">&larr; Kembali ke Root (Versi
                    Clean)</a>
                <p style="margin: 5px 0 0; font-size: 0.85rem; color: #854d0e;">Semua endpoint di sini memerlukan API
                    Key dan mengembalikan respons terenkripsi.</p>
            </div>
        </header>

        <!-- Auth Section -->
        <div class="section">
            <div class="section-header">
                <svg class="icon" viewBox="0 0 24 24">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                Autentikasi (Encrypted)
            </div>
            <ul class="item-list">
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="login.php" class="link" target="_blank">login.php</a>
                    <span class="desc">Login User</span>
                </li>
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="register.php" class="link" target="_blank">register.php</a>
                    <span class="desc">Register User</span>
                </li>
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="update_password.php" class="link" target="_blank">update_password.php</a>
                    <span class="desc">Update Password</span>
                </li>
            </ul>
        </div>

        <!-- Presensi Section -->
        <div class="section">
            <div class="section-header">
                <svg class="icon" viewBox="0 0 24 24">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                Presensi & Absensi (Encrypted)
            </div>
            <ul class="item-list">
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="absen.php" class="link" target="_blank">absen.php</a>
                    <span class="desc">Input Absen (Masuk/Pulang)</span>
                </li>
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="presensi_add.php" class="link" target="_blank">presensi_add.php</a>
                    <span class="desc">Input Manual (Legacy)</span>
                </li>
            </ul>
        </div>

        <!-- Data Section -->
        <div class="section">
            <div class="section-header">
                <svg class="icon" viewBox="0 0 24 24">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                </svg>
                Data & Laporan (Encrypted)
            </div>
            <ul class="item-list">
                <li class="item">
                    <span class="method get">GET</span>
                    <a href="get_users.php" class="link" target="_blank">get_users.php</a>
                    <span class="desc">List Semua User</span>
                </li>
                <li class="item">
                    <span class="method get">GET</span>
                    <a href="absen_admin_list.php" class="link" target="_blank">absen_admin_list.php</a>
                    <span class="desc">Data Semua Absensi</span>
                </li>
                <li class="item">
                    <span class="method get">GET</span>
                    <a href="presensi_rekap.php?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>"
                        class="link" target="_blank">presensi_rekap.php</a>
                    <span class="desc">Rekap Bulanan</span>
                </li>
                <li class="item">
                    <span class="method get">GET</span>
                    <a href="absen_history.php?user_id=1" class="link" target="_blank">absen_history.php</a>
                    <span class="desc">History Absen (By User ID)</span>
                </li>
                <li class="item">
                    <span class="method get">GET</span>
                    <a href="presensi_user_history.php?user_id=1" class="link"
                        target="_blank">presensi_user_history.php</a>
                    <span class="desc">History Detail (By User ID)</span>
                </li>
            </ul>
        </div>

        <!-- Management Section -->
        <div class="section">
            <div class="section-header">
                <svg class="icon" viewBox="0 0 24 24">
                    <path
                        d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z">
                    </path>
                </svg>
                Manajemen (Encrypted)
            </div>
            <ul class="item-list">
                <li class="item">
                    <span class="method get">GET</span>
                    <a href="presensi_pending.php" class="link" target="_blank">presensi_pending.php</a>
                    <span class="desc">List Pending Approval</span>
                </li>
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="presensi_approve.php" class="link" target="_blank">presensi_approve.php</a>
                    <span class="desc">Approve/Reject (presensi_approve)</span>
                </li>
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="absen_approve.php" class="link" target="_blank">absen_approve.php</a>
                    <span class="desc">Approve/Reject (absen_approve)</span>
                </li>
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="update_user.php" class="link" target="_blank">update_user.php</a>
                    <span class="desc">Edit User</span>
                </li>
                <li class="item">
                    <span class="method post">POST</span>
                    <a href="delete_user.php" class="link" target="_blank">delete_user.php</a>
                    <span class="desc">Hapus User</span>
                </li>
            </ul>
        </div>
    </div>
</body>

</html>