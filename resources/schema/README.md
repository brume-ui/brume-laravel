# Vendored schema provenance

The committed `v0.json` is copied byte-for-byte from the actual npm package
`@brume-ui/brume-schema@0.1.0-alpha.26`, path `schema/v0.json`, installed using the
frozen `frontend/pnpm-lock.yaml`. Its registry tarball is
`https://registry.npmjs.org/@brume-ui/brume-schema/-/brume-schema-0.1.0-alpha.26.tgz`.
Registry integrity:
`sha512-xbW946LDpgtzJCXdQuXwRUO/7Wk2PLDneoTLrcUwgl0x5OhMpdTNAgY3bASxPtDDzRgSINv5LztAaf6FjFCbRg==`.
Schema SHA-256: `0ec1e60b85b089d502a53e16e2d2115e99bc8f534358693795d6841d19b7673f`.

This is schema `0.4.0` (`BrumeManager::SCHEMA_VERSION`), published from UI commit
`e0028ce6897aafe731cbf0fa6f0cfa1e844a8eea` by the `v0.1.0-alpha.26` release (the same
commit `.ci/ui-integration.json` pins). Since alpha.14 it has gained, among others, the
`richText` form field (`height`, `contentCss`, `images { accept, maxSize }`; value
`string | null`), which is not a plain field: it is rejected in Action forms, quick-create
forms and upload `extra` fields. Alpha.26 adds the plain `color` and `tel` form fields and the
static `html` block (no value, not a field).

`tests/fixtures/conditions/evaluation.json` is the unchanged 23-case shared UI fixture,
byte-identical to `packages/schema/fixtures/conditions/evaluation.json` at that commit.
SHA-256: `bb61fb21bd1f0764c481678cdb780a55d1122570713d0e8bda2bd58c93637d35`.

After a frozen frontend install, `composer sync-schema` copies this published schema.
Verify its byte equality and provenance whenever updating the UI package version.
