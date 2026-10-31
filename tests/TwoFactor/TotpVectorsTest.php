<?php

declare(strict_types=1);

namespace App\Tests\TwoFactor;

use App\TwoFactor\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpVectorsTest extends TestCase
{
    /**
     * RFC 6238 appendix B, SHA-1, secret "12345678901234567890", truncated to 6 digits.
     *
     * @return iterable<array{int, string}>
     */
    public static function vectors(): iterable
    {
        yield [59, '287082'];
        yield [1111111109, '081804'];
        yield [1111111111, '050471'];
        yield [1234567890, '005924'];
        yield [2000000000, '279037'];
        yield [20000000000, '353130'];
    }

    #[DataProvider('vectors')]
    public function testRfc6238(int $timestamp, string $expected): void
    {
        // base32("12345678901234567890")
        self::assertSame($expected, TotpService::codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $timestamp));
    }
}
