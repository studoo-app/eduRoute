# EduRoute — documentation

[English](../en/index.md)

`studoo/edu-route` est un **routeur HTTP** pour PHP 8.4 et 8.5. Il associe une méthode HTTP
(`GET`, `POST`…) et une URL (`/user/42`) à un *handler* : un nom de contrôleur, un tableau, une
closure, n'importe quelle donnée. Le routeur vous dit **quel** handler correspond à la requête et
avec **quelles** variables (`['id' => '42']`) ; c'est ensuite à votre application de l'exécuter.

C'est la couche de routage d'[edu-framework](https://github.com/studoo-app/edu-framework), un
framework pédagogique. La bibliothèque est un fork modernisé de
[nikic/FastRoute](https://github.com/nikic/FastRoute), dont elle reprend l'algorithme à base
d'expressions régulières combinées (voir [Comment ça marche](how-it-works.md)), réécrit pour
PHP 8.4 : énumérations, objets résultat immuables, attributs `#[\Override]`, *lazy objects*,
analyse statique au niveau maximal.

## Ce que fait la bibliothèque

- déclarer des routes avec des paramètres (`/user/{id:\d+}`), des segments optionnels et des
  groupes ([Définir les routes](routes.md)) ;
- trouver la route d'une requête et distinguer « trouvée », « méthode non autorisée » (405) et
  « introuvable » (404) ([Trouver la route d'une requête](dispatching.md)) ;
- construire l'URL d'une route nommée plutôt que de l'écrire en dur ([Générer des URL](uri-generation.md)) ;
- déclarer les routes par attributs PHP sur les contrôleurs ([Routes par attributs](attributes.md)) ;
- lister les routes déclarées, par exemple pour une commande `routes:list` ([Lister les routes](listing-routes.md)) ;
- mettre les routes en cache pour ne pas les recalculer à chaque requête ([Cache](cache.md)) ;
- router dans une application PSR-7/PSR-15 ([Middleware PSR-15](middleware.md)).

## Installation

```sh
composer require studoo/edu-route
```

Prérequis : PHP 8.4 ou 8.5. Les dépendances `psr/http-message` et `psr/simple-cache` sont
installées automatiquement. Pour le [middleware PSR-15](middleware.md), installez aussi
`psr/http-server-middleware` et `psr/http-factory`.

## Utilisation rapide

Un contrôleur frontal (`public/index.php`) complet :

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;

// 1. Déclarer les routes
$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        // {id} doit être un nombre (\d+)
        $r->get('/user/{id:\d+}', 'show_user');
        // Le suffixe /{title} est optionnel
        $r->addRoute(HttpMethod::Get, '/articles/{id:\d+}[/{title}]', 'show_article');
    },
    dirname(__DIR__) . '/var/cache/routes.php', // fichier de cache, créé au premier appel
);

$dispatcher = $router->dispatcher();

// 2. Lire la méthode et l'URI de la requête : on retire la query string (?page=2), puis on décode
$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);

// 3. Chercher la route correspondante
$result = $dispatcher->dispatch($httpMethod, $uri);

// 4. Réagir selon le résultat
echo match (true) {
    $result instanceof Matched => $result->handler . ' ' . json_encode($result->variables),
    $result instanceof MethodNotAllowed => '405 – méthodes autorisées : ' . implode(', ', $result->allowedMethods),
    $result instanceof NotMatched => '404 – page introuvable',
}, "\n";
```

Selon la requête, ce script affiche :

| Requête | Affichage |
|---|---|
| `GET /user/42` | `show_user {"id":"42"}` |
| `GET /articles/7` | `show_article {"id":"7"}` |
| `GET /articles/7/hello%20world?ref=home` | `show_article {"id":"7","title":"hello world"}` |
| `POST /users` | `405 – méthodes autorisées : GET` |
| `GET /user/abc` | `404 – page introuvable` |

Dans une vraie application, la branche `Matched` appelle le contrôleur désigné par
`$result->handler` en lui passant `$result->variables`, et les branches 405 et 404 envoient le
code HTTP correspondant (`http_response_code()`, en-tête `Allow` pour le 405).

> **Note**
> `recommendedSettings()` active un cache fichier. Pendant le développement, ou si vos handlers
> sont des closures, ajoutez `->disableCache()` :
> `EduRoute::recommendedSettings($routes, 'unused')->disableCache()`. Pensez aussi à supprimer le
> fichier de cache quand vos routes changent. Tous les détails dans [Cache](cache.md).

## Vocabulaire

| Terme | Sens |
|---|---|
| **route** | une méthode HTTP + un motif d'URL + un handler |
| **motif** (*pattern*) | l'URL attendue, avec ses paramètres : `/user/{id:\d+}` |
| **handler** | la donnée associée à la route, renvoyée telle quelle quand elle correspond |
| **variables** | les valeurs extraites de l'URL : `['id' => '42']` |
| **dispatch** | l'opération qui cherche la route correspondant à une méthode et une URI |
| **dispatcher** | l'objet qui fait le dispatch (`$router->dispatcher()`) |

## Sommaire

1. [Présentation](index.md) — cette page : installation et utilisation rapide
2. [Définir les routes](routes.md) — méthodes HTTP, motifs d'URL, segments optionnels, groupes, paramètres supplémentaires
3. [Trouver la route d'une requête](dispatching.md) — `dispatch()` et ses trois résultats, préparation de l'URI, `HEAD` et routes de repli
4. [Générer des URL](uri-generation.md) — routes nommées et générateur d'URL
5. [Routes par attributs](attributes.md) — `#[Route]`, `#[RouteGroup]` et `AttributeRouteLoader`
6. [Lister les routes](listing-routes.md) — `declaredRoutes()` et une commande `routes:list`
7. [Cache et chargement paresseux](cache.md) — `FileCache`, cache PSR-16, invalidation, chargement paresseux
8. [Middleware PSR-15](middleware.md) — `RoutingMiddleware`, réponses 404 et 405
9. [Comment ça marche](how-it-works.md) — analyseur, générateur, dispatcher et les quatre stratégies
10. [Erreurs et exceptions](errors.md) — référence des exceptions et comment les corriger
11. [Migration](migration.md) — depuis `nikic/fast-route` 1.x et 2.0-dev

## Contribuer

```sh
make ci        # standard de code, PHPStan (niveau max), Rector, PHPUnit
make phpbench  # benchmarks des stratégies de dispatch
```

L'historique des versions est dans [CHANGELOG.md](../../CHANGELOG.md) (en anglais).

## Crédits et licence

Code d'origine © Nikita Popov et les contributeurs de FastRoute ; modernisation © Studoo.
Distribué sous licence BSD-3-Clause ([LICENSE](../../LICENSE)).

---

[Sommaire](index.md) · [Page suivante](routes.md) →
