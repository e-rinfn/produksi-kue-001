<?php
require_once __DIR__ . '/config/config.php';

// Redirect ke halaman dashboard
header("Location: " . BASE_URL . "/modules/dashboard/");
exit();
