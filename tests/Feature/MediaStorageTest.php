<?php

namespace Tests\Feature;

use App\Services\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MediaStorageTest extends TestCase
{
    public function test_media_storage_writes_to_primary_disk_and_optional_mirror(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        config()->set('media.disk', 's3');
        config()->set('media.fallback_disk', 'local');
        config()->set('media.mirror_disk', 'local');

        $path = app(MediaStorage::class)->store(
            UploadedFile::fake()->create('documento.pdf', 20, 'application/pdf'),
            'repasse-evidence/10',
        );

        Storage::disk('s3')->assertExists($path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_media_storage_falls_back_to_local_for_files_not_yet_migrated(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        config()->set('media.disk', 's3');
        config()->set('media.fallback_disk', 'local');

        $path = 'repasse-evidence/10/old.pdf';
        Storage::disk('local')->put($path, 'old-content');

        $this->assertTrue(app(MediaStorage::class)->exists($path));
        $this->assertSame('local', app(MediaStorage::class)->diskFor($path));
        $this->assertTrue(app(MediaStorage::class)->verify($path, 11, hash('sha256', 'old-content')));
    }

    public function test_media_storage_delete_removes_primary_fallback_and_mirror(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        config()->set('media.disk', 's3');
        config()->set('media.fallback_disk', 'local');
        config()->set('media.mirror_disk', 'local');

        $path = 'avatars/10/avatar.jpg';
        Storage::disk('s3')->put($path, 's3-content');
        Storage::disk('local')->put($path, 'local-content');

        app(MediaStorage::class)->delete($path);

        Storage::disk('s3')->assertMissing($path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_media_sync_copies_files_without_removing_the_source(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        Storage::disk('local')->put('repasse-evidence/10/file.pdf', 'document');

        $this->artisan('media:sync', [
            '--from' => 'local',
            '--to' => 's3',
            '--verify' => true,
        ])->assertExitCode(0);

        Storage::disk('local')->assertExists('repasse-evidence/10/file.pdf');
        Storage::disk('s3')->assertExists('repasse-evidence/10/file.pdf');
    }
}
