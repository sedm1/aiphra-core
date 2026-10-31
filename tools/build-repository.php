<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function buildRepository(string $archives, string $output, string $url): void {
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http', 'file'], true)) {
        throw new InvalidArgumentException('Expected an absolute repository URL.');
    }
    $packages = [];
    foreach (glob($archives . '/*/*.zip') as $archive) {
        $version = releaseVersion(basename(dirname($archive)));
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) { throw new RuntimeException('Cannot read archive: ' . $archive); }
        $manifest = $zip->getFromName('composer.json');
        $zip->close();
        if (!$manifest) { throw new RuntimeException('Archive has no root composer.json: ' . $archive); }
        $package = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        $name = $package['name'] ?? '';
        if (!preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $name) || ($package['version'] ?? null) !== $version) {
            throw new RuntimeException('Incorrect package name or version in ' . $archive);
        }
        $filename = str_replace('/', '-', $name) . '-' . $version . '.zip';
        if (basename($archive) !== $filename || isset($packages[$name][$version])) {
            throw new RuntimeException('Unexpected or duplicate archive: ' . $archive);
        }
        $relative = 'dist/v' . $version . '/' . $filename;
        $directory = $output . '/dist/v' . $version;
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) { throw new RuntimeException('Cannot create archive directory.'); }
        if (!copy($archive, $output . '/' . $relative)) { throw new RuntimeException('Cannot copy archive: ' . $archive); }
        $package['dist'] = ['type' => 'zip', 'url' => rtrim($url, '/') . '/' . $relative, 'shasum' => hash_file('sha1', $archive)];
        $packages[$name][$version] = $package;
    }
    if (!$packages) { throw new RuntimeException('No released package archives found.'); }
    ksort($packages);
    foreach ($packages as &$versions) { uksort($versions, fn (string $left, string $right): int => version_compare($right, $left)); }
    unset($versions);
    file_put_contents($output . '/packages.json', json_encode(['packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $links = '';
    foreach ($packages as $name => $versions) {
        $links .= '<li><code>' . htmlspecialchars($name) . '</code>: ' . implode(', ', array_keys($versions)) . '</li>' . "\n";
    }
    $html = str_replace(['{{url}}', '{{packages}}'], [htmlspecialchars(rtrim($url, '/'), ENT_QUOTES), $links], file_get_contents(__DIR__ . '/repository.html'));
    file_put_contents($output . '/index.html', $html);
    file_put_contents($output . '/.nojekyll', '');
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $arguments = $argv ?? [];
        if (count($arguments) !== 4) { throw new InvalidArgumentException('Expected archives directory, output directory and public URL.'); }
        buildRepository($arguments[1], $arguments[2], $arguments[3]);
        echo 'Composer repository built in ' . $arguments[2] . "\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\nUsage: composer repository:build -- archives output https://packages.example.org\n");
        exit(1);
    }
}
