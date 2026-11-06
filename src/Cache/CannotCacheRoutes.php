<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Cache;

use LogicException;
use Studoo\EduRoute\Exception;

use function get_debug_type;
use function sprintf;

final class CannotCacheRoutes extends LogicException implements Exception
{
    public static function becauseOfObject(object $value): self
    {
        return new self(sprintf(
            'Les routes ne peuvent pas être mises en cache dans un fichier : elles contiennent un objet (%s). '
            . 'Avec un cache, utilisez des handlers exportables (chaîne "App\\Controller\\UserController::show", '
            . 'tableau, scalaire) ; les closures et objets ne fonctionnent qu\'avec ->disableCache().',
            get_debug_type($value),
        ));
    }
}
