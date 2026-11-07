# Middleware PSR-15

[English](../en/middleware.md)

Dans une application PSR-7/PSR-15, le routage se fait le plus souvent dans un *middleware* :
un maillon de la chaîne de traitement de la requête. EduRoute fournit
[`RoutingMiddleware`](../../src/Http/RoutingMiddleware.php), qui :

- trouve la route de la requête avec un [dispatcher](dispatching.md) ;
- répond directement `404` ou `405` quand il n'y a pas de route ;
- sinon, ajoute le résultat du routage à la requête et passe la main au *handler* suivant, qui
  exécute le contrôleur.

## Installation

`RoutingMiddleware` utilise les interfaces PSR-15 (middleware) et PSR-17 (fabriques), qui ne
sont pas installées avec EduRoute (seules les interfaces PSR-7 le sont). Ajoutez :

```sh
composer require psr/http-server-middleware psr/http-factory
```

Il vous faut aussi une implémentation de PSR-7 et PSR-17 (requêtes, réponses et fabriques),
par exemple [`nyholm/psr7`](https://github.com/Nyholm/psr7) :

```sh
composer require nyholm/psr7
```

## Créer le middleware

Le constructeur prend deux arguments :

```php
public function __construct(
    Dispatcher $dispatcher,                       // le dispatcher d'EduRoute
    ResponseFactoryInterface $responseFactory,    // fabrique PSR-17, pour les réponses 404 et 405
)
```

```php
use Studoo\EduRoute\Http\RoutingMiddleware;

$middleware = new RoutingMiddleware($router->dispatcher(), $psr17ResponseFactory);
```

> **Note**
> Vous pouvez passer `$router->lazyDispatcher()` à la place de `$router->dispatcher()` : les
> routes ne seront chargées qu'à la première requête réellement routée (voir
> [chargement paresseux](cache.md#chargement-paresseux)).

## Ce que fait le middleware

| Résultat du dispatch | Ce que fait `RoutingMiddleware` |
|---|---|
| [`Matched`](dispatching.md#matched) | ajoute des attributs à la requête, puis appelle `$handler->handle($request)` |
| [`MethodNotAllowed`](dispatching.md#methodnotallowed) | renvoie une réponse `405` avec l'en-tête `Allow` ; le *handler* suivant n'est pas appelé |
| [`NotMatched`](dispatching.md#notmatched) | renvoie une réponse `404` ; le *handler* suivant n'est pas appelé |

Les réponses `404` et `405` sont créées par la fabrique PSR-17, **avec un corps vide**.

Exemple complet : un *handler* final qui « exécute » le contrôleur trouvé, et quatre requêtes.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Http\RoutingMiddleware;

// Handler final : exécute le contrôleur choisi par le routeur
final readonly class ControllerHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = $request->getAttribute(RoutingMiddleware::ROUTE_ATTRIBUTE);
        assert($route instanceof Matched);

        $response = $this->responses->createResponse(200);
        $response->getBody()->write(sprintf(
            '%s, id=%s',
            $route->handler,
            $request->getAttribute('id'), // une variable de route = un attribut
        ));

        return $response;
    }
}

$factory = new Psr17Factory(); // implémente les interfaces PSR-17 (fabriques)

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/user/{id:\d+}', 'UserController::show');
        $r->post('/user/{id:\d+}', 'UserController::update');
    },
    'unused',
)->disableCache();

$middleware = new RoutingMiddleware($router->dispatcher(), $factory);
$next = new ControllerHandler($factory);

foreach ([['GET', '/user/42?tab=profile'], ['HEAD', '/user/42'], ['DELETE', '/user/42'], ['GET', '/nowhere']] as [$method, $uri]) {
    $response = $middleware->process($factory->createServerRequest($method, $uri), $next);

    echo $method, ' ', $uri, ' -> ', $response->getStatusCode();
    echo $response->hasHeader('Allow') ? ' Allow: ' . $response->getHeaderLine('Allow') : '';
    echo $response->getBody()->getSize() > 0 ? ' | ' . $response->getBody() : '', PHP_EOL;
}
```

Sortie :

```text
GET /user/42?tab=profile -> 200 | UserController::show, id=42
HEAD /user/42 -> 200 | UserController::show, id=42
DELETE /user/42 -> 405 Allow: GET, POST, HEAD
GET /nowhere -> 404
```

Dans une vraie application, `$middleware` est placé dans la pile de middlewares de votre
framework, juste avant le *handler* qui appelle les contrôleurs.

## Les attributs de la requête

Quand une route est trouvée, la requête transmise au *handler* suivant reçoit :

| Attribut | Valeur |
|---|---|
| `RoutingMiddleware::ROUTE_ATTRIBUTE` | l'objet [`Matched`](dispatching.md#matched) complet : `handler`, `variables`, `extraParameters` |
| un attribut par variable de route | `{id}` donne `$request->getAttribute('id')`, toujours une chaîne |

La constante `RoutingMiddleware::ROUTE_ATTRIBUTE` vaut
`'Studoo\EduRoute\Dispatcher\Result\Matched'` (le nom de la classe). Utilisez toujours la
constante plutôt que la chaîne.

Les [paramètres supplémentaires](routes.md#paramètres-supplémentaires) de la route ne deviennent **pas** des attributs :
lisez-les dans `$route->extraParameters`.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Http\RoutingMiddleware;

// Handler final : affiche ce que le middleware a ajouté à la requête
final readonly class DumpHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = $request->getAttribute(RoutingMiddleware::ROUTE_ATTRIBUTE);

        echo 'attributs : ', implode(', ', array_keys($request->getAttributes())), PHP_EOL;
        echo 'name : ', $request->getAttribute('name'), PHP_EOL;
        echo 'layout (attribut) : ', var_export($request->getAttribute('layout'), true), PHP_EOL;
        echo 'layout (extraParameters) : ', $route->extraParameters['layout'], PHP_EOL;

        return new Psr17Factory()->createResponse(204);
    }
}

$factory = new Psr17Factory();

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/café/{name}', 'CafeController::show', ['layout' => 'cafe']);
    },
    'unused',
)->disableCache();

// Le middleware reçoit un dispatcher paresseux : rien n'est chargé avant la première requête
$middleware = new RoutingMiddleware($router->lazyDispatcher(), $factory);

// Le chemin arrive encodé (%C3%A9 = é, %20 = espace) : le middleware le décode avant le dispatch
$request = $factory->createServerRequest('GET', '/caf%C3%A9/Jos%C3%A9%20Martin');

echo $middleware->process($request, new DumpHandler())->getStatusCode(), PHP_EOL;
```

Sortie :

```text
attributs : Studoo\EduRoute\Dispatcher\Result\Matched, name
name : José Martin
layout (attribut) : NULL
layout (extraParameters) : cafe
204
```

> **Attention**
> Une variable de route remplace un attribut de même nom déjà présent sur la requête (posé par
> un middleware précédent). Évitez de nommer vos variables comme des attributs utilisés
> ailleurs dans votre application.

## Comment l'URI est préparée

Le middleware fait pour vous la [préparation de l'URI](dispatching.md#préparer-luri)
décrite pour `dispatch()` :

- seul le **chemin** de l'URI est utilisé : la query string (`?tab=profile`) et le nom d'hôte
  sont ignorés ;
- le chemin est décodé avec `rawurldecode()` : `/caf%C3%A9` devient `/café` ;
- un chemin vide devient `/`.

La méthode HTTP est passée telle quelle (`$request->getMethod()`).

## `HEAD` et l'en-tête `Allow`

Le dispatcher sert une requête `HEAD` avec la route `GET` correspondante (voir
[repli HEAD vers GET](dispatching.md#repli-head-vers-get)). Le middleware en tient compte dans
la réponse `405` : quand `GET` fait partie des méthodes autorisées, il ajoute `HEAD` à
l'en-tête `Allow`, à la fin de la liste. C'est pourquoi l'exemple plus haut affiche
`Allow: GET, POST, HEAD` alors qu'aucune route `HEAD` n'est déclarée.

> **Note**
> PSR-7 ne retire pas le corps d'une réponse à une requête `HEAD` : dans l'exemple, la
> réponse contient encore le texte du contrôleur. C'est en général le serveur web (ou
> l'émetteur de réponse de votre framework) qui ne l'envoie pas.

## Personnaliser les pages 404 et 405

Les réponses `404` et `405` du middleware ont un corps vide. Pour afficher une vraie page
d'erreur, placez **avant** `RoutingMiddleware` un middleware qui examine le code de la
réponse :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Http\RoutingMiddleware;

// Ajoute un corps aux réponses 404 et 405 vides
final readonly class ErrorPages implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getBody()->getSize() === 0 && in_array($response->getStatusCode(), [404, 405], true)) {
            $response->getBody()->write(match ($response->getStatusCode()) {
                404 => 'Page introuvable',
                405 => 'Méthode non autorisée (autorisées : ' . $response->getHeaderLine('Allow') . ')',
            });
        }

        return $response;
    }
}

// Enchaîne un middleware et le handler suivant
final readonly class Next implements RequestHandlerInterface
{
    public function __construct(private MiddlewareInterface $middleware, private RequestHandlerInterface $next)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}

// Handler final : le « contrôleur »
final readonly class Hello implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = new Psr17Factory()->createResponse(200);
        $response->getBody()->write('Bonjour !');

        return $response;
    }
}

$factory = new Psr17Factory();
$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/', 'HomeController::index');
    },
    'unused',
)->disableCache();

// Pile : ErrorPages -> RoutingMiddleware -> Hello
$app = new Next(new ErrorPages(), new Next(new RoutingMiddleware($router->dispatcher(), $factory), new Hello()));

foreach ([['GET', '/'], ['GET', '/missing'], ['POST', '/']] as [$method, $uri]) {
    $response = $app->handle($factory->createServerRequest($method, $uri));
    echo $method, ' ', $uri, ' -> ', $response->getStatusCode(), ' ', $response->getBody(), PHP_EOL;
}
```

Sortie :

```text
GET / -> 200 Bonjour !
GET /missing -> 404 Page introuvable
POST / -> 405 Méthode non autorisée (autorisées : GET, HEAD)
```

La classe `Next` ne sert qu'à l'exemple : votre framework fournit déjà la pile de middlewares.

## Erreurs possibles

`RoutingMiddleware` ne capture aucune exception : celles du dispatcher remontent telles
quelles. Avec un dispatcher paresseux, ce sont aussi les erreurs de chargement des routes
([`BadRouteException`](errors.md#badrouteexception),
[`CannotCacheRoutes`](errors.md#cannotcacheroutes)), levées à la première requête. Voir la
[référence des erreurs](errors.md).

---

← [Page précédente](cache.md) · [Sommaire](index.md) · [Page suivante](how-it-works.md) →
