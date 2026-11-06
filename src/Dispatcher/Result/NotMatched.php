<?php
declare(strict_types=1);

namespace FastRoute\Dispatcher\Result;

use ArrayAccess;
use FastRoute\Dispatcher;
use OutOfBoundsException;
use Override;
use RuntimeException;

/** @implements ArrayAccess<int, Dispatcher::NOT_FOUND> */
final class NotMatched implements ArrayAccess
{
    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return $offset === 0;
    }

    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            0 => Dispatcher::NOT_FOUND,
            default => throw new OutOfBoundsException(),
        };
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new RuntimeException('Result cannot be changed');
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new RuntimeException('Result cannot be changed');
    }
}
