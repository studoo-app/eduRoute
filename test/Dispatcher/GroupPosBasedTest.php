<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Dispatcher;

use Override;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;

final class GroupPosBasedTest extends DispatcherTestCase
{
    #[Override]
    protected function getDispatcherClass(): string
    {
        return Dispatcher\GroupPosBased::class;
    }

    #[Override]
    protected function getDataGeneratorClass(): string
    {
        return DataGenerator\GroupPosBased::class;
    }
}
