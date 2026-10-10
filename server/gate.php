<?php
// Вход на сайт: .htaccess направляет сюда каждый запрос к файлам сайта.
// Вошёл - отдаём файл, нет - страница входа (для страниц) или 401 (для данных и документов).
declare(strict_types=1);
require __DIR__ . '/_srv/lib.php';

$raw = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$b = base();
$rel = rawurldecode(str_starts_with($raw, $b) ? substr($raw, strlen($b)) : ltrim($raw, '/'));

if (!user()) {
    header('Cache-Control: no-store');
    $page = $rel === '' || $rel === 'index.html' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html');
    if ($page) redirect($b . 'auth/login.php' . ($rel === '' || $rel === 'index.html' ? '' : '?next=' . rawurlencode($raw)));
    fail('Нужно войти на сайт.', 401);
}

// служебное и чужое не отдаём
if ($rel === '' || str_ends_with($rel, '/')) $rel .= 'index.html';
$root = (string)realpath(__DIR__);
$file = realpath($root . '/' . $rel);
$deny = '#(^|/)(\.|_srv(/|$)|auth(/|$))|\.(php|phtml|phar|sql|ini|log|sh|py)$#i';
if (preg_match($deny, $rel) || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) fail('Не найдено.', 404);
if (is_dir($file)) redirect($raw . '/');
if (!is_file($file)) fail('Не найдено.', 404);

$types = ['html' => 'text/html; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
    'json' => 'application/json; charset=utf-8', 'webmanifest' => 'application/manifest+json', 'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/plain; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'woff2' => 'font/woff2',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$size = filesize($file);
$mtime = filemtime($file);
$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
// страница, список документов и service worker - всегда свежие, остальное браузер может держать 10 минут
$fresh = in_array($ext, ['html', 'json', 'js', 'webmanifest'], true);

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Cache-Control: private, ' . ($fresh ? 'no-cache' : 'max-age=600'));
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
if ($ext === 'js' && basename($file) === 'sw.js') header('Service-Worker-Allowed: ' . $b);

if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

// часть файла (PDF открываются кусками)
$from = 0;
$to = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $from = max(0, $size - (int)$m[2]); }
    else { $from = (int)$m[1]; if ($m[2] !== '') $to = min($to, (int)$m[2]); }
    if ($from > $to || $from >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $from-$to/$size");
}
header('Content-Length: ' . ($to - $from + 1));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

while (ob_get_level()) ob_end_clean();
$f = fopen($file, 'rb');
fseek($f, $from);
for ($left = $to - $from + 1; $left > 0 && !feof($f); $left -= strlen($chunk)) {
    $chunk = (string)fread($f, min(65536, $left));
    echo $chunk;
    flush();
}
fclose($f);
