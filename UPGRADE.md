# Migrer vers studoo/edu-route 1.0

## Depuis `nikic/fast-route` 1.x (cas d'edu-framework)

### 1. Dépendance et namespace

```diff
- "nikic/fast-route": "^1.3"
+ "studoo/edu-route": "^1.0"
```

Remplacez **tous** les préfixes `FastRoute\` par `Studoo\EduRoute\` : lignes `use`, noms
pleinement qualifiés dans le code (`\FastRoute\RouteCollector`), types de propriétés et de
paramètres, et PHPDoc (`@var \FastRoute\RouteCollector`).

```diff
- use FastRoute\Dispatcher;
- use FastRoute\RouteCollector;
- use FastRoute\RouteParser\Std;
+ use Studoo\EduRoute\ConfigureRoutes;
+ use Studoo\EduRoute\DataGenerator;
+ use Studoo\EduRoute\Dispatcher;
+ use Studoo\EduRoute\EduRoute;
+ use Studoo\EduRoute\RouteCollector;
+ use Studoo\EduRoute\RouteParser\Std;
```

### 2. Construire le dispatcher

Les fonctions `FastRoute\simpleDispatcher()` et `FastRoute\cachedDispatcher()` n'existent plus,
ni `RouteCollector::getData()`. Utilisez le constructeur `EduRoute` :

```diff
- $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
-     $r->addRoute('GET', '/users', 'handler');
- });
+ $dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
+     $r->addRoute('GET', '/users', 'handler');
+ }, 'unused')->disableCache()->dispatcher();
```

| Avant (fonction ou option) | Après |
|---|---|
| `simpleDispatcher($cb)` | `EduRoute::recommendedSettings($cb, 'unused')->disableCache()->dispatcher()` |
| `cachedDispatcher($cb, ['cacheFile' => $f])` | `EduRoute::recommendedSettings($cb, $f)->dispatcher()` (**voir l'avertissement ci-dessous**) |
| option `cacheDisabled => true` | `->disableCache()` |
| options `dataGenerator` + `dispatcher` | `->useMarkDispatcher()`, `->useGroupCountDispatcher()`, `->useGroupPosDispatcher()`, `->useCharCountDispatcher()` ou `->useCustomDispatcher($generateur, $dispatcher)` |
| options `routeParser` / `routeCollector` | pas d'équivalent sur `EduRoute` : construisez le collecteur vous-même (bloc ci-dessous) avec votre implémentation de `RouteParser` ou de `ConfigureRoutes` |
| `$collector->getData()` | `$collector->processedRoutes()` |
| *(2.0-dev)* options `cacheDriver` / `cacheKey` | `->withCache($driver, $cle)` |

> ⚠️ **Le format du cache a changé.** Supprimez l'ancien fichier `cacheFile` (ou utilisez un autre
> chemin) au déploiement. Un fichier à l'ancien format (2 entrées) est détecté et régénéré, mais un
> cache écrit avec une autre stratégie de dispatch ne l'est pas. Avec un cache, les *handlers*
> doivent être exportables (chaînes, tableaux, scalaires) : une closure lève `CannotCacheRoutes`.

Si vous construisez le collecteur vous-même, comme edu-framework (`FastRouteCore`) :

```diff
- $this->routeCollector = new \FastRoute\RouteCollector(
-     new \FastRoute\RouteParser\Std(),
-     new \FastRoute\DataGenerator\GroupCountBased()
- );
- $dispatcher = new \FastRoute\Dispatcher\GroupCountBased($this->routeCollector->getData());
+ $this->routeCollector = new \Studoo\EduRoute\RouteCollector(
+     new \Studoo\EduRoute\RouteParser\Std(),
+     new \Studoo\EduRoute\DataGenerator\MarkBased(),
+ );
+ $dispatcher = new \Studoo\EduRoute\Dispatcher\MarkBased($this->routeCollector->processedRoutes());
```

(`GroupCountBased` reste disponible ; `MarkBased` est simplement le plus rapide. Le générateur de
données et le dispatcher doivent toujours être de la même famille.)

### 3. Lire le résultat du dispatch

`dispatch()` renvoie un objet (`Matched`, `MethodNotAllowed` ou `NotMatched`). L'accès tableau
fonctionne encore mais émet une dépréciation :

```diff
- switch ($routeInfo[0]) {
-     case Dispatcher::NOT_FOUND: ...
-     case Dispatcher::METHOD_NOT_ALLOWED: $allowed = $routeInfo[1]; ...
-     case Dispatcher::FOUND: $handler = $routeInfo[1]; $vars = $routeInfo[2]; ...
- }
+ match (true) {
+     $result instanceof NotMatched => ...,
+     $result instanceof MethodNotAllowed => ... $result->allowedMethods ...,
+     $result instanceof Matched => ... $result->handler ... $result->variables ...,
+ };
```

(avec `use Studoo\EduRoute\Dispatcher\Result\{Matched, MethodNotAllowed, NotMatched};`)

### 4. Groupes et cache : `Closure` obligatoire

`addGroup()` et `Cache::get()` n'acceptent plus que des `Closure` (plus de chaîne ou de tableau
« callable ») :

```diff
- $r->addGroup('/admin', [$this, 'adminRoutes']);
+ $r->addGroup('/admin', $this->adminRoutes(...));
```

### 5. Extensions

- Les classes concrètes sont `final` (`RouteCollector`, `RouteParser\Std`, les dispatchers et
  générateurs de données, `BadRouteException`) : composez, ou implémentez les interfaces
  `ConfigureRoutes`, `RouteParser`, `DataGenerator`, `Dispatcher`.
- Les constantes publiques sont typées (`Dispatcher::FOUND` est un `int`,
  `ConfigureRoutes::ROUTE_NAME` une `string`) : une redéclaration doit reprendre le même type.
- Les objets résultat sont des classes `readonly` aux propriétés « write-once »
  (`public(set) readonly`) : un dispatcher maison les crée avec `new Matched()` puis écrit
  **une seule fois chacune** des propriétés `handler`, `variables` (plus de valeur par défaut
  `[]`) et `extraParameters`. Toute modification ultérieure lève une `Error`.
- Les constantes `Dispatcher::FOUND`, `NOT_FOUND` et `METHOD_NOT_ALLOWED` sont dépréciées avec
  l'accès tableau.

## Depuis `nikic/fast-route` 2.0-dev (branche `master`)

Tous les points ci-dessus s'appliquent (les fonctions supprimées au point 2 y étaient déjà
dépréciées), plus le renommage du constructeur `FastRoute\FastRoute` en
`Studoo\EduRoute\EduRoute` (mêmes méthodes).
