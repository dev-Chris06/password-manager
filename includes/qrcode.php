<?php
declare(strict_types=1);

final class QRCodeGenerator
{
    private const VERSION = 6;
    private const SIZE = 41;
    private const DATA_CODEWORDS = 136;
    private const EC_CODEWORDS_PER_BLOCK = 18;
    private const BLOCKS = 2;

    public static function generateSvgDataUri(string $data, int $scale = 5, int $quietZone = 4): string
    {
        $matrix = self::createMatrix($data);
        $moduleCount = self::SIZE + ($quietZone * 2);
        $size = $moduleCount * $scale;
        $rects = [];

        foreach ($matrix as $row => $cols) {
            foreach ($cols as $col => $dark) {
                if ($dark) {
                    $x = ($col + $quietZone) * $scale;
                    $y = ($row + $quietZone) * $scale;
                    $rects[] = '<rect x="' . $x . '" y="' . $y . '" width="' . $scale . '" height="' . $scale . '"/>';
                }
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . '<g fill="#000">' . implode('', $rects) . '</g>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * @return array<int, array<int, bool>>
     */
    private static function createMatrix(string $data): array
    {
        $bytes = array_values(unpack('C*', $data) ?: []);
        if (count($bytes) > 134) {
            throw new RuntimeException('Donnees trop longues pour le QR code local.');
        }

        $modules = array_fill(0, self::SIZE, array_fill(0, self::SIZE, null));
        $reserved = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));

        self::drawFunctionPatterns($modules, $reserved);
        $codewords = self::encodeData($bytes);
        self::drawCodewords($modules, $reserved, $codewords);
        self::applyMask0($modules, $reserved);
        self::drawFormatBits($modules, $reserved);

        foreach ($modules as $row => $cols) {
            foreach ($cols as $col => $value) {
                $modules[$row][$col] = (bool) $value;
            }
        }

        return $modules;
    }

    private static function drawFunctionPatterns(array &$modules, array &$reserved): void
    {
        self::drawFinder($modules, $reserved, 0, 0);
        self::drawFinder($modules, $reserved, self::SIZE - 7, 0);
        self::drawFinder($modules, $reserved, 0, self::SIZE - 7);

        for ($i = 8; $i < self::SIZE - 8; $i++) {
            self::setFunction($modules, $reserved, 6, $i, $i % 2 === 0);
            self::setFunction($modules, $reserved, $i, 6, $i % 2 === 0);
        }

        self::drawAlignment($modules, $reserved, 34, 34);
        self::setFunction($modules, $reserved, 8, 33, true);

        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = self::SIZE - 8; $i < self::SIZE; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
    }

    private static function drawFinder(array &$modules, array &$reserved, int $x, int $y): void
    {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $row = $y + $dy;
                $col = $x + $dx;
                if ($row < 0 || $row >= self::SIZE || $col < 0 || $col >= self::SIZE) {
                    continue;
                }
                $dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                    && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                self::setFunction($modules, $reserved, $row, $col, $dark);
            }
        }
    }

    private static function drawAlignment(array &$modules, array &$reserved, int $centerX, int $centerY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $dark = max(abs($dx), abs($dy)) !== 1;
                self::setFunction($modules, $reserved, $centerY + $dy, $centerX + $dx, $dark);
            }
        }
    }

    private static function setFunction(array &$modules, array &$reserved, int $row, int $col, bool $dark): void
    {
        $modules[$row][$col] = $dark;
        $reserved[$row][$col] = true;
    }

    /**
     * @param array<int, int> $bytes
     * @return array<int, int>
     */
    private static function encodeData(array $bytes): array
    {
        $bits = [];
        self::appendBits($bits, 0b0100, 4);
        self::appendBits($bits, count($bytes), 8);
        foreach ($bytes as $byte) {
            self::appendBits($bits, $byte, 8);
        }

        $capacityBits = self::DATA_CODEWORDS * 8;
        self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $dataCodewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $value = 0;
            for ($j = 0; $j < 8; $j++) {
                $value = ($value << 1) | $bits[$i + $j];
            }
            $dataCodewords[] = $value;
        }

        for ($pad = 0xEC; count($dataCodewords) < self::DATA_CODEWORDS; $pad = $pad === 0xEC ? 0x11 : 0xEC) {
            $dataCodewords[] = $pad;
        }

        $blocks = array_chunk($dataCodewords, intdiv(self::DATA_CODEWORDS, self::BLOCKS));
        $eccBlocks = array_map(fn (array $block): array => self::reedSolomonRemainder($block), $blocks);
        $result = [];

        for ($i = 0; $i < count($blocks[0]); $i++) {
            foreach ($blocks as $block) {
                $result[] = $block[$i];
            }
        }
        for ($i = 0; $i < self::EC_CODEWORDS_PER_BLOCK; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private static function reedSolomonRemainder(array $data): array
    {
        $generator = self::reedSolomonGenerator(self::EC_CODEWORDS_PER_BLOCK);
        $result = array_fill(0, self::EC_CODEWORDS_PER_BLOCK, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $result[0];
            array_shift($result);
            $result[] = 0;
            foreach ($generator as $i => $coefficient) {
                $result[$i] ^= self::gfMultiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private static function reedSolomonGenerator(int $degree): array
    {
        $result = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($result) + 1, 0);
            foreach ($result as $j => $value) {
                $next[$j] ^= self::gfMultiply($value, self::gfPow(2, $i));
                $next[$j + 1] ^= $value;
            }
            $result = $next;
        }
        array_shift($result);

        return $result;
    }

    private static function gfPow(int $value, int $power): int
    {
        $result = 1;
        while ($power-- > 0) {
            $result = self::gfMultiply($result, $value);
        }

        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $result = 0;
        while ($y > 0) {
            if (($y & 1) !== 0) {
                $result ^= $x;
            }
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
            $y >>= 1;
        }

        return $result & 0xFF;
    }

    /**
     * @param array<int, int> $codewords
     */
    private static function drawCodewords(array &$modules, array $reserved, array $codewords): void
    {
        $bits = [];
        foreach ($codewords as $codeword) {
            self::appendBits($bits, $codeword, 8);
        }

        $bitIndex = 0;
        $direction = -1;
        for ($right = self::SIZE - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }
            for ($i = 0; $i < self::SIZE; $i++) {
                $row = $direction === -1 ? self::SIZE - 1 - $i : $i;
                for ($col = $right; $col >= $right - 1; $col--) {
                    if ($reserved[$row][$col]) {
                        continue;
                    }
                    $modules[$row][$col] = ($bits[$bitIndex] ?? 0) === 1;
                    $bitIndex++;
                }
            }
            $direction *= -1;
        }
    }

    private static function applyMask0(array &$modules, array $reserved): void
    {
        for ($row = 0; $row < self::SIZE; $row++) {
            for ($col = 0; $col < self::SIZE; $col++) {
                if (!$reserved[$row][$col] && (($row + $col) % 2 === 0)) {
                    $modules[$row][$col] = !$modules[$row][$col];
                }
            }
        }
    }

    private static function drawFormatBits(array &$modules, array &$reserved): void
    {
        $format = 0b111011111000100; // ECC L, mask 0.

        for ($i = 0; $i <= 5; $i++) {
            self::setFunction($modules, $reserved, 8, $i, (($format >> $i) & 1) !== 0);
        }
        self::setFunction($modules, $reserved, 8, 7, (($format >> 6) & 1) !== 0);
        self::setFunction($modules, $reserved, 8, 8, (($format >> 7) & 1) !== 0);
        self::setFunction($modules, $reserved, 7, 8, (($format >> 8) & 1) !== 0);
        for ($i = 9; $i < 15; $i++) {
            self::setFunction($modules, $reserved, 14 - $i, 8, (($format >> $i) & 1) !== 0);
        }

        for ($i = 0; $i < 8; $i++) {
            self::setFunction($modules, $reserved, self::SIZE - 1 - $i, 8, (($format >> $i) & 1) !== 0);
        }
        for ($i = 8; $i < 15; $i++) {
            self::setFunction($modules, $reserved, 8, self::SIZE - 15 + $i, (($format >> $i) & 1) !== 0);
        }
    }
}
