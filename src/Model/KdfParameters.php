<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Parameters of the key derivation function run on the client (docs/PLAN.md §4.2).
 */
final readonly class KdfParameters
{
    public const string ALGORITHM = 'argon2id';
    // Floors enforced on both sides: the client refuses weaker parameters, the server never stores them.
    public const int MIN_MEMORY_KIB = 65536;
    public const int MIN_ITERATIONS = 3;
    public const int MIN_PARALLELISM = 1;
    // Ceilings keep a hostile request from making a client freeze while deriving.
    public const int MAX_MEMORY_KIB = 1048576;
    public const int MAX_ITERATIONS = 64;
    public const int MAX_PARALLELISM = 16;

    public function __construct(
        public string $algorithm,
        public int $memoryKib,
        public int $iterations,
        public int $parallelism,
    ) {
    }

    public static function defaults(): self
    {
        return new self(self::ALGORITHM, self::MIN_MEMORY_KIB, self::MIN_ITERATIONS, self::MIN_PARALLELISM);
    }

    public function isAcceptable(): bool
    {
        return self::ALGORITHM === $this->algorithm
            && $this->memoryKib >= self::MIN_MEMORY_KIB && $this->memoryKib <= self::MAX_MEMORY_KIB
            && $this->iterations >= self::MIN_ITERATIONS && $this->iterations <= self::MAX_ITERATIONS
            && $this->parallelism >= self::MIN_PARALLELISM && $this->parallelism <= self::MAX_PARALLELISM;
    }

    /**
     * @return array{algorithm: string, memoryKib: int, iterations: int, parallelism: int}
     */
    public function toArray(): array
    {
        return [
            'algorithm' => $this->algorithm,
            'memoryKib' => $this->memoryKib,
            'iterations' => $this->iterations,
            'parallelism' => $this->parallelism,
        ];
    }
}
