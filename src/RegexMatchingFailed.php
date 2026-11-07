<?php
declare(strict_types=1);

namespace Studoo\EduRoute;

use RuntimeException;

use function preg_last_error;
use function sprintf;

use const PREG_INTERNAL_ERROR;

/**
 * Le moteur d'expressions régulières (PCRE) a échoué en testant une route.
 *
 * Sans cette exception, l'échec passait pour une absence de correspondance : une URL valide
 * recevait un 404 sans explication (bug hérité de FastRoute, nikic/FastRoute#167).
 */
final class RegexMatchingFailed extends RuntimeException implements Exception
{
    /** À appeler juste après l'appel preg_*() qui a échoué : le conseil dépend de preg_last_error(). */
    public static function whileDispatching(string $uri, string $pcreError): self
    {
        return new self(sprintf(
            'Impossible de chercher la route de l\'URI "%s" : l\'expression régulière des routes a échoué (%s). %s',
            $uri,
            $pcreError,
            self::hint(
                'Un paramètre de route utilise sans doute un motif trop coûteux, par exemple {p:(?:a?a?)*} : '
                . 'simplifiez-le, ou augmentez pcre.backtrack_limit / pcre.jit_stacklimit.',
            ),
        ));
    }

    /** À appeler juste après l'appel preg_*() qui a échoué : le conseil dépend de preg_last_error(). */
    public static function whileCheckingShadowing(string $route, string $regex, string $pcreError): self
    {
        return new self(sprintf(
            'Impossible de vérifier si la route statique "%s" est masquée par la route variable "%s" : '
            . 'l\'expression régulière a échoué (%s). %s',
            $route,
            $regex,
            $pcreError,
            self::hint('Simplifiez le motif de la route variable.'),
        ));
    }

    /**
     * PCRE signale « Internal error » quand l'expression ne compile pas : le motif d'un paramètre
     * est alors invalide (par exemple {p:a)}), et ce n'est pas une question de limite.
     */
    private static function hint(string $limitExhausted): string
    {
        return preg_last_error() === PREG_INTERNAL_ERROR
            ? 'Le motif d\'un paramètre de route est sans doute invalide (le message d\'avertissement de '
                . 'preg_match() donne l\'erreur de compilation) : corrigez-le.'
            : $limitExhausted;
    }
}
