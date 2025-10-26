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
    <a href="#overview" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-white/5">
      <i class="fa-solid fa-chart-simple"></i> نظرة عامة
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
<main class="md:pr-80 p-4 md:p-8 space-y-8">

  <!-- TOP BAR -->
  <section class="panel soft round p-4 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3">
      <div class="text-2xl font-bold">Analytics</div>
      <div class="chip pill px-3 py-1 text-sm text-gray-600">facebook.com/companyname</div>
    </div>
    <div class="flex items-center gap-3">
      <div class="chip pill px-3 py-1 text-sm text-gray-500 hidden sm:block">بحث</div>
      <a href="?export=otp&from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?><?= $filter_admin!==null ? '&admin_id='.$filter_admin : '' ?>" class="pill px-4 py-2 bg-black text-white">تصدير OTP</a>
    </div>
  </section>

  <!-- OVERVIEW GRID -->
  <section id="overview" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Gradient Big Card -->
    <div class="gradA round soft p-6 text-white relative">
      <div class="text-sm opacity-80 mb-2">إجمالي طلبات OTP (الفترة)</div>
      <div class="text-4xl font-bold"><?= number_format($otpTotalInRange) ?></div>
      <div class="mt-6 text-xs opacity-90 grid grid-cols-3 gap-2">
        <div class="chip pill px-3 py-1 bg-white/15 border-white/20">مشرفون: <?= count($otpTopAdmins) ?></div>
        <div class="chip pill px-3 py-1 bg-white/15 border-white/20">عملاء فريدون: <?= $otpUniqueClients ?></div>
        <div class="chip pill px-3 py-1 bg-white/15 border-white/20">اليوم: <?= $clientsToday ?></div>
      </div>
      <div class="absolute -bottom-6 -left-6 w-24 h-24 round gradB opacity-70 blur-sm"></div>
    </div>

    <!-- Donut / Top admin share -->
    <div class="panel round soft p-6">
      <div class="flex items-center justify-between mb-3">
        <div class="font-semibold">حصة أعلى مشرف</div>
      </div>
      <div class="flex items-center gap-6">
        <canvas id="donut" width="140" height="140"></canvas>
        <div>
          <div class="text-3xl font-bold"><?= $topShare ?>%</div>
          <div class="text-sm muted">المشرف: <b><?= htmlspecialchars($topAdminName) ?></b></div>
          <div class="text-xs mt-1 text-gray-500">عدد طلباته: <?= $topAdminCount ?></div>
        </div>
      </div>
    </div>

    <!-- Comments / counters card -->
    <div class="panel round soft p-6">
      <div class="font-semibold mb-3">ملخص سريع</div>
      <div class="grid grid-cols-2 gap-3">
        <div class="chip pill px-3 py-3">
          <div class="text-xs muted">الإيميلات المرتبطة</div>
          <div class="text-xl font-bold"><?= $totalEmails ?></div>
        </div>
        <div class="chip pill px-3 py-3">
          <div class="text-xs muted">المشرفون النشطون</div>
          <div class="text-xl font-bold"><?= $activeAdmins ?></div>
        </div>
        <div class="chip pill px-3 py-3">
          <div class="text-xs muted">عدد العملاء</div>
          <div class="text-xl font-bold"><?= $totalClients ?></div>
        </div>
        <div class="chip pill px-3 py-3">
          <div class="text-xs muted">الإيميلات اليوم</div>
          <div class="text-xl font-bold"><?= $emailsToday ?></div>
        </div>
      </div>
    </div>
  </section>

  <!-- CHARTS + QUICK LIST -->
  <section class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 panel round soft p-6">
      <div class="flex items-center justify-between mb-3">
        <div class="font-semibold">Post Stats / OTP آخر 14 يوم</div>
      </div>
      <canvas id="chartDays"></canvas>
    </div>
    <div class="panel round soft p-6">
      <div class="flex items-center justify-between mb-3">
        <div class="font-semibold">أفضل 10 مشرفين</div>
      </div>
      <canvas id="chartAdmins"></canvas>
    </div>
  </section>

  <!-- FILTERS -->
  <section id="otp" class="panel round soft p-6 space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="font-semibold text-lg">تتبّع الأكواد</h3>
      <div class="text-sm muted">آخر تحديث: <?= date('H:i') ?></div>
    </div>
    <form method="GET" class="grid md:grid-cols-4 gap-3">
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
            <option value="<?= (int)$a['id'] ?>" <?= $filter_admin===(int)$a['id']?'selected':'' ?>>
              <?= htmlspecialchars($a['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-end">
        <button class="w-full pill px-4 py-2 bg-black text-white">تطبيق</button>
      </div>
    </form>

    <!-- Quick KPIs -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div class="chip px-4 py-4 round">
        <div class="text-xs muted">إجمالي الطلبات</div>
        <div class="text-2xl font-bold"><?= $otpTotalInRange ?></div>
      </div>
      <div class="chip px-4 py-4 round">
        <div class="text-xs muted">عملاء فريدون</div>
        <div class="text-2xl font-bold"><?= $otpUniqueClients ?></div>
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

    <!-- Recent Table -->
    <div class="mt-2">
      <div class="flex items-center justify-between mb-3">
        <div class="font-semibold">آخر 100 عملية</div>
        <input id="otpSearch" class="chip round px-3 py-2 w-60" placeholder="بحث سريع...">
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b" style="border-color:var(--hair)">
            <tr class="text-gray-500">
              <th class="text-right py-2">#</th>
              <th class="text-right py-2">العميل</th>
              <th class="text-right py-2">المشرف</th>
              <th class="text-right py-2">الكود/الهاش</th>
              <th class="text-right py-2">الوقت</th>
            </tr>
          </thead>
          <tbody id="otpTableBody">
            <?php foreach ($otpRecent as $r): ?>
              <tr class="border-b hover:bg-gray-50" style="border-color:var(--hair)">
                <td class="py-2"><?= (int)$r['id'] ?></td>
                <td class="py-2">
                  <?= $r['client_name'] ? htmlspecialchars($r['client_name']).' <span class="text-xs muted">#'.$r['client_id'].'</span>' : '—' ?>
                </td>
                <td class="py-2">
                  <?= $r['admin_name'] ? htmlspecialchars($r['admin_name']).' <span class="text-xs muted">#'.$r['admin_id'].'</span>' : '—' ?>
                </td>
                <td class="py-2"><code class="text-xs"><?= htmlspecialchars($r['code']) ?></code></td>
                <td class="py-2"><?= htmlspecialchars($r['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($otpRecent)): ?>
              <tr><td colspan="5" class="py-6 text-center muted">لا توجد سجلات.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Admins Management -->
  <section id="admins" class="panel round soft p-6 space-y-6">
    <div class="flex items-center justify-between">
      <h3 class="font-semibold text-lg">إدارة المشرفين</h3>
    </div>

    <?php if ($success): ?>
      <div class="round px-4 py-3 bg-emerald-50 text-emerald-700 border border-emerald-200"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="round px-4 py-3 bg-rose-50 text-rose-700 border border-rose-200"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="grid md:grid-cols-3 gap-3">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="text" name="username" placeholder="اسم المستخدم" required class="chip round px-3 py-2">
      <input type="password" name="password" placeholder="كلمة المرور" required class="chip round px-3 py-2">
      <input type="date" name="expiry_date" class="chip round px-3 py-2">
      <div class="md:col-span-3">
        <button type="submit" name="add_admin" class="pill px-5 py-2 bg-black text-white">إضافة مشرف</button>
      </div>
    </form>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
      <?php foreach ($supervisors as $admin):
        $statusText = 'مشترك'; $badge='border-emerald-200 text-emerald-700 bg-emerald-50';
        if (!empty($admin['expiry_date'])) {
          $exp=strtotime($admin['expiry_date']); $now=time(); $days=($exp-$now)/86400;
          if ($exp < $now) { $statusText='منتهي'; $badge='border-rose-200 text-rose-700 bg-rose-50'; }
          elseif ($days <= 7) { $statusText='قريب الانتهاء'; $badge='border-amber-200 text-amber-700 bg-amber-50'; }
        }
      ?>
      <div class="card round soft p-4">
        <div class="flex items-center justify-between mb-2">
          <div class="font-semibold"><?= htmlspecialchars($admin['username']) ?></div>
          <span class="px-3 py-1 text-xs rounded-full border <?= $badge ?>"><?= $statusText ?></span>
        </div>
        <div class="text-xs muted">الإيميلات: <?= (int)$admin['emailCount'] ?> • العملاء: <?= (int)$admin['clientCount'] ?> • الحالة: <?= $admin['is_active']?'نشط':'موقوف' ?></div>
        <div class="text-xs muted mt-1">الانتهاء: <?= htmlspecialchars($admin['expiry_date'] ?? 'غير محدد') ?></div>
        <div class="flex flex-wrap gap-2 mt-3">
          <?php if ((int)$admin['id'] !== (int)$_SESSION['super_admin_id']): ?>
            <button class="chip round px-3 py-1 edit-admin"
              data-id="<?= (int)$admin['id'] ?>"
              data-username="<?= htmlspecialchars($admin['username'], ENT_QUOTES, 'UTF-8') ?>"
              data-expiry="<?= htmlspecialchars($admin['expiry_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
              تعديل
            </button>
            <form method="POST" onsubmit="return confirm('تأكيد الحذف؟')">
              <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" name="delete_admin" class="chip round px-3 py-1">حذف</button>
            </form>
            <form method="POST">
              <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
              <input type="hidden" name="is_active" value="<?= $admin['is_active']?0:1 ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" name="toggle_status" class="chip round px-3 py-1">
                <?= $admin['is_active']?'إيقاف':'تنشيط' ?>
              </button>
            </form>
          <?php else: ?>
            <span class="text-xs muted">لا يمكن تعديل حسابك</span>
          <?php endif; ?>
          <a href="admin_details.php?admin_id=<?= (int)$admin['id'] ?>" class="pill px-3 py-1 bg-black text-white">تفاصيل</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- MODAL: Edit -->
  <div id="editAdminModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-50">
    <div class="panel round p-6 w-[92%] max-w-md">
      <div class="font-semibold mb-3">تعديل المشرف</div>
      <form id="editAdminForm" class="space-y-3">
        <input type="hidden" name="admin_id" id="editAdminId">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" name="username" id="editUsername" required class="chip round px-3 py-2" placeholder="اسم المستخدم">
        <input type="date" name="expiry_date" id="editExpiryDate" class="chip round px-3 py-2">
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
