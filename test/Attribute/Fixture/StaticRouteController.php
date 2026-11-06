<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

final class StaticRouteController
{
    #[Route('/static')]
    public static function shared(): string
    {
        return 'static';
    }
}
