# Changelog

Toutes les évolutions notables de `studoo/edu-route` sont consignées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte
le [versionnage sémantique](https://semver.org/lang/fr/).

`studoo/edu-route` est un fork modernisé de [nikic/FastRoute](https://github.com/nikic/FastRoute)
(branche `master`, commit `1c96139`, avril 2024). Pour migrer, voir [UPGRADE.md](UPGRADE.md).

## [Non publié] — 1.0.0

Première version. Par rapport à FastRoute :

### Rupture de compatibilité

- PHP **8.4** minimum (`~8.4.0 || ~8.5.0`).
- Namespace **`Studoo\EduRoute\`** (au lieu de `FastRoute\`) ; le constructeur `FastRoute\FastRoute`
  devient `Studoo\EduRoute\EduRoute`.
- Supprimés : les fonctions `FastRoute\simpleDispatcher()` et `FastRoute\cachedDispatcher()`
  (remplacées par `EduRoute::recommendedSettings()`), et `RouteCollector::getData()`
  (remplacée par `processedRoutes()`).
- Objets résultat (`Matched`, `MethodNotAllowed`, `NotMatched`) **immuables** : classes `readonly`
  aux propriétés « write-once » (`public(set) readonly`). Les propriétés n'ont plus de valeur par
  défaut ; toute modification après création lève une `Error`.
- Classes concrètes `final` natives (auparavant `@final` en PHPDoc) : `RouteCollector`,
  `RouteParser\Std`, dispatchers, générateurs de données, `BadRouteException`.
- Constantes publiques typées (`Dispatcher::FOUND` : `int`, `ConfigureRoutes::ROUTE_NAME` : `string`…).
- `ConfigureRoutes::addGroup()` et `Cache::get()` attendent une `Closure` (plus un `callable`).

### Corrigé

- `GroupPosBased` envoyait la requête vers la mauvaise route (avec des avertissements et des
  variables `null`) quand un paramètre capturait une chaîne vide, par exemple
  `{slug:[a-z]*}` sur `/page/`. Bug hérité de FastRoute. La route est désormais retrouvée par la
  position de son *dernier* groupe (plus de boucle, jusqu'à −13 % sur les routes dynamiques) ;
  le format des données en cache de cette stratégie change.
- Un fichier de cache à un autre format (par exemple écrit par FastRoute 1.x) est détecté et
  régénéré, au lieu de provoquer des erreurs au dispatch (`FileCache` et `Psr16Cache`).
- Des routes contenant un objet (closure…) ne sont plus écrites dans un cache fichier illisible
  (erreur fatale à la requête suivante) : `CannotCacheRoutes` est levée dès la première requête.

### Ajouté

- Énumération **`HttpMethod`** (`Get`, `Post`, `Put`, `Patch`, `Delete`, `Head`, `Options`, `Any`),
  acceptée avec les chaînes par `addRoute()` et `dispatch()` :
  `addRoute([HttpMethod::Put, 'PATCH'], …)`, `dispatch(HttpMethod::Get, $uri)`. Une valeur qui
  n'est ni une chaîne ni un cas de l'énumération lève une `TypeError`.
- **Routes par attributs** : `#[Route]` (répétable) sur les méthodes de contrôleur et `#[RouteGroup]`
  (préfixe de chemin et de nom) sur la classe, enregistrées par `AttributeRouteLoader::register()`.
  Le handler est la chaîne `Classe::méthode`, compatible avec le cache. Les erreurs (contrôleur non
  instanciable, méthode héritée, statique ou magique, attribut mal placé, nom vide, aucune
  méthode HTTP, route en double) sont signalées avec le contrôleur et la méthode en cause.
- **Chargement paresseux** : `EduRoute::lazyDispatcher()` et `lazyUriGenerator()` (*lazy objects*
  « ghost » PHP 8.4) ne chargent les routes qu'au premier appel, sans surcoût ensuite (un *proxy*
  coûtait +30 à +76 % par dispatch avec le JIT).
- **Middleware PSR-15** `Http\RoutingMiddleware` : attributs de requête pour la route et ses
  variables, réponses 404 et 405 (en-tête `Allow`, `HEAD` inclus avec `GET`). `psr/http-server-middleware` et
  `psr/http-factory` sont suggérés, pas imposés.
- `#[\Override]` sur toutes les méthodes redéfinies (imposé par PHPStan).
- Documentation en français : README, UPGRADE.

### Déprécié

- L'accès tableau aux résultats (`$result[0]`, `[1]`, `[2]`, `isset($result[1])`) émet un
  `E_USER_DEPRECATED` (`#[\Deprecated]`) : utilisez `instanceof` et les propriétés. Les constantes
  `Dispatcher::FOUND`, `NOT_FOUND` et `METHOD_NOT_ALLOWED`, qui n'ont de sens qu'avec cet accès,
  sont dépréciées. Suppression prévue en 2.0.

### Modifié

- Le constructeur `EduRoute` renvoie une nouvelle instance à chaque méthode `with*()` / `use*()`
  par clonage ; les routes déjà calculées *en mémoire* ne sont jamais réutilisées par une autre
  configuration (un fichier de cache, lui, doit être supprimé en cas de changement de stratégie).
- Classes `readonly` pour `GeneratedUri`, `FromProcessedConfiguration`, `Psr16Cache` et `Route`.
- Dispatch : une seule recherche dans les tables de routes au lieu de `isset()` suivi de deux
  lectures, ce qui compense l'essentiel du coût de l'immuabilité. Mesuré face à la version
  précédente (2 passes, 75 mesures) : médiane +1,0 à +1,4 %, dans le bruit de mesure ; routes
  statiques −1 à −9 %, première route dynamique +2 à +8 %.
- `str_increment()` à la place de `++` sur une chaîne (déprécié depuis PHP 8.3).

### Écarté après mesure

- Objets résultat construits par constructeur : +10 % par route dynamique trouvée, +25 % à froid
  (un appel de fonction par dispatch). Les propriétés « write-once » donnent la même garantie sans ce coût.
- Mémorisation des résultats statiques : −34 % sur un worker persistant, mais +36 % à froid
  (PHP-FPM) et de la mémoire en plus.
- `array_find()` pour détecter une route statique masquée : +9 % sur l'enregistrement des routes.

### Outillage

- PHPUnit 13.4, PHPStan 2.3 niveau max + règles strictes, lcobucci/coding-standard 12.1,
  Rector 2.8 (bloquant), phpbench 1.7 ; suite stricte (échec sur toute dépréciation non attendue).
- CI GitHub Actions : PHP 8.4 / 8.5 × dépendances lowest / locked / highest, PHPStan, PHPCS, Rector,
  comparaison de performances sur chaque PR (seuil 15 %), contrôle de compatibilité Roave.
