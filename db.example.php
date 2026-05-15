<?php
$conn = new mysqli("localhost", "root", "", "jj_kitchenette");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
