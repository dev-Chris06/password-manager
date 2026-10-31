<?php

declare(strict_types=1);

// Gives PHPStan (phpstan-symfony) access to the console commands.

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

return new Application(new Kernel('test', true));
