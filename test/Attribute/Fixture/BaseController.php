<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

abstract class BaseController
{
    #[Route('/health')]
    public function health(): string
    {
        return 'ok';
    }
}
