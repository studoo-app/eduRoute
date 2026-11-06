<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

#[Route('/blog')]
final class ClassLevelRouteController
{
    #[Route('/list')]
    public function list(): string
    {
        return 'list';
    }
}
