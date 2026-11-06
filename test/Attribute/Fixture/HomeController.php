<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

final class HomeController
{
    #[Route('/', name: 'home')]
    public function index(): string
    {
        return 'home';
    }
}
