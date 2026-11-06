<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Dispatcher;

use Override;
use Studoo\EduRoute\Dispatcher\Result\Matched;

use function count;
use function preg_match;

final class GroupPosBased extends RegexBasedAbstract
{
    /** @inheritDoc */
    #[Override]
    protected function dispatchVariableRoute(array $routeData, string $uri): ?Matched
    {
        foreach ($routeData as $data) {
            if (preg_match($data['regex'], $uri, $matches) !== 1) {
                continue;
            }

            // PCRE omet les groupes non utilisés situés après le dernier groupe utilisé : le dernier
            // indice de $matches est donc le dernier groupe de la route trouvée (tous ses groupes
            // participent, même s'ils capturent une chaîne vide comme {slug:[a-z]*} sur « /page/ »).
            // Les routes du chunk sont indexées par la position de leur dernier groupe.
            $last = count($matches) - 1;
            $route = $data['routeMap'][$last];
            $i = $last - count($route[1]);

            $vars = [];
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
