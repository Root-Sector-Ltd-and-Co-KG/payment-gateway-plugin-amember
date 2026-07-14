<?php

declare(strict_types=1);

function prepareRelease(string $root, string $version): void
{
    if (!preg_match('/\A\d+\.\d+\.\d+\z/D', $version)) {
        throw new InvalidArgumentException('Release version must use the x.y.z format.');
    }

    $pluginPath = $root . '/payment-gateway-app.php';
    $readmePath = $root . '/README.md';
    $plugin = readReleaseFile($pluginPath);
    $readme = readReleaseFile($readmePath);

    $plugin = replaceReleaseValue(
        $plugin,
        "/const PLUGIN_REVISION = '[^']*';/",
        "const PLUGIN_REVISION = '{$version}';",
        'PLUGIN_REVISION'
    );
    $readme = replaceReleaseValue(
        $readme,
        '/\*\*Version:\*\* (?:dev|\d+\.\d+\.\d+)/',
        '**Version:** ' . $version,
        'README version'
    );

    $escapedVersion = preg_quote($version, '/');
    if (!preg_match('/^###\h+' . $escapedVersion . '\h*\R.*?(?=^###\h+|\z)/ms', $readme, $matches)) {
        throw new RuntimeException("README changelog entry for {$version} was not found.");
    }

    writeReleaseFile($pluginPath, $plugin);
    writeReleaseFile($readmePath, $readme);
    writeReleaseFile($root . '/RELEASE.md', rtrim($matches[0]) . PHP_EOL);
}

function readReleaseFile(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}.");
    }
    return $contents;
}

function replaceReleaseValue(string $contents, string $pattern, string $replacement, string $label): string
{
    $count = 0;
    $updated = preg_replace_callback(
        $pattern,
        static fn (): string => $replacement,
        $contents,
        -1,
        $count
    );
    if ($updated === null || $count !== 1) {
        throw new RuntimeException("Expected exactly one {$label} marker; found {$count}.");
    }
    return $updated;
}

function writeReleaseFile(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Unable to write {$path}.");
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        if ($argc !== 2) {
            throw new InvalidArgumentException('Usage: php scripts/prepare-release.php <x.y.z>');
        }
        prepareRelease(getcwd(), $argv[1]);
        fwrite(STDOUT, "Prepared release {$argv[1]}.\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
}
