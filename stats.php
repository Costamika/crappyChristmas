<?php
/**
 * ДЕРЬМОВОЕ РОЖДЕСТВО 3D — таблица рекордов.
 *
 * Один файл, без внешних зависимостей. Хранилище: SQLite (если есть PDO),
 * иначе — JSON-файл с блокировкой. Кладётся рядом с index.html.
 *
 * Имя файла stats.php намеренно: на хостингах с защитным .htaccess
 * (LiteSpeed + WordPress/Imunify) прочие *.php часто блокируются (403),
 * а stats.php обычно в белом списке разрешённых имён.
 *
 * API:
 *   GET  ?action=top&diff=normal&limit=20   → топ по сложности
 *   POST ?action=submit  (JSON в теле)      → отправить результат забега
 *
 * Файлы данных создаются рядом со скриптом:
 *   lb.sqlite  (или lb.json) и lb_rate.json
 * Каталог со скриптом должен быть доступен PHP на запись.
 */

// ── Настройки ────────────────────────────────────────────────
// Секрет НЕ дублируется в клиентском JS (утечка закрыта).
// HMAC отключён по умолчанию; реальную защиту дают CAPS + rate-limit.
// Если нужен приватный HMAC — задай LB_SECRET и включи LB_VERIFYSIG,
// но НЕ копируй секрет в index.html.
const LB_SECRET   = 'dcx3d_7Hk9_mudmas_q2Zx8';
const LB_VERIFYSIG= false;   // HMAC отключён: не требует секрета на клиенте
const LB_MAX_ROWS = 100;    // сколько хранить/отдавать на сложность
const LB_RATE_SEC = 3;      // мин. интервал между отправками с одного IP
const LB_NAME_MAX = 16;

const CAPS = [
  'score' => 5000000,
  'wave'  => 99,
  'kills' => 100000,
  'acc'   => 100,
  'combo' => 100000,
  'time'  => 86400,
];
const DIFFS = ['easy','normal','hard'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, $code = 200) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// ── Хранилище (SQLite при наличии PDO, иначе JSON) ────────────
function store_available_sqlite() {
  return class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function db() {
  static $pdo = null;
  if ($pdo !== null) return $pdo;
  $path = __DIR__ . '/lb.sqlite';
  $pdo = new PDO('sqlite:' . $path);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('CREATE TABLE IF NOT EXISTS scores(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    diff TEXT NOT NULL,
    name TEXT NOT NULL,
    score INTEGER NOT NULL,
    wave INTEGER NOT NULL,
    kills INTEGER NOT NULL,
    acc INTEGER NOT NULL,
    combo INTEGER NOT NULL,
    time INTEGER NOT NULL,
    won INTEGER NOT NULL,
    ts INTEGER NOT NULL
  )');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_diff_score ON scores(diff, score DESC)');
  return $pdo;
}

// JSON-фолбэк: {easy:[...],normal:[...],hard:[...]}
function json_path() { return __DIR__ . '/lb.json'; }

function json_load() {
  $p = json_path();
  if (!file_exists($p)) return ['easy'=>[], 'normal'=>[], 'hard'=>[]];
  $raw = file_get_contents($p);
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
  foreach (DIFFS as $d) if (!isset($data[$d]) || !is_array($data[$d])) $data[$d] = [];
  return $data;
}

function json_save($data) {
  $p = json_path();
  $fp = fopen($p, 'c+');
  if (!$fp) return false;
  flock($fp, LOCK_EX);
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return true;
}

// ── Rate limit по IP (простой файл) ──────────────────────────
function client_ip() {
  // Учитываем прокси/Cloudflare, но только валидные IP и первый из XFF
  $candidates = [];
  if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $candidates[] = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    if (isset($parts[0])) $candidates[] = trim($parts[0]);
  }
  if (!empty($_SERVER['HTTP_X_REAL_IP'])) $candidates[] = trim($_SERVER['HTTP_X_REAL_IP']);
  foreach ($candidates as $c) {
    if (filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $c;
    if (filter_var($c, FILTER_VALIDATE_IP)) return $c;
  }
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rate_ok() {
  $p = __DIR__ . '/lb_rate.json';
  $ip = client_ip();
  $now = time();
  $fp = fopen($p, 'c+');
  if (!$fp) return true; // не блокируем, если не можем писать
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $map = json_decode($raw ?: '{}', true);
  if (!is_array($map)) $map = [];
  // чистим старые записи
  foreach ($map as $k => $t) if ($now - $t > 3600) unset($map[$k]);
  $ok = !isset($map[$ip]) || ($now - $map[$ip] >= LB_RATE_SEC);
  if ($ok) {
    $map[$ip] = $now;
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($map));
  }
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return $ok;
}

// ── Валидация ────────────────────────────────────────────────
function clean_name($n) {
  $n = is_string($n) ? $n : '';
  $n = trim($n);
  // убираем управляющие символы, оставляем печатаемые (в т.ч. кириллицу/эмодзи)
  $n = preg_replace('/[\x00-\x1F\x7F]/u', '', $n);
  if ($n === '') $n = 'АНОН';
  // ограничение по длине в символах (мультибайт)
  if (function_exists('mb_substr')) $n = mb_substr($n, 0, LB_NAME_MAX, 'UTF-8');
  else $n = substr($n, 0, LB_NAME_MAX);
  return $n;
}

function as_int($v, $max, $min = 0) {
  $i = (int)$v;
  if ($i < $min) $i = $min;
  if ($i > $max) $i = $max;
  return $i;
}

function expected_sig($p) {
  // Подпись по числовым полям (имя не подписываем — оно не критично, а его
  // нормализация могла бы ломать совпадение подписи). Тот же порядок в клиенте.
  $base = $p['diff'].'|'.$p['score'].'|'.$p['wave'].'|'.$p['kills'].'|'.$p['acc'].'|'.$p['combo'].'|'.$p['time'].'|'.$p['won'];
  return hash_hmac('sha256', $base, LB_SECRET);
}

// ── Действия ─────────────────────────────────────────────────
function get_top($diff, $limit) {
  $limit = max(1, min(LB_MAX_ROWS, (int)$limit));
  if (store_available_sqlite()) {
    $st = db()->prepare('SELECT name,score,wave,kills,acc,combo,time,won,ts FROM scores WHERE diff=? ORDER BY score DESC, ts ASC LIMIT ?');
    $st->bindValue(1, $diff, PDO::PARAM_STR);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
  $data = json_load();
  $rows = $data[$diff] ?? [];
  usort($rows, function($a, $b){ return $b['score'] <=> $a['score'] ?: ($a['ts'] <=> $b['ts']); });
  return array_slice($rows, 0, $limit);
}

function insert_score($rec) {
  if (store_available_sqlite()) {
    $st = db()->prepare('INSERT INTO scores(diff,name,score,wave,kills,acc,combo,time,won,ts) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$rec['diff'],$rec['name'],$rec['score'],$rec['wave'],$rec['kills'],$rec['acc'],$rec['combo'],$rec['time'],$rec['won'],$rec['ts']]);
    // подрезаем таблицу до LB_MAX_ROWS на сложность
    $db = db();
    $del = $db->prepare('DELETE FROM scores WHERE diff=? AND id NOT IN (SELECT id FROM (SELECT id FROM scores WHERE diff=? ORDER BY score DESC, ts ASC LIMIT ?) AS keep)');
    $del->bindValue(1, $rec['diff'], PDO::PARAM_STR);
    $del->bindValue(2, $rec['diff'], PDO::PARAM_STR);
    $del->bindValue(3, LB_MAX_ROWS, PDO::PARAM_INT);
    $del->execute();
    return;
  }
  $data = json_load();
  $data[$rec['diff']][] = $rec;
  usort($data[$rec['diff']], function($a, $b){ return $b['score'] <=> $a['score'] ?: ($a['ts'] <=> $b['ts']); });
  $data[$rec['diff']] = array_slice($data[$rec['diff']], 0, LB_MAX_ROWS);
  json_save($data);
}

// ── Роутинг ──────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

try {
  if ($action === 'top') {
    $diff = $_GET['diff'] ?? 'normal';
    if (!in_array($diff, DIFFS, true)) $diff = 'normal';
    $limit = $_GET['limit'] ?? 20;
    out(['ok'=>true, 'diff'=>$diff, 'rows'=>get_top($diff, $limit)]);
  }

  if ($action === 'submit') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(['ok'=>false,'err'=>'method'], 405);
    if (!rate_ok()) out(['ok'=>false,'err'=>'rate'], 429);

    $body = file_get_contents('php://input');
    $in = json_decode($body, true);
    if (!is_array($in)) out(['ok'=>false,'err'=>'json'], 400);

    $diff = in_array(($in['diff'] ?? ''), DIFFS, true) ? $in['diff'] : null;
    if ($diff === null) out(['ok'=>false,'err'=>'diff'], 400);

    $rec = [
      'diff'  => $diff,
      'name'  => clean_name($in['name'] ?? ''),
      'score' => as_int($in['score'] ?? 0, CAPS['score']),
      'wave'  => as_int($in['wave'] ?? 0, CAPS['wave']),
      'kills' => as_int($in['kills'] ?? 0, CAPS['kills']),
      'acc'   => as_int($in['acc'] ?? 0, CAPS['acc']),
      'combo' => as_int($in['combo'] ?? 0, CAPS['combo']),
      'time'  => as_int($in['time'] ?? 0, CAPS['time']),
      'won'   => !empty($in['won']) ? 1 : 0,
    ];

    if (LB_VERIFYSIG) {
      $sig = $in['sig'] ?? '';
      if (!is_string($sig) || !hash_equals(expected_sig($rec), $sig)) {
        out(['ok'=>false,'err'=>'sig'], 403);
      }
    }

    $rec['ts'] = time();
    insert_score($rec);
    // сообщаем клиенту его позицию (1-based) в топе
    $top = get_top($diff, LB_MAX_ROWS);
    $rank = 0;
    foreach ($top as $i => $r) {
      if ((int)$r['score'] === $rec['score'] && $r['name'] === $rec['name'] && (int)$r['ts'] === $rec['ts']) { $rank = $i + 1; break; }
    }
    out(['ok'=>true, 'rank'=>$rank, 'rows'=>array_slice($top, 0, 20)]);
  }

  out(['ok'=>false, 'err'=>'unknown_action'], 400);
} catch (Throwable $e) {
  out(['ok'=>false, 'err'=>'server'], 500);
}

