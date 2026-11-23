<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class MediaStorage
{
    public function disk(): string
    {
        return (string) config('media.disk', 'local');
    }

    public function fallbackDisk(): ?string
    {
        $disk = trim((string) config('media.fallback_disk', ''));

        return $disk !== '' ? $disk : null;
    }

    /** @return list<string> */
    public function fallbackDisks(): array
    {
        $disks = config('media.fallback_disks');
        if (! is_array($disks)) {
            $disks = [$this->fallbackDisk()];
        }

        return array_values(array_filter(array_map(
            static fn ($disk): string => trim((string) $disk),
            $disks
        )));
    }

    public function mirrorDisk(): ?string
    {
        $disk = trim((string) config('media.mirror_disk', ''));

        return $disk !== '' ? $disk : null;
    }

    public function store(UploadedFile $file, string $directory, ?string $filename = null): string
    {
        $primary = $this->disk();
        $filename ??= $file->hashName();
        $path = $file->storeAs($directory, $filename, $primary);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException("Não foi possível salvar o arquivo no disco {$primary}.");
        }

        $this->mirror($path, $primary);

        return $path;
    }

    /** @param array<string, mixed> $options */
    public function put(string $path, string $contents, array $options = []): void
    {
        $primary = $this->disk();
        if (! Storage::disk($primary)->put($path, $contents, $options)) {
            throw new RuntimeException("Não foi possível salvar {$path} no disco {$primary}.");
        }

        $this->mirror($path, $primary);
    }

    /** @param resource $stream */
    public function putStream(string $path, $stream): void
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('O stream do arquivo não é válido.');
        }

        $primary = $this->disk();
        if (! Storage::disk($primary)->writeStream($path, $stream)) {
            throw new RuntimeException("Não foi possível salvar {$path} no disco {$primary}.");
        }

        $this->mirror($path, $primary);
    }

    public function exists(string $path, ?string $preferredDisk = null): bool
    {
        return $this->findDisk($path, $preferredDisk) !== null;
    }

    public function diskFor(string $path, ?string $preferredDisk = null): string
    {
        $disk = $this->findDisk($path, $preferredDisk);
        if ($disk === null) {
            throw new RuntimeException("Arquivo de mídia não localizado: {$path}");
        }

        return $disk;
    }

    public function size(string $path, ?string $preferredDisk = null): int
    {
        return (int) Storage::disk($this->diskFor($path, $preferredDisk))->size($path);
    }

    public function mimeType(string $path, ?string $preferredDisk = null): ?string
    {
        return Storage::disk($this->diskFor($path, $preferredDisk))->mimeType($path) ?: null;
    }

    /** @return resource */
    public function readStream(string $path, ?string $preferredDisk = null)
    {
        $stream = Storage::disk($this->diskFor($path, $preferredDisk))->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Não foi possível abrir o arquivo de mídia: {$path}");
        }

        return $stream;
    }

    public function verify(string $path, int $expectedSize, string $expectedSha256, ?string $preferredDisk = null): bool
    {
        $disk = $this->findDisk($path, $preferredDisk);
        if ($disk === null) {
            return false;
        }
        if ((int) Storage::disk($disk)->size($path) !== $expectedSize) {
            return false;
        }

        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            return false;
        }

        $hash = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    return false;
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        return hash_equals(strtolower($expectedSha256), hash_final($hash));
    }

    public function delete(string $path, ?string $preferredDisk = null): void
    {
        $disks = array_values(array_unique(array_filter([
            $preferredDisk,
            $this->disk(),
            ...$this->fallbackDisks(),
            $this->mirrorDisk(),
        ])));

        foreach ($disks as $disk) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Falha ao remover mídia de um dos discos.', [
                    'disk' => $disk, 'path' => $path, 'exception' => $exception,
                ]);
            }
        }
    }

    public function url(?string $path, ?string $disk = null): ?string
    {
        if (! $path) {
            return null;
        }
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $resolved = $disk ?: $this->diskFor($path);
        if ($resolved === 's3' && config('media.url_mode') === 'temporary') {
            try {
                $minutes = max(1, (int) config('media.temporary_url_minutes', 60));
                $cacheMinutes = max(0, min(
                    (int) config('media.temporary_url_cache_minutes', max(1, $minutes - 5)),
                    $minutes - 1,
                ));
                $key = 'media:temporary-url:'.$resolved.':'.sha1($path);
                $make = fn (): string => Storage::disk($resolved)->temporaryUrl($path, now()->addMinutes($minutes));

                return $cacheMinutes > 0
                    ? Cache::remember($key, now()->addMinutes($cacheMinutes), $make)
                    : $make();
            } catch (Throwable $exception) {
                Log::warning('Falha ao gerar URL temporária de mídia.', [
                    'disk' => $resolved, 'path' => $path, 'exception' => $exception,
                ]);
            }
        }

        return Storage::disk($resolved)->url($path);
    }

    public function response(string $path, array $headers = []): Response
    {
        $disk = $this->diskFor($path);
        $storage = Storage::disk($disk);
        $stream = $storage->readStream($path);
        if (! is_resource($stream)) {
            abort(404);
        }
        $size = $storage->size($path);
        $mime = $storage->mimeType($path) ?: 'application/octet-stream';

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, array_merge([
            'Cache-Control' => 'public, max-age=86400',
            'Content-Length' => (string) $size,
            'Content-Type' => $mime,
        ], $headers));
    }

    public function download(string $path, string $name, array $headers = []): StreamedResponse
    {
        $storage = Storage::disk($this->diskFor($path));
        $stream = $storage->readStream($path);
        if (! is_resource($stream)) {
            abort(404);
        }
        $size = $storage->size($path);
        $mime = $storage->mimeType($path) ?: 'application/octet-stream';

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $name, array_merge([
            'Content-Length' => (string) $size,
            'Content-Type' => $mime,
        ], $headers));
    }

    private function findDisk(string $path, ?string $preferredDisk = null): ?string
    {
        $disks = array_values(array_unique(array_filter([
            $preferredDisk,
            $this->disk(),
            ...$this->fallbackDisks(),
            $this->mirrorDisk(),
        ])));

        foreach ($disks as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return $disk;
                }
            } catch (Throwable $exception) {
                Log::warning('Falha ao consultar mídia em um dos discos.', [
                    'disk' => $disk, 'path' => $path, 'exception' => $exception,
                ]);
            }
        }

        return null;
    }

    private function mirror(string $path, string $sourceDisk): void
    {
        $mirror = $this->mirrorDisk();
        if (! $mirror || $mirror === $sourceDisk) {
            return;
        }

        $stream = null;
        try {
            $stream = Storage::disk($sourceDisk)->readStream($path);
            if (! is_resource($stream)) {
                throw new RuntimeException("Não foi possível abrir {$path} para espelhamento.");
            }
            if (! Storage::disk($mirror)->writeStream($path, $stream)) {
                throw new RuntimeException("Não foi possível espelhar {$path} no disco {$mirror}.");
            }
        } catch (Throwable $exception) {
            Log::warning('Falha no espelhamento de mídia; o primário foi preservado.', [
                'source_disk' => $sourceDisk, 'mirror_disk' => $mirror,
                'path' => $path, 'exception' => $exception,
            ]);
            if (! (bool) config('media.mirror_fail_open', true)) {
                throw $exception;
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
