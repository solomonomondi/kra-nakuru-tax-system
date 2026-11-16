<?php
require_once 'config.php';
if (empty($_SESSION['user_id'])) { header("Location: login.php"); exit; }
header("Location: dashboard.php"); exit;
