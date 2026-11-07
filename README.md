EduRoute — routeur HTTP rapide pour PHP 8.4+
=============================================

[![Qualité](https://github.com/studoo-app/eduRoute/actions/workflows/qa.yml/badge.svg)](https://github.com/studoo-app/eduRoute/actions/workflows/qa.yml)

`studoo/edu-route` associe une URL et une méthode HTTP à un *handler* (un nom de contrôleur,
une closure, n'importe quelle donnée). C'est la couche de routage d'[edu-framework](https://github.com/studoo-app/edu-framework).

Il s'agit d'un fork modernisé de [nikic/FastRoute](https://github.com/nikic/FastRoute), dont il
reprend l'algorithme à base d'expressions régulières combinées ([explication de son
fonctionnement][blog_post]), réécrit pour PHP 8.4 : énumérations, objets immuables, attributs
`#[\Override]`, analyse statique au niveau maximal.

Installation
------------

```sh
composer require studoo/edu-route
```

PHP 8.4 ou 8.5 requis.

Utilisation rapide
------------------

```php
<?php

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'get_all_users_handler');
        // {id} doit être un nombre (\d+)
        $r->get('/user/{id:\d+}', 'get_user_handler');
        // Le suffixe /{title} est optionnel
        $r->addRoute(HttpMethod::Get, '/articles/{id:\d+}[/{title}]', 'get_article_handler');
    },
    __DIR__ . '/var/cache/routes.php', // fichier de cache (voir « Cache »)
);

$dispatcher = $router->dispatcher();

// Récupère la méthode et l'URI, sans la query string (?foo=bar), puis décode l'URI
$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = rawurldecode(strtok($_SERVER['REQUEST_URI'], '?'));

$result = $dispatcher->dispatch($httpMethod, $uri);

echo match (true) {
    $result instanceof Matched => call_user_func($result->handler, $result->variables),
    $result instanceof MethodNotAllowed => '405 – méthodes autorisées : ' . implode(', ', $result->allowedMethods),
    $result instanceof NotMatched => '404 – page introuvable',
};
```

Définir les routes
------------------

Les routes sont déclarées dans une closure qui reçoit un `ConfigureRoutes` :

```php
$r->addRoute($method, $routePattern, $handler);
```

### Méthodes HTTP

`$method` accepte un cas de l'énumération `HttpMethod`, une chaîne en majuscules (y compris une
méthode non standard comme `'PURGE'`) ou un tableau mélangeant les deux :

```php
$r->addRoute(HttpMethod::Get, '/test', 'handler');
$r->addRoute('GET', '/test', 'handler');
$r->addRoute([HttpMethod::Put, 'PATCH'], '/test', 'handler');
```

Des raccourcis existent : `get()`, `post()`, `put()`, `patch()`, `delete()`, `head()`,
`options()` et `any()`. Cette dernière déclare une route **de repli** (`HttpMethod::Any`, soit
`'*'`), utilisée quand aucune route n'existe pour la méthode demandée. Une requête `HEAD` sans
route dédiée retombe automatiquement sur la route `GET`.

### Motifs d'URL

`{foo}` est un paramètre nommé `foo` qui accepte `[^/]+` (tout sauf `/`). On précise un autre
motif avec `{nom:regex}` :

```php
// /user/42 → OK, /user/xyz → non
$r->get('/user/{id:\d+}', 'handler');

// /user/foobar → OK, /user/foo/bar → non
$r->get('/user/{name}', 'handler');

// /user/foo/bar → OK
$r->get('/user/{name:.+}', 'handler');
```

Un motif ne peut pas contenir de groupe capturant : `{lang:(en|de)}` est refusé, utilisez
`{lang:en|de}` ou `{lang:(?:en|de)}`.

Les parties entre `[...]` sont **optionnelles**, et peuvent s'imbriquer, mais uniquement en fin
de route :

```php
// /user/foobar et /user/foobar/42
$r->get('/user/{name}[/{id:\d+}]', 'handler');
```

Le *handler* peut être n'importe quelle donnée : un nom de contrôleur
(`'App\Controller\UserController::show'`), un tableau, une closure… Le routeur indique seulement
quel *handler* correspond ; c'est à votre application de l'exécuter.

> Avec un cache (activé par défaut par `recommendedSettings()`), les *handlers* doivent être
> **exportables** : chaînes, tableaux, scalaires. Une closure ou un objet lève
> `CannotCacheRoutes` ; ils ne fonctionnent qu'avec `->disableCache()`.

### Groupes

Toutes les routes d'un groupe partagent un préfixe ; les groupes peuvent s'imbriquer :

```php
$r->addGroup('/admin', static function (ConfigureRoutes $r): void {
    $r->get('/do-something', 'handler');       // /admin/do-something
    $r->get('/do-another-thing', 'handler');   // /admin/do-another-thing
});
```

### Routes nommées et génération d'URL

Une route nommée (paramètre `ConfigureRoutes::ROUTE_NAME`) permet de construire son URL plutôt
que de l'écrire en dur dans les vues :

```php
$r->get('/articles/{year}[/{month}]', 'archive', [ConfigureRoutes::ROUTE_NAME => 'blog.archive']);

$uris = $router->uriGenerator();

echo $uris->forRoute('blog.archive', ['year' => '2026']);                   // /articles/2026
echo $uris->forRoute('blog.archive', ['year' => '2026', 'month' => '11']);  // /articles/2026/11
```

Les paramètres en trop deviennent une query string avec `->asUri($psr7Uri)`. Une exception
`UriCouldNotBeGenerated` signale une route inconnue, un paramètre manquant ou une valeur qui ne
respecte pas le motif.

### Lister les routes (`routes:list`)

`declaredRoutes()` renvoie les routes déclarées, dans l'ordre de déclaration, sous forme d'objets
`DeclaredRoute` : méthode HTTP, motif d'origine (préfixe de groupe inclus), handler, nom et
paramètres supplémentaires. De quoi écrire une commande `routes:list` :

```php
foreach ($router->declaredRoutes() as $route) {
    printf(
        "%-8s %-35s %-40s %s\n",
        $route->isFallback() ? 'ANY' : $route->method,
        $route->pattern,
        $route->describeHandler(),   // texte affichable, même pour une closure ou un tableau
        $route->name ?? '-',
    );
}
```

```text
GET      /                                   Controller\HomeController                 home
GET      /articles/{year}[/{month}]          archive                                  blog.archive
POST     /contact                            Controller\ContactController::send        -
```

Avec un `RouteCollector` construit à la main (comme le fait edu-framework), la même liste est
donnée par `$collector->declaredRoutes()`.

À savoir :

- une ligne **par méthode** : `addRoute(['GET', 'POST'], …)` donne deux lignes ;
- `'*'` désigne une route de repli (`any()`) ; la réponse implicite à HEAD des routes GET n'est
  pas listée ;
- les segments optionnels sont affichés tels que déclarés (`[/{month}]`) ;
- pour trier : `usort($routes, fn ($a, $b) => strcmp($a->pattern, $b->pattern))` ;
- `$router->declaredRoutes()` rejoue la fonction de définition des routes à chaque appel, sans lire
  ni écrire le cache : la liste reflète le code actuel. C'est un outil de développement, pas
  une méthode à appeler à chaque requête.

### Routes par attributs

Plutôt que de lister les routes dans une closure, on peut les déclarer directement sur les
méthodes des contrôleurs avec l'attribut `#[Route]` (répétable), et un préfixe commun avec
`#[RouteGroup]` :

```php
use Studoo\EduRoute\Attribute\Route;
use Studoo\EduRoute\Attribute\RouteGroup;
use Studoo\EduRoute\HttpMethod;

#[RouteGroup('/admin/product', namePrefix: 'admin.product.')]
final class ProductController
{
    #[Route('', name: 'index')]                                   // GET /admin/product
    public function index(): string { /* ... */ }

    #[Route('/{id:\d+}', methods: [HttpMethod::Put, 'PATCH'], name: 'update')]
    #[Route('/{id:\d+}/edit', methods: HttpMethod::Post)]
    public function update(): string { /* ... */ }
}
```

Les routes sont enregistrées par `AttributeRouteLoader`, dans l'ordre des contrôleurs puis des
méthodes :

```php
use Studoo\EduRoute\Attribute\AttributeRouteLoader;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        AttributeRouteLoader::register($r, HomeController::class, ProductController::class);
    },
    __DIR__ . '/var/cache/routes.php',
);
```

Le *handler* de chaque route est la chaîne `'App\Controller\ProductController::update'` : elle
reste compatible avec le cache.

Règles, vérifiées à l'enregistrement (`BadRouteException` avec le contrôleur et la méthode en cause) :

- un contrôleur est une classe instanciable (ni abstraite, ni interface, ni enum) ;
- une méthode routée est publique, non statique, non magique (sauf `__invoke`) et déclarée dans
  le contrôleur lui-même ou dans un de ses traits ; les méthodes héritées d'une classe parente
  sont refusées (enregistrez une route sur une méthode redéclarée) ;
- `#[RouteGroup]` ne s'applique qu'à la classe qui le porte : il n'est pas hérité ;
- ordre d'enregistrement : les méthodes propres du contrôleur dans l'ordre du fichier, puis celles
  de ses traits.

Résultat du dispatch
--------------------

`dispatch(HttpMethod|string $method, string $uri)` renvoie l'un de ces trois objets **immuables** :

| Objet | Signification | Données |
|---|---|---|
| `Matched` | une route correspond | `handler`, `variables` (`['id' => '42']`), `extraParameters` |
| `MethodNotAllowed` | l'URI existe, pas pour cette méthode (réponse 405) | `allowedMethods`, à renvoyer dans l'en-tête `Allow` |
| `NotMatched` | aucune route (réponse 404) | — |

> L'accès historique sous forme de tableau (`$result[0]`, `[1]`, `[2]`, comparé aux constantes
> `Dispatcher::FOUND`…) fonctionne encore mais est **déprécié** : préférez `instanceof`.

Middleware PSR-15
-----------------

Dans une application PSR-7/PSR-15 (installez `psr/http-server-middleware` et
`psr/http-factory`), `RoutingMiddleware` fait le routage :

```php
use Studoo\EduRoute\Http\RoutingMiddleware;

$middleware = new RoutingMiddleware($router->dispatcher(), $psr17ResponseFactory);
```

- route trouvée : la requête reçoit l'attribut `RoutingMiddleware::ROUTE_ATTRIBUTE` (l'objet
  `Matched`, donc le *handler*) et un attribut par variable (`$request->getAttribute('id')`),
  puis passe au middleware suivant, qui exécute le contrôleur ;
- méthode non autorisée : réponse **405** avec l'en-tête `Allow` (qui inclut `HEAD` dès que `GET`
  est autorisé, `HEAD` étant servi par les routes `GET`) ;
- aucune route : réponse **404**.

Cache
-----

Analyser les routes et construire les expressions régulières a un coût. Avec
`recommendedSettings($routes, $cacheKey)`, le résultat est mis en cache dans le fichier
`$cacheKey` (`FileCache`, mis en cache par OPcache).

Le cache n'est jamais invalidé automatiquement : **supprimez le fichier quand vos routes changent
ou quand vous changez de stratégie de dispatch** (le format des données dépend de la stratégie),
ou utilisez une clé différente par stratégie.

```php
// Développement : pas de cache
$router = EduRoute::recommendedSettings($routes, 'unused')->disableCache();

// Autre stockage : tout cache PSR-16 (Redis, APCu…)
use Studoo\EduRoute\Cache\Psr16Cache;

$router = EduRoute::recommendedSettings($routes, 'routes')
    ->withCache(new Psr16Cache($psr16Cache), 'routes');
```

### Chargement paresseux

`lazyDispatcher()` et `lazyUriGenerator()` renvoient des objets paresseux (*lazy objects* de
PHP 8.4) : les routes ne sont lues dans le cache, ou calculées, qu'au premier appel. Pratique
quand une partie des requêtes ne route rien (commande CLI, réponse servie par un middleware en
amont) :

```php
$dispatcher = $router->lazyDispatcher(); // rien n'est chargé ici
$result = $dispatcher->dispatch($method, $uri); // les routes sont chargées ici, une seule fois
```

Une fois initialisé, l'objet est un dispatcher ordinaire, sans surcoût. Seule limite : un
dispatcher maison qui hérite d'une classe interne de PHP ne peut pas être paresseux ; il est
alors construit immédiatement.

Choisir la stratégie de dispatch
--------------------------------

Quatre stratégies sont fournies ; elles donnent le même résultat mais combinent différemment les
expressions régulières. Les comparer est un bon exercice (`make phpbench`).

| Méthode | Stratégie |
|---|---|
| `useMarkDispatcher()` *(par défaut)* | verbe PCRE `(*MARK)` : la plus rapide |
| `useGroupCountDispatcher()` | nombre de groupes capturants |
| `useGroupPosDispatcher()` | position du dernier groupe capturant utilisé |
| `useCharCountDispatcher()` | suffixe de longueur variable |

`useCustomDispatcher()` et `withUriGenerator()` permettent de brancher vos propres
implémentations des interfaces `DataGenerator`, `Dispatcher` et `GenerateUri`.

Architecture
------------

Le routage passe par trois composants :

1. **`RouteParser`** (`RouteParser\Std`) transforme `/user/{id:\d+}[/{name}]` en segments
   statiques et paramètres ;
2. **`DataGenerator`** regroupe les routes statiques dans une table de hachage et combine les
   routes variables en quelques grandes expressions régulières ;
3. **`Dispatcher`** cherche d'abord une route statique, puis teste les expressions régulières.

Contribuer
----------

```sh
make ci        # standard de code, PHPStan (niveau max), Rector, PHPUnit
make phpbench  # benchmarks
```

Voir [CHANGELOG.md](CHANGELOG.md) et, pour migrer depuis `nikic/fast-route`, [UPGRADE.md](UPGRADE.md).

Crédits et licence
------------------

Code d'origine © Nikita Popov et les contributeurs de FastRoute ; modernisation © Studoo.
Distribué sous licence BSD-3-Clause ([LICENSE](LICENSE)).

[blog_post]: http://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html
