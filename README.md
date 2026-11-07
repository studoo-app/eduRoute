EduRoute — fast HTTP router for PHP 8.4+
========================================

[![Quality](https://github.com/studoo-app/eduRoute/actions/workflows/qa.yml/badge.svg)](https://github.com/studoo-app/eduRoute/actions/workflows/qa.yml)
[![Packagist](https://img.shields.io/packagist/v/studoo/edu-route.svg)](https://packagist.org/packages/studoo/edu-route)

**English** · [Français](README.fr.md)

`studoo/edu-route` maps an HTTP method and a URL to a *handler* (a controller name, a closure,
any value). It is the routing layer of [edu-framework](https://github.com/studoo-app/edu-framework),
a framework built for teaching.

It is a modernized fork of [nikic/FastRoute](https://github.com/nikic/FastRoute): same combined
regular-expression algorithm, rewritten for PHP 8.4 with enums, immutable result objects,
attribute routes, a route listing for `routes:list` commands, and static analysis at the
strictest level.

Installation
------------

```sh
composer require studoo/edu-route
```

PHP 8.4 or 8.5 is required.

Quick start
-----------

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
        $r->get('/user/{id:\d+}', 'show_user');               // {id} must be a number
        $r->get('/articles/{id:\d+}[/{title}]', 'show_article'); // /{title} is optional
    },
    __DIR__ . '/var/cache/routes.php', // cache file
);

// Method and URI of the request, without the query string, decoded
$method = $_SERVER['REQUEST_METHOD'];
$uri = rawurldecode(strtok($_SERVER['REQUEST_URI'], '?'));

$result = $router->dispatcher()->dispatch($method, $uri);

echo match (true) {
    $result instanceof Matched => $result->handler . ' ' . json_encode($result->variables),
    $result instanceof MethodNotAllowed => '405 – allowed: ' . implode(', ', $result->allowedMethods),
    $result instanceof NotMatched => '404 – not found',
}, "\n";
```

Documentation
-------------

| English | Français |
|---|---|
| [Contents](docs/en/index.md) | [Sommaire](docs/fr/index.md) |
| [Defining routes](docs/en/routes.md) | [Définir les routes](docs/fr/routes.md) |
| [Dispatching a request](docs/en/dispatching.md) | [Trouver la route d'une requête](docs/fr/dispatching.md) |
| [Generating URLs](docs/en/uri-generation.md) | [Générer des URL](docs/fr/uri-generation.md) |
| [Attribute routes](docs/en/attributes.md) | [Routes par attributs](docs/fr/attributes.md) |
| [Listing routes](docs/en/listing-routes.md) | [Lister les routes](docs/fr/listing-routes.md) |
| [Cache and lazy loading](docs/en/cache.md) | [Cache et chargement paresseux](docs/fr/cache.md) |
| [PSR-15 middleware](docs/en/middleware.md) | [Middleware PSR-15](docs/fr/middleware.md) |
| [How it works](docs/en/how-it-works.md) | [Comment ça marche](docs/fr/how-it-works.md) |
| [Errors and exceptions](docs/en/errors.md) | [Erreurs et exceptions](docs/fr/errors.md) |
| [Migration](docs/en/migration.md) | [Migration](docs/fr/migration.md) |

Changes are listed in [CHANGELOG.md](CHANGELOG.md).

Contributing
------------

```sh
make ci        # coding standard, PHPStan (max level), Rector, PHPUnit
make phpbench  # benchmarks
```

Credits and license
-------------------

Original code © Nikita Popov and the FastRoute contributors; modernization © Studoo.
Released under the BSD-3-Clause license ([LICENSE](LICENSE)).
