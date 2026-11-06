<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Handler PSR-15 de test : mémorise la requête reçue et répond 200. */
final class RequestSpy implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public function __construct(private readonly Psr17Factory $factory)
    {
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return $this->factory->createResponse(200);
    }
}
