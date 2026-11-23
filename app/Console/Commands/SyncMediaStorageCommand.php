<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class SyncMediaStorageCommand extends Command
{
    protected $signature = 'media:sync
        {--from=local : Disco de origem}
        {--to=s3 : Disco de destino}
        {--prefix= : Limita a sincronização a um prefixo}
        {--dry-run : Apenas informa o que seria copiado}
        {--verify : Confere existência e tamanho no destino}';

    protected $description = 'Copia mídia entre discos sem remover a origem.';

    public function handle(): int
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $prefix = trim((string) $this->option('prefix'), '/');
        $dryRun = (bool) $this->option('dry-run');
        $verify = (bool) $this->option('verify');
        $configured = (array) config('filesystems.disks', []);
        if ($from === '' || $to === '' || $from === $to
            || ! array_key_exists($from, $configured) || ! array_key_exists($to, $configured)) {
            $this->error('Informe dois discos diferentes e configurados.');

            return self::FAILURE;
        }

        try {
            $files = Storage::disk($from)->allFiles($prefix);
        } catch (Throwable $exception) {
            $this->error('Não foi possível listar a origem: '.$exception->getMessage());

            return self::FAILURE;
        }

        $copied = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($files as $path) {
            try {
                $sourceSize = Storage::disk($from)->size($path);
                $ready = Storage::disk($to)->exists($path)
                    && Storage::disk($to)->size($path) === $sourceSize;
                if ($ready) {
                    $skipped++;

                    continue;
                }
                if ($dryRun) {
                    $this->line("COPIAR: {$path}");

                    continue;
                }
                $stream = Storage::disk($from)->readStream($path);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Origem não abriu o stream.');
                }
                try {
                    if (! Storage::disk($to)->writeStream($path, $stream)) {
                        throw new RuntimeException('Destino recusou o arquivo.');
                    }
                } finally {
                    fclose($stream);
                }
                if ($verify && (! Storage::disk($to)->exists($path)
                    || Storage::disk($to)->size($path) !== $sourceSize
                    || $this->sha256($from, $path) !== $this->sha256($to, $path))) {
                    throw new RuntimeException('Verificação pós-cópia falhou.');
                }
                $copied++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error("FALHA {$path}: {$exception->getMessage()}");
            }
        }

        $this->line('Arquivos encontrados: '.count($files));
        $this->line("Copiados: {$copied} | Já existentes: {$skipped} | Falhas: {$failed}");
        if ($dryRun) {
            $this->warn('DRY-RUN: nenhum arquivo foi gravado.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sha256(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Não foi possível abrir o arquivo para calcular o hash.');
        }

        $hash = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Falha ao ler o arquivo para calcular o hash.');
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        return hash_final($hash);
    }
}
