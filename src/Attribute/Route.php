<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Attribute;

use Attribute;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\HttpMethod;

/**
 * Déclare une route directement sur une méthode de contrôleur.
 *
 *     #[Route('/user/{id:\d+}', name: 'user.show')]
 *     public function show(): string { ... }
 *
 * Répétable : une méthode peut répondre à plusieurs routes. Les routes sont enregistrées par
 * AttributeRouteLoader, avec pour handler la chaîne "Classe::méthode".
 *
 * @phpstan-import-type ExtraParameters from DataGenerator
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /**
     * @param string                                    $path    motif de la route, relatif au préfixe de RouteGroup
     * @param HttpMethod|string|list<HttpMethod|string> $methods méthode(s) HTTP, GET par défaut
     * @param non-empty-string|null                     $name    nom de la route (génération d'URL), préfixé par RouteGroup
     * @param ExtraParameters                           $extra   paramètres supplémentaires renvoyés dans Matched::$extraParameters
     */
    public function __construct(
        public string $path,
        public HttpMethod|string|array $methods = HttpMethod::Get,
        public ?string $name = null,
        public array $extra = [],
    ) {
    }
}
