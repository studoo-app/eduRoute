<?php
declare(strict_types=1);

namespace Studoo\EduRoute;

use RuntimeException;

use function sprintf;

/**
 * Le moteur d'expressions régulières (PCRE) a échoué en testant une route.
 *
 * Sans cette exception, l'échec passait pour une absence de correspondance : une URL valide
 * recevait un 404 sans explication (bug hérité de FastRoute, nikic/FastRoute#167).
 */
final class RegexMatchingFailed extends RuntimeException implements Exception
{
    public static function whileDispatching(string $uri, string $pcreError): self
    {
        return new self(sprintf(
            'Impossible de chercher la route de l\'URI "%s" : l\'expression régulière des routes a échoué (%s). '
            . 'Un paramètre de route utilise sans doute un motif trop coûteux, par exemple {p:(?:a?a?)*} : '
            . 'simplifiez-le, ou augmentez pcre.backtrack_limit / pcre.jit_stacklimit.',
            $uri,
            $pcreError,
        ));
    }

    public static function whileCheckingShadowing(string $route, string $regex, string $pcreError): self
    {
        return new self(sprintf(
            'Impossible de vérifier si la route statique "%s" est masquée par la route variable "%s" : '
            . 'l\'expression régulière a échoué (%s). Simplifiez le motif de la route variable.',
            $route,
            $regex,
            $pcreError,
        ));
    }
}
