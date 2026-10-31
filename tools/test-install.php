<?php

declare(strict_types=1);

require __DIR__ . '/build-release.php';
require __DIR__ . '/build-repository.php';

$root = dirname(__DIR__);
$temporary = temporaryDirectory('aiphra-install-');
$packages = ['core' => 'aiphra/core', 'caldav' => 'aiphra/caldav', 'google' => 'aiphra/google'];
$workspace = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$developmentVersions = $workspace['repositories'][0]['options']['versions'];
$stableVersion = str_replace('.x-dev', '.1', $developmentVersions['aiphra/core']);
try {
    buildRelease($stableVersion, $temporary . '/archives');
    buildRelease($stableVersion, $temporary . '/releases/v' . $stableVersion);
    $nextVersion = substr($stableVersion, 0, strrpos($stableVersion, '.') + 1) . '2';
    buildRelease($nextVersion, $temporary . '/releases/v' . $nextVersion);
    buildRepository($temporary . '/releases', $temporary . '/repository', 'file://' . $temporary . '/repository');
    foreach (['path', 'artifact', 'composer'] as $mode) {
        foreach ($packages as $suite => $name) {
            $directory = $temporary . '/' . $mode . '-' . $suite;
            mkdir($directory);
            $repository = match ($mode) {
                'path' => ['type' => 'path', 'url' => $root . '/packages/*', 'options' => ['symlink' => false, 'versions' => $developmentVersions]],
                'artifact' => ['type' => 'artifact', 'url' => $temporary . '/archives'],
                'composer' => ['type' => 'composer', 'url' => 'file://' . $temporary . '/repository'],
            };
            $manifest = [
                'name' => 'aiphra/installation-test',
                'type' => 'project',
                'version' => '1.0.0',
                'require' => [$name => $mode === 'path' ? $developmentVersions[$name] : ($mode === 'composer' ? '~' . $stableVersion : $stableVersion)],
                'minimum-stability' => $mode === 'path' ? 'dev' : 'stable',
                'repositories' => [$repository, ['packagist.org' => false]],
                'config' => ['allow-plugins' => false, 'cache-dir' => $temporary . '/cache'],
            ];
            file_put_contents($directory . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            echo "\nInstalling $name from $mode into a clean project\n";
            runCommand(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--no-scripts'], $directory);
            $environment = array_merge(getenv(), ['AIPHRA_AUTOLOAD' => $directory . '/vendor/autoload.php']);
            /**
             * Run the actual package scenarios against this consumer's autoloader.
             */
            runCommand([PHP_BINARY, $root . '/tests/run.php', $suite], $root, $environment);
            $expected = match ($suite) { 'core' => ['aiphra/core'], 'caldav' => ['aiphra/core', 'aiphra/caldav'], 'google' => array_values($packages) };
            $installed = json_decode(file_get_contents($directory . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
            $actual = array_column($installed['packages'], 'name');
            sort($actual);
            sort($expected);
            if ($expected !== $actual) { throw new RuntimeException('Unwanted or missing dependencies for ' . $name); }
            if ($mode === 'composer') {
                foreach ($installed['packages'] as $package) {
                    if ($package['version'] !== $nextVersion) { throw new RuntimeException('Composer did not select the newest release.'); }
                }
                runCommand(['composer', 'require', $name . ':' . $stableVersion, '--with-all-dependencies', '--no-interaction', '--no-progress', '--no-scripts'], $directory);
                $lock = json_decode(file_get_contents($directory . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
                foreach ($lock['packages'] as $package) {
                    if ($package['name'] === $name && $package['version'] !== $stableVersion) { throw new RuntimeException('An earlier release disappeared from the catalog.'); }
                }
            }
            if ($suite === 'core') {
                /**
                 * No curl, DOM or other dynamically loaded PHP extensions.
                 */
                runCommand([PHP_BINARY, '-n', $root . '/tests/run.php', 'core'], $root, $environment);
                runCommand([PHP_BINARY, '-r', 'require getenv("AIPHRA_AUTOLOAD"); if (class_exists("AiphraCore\\CalDav\\Client") || class_exists("AiphraCore\\Providers\\GoogleCalendar\\Provider")) { exit(1); }'], $root, $environment);
            } elseif ($suite === 'caldav') {
                runCommand([PHP_BINARY, '-r', 'require getenv("AIPHRA_AUTOLOAD"); if (class_exists("AiphraCore\\Providers\\GoogleCalendar\\Provider")) { exit(1); }'], $root, $environment);
            }
        }
    }
    echo "\nAll nine isolated installations passed, including version selection and core without optional PHP extensions.\n";
} finally {
    removeTemporaryDirectory($temporary);
}
