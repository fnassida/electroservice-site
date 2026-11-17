<?php
require __DIR__ . '/../private/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($u = current_user()) log_activity((int)$u['id'], 'logout');
    $_SESSION = [];
    session_destroy();
}
redirect('/portal/login.php');
