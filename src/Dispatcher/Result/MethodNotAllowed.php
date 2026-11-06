<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Dispatcher\Result;

use ArrayAccess;
use Deprecated;
use OutOfBoundsException;
use Override;
use RuntimeException;
use Studoo\EduRoute\Dispatcher;

/** @implements ArrayAccess<int, Dispatcher::METHOD_NOT_ALLOWED|non-empty-list<string>> */
final readonly class MethodNotAllowed implements ArrayAccess
{
    /** @var non-empty-list<string> $allowedMethods Écriture unique (readonly), faite par le dispatcher */
    public public(set) array $allowedMethods;

    #[Override]
    #[Deprecated('use $result instanceof MethodNotAllowed and $result->allowedMethods instead', since: '1.0')]
    public function offsetExists(mixed $offset): bool
    {
        return $offset === 0 || $offset === 1;
    }

    /** @return Dispatcher::METHOD_NOT_ALLOWED|non-empty-list<string> */
    #[Override]
    #[Deprecated('use $result instanceof MethodNotAllowed and $result->allowedMethods instead', since: '1.0')]
    public function offsetGet(mixed $offset): int|array
    {
        return match ($offset) {
            0 => Dispatcher::METHOD_NOT_ALLOWED,
            1 => $this->allowedMethods,
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
