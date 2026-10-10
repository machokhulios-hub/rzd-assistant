<?php
// Кто вошёл - для страницы сайта (раздел «Настройки»: почта, «Выйти», «Пользователи»).
declare(strict_types=1);
require __DIR__ . '/../_srv/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$u = user();
if (!$u) { http_response_code(401); exit('{}'); }
echo json_encode(['email' => $u['email'], 'name' => $u['name'], 'admin' => $u['role'] === 'admin',
    'pending' => $u['role'] === 'admin' ? (int)q("SELECT COUNT(*) FROM users WHERE status = 'pending' AND verified = 1")->fetchColumn() : 0],
    JSON_UNESCAPED_UNICODE);
