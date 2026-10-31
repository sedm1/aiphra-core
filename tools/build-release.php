<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

/**
 * @return list<string>
 */
function buildRelease(string $version, string $output): array {
    $version = releaseVersion($version);
    if (!class_exists(ZipArchive::class)) { throw new RuntimeException('Release archives require ext-zip.'); }
    $root = dirname(__DIR__);
    $workspace = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $archives = [];
    if (!is_dir($output) && !mkdir($output, 0755, true)) { throw new RuntimeException('Cannot create output directory.'); }
    foreach (glob($root . '/packages/*/composer.json') as $manifest) {
        $directory = dirname($manifest);
        $package = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $developmentVersion = $workspace['repositories'][0]['options']['versions'][$package['name']];
        if (implode('.', array_slice(explode('.', $version), 0, 2)) . '.x-dev' !== $developmentVersion) {
            throw new RuntimeException('Update the development version line and internal constraints before releasing ' . $version);
        }
        /**
         * Artifact repositories need an explicit version inside the archive.
         */
        $package['version'] = $version;
        $archive = $output . '/' . str_replace('/', '-', $package['name']) . '-' . $version . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create archive: ' . $archive);
        }
        $zip->addFromString('composer.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->isLink()) { throw new RuntimeException('Unexpected source entry: ' . $file->getPathname()); }
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($directory) + 1));
        }
        foreach (['LICENSE', 'LICENSE.md'] as $file) {
            if (is_file($directory . '/' . $file)) { $zip->addFile($directory . '/' . $file, $file); }
        }
        if (!$zip->close()) { throw new RuntimeException('Cannot finish archive: ' . $archive); }
        $archives[] = $archive;
    }
    $checksums = '';
    foreach ($archives as $archive) { $checksums .= hash_file('sha256', $archive) . '  ' . basename($archive) . "\n"; }
    file_put_contents($output . '/SHA256SUMS', $checksums);
    return $archives;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $version = releaseVersion($argv[1] ?? '');
        $output = $argv[2] ?? dirname(__DIR__) . '/dist/v' . $version;
        foreach (buildRelease($version, $output) as $archive) { echo $archive . "\n"; }
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\nUsage: composer release:build -- 0.0.1 [output-directory]\n");
        exit(1);
    }
}
