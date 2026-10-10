<?php
// Регистрация: почта, имя и пароль. Дальше - подтверждение почты и (в режиме approve) одобрение администратором.
declare(strict_types=1);
require __DIR__ . '/../_srv/lib.php';

$mode = (string)cfg('registration');
if (user()) redirect(base());
if ($mode === 'closed') page('Регистрация', msg('Регистрация закрыта - учётную запись выдаёт администратор сайта.', 'warn') . '<div class="links"><a href="login.php">Вход</a></div>');

$email = norm_email((string)($_POST['email'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$err = '';
if (posted()) {
    $pass = (string)($_POST['pass'] ?? '');
    if (too_many($email)) $err = 'Слишком много попыток. Подождите 15 минут.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) $err = 'Проверьте адрес почты.';
    elseif (mb_strlen($name) > 100) $err = 'Имя слишком длинное.';
    elseif (mb_strlen($pass) < 8 || mb_strlen($pass) > 200) $err = 'Пароль - от 8 до 200 символов.';
    elseif ($pass !== (string)($_POST['pass2'] ?? '')) $err = 'Пароли не совпадают.';
    elseif (find_user($email)) $err = 'Эта почта уже зарегистрирована. Войдите или восстановите пароль.';
    else {
        attempt($email);   // регистрации тоже считаются: не больше 30 с одного IP за 15 минут
        $verify = (bool)cfg('verify_email');
        q('INSERT INTO users (email, name, pass, role, status, verified, created) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'user', $mode === 'open' ? 'active' : 'pending', $verify ? 0 : 1, time()]);
        $id = (int)db()->lastInsertId();
        $done = [];
        if ($verify) {
            $t = make_token($id, 'verify', 7 * 86400);
            send_mail($email, 'Подтверждение почты', "Вы зарегистрировались на сайте РЖД Ассистент.\nЧтобы подтвердить почту, откройте ссылку:\n"
                . url('auth/verify.php?t=' . $t) . "\n\nСсылка действует 7 дней. Если вы не регистрировались - просто удалите письмо.");
            $done[] = 'На почту <b>' . h($email) . '</b> отправлено письмо - откройте ссылку из него, чтобы подтвердить адрес. Проверьте и папку «Спам».';
        }
        if ($mode === 'approve') {
            admins_mail('Новая заявка на доступ', "Зарегистрировался пользователь: $email" . ($name !== '' ? " ($name)" : '')
                . "\nОдобрить или отклонить: " . url('auth/admin.php'));
            $done[] = 'Заявка отправлена администратору. Когда доступ откроют, придёт письмо.';
        }
        if (!$done) { login_user($id); redirect(base()); }
        page('Заявка принята', msg(implode('<br><br>', $done), 'ok') . '<div class="links"><a href="login.php">Вход</a></div>');
    }
}
page('Регистрация', ($err ? msg(h($err), 'err') : '') .
    ($mode === 'approve' ? '<p class="sub">После регистрации заявку одобряет администратор сайта.</p>' : '') .
    form(field('Почта', 'email', 'email', $email, 'required autocomplete="email" maxlength="190"') .
        field('Имя и должность', 'name', 'text', $name, 'autocomplete="name" maxlength="100" placeholder="например, Иванов И. И., машинист"') .
        field('Пароль, не короче 8 символов', 'pass', 'password', '', 'required minlength="8" maxlength="200" autocomplete="new-password"') .
        field('Пароль ещё раз', 'pass2', 'password', '', 'required minlength="8" maxlength="200" autocomplete="new-password"') .
        '<button>Зарегистрироваться</button>') .
    '<div class="links"><a href="login.php">Уже есть учётная запись? Войти</a></div>');
