<?php
require __DIR__ . '/lib/bootstrap.php';
auth_logout();
header('Location: login.php');
exit;
