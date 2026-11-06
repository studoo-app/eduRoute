<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Dispatcher;

use Override;
use Studoo\EduRoute\Dispatcher\Result\Matched;

use function assert;
use function end;
use function preg_match;

final class CharCountBased extends RegexBasedAbstract
{
    /** @inheritDoc */
    #[Override]
    protected function dispatchVariableRoute(array $routeData, string $uri): ?Matched
    {
        foreach ($routeData as $data) {
            assert(isset($data['suffix']));

            if (preg_match($data['regex'], $uri . $data['suffix'], $matches) !== 1) {
                continue;
            }

            $route = $data['routeMap'][end($matches)];

            $vars = [];
            $i = 0;
            foreach ($route[1] as $varName) {
                $vars[$varName] = $matches[++$i];
            }

            $result = new Matched();
            $result->handler = $route[0];
            $result->variables = $vars;
            $result->extraParameters = $route[2];

            return $result;
        }

        return null;
    }
}
