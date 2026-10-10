<?php
// Вход. Пока пользователей нет - здесь же создаётся первый администратор.
declare(strict_types=1);
require __DIR__ . '/../_srv/lib.php';

$b = base();
$next = next_url();
$email = norm_email((string)($_POST['email'] ?? ''));
$first = !q('SELECT COUNT(*) FROM users')->fetchColumn();

if ($first) {   // первый запуск: администратор сайта
    $err = '';
    if (posted()) {
        $pass = (string)($_POST['pass'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Проверьте адрес почты.';
        elseif (mb_strlen($pass) < 8) $err = 'Пароль - не короче 8 символов.';
        elseif ($pass !== (string)($_POST['pass2'] ?? '')) $err = 'Пароли не совпадают.';
        else {
            q("INSERT INTO users (email, name, pass, role, status, verified, created) VALUES (?, ?, ?, 'admin', 'active', 1, ?)",
                [$email, trim((string)($_POST['name'] ?? '')), password_hash($pass, PASSWORD_DEFAULT), time()]);
            login_user((int)db()->lastInsertId());
            redirect($b);
        }
    }
    page('Настройка сайта', ($err ? msg(h($err), 'err') : '') .
        '<p class="sub">Пользователей ещё нет. Создайте учётную запись администратора - он одобряет заявки и управляет пользователями.</p>' .
        form(field('Почта', 'email', 'email', $email, 'required autocomplete="email"') .
            field('Имя', 'name', 'text', (string)($_POST['name'] ?? ''), 'autocomplete="name" maxlength="100"') .
            field('Пароль, не короче 8 символов', 'pass', 'password', '', 'required minlength="8" autocomplete="new-password"') .
            field('Пароль ещё раз', 'pass2', 'password', '', 'required minlength="8" autocomplete="new-password"') .
            '<button>Создать администратора</button>'));
}

if (user()) redirect($next);

$err = '';
$note = match ($_GET['m'] ?? '') {
    'out' => msg('Вы вышли с сайта.', 'ok'),
    'reset' => msg('Пароль изменён - войдите с новым паролем.', 'ok'),
    default => '',
};
if (posted()) {
    $pass = (string)($_POST['pass'] ?? '');
    $u = $email !== '' ? find_user($email) : null;
    if (too_many($email)) $err = 'Слишком много попыток. Подождите 15 минут.';
    elseif (isset($_POST['resend'])) {   // письмо для подтверждения - ещё раз (не чаще 10 раз за 15 минут)
        attempt($email);
        if ($u && !$u['verified']) {
            $t = make_token((int)$u['id'], 'verify', 7 * 86400);
            send_mail($u['email'], 'Подтверждение почты', "Чтобы подтвердить почту, откройте ссылку:\n" . url('auth/verify.php?t=' . $t) . "\n\nСсылка действует 7 дней.");
        }
        $note = msg('Если почта зарегистрирована и ещё не подтверждена, письмо отправлено. Проверьте и папку «Спам».', 'ok');
    } elseif (!$u || !password_verify($pass, $u['pass'])) { attempt($email); $err = 'Неверная почта или пароль.'; }
    elseif (!$u['verified']) $err = 'Почта не подтверждена: откройте ссылку из письма, которое пришло после регистрации.';
    elseif ($u['status'] === 'pending') $err = 'Заявка ждёт одобрения администратора. Когда доступ откроют, придёт письмо.';
    elseif ($u['status'] !== 'active') $err = 'Доступ к сайту закрыт администратором.';
    else {
        if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) q('UPDATE users SET pass = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        login_user((int)$u['id']);
        redirect($next);
    }
}
$resend = $err !== '' && str_starts_with($err, 'Почта не подтверждена');
$reg = cfg('registration') !== 'closed';
page('Вход', $note . ($err ? msg(h($err), 'err') : '') .
    '<p class="sub">Сайт доступен только зарегистрированным пользователям.</p>' .
    form('<input type="hidden" name="next" value="' . h($next) . '">' .
        field('Почта', 'email', 'email', $email, 'required autocomplete="username" autofocus') .
        field('Пароль', 'pass', 'password', '', 'autocomplete="current-password"') .
        '<button>Войти</button>' .
        ($resend ? '<button class="btn2" name="resend" value="1" formnovalidate>Отправить письмо ещё раз</button>' : '')) .
    '<div class="links">' . ($reg ? '<a href="register.php">Регистрация</a>' : '') . '<a href="forgot.php">Забыли пароль?</a></div>' .
    ($reg ? '' : '<p class="note">Регистрация закрыта - учётную запись выдаёт администратор.</p>'));
