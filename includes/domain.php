<?php
declare(strict_types=1);

function normaliser_domaine_site(string $value): string
{
    $value = strtolower(trim($value));

    if ($value === '') {
        return '';
    }

    if (!str_contains($value, '://')) {
        $value = 'https://' . $value;
    }

    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }

    $host = strtolower(rtrim($host, '.'));
    $host = preg_replace('/^www\./', '', $host) ?? '';

    if ($host === '' || strlen($host) > 253) {
        return '';
    }

    foreach (explode('.', $host) as $label) {
        if (
            $label === '' ||
            strlen($label) > 63 ||
            preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1
        ) {
            return '';
        }
    }

    return $host;
}

function site_correspond_au_domaine(string $site, string $domaine): bool
{
    return $domaine !== '' && normaliser_domaine_site($site) === $domaine;
}
