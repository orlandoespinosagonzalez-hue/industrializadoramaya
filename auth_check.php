<?php
// auth_check.php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['auth']) || $_SESSION['auth'] !== true) {
  $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
  header('Location: login.php?next=' . urlencode($next));
  exit;
}
