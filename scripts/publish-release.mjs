#!/usr/bin/env node

import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { pathToFileURL } from "node:url";

// Public source: Root-Sector-Ltd-and-Co-KG/payment-gateway-plugin-woocommerce
// Revision: e19da7c7086df7edf538f0a9dc0225de5e0cf8c9. Changes require review
// of both hashes and the pinned checkouts; this wrapper is release-digest bound.
const files = Object.freeze({
  "scripts/publish-release.mjs": "45820072374c6a7641c12eb6fdc45d2f58a4c4fec5ef16536345672f022187e1",
  "tests/publish-release.test.mjs": "5fa161b1808139d8b204ddca6465aa2f7a1f1e22971a09ba1995bf91eab59a32",
});

export function withVerifiedPublisher(sourceRoot, execute) {
  if (!sourceRoot) throw new Error("SHARED_PUBLISHER_ROOT is required");
  const verified = Object.entries(files).map(([relative, expected]) => {
    let body;
    try {
      body = fs.readFileSync(path.join(sourceRoot, relative));
    } catch (error) {
      if (error.code === "ENOENT") throw new Error(`Missing shared publisher file: ${relative}`);
      throw error;
    }
    if (createHash("sha256").update(body).digest("hex") !== expected) {
      throw new Error(`Shared publisher digest mismatch: ${relative}`);
    }
    return [relative, body];
  });
  // Execute only this private snapshot: no unverified checkout files, package
  // configuration, or later checkout modifications can enter module resolution.
  // Canonicalize macOS temporary-directory symlinks for the shared CLI entry guard.
  const snapshot = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "verified-plugin-publisher-")));
  try {
    for (const [relative, body] of verified) {
      const target = path.join(snapshot, relative);
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.writeFileSync(target, body, { mode: 0o600 });
    }
    return execute(snapshot);
  } finally {
    fs.rmSync(snapshot, { recursive: true, force: true });
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    process.exitCode = withVerifiedPublisher(process.env.SHARED_PUBLISHER_ROOT, (snapshot) => {
      const result = spawnSync(process.execPath, [path.join(snapshot, "scripts/publish-release.mjs"), ...process.argv.slice(2)], {
        stdio: "inherit",
      });
      if (result.error) throw result.error;
      if (result.status === null) throw new Error("Shared publisher terminated without an exit status");
      return result.status;
    });
  } catch (error) {
    console.error(`ERROR: ${error.message}`);
    process.exitCode = 1;
  }
}
