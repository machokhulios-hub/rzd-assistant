<?php
// Ссылка из письма: почта подтверждена.
declare(strict_types=1);
require __DIR__ . '/../_srv/lib.php';

$id = token_user((string)($_GET['t'] ?? ''), 'verify', true);
if (!$id) page('Подтверждение почты', msg('Ссылка устарела или уже использована. Войдите - если почта ещё не подтверждена, письмо можно отправить снова.', 'warn') . '<div class="links"><a href="login.php">Вход</a></div>');
q('UPDATE users SET verified = 1 WHERE id = ?', [$id]);
$u = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
if ($u && $u['status'] === 'active') { login_user($id); redirect(base()); }
page('Почта подтверждена', msg('Почта подтверждена. Заявка ждёт одобрения администратора - когда доступ откроют, придёт письмо.', 'ok') . '<div class="links"><a href="login.php">Вход</a></div>');
