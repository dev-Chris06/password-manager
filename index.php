<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (utilisateur_connecte()) {
    redirect_to('pages/dashboard.php');
}

redirect_to('pages/login.php');
