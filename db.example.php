<?php
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: '';
$dbName = getenv('DB_DATABASE') ?: 'jj_kitchenette';
$dbPort = (int) (getenv('DB_PORT') ?: 3306);
$dbSslCa = getenv('DB_SSL_CA') ?: '';
$dbSslCaContent = getenv('DB_SSL_CA_CONTENT') ?: '';

if ($dbSslCa === '' && $dbSslCaContent !== '') {
    $dbSslCa = sys_get_temp_dir() . '/db-ca.pem';
    file_put_contents($dbSslCa, $dbSslCaContent);
}

$conn = mysqli_init();

if ($dbSslCa !== '') {
    $conn->ssl_set(null, null, $dbSslCa, null, null);
}

$conn->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
