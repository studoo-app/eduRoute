<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

final class DuplicateRouteController
{
    #[Route('/same')]
    public function first(): string
    {
        return 'first';
    }

    #[Route('/same')]
    public function second(): string
    {
        return 'second';
    }
}
