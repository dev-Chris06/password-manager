<?php
declare(strict_types=1);

/**
 * Génère les QR codes localement, sans transmettre le secret TOTP à un tiers.
 *
 * Cette façade repose sur PHP QR Code 1.1.4 (LGPL-3.0-or-later), inclus dans
 * third_party/phpqrcode/. L'ancien encodeur artisanal produisait une matrice
 * invalide pour certains QR TOTP et a été retiré.
 */
final class QRCodeGenerator
{
    public static function generatePng(string $data, int $scale = 5, int $quietZone = 4): string
    {
        if ($data === '') {
            throw new InvalidArgumentException('Les données du QR code sont requises.');
        }
        if (!extension_loaded('gd')) {
            throw new RuntimeException('L’extension PHP GD est requise pour générer le QR code.');
        }

        require_once __DIR__ . '/../third_party/phpqrcode/phpqrcode.php';

        ob_start();
        try {
            // Correction M : robuste à l'écran tout en gardant une taille compacte.
            QRcode::png($data, false, QR_ECLEVEL_M, $scale, $quietZone);
            $png = ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw new RuntimeException('Échec de génération du QR code.', previous: $e);
        }

        if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('Le générateur n’a pas produit une image PNG valide.');
        }

        return $png;
    }
}
