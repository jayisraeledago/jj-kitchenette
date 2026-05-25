<?php
$email = trim($_GET['email'] ?? '');
$target = '/jj_kitchenette/forgot-password.php?mode=reset';

if ($email !== '') {
    $target .= '&email=' . urlencode($email);
}

header('Location: ' . $target);
exit;
