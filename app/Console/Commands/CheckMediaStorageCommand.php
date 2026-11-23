<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class CheckMediaStorageCommand extends Command
{
    protected $signature = 'media:check {--disk=s3 : Disco a ser validado}';

    protected $description = 'Valida escrita, leitura e exclusão em um disco de mídia.';

    public function handle(): int
    {
        $disk = trim((string) $this->option('disk'));
        if (! array_key_exists($disk, (array) config('filesystems.disks', []))) {
            $this->error("Disco '{$disk}' não configurado.");

            return self::FAILURE;
        }

        $path = '_storage-healthchecks/'.now()->format('YmdHis').'-'.Str::uuid().'.txt';
        $payload = 'fluxo-repasse-storage-check:'.Str::random(32);
        $created = false;
        try {
            $storage = Storage::disk($disk);
            if (! $storage->put($path, $payload)) {
                throw new \RuntimeException('Falha na escrita.');
            }
            $created = true;
            if (! $storage->exists($path) || $storage->get($path) !== $payload) {
                throw new \RuntimeException('Falha na leitura/verificação.');
            }
            if (! $storage->delete($path)) {
                throw new \RuntimeException('Falha na exclusão.');
            }
            $created = false;
            $this->info("Disco '{$disk}' validado com sucesso.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Falha ao validar o storage: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($created) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable) {
                }
            }
        }
    }
}
