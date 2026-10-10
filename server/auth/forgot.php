<?php
// Забыли пароль: письмо со ссылкой на смену пароля.
declare(strict_types=1);
require __DIR__ . '/../_srv/lib.php';

$email = norm_email((string)($_POST['email'] ?? ''));
if (posted()) {
    if (too_many($email)) page('Восстановление пароля', msg('Слишком много попыток. Подождите 15 минут.', 'err') . '<div class="links"><a href="login.php">Вход</a></div>');
    attempt($email);   // не больше 10 писем на адрес за 15 минут
    $u = $email !== '' ? find_user($email) : null;
    if ($u && $u['status'] !== 'blocked') {
        $t = make_token((int)$u['id'], 'reset', 3600);
        send_mail($u['email'], 'Смена пароля', "Чтобы задать новый пароль, откройте ссылку:\n" . url('auth/reset.php?t=' . $t)
            . "\n\nСсылка действует 1 час. Если вы не просили сменить пароль - просто удалите письмо.");
    }
    // одинаковый ответ, есть такая почта или нет
    page('Восстановление пароля', msg('Если такая почта зарегистрирована, на неё отправлено письмо со ссылкой. Проверьте и папку «Спам».', 'ok') .
        '<div class="links"><a href="login.php">Вход</a></div>');
}
page('Восстановление пароля', '<p class="sub">Пришлю на почту ссылку, по которой можно задать новый пароль.</p>' .
    form(field('Почта', 'email', 'email', $email, 'required autocomplete="email" autofocus') . '<button>Отправить ссылку</button>') .
    '<p class="note">Письмо не пришло - проверьте папку «Спам» или попросите администратора сайта выдать ссылку для смены пароля.</p>' .
    '<div class="links"><a href="login.php">Вход</a></div>');
