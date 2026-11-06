# Changelog

Toutes les évolutions notables de `studoo/edu-route` sont consignées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte
le [versionnage sémantique](https://semver.org/lang/fr/).

`studoo/edu-route` est un fork modernisé de [nikic/FastRoute](https://github.com/nikic/FastRoute)
(branche `master`, commit `1c96139`, avril 2024). Le namespace `FastRoute\` est conservé.

## [Non publié] — 1.0.0

### Rupture de compatibilité

- PHP **8.4** minimum (`~8.4.0 || ~8.5.0`).

### Déprécié

Ces API fonctionnent toujours, mais émettent désormais un `E_USER_DEPRECATED` **à l'exécution**
(attribut natif `#[\Deprecated]` de PHP 8.4) et seront supprimées en 2.0 :

| API dépréciée | Remplacement |
|---|---|
| `FastRoute\simpleDispatcher($callback, $options)` | `FastRoute::recommendedSettings($callback, $cacheKey)->disableCache()->dispatcher()` |
| `FastRoute\cachedDispatcher($callback, $options)` | `FastRoute::recommendedSettings($callback, $cacheKey)->dispatcher()` |
| `RouteCollector::getData()` | `RouteCollector::processedRoutes()` |

> ⚠️ Une application qui convertit les dépréciations en exceptions (gestionnaire d'erreurs strict,
> PHPUnit `failOnDeprecation`) doit migrer avant de monter de version. C'est le cas d'edu-framework,
> dont `FastRouteCore` appelle `getData()`.

### Modifié

- Modernisation PHP 8.4 sans changement d'API :
  - `#[\Override]` sur toutes les méthodes qui implémentent ou redéfinissent une méthode parente
    (vérifié par PHPStan `checkMissingOverrideMethodAttribute`) ;
  - classes `readonly` pour les objets de valeur finaux (`GeneratedUri`, `FromProcessedConfiguration`,
    `Psr16Cache`) et pour `Route` (`@internal`) ;
  - `str_increment()` à la place de `++` sur une chaîne (déprécié depuis PHP 8.3) dans `DataGenerator\MarkBased` ;
  - callables de première classe (`$this->processChunk(...)`), `new` sans parenthèses ;
  - constantes de classe **privées** typées.
- `GroupPosBased` : la boucle à corps vide qui cherche le premier groupe capturant non vide est
  réécrite en `while` explicite (Rector la supprimait à tort comme « code mort »).
- `simpleDispatcher()` et `cachedDispatcher()` partagent une implémentation interne
  (`LegacyDispatcherFactory`, `@internal`), pour n'émettre qu'une seule dépréciation par appel.

### Écarté après mesure

- `array_find()` pour détecter une route statique masquée : +9 % sur l'enregistrement des routes
  (refait à chaque requête sans cache, comme dans edu-framework). La boucle `foreach` est conservée.

### Outillage

- PHPUnit 13.4 (obligatoire), PHPStan 2.3 niveau max + règles strictes, lcobucci/coding-standard 12.1,
  Rector 2.8 (bloquant en CI), phpbench 1.7.
- La suite de tests échoue sur toute dépréciation, notice ou avertissement.
- CI GitHub Actions : PHP 8.4 / 8.5 × dépendances lowest / locked / highest, PHPStan, PHPCS, Rector,
  comparaison de performances sur chaque PR (seuil 15 %), contrôle de compatibilité Roave.

### Reporté à la 2.0 (rupture de compatibilité)

- Objets résultat `Matched` / `MethodNotAllowed` / `NotMatched` en `final readonly class` construits
  par constructeur. Mesuré : casse les `Dispatcher` tiers (`new Matched()` puis affectation) et coûte
  ~20 ns par route trouvée (+20 %, +40 % avec JIT). À retravailler sans perte de performances.
- Constantes publiques typées (`Dispatcher::FOUND`, `ConfigureRoutes::ROUTE_NAME`, `Std::VARIABLE_REGEX`…) :
  casse le code qui les redéclare.
- `final` natif sur les classes annotées `@final`, `Closure` à la place de `callable`, enums
  `HttpMethod` / statut de dispatch, suppression de l'accès tableau sur les résultats.
