<?php
declare(strict_types=1);

namespace FastRoute\Dispatcher;

use FastRoute\Dispatcher\Result\Matched;
use Override;

use function preg_match;

/** @final */
class GroupPosBased extends RegexBasedAbstract
{
    /** @inheritDoc */
    #[Override]
    protected function dispatchVariableRoute(array $routeData, string $uri): ?Matched
    {
        foreach ($routeData as $data) {
            if (preg_match($data['regex'], $uri, $matches) !== 1) {
                continue;
            }

            // Position du premier groupe capturant non vide = index de la route dans le chunk
            $i = 1;
            while ($matches[$i] === '') {
                ++$i;
            }

            [$handler, $varNames, $extraParameters] = $data['routeMap'][$i];

            $vars = [];
            foreach ($varNames as $varName) {
                $vars[$varName] = $matches[$i++];
            }

            $result = new Matched();
            $result->handler = $handler;
            $result->variables = $vars;
            $result->extraParameters = $extraParameters;

            return $result;
        }

        return null;
    }
}
