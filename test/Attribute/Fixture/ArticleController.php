<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

final class ArticleController
{
    use ArticleRoutes;

    #[Route('/articles')]
    public function index(): string
    {
        return 'index';
    }
}
