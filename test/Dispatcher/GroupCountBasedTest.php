<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Dispatcher;

use Override;
use Studoo\EduRoute\DataGenerator\GroupCountBased;
use Studoo\EduRoute\Dispatcher;

final class GroupCountBasedTest extends DispatcherTestCase
{
    #[Override]
    protected function getDispatcherClass(): string
    {
        return Dispatcher\GroupCountBased::class;
    }

    #[Override]
    protected function getDataGeneratorClass(): string
    {
        return GroupCountBased::class;
    }
}
