<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;
use Studoo\EduRoute\Attribute\RouteGroup;

final class RouteGroupOnMethodController
{
    #[RouteGroup('/x')]
    #[Route('/a')]
    public function action(): string
    {
        return 'a';
    }
}
