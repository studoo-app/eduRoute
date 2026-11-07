# Générer des URL

[English](../en/uri-generation.md)

Le dispatcher va de l'URL vers la route. La **génération d'URL** fait le chemin inverse : à partir
du nom d'une route et de ses paramètres, elle construit le chemin. Vos gabarits et vos redirections
n'écrivent plus `/articles/2026/11` en dur : si le motif de la route change, les liens suivent.

## Nommer une route

Seules les routes **nommées** peuvent être générées. Donnez le nom avec la clé
`ConfigureRoutes::ROUTE_NAME` (soit `'_name'`) dans les paramètres supplémentaires de la route :

```php
$r->get('/users/{id:\d+}', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
```

Quelques règles, vérifiées à l'enregistrement de la route :

- le nom est une **chaîne non vide** ; sinon `BadRouteException` ;
- un nom est **unique** : deux routes ne peuvent pas porter le même nom, même pour des méthodes
  différentes. Une route déclarée pour plusieurs méthodes (`addRoute(['GET', 'POST'], …)`) n'a
  qu'un nom ;
- dans un groupe, le nom **ne reprend pas** le préfixe du groupe : choisissez-le librement (une
  convention comme `admin.user.edit` aide à s'y retrouver). Le chemin généré, lui, contient bien le
  préfixe.

Avec les [routes par attributs](attributes.md), le nom se donne avec `#[Route(..., name: '...')]`,
et `#[RouteGroup]` peut y ajouter un préfixe (`namePrefix`).

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$routeSets = [
    static function (ConfigureRoutes $r): void {
        $r->get('/login', 'login', [ConfigureRoutes::ROUTE_NAME => 'login']);
        $r->post('/login', 'check_login', [ConfigureRoutes::ROUTE_NAME => 'login']);
    },
    static function (ConfigureRoutes $r): void {
        $r->get('/login', 'login', [ConfigureRoutes::ROUTE_NAME => '']);
    },
];

foreach ($routeSets as $routes) {
    try {
        EduRoute::recommendedSettings($routes, 'unused')->disableCache()->uriGenerator();
    } catch (BadRouteException $e) {
        echo $e->getMessage(), "\n";
    }
}
```

```text
Cannot register two routes under the name "login"
Route name must be a non-empty string, "''" given
```

Le nom est aussi visible dans le résultat du dispatch : `$result->extraParameters['_name']` (voir
[Trouver la route d'une requête](dispatching.md#matched)).

## Obtenir le générateur

```php
$uris = $router->uriGenerator();     // les routes sont chargées tout de suite
$uris = $router->lazyUriGenerator(); // les routes sont chargées au premier forRoute()
```

`$router` est l'objet construit par `EduRoute::recommendedSettings()`. Les deux méthodes renvoient
un objet qui implémente l'interface [`GenerateUri`](../../src/GenerateUri.php), qui n'a qu'une
méthode :

```php
public function forRoute(string $name, array $substitutions = []): GeneratedUri;
```

- `$name` : le nom de la route ;
- `$substitutions` : un tableau `nom du paramètre => valeur`. Les valeurs sont des **chaînes**.

L'implémentation par défaut est
[`FromProcessedConfiguration`](../../src/GenerateUri/FromProcessedConfiguration.php). Elle lit les
mêmes données que le dispatcher, donc le même [cache](cache.md).

## Générer un chemin

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/', 'home', [ConfigureRoutes::ROUTE_NAME => 'home']);
        $r->get('/articles/{year:\d{4}}[/{month:\d{2}}]', 'archive', [
            ConfigureRoutes::ROUTE_NAME => 'blog.archive',
        ]);
        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            // Le nom ne reprend pas le préfixe : choisissez-le librement
            $r->addRoute(['GET', 'POST'], '/users/{id:\d+}', 'edit_user', [
                ConfigureRoutes::ROUTE_NAME => 'admin.user.edit',
            ]);
        });
    },
    'unused',
)->disableCache();

$uris = $router->uriGenerator();

echo $uris->forRoute('home'), "\n";
echo $uris->forRoute('blog.archive', ['year' => '2026']), "\n";
echo $uris->forRoute('blog.archive', ['year' => '2026', 'month' => '11']), "\n";
echo $uris->forRoute('admin.user.edit', ['id' => '42']), "\n";
```

```text
/
/articles/2026
/articles/2026/11
/admin/users/42
```

### Segments optionnels

Une route avec des segments optionnels (`[/{month:\d{2}}]`) correspond à plusieurs formes de
chemin. Le générateur les essaie de la **plus longue à la plus courte**, et retient la première
dont il a **tous** les paramètres :

- avec `year` et `month`, il produit `/articles/2026/11` ;
- avec `year` seul, la forme longue n'est pas possible (il manque `month`) : il produit
  `/articles/2026`.

L'ordre des clés dans `$substitutions` n'a aucune importance.

> **Attention**
> Le choix de la forme se fait sur la **présence** des paramètres, pas sur leur valeur. Si vous
> passez `month`, le générateur choisit la forme longue ; si la valeur de `month` est invalide, il
> lève une exception au lieu de se rabattre sur la forme courte. Ne passez pas un paramètre
> optionnel que vous ne voulez pas dans l'URL.

## Le résultat : `GeneratedUri`

`forRoute()` renvoie un objet [`GeneratedUri`](../../src/GenerateUri/GeneratedUri.php), immuable
(`readonly`) :

| Membre | Type | Contenu |
|---|---|---|
| `path` | `string` | le chemin généré, par exemple `/articles/2026` |
| `unmatchedSubstitutions` | `array<string, string>` | les valeurs passées qui ne correspondent à aucun paramètre du chemin |
| `__toString()` | `string` | le chemin seul (`path`), sans query string |
| `asUri(UriInterface $baseUri)` | `UriInterface` | une URI PSR-7 : `$baseUri` avec ce chemin et `unmatchedSubstitutions` en query string |

Les valeurs « en trop » ne sont pas une erreur : elles sont rangées dans `unmatchedSubstitutions`.
`echo` ou `(string)` les ignore ; `asUri()` les transforme en query string avec
`http_build_query()` :

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Nyholm\Psr7\Uri;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$uris = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/articles/{year:\d{4}}[/{month:\d{2}}]', 'archive', [
            ConfigureRoutes::ROUTE_NAME => 'blog.archive',
        ]);
    },
    'unused',
)->disableCache()->uriGenerator();

$generated = $uris->forRoute('blog.archive', ['year' => '2026', 'page' => '2', 'sort' => 'date']);

var_dump($generated->path);                   // la partie chemin
var_dump($generated->unmatchedSubstitutions); // les valeurs non utilisées dans le chemin
echo $generated, "\n";                        // __toString() : le chemin seul

// asUri() : chemin + query string, sur une URI PSR-7 de base
echo $generated->asUri(new Uri('https://example.com')), "\n";
```

```text
string(14) "/articles/2026"
array(2) {
  ["page"]=>
  string(1) "2"
  ["sort"]=>
  string(4) "date"
}
/articles/2026
https://example.com/articles/2026?page=2&sort=date
```

`asUri()` attend une implémentation de `Psr\Http\Message\UriInterface`. EduRoute n'en fournit pas :
utilisez celle de votre framework ou une bibliothèque comme `nyholm/psr7` (utilisée ici :
`composer require nyholm/psr7`).

> **Note**
> `asUri()` **remplace** le chemin et la query string de `$baseUri` : une query string déjà présente
> dans l'URI de base est perdue, même quand `unmatchedSubstitutions` est vide. Le schéma, l'hôte,
> le port et le fragment (`#…`) sont conservés.

## Encoder les valeurs

Le générateur recopie les valeurs **telles quelles** dans le chemin, sans les encoder. Pour un
paramètre qui peut contenir des espaces, des accents ou d'autres caractères spéciaux, encodez la
valeur avec `rawurlencode()`. C'est le pendant du `rawurldecode()` fait avant le dispatch (voir
[Préparer l'URI](dispatching.md#préparer-luri)) :

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/hello/{name}', 'hello', [ConfigureRoutes::ROUTE_NAME => 'hello']);
    },
    'unused',
)->disableCache();

$uris = $router->uriGenerator();

// La valeur est recopiée telle quelle, sans encodage
echo $uris->forRoute('hello', ['name' => 'Jean Dupont']), "\n";

// Encodez-la vous-même pour obtenir une URL valide
$uri = (string) $uris->forRoute('hello', ['name' => rawurlencode('Jean Dupont')]);
echo $uri, "\n";

// Aller-retour : le dispatcher retrouve la route après rawurldecode()
$result = $router->dispatcher()->dispatch('GET', rawurldecode($uri));
echo $result->handler, ' ', $result->variables['name'], "\n";
```

```text
/hello/Jean Dupont
/hello/Jean%20Dupont
hello Jean Dupont
```

Les valeurs doivent être des chaînes : un entier (`['id' => 7]`) provoque une `TypeError`. Écrivez
`['id' => '7']` ou `['id' => (string) $user->id]`.

## Les erreurs : `UriCouldNotBeGenerated`

Quand le chemin ne peut pas être construit, `forRoute()` lève
[`UriCouldNotBeGenerated`](../../src/GenerateUri/UriCouldNotBeGenerated.php) (une `LogicException`
qui implémente l'interface `Studoo\EduRoute\Exception`). C'est une erreur de programmation : le
code qui génère le lien est à corriger.

| Cas | Message |
|---|---|
| aucune route ne porte ce nom | `There is no route with name "<nom>" defined` |
| il manque un paramètre obligatoire | `Route "<nom>" expects at least parameter values for [<manquants>], but received <reçus>` |
| une valeur ne respecte pas le motif du paramètre | ``Route "<nom>" expects the parameter [<paramètre>] to match the regex `<motif>` `` |

Dans le deuxième message, `<reçus>` vaut `none` si vous n'avez passé aucune valeur, sinon la liste
des noms reçus entre crochets. Quand la route a des segments optionnels, les paramètres manquants
sont ceux de la forme **la plus courte**.

Le motif est vérifié en entier : `\d{4}` refuse `'26'` comme `'20261'`. Un paramètre sans motif
(`{name}`, soit `[^/]+`) refuse la chaîne vide et toute valeur qui contient `/`.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\GenerateUri\UriCouldNotBeGenerated;

$uris = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/articles/{year:\d{4}}[/{month:\d{2}}]', 'archive', [
            ConfigureRoutes::ROUTE_NAME => 'blog.archive',
        ]);
    },
    'unused',
)->disableCache()->uriGenerator();

$attempts = [
    ['blog.achive', ['year' => '2026']],                    // faute de frappe dans le nom
    ['blog.archive', []],                                   // paramètre obligatoire absent
    ['blog.archive', ['page' => '2']],                      // idem, avec un paramètre en trop
    ['blog.archive', ['year' => '26']],                     // ne respecte pas \d{4}
    ['blog.archive', ['year' => '2026', 'month' => 'nov']], // ne respecte pas \d{2}
];

foreach ($attempts as [$name, $substitutions]) {
    try {
        $uris->forRoute($name, $substitutions);
    } catch (UriCouldNotBeGenerated $e) {
        echo $e->getMessage(), "\n";
    }
}
```

```text
There is no route with name "blog.achive" defined
Route "blog.archive" expects at least parameter values for [year], but received none
Route "blog.archive" expects at least parameter values for [year], but received [page]
Route "blog.archive" expects the parameter [year] to match the regex `\d{4}`
Route "blog.archive" expects the parameter [month] to match the regex `\d{2}`
```

Voir aussi [Erreurs](errors.md#uricouldnotbegenerated).

## Chargement paresseux

`lazyUriGenerator()` renvoie un générateur dont les routes ne sont chargées (depuis le cache, ou
calculées) qu'au premier appel de `forRoute()`. Avec un cache fichier, le fichier n'est donc écrit
qu'à ce moment-là :

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$cacheFile = __DIR__ . '/routes.cache.php';
@unlink($cacheFile); // repartir d'un cache vide pour l'exemple

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users/{id:\d+}', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
    },
    $cacheFile,
);

$uris = $router->lazyUriGenerator();
var_dump(file_exists($cacheFile)); // rien n'est encore chargé

echo $uris->forRoute('user.show', ['id' => '7']), "\n";
var_dump(file_exists($cacheFile)); // le premier forRoute() a construit et mis en cache les routes
```

```text
bool(false)
/users/7
bool(true)
```

Voir [Cache et chargement paresseux](cache.md#chargement-paresseux).

## Sans `EduRoute`

Si vous construisez le `RouteCollector` vous-même, `processedRoutes()` renvoie un tableau de trois
éléments : routes statiques, routes variables, et **routes nommées** (index `2`). Passez ce dernier
au constructeur de `FromProcessedConfiguration` :

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator\MarkBased;
use Studoo\EduRoute\GenerateUri\FromProcessedConfiguration;
use Studoo\EduRoute\RouteCollector;
use Studoo\EduRoute\RouteParser\Std;

$collector = new RouteCollector(new Std(), new MarkBased());
$collector->get('/users/{id:\d+}', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);

// processedRoutes() renvoie [routes statiques, routes variables, routes nommées]
$uris = new FromProcessedConfiguration($collector->processedRoutes()[2]);

echo $uris->forRoute('user.show', ['id' => '7']), "\n";
```

```text
/users/7
```

Pour brancher votre propre générateur (une classe qui implémente `GenerateUri`), utilisez
`withUriGenerator()` : voir [Comment ça marche](how-it-works.md#implémentations-personnalisées).

---

← [Page précédente](dispatching.md) · [Sommaire](index.md) · [Page suivante](attributes.md) →
