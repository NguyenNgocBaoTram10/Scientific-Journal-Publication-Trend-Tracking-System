<?php
session_start();

// nếu chưa đăng nhập
if (!isset($_SESSION["userId"])) {
    header("Location: login.html");
    exit();
}
?>