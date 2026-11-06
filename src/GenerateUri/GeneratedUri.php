<?php
declare(strict_types=1);

namespace FastRoute\GenerateUri;

use FastRoute\GenerateUri;
use Override;
use Psr\Http\Message\UriInterface;
use Stringable;

use function http_build_query;

/** @phpstan-import-type UriSubstitutions from GenerateUri */
final readonly class GeneratedUri implements Stringable
{
    /**
     * @param non-empty-string $path
     * @param UriSubstitutions $unmatchedSubstitutions
     */
    public function __construct(
        public string $path,
        public array $unmatchedSubstitutions,
    ) {
    }

    public function asUri(UriInterface $baseUri): UriInterface
    {
        return $baseUri
            ->withPath($this->path)
            ->withQuery(http_build_query($this->unmatchedSubstitutions));
    }

    #[Override]
    public function __toString(): string
    {
        return $this->path;
    }
}
