EduRoute — routeur HTTP rapide pour PHP 8.4+
=============================================

[![Qualité](https://github.com/studoo-app/eduRoute/actions/workflows/qa.yml/badge.svg)](https://github.com/studoo-app/eduRoute/actions/workflows/qa.yml)
[![Packagist](https://img.shields.io/packagist/v/studoo/edu-route.svg)](https://packagist.org/packages/studoo/edu-route)

[English](README.md) · **Français**

`studoo/edu-route` associe une méthode HTTP et une URL à un *handler* (un nom de contrôleur,
une closure, n'importe quelle valeur). C'est la couche de routage
d'[edu-framework](https://github.com/studoo-app/edu-framework), un framework pédagogique.

Il s'agit d'un fork modernisé de [nikic/FastRoute](https://github.com/nikic/FastRoute) : même
algorithme à base d'expressions régulières combinées, réécrit pour PHP 8.4 avec des énumérations,
des résultats immuables, des routes par attributs, un listage des routes pour une commande
`routes:list` et une analyse statique au niveau maximal.

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

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        $r->get('/user/{id:\d+}', 'show_user');               // {id} doit être un nombre
        $r->get('/articles/{id:\d+}[/{title}]', 'show_article'); // /{title} est optionnel
    },
    __DIR__ . '/var/cache/routes.php', // fichier de cache
);

// Méthode et URI de la requête, sans la query string, décodée
$method = $_SERVER['REQUEST_METHOD'];
$uri = rawurldecode(strtok($_SERVER['REQUEST_URI'], '?'));

$result = $router->dispatcher()->dispatch($method, $uri);

echo match (true) {
    $result instanceof Matched => $result->handler . ' ' . json_encode($result->variables),
    $result instanceof MethodNotAllowed => '405 – méthodes autorisées : ' . implode(', ', $result->allowedMethods),
    $result instanceof NotMatched => '404 – page introuvable',
}, "\n";
```

Documentation
-------------

| Français | English |
|---|---|
| [Sommaire](docs/fr/index.md) | [Contents](docs/en/index.md) |
| [Définir les routes](docs/fr/routes.md) | [Defining routes](docs/en/routes.md) |
| [Trouver la route d'une requête](docs/fr/dispatching.md) | [Dispatching a request](docs/en/dispatching.md) |
| [Générer des URL](docs/fr/uri-generation.md) | [Generating URLs](docs/en/uri-generation.md) |
| [Routes par attributs](docs/fr/attributes.md) | [Attribute routes](docs/en/attributes.md) |
| [Lister les routes](docs/fr/listing-routes.md) | [Listing routes](docs/en/listing-routes.md) |
| [Cache et chargement paresseux](docs/fr/cache.md) | [Cache and lazy loading](docs/en/cache.md) |
| [Middleware PSR-15](docs/fr/middleware.md) | [PSR-15 middleware](docs/en/middleware.md) |
| [Comment ça marche](docs/fr/how-it-works.md) | [How it works](docs/en/how-it-works.md) |
| [Erreurs et exceptions](docs/fr/errors.md) | [Errors and exceptions](docs/en/errors.md) |
| [Migration](docs/fr/migration.md) | [Migration](docs/en/migration.md) |

Les évolutions sont consignées dans [CHANGELOG.md](CHANGELOG.md) (en anglais).

Contribuer
----------

```sh
make ci        # standard de code, PHPStan (niveau max), Rector, PHPUnit
make phpbench  # benchmarks
```

Crédits et licence
------------------

Code d'origine © Nikita Popov et les contributeurs de FastRoute ; modernisation © Studoo.
Distribué sous licence BSD-3-Clause ([LICENSE](LICENSE)).
