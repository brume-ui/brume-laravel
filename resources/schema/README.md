# Vendored schema provenance

The committed `v0.json` is copied byte-for-byte from `brume-ui` commit
`d3d0ed5a4a64e7964a6583ae665051818f05ad88`, path `packages/schema/schema/v0.json`.
SHA-256: `ddf64808127250adb9cf696663b4f93c5360ae97d4e74794af306b469e6554fd`.

This is schema `0.4.0`: reviewed Tasks 1–6 source `b3854935db4e8a8a6e27e4612029a173c0a101ee`
plus the controller-approved S3-R15 resolved `FormDef.rowFields` contract and final
S3-R34/R35 normalized `defaultValue`, `hydrateAuthority` capability and scoped selected-owner
`PickerSearchResponse.data[].authority` snapshots. The complete source is pinned above.
The source version remains alpha.12 until the coordinated alpha.13 release; this source
integration is not a claim that an alpha.13 registry package exists.

`tests/fixtures/conditions/evaluation.json` is the unchanged 23-case shared UI fixture.
SHA-256: `bb61fb21bd1f0764c481678cdb780a55d1122570713d0e8bda2bd58c93637d35`.

Do not run `composer sync-schema` against the currently installed alpha.12 frontend package:
that package contains schema 0.3. Task 11 replaces this source integration with the reviewed
published alpha.13 dependency and verifies exact registry schema parity.
