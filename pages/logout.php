<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

deconnecter_utilisateur();
redirect_to('pages/login.php');
