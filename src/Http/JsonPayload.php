<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Strictly typed access to a JSON request body. Any malformed field yields a 400 or 422 error
 * without echoing the submitted values.
 */
final readonly class JsonPayload
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function fromRequest(Request $request, int $maxBytes = 1_048_576): self
    {
        $content = $request->getContent();
        if (\strlen($content) > $maxBytes) {
            throw new BadRequestHttpException('Request body too large.');
        }

        try {
            $data = json_decode($content, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Malformed JSON.');
        }

        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw new BadRequestHttpException('A JSON object is expected.');
        }

        /* @var array<string, mixed> $data */
        return new self($data);
    }

    public function string(string $key, int $maxLength = 255): string
    {
        $value = $this->data[$key] ?? null;
        if (!\is_string($value) || '' === $value || mb_strlen($value) > $maxLength) {
            throw self::invalid($key);
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;
        if (!\is_int($value)) {
            throw self::invalid($key);
        }

        return $value;
    }

    public function object(string $key): self
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw self::invalid($key);
        }

        /* @var array<string, mixed> $value */
        return new self($value);
    }

    /**
     * @return list<self>
     */
    public function objects(string $key, int $maxItems): array
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value) || \count($value) > $maxItems) {
            throw self::invalid($key);
        }

        $items = [];
        foreach ($value as $item) {
            if (!\is_array($item) || ([] !== $item && array_is_list($item))) {
                throw self::invalid($key);
            }
            /* @var array<string, mixed> $item */
            $items[] = new self($item);
        }

        return $items;
    }

    public function email(string $key = 'email'): string
    {
        $email = mb_strtolower(trim($this->string($key, 180)));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw self::invalid($key);
        }

        return $email;
    }

    /**
     * A base64 string whose decoded size is exactly (or at most) the given number of bytes.
     */
    public function base64(string $key, int $bytes, bool $exact = true): string
    {
        $value = $this->string($key, (int) ceil($bytes / 3) * 4);
        $decoded = base64_decode($value, true);
        if (false === $decoded || ($exact ? \strlen($decoded) !== $bytes : \strlen($decoded) > $bytes) || base64_encode($decoded) !== $value) {
            throw self::invalid($key);
        }

        return $value;
    }

    private static function invalid(string $key): UnprocessableEntityHttpException
    {
        return new UnprocessableEntityHttpException(\sprintf('Invalid field "%s".', $key));
    }
}
