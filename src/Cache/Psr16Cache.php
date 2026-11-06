<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Cache;

use Closure;
use Override;
use Psr\SimpleCache\CacheInterface;
use Studoo\EduRoute\Cache;

use function count;
use function is_array;

final readonly class Psr16Cache implements Cache
{
    public function __construct(private CacheInterface $cache)
    {
    }

    /** @inheritDoc */
    #[Override]
    public function get(string $key, Closure $loader): array
    {
        $result = $this->cache->get($key);

        // Entrée absente ou d'un autre format (ex. cache FastRoute 1.x à 2 entrées) : on régénère
        if (is_array($result) && count($result) === 3) {
            // @phpstan-ignore-next-line because we won´t be able to validate the array shape in a performant way
            return $result;
        }

        $data = $loader();
        $this->cache->set($key, $data);

        return $data;
    }
}
