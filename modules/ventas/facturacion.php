<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
header('Location: ' . BASE_URL . 'modules/ventas/index.php?tab=sunat');
exit;
