<?php
declare(strict_types=1);

namespace Studoo\EduRoute;

use Closure;
use ReflectionFunction;

use function array_is_list;
use function basename;
use function count;
use function get_debug_type;
use function is_array;
use function is_object;
use function is_string;
use function method_exists;
use function sprintf;

/**
 * Une route telle qu'elle a été déclarée : une instance par méthode HTTP et par appel à addRoute().
 *
 * Les instances sont créées par le routeur (RouteCollector::declaredRoutes(), EduRoute::declaredRoutes()) :
 * inutile de les construire soi-même. Tout futur paramètre du constructeur sera ajouté à la fin, avec une
 * valeur par défaut.
 *
 * Le routeur répond aussi à HEAD avec les routes GET : cette réponse implicite n'apparaît pas dans la liste.
 *
 * @phpstan-import-type ExtraParameters from DataGenerator
 */
final readonly class DeclaredRoute
{
    /**
     * @param string                $method          'GET', 'POST'…, une méthode non standard telle quelle, ou '*' (HttpMethod::Any)
     * @param string                $pattern         motif d'origine, préfixe de groupe inclus, segments optionnels non éclatés
     * @param non-empty-string|null $name            nom de la route (ConfigureRoutes::ROUTE_NAME), null si elle n'est pas nommée
     * @param ExtraParameters       $extraParameters paramètres supplémentaires, sans les clés réservées _route et _name
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public mixed $handler,
        public ?string $name,
        public array $extraParameters,
    ) {
    }

    /** Route de repli, déclarée avec any() ou HttpMethod::Any : elle répond à toutes les méthodes. */
    public function isFallback(): bool
    {
        return $this->method === HttpMethod::Any->value;
    }

    /**
     * Texte lisible du handler, pour l'afficher (commande routes:list…). Ne provoque jamais
     * d'erreur « Array to string conversion » : la valeur brute reste disponible dans $handler.
     */
    public function describeHandler(): string
    {
        $handler = $this->handler;

        if ($handler instanceof Closure) {
            $function = new ReflectionFunction($handler);
            $file = $function->getFileName();

            return $file === false
                ? 'Closure'
                : sprintf('Closure (%s:%d)', basename($file), (int) $function->getStartLine());
        }

        if (is_string($handler)) {
            return $handler;
        }

        if (
            is_array($handler)
            && count($handler) === 2
            && array_is_list($handler)
            && (is_string($handler[0]) || is_object($handler[0]))
            && is_string($handler[1])
        ) {
            return (is_object($handler[0]) ? get_debug_type($handler[0]) : $handler[0]) . '::' . $handler[1];
        }

        if (is_object($handler) && method_exists($handler, '__invoke')) {
            return get_debug_type($handler) . '::__invoke';
        }

        return get_debug_type($handler);
    }
}
