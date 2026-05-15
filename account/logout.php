<?php
session_start();

// UNSET ALL SESSION VARIABLES
$_SESSION = [];

// DESTROY SESSION
session_destroy();

// REDIRECT TO LOGIN PAGE
header("Location: ../login.php");
exit;
?>