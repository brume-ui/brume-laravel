# Vendored schema provenance

The committed `v0.json` is copied byte-for-byte from `brume-ui` commit
`0db32998a801375bc33f9ccfe9dda926accc0d5f`, path `packages/schema/schema/v0.json`.
SHA-256: `55558d91fe94795ee9fc3632d0d5068887adbec930b622788c26bb28067185f0`.

This is schema `0.4.0`: reviewed Tasks 1–6 source `b3854935db4e8a8a6e27e4612029a173c0a101ee`
plus the controller-approved S3-R15 resolved `FormDef.rowFields` contract in Task 7.
The source version remains alpha.12 until the coordinated alpha.13 release; this source
integration is not a claim that an alpha.13 registry package exists.

`tests/fixtures/conditions/evaluation.json` is the unchanged 23-case shared UI fixture.
SHA-256: `bb61fb21bd1f0764c481678cdb780a55d1122570713d0e8bda2bd58c93637d35`.

Do not run `composer sync-schema` against the currently installed alpha.12 frontend package:
that package contains schema 0.3. Task 11 replaces this source integration with the reviewed
published alpha.13 dependency and verifies exact registry schema parity.
