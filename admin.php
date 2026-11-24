<?php
// إعدادات الاتصال
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/database.php';
start_admin_session();
$conn = letters_game_db_connection();
ensure_admin_auth_table($conn);

$message = "";
$authError = "";
$credentials = $conn->query('SELECT password_hash, failed_attempts, locked_until FROM admin_credentials WHERE id = 1')->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    if (!valid_admin_csrf($_POST['csrf_token'] ?? null)) {
        $authError = "انتهت صلاحية الطلب. حدّث الصفحة ثم حاول مجدداً.";
    } elseif ($credentials['password_hash'] === null) {
        $authError = "أنشئ كلمة مرور المشرف أولاً.";
    } elseif ($credentials['locked_until'] !== null && (int)$credentials['locked_until'] > time()) {
        $minutes = (int)ceil(((int)$credentials['locked_until'] - time()) / 60);
        $authError = "تم إيقاف محاولات الدخول مؤقتاً. حاول بعد $minutes دقيقة.";
    } elseif (password_verify($_POST['password'] ?? '', $credentials['password_hash'])) {
        $conn->query('UPDATE admin_credentials SET failed_attempts = 0, locked_until = NULL WHERE id = 1');
        session_regenerate_id(true);
        $_SESSION['letters_game_admin'] = true;
        unset($_SESSION['admin_csrf_token']);
        header('Location: admin.php');
        exit;
    } else {
        $attempts = (int)$credentials['failed_attempts'] + 1;
        $lockedUntil = $attempts >= 5 ? time() + 900 : null;
        $update = $conn->prepare('UPDATE admin_credentials SET failed_attempts = ?, locked_until = ? WHERE id = 1');
        $update->bind_param('ii', $attempts, $lockedUntil);
        $update->execute();
        $authError = $lockedUntil === null
            ? "كلمة المرور غير صحيحة. المحاولات المتبقية: " . (5 - $attempts) . "."
            : "تجاوزت عدد المحاولات المسموح. حاول بعد 15 دقيقة.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_setup'])) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!valid_admin_csrf($_POST['csrf_token'] ?? null)) {
        $authError = "انتهت صلاحية الطلب. حدّث الصفحة ثم حاول مجدداً.";
    } elseif (!is_local_admin_setup()) {
        $authError = "لأمان الحساب، يجب إنشاء كلمة مرور المشرف من جهاز الخادم نفسه أولاً.";
    } elseif ($credentials['password_hash'] !== null) {
        $authError = "تم إعداد كلمة مرور المشرف مسبقاً. سجّل الدخول.";
    } elseif (strlen($password) < 12) {
        $authError = "يجب أن تتكون كلمة المرور من 12 حرفاً أو أكثر.";
    } elseif ($password !== $confirm) {
        $authError = "كلمتا المرور غير متطابقتين.";
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $setup = $conn->prepare('UPDATE admin_credentials SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = 1 AND password_hash IS NULL');
        $setup->bind_param('s', $passwordHash);
        $setup->execute();
        if ($setup->affected_rows !== 1) {
            $authError = "تم إعداد كلمة مرور المشرف من جلسة أخرى. حدّث الصفحة وسجّل الدخول.";
        } else {
            session_regenerate_id(true);
            $_SESSION['letters_game_admin'] = true;
            unset($_SESSION['admin_csrf_token']);
            header('Location: admin.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_logout'])) {
    if (valid_admin_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: admin.php');
        exit;
    }
    $authError = "انتهت صلاحية الطلب. حدّث الصفحة ثم حاول مجدداً.";
}

if (!is_admin_authenticated()) {
    $credentials = $conn->query('SELECT password_hash FROM admin_credentials WHERE id = 1')->fetch_assoc();
    $setupRequired = $credentials['password_hash'] === null;
    $canSetup = !$setupRequired || is_local_admin_setup();
    $csrfToken = htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= $setupRequired ? 'إعداد كلمة مرور المشرف' : 'دخول المشرف' ?></title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f6f9; padding: 20px; }
            .login-box { max-width: 460px; margin: 10vh auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 18px #0001; }
            label { display: block; margin: 16px 0 6px; font-weight: bold; }
            input { width: 100%; padding: 12px; border: 1px solid #ccd3da; border-radius: 6px; box-sizing: border-box; }
            button, .home-link { display: inline-block; margin-top: 18px; border: 0; border-radius: 6px; padding: 11px 18px; background: #2c3e50; color: white; font-size: 16px; text-decoration: none; cursor: pointer; }
            .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; }
            .hint { color: #59636e; line-height: 1.7; }
        </style>
    </head>
    <body>
        <main class="login-box">
            <h1><?= $setupRequired ? 'إعداد كلمة مرور المشرف' : 'دخول المشرف' ?></h1>
            <p class="hint"><?= $setupRequired
                ? ($canSetup
                    ? 'هذه أول مرة تفتح فيها لوحة المشرف. أنشئ كلمة مرور خاصة لا تقل عن 12 حرفاً، واحتفظ بها.'
                    : 'يجب على المشرف إنشاء كلمة المرور أولاً من جهاز الخادم نفسه، ثم يمكنه تسجيل الدخول من هنا.')
                : 'أدخل كلمة مرور المشرف لفتح لوحة الإدارة.' ?></p>
            <?php if ($authError !== ''): ?><div class="error"><?= htmlspecialchars($authError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($canSetup): ?><form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <label for="admin-password">كلمة المرور</label>
                <input id="admin-password" type="password" name="password" autocomplete="<?= $setupRequired ? 'new-password' : 'current-password' ?>" required minlength="<?= $setupRequired ? '12' : '1' ?>">
                <?php if ($setupRequired): ?>
                    <label for="confirm-password">تأكيد كلمة المرور</label>
                    <input id="confirm-password" type="password" name="confirm_password" autocomplete="new-password" required minlength="12">
                    <button type="submit" name="admin_setup">حفظ كلمة المرور</button>
                <?php else: ?>
                    <button type="submit" name="admin_login">دخول</button>
                <?php endif; ?>
            </form><?php endif; ?>
            <a class="home-link" href="index.php">العودة إلى الصفحة الرئيسية</a>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    (isset($_POST['manual_submit']) || isset($_POST['csv_submit'])) &&
    !valid_admin_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('انتهت صلاحية الطلب. حدّث الصفحة ثم حاول مجدداً.');
}

$activeRooms = $conn->query("SELECT room_code, status, created_at FROM game_rooms WHERE status IN ('lobby', 'playing') ORDER BY created_at DESC LIMIT 30")->fetch_all(MYSQLI_ASSOC);
$qrServerIp = gethostbyname(gethostname());
if (!filter_var($qrServerIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || strpos($qrServerIp, '127.') === 0) {
    $qrServerIp = '';
}

// 1. معالجة الإضافة اليدوية
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['manual_submit'])) {
    $letter = $conn->real_escape_string($_POST['letter']);
    $question = $conn->real_escape_string($_POST['question']);
    $answer = $conn->real_escape_string($_POST['answer']);
    
    $sql = "INSERT INTO questions (letter, question, answer) VALUES ('$letter', '$question', '$answer')";
    if ($conn->query($sql) === TRUE) {
        $message = "<div class='success'>تم إضافة السؤال بنجاح!</div>";
    } else {
        $message = "<div class='error'>خطأ: " . $conn->error . "</div>";
    }
}

// 2. معالجة الرفع من ملف CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csv_submit']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        // لتخطي السطر الأول (الترويسات) إذا كان موجوداً
        fgetcsv($handle); 
        
        $count = 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) >= 3) {
                $letter = $conn->real_escape_string(trim($data[0]));
                $question = $conn->real_escape_string(trim($data[1]));
                $answer = $conn->real_escape_string(trim($data[2]));
                
                $conn->query("INSERT INTO questions (letter, question, answer) VALUES ('$letter', '$question', '$answer')");
                $count++;
            }
        }
        fclose($handle);
        $message = "<div class='success'>تم استيراد $count سؤال من الملف بنجاح!</div>";
    } else {
        $message = "<div class='error'>حدث خطأ أثناء قراءة الملف.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="admin-csrf-token" content="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>إدارة أسئلة المسابقة</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: #f4f6f9; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h2 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        .btn { background-color: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 16px; margin-top: 10px; }
        .btn:hover { background-color: #2980b9; }
        .success { background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .error { background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .divider { height: 1px; background: #eee; margin: 30px 0; }
        .room-result { display: none; background: #e8f4fd; padding: 15px; border-radius: 5px; margin-top: 15px; }
        .room-code { font-size: 28px; font-weight: bold; letter-spacing: 4px; color: #2c3e50; }
        .room-admin { display: none; background: #f8fbff; border: 1px solid #dce8f5; padding: 22px; border-radius: 10px; margin: 20px 0; }
        .admin-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin: 16px 0; }
        .admin-card { background: #fff; border: 1px solid #e4e8ed; border-radius: 8px; padding: 16px; }
        .admin-question { font-size: 20px; font-weight: bold; line-height: 1.7; }
        .admin-timer { font-size: 34px; font-weight: bold; color: #2c3e50; }
        .admin-scores { font-size: 22px; font-weight: bold; }
        .team-red { color: #ee5253; }
        .team-blue { color: #2e86de; }
        .results-table { width: 100%; border-collapse: collapse; margin-top: 12px; background: #fff; }
        .results-table th, .results-table td { border: 1px solid #e1e6eb; padding: 9px; text-align: right; }
        .admin-error { color: #b42318; font-weight: bold; margin-top: 12px; }
        #admin-join-qr-modal { display:none; position:fixed; inset:0; z-index:1000; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,.75); }
        #admin-join-qr-modal .qr-content { width:min(100%,420px); padding:24px; border-radius:14px; background:white; text-align:center; }
        #admin-join-qr-image { display:none; width:min(100%,280px); aspect-ratio:1; margin:16px auto; }
        #admin-join-qr-message { overflow-wrap:anywhere; color:#475569; font-weight:bold; }
        #admin-join-base-url { width:100%; box-sizing:border-box; padding:10px; margin:8px 0; direction:ltr; }
        #admin-join-link { display:none; overflow-wrap:anywhere; }
    </style>
</head>
<body data-server-ip="<?= htmlspecialchars($qrServerIp, ENT_QUOTES, 'UTF-8') ?>">

<div class="container">
    <h1>لوحة تحكم المسابقة</h1>
    <form method="POST" style="text-align:left;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" name="admin_logout" class="btn" style="background:#7f8c8d;">تسجيل الخروج</button>
    </form>

    <section id="room-admin-panel" class="room-admin">
        <h2>إدارة الغرفة <span id="admin-room-code"></span></h2>
        <p><a id="presentation-link" target="_blank" rel="noopener" class="btn" style="background:#673ab7;text-decoration:none;display:inline-block;">فتح شاشة النتائج للشاشة الكبيرة</a></p>
        <p id="admin-join-qr-controls" style="display:none;">
            <button type="button" class="btn" id="admin-show-join-qr">عرض QR لانضمام المتسابقين</button>
        </p>
        <div id="admin-error" class="admin-error"></div>
        <div class="admin-grid">
            <div class="admin-card">
                <h3>المؤقت</h3>
                <div id="admin-timer" class="admin-timer">--:--</div>
                <label for="admin-timer-seconds">الوقت لكل سؤال (بالثواني، 5 إلى 600):</label>
                <input type="number" id="admin-timer-seconds" min="5" max="600" value="60">
                <button type="button" id="reset-timer-btn" class="btn">بدء / إعادة ضبط المؤقت</button>
                <p id="timer-help">يبدأ العد عند اختيار سؤال، ويمكن إعادة ضبطه من هنا.</p>
            </div>
            <div class="admin-card">
                <h3>النتيجة</h3>
                <div id="admin-scores" class="admin-scores"></div>
                <p id="admin-room-status"></p>
                <div id="admin-players"></div>
            </div>
        </div>
        <div class="admin-card">
            <h3>السؤال الحالي وإجابة النموذج</h3>
            <div id="admin-current-question">بانتظار اختيار أحد اللاعبين لمربع.</div>
            <div id="admin-current-answer"></div>
            <div id="resolve-controls" style="display:none;">
                <p><strong>حدد الفريق ولاعب الفريق الذي أجاب إجابة صحيحة:</strong></p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:12px;">
                    <label>لاعب أحمر
                        <select id="answer-player-red" style="display:block;min-width:180px;padding:8px;"><option value="">اختر اللاعب</option></select>
                    </label>
                    <label>لاعب أزرق
                        <select id="answer-player-blue" style="display:block;min-width:180px;padding:8px;"><option value="">اختر اللاعب</option></select>
                    </label>
                </div>
                <button type="button" class="btn" style="background:#ee5253" onclick="resolveQuestion('red')">نقطة للأحمر</button>
                <button type="button" class="btn" style="background:#2e86de" onclick="resolveQuestion('blue')">نقطة للأزرق</button>
                <button type="button" class="btn" style="background:#8395a7" onclick="resolveQuestion('none')">لم يجب أحد</button>
            </div>
        </div>
        <div class="admin-card" style="margin-top:14px;">
            <h3>سجل النتائج</h3>
            <div id="admin-results">لا توجد نتائج مسجلة بعد.</div>
            <button type="button" id="undo-result-btn" class="btn" style="background:#8395a7" onclick="undoAdminResult()">التراجع عن آخر نتيجة</button>
        </div>
        <div class="admin-card" style="margin-top:14px;">
            <h3>إنهاء المباراة</h3>
            <p>اختر الفريق الفائز بالمباراة لإعلان النتيجة لجميع اللاعبين.</p>
            <div id="finish-controls">
                <button type="button" class="btn" style="background:#ee5253" onclick="finishGame('red')">إعلان فوز الأحمر</button>
                <button type="button" class="btn" style="background:#2e86de" onclick="finishGame('blue')">إعلان فوز الأزرق</button>
            </div>
        </div>
    </section>

    <div id="admin-join-qr-modal" role="dialog" aria-modal="true" aria-labelledby="admin-join-qr-title">
        <div class="qr-content">
            <h2 id="admin-join-qr-title">امسح الرمز للانضمام</h2>
            <p id="admin-join-qr-message" aria-live="polite"></p>
            <label for="admin-join-base-url">رابط صفحة الانضمام المتاح للهاتف:</label>
            <input id="admin-join-base-url" type="url" placeholder="http://192.168.1.20:8080/letters_game/index.php">
            <button type="button" class="btn" id="admin-generate-join-qr">إنشاء رمز QR</button>
            <img id="admin-join-qr-image" alt="رمز QR لفتح صفحة الانضمام إلى المسابقة">
            <p><a id="admin-join-link" target="_blank" rel="noopener">فتح رابط الانضمام</a></p>
            <button type="button" class="btn" id="admin-copy-join-link" style="display:none;">نسخ رابط الانضمام</button>
            <button type="button" class="btn" id="admin-close-join-qr" style="background:#8395a7;">إغلاق</button>
        </div>
    </div>

    <section id="active-rooms-section">
        <h2>الغرف النشطة</h2>
        <?php if ($activeRooms): ?>
            <table class="results-table">
                <thead><tr><th>رمز الغرفة</th><th>الحالة</th><th>تاريخ الإنشاء</th><th>إدارة</th></tr></thead>
                <tbody>
                <?php foreach ($activeRooms as $activeRoom): ?>
                    <tr>
                        <td><?= htmlspecialchars($activeRoom['room_code'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $activeRoom['status'] === 'playing' ? 'اللعبة جارية' : 'بانتظار اللاعبين' ?></td>
                        <td><?= htmlspecialchars($activeRoom['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><a href="admin.php?room=<?= rawurlencode($activeRoom['room_code']) ?>">فتح لوحة الغرفة</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>لا توجد غرف نشطة حالياً.</p>
        <?php endif; ?>
    </section>
    
    <?= $message ?>

    <h2>إنشاء غرفة لعب جماعي</h2>
    <p>حدد العدد الإجمالي للاعبين، ثم شارك رمز الغرفة مع اللاعبين. تبدأ اللعبة تلقائياً عند اكتمال العدد.</p>
    <form id="room-form">
        <div class="form-group">
            <label for="competition-title">عنوان المسابقة:</label>
            <input type="text" id="competition-title" maxlength="160" value="مسابقة الحروف التفاعلية" required>
        </div>
        <div class="form-group">
            <label for="max-players">عدد اللاعبين (2 إلى 50):</label>
            <input type="number" id="max-players" min="2" max="50" value="4" required>
        </div>
        <div class="form-group">
            <label for="room-timer-seconds">مدة السؤال بالثواني (5 إلى 600):</label>
            <input type="number" id="room-timer-seconds" min="5" max="600" value="60" required>
        </div>
        <div class="form-group">
            <label for="answer-mode">طريقة احتساب الإجابة:</label>
            <select id="answer-mode" required>
                <option value="fastest_player">المتسابقون يكتبون الإجابة — أول إجابة صحيحة تفوز</option>
                <option value="admin_judged">المشرف يحدد الفريق الفائز يدوياً</option>
            </select>
        </div>
        <button type="submit" class="btn">إنشاء غرفة</button>
    </form>
    <div id="room-error" class="error" style="display:none;"></div>
    <div id="room-result" class="room-result">
        <div>رمز الغرفة:</div>
        <div id="room-code" class="room-code"></div>
        <div>رابط انضمام اللاعبين:</div>
        <div id="room-link"></div>
        <button type="button" class="btn" onclick="copyRoomLink()">نسخ رابط الانضمام</button>
        <p><a id="admin-room-link" target="_blank" rel="noopener">فتح لوحة إدارة هذه الغرفة</a></p>
    </div>

    <div class="divider"></div>

    <!-- نموذج الإضافة اليدوية -->
    <h2>إضافة سؤال واحد يدوياً</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group">
            <label>الحرف:</label>
            <select name="letter" required>
                <?php
                $letters = ['أ','ب','ت','ث','ج','ح','خ','د','ذ','ر','ز','س','ش','ص','ض','ط','ظ','ع','غ','ف','ق','ك','ل','م','ن'];
                foreach ($letters as $l) {
                    echo "<option value='$l'>$l</option>";
                }
                ?>
            </select>
        </div>
        <div class="form-group">
            <label>السؤال:</label>
            <input type="text" name="question" required placeholder="اكتب السؤال هنا...">
        </div>
        <div class="form-group">
            <label>الإجابة الصحيحة:</label>
            <input type="text" name="answer" required placeholder="اكتب الإجابة هنا...">
        </div>
        <button type="submit" name="manual_submit" class="btn">حفظ السؤال</button>
    </form>

    <div class="divider"></div>

    <!-- نموذج رفع ملف CSV -->
    <h2>إضافة أسئلة متعددة (عبر ملف CSV)</h2>
    <p style="color: #7f8c8d; font-size: 14px;">يجب أن يحتوي الملف على 3 أعمدة بالترتيب التالي: (الحرف، السؤال، الإجابة).</p>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group">
            <label>اختر ملف (CSV):</label>
            <input type="file" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" name="csv_submit" class="btn" style="background-color: #2ecc71;">رفع واستيراد الأسئلة</button>
    </form>
</div>

<script>
    let inviteUrl = '';
    let adminRoomCode = '';
    let adminKey = '';
    let adminState = null;
    let adminPollTimer = null;
    let adminClockTimer = null;
    document.getElementById('room-form').addEventListener('submit', async function (event) {
        event.preventDefault();
        const errorBox = document.getElementById('room-error');
        const resultBox = document.getElementById('room-result');
        errorBox.style.display = 'none';
        resultBox.style.display = 'none';
        try {
            const response = await fetch('multiplayer_api.php?action=create', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="admin-csrf-token"]').content},
                body: JSON.stringify({
                    title: document.getElementById('competition-title').value,
                    max_players: document.getElementById('max-players').value,
                    timer_seconds: document.getElementById('room-timer-seconds').value,
                    answer_mode: document.getElementById('answer-mode').value
                })
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'تعذر إنشاء الغرفة.');
            document.getElementById('room-code').textContent = data.room_code;
            inviteUrl = new URL('index.php?room=' + encodeURIComponent(data.room_code), window.location.href).href;
            document.getElementById('room-link').textContent = inviteUrl;
            adminRoomCode = data.room_code;
            adminKey = data.admin_token;
            document.getElementById('admin-room-link').href = new URL(
                'admin.php?room=' + encodeURIComponent(adminRoomCode) + '&key=' + encodeURIComponent(adminKey),
                window.location.href
            ).href;
            resultBox.style.display = 'block';
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.style.display = 'block';
        }
    });

    async function copyRoomLink() {
        try {
            await navigator.clipboard.writeText(inviteUrl);
        } catch (error) {
            document.getElementById('room-link').textContent = 'انسخ الرابط يدوياً: ' + inviteUrl;
        }
    }

    async function adminRequest(action, data = {}, method = 'POST') {
        const options = {method, headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="admin-csrf-token"]').content
        }};
        if (method !== 'GET') {
            options.body = JSON.stringify({...data, room_code: adminRoomCode, admin_token: adminKey});
        }
        const query = new URLSearchParams({action, room: adminRoomCode, key: adminKey});
        const response = await fetch('multiplayer_api.php?' + query.toString(), options);
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'تعذر تنفيذ طلب الإدارة.');
        return result;
    }

    function showAdminError(message) {
        document.getElementById('admin-error').textContent = message || '';
    }

    async function refreshRoomAdmin() {
        try {
            adminState = await adminRequest('admin_state', {}, 'GET');
            renderRoomAdmin();
            showAdminError('');
        } catch (error) {
            showAdminError(error.message);
        }
    }

    function renderRoomAdmin() {
        if (!adminState) return;
        document.querySelector('#room-admin-panel h2').textContent = 'إدارة المسابقة: ' +
            (adminState.title || 'مسابقة الحروف التفاعلية') + ' — الغرفة ' + adminState.room_code;
        document.getElementById('presentation-link').href = new URL(
            'index.php?display=1&room=' + encodeURIComponent(adminState.room_code),
            window.location.href
        ).href;
        const timerInput = document.getElementById('admin-timer-seconds');
        if (document.activeElement !== timerInput) timerInput.value = adminState.timer_seconds;
        document.getElementById('admin-scores').innerHTML =
            `<span class="team-red">الأحمر: ${adminState.scores.red}</span> &nbsp;—&nbsp; ` +
            `<span class="team-blue">الأزرق: ${adminState.scores.blue}</span>`;
        const statusNames = {lobby: 'بانتظار اللاعبين', playing: 'اللعبة جارية', finished: 'انتهت المباراة'};
        document.getElementById('admin-room-status').textContent =
            `${statusNames[adminState.status] || adminState.status} — ${adminState.players.length} / ${adminState.max_players} لاعب`;
        if (adminState.status === 'playing') {
            document.getElementById('admin-room-status').textContent +=
                ` — الدور: ${adminState.current_team === 'red' ? 'الأحمر' : 'الأزرق'}`;
        }
        document.getElementById('admin-room-status').textContent +=
            ` — طريقة الإجابة: ${adminState.answer_mode === 'fastest_player' ? 'سباق الإجابات' : 'تحكيم المشرف'}`;
        const canShowJoinQr = adminState.status === 'lobby' && adminState.players.length < adminState.max_players;
        document.getElementById('admin-join-qr-controls').style.display = canShowJoinQr ? 'block' : 'none';
        if (!canShowJoinQr) adminJoinQrModal.style.display = 'none';
        const playersList = document.getElementById('admin-players');
        playersList.replaceChildren();
        const redSelect = document.getElementById('answer-player-red');
        const blueSelect = document.getElementById('answer-player-blue');
        const selectedRed = redSelect.value;
        const selectedBlue = blueSelect.value;
        redSelect.innerHTML = '<option value="">اختر اللاعب</option>';
        blueSelect.innerHTML = '<option value="">اختر اللاعب</option>';
        adminState.players.forEach(player => {
            const option = document.createElement('option');
            option.value = player.player_name;
            option.textContent = player.player_name;
            (player.team === 'red' ? redSelect : blueSelect).appendChild(option.cloneNode(true));
        });
        if (selectedRed) redSelect.value = selectedRed;
        if (selectedBlue) blueSelect.value = selectedBlue;

        const playerTitle = document.createElement('strong');
        playerTitle.textContent = 'اللاعبون: ';
        playersList.appendChild(playerTitle);
        playersList.appendChild(document.createTextNode(adminState.players.map(player =>
            `${player.player_name} (${player.team === 'red' ? 'أحمر' : 'أزرق'})`
        ).join('، ') || 'لا يوجد لاعبون بعد'));

        const questionBox = document.getElementById('admin-current-question');
        const answerBox = document.getElementById('admin-current-answer');
        const resolveControls = document.getElementById('resolve-controls');
        if (adminState.status === 'finished') {
            questionBox.textContent = `انتهت المباراة — الفائز: ${adminState.winner === 'red' ? 'الفريق الأحمر' : 'الفريق الأزرق'}`;
            answerBox.textContent = '';
            resolveControls.style.display = 'none';
        } else if (adminState.question) {
            questionBox.textContent = `المربع ${adminState.question.cell + 1} — حرف ${adminState.question.letter}: ${adminState.question.question}`;
            answerBox.textContent = 'الإجابة النموذجية: ' + adminState.question.answer;
            resolveControls.style.display = 'block';
        } else {
            questionBox.textContent = adminState.status === 'finished'
                ? `الفائز بالمباراة: ${adminState.winner === 'red' ? 'الفريق الأحمر' : 'الفريق الأزرق'}`
                : 'بانتظار اختيار أحد اللاعبين لمربع.';
            answerBox.textContent = '';
            resolveControls.style.display = 'none';
        }
        document.querySelector('#resolve-controls > p').textContent =
            adminState.answer_mode === 'fastest_player'
                ? 'يسجل النظام أول إجابة صحيحة تلقائياً.'
                : 'حدد الفريق الذي أجاب إجابة صحيحة:';
        document.getElementById('resolve-controls').querySelectorAll('button').forEach(button => {
            button.style.display = adminState.answer_mode === 'admin_judged' ? 'inline-block' : 'none';
        });
        document.getElementById('reset-timer-btn').disabled = !adminState.question || adminState.status !== 'playing';
        document.getElementById('undo-result-btn').disabled = adminState.results.length === 0;
        document.getElementById('finish-controls').querySelectorAll('button').forEach(button => {
            button.disabled = adminState.status !== 'playing';
        });
        document.getElementById('resolve-controls').querySelectorAll('button').forEach(button => {
            button.disabled = adminState.status !== 'playing';
        });
        renderAdminTimer();
        renderAdminResults();
    }

    function renderAdminTimer() {
        const timer = document.getElementById('admin-timer');
        if (!adminState || adminState.timer_deadline === null) {
            timer.textContent = '--:--';
            timer.style.color = '#2c3e50';
            return;
        }
        const remaining = Math.max(0, adminState.timer_deadline - Math.floor(Date.now() / 1000));
        timer.textContent = `${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`;
        timer.style.color = remaining <= 10 ? '#b42318' : '#2c3e50';
        if (remaining === 0) document.getElementById('timer-help').textContent =
            adminState.answer_mode === 'fastest_player'
                ? 'انتهى الوقت؛ لم يفز أحد بهذا السؤال.'
                : 'انتهى الوقت؛ حدد نتيجة السؤال أو أعد ضبط المؤقت.';
        else if (adminState.question) document.getElementById('timer-help').textContent = 'المؤقت جارٍ، ويظهر الوقت نفسه للاعبين.';
    }

    function renderAdminResults() {
        const resultsBox = document.getElementById('admin-results');
        if (adminState.results.length === 0) {
            resultsBox.textContent = 'لا توجد نتائج مسجلة بعد.';
            return;
        }
        const table = document.createElement('table');
        table.className = 'results-table';
        const head = table.createTHead().insertRow();
        ['السؤال', 'الإجابة الصحيحة', 'النقطة'].forEach(label => {
            const cell = document.createElement('th');
            cell.textContent = label;
            head.appendChild(cell);
        });
        const body = table.createTBody();
        adminState.results.slice().reverse().forEach(result => {
            const row = body.insertRow();
            [result.question || '—', result.answer || '—',
                result.timed_out ? 'انتهى الوقت — لا أحد' :
                    result.player_name
                        ? `${result.player_name} — ${result.team === 'red' ? 'الأحمر' : 'الأزرق'}`
                        : result.team === 'red' ? 'الأحمر' : result.team === 'blue' ? 'الأزرق' : 'لا أحد'
            ].forEach((value, index) => {
                const cell = row.insertCell();
                cell.textContent = value;
                if (index === 2 && result.team) cell.className = result.team === 'red' ? 'team-red' : 'team-blue';
            });
        });
        resultsBox.replaceChildren(table);
    }

    async function runAdminAction(action, data = {}) {
        try {
            await adminRequest(action, data);
            await refreshRoomAdmin();
            showAdminError('');
        } catch (error) {
            showAdminError(error.message);
        }
    }

    function resolveQuestion(team) {
        const playerName = team === 'red'
            ? document.getElementById('answer-player-red').value
            : team === 'blue'
                ? document.getElementById('answer-player-blue').value
                : '';
        runAdminAction('admin_resolve', {team, player_name: playerName});
    }

    function undoAdminResult() {
        runAdminAction('admin_undo');
    }

    function finishGame(team) {
        runAdminAction('admin_finish', {team});
    }

    document.getElementById('reset-timer-btn').addEventListener('click', function () {
        runAdminAction('admin_timer', {seconds: document.getElementById('admin-timer-seconds').value});
    });

    const adminJoinQrModal = document.getElementById('admin-join-qr-modal');
    const adminJoinQrImage = document.getElementById('admin-join-qr-image');
    document.getElementById('admin-show-join-qr').addEventListener('click', function () {
        adminJoinQrImage.style.display = 'none';
        adminJoinQrImage.removeAttribute('src');
        document.getElementById('admin-join-link').style.display = 'none';
        document.getElementById('admin-copy-join-link').style.display = 'none';
        const baseUrlInput = document.getElementById('admin-join-base-url');
        const savedBaseUrl = localStorage.getItem('letters_game_join_base_url') || '';
        const isLocalHost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);
        if (isLocalHost) {
            const serverIp = document.body.dataset.serverIp;
            const localUrl = new URL(window.location.href);
            if (serverIp) {
                localUrl.hostname = serverIp;
                localUrl.pathname = localUrl.pathname.replace(/admin\.php$/i, 'index.php');
                localUrl.search = '';
                localUrl.hash = '';
            }
            baseUrlInput.value = serverIp ? localUrl.href : savedBaseUrl;
        } else {
            baseUrlInput.value = savedBaseUrl || new URL('index.php', window.location.href).href;
        }
        adminJoinQrImage.onload = function () {
            adminJoinQrImage.style.display = 'block';
            document.getElementById('admin-join-qr-message').textContent =
                'امسح الرمز لفتح صفحة الانضمام إلى الغرفة ' + adminRoomCode + '.';
        };
        adminJoinQrImage.onerror = function () {
            document.getElementById('admin-join-qr-message').textContent =
                'تعذر تحميل صورة QR. استخدم رابط الانضمام أدناه أو تحقق من اتصال الإنترنت.';
            adminJoinQrImage.style.display = 'none';
        };
        document.getElementById('admin-join-qr-message').textContent = isLocalHost && !baseUrlInput.value
            ? 'لا يمكن للهاتف فتح localhost. أدخل عنوان IP لهذا الكمبيوتر على الشبكة، مثل http://192.168.1.20:8080/letters_game/index.php، ثم أنشئ الرمز.'
            : 'اضغط «إنشاء رمز QR» لعرض رابط الانضمام.';
        adminJoinQrModal.style.display = 'flex';
        if (baseUrlInput.value) document.getElementById('admin-generate-join-qr').click();
    });
    document.getElementById('admin-generate-join-qr').addEventListener('click', function () {
        const message = document.getElementById('admin-join-qr-message');
        adminJoinQrImage.style.display = 'none';
        adminJoinQrImage.removeAttribute('src');
        try {
            const baseUrl = document.getElementById('admin-join-base-url').value.trim();
            if (!baseUrl) throw new Error('أدخل رابط صفحة الانضمام الذي يمكن للهاتف الوصول إليه.');
            const inviteUrl = new URL(baseUrl);
            if (!['http:', 'https:'].includes(inviteUrl.protocol) ||
                ['localhost', '127.0.0.1', '::1'].includes(inviteUrl.hostname)) {
                throw new Error('استخدم رابطاً أو عنوان IP يمكن للهاتف الوصول إليه، وليس localhost.');
            }
            inviteUrl.pathname = inviteUrl.pathname.replace(/(?:admin|index)\.php$/i, 'index.php');
            if (!inviteUrl.pathname.toLowerCase().endsWith('index.php')) {
                inviteUrl.pathname = inviteUrl.pathname.replace(/\/?$/, '/') + 'index.php';
            }
            inviteUrl.search = '';
            inviteUrl.hash = '';
            inviteUrl.searchParams.set('room', adminRoomCode);
            localStorage.setItem('letters_game_join_base_url', baseUrl);
            const link = document.getElementById('admin-join-link');
            link.href = inviteUrl.href;
            link.textContent = inviteUrl.href;
            link.style.display = 'inline';
            document.getElementById('admin-copy-join-link').style.display = 'inline-block';
            message.textContent = 'جارٍ إنشاء رمز QR...';
            adminJoinQrImage.src = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' +
                encodeURIComponent(inviteUrl.href);
        } catch (error) {
            message.textContent = error.message;
        }
    });
    document.getElementById('admin-copy-join-link').addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(document.getElementById('admin-join-link').href);
            document.getElementById('admin-join-qr-message').textContent = 'تم نسخ رابط الانضمام.';
        } catch (error) {
            document.getElementById('admin-join-qr-message').textContent =
                'تعذر النسخ تلقائياً؛ انسخ رابط الانضمام الظاهر أعلاه.';
        }
    });
    document.getElementById('admin-close-join-qr').addEventListener('click', function () {
        adminJoinQrModal.style.display = 'none';
    });
    adminJoinQrModal.addEventListener('click', function (event) {
        if (event.target === adminJoinQrModal) adminJoinQrModal.style.display = 'none';
    });

    const adminParams = new URLSearchParams(window.location.search);
    if (adminParams.has('room')) {
        adminRoomCode = adminParams.get('room').toUpperCase();
        adminKey = adminParams.get('key') || '';
        document.title = 'إدارة غرفة ' + adminRoomCode;
        document.querySelector('.container > h1').textContent = 'لوحة إدارة الغرفة';
        document.getElementById('room-admin-panel').style.display = 'block';
        document.querySelectorAll('.container > :not(h1):not(#room-admin-panel)').forEach(element => {
            element.style.display = 'none';
        });
        document.getElementById('admin-room-code').textContent = adminRoomCode;
        refreshRoomAdmin();
        adminPollTimer = setInterval(refreshRoomAdmin, 1500);
        adminClockTimer = setInterval(renderAdminTimer, 1000);
    }
</script>

</body>
</html>