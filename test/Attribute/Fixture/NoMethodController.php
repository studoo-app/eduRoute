<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

final class NoMethodController
{
    #[Route('/none', methods: [])]
    public function action(): string
    {
        return 'none';
    }
}
