<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/prepare-release.php';

function releaseAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function releaseAssertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, $message . "\nMissing: " . $needle . "\n");
        exit(1);
    }
}

function releaseAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        fwrite(STDERR, $message . "\nUnexpected: " . $needle . "\n");
        exit(1);
    }
}

$temporaryRoot = sys_get_temp_dir() . '/payment-gateway-app-release-' . bin2hex(random_bytes(6));
mkdir($temporaryRoot, 0777, true);

try {
    file_put_contents(
        $temporaryRoot . '/payment-gateway-app.php',
        "<?php\nclass Plugin { const PLUGIN_REVISION = 'dev'; }\n"
    );
    file_put_contents(
        $temporaryRoot . '/README.md',
        "# Plugin\n\n**Version:** dev\n\n## Changelog\n\n### 1.1.1\n\n- Fixed release packaging.\n\n### 1.1.0\n\n- Previous release.\n"
    );

    prepareRelease($temporaryRoot, '1.1.1');

    $plugin = file_get_contents($temporaryRoot . '/payment-gateway-app.php');
    $readme = file_get_contents($temporaryRoot . '/README.md');
    $releaseNotes = file_get_contents($temporaryRoot . '/RELEASE.md');

    releaseAssertContains("const PLUGIN_REVISION = '1.1.1';", $plugin, 'The packaged PHP file must contain the tag version.');
    releaseAssertContains('**Version:** 1.1.1', $readme, 'The packaged README must contain the tag version.');
    releaseAssertContains('### 1.1.1', $releaseNotes, 'Release notes must include the matching changelog heading.');
    releaseAssertContains('- Fixed release packaging.', $releaseNotes, 'Release notes must include the matching changelog items.');
    releaseAssertNotContains('### 1.1.0', $releaseNotes, 'Release notes must stop before the previous version.');

    $invalidVersionRejected = false;
    try {
        prepareRelease($temporaryRoot, 'release/latest');
    } catch (InvalidArgumentException $exception) {
        $invalidVersionRejected = true;
    }
    releaseAssertSame(true, $invalidVersionRejected, 'Non-semantic release tags must be rejected.');

    $missingChangelogRejected = false;
    try {
        prepareRelease($temporaryRoot, '1.2.0');
    } catch (RuntimeException $exception) {
        $missingChangelogRejected = str_contains($exception->getMessage(), 'changelog entry');
    }
    releaseAssertSame(true, $missingChangelogRejected, 'A release without a matching changelog entry must be rejected.');

    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/phpreleaser.yml');
    releaseAssertContains('- "**"', $workflow, 'The workflow must validate every tag name, including tags containing slashes.');
    releaseAssertContains('PLUGIN_RELEASE_VERSION: ${{ github.ref_name }}', $workflow, 'The workflow must derive the release version from the Git tag.');
    releaseAssertContains('php scripts/prepare-release.php "$PLUGIN_RELEASE_VERSION"', $workflow, 'The workflow must prepare versioned release files.');
    releaseAssertContains('filename: payment-gateway-app_v${{ env.PLUGIN_RELEASE_VERSION }}.zip', $workflow, 'The archive filename must include the release version.');
    releaseAssertContains('payment-gateway-app/scripts/* payment-gateway-app/tests/*', $workflow, 'Development scripts and tests must be excluded from the archive.');
    releaseAssertContains('bodyFile: payment-gateway-app/RELEASE.md', $workflow, 'The GitHub release must use the matching changelog section.');

    $sourceReadme = file_get_contents(dirname(__DIR__) . '/README.md');
    releaseAssertContains('**Version:** dev', $sourceReadme, 'The source README must use the release-time version placeholder.');
    releaseAssertContains('### 1.1.1', $sourceReadme, 'The changelog must document the next patch release.');
} finally {
    $files = array_reverse(glob($temporaryRoot . '/*') ?: array());
    foreach ($files as $file) {
        is_dir($file) ? rmdir($file) : unlink($file);
    }
    rmdir($temporaryRoot);
}

echo "Release packaging contract: PASS\n";
