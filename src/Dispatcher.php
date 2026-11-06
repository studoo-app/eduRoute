<?php
declare(strict_types=1);

namespace Studoo\EduRoute;

use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;

interface Dispatcher
{
    /** @deprecated since 1.0, only meaningful with the deprecated array access: use `instanceof NotMatched` */
    public const int NOT_FOUND = 0;

    /** @deprecated since 1.0, only meaningful with the deprecated array access: use `instanceof Matched` */
    public const int FOUND = 1;

    /** @deprecated since 1.0, only meaningful with the deprecated array access: use `instanceof MethodNotAllowed` */
    public const int METHOD_NOT_ALLOWED = 2;

    /**
     * Cherche la route qui correspond à la méthode HTTP et à l'URI.
     *
     * Renvoie un objet immuable : Matched (handler et variables), MethodNotAllowed (méthodes
     * autorisées, pour une réponse 405) ou NotMatched (404). L'ancien accès tableau
     * ([self::FOUND, $handler, $vars]…) fonctionne encore mais est déprécié.
     */
    public function dispatch(HttpMethod|string $httpMethod, string $uri): Matched|NotMatched|MethodNotAllowed;
}
