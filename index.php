<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

redirect(is_logged_in() ? 'dashboard.php' : 'login.php');
