<?php
require_once 'config.php';
session_start();

// ===== Debug (عطّل بالإنتاج) =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ===== Auth =====
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: super_admin_login.php');
    exit;
}

// ===== Super Admin Info =====
$stmtAdmin = $pdo->prepare("SELECT expiry_date, username FROM admins WHERE id = ?");
$stmtAdmin->execute([$_SESSION['super_admin_id']]);
$adminDetails = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
$expiry_date = $adminDetails['expiry_date'] ?? null;
$superAdminUsername = $adminDetails['username'] ?? 'Super Admin';
$isSubscriptionExpired = $expiry_date && (strtotime($expiry_date) < time());

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error   = '';

// =======================
// CRUD: Admins
// =======================
if (isset($_POST['add_admin'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "فشل التحقق من الطلب.";
    } else {
        $username    = trim($_POST['username'] ?? '');
        $password    = trim($_POST['password'] ?? '');
        $expiry_in   = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        if ($username !== '' && $password !== '') {
            $stmt = $pdo->prepare("SELECT 1 FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn()) {
                $error = "اسم المستخدم موجود.";
            } else {
                // (مطابق لكودك: كلمة مرور غير مُشفّرة)
                $stmt = $pdo->prepare("INSERT INTO admins (username, password, is_active, expiry_date) VALUES (?, ?, 1, ?)");
                $success = $stmt->execute([$username, $password, $expiry_in]) ? "تم إنشاء المشرف." : "خطأ أثناء الإنشاء.";
                if ($success !== "تم إنشاء المشرف.") $error = "خطأ أثناء الإنشاء.";
            }
        } else {
            $error = "عبّ البيانات كاملة.";
        }
    }
}

if (isset($_POST['delete_admin'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "فشل التحقق من الطلب.";
    } else {
        $adminId = (int)($_POST['admin_id'] ?? 0);
        if ($adminId === (int)$_SESSION['super_admin_id']) {
            $error = "ما تقدر تحذف نفسك.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
            $success = $stmt->execute([$adminId]) ? "تم الحذف." : "فشل الحذف.";
            if ($success !== "تم الحذف.") $error = "فشل الحذف.";
        }
    }
}

if (isset($_POST['toggle_status'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "فشل التحقق من الطلب.";
    } else {
        $adminId  = (int)($_POST['admin_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);
        if ($adminId === (int)$_SESSION['super_admin_id']) {
            $error = "ما تقدر تغيّر حالة حسابك.";
        } else {
            $stmt = $pdo->prepare("UPDATE admins SET is_active = ? WHERE id = ?");
            $success = $stmt->execute([$isActive, $adminId]) ? "تم التحديث." : "فشل التحديث.";
            if ($success !== "تم التحديث.") $error = "فشل التحديث.";
        }
    }
}

// AJAX: Edit admin
if (isset($_POST['edit_admin'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['status'=>'error','message'=>'فشل التحقق.']); exit;
    }
    $adminId   = (int)($_POST['admin_id'] ?? 0);
    $username  = trim($_POST['username'] ?? '');
    $expiry_in = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

    if ($adminId === (int)$_SESSION['super_admin_id']) {
        echo json_encode(['status'=>'error','message'=>'ما تقدر تعدّل حسابك.']); exit;
    }
    if ($username === '') {
        echo json_encode(['status'=>'error','message'=>'اسم المستخدم مطلوب.']); exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
    $stmt->execute([$username, $adminId]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['status'=>'error','message'=>'الاسم مستخدم.']); exit;
    }
    $stmt = $pdo->prepare("UPDATE admins SET username = ?, expiry_date = ? WHERE id = ?");
    $ok = $stmt->execute([$username, $expiry_in, $adminId]);
    echo json_encode(['status'=>$ok?'success':'error','message'=>$ok?'تم الحفظ.':'فشل الحفظ.']); exit;
}

// =======================
// Supervisors data
// =======================
$stmtSupervisors = $pdo->query("SELECT * FROM admins ORDER BY id DESC");
$supervisors = $stmtSupervisors->fetchAll(PDO::FETCH_ASSOC);
foreach ($supervisors as &$admin) {
    $aid = (int)$admin['id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE admin_id = ?");
    $stmt->execute([$aid]);
    $admin['emailCount'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE admin_id = ?");
    $stmt->execute([$aid]);
    $admin['clientCount'] = (int)$stmt->fetchColumn();
}
unset($admin);

// =======================
// Global stats
// =======================
$totalEmails  = (int)$pdo->query("SELECT COUNT(*) FROM emails")->fetchColumn();
$totalClients = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalAdmins  = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE DATE(created_at)=CURDATE()");
$stmt->execute(); $clientsToday = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT a.username, COUNT(c.id) as client_count
    FROM admins a
    LEFT JOIN clients c ON a.id = c.admin_id
    WHERE DATE(c.created_at)=CURDATE()
    GROUP BY a.id, a.username
    ORDER BY client_count DESC
    LIMIT 1
"); $stmt->execute();
$topSupervisor = $stmt->fetch(PDO::FETCH_ASSOC);
$topSupervisorName = $topSupervisor['username'] ?? '—';
$topSupervisorClients = (int)($topSupervisor['client_count'] ?? 0);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE DATE(created_at)=CURDATE()");
$stmt->execute(); $emailsToday = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE is_active=1");
$stmt->execute(); $activeAdmins = (int)$stmt->fetchColumn();

// =======================
// OTP Tracking
// =======================
$filter_from  = $_GET['from'] ?? date('Y-m-01');
$filter_to    = $_GET['to']   ?? date('Y-m-d');
$filter_admin = isset($_GET['admin_id']) && $_GET['admin_id'] !== '' ? (int)$_GET['admin_id'] : null;

// Export CSV
if (isset($_GET['export']) && $_GET['export']==='otp') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="otp_logs_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Client','Admin','Code/Hash','Created At']);
    $sql = "SELECT l.id, l.client_id, l.admin_id, l.code, l.created_at,
                   c.username AS client_name, a.username AS admin_name
            FROM otp_simple_log l
            LEFT JOIN clients c ON c.id = l.client_id
            LEFT JOIN admins a  ON a.id = l.admin_id
            WHERE DATE(l.created_at) BETWEEN :f AND :t";
    $params = [':f'=>$filter_from, ':t'=>$filter_to];
    if ($filter_admin !== null) { $sql .= " AND l.admin_id = :ad"; $params[':ad']=$filter_admin; }
    $sql .= " ORDER BY l.created_at DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['id'],
            $r['client_name'] ? ($r['client_name'].' (#'.$r['client_id'].')') : $r['client_id'],
            $r['admin_name'] ? ($r['admin_name'].' (#'.$r['admin_id'].')') : $r['admin_id'],
            $r['code'],
            $r['created_at']
        ]);
    }
    fclose($out); exit;
}

// Counters for range
$sql = "SELECT COUNT(*) FROM otp_simple_log WHERE DATE(created_at) BETWEEN :f AND :t";
$params = [':f'=>$filter_from, ':t'=>$filter_to];
if ($filter_admin !== null) { $sql .= " AND admin_id=:ad"; $params[':ad']=$filter_admin; }
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$otpTotalInRange = (int)$stmt->fetchColumn();

$sql = "SELECT COUNT(DISTINCT client_id) FROM otp_simple_log WHERE DATE(created_at) BETWEEN :f AND :t";
if ($filter_admin !== null) { $sql .= " AND admin_id=:ad"; }
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$otpUniqueClients = (int)$stmt->fetchColumn();

// Top admins (OTP)
$sql = "SELECT a.username, l.admin_id, COUNT(*) cnt
        FROM otp_simple_log l
        LEFT JOIN admins a ON a.id = l.admin_id
        WHERE DATE(l.created_at) BETWEEN :f AND :t";
if ($filter_admin !== null) { $sql .= " AND l.admin_id = :ad"; }
$sql .= " GROUP BY l.admin_id, a.username ORDER BY cnt DESC LIMIT 10";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$otpTopAdmins = $stmt->fetchAll(PDO::FETCH_ASSOC);

$topAdminCount = isset($otpTopAdmins[0]['cnt']) ? (int)$otpTopAdmins[0]['cnt'] : 0;
$topAdminName  = isset($otpTopAdmins[0]['username']) ? $otpTopAdmins[0]['username'] : '—';
$topShare      = $otpTotalInRange > 0 ? round(($topAdminCount / $otpTotalInRange) * 100, 1) : 0;

// Last 14 days chart
$stmt = $pdo->prepare("
    SELECT DATE(created_at) d, COUNT(*) c
    FROM otp_simple_log
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC
"); $stmt->execute();
$chartDays=[]; $chartCounts=[];
while($r=$stmt->fetch(PDO::FETCH_ASSOC)){ $chartDays[]=$r['d']; $chartCounts[]=(int)$r['c']; }

// Recent list
$sql = "SELECT l.id, l.client_id, l.admin_id, l.code, l.created_at,
               c.username AS client_name, a.username AS admin_name
        FROM otp_simple_log l
        LEFT JOIN clients c ON c.id = l.client_id
        LEFT JOIN admins a  ON a.id = l.admin_id
        WHERE DATE(l.created_at) BETWEEN :f AND :t";
if ($filter_admin !== null) { $sql .= " AND l.admin_id = :ad"; }
$sql .= " ORDER BY l.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$otpRecent = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Admins list for filters
$adminsList = $pdo->query("SELECT id, username FROM admins ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

// =======================
// Enhanced insights data
// =======================
$stmt = $pdo->prepare("SELECT c.id, c.username, c.admin_id, c.created_at, a.username AS admin_name
                       FROM clients c
                       LEFT JOIN admins a ON a.id = c.admin_id
                       ORDER BY c.created_at DESC
                       LIMIT 8");
$stmt->execute();
$recentClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$topClientLeaders = $pdo->query("SELECT a.id, a.username, COUNT(c.id) AS total_clients
                                 FROM admins a
                                 LEFT JOIN clients c ON c.admin_id = a.id
                                 GROUP BY a.id, a.username
                                 ORDER BY total_clients DESC
                                 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$otpDailyAverage = !empty($chartCounts) ? round(array_sum($chartCounts) / max(count($chartCounts), 1), 1) : 0;
$latestOtp = $otpRecent[0] ?? null;

function shortNumber($number)
{
    $number = (int)$number;
    if ($number >= 1000000) {
        return round($number / 1000000, 1) . ' م';
    }
    if ($number >= 1000) {
        return round($number / 1000, 1) . ' ألف';
    }
    return number_format($number);
}

function formatRelativeTime(?string $datetime): string
{
    if (!$datetime) {
        return 'غير متوفر';
    }
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return htmlspecialchars($datetime, ENT_QUOTES, 'UTF-8');
    }
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'الآن';
    }
    if ($diff < 3600) {
        $minutes = max(1, floor($diff / 60));
        return 'قبل ' . $minutes . ' دقيقة';
    }
    if ($diff < 86400) {
        $hours = max(1, floor($diff / 3600));
        return 'قبل ' . $hours . ' ساعة';
    }
    if ($diff < 604800) {
        $days = max(1, floor($diff / 86400));
        return 'قبل ' . $days . ' يوم';
    }
    return date('Y-m-d H:i', $timestamp);
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>لوحة المشرف الأعلى</title>

<!-- Tailwind -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --bg:#f3f1ee; --panel:#fff; --card:#fff; --muted:#707070;
    --ink:#1f2937; --hair:#ece9e6;
    --accent:#111827; --chip:#f5f6f8;
    --grad1:#ff7a88; --grad2:#ffb36b; /* pink -> peach */
    --grad3:#a18cd1; --grad4:#61d4e3; /* lilac -> aqua */
  }
  body{font-family:'IBM Plex Sans Arabic','Plus Jakarta Sans',system-ui; background:var(--bg); color:var(--ink);}
  .panel{background:var(--panel); border:1px solid var(--hair)}
  .card{background:var(--card); border:1px solid var(--hair)}
  .chip{background:var(--chip); border:1px solid var(--hair)}
  .gradA{background:linear-gradient(135deg,var(--grad1),var(--grad2));}
  .gradB{background:linear-gradient(135deg,var(--grad3),var(--grad4));}
  .soft{box-shadow: 0 12px 30px rgba(31,41,55,.06);}
  .pill{border-radius:16px}
  .round{border-radius:22px}
  .sidebar{background:#111827; color:#e5e7eb}
  .icon-btn{background:#0f172a; color:#e5e7eb; border:1px solid rgba(255,255,255,.06)}
  .muted{color:var(--muted)}
  .hero{position:relative; overflow:hidden;}
  .hero::after{content:''; position:absolute; right:-40px; left:-40px; bottom:-60px; height:180px; background:radial-gradient(circle at center,var(--grad2),transparent 70%); opacity:.6;}
  .stat-card{background:var(--panel); border:1px solid var(--hair); border-radius:20px; padding:20px; box-shadow:0 10px 22px rgba(15,23,42,.06); display:flex; flex-direction:column; gap:12px;}
  .stat-icon{width:40px; height:40px; border-radius:14px; display:grid; place-items:center; font-size:18px;}
  .stat-value{font-size:28px; font-weight:700; color:var(--ink);}
  .badge-soft{border-radius:999px; border:1px solid rgba(17,24,39,.08); background:rgba(249,250,251,.75); padding:4px 10px; font-size:12px; color:#4b5563; display:inline-flex; align-items:center; gap:6px;}
  .glass{background:rgba(255,255,255,.65); border:1px solid rgba(255,255,255,.4); box-shadow:0 16px 40px rgba(15,23,42,.08); backdrop-filter:blur(18px);}
  .list-tile{display:flex; justify-content:space-between; align-items:center; padding:14px 0; border-bottom:1px solid var(--hair);}
  .list-tile:last-child{border-bottom:none;}
  .timeline-row{display:grid; grid-template-columns:auto 1fr; gap:16px; padding:12px 0; border-bottom:1px solid var(--hair);}
  .timeline-row:last-child{border-bottom:none;}
  .timeline-dot{width:14px; height:14px; border-radius:50%; background:linear-gradient(135deg,var(--grad3),var(--grad4)); position:relative; top:6px; box-shadow:0 0 0 4px rgba(161,140,209,.18);}
  .table-surface table{width:100%; border-collapse:separate; border-spacing:0;}
  .table-surface thead th{background:var(--chip); color:#6b7280; font-size:12px; font-weight:600; padding:12px; text-align:right;}
  .table-surface tbody td{padding:16px 12px; border-bottom:1px solid var(--hair); font-size:14px;}
  .table-actions{display:flex; flex-wrap:wrap; gap:8px; align-items:center;}
  .action-btn{display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:6px 14px; border:1px solid var(--hair); font-size:12px;}
  .action-btn.primary{background:#111827; color:#fff; border-color:#111827;}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar fixed top-0 right-0 h-full w-72 px-4 py-6 hidden md:flex flex-col gap-3 z-40">
  <div class="flex items-center gap-3 px-2">
    <div class="w-10 h-10 round gradB"></div>
    <div>
      <div class="font-bold">لوحة التحكم</div>
      <div class="text-xs text-gray-400">مرحباً، <?= htmlspecialchars($superAdminUsername) ?></div>
    </div>
  </div>
  <nav class="mt-3 space-y-2">
    <a href="#insights" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-white/5">
      <i class="fa-solid fa-chart-simple"></i> الإحصائيات
    </a>
    <a href="#clients" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-white/5">
      <i class="fa-solid fa-users"></i> العملاء
    </a>
    <a href="#otp" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-white/5">
      <i class="fa-solid fa-shield"></i> تتبّع الأكواد
    </a>
    <a href="#admins" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-white/5">
      <i class="fa-solid fa-user-gear"></i> إدارة المشرفين
    </a>
  </nav>
  <div class="mt-auto">
    <?php if ($expiry_date): ?>
      <div class="px-3 py-3 rounded-xl bg-white/5">
        <div class="text-xs text-gray-400 mb-1">الاشتراك</div>
        <div class="<?= $isSubscriptionExpired?'text-rose-300':'text-emerald-300' ?> text-sm">
          <?= $isSubscriptionExpired ? 'منتهي: ' : 'صالح حتى: ' ?>
          <?= htmlspecialchars(date('Y-m-d', strtotime($expiry_date))) ?>
        </div>
      </div>
    <?php endif; ?>
    <a href="logout.php" class="mt-3 block text-center icon-btn py-2 round">تسجيل الخروج</a>
  </div>
</aside>

<!-- MAIN -->
<main class="md:pr-80 p-4 md:p-8 space-y-10">

  <section class="panel hero soft round p-6 lg:p-8">
    <div class="grid gap-6 lg:grid-cols-[1.6fr,1fr] items-center relative">
      <div class="space-y-4 relative z-10">
        <span class="badge-soft"><i class="fa-solid fa-gauge-high text-sm"></i> لوحة التحكم المتقدمة</span>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900 leading-tight">كل ما تحتاجه لإدارة المنصة في صفحة واحدة</h1>
        <p class="text-gray-600 leading-relaxed">راقب أداء العملاء، فعالية المشرفين، وسجلات OTP من واجهة واحدة أنيقة. تم تصميم هذه اللوحة لتوفر لك السرعة في اتخاذ القرار والوضوح في البيانات.</p>
        <div class="flex flex-wrap gap-3">
          <a href="#admins" class="pill px-5 py-2 bg-black text-white flex items-center gap-2">
            <i class="fa-solid fa-users-gear text-sm"></i>
            إدارة المشرفين
          </a>
          <a href="?export=otp&from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?><?= $filter_admin!==null ? '&admin_id='.$filter_admin : '' ?>" class="action-btn primary">
            <i class="fa-solid fa-file-arrow-down text-sm"></i>
            تصدير OTP
          </a>
        </div>
      </div>
      <div class="glass round p-6 space-y-4 text-sm text-gray-600 relative z-10">
        <div class="flex items-center justify-between">
          <span class="font-semibold text-gray-800">لوحة الحالة</span>
          <span class="badge-soft"><i class="fa-regular fa-clock"></i> <?= date('Y-m-d H:i') ?></span>
        </div>
        <div class="space-y-3">
          <div>
            <div class="text-xs text-gray-500">آخر عميل تم تسجيله</div>
            <div class="flex items-center justify-between gap-2 text-gray-800">
              <span class="font-semibold"><?= htmlspecialchars($recentClients[0]['username'] ?? '—') ?></span>
              <span class="text-xs text-gray-500"><?= $recentClients ? formatRelativeTime($recentClients[0]['created_at'] ?? null) : '—' ?></span>
            </div>
          </div>
          <div>
            <div class="text-xs text-gray-500">أبرز مشرف اليوم</div>
            <div class="flex items-center justify-between gap-2 text-gray-800">
              <span class="font-semibold"><?= htmlspecialchars($topSupervisorName) ?></span>
              <span class="text-xs text-gray-500"><?= number_format($topSupervisorClients) ?> عملاء</span>
            </div>
          </div>
          <?php if ($expiry_date): ?>
          <div class="p-4 rounded-xl border border-white/60 bg-white/40 flex items-center justify-between gap-3">
            <div>
              <div class="text-xs text-gray-500">اشتراك النظام</div>
              <div class="font-semibold <?= $isSubscriptionExpired ? 'text-rose-600' : 'text-emerald-600' ?>">
                <?= $isSubscriptionExpired ? 'منتهي منذ ' : 'صالح حتى ' ?>
                <?= htmlspecialchars(date('Y-m-d', strtotime($expiry_date))) ?>
              </div>
            </div>
            <i class="fa-regular fa-calendar-check text-xl <?= $isSubscriptionExpired ? 'text-rose-500' : 'text-emerald-500' ?>"></i>
          </div>
          <?php endif; ?>
          <?php if ($latestOtp): ?>
          <div class="p-4 rounded-xl border border-white/60 bg-white/60 space-y-2">
            <div class="text-xs text-gray-500">آخر عملية OTP</div>
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-semibold text-gray-800">كود: <code><?= htmlspecialchars($latestOtp['code']) ?></code></div>
                <div class="text-xs text-gray-500 mt-1"><?= formatRelativeTime($latestOtp['created_at'] ?? null) ?></div>
              </div>
              <div class="text-right text-xs text-gray-500 space-y-1">
                <div><?= $latestOtp['client_name'] ? htmlspecialchars($latestOtp['client_name']) : ($latestOtp['client_id'] ? 'عميل #'.(int)$latestOtp['client_id'] : '—') ?></div>
                <div><?= $latestOtp['admin_name'] ? htmlspecialchars($latestOtp['admin_name']) : ($latestOtp['admin_id'] ? 'مشرف #'.(int)$latestOtp['admin_id'] : '—') ?></div>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section id="insights" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <div class="stat-card">
      <div class="flex items-center justify-between">
        <div class="stat-icon gradA text-white"><i class="fa-solid fa-users"></i></div>
        <span class="badge-soft"><i class="fa-regular fa-user"></i> اليوم <?= number_format($clientsToday) ?></span>
      </div>
      <div class="stat-value"><?= shortNumber($totalClients) ?></div>
      <div class="text-sm text-gray-500">عميل ضمن النظام</div>
    </div>
    <div class="stat-card">
      <div class="flex items-center justify-between">
        <div class="stat-icon gradB text-white"><i class="fa-solid fa-user-shield"></i></div>
        <span class="badge-soft"><i class="fa-solid fa-toggle-on"></i> نشطون <?= number_format($activeAdmins) ?></span>
      </div>
      <div class="stat-value"><?= shortNumber($totalAdmins) ?></div>
      <div class="text-sm text-gray-500">مشرف مسجّل</div>
    </div>
    <div class="stat-card">
      <div class="flex items-center justify-between">
        <div class="stat-icon bg-black text-white"><i class="fa-regular fa-envelope"></i></div>
        <span class="badge-soft"><i class="fa-solid fa-bolt"></i> اليوم <?= number_format($emailsToday) ?></span>
      </div>
      <div class="stat-value"><?= shortNumber($totalEmails) ?></div>
      <div class="text-sm text-gray-500">رسالة مرتبطة بالحملات</div>
    </div>
    <div class="stat-card">
      <div class="flex items-center justify-between">
        <div class="stat-icon bg-emerald-500 text-white"><i class="fa-solid fa-shield-halved"></i></div>
        <span class="badge-soft"><i class="fa-solid fa-chart-line"></i> متوسط <?= $otpDailyAverage ?></span>
      </div>
      <div class="stat-value"><?= shortNumber($otpTotalInRange) ?></div>
      <div class="text-sm text-gray-500">طلبات OTP خلال الفترة</div>
    </div>
  </section>

  <section class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 panel round soft p-6 space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="font-semibold text-lg">توجهات OTP (آخر 14 يوم)</h3>
        <span class="badge-soft"><i class="fa-solid fa-arrow-trend-up"></i> متوسط <?= $otpDailyAverage ?></span>
      </div>
      <canvas id="chartDays" height="260"></canvas>
    </div>
    <div class="flex flex-col gap-4">
      <div class="panel round soft p-6 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="font-semibold text-lg">حصة أعلى مشرف</h3>
          <span class="badge-soft"><i class="fa-solid fa-crown"></i> <?= htmlspecialchars($topAdminName) ?></span>
        </div>
        <div class="flex items-center gap-6">
          <canvas id="donut" width="140" height="140"></canvas>
          <div class="space-y-2">
            <div class="text-4xl font-bold"><?= $topShare ?>%</div>
            <div class="text-sm text-gray-500">عدد الطلبات: <?= number_format($topAdminCount) ?></div>
            <div class="text-xs text-gray-400">إجمالي الفترة: <?= number_format($otpTotalInRange) ?></div>
          </div>
        </div>
      </div>
      <div class="panel round soft p-6 space-y-3">
        <div class="flex items-center justify-between">
          <h3 class="font-semibold text-lg">أفضل 10 مشرفين (OTP)</h3>
          <span class="badge-soft"><i class="fa-solid fa-ranking-star"></i></span>
        </div>
        <canvas id="chartAdmins" height="180"></canvas>
      </div>
    </div>
  </section>

  <section id="clients" class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 panel round soft p-6">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h3 class="font-semibold text-lg">أحدث العملاء</h3>
          <p class="text-sm text-gray-500">تابع العملاء المنضمّين مؤخراً إلى المنصة.</p>
        </div>
        <span class="badge-soft"><i class="fa-solid fa-database"></i> إجمالي <?= shortNumber($totalClients) ?></span>
      </div>
      <?php if (!empty($recentClients)): ?>
        <div>
          <?php foreach ($recentClients as $client): ?>
            <?php
              $clientName = $client['username'] ? htmlspecialchars($client['username']) : 'عميل #'.(int)$client['id'];
              $clientOwner = $client['admin_name'] ? htmlspecialchars($client['admin_name']) : 'غير محدد';
            ?>
            <div class="list-tile">
              <div>
                <div class="font-semibold text-gray-800"><?= $clientName ?></div>
                <div class="text-xs text-gray-500">المشرف: <?= $clientOwner ?></div>
              </div>
              <div class="text-xs text-gray-500 flex flex-col items-end">
                <span><?= formatRelativeTime($client['created_at'] ?? null) ?></span>
                <span class="text-[10px] text-gray-400">#<?= (int)$client['id'] ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="text-sm text-gray-500 text-center py-6">لا يوجد عملاء جدد في هذه الفترة.</div>
      <?php endif; ?>
    </div>
    <div class="panel round soft p-6 space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="font-semibold text-lg">قادة المشرفين</h3>
        <span class="badge-soft"><i class="fa-solid fa-chart-pie"></i> العملاء</span>
      </div>
      <div class="space-y-4">
        <?php if (!empty($topClientLeaders)): ?>
          <?php foreach ($topClientLeaders as $leader): ?>
            <?php $percentage = $totalClients > 0 ? min(100, round(($leader['total_clients'] / $totalClients) * 100)) : 0; ?>
            <div>
              <div class="flex items-center justify-between text-sm">
                <span class="font-semibold text-gray-800"><?= $leader['username'] ? htmlspecialchars($leader['username']) : '—' ?></span>
                <span class="text-gray-500"><?= number_format($leader['total_clients']) ?> عملاء</span>
              </div>
              <div class="mt-2 h-2 rounded-full bg-gray-200 overflow-hidden">
                <div class="h-full gradB" style="width: <?= $percentage ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="text-sm text-gray-500 text-center py-6">لا يوجد بيانات لعرضها حالياً.</div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="otp" class="panel round soft p-6 space-y-6">
    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <h3 class="font-semibold text-lg">تتبّع الأكواد (OTP)</h3>
        <p class="text-sm text-gray-500">رشّح الفترات وتابع الأداء التفصيلي لجميع المشرفين.</p>
      </div>
      <span class="badge-soft"><i class="fa-regular fa-clock"></i> آخر تحديث <?= date('H:i') ?></span>
    </div>
    <form method="GET" class="grid gap-3 md:grid-cols-5">
      <div>
        <label class="text-xs muted mb-1 block">من</label>
        <input type="date" name="from" value="<?= htmlspecialchars($filter_from) ?>" class="w-full chip px-3 py-2 round">
      </div>
      <div>
        <label class="text-xs muted mb-1 block">إلى</label>
        <input type="date" name="to" value="<?= htmlspecialchars($filter_to) ?>" class="w-full chip px-3 py-2 round">
      </div>
      <div>
        <label class="text-xs muted mb-1 block">المشرف</label>
        <select name="admin_id" class="w-full chip px-3 py-2 round">
          <option value="">الكل</option>
          <?php foreach ($adminsList as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $filter_admin===(int)$a['id']?'selected':'' ?>><?= htmlspecialchars($a['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-end">
        <button class="w-full pill px-4 py-2 bg-black text-white">تطبيق</button>
      </div>
      <div class="flex items-end">
        <a href="?export=otp&from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?><?= $filter_admin!==null ? '&admin_id='.$filter_admin : '' ?>" class="w-full pill px-4 py-2 bg-white text-gray-700 border border-gray-200 text-center">تحميل CSV</a>
      </div>
    </form>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div class="chip px-4 py-4 round">
        <div class="text-xs muted">إجمالي الطلبات</div>
        <div class="text-2xl font-bold"><?= number_format($otpTotalInRange) ?></div>
      </div>
      <div class="chip px-4 py-4 round">
        <div class="text-xs muted">عملاء فريدون</div>
        <div class="text-2xl font-bold"><?= number_format($otpUniqueClients) ?></div>
      </div>
      <div class="chip px-4 py-4 round">
        <div class="text-xs muted">أعلى مشرف</div>
        <div class="text-base font-semibold"><?= htmlspecialchars($topAdminName) ?></div>
      </div>
      <div class="chip px-4 py-4 round">
        <div class="text-xs muted">نسبة حصته</div>
        <div class="text-2xl font-bold"><?= $topShare ?>%</div>
      </div>
    </div>
    <div class="table-surface overflow-x-auto">
      <div class="flex items-center justify-between px-4 py-3">
        <div class="font-semibold">آخر 100 عملية</div>
        <input id="otpSearch" class="chip round px-3 py-2 w-60" placeholder="بحث سريع...">
      </div>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>العميل</th>
            <th>المشرف</th>
            <th>الكود / الهاش</th>
            <th>التاريخ</th>
            <th>منذ</th>
          </tr>
        </thead>
        <tbody id="otpTableBody">
          <?php if (!empty($otpRecent)): ?>
            <?php foreach ($otpRecent as $r): ?>
              <?php
                $clientName = $r['client_name'] ? htmlspecialchars($r['client_name']) : ($r['client_id'] ? 'عميل #'.(int)$r['client_id'] : '—');
                $clientRef = $r['client_id'] ? '#'.(int)$r['client_id'] : '';
                $adminName = $r['admin_name'] ? htmlspecialchars($r['admin_name']) : ($r['admin_id'] ? 'مشرف #'.(int)$r['admin_id'] : '—');
                $adminRef = $r['admin_id'] ? '#'.(int)$r['admin_id'] : '';
              ?>
              <tr class="hover:bg-gray-50">
                <td><?= (int)$r['id'] ?></td>
                <td>
                  <div class="font-medium text-gray-800"><?= $clientName ?></div>
                  <?php if ($clientRef): ?><div class="text-xs text-gray-400"><?= $clientRef ?></div><?php endif; ?>
                </td>
                <td>
                  <div class="font-medium text-gray-800"><?= $adminName ?></div>
                  <?php if ($adminRef): ?><div class="text-xs text-gray-400"><?= $adminRef ?></div><?php endif; ?>
                </td>
                <td><code class="text-xs"><?= htmlspecialchars($r['code']) ?></code></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td><?= formatRelativeTime($r['created_at'] ?? null) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="text-center py-6 text-gray-500">لا توجد سجلات مطابقة للمعايير الحالية.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section id="admins" class="space-y-6">
    <div class="panel round soft p-6 space-y-6">
      <div class="flex items-center justify-between">
        <div>
          <h3 class="font-semibold text-lg">إدارة المشرفين</h3>
          <p class="text-sm text-gray-500">إنشئ حسابات جديدة، عدّل الصلاحيات، وتابع حالة الاشتراكات بسهولة.</p>
        </div>
        <a href="#admin-create" class="badge-soft"><i class="fa-solid fa-plus"></i> مشرف جديد</a>
      </div>
      <?php if ($success): ?>
        <div class="round px-4 py-3 bg-emerald-50 text-emerald-700 border border-emerald-200"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="round px-4 py-3 bg-rose-50 text-rose-700 border border-rose-200"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr),minmax(0,2fr)]">
        <div id="admin-create" class="card round soft p-5 space-y-4">
          <div>
            <h4 class="font-semibold text-base">إنشاء مشرف جديد</h4>
            <p class="text-xs text-gray-500">أدخل بيانات المشرف الجديد لتمنحه صلاحية الوصول إلى النظام.</p>
          </div>
          <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="space-y-1">
              <label class="text-xs muted">اسم المستخدم</label>
              <input type="text" name="username" placeholder="اسم المستخدم" required class="chip round px-3 py-2 w-full">
            </div>
            <div class="space-y-1">
              <label class="text-xs muted">كلمة المرور</label>
              <input type="password" name="password" placeholder="كلمة المرور" required class="chip round px-3 py-2 w-full">
            </div>
            <div class="space-y-1">
              <label class="text-xs muted">تاريخ الانتهاء</label>
              <input type="date" name="expiry_date" class="chip round px-3 py-2 w-full">
            </div>
            <button type="submit" name="add_admin" class="pill px-5 py-2 bg-black text-white w-full flex items-center justify-center gap-2">
              <i class="fa-solid fa-user-plus"></i>
              إضافة مشرف
            </button>
          </form>
        </div>
        <div class="table-surface card round soft p-0 overflow-hidden">
          <table>
            <thead>
              <tr>
                <th>المشرف</th>
                <th>العملاء</th>
                <th>الإيميلات</th>
                <th>الحالة</th>
                <th>الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($supervisors)): ?>
                <?php foreach ($supervisors as $admin):
                  $statusText = 'مشترك';
                  $badge = 'badge-soft border border-emerald-200 text-emerald-700 bg-emerald-50';
                  if (!empty($admin['expiry_date'])) {
                      $exp = strtotime($admin['expiry_date']);
                      $now = time();
                      $days = ($exp - $now) / 86400;
                      if ($exp < $now) {
                          $statusText = 'منتهي';
                          $badge = 'badge-soft border border-rose-200 text-rose-700 bg-rose-50';
                      } elseif ($days <= 7) {
                          $statusText = 'قريب الانتهاء';
                          $badge = 'badge-soft border border-amber-200 text-amber-700 bg-amber-50';
                      }
                  }
                ?>
                <tr class="hover:bg-gray-50">
                  <td>
                    <div class="flex flex-col gap-1">
                      <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-semibold text-gray-800"><?= htmlspecialchars($admin['username']) ?></span>
                        <span class="<?= $badge ?> text-xs px-3 py-1"><?= $statusText ?></span>
                      </div>
                      <span class="text-xs text-gray-500">الانتهاء: <?= htmlspecialchars($admin['expiry_date'] ?? 'غير محدد') ?></span>
                    </div>
                  </td>
                  <td class="text-center font-semibold text-gray-800"><?= number_format((int)$admin['clientCount']) ?></td>
                  <td class="text-center font-semibold text-gray-800"><?= number_format((int)$admin['emailCount']) ?></td>
                  <td class="text-center">
                    <span class="badge-soft <?= $admin['is_active'] ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-rose-50 text-rose-600 border border-rose-200' ?>">
                      <?= $admin['is_active'] ? 'نشط' : 'موقوف' ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int)$admin['id'] !== (int)$_SESSION['super_admin_id']): ?>
                      <div class="table-actions">
                        <button type="button" class="action-btn edit-admin"
                          data-id="<?= (int)$admin['id'] ?>"
                          data-username="<?= htmlspecialchars($admin['username'], ENT_QUOTES, 'UTF-8') ?>"
                          data-expiry="<?= htmlspecialchars($admin['expiry_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                          <i class="fa-regular fa-pen-to-square"></i>
                          تعديل
                        </button>
                        <form method="POST" onsubmit="return confirm('تأكيد الحذف؟')">
                          <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                          <button type="submit" name="delete_admin" class="action-btn">
                            <i class="fa-regular fa-trash-can"></i>
                            حذف
                          </button>
                        </form>
                        <form method="POST">
                          <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                          <input type="hidden" name="is_active" value="<?= $admin['is_active']?0:1 ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                          <button type="submit" name="toggle_status" class="action-btn">
                            <i class="fa-solid fa-power-off"></i>
                            <?= $admin['is_active']?'إيقاف':'تنشيط' ?>
                          </button>
                        </form>
                        <a href="admin_details.php?admin_id=<?= (int)$admin['id'] ?>" class="action-btn primary">
                          <i class="fa-solid fa-arrow-up-right-from-square"></i>
                          تفاصيل
                        </a>
                      </div>
                    <?php else: ?>
                      <span class="text-xs text-gray-400">هذا هو حسابك الحالي.</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="text-center py-6 text-gray-500">لا يوجد مشرفون مسجّلون بعد.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <!-- MODAL: Edit -->
  <div id="editAdminModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-50">
    <div class="panel round soft p-6 w-[92%] max-w-md">
      <div class="font-semibold mb-3">تعديل المشرف</div>
      <form id="editAdminForm" class="space-y-3">
        <input type="hidden" name="admin_id" id="editAdminId">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="space-y-1">
          <label class="text-xs muted" for="editUsername">اسم المستخدم</label>
          <input type="text" name="username" id="editUsername" required class="chip round px-3 py-2 w-full" placeholder="اسم المستخدم">
        </div>
        <div class="space-y-1">
          <label class="text-xs muted" for="editExpiryDate">تاريخ الانتهاء</label>
          <input type="date" name="expiry_date" id="editExpiryDate" class="chip round px-3 py-2 w-full">
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" id="closeModal" class="chip round px-4 py-2">إلغاء</button>
          <button type="submit" name="edit_admin" class="pill px-4 py-2 bg-black text-white">حفظ</button>
        </div>
      </form>
    </div>
  </div>

</main>

<!-- ICONS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js"></script>

<script>
  // Modal
  const modal = document.getElementById('editAdminModal');
  const closeModalBtn = document.getElementById('closeModal');
  document.querySelectorAll('.edit-admin').forEach(btn=>{
    btn.addEventListener('click',()=>{
      document.getElementById('editAdminId').value = btn.dataset.id;
      document.getElementById('editUsername').value = btn.dataset.username || '';
      document.getElementById('editExpiryDate').value = btn.dataset.expiry || '';
      modal.classList.remove('hidden'); modal.classList.add('flex');
    });
  });
  closeModalBtn && closeModalBtn.addEventListener('click',()=>{ modal.classList.add('hidden'); modal.classList.remove('flex'); });
  modal && modal.addEventListener('click',(e)=>{ if(e.target===modal){ closeModalBtn.click(); } });

  // Table search
  const otpSearch = document.getElementById('otpSearch');
  const rows = Array.from(document.querySelectorAll('#otpTableBody tr'));
  otpSearch && otpSearch.addEventListener('input', (e)=>{
    const q = e.target.value.toLowerCase();
    rows.forEach(tr=>{
      const text = tr.innerText.toLowerCase();
      tr.style.display = text.includes(q) ? '' : 'none';
    });
  });

  // Charts
  const chartDaysCtx = document.getElementById('chartDays');
  if(chartDaysCtx){
    new Chart(chartDaysCtx,{
      type:'line',
      data:{
        labels: <?= json_encode($chartDays, JSON_UNESCAPED_UNICODE) ?>,
        datasets:[{ data: <?= json_encode($chartCounts, JSON_UNESCAPED_UNICODE) ?>, tension:.35, fill:true, borderWidth:2, pointRadius:0 }]
      },
      options:{ plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
    });
  }
  const chartAdminsCtx = document.getElementById('chartAdmins');
  if(chartAdminsCtx){
    new Chart(chartAdminsCtx,{
      type:'bar',
      data:{
        labels: <?= json_encode(array_map(fn($r)=>$r['username']??'—',$otpTopAdmins), JSON_UNESCAPED_UNICODE) ?>,
        datasets:[{ data: <?= json_encode(array_map(fn($r)=>(int)$r['cnt'],$otpTopAdmins)) ?>, borderWidth:1 }]
      },
      options:{ plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
    });
  }

  // Donut
  const donutCtx = document.getElementById('donut');
  if(donutCtx){
    new Chart(donutCtx,{
      type:'doughnut',
      data:{ labels:['Top Admin','Others'],
        datasets:[{ data:[<?= (float)$topShare ?>, <?= 100 - (float)$topShare ?>] }] },
      options:{ plugins:{legend:{display:false}}, cutout:'70%' }
    });
  }
</script>
</body>
</html>
