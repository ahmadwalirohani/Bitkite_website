<?php

declare(strict_types=1);

namespace League\Flysystem;

use Generator;
use League\Flysystem\UrlGeneration\PrefixPublicUrlGenerator;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use Throwable;

class Filesystem implements FilesystemOperator
{
    private FilesystemAdapter $adapter;
    private Config $config;
    private PathNormalizer $pathNormalizer;
    private PublicUrlGenerator $publicUrlGenerator;

    public function __construct(
        FilesystemAdapter $adapter,
        array $config = [],
        PathNormalizer $pathNormalizer = null,
    ) {
        $this->adapter = $adapter;
        $this->config = new Config($config);
        $this->pathNormalizer = $pathNormalizer ?: new WhitespacePathNormalizer();
    }

    public function fileExists(string $location): bool
    {
        return $this->adapter->fileExists($this->pathNormalizer->normalizePath($location));
    }

    public function directoryExists(string $location): bool
    {
        return $this->adapter->directoryExists($this->pathNormalizer->normalizePath($location));
    }

    public function has(string $location): bool
    {
        $path = $this->pathNormalizer->normalizePath($location);

        return $this->adapter->fileExists($path) || $this->adapter->directoryExists($path);
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->adapter->write(
            $this->pathNormalizer->normalizePath($location),
            $contents,
            $this->config->extend($config)
        );
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        /* @var resource $contents */
        $this->assertIsResource($contents);
        $this->rewindStream($contents);
        $this->adapter->writeStream(
            $this->pathNormalizer->normalizePath($location),
            $contents,
            $this->config->extend($config)
        );
    }

    public function read(string $location): string
    {
        return $this->adapter->read($this->pathNormalizer->normalizePath($location));
    }

    public function readStream(string $location)
    {
        return $this->adapter->readStream($this->pathNormalizer->normalizePath($location));
    }

    public function delete(string $location): void
    {
        $this->adapter->delete($this->pathNormalizer->normalizePath($location));
    }

    public function deleteDirectory(string $location): void
    {
        $this->adapter->deleteDirectory($this->pathNormalizer->normalizePath($location));
    }

    public function createDirectory(string $location, array $config = []): void
    {
        $this->adapter->createDirectory(
            $this->pathNormalizer->normalizePath($location),
            $this->config->extend($config)
        );
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $path = $this->pathNormalizer->normalizePath($location);
        $listing = $this->adapter->listContents($path, $deep);

        return new DirectoryListing($this->pipeListing($location, $deep, $listing));
    }

    private function pipeListing(string $location, bool $deep, iterable $listing): Generator
    {
        try {
            foreach ($listing as $item) {
                yield $item;
            }
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($location, $deep, $exception);
        }
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        $this->adapter->move(
            $this->pathNormalizer->normalizePath($source),
            $this->pathNormalizer->normalizePath($destination),
            $this->config->extend($config)
        );
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->adapter->copy(
            $this->pathNormalizer->normalizePath($source),
            $this->pathNormalizer->normalizePath($destination),
            $this->config->extend($config)
        );
    }

    public function lastModified(string $path): int
    {
        return $this->adapter->lastModified($this->pathNormalizer->normalizePath($path))->lastModified();
    }

    public function fileSize(string $path): int
    {
        return $this->adapter->fileSize($this->pathNormalizer->normalizePath($path))->fileSize();
    }

    public function mimeType(string $path): string
    {
        return $this->adapter->mimeType($this->pathNormalizer->normalizePath($path))->mimeType();
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->adapter->setVisibility($this->pathNormalizer->normalizePath($path), $visibility);
    }

    public function visibility(string $path): string
    {
        return $this->adapter->visibility($this->pathNormalizer->normalizePath($path))->visibility();
    }

    public function publicUrl(string $path, array $config = []): string
    {
        $this->publicUrlGenerator ??= $this->resolvePublicUrlGenerator()
            ?: throw UnableToGeneratePublicUrl::noGeneratorConfigured($path);
        $config = $this->config->extend($config);

        return $this->publicUrlGenerator->publicUrl($path, $config);
    }

    private function resolvePublicUrlGenerator(): ?PublicUrlGenerator
    {
        if ($publicUrl = $this->config->get('public_url')) {
            return new PrefixPublicUrlGenerator($publicUrl);
        }

        if ($this->adapter instanceof PublicUrlGenerator) {
            return $this->adapter;
        }

        return null;
    }

    /**
     * @param mixed $contents
     */
    private function assertIsResource($contents): void
    {
        if (is_resource($contents) === false) {
            throw new InvalidStreamProvided(
                "Invalid stream provided, expected stream resource, received " . gettype($contents)
            );
        } elseif ($type = get_resource_type($contents) !== 'stream') {
            throw new InvalidStreamProvided(
                "Invalid stream provided, expected stream resource, received resource of type " . $type
            );
        }
    }

    /**
     * @param resource $resource
     */
    private function rewindStream($resource): void
    {
        if (ftell($resource) !== 0 && stream_get_meta_data($resource)['seekable']) {
            rewind($resource);
        }
    }
}
