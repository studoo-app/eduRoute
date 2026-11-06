<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute\Fixture;

use Studoo\EduRoute\Attribute\Route;
use Studoo\EduRoute\Attribute\RouteGroup;
use Studoo\EduRoute\HttpMethod;

#[RouteGroup('/admin/product', namePrefix: 'admin.product.')]
final class ProductController
{
    #[Route('', name: 'index')]
    public function index(): string
    {
        return 'index';
    }

    #[Route('/{id:\d+}', name: 'show', extra: ['section' => 'catalogue'])]
    public function show(): string
    {
        return 'show';
    }

    #[Route('/{id:\d+}', methods: [HttpMethod::Put, 'PATCH'], name: 'update')]
    #[Route('/{id:\d+}/edit', methods: HttpMethod::Post)]
    public function update(): string
    {
        return 'update';
    }

    public function helperWithoutRoute(): string
    {
        return 'not routed';
    }
}
