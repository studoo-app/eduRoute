<?php
declare(strict_types=1);

namespace Studoo\EduRoute;

use TypeError;

use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Méthodes HTTP reconnues par le routeur.
 *
 * Partout où une méthode est attendue, on peut passer un cas de cette énumération
 * (`HttpMethod::Get`) ou une chaîne (`'GET'`), y compris une méthode non standard.
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Head = 'HEAD';
    case Options = 'OPTIONS';

    /** Route de repli : correspond à toute méthode qui n'a pas de route dédiée. */
    case Any = '*';

    /**
     * Normalise une ou plusieurs méthodes (énumération ou chaîne) en liste de chaînes.
     *
     * @param self|string|array<self|string> $methods
     *
     * @return list<string>
     */
    public static function normalize(self|string|array $methods): array
    {
        if (! is_array($methods)) {
            return [$methods instanceof self ? $methods->value : $methods];
        }

        // foreach plutôt qu'array_map : la closure appelée par une fonction interne ne serait pas
        // soumise à strict_types, et convertirait silencieusement 405 ou true en chaîne
        $normalized = [];
        foreach ($methods as $method) {
            if ($method instanceof self) {
                $normalized[] = $method->value;
                continue;
            }

            // @phpstan-ignore function.alreadyNarrowedType (le type PHPDoc n'est pas vérifié à l'exécution)
            if (! is_string($method)) {
                throw new TypeError(sprintf(
                    'HTTP method must be %s or string, %s given',
                    self::class,
                    get_debug_type($method),
                ));
            }

            $normalized[] = $method;
        }

        return $normalized;
    }
}
