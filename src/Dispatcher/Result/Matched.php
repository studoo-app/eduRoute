<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Dispatcher\Result;

use ArrayAccess;
use Deprecated;
use OutOfBoundsException;
use Override;
use RuntimeException;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;

/**
 * @phpstan-import-type ExtraParameters from DataGenerator
 * @implements ArrayAccess<int, Dispatcher::FOUND|mixed|array<string, string>>
 */
final readonly class Matched implements ArrayAccess
{
    /*
     * Propriétés « write-once » : readonly (une seule écriture, imposée par le moteur) et
     * public(set) pour que les dispatchers les initialisent sans appel de constructeur
     * (un constructeur coûtait ~25 ns, soit +10 % par route trouvée). Une fois écrites,
     * toute modification lève une Error. Chaque dispatcher initialise les trois propriétés.
     */
    public public(set) mixed $handler;

    /** @var array<string, string> $variables */
    public public(set) array $variables;

    /** @var ExtraParameters */
    public public(set) array $extraParameters;

    #[Override]
    #[Deprecated('use $result instanceof Matched and its properties instead', since: '1.0')]
    public function offsetExists(mixed $offset): bool
    {
        return $offset >= 0 && $offset <= 2;
    }

    #[Override]
    #[Deprecated('use $result instanceof Matched and its properties instead', since: '1.0')]
    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            0 => Dispatcher::FOUND,
            1 => $this->handler,
            2 => $this->variables,
            default => throw new OutOfBoundsException()
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
