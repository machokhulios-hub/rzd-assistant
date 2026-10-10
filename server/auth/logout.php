<?php
// Выход с сайта на этом устройстве.
declare(strict_types=1);
require __DIR__ . '/../_srv/lib.php';

logout_user();
redirect('login.php?m=out');
