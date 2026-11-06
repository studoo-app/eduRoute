<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

trait ArticleRoutes
{
    #[Route('/articles/{id:\d+}')]
    public function show(): string
    {
        return 'show';
    }
}
