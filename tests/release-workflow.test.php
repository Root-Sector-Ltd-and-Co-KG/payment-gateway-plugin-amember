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
        "# Plugin\n\n**Version:** dev\n\n## Changelog\n\n### 1.2.0\n\n- Add the IPN v2 migration receiver.\n\n### 1.1.1\n\n- Fixed release packaging.\n"
    );

    prepareRelease($temporaryRoot, '1.2.0');

    $plugin = file_get_contents($temporaryRoot . '/payment-gateway-app.php');
    $readme = file_get_contents($temporaryRoot . '/README.md');
    $releaseNotes = file_get_contents($temporaryRoot . '/RELEASE.md');

    releaseAssertContains("const PLUGIN_REVISION = '1.2.0';", $plugin, 'The packaged PHP file must contain the intended release version.');
    releaseAssertContains('**Version:** 1.2.0', $readme, 'The packaged README must contain the intended release version.');
    releaseAssertContains('### 1.2.0', $releaseNotes, 'Release notes must include the intended changelog heading.');
    releaseAssertContains('- Add the IPN v2 migration receiver.', $releaseNotes, 'Release notes must include the IPN v2 migration item.');
    releaseAssertNotContains('### 1.1.1', $releaseNotes, 'Release notes must stop before the previous version.');

    $invalidVersionRejected = false;
    try {
        prepareRelease($temporaryRoot, 'release/latest');
    } catch (InvalidArgumentException $exception) {
        $invalidVersionRejected = true;
    }
    releaseAssertSame(true, $invalidVersionRejected, 'Non-semantic release tags must be rejected.');

    $missingChangelogRejected = false;
    try {
        prepareRelease($temporaryRoot, '1.3.0');
    } catch (RuntimeException $exception) {
        $missingChangelogRejected = str_contains($exception->getMessage(), 'changelog entry');
    }
    releaseAssertSame(true, $missingChangelogRejected, 'A release without a matching changelog entry must be rejected.');

    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/phpreleaser.yml');
    releaseAssertContains('repository_dispatch:', $workflow, 'Publication must use the default-branch repository dispatch event.');
    releaseAssertContains('types: [plugin-release-approved]', $workflow, 'Publication must require the approved release event type.');
    releaseAssertContains('github.event.client_payload.source_sha', $workflow, 'Publication must select an exact reviewed source commit.');
    releaseAssertContains('PLUGIN_RELEASE_VERSION: ${{ github.event.client_payload.version', $workflow, 'Publication must use the selected semantic version.');
    releaseAssertNotContains('workflow_dispatch:', $workflow, 'Privileged publication must not be ref-selectable.');
    releaseAssertNotContains('pull_request:', $workflow, 'Privileged publication must not execute from pull request source.');
    releaseAssertContains('refs/heads/main', $workflow, 'Publication must execute from the protected default branch.');
    releaseAssertContains('github.ref_protected', $workflow, 'Publication must require GitHub to identify the workflow ref as protected.');
    releaseAssertContains('.github/release-policy.json', $workflow, 'Publication must use the trusted repository release manifest.');
    releaseAssertContains("permissions:\n  contents: read", $workflow, 'The workflow default must be read-only.');
    releaseAssertContains('needs: validate', $workflow, 'Publication must depend on successful validation.');
    releaseAssertContains("permissions:\n      contents: write", $workflow, 'Only publication may write repository contents.');
    releaseAssertContains('php tests/ipn-v2.test.php', $workflow, 'The release workflow must execute the IPN v2 receiver regression before packaging.');
    releaseAssertNotContains('payment-gateway-release-orchestrator/', $workflow, 'A public plugin workflow must not import the private release orchestrator.');
    releaseAssertContains('scripts/validate-release-policy.mjs', $workflow, 'Publication must run the repository-local SemVer policy.');
    releaseAssertContains('.releases[$version].artifactName', $workflow, 'The archive filename must come from the trusted release manifest.');
    releaseAssertContains('sha256sum', $workflow, 'Publication must create an exact SHA-256 checksum asset.');
    releaseAssertContains('scripts/publish-release.mjs', $workflow, 'Publication must verify a resumable draft before making the release visible.');
    releaseAssertContains('persist-credentials: false', $workflow, 'Publication checkouts must not retain write credentials.');
    releaseAssertContains('release-control/$PREPARE_SCRIPT', $workflow, 'Packaging must use the trusted control-tree packager.');
    $prWorkflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/validate-release-controls.yml');
    releaseAssertContains('pull_request:', $prWorkflow, 'Pull requests must retain read-only release-control validation.');
    releaseAssertContains("permissions:\n  contents: read", $prWorkflow, 'Pull request validation must default to contents read.');
    releaseAssertNotContains('contents: write', $prWorkflow, 'Pull request validation must never publish.');
    releaseAssertNotContains('--clobber', $workflow, 'Publication must never replace a release asset.');

    $sourceReadme = file_get_contents(dirname(__DIR__) . '/README.md');
    releaseAssertContains('**Version:** dev', $sourceReadme, 'The source README must use the release-time version placeholder.');
    releaseAssertContains('### 1.2.0', $sourceReadme, 'The changelog must document the intended IPN v2 release.');
    releaseAssertContains('IPN v1', $sourceReadme, 'The intended release must document the retained IPN v1 migration path.');
    releaseAssertContains('IPN v2', $sourceReadme, 'The intended release must document the IPN v2 upgrade path.');
} finally {
    $files = array_reverse(glob($temporaryRoot . '/*') ?: array());
    foreach ($files as $file) {
        is_dir($file) ? rmdir($file) : unlink($file);
    }
    rmdir($temporaryRoot);
}

echo "Release packaging contract: PASS\n";
