<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Dispatcher;

use Override;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\RegexMatchingFailed;

use function count;
use function preg_last_error_msg;
use function preg_match;

final class GroupCountBased extends RegexBasedAbstract
{
    /** @inheritDoc */
    #[Override]
    protected function dispatchVariableRoute(array $routeData, string $uri): ?Matched
    {
        foreach ($routeData as $data) {
            $matched = preg_match($data['regex'], $uri, $matches);
            if ($matched === false) {
                throw RegexMatchingFailed::whileDispatching($uri, preg_last_error_msg());
            }

            if ($matched === 0) {
                continue;
            }

            $route = $data['routeMap'][count($matches)];

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
