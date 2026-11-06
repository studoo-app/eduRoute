<?php
declare(strict_types=1);

namespace Studoo\EduRoute\DataGenerator;

use Override;

use function count;
use function implode;

final class GroupPosBased extends RegexBasedAbstract
{
    #[Override]
    protected function getApproxChunkSize(): int
    {
        return 10;
    }

    /** @inheritDoc */
    #[Override]
    protected function processChunk(array $regexToRoutesMap): array
    {
        $routeMap = [];
        $regexes = [];
        $offset = 1;
        foreach ($regexToRoutesMap as $regex => $route) {
            $regexes[] = $regex;
            // Indexée par la position du DERNIER groupe de la route (voir Dispatcher\GroupPosBased)
            $offset += count($route->variables);
            $routeMap[$offset - 1] = [$route->handler, $route->variables, $route->extraParameters];
        }

        $regex = '~^(?:' . implode('|', $regexes) . ')$~';

        return ['regex' => $regex, 'routeMap' => $routeMap];
    }
}
