# Vendored schema provenance

The committed `v0.json` is copied byte-for-byte from the actual npm package
`@brume-ui/brume-schema@0.1.0-alpha.14`, path `schema/v0.json`, installed using the
frozen `frontend/pnpm-lock.yaml`. Its registry tarball is
`https://registry.npmjs.org/@brume-ui/brume-schema/-/brume-schema-0.1.0-alpha.14.tgz`.
Registry integrity:
`sha512-wYMeCUkyz5F/hmpljMia7bK0zdmL4Mseg9+wSw3v/5Kpy3ZPAUS/yptNgVmguT3GOS21tsvBEmKUoVZMQZtj+Q==`.
Schema SHA-256: `ddf64808127250adb9cf696663b4f93c5360ae97d4e74794af306b469e6554fd`.

This is schema `0.4.0`, published from reviewed UI commit
`28a053a1af6733965993c737421a690953336292` by the `v0.1.0-alpha.14` release.
It includes resolved `FormDef.rowFields`, normalized `defaultValue`, `hydrateAuthority`
capability and scoped selected-owner `PickerSearchResponse.data[].authority` snapshots.
The published bytes match the previously reviewed source schema exactly.

`tests/fixtures/conditions/evaluation.json` is the unchanged 23-case shared UI fixture.
SHA-256: `bb61fb21bd1f0764c481678cdb780a55d1122570713d0e8bda2bd58c93637d35`.

After a frozen frontend install, `composer sync-schema` copies this published schema.
Verify its byte equality and provenance whenever updating the UI package version.
