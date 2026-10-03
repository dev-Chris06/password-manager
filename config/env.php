<?php
declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $value = trim($value, " \t\n\r\0\x0B");

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    $cles_obligatoires = ['DB_HOST', 'DB_NAME', 'DB_USER'];
    $manquantes = [];
    foreach ($cles_obligatoires as $c) {
        if (!isset($_ENV[$c]) || $_ENV[$c] === '') {
            $manquantes[] = $c;
        }
    }
    if ((($_ENV['DB_PASS'] ?? '') === '') && (($_ENV['DB_PASSWORD'] ?? '') === '')) {
        $manquantes[] = 'DB_PASS';
    }

    if (!empty($manquantes)) {
        http_response_code(500);
        die('ERREUR CONFIG : variables .env manquantes : ' . implode(', ', $manquantes));
    }

    if (isset($_ENV['DB_HOST'])) {
        $host = $_ENV['DB_HOST'];
        $ipValide = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $hostValide = preg_match('/^[a-z0-9.-]+$/i', $host) === 1;
        if (!$ipValide && !$hostValide) {
            http_response_code(500);
            die('ERREUR CONFIG : DB_HOST invalide.');
        }
    }
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}
