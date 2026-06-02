<?php
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: '';
$dbName = getenv('DB_DATABASE') ?: 'jj_kitchenette';
$dbPort = (int) (getenv('DB_PORT') ?: 3306);
$dbSslCa = getenv('DB_SSL_CA') ?: '';
$dbSslCaContent = getenv('DB_SSL_CA_CONTENT') ?: '';
$dbPersistent = strtolower((string) (getenv('DB_PERSISTENT') ?: 'true')) !== 'false';

if ($dbSslCa === '' && $dbSslCaContent !== '') {
    $dbSslCa = sys_get_temp_dir() . '/db-ca.pem';
    if (!is_file($dbSslCa) || file_get_contents($dbSslCa) !== $dbSslCaContent) {
        file_put_contents($dbSslCa, $dbSslCaContent, LOCK_EX);
    }
}

$conn = mysqli_init();

if ($dbSslCa !== '') {
    $conn->ssl_set(null, null, $dbSslCa, null, null);
}

$connectHost = $dbHost;
if ($dbPersistent && $dbHost !== 'localhost' && !str_starts_with($dbHost, 'p:')) {
    $connectHost = 'p:' . $dbHost;
}

$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
$conn->real_connect($connectHost, $dbUser, $dbPass, $dbName, $dbPort);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("SET time_zone = '+08:00'");
?>
