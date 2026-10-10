<?php
// Вход на сайт: база, сессии, защита форм, письма и оформление страниц входа.
// Подключается из gate.php и auth/*.php. Папка _srv закрыта для браузера (.htaccess).
declare(strict_types=1);

const SRV = __DIR__;
const COOKIE = 'rzd_s';      // сессия: случайный токен, в базе хранится только его хэш
const CSRF = 'rzd_c';        // токен форм (сверяется с полем формы)

function cfg(string $key)
{
    static $c = null;
    if ($c === null) {
        $f = SRV . '/config.php';
        if (!is_file($f)) fail('Сайт не настроен: скопируйте _srv/config.sample.php в _srv/config.php и заполните его.');
        $c = (require $f) + ['registration' => 'approve', 'verify_email' => true, 'mail_from' => '', 'site_url' => '', 'session_days' => 90];
    }
    return $c[$key] ?? null;
}

function fail(string $text, int $code = 500): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($text);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(cfg('db_dsn'), cfg('db_user'), cfg('db_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $e) {
        fail('Нет связи с базой данных - проверьте db_dsn, db_user и db_pass в _srv/config.php.');
    }
    schema($pdo);
    return $pdo;
}

// таблицы создаются сами при первом обращении; файл-отметка избавляет от лишних запросов потом
function schema(PDO $p): void
{
    $flag = SRV . '/.schema1';
    if (is_file($flag)) return;
    $my = $p->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $p->exec("CREATE TABLE IF NOT EXISTS users (id $id, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL DEFAULT '',
        pass VARCHAR(255) NOT NULL, role VARCHAR(10) NOT NULL DEFAULT 'user', status VARCHAR(10) NOT NULL DEFAULT 'pending',
        verified INT NOT NULL DEFAULT 0, created INT NOT NULL, last_login INT NOT NULL DEFAULT 0)$tail");
    $p->exec("CREATE TABLE IF NOT EXISTS sessions (id $id, token CHAR(64) NOT NULL UNIQUE, user_id INT NOT NULL, expires INT NOT NULL, created INT NOT NULL)$tail");
    $p->exec("CREATE TABLE IF NOT EXISTS tokens (id $id, token CHAR(64) NOT NULL UNIQUE, user_id INT NOT NULL, kind VARCHAR(10) NOT NULL, expires INT NOT NULL)$tail");
    $p->exec("CREATE TABLE IF NOT EXISTS attempts (id $id, ip VARCHAR(45) NOT NULL, email VARCHAR(190) NOT NULL, at INT NOT NULL)$tail");
    @file_put_contents($flag, "1\n");
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

// ---- адреса: сайт может лежать и в корне домена, и в подпапке
function base(): string   // путь сайта от корня домена: '/' или '/rzd/'
{
    static $b = null;
    if ($b !== null) return $b;
    $root = str_replace('\\', '/', (string)realpath(SRV . '/..'));
    $script = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $rel = substr($script, strlen($root));                       // '/gate.php' или '/auth/login.php'
    $name = $_SERVER['SCRIPT_NAME'] ?? '/';
    $b = str_ends_with($name, $rel) ? substr($name, 0, strlen($name) - strlen($rel)) . '/' : '/';
    return $b;
}

function https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function url(string $path = ''): string   // полный адрес для писем
{
    $site = (string)cfg('site_url');
    if ($site !== '') return rtrim($site, '/') . '/' . $path;
    return (https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base() . $path;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

// куда вернуть после входа: только страницы этого сайта
function next_url(): string
{
    $n = (string)($_POST['next'] ?? $_GET['next'] ?? '');
    $b = base();
    if ($n === '' || !str_starts_with($n, $b) || str_starts_with($n, '//') || preg_match('#[\\\\\s]#', $n)
        || str_starts_with(substr($n, strlen($b)), 'auth/')) return $b;
    return $n;
}

function set_cookie(string $name, string $value, int $expires, bool $httponly = true): void
{
    setcookie($name, $value, ['expires' => $expires, 'path' => base(), 'secure' => https(), 'httponly' => $httponly, 'samesite' => 'Lax']);
}

// ---- сессии
function user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    $t = (string)($_COOKIE[COOKIE] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $t)) return null;
    $r = q('SELECT u.*, s.id AS sid, s.expires FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?', [hash('sha256', $t)])->fetch();
    if (!$r || $r['expires'] < time() || $r['status'] !== 'active' || !$r['verified']) return null;
    $exp = time() + (int)cfg('session_days') * 86400;
    if ($exp - $r['expires'] > 86400) {   // вход продлевается раз в сутки, пока сайтом пользуются
        q('UPDATE sessions SET expires = ? WHERE id = ?', [$exp, $r['sid']]);
        set_cookie(COOKIE, $t, $exp);
        set_cookie('rzd_acc', '1', $exp, false);
    }
    return $u = $r;
}

function login_user(int $id): void
{
    $t = bin2hex(random_bytes(32));
    $exp = time() + (int)cfg('session_days') * 86400;
    q('DELETE FROM sessions WHERE expires < ?', [time()]);
    q('INSERT INTO sessions (token, user_id, expires, created) VALUES (?, ?, ?, ?)', [hash('sha256', $t), $id, $exp, time()]);
    q('UPDATE users SET last_login = ? WHERE id = ?', [time(), $id]);
    set_cookie(COOKIE, $t, $exp);
    set_cookie('rzd_acc', '1', $exp, false);   // странице сайта: вход есть, можно спросить auth/me.php
}

function logout_user(): void
{
    $t = (string)($_COOKIE[COOKIE] ?? '');
    if ($t !== '') q('DELETE FROM sessions WHERE token = ?', [hash('sha256', $t)]);
    set_cookie(COOKIE, '', time() - 3600);
    set_cookie('rzd_acc', '', time() - 3600, false);
}

// ---- защита форм от подделки запроса с чужого сайта
function csrf(): string
{
    static $t = null;
    if ($t) return $t;
    $t = (string)($_COOKIE[CSRF] ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/', $t)) {
        $t = bin2hex(random_bytes(16));
        set_cookie(CSRF, $t, 0);
    }
    return $t;
}

function posted(): bool   // форма отправлена с этого сайта
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    $c = (string)($_COOKIE[CSRF] ?? '');
    return $c !== '' && hash_equals($c, (string)($_POST['csrf'] ?? ''));
}

// ---- подбор пароля: не больше 10 ошибок на адрес и 30 с одного IP за 15 минут
function too_many(string $email): bool
{
    $since = time() - 900;
    q('DELETE FROM attempts WHERE at < ?', [time() - 86400]);
    $ip = (int)q('SELECT COUNT(*) FROM attempts WHERE ip = ? AND at > ?', [ip(), $since])->fetchColumn();
    $em = (int)q('SELECT COUNT(*) FROM attempts WHERE email = ? AND at > ?', [$email, $since])->fetchColumn();
    return $ip >= 30 || $em >= 10;
}

function attempt(string $email): void
{
    q('INSERT INTO attempts (ip, email, at) VALUES (?, ?, ?)', [ip(), $email, time()]);
}

function ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// ---- одноразовые ссылки: подтверждение почты и смена пароля
function make_token(int $uid, string $kind, int $ttl): string
{
    $t = bin2hex(random_bytes(32));
    q('DELETE FROM tokens WHERE (user_id = ? AND kind = ?) OR expires < ?', [$uid, $kind, time()]);
    q('INSERT INTO tokens (token, user_id, kind, expires) VALUES (?, ?, ?, ?)', [hash('sha256', $t), $uid, $kind, time() + $ttl]);
    return $t;
}

function token_user(string $t, string $kind, bool $use = false): ?int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $t)) return null;
    $h = hash('sha256', $t);
    $id = q('SELECT user_id FROM tokens WHERE token = ? AND kind = ? AND expires > ?', [$h, $kind, time()])->fetchColumn();
    if ($id && $use) q('DELETE FROM tokens WHERE token = ?', [$h]);
    return $id ? (int)$id : null;
}

function find_user(string $email): ?array
{
    return q('SELECT * FROM users WHERE email = ?', [$email])->fetch() ?: null;
}

function norm_email(string $e): string
{
    return mb_strtolower(trim($e));
}

function send_mail(string $to, string $subject, string $text): bool
{
    $from = (string)cfg('mail_from');
    $head = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n"
        . ($from !== '' ? 'From: =?UTF-8?B?' . base64_encode('РЖД Ассистент') . "?= <$from>\r\n" : '');
    $text .= "\n\n-- \nРЖД Ассистент, " . url();
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($text)), rtrim($head), $from !== '' ? "-f$from" : '');
}

function admins_mail(string $subject, string $text): void
{
    foreach (q("SELECT email FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll() as $a) send_mail($a['email'], $subject, $text);
}

// ---- страницы входа: оформление как у сайта, тёмная и светлая тема
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function form(string $inner, string $action = ''): string
{
    return '<form method="post"' . ($action !== '' ? ' action="' . h($action) . '"' : '') . '><input type="hidden" name="csrf" value="' . h(csrf()) . '">' . $inner . '</form>';
}

function field(string $label, string $name, string $type = 'text', string $value = '', string $extra = ''): string
{
    return '<label>' . h($label) . '<input name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '" ' . $extra . '></label>';
}

function msg(string $text, string $kind = 'info'): string
{
    return '<p class="msg ' . $kind . '">' . $text . '</p>';
}

function page(string $title, string $body): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    $b = h(base());
    echo <<<HTML
<!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>{$title} - РЖД Ассистент</title>
<link rel="icon" href="{$b}icon-192.png"><link rel="apple-touch-icon" href="{$b}icon-180.png"><link rel="manifest" href="{$b}manifest.webmanifest">
<meta name="theme-color" content="#0d0f13">
<style>
:root{--bg:#0d0f13;--card:#181b21;--line:#2a2f37;--text:#f3f5f8;--muted:#8b949e;--red:#ff5252;--brand:#e21a1a;--green:#34d399;--amber:#ffc13b;--blue:#4da3ff}
@media (prefers-color-scheme:light){:root{--bg:#f3f4f6;--card:#fff;--line:#e3e6ea;--text:#111418;--muted:#5f6772;--green:#0f9b6c;--amber:#b7791f;--blue:#1f6fd1}}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--text);font-family:Manrope,-apple-system,"SF Pro Text",Roboto,sans-serif;font-size:15px;line-height:1.45}
body{min-height:100vh;display:flex;flex-direction:column}
.stripe{height:4px;background:var(--brand)}
main{width:100%;max-width:420px;margin:0 auto;padding:28px 16px 40px}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.logo img{width:42px;height:42px;border-radius:12px}
.logo b{display:block;font-size:17px;font-weight:800}.logo small{color:var(--muted);font-size:12px}
h1{font-size:24px;line-height:1.2;margin:0 0 6px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);margin:0 0 16px}
form,.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:16px;display:grid;gap:12px}
label{display:grid;gap:6px;font-size:12.5px;font-weight:700;color:var(--muted)}
input{width:100%;min-width:0;background:var(--bg);border:1px solid var(--line);border-radius:14px;padding:12px 14px;outline:0;color:var(--text);font:inherit;font-size:16px;font-weight:600;min-height:46px}
input:focus{border-color:rgba(255,82,82,.6)}
button,.btn{height:48px;border:0;border-radius:14px;background:var(--brand);color:#fff;font:inherit;font-weight:800;cursor:pointer;display:grid;place-items:center;text-decoration:none}
.btn2{background:transparent;color:var(--text);border:1px solid var(--line)}
.links{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px 16px;margin-top:14px;font-size:14px}
a{color:var(--blue);font-weight:700;text-decoration:none}
.msg{margin:0 0 14px;padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:var(--card);font-size:14px;font-weight:600}
.msg.err{color:var(--red);border-color:rgba(255,82,82,.4)}.msg.ok{color:var(--green)}.msg.warn{color:var(--amber)}
.note{color:var(--muted);font-size:12.5px;margin:12px 2px 0}
.card.list{padding:0;gap:0}
.u{padding:14px 16px;border-top:1px solid var(--line);display:grid;gap:6px;min-width:0}
.u:first-child{border-top:0}
.u b{overflow-wrap:anywhere}
.u small{color:var(--muted);font-size:12.5px}
.acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}
.acts form{all:unset;display:inline}
.acts button{height:34px;padding:0 12px;border-radius:10px;font-size:13px;font-weight:700}
.acts .no{background:transparent;color:var(--muted);border:1px solid var(--line)}
.tag{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;border:1px solid var(--line);color:var(--muted)}
.tag.pending{color:var(--amber)}.tag.blocked{color:var(--red)}.tag.active{color:var(--green)}
</style></head><body><div class="stripe"></div>
<main><div class="logo"><img src="{$b}icon-192.png" alt=""><span><b>РЖД Ассистент</b><small>неофициальный справочник железнодорожника</small></span></div>
<h1>{$title}</h1>
{$body}
</main></body></html>
HTML;
    exit;
}
