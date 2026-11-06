<?php
declare(strict_types=1);

namespace FastRoute\Cache;

use Closure;
use FastRoute\Cache;
use FastRoute\ConfigureRoutes;
use Override;
use RuntimeException;

use function chmod;
use function dirname;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_writable;
use function mkdir;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function unlink;
use function var_export;

use const LOCK_EX;

/** @phpstan-import-type ProcessedData from ConfigureRoutes */
final class FileCache implements Cache
{
    private const int DIRECTORY_PERMISSIONS = 0775;
    private const int FILE_PERMISSIONS = 0664;

    /**
     * This is cached in a local static variable to avoid instantiating a closure each time we need an empty handler
     */
    private static Closure $emptyErrorHandler;

    public function __construct()
    {
        self::$emptyErrorHandler ??= static function (): void {
        };
    }

    /** @inheritdoc */
    #[Override]
    public function get(string $key, callable $loader): array
    {
        $result = $this->readFileContents($key);

        if ($result !== null) {
            return $result;
        }

        $data = $loader();
        $this->writeToFile($key, '<?php return ' . var_export($data, true) . ';');

        return $data;
    }

    /** @return ProcessedData|null */
    private function readFileContents(string $path): ?array
    {
        // error suppression is faster than calling `file_exists()` + `is_file()` + `is_readable()`, especially because there's no need to error here
        set_error_handler(self::$emptyErrorHandler);
        $value = include $path;
        restore_error_handler();

        if (! is_array($value)) {
            return null;
        }

        // @phpstan-ignore-next-line because we won´t be able to validate the array shape in a performant way
        return $value;
    }

    private function writeToFile(string $path, string $content): void
    {
        $directory = dirname($path);

        if (! $this->createDirectoryIfNeeded($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The cache directory is not writable "' . $directory . '"');
        }

        set_error_handler(self::$emptyErrorHandler);

        $tmpFile = $path . '.tmp';

        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            restore_error_handler();

            return;
        }

        chmod($tmpFile, self::FILE_PERMISSIONS);

        if (! rename($tmpFile, $path)) {
            unlink($tmpFile);
        }

        restore_error_handler();
    }

    private function createDirectoryIfNeeded(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        set_error_handler(self::$emptyErrorHandler);
        $created = mkdir($directory, self::DIRECTORY_PERMISSIONS, true);
        restore_error_handler();

        return $created || is_dir($directory);
    }
}
