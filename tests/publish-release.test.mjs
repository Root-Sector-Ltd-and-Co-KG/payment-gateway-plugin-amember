import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

import { withVerifiedPublisher } from "../scripts/publish-release.mjs";

const sourceRoot = process.env.SHARED_PUBLISHER_ROOT;

test("runs the exact shared publisher regression suite", () => {
  withVerifiedPublisher(sourceRoot, (snapshot) => {
    const env = { ...process.env };
    delete env.NODE_TEST_CONTEXT;
    const result = spawnSync(process.execPath, ["--test", "--test-reporter=tap", path.join(snapshot, "tests/publish-release.test.mjs")], {
      encoding: "utf8", env,
    });
    assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
    assert.match(result.stdout, /# tests 19\b/);
    assert.match(result.stdout, /# fail 0\b/);
  });
});

test("missing or altered shared tests fail before the verified publisher can execute", (t) => {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), "shared-publisher-test-"));
  t.after(() => fs.rmSync(fixture, { recursive: true, force: true }));
  withVerifiedPublisher(sourceRoot, (snapshot) => {
    fs.cpSync(snapshot, fixture, { recursive: true });
  });
  const testPath = path.join(fixture, "tests/publish-release.test.mjs");
  fs.unlinkSync(testPath);
  let invoked = false;
  const execute = () => withVerifiedPublisher(fixture, () => { invoked = true; });
  assert.throws(execute, /Missing shared publisher file: tests\/publish-release\.test\.mjs/);
  fs.writeFileSync(testPath, "throw new Error('unverified suite executed');");
  assert.throws(execute, /Shared publisher digest mismatch: tests\/publish-release\.test\.mjs/);
  assert.equal(invoked, false);
});

test("execution uses verified bytes even if the checkout changes after verification", (t) => {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), "shared-publisher-snapshot-"));
  t.after(() => fs.rmSync(fixture, { recursive: true, force: true }));
  withVerifiedPublisher(sourceRoot, (snapshot) => fs.cpSync(snapshot, fixture, { recursive: true }));
  let executionRoot;
  assert.throws(() => withVerifiedPublisher(fixture, (snapshot) => {
    executionRoot = snapshot;
    const relative = "scripts/publish-release.mjs";
    const verified = fs.readFileSync(path.join(snapshot, relative));
    fs.writeFileSync(path.join(fixture, relative), "throw new Error('modified');");
    assert.deepEqual(fs.readFileSync(path.join(snapshot, relative)), verified);
    throw new Error("callback failed");
  }), /callback failed/);
  assert.equal(fs.existsSync(executionRoot), false, "snapshot is removed even on failure");
});


test("CLI forwards arguments to the verified publisher and preserves its rejection", () => {
  const wrapper = fileURLToPath(new URL("../scripts/publish-release.mjs", import.meta.url));
  const result = spawnSync(process.execPath, [wrapper, "--validation-job-result", "failure"], {
    encoding: "utf8", env: process.env,
  });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /Publication requires a successful validation job/);
});
