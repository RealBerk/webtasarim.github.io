<?php
/* Wellness Sağlık - Tek Dosya Admin Panel (admin.php)
   - Basit oturum açma
   - Logo yükleme (uploads/)
   - Yorum onaylama/silme (data/reviews.json)
   - Public API: list_reviews (public=1), settings
   - Form API: submit_review (kullanıcıdan yorum alır)
   Not: Demo amaçlı minimal güvenlik. Canlıya çıkmadan önce parola/izinler/CSRF gibi
   ek güvenlikleri güçlendirin.
*/

session_start();

// ==== Basit yapılandırma ====
define("ADMIN_USER", "admin");
define("ADMIN_PASS", "admin123"); // CANLI ÖNCESİ DEĞİŞTİRİN!

// ==== Yollar ====
$DATA_DIR   = __DIR__ . "/data";
$UPLOAD_DIR = __DIR__ . "/uploads";
$REVIEWS    = $DATA_DIR . "/reviews.json";
$SETTINGS   = $DATA_DIR . "/settings.json";

ensure_dirs();
ensure_files();

$action = isset($_GET["action"]) ? $_GET["action"] : "";

// === Public API: settings ===
if ($action === "settings") {
  header("Content-Type: application/json; charset=utf-8");
  $settings = read_json($SETTINGS);
  echo json_encode(["ok" => true, "settings" => $settings], JSON_UNESCAPED_UNICODE);
  exit;
}

// === Public API: list_reviews (public=1 ise yalnızca approved) ===
if ($action === "list_reviews") {
  header("Content-Type: application/json; charset=utf-8");
  $all = read_json($REVIEWS);
  $reviews = isset($all["reviews"]) ? $all["reviews"] : [];
  $public = isset($_GET["public"]) ? intval($_GET["public"]) : 0;
  if ($public === 1) {
    $reviews = array_values(array_filter($reviews, function($r) { return !empty($r["approved"]); }));
  }
  echo json_encode(["ok" => true, "reviews" => $reviews], JSON_UNESCAPED_UNICODE);
  exit;
}

// === Public API: submit_review (kullanıcı formu) ===
if ($action === "submit_review" && $_SERVER["REQUEST_METHOD"] === "POST") {
  header("Content-Type: application/json; charset=utf-8");
  $payload = json_decode(file_get_contents("php://input"), true);
  $name    = trim($payload["name"] ?? "");
  $country = trim($payload["country"] ?? "");
  $message = trim($payload["message"] ?? "");
  $rating  = intval($payload["rating"] ?? 0);

  if ($name === "" || $message === "" || $rating < 1 || $rating > 5) {
    echo json_encode(["ok" => false, "error" => "invalid"]);
    exit;
  }

  $all = read_json($REVIEWS);
  if (!isset($all["reviews"]) || !is_array($all["reviews"])) $all["reviews"] = [];

  $all["reviews"][] = [
    "id"       => uniqid("r_", true),
    "name"     => $name,
    "country"  => $country,
    "message"  => $message,
    "rating"   => $rating,
    "approved" => false,
    "created"  => date("c")
  ];

  write_json($REVIEWS, $all);
  echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);
  exit;
}

// === Login form ===
if ($action === "login" && $_SERVER["REQUEST_METHOD"] === "POST") {
  $user = $_POST["username"] ?? "";
  $pass = $_POST["password"] ?? "";
  if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
    $_SESSION["logged_in"] = true;
    header("Location: admin.php");
    exit;
  } else {
    $error = "Hatalı kullanıcı adı veya parola.";
  }
}

// === Logout ===
if ($action === "logout") {
  session_destroy();
  header("Location: admin.php");
  exit;
}

// === Admin API: logo yükleme ===
if ($action === "upload_logo" && is_admin() && $_SERVER["REQUEST_METHOD"] === "POST") {
  if (!isset($_FILES["logo"]) || $_FILES["logo"]["error"] !== UPLOAD_ERR_OK) {
    flash("Logo yüklenemedi.");
    header("Location: admin.php");
    exit;
  }
  $tmp  = $_FILES["logo"]["tmp_name"];
  $name = $_FILES["logo"]["name"];
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ["png","jpg","jpeg","webp","svg"])) {
    flash("Desteklenmeyen dosya türü.");
    header("Location: admin.php");
    exit;
  }
  $destName = "logo." . $ext;
  $destPath = $UPLOAD_DIR . "/" . $destName;
  if (!move_uploaded_file($tmp, $destPath)) {
    flash("Sunucu dosya taşıma hatası.");
    header("Location: admin.php");
    exit;
  }
  $settings = read_json($SETTINGS);
  $settings["logo_url"] = "uploads/" . $destName;
  write_json($SETTINGS, $settings);
  flash("Logo güncellendi.");
  header("Location: admin.php");
  exit;
}

// === Admin API: yorum onay/sil ===
if ($action === "moderate_review" && is_admin() && $_SERVER["REQUEST_METHOD"] === "POST") {
  header("Content-Type: application/json; charset=utf-8");
  $id = $_POST["id"] ?? "";
  $approved = isset($_POST["approved"]) ? intval($_POST["approved"]) : 0;

  $all = read_json($REVIEWS);
  $changed = false;
  foreach ($all["reviews"] as &$r) {
    if ($r["id"] === $id) {
      $r["approved"] = !!$approved;
      $changed = true;
      break;
    }
  }
  if ($changed) {
    write_json($REVIEWS, $all);
    echo json_encode(["ok" => true]);
  } else {
    echo json_encode(["ok" => false, "error" => "not_found"]);
  }
  exit;
}

if ($action === "delete_review" && is_admin() && $_SERVER["REQUEST_METHOD"] === "POST") {
  header("Content-Type: application/json; charset=utf-8");
  $id = $_POST["id"] ?? "";
  $all = read_json($REVIEWS);
  $before = count($all["reviews"]);
  $all["reviews"] = array_values(array_filter($all["reviews"], fn($r) => $r["id"] !== $id));
  $after = count($all["reviews"]);
  if ($after < $before) {
    write_json($REVIEWS, $all);
    echo json_encode(["ok" => true]);
  } else {
    echo json_encode(["ok" => false, "error" => "not_found"]);
  }
  exit;
}

// === Görünüm ===
echo html_header();
if (!is_admin()) {
  // Giriş formu
  $msg = fetch_flash();
  if (!empty($msg)) echo '<div class="notice">'.$msg.'</div>';
  if (isset($error)) echo '<div class="error">'.$error.'</div>';
  echo login_form();
  echo html_footer();
  exit;
}

// Admin panel
$msg = fetch_flash();
if (!empty($msg)) echo '<div class="notice">'.$msg.'</div>';
echo admin_panel();
echo html_footer();
exit;

// ===== Yardımcılar =====
function is_admin() {
  return isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true;
}
function ensure_dirs() {
  global $DATA_DIR, $UPLOAD_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
  if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);
}
function ensure_files() {
  global $REVIEWS, $SETTINGS;
  if (!file_exists($REVIEWS)) {
    write_json($REVIEWS, ["reviews" => []]);
  }
  if (!file_exists($SETTINGS)) {
    write_json($SETTINGS, ["logo_url" => ""]);
  }
}
function read_json($path) {
  if (!file_exists($path)) return [];
  $s = file_get_contents($path);
  $j = json_decode($s, true);
  return is_array($j) ? $j : [];
}
function write_json($path, $data) {
  file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}
function flash($msg) {
  $_SESSION["_flash"] = $msg;
}
function fetch_flash() {
  if (!isset($_SESSION["_flash"])) return "";
  $m = $_SESSION["_flash"];
  unset($_SESSION["_flash"]);
  return $m;
}

function html_header() {
  return '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wellness Admin</title>
  <style>
    body{font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin:0; background:#f5f7fb; color:#1e293b;}
    .container{max-width:980px;margin:24px auto;padding:0 16px;}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.04);padding:16px;margin-bottom:16px;}
    h1,h2{margin:0 0 12px}
    form{display:grid;gap:10px}
    input[type=text], input[type=password], input[type=file], select, textarea {padding:10px;border:1px solid #cbd5e1;border-radius:10px}
    button{padding:10px 14px;border:1px solid #3b82f6;background:#3b82f6;color:#fff;border-radius:999px;font-weight:700;cursor:pointer}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .notice{max-width:980px;margin:12px auto;padding:12px 16px;background:#ecfeff;border:1px solid #bae6fd;color:#075985;border-radius:12px}
    .error{max-width:980px;margin:12px auto;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}
    .pill{display:inline-block;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:4px 8px;border-radius:999px;font-size:12px}
    .actions{display:flex;gap:8px}
  </style></head><body><div class="container">';
}

function html_footer() {
  return '</div></body></html>';
}

function login_form() {
  $html = '<div class="card"><h1>Wellness Admin</h1>
    <form method="post" action="admin.php?action=login">
      <label>Kullanıcı Adı<input type="text" name="username" required></label>
      <label>Parola<input type="password" name="password" required></label>
      <div><button type="submit">Giriş Yap</button></div>
      <p style="font-size:12px;color:#64748b;margin-top:6px">Varsayılan: <code>admin / admin123</code>. Lütfen değiştirin.</p>
    </form>
  </div>';
  return $html;
}

function admin_panel() {
  global $SETTINGS, $REVIEWS;
  $settings = read_json($SETTINGS);
  $all = read_json($REVIEWS);
  $reviews = $all["reviews"] ?? [];

  // Basit sıralama: en yeni ilk
  usort($reviews, function($a,$b){ return strcmp($b["created"] ?? "", $a["created"] ?? ""); });

  ob_start(); ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h1>Yönetim Paneli</h1>
      <div><a href="admin.php?action=logout">Çıkış</a></div>
    </div>
  </div>

  <div class="row">
    <div class="card">
      <h2>Logo Yükle</h2>
      <form method="post" action="admin.php?action=upload_logo" enctype="multipart/form-data">
        <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" required>
        <div><button type="submit">Yükle</button></div>
      </form>
      <?php if (!empty($settings["logo_url"])): ?>
        <p>Mevcut logo:</p>
        <img src="<?php echo htmlspecialchars($settings["logo_url"]); ?>" alt="Logo" style="height:64px;border-radius:8px">
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Özet</h2>
      <p><span class="pill"><?php echo count(array_filter($reviews, fn($r)=>!empty($r["approved"]))); ?> onaylı</span>
         <span class="pill"><?php echo count(array_filter($reviews, fn($r)=>empty($r["approved"]))); ?> bekliyor</span></p>
      <p>Public JSON uçları:</p>
      <ul>
        <li><a href="admin.php?action=settings" target="_blank">settings</a></li>
        <li><a href="admin.php?action=list_reviews&public=1" target="_blank">list_reviews (public=1)</a></li>
      </ul>
    </div>
  </div>

  <div class="card">
    <h2>Yorumlar</h2>
    <table>
      <thead><tr><th>Durum</th><th>Ad</th><th>Ülke</th><th>Puan</th><th>Mesaj</th><th>Tarih</th><th>İşlem</th></tr></thead>
      <tbody>
        <?php foreach ($reviews as $r): ?>
          <tr data-id="<?php echo htmlspecialchars($r["id"]); ?>">
            <td><?php echo !empty($r["approved"]) ? '<span class="pill">Onaylı</span>' : 'Bekliyor'; ?></td>
            <td><?php echo htmlspecialchars($r["name"]); ?></td>
            <td><?php echo htmlspecialchars($r["country"] ?? ""); ?></td>
            <td><?php echo str_repeat("★", intval($r["rating"] ?? 0)); ?></td>
            <td><?php echo nl2br(htmlspecialchars($r["message"])); ?></td>
            <td><?php echo htmlspecialchars($r["created"] ?? ""); ?></td>
            <td class="actions">
              <?php if (empty($r["approved"])): ?>
                <button onclick="approveReview('<?php echo $r['id']; ?>', 1)">Onayla</button>
              <?php else: ?>
                <button onclick="approveReview('<?php echo $r['id']; ?>', 0)">Gizle</button>
              <?php endif; ?>
              <button onclick="deleteReview('<?php echo $r['id']; ?>')">Sil</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script>
  async function postForm(url, data) {
    const res = await fetch(url, { method: "POST", body: data });
    return await res.json();
  }
  async function approveReview(id, approved) {
    const fd = new FormData();
    fd.append("id", id);
    fd.append("approved", approved ? "1" : "0");
    const j = await postForm("admin.php?action=moderate_review", fd);
    if (j && j.ok) location.reload(); else alert("İşlem başarısız");
  }
  async function deleteReview(id) {
    if (!confirm("Silmek istediğinize emin misiniz?")) return;
    const fd = new FormData();
    fd.append("id", id);
    const j = await postForm("admin.php?action=delete_review", fd);
    if (j && j.ok) location.reload(); else alert("Silinemedi");
  }
  </script>
  <?php
  return ob_get_clean();
}
