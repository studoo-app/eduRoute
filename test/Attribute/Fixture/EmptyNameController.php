<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;

final class EmptyNameController
{
    #[Route('/empty', name: '')] // @phpstan-ignore argument.type (nom vide volontaire)
    public function action(): string
    {
        return 'empty';
    }
}
