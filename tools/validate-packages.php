<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

$root = dirname(__DIR__);
runCommand(['composer', 'validate', '--strict'], $root);
$allowed = [
    'aiphra/core' => [],
    'aiphra/caldav' => ['aiphra/core'],
    'aiphra/google' => ['aiphra/core', 'aiphra/caldav'],
];
$found = [];
foreach (glob($root . '/packages/*/composer.json') as $path) {
    $package = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $name = $package['name'];
    if (!array_key_exists($name, $allowed) || isset($found[$name])) { throw new RuntimeException('Unexpected or duplicate package: ' . $name); }
    $found[$name] = true;
    if (isset($package['repositories']) || isset($package['version'])) { throw new RuntimeException('Published manifests must not contain local repositories or fixed versions.'); }
    $internal = array_values(array_filter(array_keys($package['require']), fn (string $dependency): bool => str_starts_with($dependency, 'aiphra/')));
    sort($internal);
    $expected = $allowed[$name];
    sort($expected);
    if ($internal !== $expected) { throw new RuntimeException('Incorrect package dependencies for ' . $name); }
    runCommand(['composer', 'validate', '--strict'], dirname($path));
}
if (count($found) !== count($allowed)) { throw new RuntimeException('A package is missing.'); }
echo "All package manifests and dependency boundaries are valid.\n";
