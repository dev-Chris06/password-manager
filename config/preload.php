<?php

declare(strict_types=1);

$preloadFiles = glob(dirname(__DIR__).'/var/cache/prod/*.preload.php');

foreach (false === $preloadFiles ? [] : $preloadFiles as $file) {
    require $file;
}
