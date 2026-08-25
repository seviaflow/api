<?php

declare(strict_types=1);

it('api depends on core only and does not require UI or Livewire', function (): void {
    $root = dirname(__DIR__, 3);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

    $requirements = $composer['require'] ?? [];

    expect($requirements)->toHaveKey('seviaflow/core');
    expect(array_key_exists('seviaflow/ui', $requirements))->toBeFalse();
    expect(array_key_exists('livewire/livewire', $requirements))->toBeFalse();
    expect(array_key_exists('seviaflow/devtools', $requirements))->toBeFalse();

    $productionText = scanPackageText($root, ['src', 'routes', 'resources', 'config']);

    expect(str_contains($productionText, 'SeviaFlow\\Ui'))->toBeFalse();
    expect(str_contains($productionText, 'Livewire\\'))->toBeFalse();
});

function scanPackageText(string $root, array $directories): string
{
    $buffer = '';

    foreach ($directories as $directory) {
        $path = $root.'/'.$directory;
        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $buffer .= (string) file_get_contents($file->getPathname());
            }
        }
    }

    return $buffer;
}
