<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Http;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;

use function implode;
use function in_array;
use function rawurldecode;

/**
 * Middleware PSR-15 de routage (nécessite psr/http-server-middleware et psr/http-factory).
 *
 * - route trouvée : la requête reçoit l'attribut ROUTE_ATTRIBUTE (l'objet Matched, donc le
 *   handler) et un attribut par variable de route ({id} → $request->getAttribute('id')), puis
 *   passe au handler suivant, qui exécute le contrôleur ;
 * - méthode non autorisée : réponse 405 avec l'en-tête Allow ;
 * - aucune route : réponse 404.
 */
final readonly class RoutingMiddleware implements MiddlewareInterface
{
    /** Nom de l'attribut de requête qui contient le résultat Matched */
    public const string ROUTE_ATTRIBUTE = Matched::class;

    public function __construct(
        private Dispatcher $dispatcher,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $result = $this->dispatcher->dispatch($request->getMethod(), rawurldecode($path === '' ? '/' : $path));

        if ($result instanceof MethodNotAllowed) {
            $allowed = $result->allowedMethods;

            // HEAD est servi par les routes GET (repli du dispatcher) : il est donc aussi autorisé
            if (in_array('GET', $allowed, true) && ! in_array('HEAD', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            return $this->responseFactory
                ->createResponse(405)
                ->withHeader('Allow', implode(', ', $allowed));
        }

        if (! $result instanceof Matched) {
            return $this->responseFactory->createResponse(404);
        }

        $request = $request->withAttribute(self::ROUTE_ATTRIBUTE, $result);
        foreach ($result->variables as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $handler->handle($request);
    }
}
