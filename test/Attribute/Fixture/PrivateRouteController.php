<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

final class PrivateRouteController
{
    #[Route('/private')]
    private function hidden(): string
    {
        return 'hidden';
    }
}
