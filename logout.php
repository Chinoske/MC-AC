<?php
require_once __DIR__ . '/config.php';
$user = new User();
$user->logout();
header('Location: index.php');
exit;
