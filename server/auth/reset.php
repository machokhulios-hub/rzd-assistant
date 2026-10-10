<?php
// Новый пароль по ссылке из письма (или от администратора).
declare(strict_types=1);
require __DIR__ . '/../_srv/lib.php';

$t = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$id = token_user($t, 'reset');
if (!$id) page('Смена пароля', msg('Ссылка устарела или уже использована. Запросите новую.', 'warn') . '<div class="links"><a href="forgot.php">Восстановить пароль</a><a href="login.php">Вход</a></div>');

$err = '';
if (posted()) {
    $pass = (string)($_POST['pass'] ?? '');
    if (mb_strlen($pass) < 8 || mb_strlen($pass) > 200) $err = 'Пароль - от 8 до 200 символов.';
    elseif ($pass !== (string)($_POST['pass2'] ?? '')) $err = 'Пароли не совпадают.';
    elseif (token_user($t, 'reset', true)) {
        // ссылка пришла на почту - значит, почта подтверждена; старые входы на других устройствах закрываются
        q('UPDATE users SET pass = ?, verified = 1 WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
        q('DELETE FROM sessions WHERE user_id = ?', [$id]);
        $u = q('SELECT status FROM users WHERE id = ?', [$id])->fetch();
        if ($u && $u['status'] === 'active') { login_user($id); redirect(base()); }
        redirect('login.php?m=reset');
    }
}
page('Новый пароль', ($err ? msg(h($err), 'err') : '') .
    form('<input type="hidden" name="t" value="' . h($t) . '">' .
        field('Новый пароль, не короче 8 символов', 'pass', 'password', '', 'required minlength="8" maxlength="200" autocomplete="new-password" autofocus') .
        field('Пароль ещё раз', 'pass2', 'password', '', 'required minlength="8" maxlength="200" autocomplete="new-password"') .
        '<button>Сохранить пароль</button>'));
