<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Dispatcher\Result;

use ArrayAccess;
use Deprecated;
use OutOfBoundsException;
use Override;
use RuntimeException;
use Studoo\EduRoute\Dispatcher;

/** @implements ArrayAccess<int, Dispatcher::NOT_FOUND> */
final readonly class NotMatched implements ArrayAccess
{
    #[Override]
    #[Deprecated('use $result instanceof NotMatched instead', since: '1.0')]
    public function offsetExists(mixed $offset): bool
    {
        return $offset === 0;
    }

    #[Override]
    #[Deprecated('use $result instanceof NotMatched instead', since: '1.0')]
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
