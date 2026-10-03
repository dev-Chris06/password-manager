<?php
declare(strict_types=1);

// Routeur destiné au serveur PHP intégré et aux hôtes Apache via .htaccess.
// Le code applicatif reste un niveau au-dessus du DocumentRoot.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ltrim(is_string($uri) ? $uri : '/', '/');

if ($path === '' || $path === 'index.php') {
    require __DIR__ . '/../index.php';
    return;
}

if (preg_match('#^assets/(css|js)/[a-z0-9_.-]+$#i', $path)) {
    $target = dirname(__DIR__) . '/' . $path;
    if (is_file($target)) {
        header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'application/javascript') . '; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        readfile($target);
        return;
    }
}

if (preg_match('#^(pages|ajax)/[a-z_]+\.php$#', $path)) {
    $target = dirname(__DIR__) . '/' . $path;
    if (is_file($target)) {
        require $target;
        return;
    }
}

http_response_code(404);
exit('Not found');
