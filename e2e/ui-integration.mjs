// Explicit prepublication integration. No package manifest/lock mutation and no floating ref.
import { execFileSync } from "node:child_process";
import { lstatSync, mkdirSync, readFileSync, realpathSync, rmSync, symlinkSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const pin = JSON.parse(readFileSync(resolve(root, ".ci/ui-integration.json"), "utf8"));
if (pin.repository !== "brume-ui/brume-ui" || !/^[0-9a-f]{40}$/.test(pin.sha))
  throw new Error("Invalid immutable UI pin");
if (process.env.BRUME_UI_INTEGRATION !== "1" || !process.env.BRUME_UI_SOURCE)
  throw new Error("Explicit UI integration mode and source required");
const source = realpathSync(process.env.BRUME_UI_SOURCE);
const git = (...args) =>
  execFileSync("git", ["-C", source, ...args], { encoding: "utf8" }).trim();
if (git("rev-parse", "HEAD") !== pin.sha)
  throw new Error("UI source does not match reviewed pin");
if (git("status", "--porcelain")) throw new Error("UI source has unreviewed modifications");
const modules = resolve(root, "frontend/node_modules/@brume-ui");
mkdirSync(modules, { recursive: true });
for (const name of ["tokens", "schema", "react", "renderer", "inertia"]) {
  const expected = realpathSync(resolve(source, "packages", name));
  const destination = resolve(modules, `brume-${name}`);
  const pkg = JSON.parse(readFileSync(resolve(expected, "package.json"), "utf8"));
  if (pkg.name !== `@brume-ui/brume-${name}`) throw new Error(`Invalid ${name} package`);
  // Every linked package must have completed the ordinary workspace build first.
  readFileSync(resolve(expected, "dist/index.js"));
  if (process.argv.includes("--prepare")) {
    try {
      lstatSync(destination);
      rmSync(destination, { recursive: true, force: true });
    } catch (error) {
      if (error.code !== "ENOENT") throw error;
    }
    symlinkSync(expected, destination, "dir");
  }
  if (realpathSync(destination) !== expected)
    throw new Error(`Unexpected package origin: ${name}`);
}
console.log(`Verified five built UI package origins at ${pin.sha}`);
