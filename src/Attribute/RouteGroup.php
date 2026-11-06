<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Attribute;

use Attribute;

/**
 * Préfixe commun à toutes les routes #[Route] d'un contrôleur.
 *
 *     #[RouteGroup('/admin', namePrefix: 'admin.')]
 *     final class ProductController { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RouteGroup
{
    public function __construct(
        public string $prefix,
        public string $namePrefix = '',
    ) {
    }
}
