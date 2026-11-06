<?php
declare(strict_types=1);

namespace FastRoute\Test\Dispatcher;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\Dispatcher;
use Override;

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
