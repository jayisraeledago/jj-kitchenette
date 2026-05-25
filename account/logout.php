<?php
require_once __DIR__ . '/../includes/session.php';
startAppSession();

// UNSET ALL SESSION VARIABLES
$_SESSION = [];

// DESTROY SESSION
session_destroy();

// REDIRECT TO LOGIN PAGE
header("Location: ../login.php");
exit;
?>