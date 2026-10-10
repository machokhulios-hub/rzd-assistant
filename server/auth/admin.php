<?php
// Пользователи сайта: заявки, одобрение, блокировка, ссылка для смены пароля, новый пользователь.
declare(strict_types=1);
require __DIR__ . '/../_srv/lib.php';

$me = user();
if (!$me) redirect('login.php?next=' . rawurlencode(base() . 'auth/admin.php'));
if ($me['role'] !== 'admin') page('Пользователи', msg('Эта страница - только для администратора сайта.', 'warn') . '<div class="links"><a href="' . h(base()) . '">На сайт</a></div>');

$note = '';
if (posted()) {
    $id = (int)($_POST['id'] ?? 0);
    $u = $id ? q('SELECT * FROM users WHERE id = ?', [$id])->fetch() : null;
    $act = (string)($_POST['act'] ?? '');
    $link = fn(int $uid) => url('auth/reset.php?t=' . make_token($uid, 'reset', 7 * 86400));
    if ($act === 'add') {
        $email = norm_email((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $note = msg('Проверьте адрес почты.', 'err');
        elseif (find_user($email)) $note = msg('Эта почта уже зарегистрирована.', 'err');
        else {
            // пароль задаст сам пользователь по ссылке
            q("INSERT INTO users (email, name, pass, role, status, verified, created) VALUES (?, ?, ?, 'user', 'active', 1, ?)",
                [$email, mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100), password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), time()]);
            $l = $link((int)db()->lastInsertId());
            $sent = send_mail($email, 'Доступ к сайту РЖД Ассистент', "Для вас создана учётная запись. Чтобы задать пароль, откройте ссылку:\n$l\n\nСсылка действует 7 дней.");
            $note = msg('Пользователь добавлен. ' . ($sent ? 'Письмо со ссылкой отправлено. ' : '') . 'Ссылка, чтобы задать пароль (действует 7 дней) - можно переслать самому:<br><a href="' . h($l) . '" style="word-break:break-all">' . h($l) . '</a>', 'ok');
        }
    } elseif (!$u) $note = msg('Пользователь не найден.', 'err');
    elseif ((int)$u['id'] === (int)$me['id'] && $act !== 'link') $note = msg('Себя заблокировать или удалить нельзя.', 'err');
    elseif ($act === 'approve') {
        // одобрение администратором заменяет подтверждение почты - на случай, если письма не доходят
        q("UPDATE users SET status = 'active', verified = 1 WHERE id = ?", [$id]);
        send_mail($u['email'], 'Доступ к сайту открыт', "Администратор одобрил вашу заявку. Войти: " . url('auth/login.php'));
        $note = msg('Доступ открыт: ' . h($u['email']), 'ok');
    } elseif ($act === 'block') {
        q("UPDATE users SET status = 'blocked' WHERE id = ?", [$id]);
        q('DELETE FROM sessions WHERE user_id = ?', [$id]);
        $note = msg('Заблокирован: ' . h($u['email']), 'ok');
    } elseif ($act === 'delete') {
        q('DELETE FROM sessions WHERE user_id = ?', [$id]);
        q('DELETE FROM tokens WHERE user_id = ?', [$id]);
        q('DELETE FROM users WHERE id = ?', [$id]);
        $note = msg('Удалён: ' . h($u['email']), 'ok');
    } elseif ($act === 'admin' || $act === 'user') {
        q('UPDATE users SET role = ? WHERE id = ?', [$act, $id]);
        $note = msg(h($u['email']) . ($act === 'admin' ? ' - теперь администратор' : ' - больше не администратор'), 'ok');
    } elseif ($act === 'link') {
        $l = $link($id);
        $note = msg('Ссылка для смены пароля ' . h($u['email']) . ' (действует 7 дней, сработает один раз) - перешлите её пользователю:<br><a href="' . h($l) . '" style="word-break:break-all">' . h($l) . '</a>', 'ok');
    }
}

$users = q("SELECT * FROM users ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, created DESC")->fetchAll();
$btn = fn(array $u, string $act, string $text, string $cls = '', string $ask = '') => '<form method="post"><input type="hidden" name="csrf" value="' . h(csrf()) . '">'
    . '<input type="hidden" name="id" value="' . (int)$u['id'] . '"><input type="hidden" name="act" value="' . $act . '">'
    . '<button class="' . $cls . '"' . ($ask !== '' ? ' onclick="return confirm(\'' . h($ask) . '\')"' : '') . '>' . $text . '</button></form>';
$status = ['pending' => 'заявка', 'active' => 'доступ открыт', 'blocked' => 'заблокирован'];
$rows = '';
foreach ($users as $u) {
    $self = (int)$u['id'] === (int)$me['id'];
    $acts = ($u['status'] !== 'active' ? $btn($u, 'approve', $u['status'] === 'pending' ? 'Одобрить' : 'Разблокировать') : '')
        . ($u['status'] !== 'blocked' && !$self ? $btn($u, 'block', 'Заблокировать', 'no') : '')
        . $btn($u, 'link', 'Ссылка для пароля', 'no')
        . (!$self && $u['status'] === 'active' ? $btn($u, $u['role'] === 'admin' ? 'user' : 'admin', $u['role'] === 'admin' ? 'Снять админа' : 'Сделать админом', 'no') : '')
        . (!$self ? $btn($u, 'delete', 'Удалить', 'no', 'Удалить ' . $u['email'] . '?') : '');
    $rows .= '<div class="u"><b>' . h($u['email']) . '</b>' . ($u['name'] !== '' ? '<small>' . h($u['name']) . '</small>' : '')
        . '<div><span class="tag ' . h($u['status']) . '">' . ($status[$u['status']] ?? h($u['status'])) . '</span>'
        . ($u['role'] === 'admin' ? ' <span class="tag">админ</span>' : '') . (!$u['verified'] ? ' <span class="tag pending">почта не подтверждена</span>' : '') . '</div>'
        . '<small>с ' . date('d.m.Y', (int)$u['created']) . ($u['last_login'] ? ', последний вход ' . date('d.m.Y H:i', (int)$u['last_login']) : '') . '</small>'
        . '<div class="acts">' . $acts . '</div></div>';
}
$mode = ['approve' => 'по заявке: пользователь регистрируется, администратор одобряет', 'open' => 'свободная: вход после подтверждения почты', 'closed' => 'закрыта: пользователей добавляет администратор'];
page('Пользователи', $note .
    '<div class="card list">' . $rows . '</div>' .
    '<h1 style="font-size:18px;margin:26px 0 10px">Добавить пользователя</h1>' .
    form('<input type="hidden" name="act" value="add">' . field('Почта', 'email', 'email', '', 'required') .
        field('Имя и должность', 'name', 'text', '', 'maxlength="100"') . '<button>Добавить</button>') .
    '<p class="note">Новому пользователю придёт письмо со ссылкой, чтобы задать пароль; ссылка появится и здесь - её можно переслать самому. ' .
    'Регистрация: ' . h($mode[cfg('registration')] ?? (string)cfg('registration')) . ' (меняется в _srv/config.php).</p>' .
    '<div class="links"><a href="' . h(base()) . '">На сайт</a><a href="logout.php">Выйти</a></div>');
