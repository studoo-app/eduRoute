# Trouver la route d'une requête

[English](../en/dispatching.md)

Une fois les routes déclarées (voir [Définir les routes](routes.md)), le **dispatcher** répond à
une seule question : quelle route correspond à cette méthode HTTP et à cette URI ? Il ne
l'exécute pas. Il vous renvoie un objet qui décrit ce qu'il a trouvé, et c'est à votre application
d'appeler le contrôleur, ou de répondre 404 ou 405.

## Obtenir un dispatcher

```php
$dispatcher = $router->dispatcher();     // les routes sont chargées tout de suite
$dispatcher = $router->lazyDispatcher(); // les routes sont chargées au premier dispatch()
```

`$router` est l'objet construit par `EduRoute::recommendedSettings()`. Les deux méthodes
renvoient un [`Dispatcher`](../../src/Dispatcher.php) ; la version paresseuse est décrite dans
[Cache](cache.md). Si vous construisez le `RouteCollector` vous-même, créez le dispatcher de la même
famille que le générateur de données :

```php
$dispatcher = new \Studoo\EduRoute\Dispatcher\MarkBased($collector->processedRoutes());
```

## Appeler `dispatch()`

```php
public function dispatch(HttpMethod|string $httpMethod, string $uri): Matched|NotMatched|MethodNotAllowed;
```

- `$httpMethod` : un cas de l'énumération `HttpMethod` (`HttpMethod::Get`) ou une chaîne
  (`'GET'`, `'PURGE'`…). La comparaison est **sensible à la casse** : `'get'` ne trouve pas une
  route déclarée en `GET`. `$_SERVER['REQUEST_METHOD']` est déjà en majuscules.
- `$uri` : le **chemin** de l'URL, sans la query string, déjà décodé (voir
  [Préparer l'URI](#préparer-luri)).

Le résultat est l'un de ces trois objets, tous dans le namespace
`Studoo\EduRoute\Dispatcher\Result` :

| Objet | Signification | Propriétés |
|---|---|---|
| [`Matched`](../../src/Dispatcher/Result/Matched.php) | une route correspond | `handler`, `variables`, `extraParameters` |
| [`MethodNotAllowed`](../../src/Dispatcher/Result/MethodNotAllowed.php) | l'URI existe, mais pas pour cette méthode : réponse **405** | `allowedMethods` |
| [`NotMatched`](../../src/Dispatcher/Result/NotMatched.php) | aucune route : réponse **404** | aucune |

Testez le résultat avec `instanceof`, par exemple dans un `match (true)` :

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        $r->post('/users', 'create_user');
        $r->get('/users/{id:\d+}', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
    },
    'unused',
)->disableCache()->dispatcher();

$requests = [
    ['GET', '/users/42'],
    ['DELETE', '/users'],
    ['GET', '/contact'],
];

foreach ($requests as [$method, $uri]) {
    $result = $dispatcher->dispatch($method, $uri);

    echo "$method $uri → ", match (true) {
        $result instanceof Matched => sprintf(
            '%s %s %s',
            $result->handler,
            json_encode($result->variables),
            json_encode($result->extraParameters, JSON_UNESCAPED_SLASHES),
        ),
        $result instanceof MethodNotAllowed => '405, Allow: ' . implode(', ', $result->allowedMethods),
        $result instanceof NotMatched => '404',
    }, "\n";
}
```

```text
GET /users/42 → show_user {"id":"42"} {"_route":"/users/{id:\\d+}","_name":"user.show"}
DELETE /users → 405, Allow: GET, POST
GET /contact → 404
```

### `Matched`

| Propriété | Type | Contenu |
|---|---|---|
| `handler` | `mixed` | le *handler* déclaré avec la route, tel quel |
| `variables` | `array<string, string>` | les paramètres de l'URL : `/users/{id:\d+}` sur `/users/42` donne `['id' => '42']` |
| `extraParameters` | `array<string, string\|int\|bool\|float>` | les paramètres supplémentaires de la route (voir [Définir les routes](routes.md)) |

Les valeurs de `variables` sont **toujours des chaînes** : `'42'`, pas `42`. Convertissez-les
vous-même (`(int) $result->variables['id']`). Un segment optionnel absent de l'URL n'apparaît pas
dans `variables` : testez-le avec `isset()` ou `??`.

`extraParameters` contient toujours la clé `'_route'` (constante `ConfigureRoutes::ROUTE_REGEX`) :
le motif d'origine de la route, préfixe de groupe compris. Une route nommée y ajoute `'_name'`
(`ConfigureRoutes::ROUTE_NAME`). Ces deux clés sont réservées.

### `MethodNotAllowed`

`allowedMethods` est une liste non vide des méthodes qui ont une route pour cette URI. Renvoyez-la
dans l'en-tête `Allow` de la réponse 405 (le [middleware PSR-15](middleware.md) le fait pour vous).
Voir [Calcul des méthodes autorisées](#calcul-des-méthodes-autorisées) pour ses particularités.

### `NotMatched`

Aucune propriété : aucune route ne correspond à l'URI, quelle que soit la méthode.

### Des résultats immuables

Les trois classes sont `readonly`. Une fois le résultat créé par le dispatcher, toute modification
est refusée :

```php
$result->handler = 'autre';  // Error: Cannot modify readonly property Studoo\EduRoute\Dispatcher\Result\Matched::$handler
$result[0] = 'autre';        // RuntimeException: Result cannot be changed
```

## Préparer l'URI

Le dispatcher compare l'URI **exactement** au motif des routes. Il ne fait aucun nettoyage : c'est
à vous de lui passer le chemin seul, décodé.

1. **Retirez la query string** : `/users?page=2` ne correspond pas à la route `/users` (résultat
   `NotMatched`). Les paramètres de la query string restent disponibles dans `$_GET`.
2. **Décodez l'URI** avec `rawurldecode()` : sinon `/hello/Jean%20Dupont` donne la variable
   `'Jean%20Dupont'` au lieu de `'Jean Dupont'`.
3. **Remplacez un chemin vide par `/`** : `dispatch('GET', '')` ne trouve jamais la route `/`.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/hello/{name}', 'hello');
    },
    'unused',
)->disableCache()->dispatcher();

// Ce que PHP fournit pour la requête GET /hello/Jean%20Dupont?lang=fr
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/hello/Jean%20Dupont?lang=fr';

// 1. Retirer la query string ; 2. décoder les caractères encodés (%20…)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode(is_string($path) && $path !== '' ? $path : '/');

$result = $dispatcher->dispatch($_SERVER['REQUEST_METHOD'], $uri);

var_dump($result->variables['name']); // la query string reste lisible dans $_GET
```

```text
string(11) "Jean Dupont"
```

> **Note**
> La barre oblique finale compte : `/users/` ne correspond pas à la route `/users`. Choisissez une
> convention et, si besoin, redirigez l'autre forme dans votre application.

## Ordre de recherche

Pour une méthode et une URI, le dispatcher essaie dans cet ordre, et s'arrête au premier succès :

1. les routes **statiques** (sans paramètre) de la méthode demandée : une simple recherche dans un
   tableau ;
2. les routes **variables** de la méthode demandée, **dans l'ordre de déclaration** : la première
   qui correspond l'emporte ;
3. pour une requête `HEAD` uniquement : les routes statiques puis variables de `GET` ;
4. les routes **de repli** `'*'` (déclarées avec `any()` ou `HttpMethod::Any`), statiques puis
   variables ;
5. si rien ne correspond : les autres méthodes sont testées pour construire `MethodNotAllowed` ;
   s'il n'y en a aucune, le résultat est `NotMatched`.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/articles/new', 'new_article_form');
        $r->get('/articles/{id:\d+}', 'show_article_by_id');
        $r->get('/articles/{slug}', 'show_article');
        $r->any('/maintenance', 'maintenance_page');
        $r->any('/{page}', 'cms_page');
    },
    'unused',
)->disableCache()->dispatcher();

$requests = [
    [HttpMethod::Get, '/articles/new'],
    [HttpMethod::Get, '/articles/42'],
    [HttpMethod::Head, '/articles/hello'],
    [HttpMethod::Post, '/maintenance'],
    [HttpMethod::Delete, '/about'],
    ['get', '/articles/hello'],
];

foreach ($requests as [$method, $uri]) {
    $result = $dispatcher->dispatch($method, $uri);
    $label = $method instanceof HttpMethod ? $method->value : $method;

    echo str_pad("$label $uri", 22), '→ ',
        $result instanceof Matched ? $result->handler : substr(strrchr($result::class, '\\'), 1), "\n";
}
```

```text
GET /articles/new     → new_article_form
GET /articles/42      → show_article_by_id
HEAD /articles/hello  → show_article
POST /maintenance     → maintenance_page
DELETE /about         → cms_page
get /articles/hello   → MethodNotAllowed
```

Ce qu'il faut retenir :

- `/articles/42` correspond aux deux routes variables ; `{id:\d+}` gagne parce qu'elle est
  déclarée **avant** `{slug}`. Déclarez les motifs les plus précis en premier.
- La route statique `/articles/new` doit être déclarée avant `/articles/{slug}` : dans l'ordre
  inverse, elle serait masquée et l'enregistrement lève une `BadRouteException`
  (`Static route "/articles/new" is shadowed by previously defined variable route "/articles/([^/]+)" for method "GET"`).
  Voir [Erreurs](errors.md).
- `'get'` en minuscules n'est pas `GET` : le dispatcher ne trouve que la route de la méthode
  `GET` dans l'étape 5, d'où `MethodNotAllowed`.

### Repli `HEAD` vers `GET`

Une requête `HEAD` demande les en-têtes d'une page sans son contenu. Si aucune route `HEAD` ne
correspond, le dispatcher essaie les routes `GET` (étape 3) : vous n'avez pas à déclarer `HEAD`
à côté de chaque `GET`. Une route `HEAD` déclarée explicitement reste prioritaire.

Le résultat est un `Matched` ordinaire, avec le *handler* de la route `GET`. C'est à votre
application (ou à votre serveur) de ne pas envoyer de corps de réponse pour `HEAD`.

### Routes de repli `'*'`

Une route déclarée avec `any()` (méthode `HttpMethod::Any`, soit `'*'`) correspond à **toute
méthode qui n'a pas trouvé de route dédiée**. Elle passe après les routes de la méthode demandée et
après le repli `HEAD` → `GET` : dans l'exemple, `HEAD /articles/hello` trouve la route `GET`
avant la route `'*'` `/{page}`.

Comme une route de repli correspond à toutes les méthodes, une URI couverte par une route `'*'` ne
donne jamais `MethodNotAllowed`.

### Calcul des méthodes autorisées

Quand aucune route ne correspond, le dispatcher teste l'URI avec les routes de **toutes les autres
méthodes** déclarées, statiques d'abord puis variables. Deux particularités, héritées de FastRoute :

- `allowedMethods` ne contient pas `HEAD` quand seul `GET` existe, alors que `HEAD` est servi par
  le repli. Le [middleware PSR-15](middleware.md) l'ajoute à l'en-tête `Allow` ; faites de même si
  vous écrivez la réponse 405 vous-même.
- Une méthode peut apparaître **deux fois** si l'URI correspond à la fois à une route statique et à
  une route variable de cette méthode. Dédoublonnez avant d'écrire l'en-tête :

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/files/readme', 'readme');
        $r->get('/files/{name}', 'download');
        $r->delete('/files/{name}', 'remove');
    },
    'unused',
)->disableCache()->dispatcher();

$result = $dispatcher->dispatch('POST', '/files/readme');

var_dump($result->allowedMethods);
var_dump(array_values(array_unique($result->allowedMethods)));
```

```text
array(3) {
  [0]=>
  string(3) "GET"
  [1]=>
  string(3) "GET"
  [2]=>
  string(6) "DELETE"
}
array(2) {
  [0]=>
  string(3) "GET"
  [1]=>
  string(6) "DELETE"
}
```

## Quand le moteur d'expressions régulières échoue

Les routes variables sont testées avec des expressions régulières (PCRE). Un motif de paramètre
très coûteux, comme `{p:(?:a?a?)*}`, peut épuiser la limite `pcre.backtrack_limit` ou la pile
JIT. Au lieu de répondre « aucune route » (et donc 404), `dispatch()` lève alors
[`RegexMatchingFailed`](../../src/RegexMatchingFailed.php), avec l'URI et l'erreur PCRE :

```text
Impossible de chercher la route de l'URI "/p/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaab" : l'expression régulière des routes a échoué (Backtrack limit exhausted). Un paramètre de route utilise sans doute un motif trop coûteux, par exemple {p:(?:a?a?)*} : simplifiez-le, ou augmentez pcre.backtrack_limit / pcre.jit_stacklimit.
```

La solution est presque toujours de simplifier le motif. Voir [Erreurs](errors.md).

## Accès tableau (déprécié)

Dans FastRoute, `dispatch()` renvoyait un tableau. Pour faciliter la migration, les trois objets
résultat acceptent encore la lecture par index, comparée aux constantes de `Dispatcher` :

| Résultat | `[0]` | `[1]` | `[2]` |
|---|---|---|---|
| `Matched` | `Dispatcher::FOUND` (`1`) | `handler` | `variables` |
| `MethodNotAllowed` | `Dispatcher::METHOD_NOT_ALLOWED` (`2`) | `allowedMethods` | |
| `NotMatched` | `Dispatcher::NOT_FOUND` (`0`) | | |

Un autre index lève une `OutOfBoundsException`. `extraParameters` n'est pas accessible par index.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users/{id:\d+}', 'show_user');
    },
    'unused',
)->disableCache()->dispatcher();

$routeInfo = $dispatcher->dispatch('GET', '/users/42');

// Ancien style FastRoute : fonctionne, mais chaque lecture émet une dépréciation
if ($routeInfo[0] === Dispatcher::FOUND) {
    echo $routeInfo[1], ' ', json_encode($routeInfo[2]), "\n";
}
```

Le script affiche `show_user {"id":"42"}`, et chaque lecture par index émet une dépréciation
(`E_USER_DEPRECATED`) :

```text
Deprecated: Method Studoo\EduRoute\Dispatcher\Result\Matched::offsetGet() is deprecated since 1.0, use $result instanceof Matched and its properties instead
```

> **Attention**
> L'accès tableau et les constantes `Dispatcher::FOUND`, `NOT_FOUND` et `METHOD_NOT_ALLOWED` sont
> dépréciés depuis la 1.0. Remplacez-les par `instanceof` et les propriétés : voir
> [Migration](migration.md).

---

← [Page précédente](routes.md) · [Sommaire](index.md) · [Page suivante](uri-generation.md) →
