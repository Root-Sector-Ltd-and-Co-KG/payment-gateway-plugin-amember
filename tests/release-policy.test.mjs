import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath, pathToFileURL } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const policyPath = path.join(root, "scripts/validate-release-policy.mjs");
const finalReceiverRevision = "4f6ca202eaf0fb535571b6aa4533392a3a7fa0b3";
const finalReceiverDigests = {
  "payment-gateway-app.php": "3d02679daed1591496ac8dbf0ee75bf3603fcb28fe6db2fa30392e67e183e6a3",
  "README.md": "3772445e5dbd34f9590e7c6db25c1f9e6dac21265a729282a0245f25ccfcd0cf",
};

test("release policy implementation is repository-local", () => {
  assert.ok(existsSync(policyPath), "scripts/validate-release-policy.mjs must exist");
});

test("trusted release manifest binds the protected workflow, policy, source, version, and package inputs", async () => {
  const { validateTrustedRelease } = await import(pathToFileURL(policyPath));
  const manifest = {
    schemaVersion: 1,
    defaultBranch: "main",
    workflowPath: ".github/workflows/phpreleaser.yml",
    policy: {
      id: "amember-plugin-release-v1",
      path: "scripts/validate-release-policy.mjs",
    },
    releases: {
      "1.2.0": {
        sourceRevision: finalReceiverRevision,
        artifactName: "payment-gateway-app_v1.2.0.zip",
        archiveRoot: "payment-gateway-app",
        prepareScript: "scripts/prepare-release.php",
        changelogFile: "README.md",
        changelogHeading: "### 1.2.0",
        releaseNotesFile: "RELEASE.md",
      },
    },
  };

  assert.deepEqual(validateTrustedRelease({
    manifest,
    requestedVersion: "1.2.0",
    requestedSourceRevision: finalReceiverRevision,
    workflowRef: "refs/heads/main",
    defaultBranch: "main",
    workflowPath: ".github/workflows/phpreleaser.yml",
    policyPath: "scripts/validate-release-policy.mjs",
  }), manifest.releases["1.2.0"]);

  for (const change of [
    { requestedSourceRevision: "f".repeat(40) },
    { requestedVersion: "1.2.1" },
    { workflowRef: "refs/heads/release/1.2.0" },
    { defaultBranch: "develop" },
    { workflowPath: ".github/workflows/other.yml" },
    { policyPath: "scripts/other-policy.mjs" },
  ]) {
    assert.throws(
      () => validateTrustedRelease({
        manifest,
        requestedVersion: "1.2.0",
        requestedSourceRevision: finalReceiverRevision,
        workflowRef: "refs/heads/main",
        defaultBranch: "main",
        workflowPath: ".github/workflows/phpreleaser.yml",
        policyPath: "scripts/validate-release-policy.mjs",
        ...change,
      }),
      /trusted release manifest|protected default branch/i,
    );
  }
});

test("1.2.0 remains bound to its exact immutable receiver package source", () => {
  const manifest = JSON.parse(readFileSync(path.join(root, ".github/release-policy.json"), "utf8"));
  const sourceRevision = manifest.releases["1.2.0"].sourceRevision;
  assert.equal(sourceRevision, finalReceiverRevision);

  for (const [packagePath, expectedDigest] of Object.entries(finalReceiverDigests)) {
    const archive = execFileSync("git", ["-C", root, "archive", sourceRevision, packagePath]);
    const archivedSource = execFileSync("tar", ["-xOf", "-", packagePath], {
      input: archive,
    });
    assert.equal(createHash("sha256").update(archivedSource).digest("hex"), expectedDigest);
  }
});

test("release policy CLI maps documented kebab-case arguments", async () => {
  const { parseCliArgs } = await import(pathToFileURL(policyPath));
  assert.deepEqual(
    parseCliArgs([
      "--source-sha",
      "a".repeat(40),
      "--policy-revision",
      "b".repeat(40),
      "--changelog-heading",
      "1.2.0",
    ]),
    {
      sourceSha: "a".repeat(40),
      policyRevision: "b".repeat(40),
      changelogHeading: "1.2.0",
    },
  );
});

test("release policy binds exact source, SemVer impact, changelog, and policy identity", async () => {
  const { validateReleasePolicy } = await import(pathToFileURL(policyPath));
  const sourceRevision = "a".repeat(40);
  const policyRevision = "d".repeat(40);
  const evidence = validateReleasePolicy({
    sourceRevision,
    checkoutRevision: sourceRevision,
    previousTag: "1.1.1",
    requestedVersion: "1.2.0",
    commits: [
      { sha: "b".repeat(40), message: "fix(ipn): preserve claim state" },
      { sha: "c".repeat(40), message: "feat(ipn): add durable v2 receiver" },
    ],
    changelog: "### 1.2.0\n\n- Add IPN v2 support.\n",
    changelogHeading: "### 1.2.0",
    policyId: "amember-plugin-release-v1",
    policyRevision,
  });

  assert.deepEqual(evidence, {
    schemaVersion: 1,
    validated: true,
    sourceRevision,
    version: "1.2.0",
    previousTag: "1.1.1",
    requiredBump: "minor",
    policy: {
      id: "amember-plugin-release-v1",
      revision: policyRevision,
    },
  });
});

test("release policy rejects source retargeting, under-versioning, and malformed commits", async () => {
  const { validateReleasePolicy } = await import(pathToFileURL(policyPath));
  const base = {
    sourceRevision: "a".repeat(40),
    checkoutRevision: "a".repeat(40),
    previousTag: "1.1.1",
    requestedVersion: "1.2.0",
    commits: [{ sha: "b".repeat(40), message: "feat(ipn): add v2" }],
    changelog: "### 1.2.0\n",
    changelogHeading: "### 1.2.0",
    policyId: "amember-plugin-release-v1",
    policyRevision: "d".repeat(40),
  };

  assert.throws(
    () => validateReleasePolicy({ ...base, checkoutRevision: "f".repeat(40) }),
    /does not match checked-out revision/,
  );
  assert.throws(
    () => validateReleasePolicy({ ...base, requestedVersion: "1.1.2", changelogHeading: "### 1.1.2", changelog: "### 1.1.2\n" }),
    /must equal calculated minor version 1\.2\.0/,
  );
  assert.throws(
    () => validateReleasePolicy({ ...base, commits: [{ sha: "b".repeat(40), message: "update receiver" }] }),
    /valid Conventional Commit/,
  );
});
const controlBundlePath = path.join(root, "scripts/release-control-digest.mjs");

function discoverStaticReleaseControlInputs(entryPaths) {
  const discovered = new Set(entryPaths);
  const pending = [...entryPaths];
  while (pending.length > 0) {
    const relativePath = pending.shift();
    const source = readFileSync(path.join(root, relativePath), "utf8");
    const dependencies = [];
    for (const match of source.matchAll(/path\.join\(root,\s*["']([^"']+)["']\)/g)) {
      dependencies.push(match[1]);
    }
    for (const match of source.matchAll(/dirname\(__DIR__\)\s*\.\s*["']\/([^"']+)["']/g)) {
      dependencies.push(match[1]);
    }
    for (const match of source.matchAll(/(?:from\s+|import\s*\()\s*["'](\.\.?\/[^"']+)["']/g)) {
      dependencies.push(path.relative(root, path.resolve(path.dirname(path.join(root, relativePath)), match[1])));
    }
    for (const dependency of dependencies) {
      if (!discovered.has(dependency)) {
        discovered.add(dependency);
        pending.push(dependency);
      }
    }
  }
  return [...discovered].sort();
}

test("release-control digest binds the exact publication controls and fails on a missing file", async (t) => {
  const { RELEASE_CONTROL_FILES, calculateReleaseControlBundle } = await import(pathToFileURL(controlBundlePath));
  assert.deepEqual(RELEASE_CONTROL_FILES, [
    ".github/workflows/phpreleaser.yml",
    ".github/release-policy.json",
    "scripts/release-control-digest.mjs",
    "scripts/validate-release-policy.mjs",
    "scripts/prepare-release.php",
    "scripts/publish-release.mjs",
    "tests/release-workflow.test.php",
    "tests/release-policy.test.mjs",
    "tests/release-workflow.test.mjs",
    "tests/publish-release.test.mjs",
    ".github/workflows/validate-release-controls.yml",
    "README.md",
  ]);
  const staticInputs = discoverStaticReleaseControlInputs([
    "tests/release-workflow.test.php",
    "tests/release-policy.test.mjs",
    "tests/release-workflow.test.mjs",
    "tests/publish-release.test.mjs",
  ]);
  assert.deepEqual(
    staticInputs.filter((filePath) => !RELEASE_CONTROL_FILES.includes(filePath)),
    [],
    "Every statically read or imported repository input must be bound into the release-control digest",
  );
  const first = calculateReleaseControlBundle(root);
  const second = calculateReleaseControlBundle(root);
  assert.deepEqual(second, first);
  assert.match(first.digest, /^sha256:[0-9a-f]{64}$/);

  const fixtureRoot = mkdtempSync(path.join(os.tmpdir(), "release-controls-"));
  t.after(() => rmSync(fixtureRoot, { recursive: true, force: true }));
  assert.throws(() => calculateReleaseControlBundle(fixtureRoot), /missing release-control file/i);
});
