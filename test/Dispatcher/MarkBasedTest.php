<?php
declare(strict_types=1);

namespace FastRoute\Test\Dispatcher;

use FastRoute\DataGenerator;
use FastRoute\Dispatcher;
use Override;

final class MarkBasedTest extends DispatcherTestCase
{
    #[Override]
    protected function getDispatcherClass(): string
    {
        return Dispatcher\MarkBased::class;
    }

    #[Override]
    protected function getDataGeneratorClass(): string
    {
        return DataGenerator\MarkBased::class;
    }
}
