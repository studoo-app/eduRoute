# Comment ça marche

[English](../en/how-it-works.md)

Cette page ouvre le capot. Elle explique comment EduRoute transforme vos routes en quelques
expressions régulières, et comment il retrouve la bonne route en un seul appel à `preg_match()`.
Vous n'en avez pas besoin pour utiliser le routeur, mais c'est un bon terrain d'étude : tableaux
associatifs, expressions régulières PCRE, mesure de performances.

L'algorithme vient de [nikic/FastRoute](https://github.com/nikic/FastRoute). Son auteur l'explique
en détail dans l'article [Fast request routing using regular expressions](http://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html).

## Vue d'ensemble

Le routage passe par trois composants, chacun défini par une interface :

```text
"/user/{id:\d+}[/{name}]"
        |
        v
  RouteParser      découpe le motif en segments statiques et en paramètres
        |
        v
  DataGenerator    range les routes statiques dans une table de hachage
        |          et combine les routes variables en grandes expressions régulières
        v
  Dispatcher       cherche la route qui correspond à une méthode HTTP et une URI
```

| Rôle | Interface | Implémentations fournies |
|---|---|---|
| Analyser un motif | [`RouteParser`](../../src/RouteParser.php) | [`RouteParser\Std`](../../src/RouteParser/Std.php) |
| Préparer les données | [`DataGenerator`](../../src/DataGenerator.php) | [`MarkBased`](../../src/DataGenerator/MarkBased.php), [`GroupCountBased`](../../src/DataGenerator/GroupCountBased.php), [`GroupPosBased`](../../src/DataGenerator/GroupPosBased.php), [`CharCountBased`](../../src/DataGenerator/CharCountBased.php) |
| Trouver la route | [`Dispatcher`](../../src/Dispatcher.php) | une classe du même nom par stratégie dans [`src/Dispatcher/`](../../src/Dispatcher/) |

[`EduRoute`](../../src/EduRoute.php) assemble ces pièces :

1. il crée un [`RouteCollector`](../../src/RouteCollector.php) avec le parser et le générateur
   choisis, puis appelle votre fonction de définition des routes ;
2. `RouteCollector::processedRoutes()` renvoie un tableau `[routes statiques, routes variables,
   routes nommées]` ; c'est ce tableau qui est mis en [cache](cache.md) ;
3. `dispatcher()` fait `new $dispatcher($data)` et `uriGenerator()` fait
   `new $uriGenerator($data[2])`.

Les données sont donc calculées une fois (ou lues dans le cache), puis le dispatcher ne fait plus
que des recherches.

## Étape 1 : le RouteParser

`RouteParser\Std` lit la syntaxe des motifs (`{nom}`, `{nom:regex}`, `[...]` pour les segments
optionnels, voir [Définir les routes](routes.md)). Il renvoie **une liste de routes** : une
version sans le segment optionnel, une version avec.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\RouteParser\Std;

$parser = new Std();

// Un motif avec un segment optionnel donne deux routes
foreach ($parser->parse('/user/{id:\d+}[/{name}]') as $parsedRoute) {
    echo json_encode($parsedRoute, JSON_UNESCAPED_SLASHES), "\n";
}
```

Sortie :

```text
["/user/",["id","\\d+"]]
["/user/",["id","\\d+"],"/",["name","[^/]+"]]
```

Chaque route est une suite de morceaux :

- une **chaîne** est un segment statique (`"/user/"`) ;
- un **tableau** `[nom, regex]` est un paramètre. Sans regex explicite, le parser utilise
  `Std::DEFAULT_DISPATCH_REGEX`, soit `[^/]+` (tout sauf une barre oblique).

Le parser refuse dès cette étape les motifs incorrects : crochets mal fermés, segment optionnel au
milieu d'une route, paramètre déclaré deux fois, regex contenant un groupe capturant. Les messages
sont décrits dans [Erreurs](errors.md).

> **Note**
> Les groupes capturants sont interdits dans les regex de paramètres (`{id:(\d+)}`) parce que
> l'étape suivante compte et numérote les groupes. Utilisez un groupe non capturant :
> `{lang:(?:fr|en)}`.

## Étape 2 : le DataGenerator

Le générateur reçoit chaque route analysée avec sa méthode HTTP et son handler
(`DataGenerator::addRoute()`), puis produit les données du dispatcher (`getData()`). Les quatre
générateurs fournis partagent la même base, [`RegexBasedAbstract`](../../src/DataGenerator/RegexBasedAbstract.php).

### Routes statiques : une table de hachage

Une route sans paramètre (`/about`) est rangée dans un tableau PHP indexé par méthode puis par
URI :

```text
['GET' => ['/about' => [handler, extraParameters]]]
```

La recherche est un simple accès par clé : son coût ne dépend pas du nombre de routes. C'est
pourquoi le dispatcher teste **toujours les routes statiques en premier**.

### Routes variables : une expression régulière combinée

Chaque route variable devient une expression régulière (classe interne
[`Route`](../../src/Route.php)) : les segments statiques sont échappés avec `preg_quote()` et
chaque paramètre devient un groupe capturant. `/user/{id:\d+}` devient `/user/(\d+)`.

Tester ces expressions une par une serait lent : 100 routes, 100 appels à `preg_match()`. L'idée
de FastRoute est de les réunir en **une seule** expression, avec des alternatives `|` :

```text
~^(?:/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+))$~
```

Un seul appel à `preg_match()` suffit alors. Reste une question : **quelle alternative a
correspondu ?** PCRE ne le dit pas directement. Les quatre stratégies, décrites
[plus bas](#les-quatre-stratégies), sont quatre réponses différentes à cette question.

Voici ce que produit le générateur par défaut (`MarkBased`) pour une route statique et trois
routes variables :

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\DataGenerator\MarkBased;
use Studoo\EduRoute\RouteParser\Std;

$parser = new Std();
$generator = new MarkBased();

$routes = [
    '/about'                => 'about',
    '/user/{id:\d+}'        => 'user.show',
    '/user/{id:\d+}/{name}' => 'user.profile',
    '/post/{slug}'          => 'post.show',
];

foreach ($routes as $pattern => $handler) {
    foreach ($parser->parse($pattern) as $parsedRoute) {
        $generator->addRoute('GET', $parsedRoute, $handler);
    }
}

[$static, $dynamic] = $generator->getData();

// Routes statiques : méthode => URI => [handler, paramètres supplémentaires]
echo json_encode($static, JSON_UNESCAPED_SLASHES), "\n";

// Routes variables : méthode => liste de paquets (regex + table de correspondance)
echo $dynamic['GET'][0]['regex'], "\n";
echo json_encode($dynamic['GET'][0]['routeMap'], JSON_UNESCAPED_SLASHES), "\n";
```

Sortie :

```text
{"GET":{"/about":["about",[]]}}
~^(?|/user/(\d+)(*MARK:a)|/user/(\d+)/([^/]+)(*MARK:b)|/post/([^/]+)(*MARK:c))$~
{"a":["user.show",{"id":"id"},[]],"b":["user.profile",{"id":"id","name":"name"},[]],"c":["post.show",{"slug":"slug"},[]]}
```

La `routeMap` associe à chaque route son handler, le nom de ses paramètres (dans l'ordre des
groupes) et ses paramètres supplémentaires.

> **Note**
> Appelé directement, le générateur ne voit que ce que vous lui passez. Via `RouteCollector`, les
> paramètres supplémentaires contiennent aussi la clé réservée `_route` (le motif d'origine), voir
> [Définir les routes](routes.md).

### Découpage en paquets

Une expression géante avec des centaines d'alternatives devient lente à compiler et à exécuter, et
peut dépasser les limites de PCRE. Les routes variables de chaque méthode sont donc découpées en
**paquets** (*chunks*) d'une taille approximative fixée par la stratégie : environ 30 routes pour
`MarkBased` et `CharCountBased`, environ 10 pour `GroupCountBased` et `GroupPosBased`. La taille
réelle est ajustée pour équilibrer les paquets :

| Générateur | 25 routes | 100 routes |
|---|---|---|
| `MarkBased` | 1 regex (25) | 3 regex (34 + 34 + 32) |
| `GroupCountBased` | 3 regex (9 + 9 + 7) | 10 regex (10 × 10) |

Le dispatcher essaie les paquets dans l'ordre et s'arrête au premier qui correspond.

### Vérifications à l'enregistrement

Le générateur détecte deux erreurs dès la déclaration des routes (messages complets dans
[Erreurs](errors.md)) :

- une route déjà déclarée pour la même méthode ;
- une route statique **masquée** par une route variable déclarée avant elle.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/user/{name}', 'user.show');
    $r->get('/user/me', 'user.me'); // déclarée APRÈS la route variable qui la couvre
}, 'demo')->disableCache();

try {
    $router->dispatcher();
} catch (BadRouteException $e) {
    echo $e->getMessage(), "\n";
}

$router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/user/me', 'user.me'); // la route statique d'abord
    $r->get('/user/{name}', 'user.show');
}, 'demo')->disableCache();

$dispatcher = $router->dispatcher();
echo $dispatcher->dispatch('GET', '/user/me')->handler, "\n";
echo $dispatcher->dispatch('GET', '/user/alice')->handler, "\n";
```

Sortie :

```text
Static route "/user/me" is shadowed by previously defined variable route "/user/([^/]+)" for method "GET"
user.me
user.show
```

Dans le second routeur, `/user/me` gagne parce que la table statique est consultée avant les
expressions régulières.

Entre routes **variables**, en revanche, rien n'est vérifié : la première alternative qui
correspond gagne, donc l'ordre de déclaration compte.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/post/{slug}', 'post.bySlug');  // déclarée en premier
    $r->get('/post/{id:\d+}', 'post.byId');  // ne sera jamais atteinte
}, 'demo')->disableCache()->dispatcher();

echo $dispatcher->dispatch('GET', '/post/42')->handler, "\n"; // post.bySlug
```

> **Attention**
> Déclarez les routes variables de la plus précise à la plus générale (`{id:\d+}` avant `{slug}`).

## Étape 3 : le Dispatcher

`dispatch($methode, $uri)` ([`Dispatcher\RegexBasedAbstract`](../../src/Dispatcher/RegexBasedAbstract.php))
cherche dans cet ordre, et s'arrête au premier résultat :

1. la table statique de la méthode demandée ;
2. les paquets d'expressions régulières de cette méthode ;
3. pour une requête `HEAD` : les routes statiques puis variables de `GET` ;
4. les routes de repli `'*'` (statiques puis variables).

Si rien ne correspond, il teste l'URI avec **toutes les autres méthodes** pour savoir si la route
existe ailleurs : il renvoie alors `MethodNotAllowed` (avec la liste des méthodes autorisées),
sinon `NotMatched`. Les résultats sont détaillés dans [Dispatcher une requête](dispatching.md).

Seule l'étape 2 dépend de la stratégie : chaque dispatcher implémente `dispatchVariableRoute()`,
qui lit le résultat de `preg_match()` pour retrouver la route et ses variables.

## Les quatre stratégies

Les quatre stratégies donnent toujours le même résultat : seule la façon d'identifier
l'alternative trouvée change. Le script suivant construit les mêmes trois routes avec chacune
d'elles, affiche l'expression produite et dispatche `/user/42/alice` :

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\RouteParser\Std;

// Chaque stratégie associe un générateur et le dispatcher qui sait lire ses données
$strategies = [
    'Mark'       => [DataGenerator\MarkBased::class, Dispatcher\MarkBased::class],
    'GroupCount' => [DataGenerator\GroupCountBased::class, Dispatcher\GroupCountBased::class],
    'GroupPos'   => [DataGenerator\GroupPosBased::class, Dispatcher\GroupPosBased::class],
    'CharCount'  => [DataGenerator\CharCountBased::class, Dispatcher\CharCountBased::class],
];

$routes = [
    '/user/{id:\d+}'        => 'user.show',
    '/user/{id:\d+}/{name}' => 'user.profile',
    '/post/{slug}'          => 'post.show',
];

$parser = new Std();

foreach ($strategies as $label => [$generatorClass, $dispatcherClass]) {
    $generator = new $generatorClass();
    foreach ($routes as $pattern => $handler) {
        foreach ($parser->parse($pattern) as $parsedRoute) {
            $generator->addRoute('GET', $parsedRoute, $handler);
        }
    }

    $data = $generator->getData();
    $chunk = $data[1]['GET'][0]; // le seul paquet de routes variables

    // Les tabulations sont affichées « \t » pour rester lisibles
    $show = static fn (string $text): string => str_replace("\t", '\t', $text);

    echo "== $label\n";
    echo 'regex    : ', $chunk['regex'], "\n";
    if (isset($chunk['suffix'])) {
        echo 'suffix   : ', $show($chunk['suffix']), "\n";
    }
    echo 'routeMap : ', implode(', ', array_map(
        static fn (int|string $key, array $route): string => $show(var_export($key, true)) . ' => ' . $route[0],
        array_keys($chunk['routeMap']),
        $chunk['routeMap'],
    )), "\n";

    $result = (new $dispatcherClass($data))->dispatch('GET', '/user/42/alice');
    echo 'dispatch : ', $result->handler, ' ', json_encode($result->variables), "\n\n";
}
```

Sortie :

```text
== Mark
regex    : ~^(?|/user/(\d+)(*MARK:a)|/user/(\d+)/([^/]+)(*MARK:b)|/post/([^/]+)(*MARK:c))$~
routeMap : 'a' => user.show, 'b' => user.profile, 'c' => post.show
dispatch : user.profile {"id":"42","name":"alice"}

== GroupCount
regex    : ~^(?|/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+)()())$~
routeMap : 2 => user.show, 3 => user.profile, 4 => post.show
dispatch : user.profile {"id":"42","name":"alice"}

== GroupPos
regex    : ~^(?:/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+))$~
routeMap : 1 => user.show, 3 => user.profile, 4 => post.show
dispatch : user.profile {"id":"42","name":"alice"}

== CharCount
regex    : ~^(?|(?:/user/(\d+)/(\t{1})\t{2})|(?:/user/(\d+)/([^/]+)/(\t{2})\t{1})|(?:/post/([^/]+)/(\t{3})\t{0}))$~
suffix   : /\t\t\t
routeMap : '\t' => user.show, '\t\t' => user.profile, '\t\t\t' => post.show
dispatch : user.profile {"id":"42","name":"alice"}
```

Pour comprendre chaque stratégie, regardons ce que `preg_match()` renvoie pour l'URI
`/post/hello` (troisième route) avec ces expressions :

```php
<?php
declare(strict_types=1);

// Les expressions affichées ci-dessus, appliquées à « /post/hello »
$uri = '/post/hello';

preg_match('~^(?|/user/(\d+)(*MARK:a)|/user/(\d+)/([^/]+)(*MARK:b)|/post/([^/]+)(*MARK:c))$~', $uri, $m);
echo 'Mark       : ', json_encode($m, JSON_UNESCAPED_SLASHES), "\n";

preg_match('~^(?|/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+)()())$~', $uri, $m);
echo 'GroupCount : ', json_encode($m, JSON_UNESCAPED_SLASHES), ' count=', count($m), "\n";

preg_match('~^(?:/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+))$~', $uri, $m);
echo 'GroupPos   : ', json_encode($m, JSON_UNESCAPED_SLASHES), ' dernier indice=', count($m) - 1, "\n";

// CharCount ajoute le suffixe « / » + 3 tabulations à l'URI
preg_match("~^(?|(?:/user/(\d+)/(\t{1})\t{2})|(?:/user/(\d+)/([^/]+)/(\t{2})\t{1})|(?:/post/([^/]+)/(\t{3})\t{0}))$~", $uri . "/\t\t\t", $m);
echo 'CharCount  : ', json_encode($m, JSON_UNESCAPED_SLASHES), ' dernier=', strlen(end($m)), " tabulation(s)\n";
```

Sortie :

```text
Mark       : {"0":"/post/hello","1":"hello","MARK":"c"}
GroupCount : ["/post/hello","hello","",""] count=4
GroupPos   : ["/post/hello","","","","hello"] dernier indice=4
CharCount  : ["/post/hello/\t\t\t","hello","\t\t\t"] dernier=3 tabulation(s)
```

Deux notions PCRE reviennent :

- `(?|...)` est un **groupe à réinitialisation de branche** (*branch reset*) : dans chaque
  alternative, la numérotation des groupes repart de 1. Les variables de la route trouvée sont
  donc toujours dans `$matches[1]`, `$matches[2]`, etc.
- `(?:...)` est un groupe non capturant classique : les groupes sont numérotés à la suite d'une
  alternative à l'autre.

### Mark (par défaut)

Chaque alternative se termine par le verbe PCRE `(*MARK:nom)`, avec un nom différent par route
(`a`, `b`, `c`… générés avec `str_increment()`). Quand une alternative correspond, PHP place son
nom dans `$matches['MARK']`. Le dispatcher lit `routeMap[$matches['MARK']]` : c'est un accès
direct, sans calcul.

C'est la stratégie choisie par `EduRoute::recommendedSettings()`. Paquets d'environ 30 routes.

### GroupCount

Grâce à `(?|...)`, le **nombre de groupes** de l'alternative trouvée se lit dans `count($matches)`.
Pour que ce nombre soit différent pour chaque route, le générateur ajoute des groupes vides `()` :
chaque route a au moins un groupe de plus que la précédente. Ici `/post/([^/]+)` n'a qu'une
variable, mais reçoit `()()` pour atteindre 3 groupes ; `count($matches)` vaut alors 4, la clé de
`post.show` dans la `routeMap`.

C'est la stratégie historique de FastRoute. Les groupes vides alourdissent l'expression : paquets
d'environ 10 routes.

### GroupPos

Pas de réinitialisation de branche : `(?:...)` numérote les groupes à la suite (`user.show`
utilise le groupe 1, `user.profile` les groupes 2 et 3, `post.show` le groupe 4). PHP n'inclut pas
dans `$matches` les groupes non utilisés situés **après** le dernier groupe utilisé : le dernier
indice de `$matches` est donc la position du dernier groupe de la route trouvée. La `routeMap` est
indexée par cette position, et les variables sont les `n` derniers groupes.

Aucun groupe vide n'est ajouté, mais `$matches` contient les groupes des alternatives précédentes
(chaînes vides) : paquets d'environ 10 routes.

### CharCount

Le dispatcher ajoute à l'URI un **suffixe** : `/` suivi d'autant de tabulations que de routes dans
le paquet (ici trois). Chaque alternative `i` capture exactement `i` tabulations dans un dernier
groupe, puis consomme les autres. Le contenu de ce dernier groupe, lu avec `end($matches)`, est la
clé de la route dans la `routeMap`.

La route est identifiée par une longueur de texte plutôt que par un groupe ou un nom. Paquets
d'environ 30 routes.

### Récapitulatif

| Méthode `EduRoute` | Classes | Identification de la route | Paquets |
|---|---|---|---|
| `useMarkDispatcher()` *(par défaut)* | `DataGenerator\MarkBased` + `Dispatcher\MarkBased` | `$matches['MARK']` | ~30 |
| `useGroupCountDispatcher()` | `DataGenerator\GroupCountBased` + `Dispatcher\GroupCountBased` | `count($matches)` | ~10 |
| `useGroupPosDispatcher()` | `DataGenerator\GroupPosBased` + `Dispatcher\GroupPosBased` | dernier indice de `$matches` | ~10 |
| `useCharCountDispatcher()` | `DataGenerator\CharCountBased` + `Dispatcher\CharCountBased` | longueur du suffixe capturé | ~30 |

```php
$router = EduRoute::recommendedSettings($routes, 'routes')->useGroupCountDispatcher();
```

> **Attention**
> Le format des données mises en cache dépend de la stratégie. Après un changement de stratégie,
> videz le cache ou utilisez une autre clé (voir [Cache](cache.md)).

## Comparer les stratégies

Le dépôt contient des bancs d'essai [PHPBench](https://phpbench.readthedocs.io/) dans
[`benchmark/`](../../benchmark/) :

| Fichier | Ce qui est mesuré |
|---|---|
| `RoutingWithRealLifeExampleBench.php` | dispatch sur une quarantaine de routes réalistes (site, boutique, administration) |
| `RoutingWithManyRoutesBench.php` | dispatch sur 400 routes statiques et 400 routes variables |
| `RouteRegistrationBench.php` | enregistrement des routes |
| `UriGenerationBench.php` | génération d'URL |

Les deux premiers mesurent chaque stratégie (colonne `set` : `mark`, `group_count`, `group_pos`,
`char_count`) dans plusieurs situations (colonne `subject`) : première ou dernière route,
statique ou variable, mauvaise méthode (`staticInvalidMethod`, `dynamicInvalidMethod` : réponse 405), route inconnue
(`unknownRoute` : réponse 404).

```bash
make phpbench

# Un seul fichier, moins d'itérations pour un essai rapide
make phpbench PHPBENCH_OPTIONS="--filter=RoutingWithManyRoutesBench --iterations=2 --revs=50"
```

Lisez la colonne `mode` (temps le plus fréquent par appel) et `rstdev` (dispersion : au-delà de
quelques pour cent, relancez la mesure). Quelques observations à vérifier vous-même :

- les routes statiques coûtent presque la même chose avec toutes les stratégies (accès par clé) ;
- l'écart se creuse sur la **dernière** route variable et sur les réponses 404/405, qui
  parcourent tous les paquets ;
- `Mark` est en général la plus rapide, ce qui justifie son choix par défaut.

`make phpbench-compare` compare une nouvelle mesure à une référence enregistrée localement sous
l'étiquette `baseline_upstream`. Enregistrez-la d'abord, par exemple avant une modification :
`make phpbench PHPBENCH_OPTIONS="--store --tag=baseline_upstream"`.

> **Note**
> Xdebug fausse fortement les mesures : la cible `make phpbench` le désactive
> (`XDEBUG_MODE=off`).

## Implémentations personnalisées

Deux méthodes de `EduRoute` branchent vos propres classes. Comme les autres méthodes `use*()` et
`with*()`, elles renvoient une nouvelle instance.

- `useCustomDispatcher(string $dataGenerator, string $dispatcher)` : une classe qui implémente
  `DataGenerator` et une classe qui implémente `Dispatcher`. EduRoute crée le dispatcher avec
  `new $dispatcher($data)`, où `$data` est le tableau produit par le générateur, suivi des routes
  nommées.
- `withUriGenerator(string $uriGenerator)` : une classe qui implémente `GenerateUri`. EduRoute la
  crée avec `new $uriGenerator($routesNommees)`.

Les deux méthodes attendent des **noms de classe**. L'exemple suivant décore les implémentations
fournies : un dispatcher qui journalise chaque requête, et un générateur qui préfixe les URL par la
langue.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\GenerateUri;
use Studoo\EduRoute\GenerateUri\FromProcessedConfiguration;
use Studoo\EduRoute\GenerateUri\GeneratedUri;
use Studoo\EduRoute\HttpMethod;

/**
 * Journalise chaque requête, puis délègue au dispatcher Mark.
 * Le constructeur reçoit les données du DataGenerator choisi (ici MarkBased).
 */
final class LoggingDispatcher implements Dispatcher
{
    private Dispatcher $inner;

    public function __construct(array $data)
    {
        $this->inner = new Dispatcher\MarkBased($data);
    }

    public function dispatch(HttpMethod|string $httpMethod, string $uri): Matched|NotMatched|MethodNotAllowed
    {
        $result = $this->inner->dispatch($httpMethod, $uri);
        $method = $httpMethod instanceof HttpMethod ? $httpMethod->value : $httpMethod;
        echo '[log] ', $method, ' ', $uri, ' -> ', $result::class, "\n";

        return $result;
    }
}

/**
 * Ajoute un préfixe de langue aux URL générées.
 * Le constructeur reçoit les routes nommées.
 */
final class LocalizedUriGenerator implements GenerateUri
{
    private GenerateUri $inner;

    public function __construct(array $namedRoutes)
    {
        $this->inner = new FromProcessedConfiguration($namedRoutes);
    }

    public function forRoute(string $name, array $substitutions = []): GeneratedUri
    {
        $uri = $this->inner->forRoute($name, $substitutions);

        return new GeneratedUri('/fr' . $uri->path, $uri->unmatchedSubstitutions);
    }
}

$router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/article/{id:\d+}', 'article.show', [ConfigureRoutes::ROUTE_NAME => 'article']);
}, 'demo')
    ->disableCache()
    ->useCustomDispatcher(DataGenerator\MarkBased::class, LoggingDispatcher::class)
    ->withUriGenerator(LocalizedUriGenerator::class);

$router->dispatcher()->dispatch('GET', '/article/7');
$router->dispatcher()->dispatch('GET', '/nope');

echo $router->uriGenerator()->forRoute('article', ['id' => '7']), "\n";
```

Sortie :

```text
[log] GET /article/7 -> Studoo\EduRoute\Dispatcher\Result\Matched
[log] GET /nope -> Studoo\EduRoute\Dispatcher\Result\NotMatched
/fr/article/7
```

> **Attention**
> Le générateur et le dispatcher doivent parler le même format de données : associez toujours un
> dispatcher au générateur qu'il sait lire. Les classes `RegexBasedAbstract` de `src/DataGenerator/`
> et `src/Dispatcher/` sont marquées `@internal` : pour une stratégie entièrement nouvelle,
> implémentez directement les interfaces `DataGenerator` et `Dispatcher`.

Les données d'un dispatcher personnalisé passent par le même cache que les autres : leurs handlers
doivent rester exportables (voir [Cache](cache.md)). Le dispatcher personnalisé profite aussi du
chargement paresseux de `lazyDispatcher()`.

## Pour aller plus loin

- [Fast request routing using regular expressions](http://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html),
  l'article d'origine de nikic ;
- la documentation PCRE de PHP sur les [sous-masques](https://www.php.net/manual/fr/regexp.reference.subpatterns.php)
  (dont `(?|...)`) et, pour `(*MARK)`, la [documentation de PCRE2](https://www.pcre.org/current/doc/html/pcre2pattern.html) ;
- le code : [`RouteParser\Std`](../../src/RouteParser/Std.php),
  [`DataGenerator\MarkBased`](../../src/DataGenerator/MarkBased.php),
  [`Dispatcher\MarkBased`](../../src/Dispatcher/MarkBased.php) ; chaque stratégie tient en une
  cinquantaine de lignes.

---

← [Page précédente](middleware.md) · [Sommaire](index.md) · [Page suivante](errors.md) →
