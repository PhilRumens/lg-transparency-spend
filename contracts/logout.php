<?php
require_once __DIR__ . '/config.php';
start_session();
$_SESSION = [];
session_destroy();
header('Location: /contracts/tech_login.php');
exit;
