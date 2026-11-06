<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Dispatcher;

use ArrayObject;
use Override;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\Dispatcher\MarkBased;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\HttpMethod;

/**
 * Dispatcher maison qui hérite d'une classe interne (ArrayObject) : PHP refuse d'en faire un
 * lazy object, EduRoute::lazyDispatcher() doit alors le construire immédiatement.
 *
 * @phpstan-import-type RouteData from DataGenerator
 * @extends ArrayObject<int, mixed>
 */
final class InternalParentDispatcher extends ArrayObject implements Dispatcher
{
    private readonly MarkBased $inner;

    /** @param RouteData $data */
    public function __construct(array $data)
    {
        parent::__construct();

        $this->inner = new MarkBased($data);
    }

    #[Override]
    public function dispatch(HttpMethod|string $httpMethod, string $uri): Matched|NotMatched|MethodNotAllowed
    {
        return $this->inner->dispatch($httpMethod, $uri);
    }
}
