# Définir les routes

[English](../en/routes.md)

Une **route** associe une ou plusieurs méthodes HTTP et un motif d'URL à un *handler*. Cette page
présente tout ce qui sert à déclarer des routes : `addRoute()` et ses raccourcis, les méthodes
HTTP, les paramètres d'URL, les segments optionnels, les groupes et les paramètres
supplémentaires.

## Où déclarer les routes

Les routes sont déclarées dans une closure qui reçoit un objet
[`ConfigureRoutes`](../../src/ConfigureRoutes.php). Vous la passez à
`EduRoute::recommendedSettings()` :

```php
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        // … vos autres routes
    },
    __DIR__ . '/var/cache/routes.php', // clé de cache (ici, un fichier)
);
```

La closure n'est pas appelée tout de suite : elle l'est quand le routeur a besoin des routes,
au premier appel de `dispatcher()` ou `uriGenerator()` si le cache est vide ou désactivé (et à chaque appel
de `declaredRoutes()`, voir [Lister les routes](listing-routes.md)). Une route mal écrite lève
donc une exception à ce moment-là, pas à la déclaration de la closure.

> **Note**
> Dans les exemples de cette page, `->disableCache()` désactive le cache pour que chaque exécution
> reparte de vos routes. En production, gardez le cache (voir [Cache](cache.md)).

## `addRoute()`

Toutes les routes passent par cette méthode :

```php
public function addRoute(
    HttpMethod|string|array $httpMethod,
    string $route,
    mixed $handler,
    array $extraParameters = [],
): void;
```

| Paramètre | Rôle |
|---|---|
| `$httpMethod` | la ou les méthodes HTTP : `HttpMethod::Get`, `'GET'` ou un tableau (voir [Méthodes HTTP](#méthodes-http)) |
| `$route` | le motif d'URL, par exemple `/user/{id:\d+}` (voir [Motifs d'URL et paramètres](#motifs-durl-et-paramètres)) |
| `$handler` | la donnée renvoyée quand la route correspond (voir [Le handler](#le-handler)) |
| `$extraParameters` | des données associées à la route, dont son nom (voir [Paramètres supplémentaires](#paramètres-supplémentaires)) |

## Méthodes HTTP

`$httpMethod` accepte :

- un cas de l'énumération [`HttpMethod`](../../src/HttpMethod.php) : `Get`, `Post`, `Put`,
  `Patch`, `Delete`, `Head`, `Options`, et `Any` (`'*'`, la route de repli) ;
- une chaîne : `'GET'`, `'POST'`… y compris une méthode non standard comme `'PURGE'` ;
- un tableau qui mélange les deux, pour déclarer la même route pour plusieurs méthodes.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->addRoute(HttpMethod::Get, '/articles', 'article.list');
        $r->addRoute('POST', '/articles', 'article.create');
        $r->addRoute([HttpMethod::Put, 'PATCH'], '/articles/{id:\d+}', 'article.update');
        $r->addRoute('PURGE', '/cache', 'cache.purge'); // méthode non standard
    },
    'unused',
)->disableCache()->dispatcher();

foreach ([['GET', '/articles'], ['PATCH', '/articles/3'], ['PURGE', '/cache'], ['get', '/articles']] as [$method, $uri]) {
    $result = $dispatcher->dispatch($method, $uri);

    echo str_pad("$method $uri", 18), match (true) {
        $result instanceof Matched => $result->handler,
        $result instanceof MethodNotAllowed => '405, autorisées : ' . implode(', ', $result->allowedMethods),
        default => '404',
    }, "\n";
}
```

Sortie :

```text
GET /articles     article.list
PATCH /articles/3 article.update
PURGE /cache      cache.purge
get /articles     405, autorisées : GET, POST
```

> **Attention**
> Les méthodes sont comparées **en respectant la casse** : `'get'` ne correspond pas à une route
> `GET`. Écrivez les méthodes en majuscules, ou utilisez l'énumération, qui évite aussi les
> fautes de frappe.

Un élément de tableau qui n'est ni un `HttpMethod` ni une chaîne lève une `TypeError` :
`HTTP method must be Studoo\EduRoute\HttpMethod or string, int given`.

### Raccourcis

Chaque méthode courante a son raccourci. Ils prennent les mêmes arguments qu'`addRoute()`, sans
le premier :

| Raccourci | Équivalent |
|---|---|
| `$r->get($route, $handler)` | `$r->addRoute(HttpMethod::Get, $route, $handler)` |
| `$r->post(…)` | `HttpMethod::Post` |
| `$r->put(…)` | `HttpMethod::Put` |
| `$r->patch(…)` | `HttpMethod::Patch` |
| `$r->delete(…)` | `HttpMethod::Delete` |
| `$r->head(…)` | `HttpMethod::Head` |
| `$r->options(…)` | `HttpMethod::Options` |
| `$r->any(…)` | `HttpMethod::Any`, soit `'*'` |

`any()` déclare une route **de repli** : elle sert pour toute méthode qui n'a pas de route
dédiée sur cette URL. Par ailleurs, une requête `HEAD` sans route `HEAD` retombe sur la route
`GET` :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/page', 'page.show');
        $r->any('/page', 'page.fallback'); // toute autre méthode
    },
    'unused',
)->disableCache()->dispatcher();

echo $dispatcher->dispatch('GET', '/page')->handler, "\n";    // page.show
echo $dispatcher->dispatch('HEAD', '/page')->handler, "\n";   // page.show (HEAD retombe sur GET)
echo $dispatcher->dispatch('DELETE', '/page')->handler, "\n"; // page.fallback
```

L'ordre exact de ces replis est détaillé dans [Trouver la route d'une requête](dispatching.md).

## Motifs d'URL et paramètres

Un motif est une URL dans laquelle certaines parties sont des **paramètres** entre accolades :

| Syntaxe | Accepte | Exemple |
|---|---|---|
| `{name}` | tout sauf `/` (expression `[^/]+`) | `/user/{name}` : `/user/alice` |
| `{name:regex}` | ce que décrit l'expression régulière | `/user/{id:\d+}` : `/user/42` |

Le reste du motif est du texte **littéral** : dans `/feed.json`, le point est un vrai point, pas
« n'importe quel caractère ».

Quelques règles :

- le nom d'un paramètre commence par une lettre ou `_`, puis contient lettres, chiffres, `_` ou
  `-` ; les espaces autour du nom et de l'expression sont ignorés (`{ id : \d+ }` vaut
  `{id:\d+}`) ;
- un même nom ne peut pas apparaître deux fois dans un motif ;
- l'expression peut contenir des accolades (`{year:\d{4}}`) et une alternative (`{lang:en|fr}`) ;
- l'expression ne doit **pas** contenir de groupe capturant : `{lang:(en|fr)}` est refusé,
  écrivez `{lang:en|fr}` ou `{lang:(?:en|fr)}` ;
- l'URL entière doit correspondre au motif : `/user/42/`, avec une barre finale, ne correspond
  pas à `/user/{id:\d+}` ;
- les valeurs extraites sont toujours des **chaînes** (`'42'`, pas `42`).

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/user/{id:\d+}', 'user.byId');          // chiffres uniquement
        $r->get('/user/{name}', 'user.byName');          // motif par défaut : [^/]+
        $r->get('/files/{path:.+}', 'file.show');        // .+ accepte aussi les /
        $r->get('/archive/{year:\d{4}}', 'archive');     // les accolades sont permises dans le motif
        $r->get('/{lang:en|fr}/about', 'about');         // alternative sans groupe capturant
    },
    'unused',
)->disableCache()->dispatcher();

$uris = ['/user/42', '/user/alice', '/user/alice/posts', '/files/docs/a.pdf',
         '/archive/2026', '/archive/26', '/fr/about', '/de/about', '/user/42/'];

foreach ($uris as $uri) {
    $result = $dispatcher->dispatch('GET', $uri);

    echo str_pad($uri, 20), $result instanceof Matched
        ? $result->handler . ' ' . json_encode($result->variables, JSON_UNESCAPED_SLASHES)
        : '404', "\n";
}
```

Sortie :

```text
/user/42            user.byId {"id":"42"}
/user/alice         user.byName {"name":"alice"}
/user/alice/posts   404
/files/docs/a.pdf   file.show {"path":"docs/a.pdf"}
/archive/2026       archive {"year":"2026"}
/archive/26         404
/fr/about           about {"lang":"fr"}
/de/about           404
/user/42/           404
```

`/user/42` correspond aussi à `/user/{name}`, mais la route `{id:\d+}` est déclarée avant : elle
gagne (voir [Ordre et conflits](#ordre-et-conflits)).

## Segments optionnels

Une partie entre crochets `[...]` est **optionnelle**. Les crochets peuvent s'imbriquer, mais
seulement **en fin de motif** :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        // /blog, /blog/2026 et /blog/2026/11
        $r->get('/blog[/{year:\d{4}}[/{month:\d{2}}]]', 'blog.archive');
    },
    'unused',
)->disableCache()->dispatcher();

foreach (['/blog', '/blog/2026', '/blog/2026/11'] as $uri) {
    echo str_pad($uri, 15), json_encode($dispatcher->dispatch('GET', $uri)->variables), "\n";
}
```

Sortie :

```text
/blog          []
/blog/2026     {"year":"2026"}
/blog/2026/11  {"year":"2026","month":"11"}
```

Un paramètre absent de l'URL est absent de `variables` : prévoyez une valeur par défaut, par
exemple `$result->variables['month'] ?? null`.

En interne, ce motif est découpé en trois routes : `/blog`, `/blog/{year}` et
`/blog/{year}/{month}`. Les erreurs de syntaxe sont refusées :

| Motif | Problème |
|---|---|
| `/user[/{id}]/edit` | partie optionnelle au milieu du motif |
| `/user[/{id}` | crochet non fermé |
| `/user[]` | partie optionnelle vide |

## Groupes

`addGroup()` ajoute un **préfixe** commun à toutes les routes déclarées dans sa closure. Les
groupes peuvent s'imbriquer ; les préfixes s'additionnent :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            $r->get('', 'admin.dashboard');                    // /admin
            $r->addGroup('/users', static function (ConfigureRoutes $r): void {
                $r->get('', 'admin.users.list');               // /admin/users
                $r->get('/{id:\d+}', 'admin.users.show');      // /admin/users/{id:\d+}
            });
        });
        $r->get('/users', 'users.list');                       // hors groupe : pas de préfixe
    },
    'unused',
)->disableCache()->dispatcher();

foreach (['/admin', '/admin/users', '/admin/users/7', '/users'] as $uri) {
    echo str_pad($uri, 16), $dispatcher->dispatch('GET', $uri)->handler, "\n";
}
```

Sortie :

```text
/admin          admin.dashboard
/admin/users    admin.users.list
/admin/users/7  admin.users.show
/users          users.list
```

> **Attention**
> Le préfixe est collé tel quel devant le motif, sans ajouter de `/`. Avec
> `addGroup('/admin', …)`, écrivez `'/users'` et non `'users'` (qui donnerait `/adminusers`).

Pour préfixer aussi les **noms** de routes, utilisez les attributs et `#[RouteGroup]` avec
`namePrefix` (voir [Routes par attributs](attributes.md)).

## Paramètres supplémentaires

Le quatrième argument, `$extraParameters`, attache des données à la route : un tableau
`array<string, string|int|bool|float>`. Le dispatcher les renvoie dans
`Matched::$extraParameters`, ce qui permet par exemple à un middleware de vérifier un rôle.
Deux clés sont réservées :

| Clé | Constante | Rôle |
|---|---|---|
| `'_name'` | `ConfigureRoutes::ROUTE_NAME` | le nom de la route, pour [générer son URL](uri-generation.md) ; chaîne non vide, unique |
| `'_route'` | `ConfigureRoutes::ROUTE_REGEX` | le motif d'origine, préfixe de groupe inclus ; **toujours** ajouté par le routeur, une valeur fournie est ignorée |

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            $r->get('/users/{id:\d+}', 'admin.users.show', [
                ConfigureRoutes::ROUTE_NAME => 'admin.user', // nom de la route ('_name')
                'auth' => true,                               // vos propres données
                'role' => 'ROLE_ADMIN',
            ]);
        });
    },
    'unused',
)->disableCache()->dispatcher();

$result = $dispatcher->dispatch('GET', '/admin/users/7');

var_export($result->extraParameters);
echo "\n";
```

Sortie :

```text
array (
  '_route' => '/admin/users/{id:\\d+}',
  '_name' => 'admin.user',
  'auth' => true,
  'role' => 'ROLE_ADMIN',
)
```

Un nom invalide (`''`, un entier…) ou déjà utilisé lève une `BadRouteException`, avant que la
route ne soit enregistrée.

## Le handler

Le *handler* peut être n'importe quelle donnée : un nom de contrôleur
(`'App\Controller\UserController::show'`), un tableau (`[UserController::class, 'show']`), une
closure… Le routeur ne l'exécute jamais : il le renvoie tel quel dans `Matched::$handler`.

> **Attention**
> Avec le cache fichier (actif par défaut avec `recommendedSettings()`), les handlers et les
> paramètres supplémentaires doivent être **exportables** avec `var_export()` : chaînes, nombres,
> booléens, tableaux de ces valeurs. Une closure ou un objet lève `CannotCacheRoutes` ; utilisez
> alors `->disableCache()`. Détails dans [Cache](cache.md).

## Ordre et conflits

Le routeur cherche d'abord parmi les routes **statiques** (sans paramètre), puis parmi les
routes **variables** dans leur **ordre de déclaration** : la première qui correspond gagne.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users/me', 'user.me');               // route statique
        $r->get('/users/{name}', 'user.byName');       // déclarée en premier...
        $r->get('/users/{id:\d+}', 'user.byId');       // ...elle passe avant celle-ci
    },
    'unused',
)->disableCache()->dispatcher();

foreach (['/users/me', '/users/alice', '/users/42'] as $uri) {
    echo str_pad($uri, 14), $dispatcher->dispatch('GET', $uri)->handler, "\n";
}
```

Sortie :

```text
/users/me     user.me
/users/alice  user.byName
/users/42     user.byName
```

`user.byId` n'est jamais atteinte : déclarez les routes les plus précises **en premier**.

Deux situations sont refusées, pour une même méthode HTTP :

- deux routes identiques, y compris `/users/{a}` et `/users/{b}`, qui ont la même expression ;
- une route statique déclarée **après** une route variable qui la capture déjà (elle ne serait
  jamais atteinte). Dans l'autre ordre, c'est accepté, comme `/users/me` ci-dessus.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$tryRoutes = static function (Closure $routes): void {
    try {
        EduRoute::recommendedSettings($routes, 'unused')->disableCache()->dispatcher();
        echo "OK\n";
    } catch (BadRouteException $e) {
        echo $e->getMessage(), "\n";
    }
};

// Deux fois la même route
$tryRoutes(static function (ConfigureRoutes $r): void {
    $r->get('/users', 'a');
    $r->get('/users', 'b');
});

// Route statique déclarée APRÈS une route variable qui la capture
$tryRoutes(static function (ConfigureRoutes $r): void {
    $r->get('/users/{name}', 'user.show');
    $r->get('/users/me', 'user.me');
});

// Même chose dans l'autre ordre : accepté
$tryRoutes(static function (ConfigureRoutes $r): void {
    $r->get('/users/me', 'user.me');
    $r->get('/users/{name}', 'user.show');
});
```

Sortie :

```text
Cannot register two routes matching "/users" for method "GET"
Static route "/users/me" is shadowed by previously defined variable route "/users/([^/]+)" for method "GET"
OK
```

## Erreurs de déclaration

Une route invalide lève une [`BadRouteException`](../../src/BadRouteException.php) (messages en
anglais) :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$patterns = [
    '/lang/{lang:(en|fr)}',      // groupe capturant
    '/lang/{lang:(?:en|fr)}',    // groupe non capturant : accepté
    '/copy/{id}/to/{id}',        // même paramètre deux fois
    '/user[/{id}]/edit',         // segment optionnel au milieu
    '/user[/{id}',               // crochet non fermé
    '/user[]',                   // partie optionnelle vide
];

foreach ($patterns as $pattern) {
    try {
        EduRoute::recommendedSettings(
            static fn (ConfigureRoutes $r) => $r->get($pattern, 'handler'),
            'unused',
        )->disableCache()->dispatcher();
        echo str_pad($pattern, 26), "OK\n";
    } catch (BadRouteException $e) {
        echo str_pad($pattern, 26), $e->getMessage(), "\n";
    }
}
```

Sortie :

```text
/lang/{lang:(en|fr)}      Regex "(en|fr)" for parameter "lang" contains a capturing group
/lang/{lang:(?:en|fr)}    OK
/copy/{id}/to/{id}        Cannot use the same placeholder "id" twice
/user[/{id}]/edit         Optional segments can only occur at the end of a route
/user[/{id}               Number of opening '[' and closing ']' does not match
/user[]                   Empty optional part
```

| Message | Cause |
|---|---|
| `Cannot register two routes matching "…" for method "…"` | route déclarée deux fois |
| `Static route "…" is shadowed by previously defined variable route "…" for method "…"` | route statique masquée par une route variable déclarée avant |
| `Cannot use the same placeholder "…" twice` | paramètre répété dans le motif |
| `Regex "…" for parameter "…" contains a capturing group` | groupe capturant `(…)` dans l'expression |
| `Optional segments can only occur at the end of a route` | `[...]` au milieu du motif |
| `Number of opening '[' and closing ']' does not match` | crochets déséquilibrés |
| `Empty optional part` | `[]` vide |
| `Cannot register two routes under the name "…"` | nom de route déjà utilisé |
| `Route name must be a non-empty string, "…" given` | nom de route vide ou qui n'est pas une chaîne |

Chaque message et sa correction sont détaillés dans [Erreurs](errors.md).

## Sans `EduRoute` : le `RouteCollector`

`EduRoute` assemble pour vous un analyseur de motifs, un générateur de données et un dispatcher.
Vous pouvez aussi utiliser directement le [`RouteCollector`](../../src/RouteCollector.php), qui
implémente `ConfigureRoutes` (mêmes méthodes que ci-dessus), sans cache :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\RouteCollector;
use Studoo\EduRoute\RouteParser\Std;

$collector = new RouteCollector(new Std(), new DataGenerator\MarkBased());

$collector->get('/users/{id:\d+}', 'user.show');
$collector->addGroup('/admin', static function (ConfigureRoutes $r): void {
    $r->post('/users', 'admin.users.create');
});

// Le générateur et le dispatcher doivent utiliser la même stratégie (ici Mark)
$dispatcher = new Dispatcher\MarkBased($collector->processedRoutes());

echo $dispatcher->dispatch('GET', '/users/5')->handler, "\n";      // user.show
echo $dispatcher->dispatch('POST', '/admin/users')->handler, "\n"; // admin.users.create
```

Ici, les erreurs sont levées dès l'appel à `get()` ou `addGroup()`. Les stratégies sont
présentées dans [Comment ça marche](how-it-works.md).

---

← [Page précédente](index.md) · [Sommaire](index.md) · [Page suivante](dispatching.md) →
