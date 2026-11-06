<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Cache;

use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Studoo\EduRoute\Cache\Psr16Cache;

final class Psr16CacheTest extends TestCase
{
    #[PHPUnit\Test]
    public function cacheShouldOnlyBeSetOnMiss(): void
    {
        $data = [];

        $generatedData = [['GET' => ['/' => ['test', []]]], [], []];

        $adapter = new Psr16Cache($this->createDummyCache($data));
        $result = $adapter->get('test', static fn (): array => $generatedData);

        self::assertSame($generatedData, $result);
        self::assertSame($generatedData, $data['test']);

        // Try again, now with a different callback
        $result = $adapter->get('test', static fn (): array => [['POST' => ['/' => ['test', []]]], [], []]);

        self::assertSame($generatedData, $result);
    }

    /** @param array<string, mixed> $data */
    private function createDummyCache(array &$data): CacheInterface
    {
        $cache = self::createStub(CacheInterface::class);

        $cache->method('get')
            ->willReturnCallback(
                static function (string $key, mixed $default) use (&$data): mixed {
                    return $data[$key] ?? $default;
                },
            );

        $cache->method('set')
            ->willReturnCallback(
                static function (string $key, mixed $value) use (&$data): bool {
                    $data[$key] = $value;

                    return true;
                },
            );

        return $cache;
    }

    #[PHPUnit\Test]
    public function entryFromAnotherFormatIsRegenerated(): void
    {
        $data = ['test' => [['GET' => ['/' => 'old-handler']], []]]; // format FastRoute 1.x
        $generatedData = [['GET' => ['/' => ['test', []]]], [], []];

        $result = new Psr16Cache($this->createDummyCache($data))->get('test', static fn (): array => $generatedData);

        self::assertSame($generatedData, $result);
        self::assertSame($generatedData, $data['test']);
    }
}
