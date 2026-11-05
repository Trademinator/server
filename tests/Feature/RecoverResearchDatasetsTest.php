<?php

use App\Domain\Research\DatasetStore;
use App\Models\HumanTrainingSnapshot;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function (): void {
    $this->researchRecoveryPath = sys_get_temp_dir().'/research-recovery-'.Str::uuid7();
    config(['research.path' => $this->researchRecoveryPath]);
});

afterEach(function (): void {
    File::deleteDirectory($this->researchRecoveryPath);
});

it('keeps the default recovery audit read only', function (): void {
    $manifest = IntelligenceFixtures::snapshot(3);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']).'/manifest.json';
    unlink($path);

    $this->artisan('trademinator:recover-research-datasets')
        ->expectsOutput('Dry run only. Use --apply to restore only manifests backed by checksum-valid rows.')
        ->assertSuccessful();

    expect(is_file($path))->toBeFalse();
    expect(app(DatasetStore::class)->inspectArtifact($manifest['dataset_id']))
        ->toMatchArray(['status' => 'manifest_missing', 'repairable' => true]);
});

it('restores a manifest only when the retained rows match the database digest', function (): void {
    $manifest = IntelligenceFixtures::snapshot(3);
    $directory = app(DatasetStore::class)->directory($manifest['dataset_id']);
    unlink($directory.'/manifest.json');

    $this->artisan('trademinator:recover-research-datasets', ['--apply' => true])
        ->expectsOutput('Repaired manifests: 1')
        ->assertSuccessful();

    expect(app(DatasetStore::class)->manifest($manifest['dataset_id'])['dataset_id'])->toBe($manifest['dataset_id']);
    expect(fileperms($directory.'/manifest.json') & 0777)->toBe(0600);
});

it('does not fabricate a manifest for missing or changed rows', function (bool $missing): void {
    $manifest = IntelligenceFixtures::snapshot(3);
    $directory = app(DatasetStore::class)->directory($manifest['dataset_id']);
    unlink($directory.'/manifest.json');
    if ($missing) {
        unlink($directory.'/rows.jsonl');
    } else {
        file_put_contents($directory.'/rows.jsonl', "{}\n", FILE_APPEND);
    }

    $this->artisan('trademinator:recover-research-datasets', ['--apply' => true])
        ->expectsOutput('Repaired manifests: 0')
        ->assertSuccessful();

    expect(is_file($directory.'/manifest.json'))->toBeFalse();
    expect(app(DatasetStore::class)->inspectArtifact($manifest['dataset_id'])['status'])
        ->toBe($missing ? 'rows_missing' : 'rows_invalid');
})->with([true, false]);

it('can restrict manifest recovery to datasets referenced by human training', function (): void {
    $human = IntelligenceFixtures::snapshot(3);
    $other = IntelligenceFixtures::snapshot(3);
    $store = app(DatasetStore::class);
    unlink($store->directory($human['dataset_id']).'/manifest.json');
    unlink($store->directory($other['dataset_id']).'/manifest.json');
    HumanTrainingSnapshot::factory()->create(['dataset_id' => $human['dataset_id']]);

    $this->artisan('trademinator:recover-research-datasets', ['--apply' => true, '--human-only' => true])
        ->expectsOutput('Repaired manifests: 1')
        ->assertSuccessful();

    expect(is_file($store->directory($human['dataset_id']).'/manifest.json'))->toBeTrue();
    expect(is_file($store->directory($other['dataset_id']).'/manifest.json'))->toBeFalse();
});
