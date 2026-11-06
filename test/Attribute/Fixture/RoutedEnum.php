<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

enum RoutedEnum
{
    case Only;

    #[Route('/enum')]
    public function action(): string
    {
        return 'enum';
    }
}
