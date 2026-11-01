import { execFileSync, spawnSync } from "node:child_process";
import {
  copyFileSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { resolve } from "node:path";
import { test } from "node:test";
import assert from "node:assert/strict";

// Real disposable repository proves pin/clean-build/origin gates, not mocked git responses.
test("source substitution requires opt-in, exact clean SHA and all five built package origins", () => {
  const root = mkdtempSync(resolve(tmpdir(), "brume-integration-"));
  const source = resolve(root, "source");
  for (const dir of ["e2e", ".ci", "frontend/node_modules/@brume-ui", "source"])
    mkdirSync(resolve(root, dir), { recursive: true });
  copyFileSync(
    new URL("./ui-integration.mjs", import.meta.url),
    resolve(root, "e2e/ui-integration.mjs"),
  );
  const git = (...args) =>
    execFileSync("git", ["-C", source, ...args], { encoding: "utf8" }).trim();
  git("init", "-q");
  git("config", "user.email", "codex@openai.com");
  git("config", "user.name", "Codex");
  for (const name of ["tokens", "schema", "react", "renderer", "inertia"]) {
    const dir = resolve(source, "packages", name);
    mkdirSync(resolve(dir, "dist"), { recursive: true });
    writeFileSync(
      resolve(dir, "package.json"),
      JSON.stringify({ name: `@brume-ui/brume-${name}` }),
    );
    writeFileSync(resolve(dir, "dist/index.js"), "export {};");
  }
  git("add", ".");
  git("commit", "-qm", "fixture");
  const pin = { repository: "brume-ui/brume-ui", sha: git("rev-parse", "HEAD") };
  writeFileSync(resolve(root, ".ci/ui-integration.json"), JSON.stringify(pin));
  const run = (env = {}) =>
    spawnSync(process.execPath, [resolve(root, "e2e/ui-integration.mjs"), "--prepare"], {
      env: { ...process.env, BRUME_UI_INTEGRATION: "", BRUME_UI_SOURCE: "", ...env },
      encoding: "utf8",
    });
  assert.notEqual(run().status, 0);
  const env = { BRUME_UI_INTEGRATION: "1", BRUME_UI_SOURCE: source };
  assert.equal(run(env).status, 0);
  for (const name of ["tokens", "schema", "react", "renderer", "inertia"])
    assert.equal(
      realpathSync(resolve(root, "frontend/node_modules/@brume-ui/brume-" + name)),
      realpathSync(resolve(source, "packages", name)),
    );
  writeFileSync(resolve(source, "packages/react/dist/index.js"), "changed");
  assert.match(run(env).stderr, /modifications/);
  git("checkout", "--", ".");
  writeFileSync(resolve(source, "unreviewed.js"), "export {};");
  assert.match(run(env).stderr, /modifications/);
  git("add", "unreviewed.js");
  git("commit", "-qm", "unreviewed change");
  writeFileSync(
    resolve(root, ".ci/ui-integration.json"),
    JSON.stringify({ ...pin, sha: "0".repeat(40) }),
  );
  assert.match(run(env).stderr, /does not match reviewed pin/);
  writeFileSync(
    resolve(root, ".ci/ui-integration.json"),
    JSON.stringify({ ...pin, sha: "phase-2" }),
  );
  assert.match(run(env).stderr, /Invalid immutable UI pin/);
  assert.ok(readFileSync(resolve(root, "e2e/ui-integration.mjs"), "utf8").length);
});
