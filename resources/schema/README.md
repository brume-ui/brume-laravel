# Vendored schema provenance

The committed `v0.json` is copied byte-for-byte from the actual npm package
`@brume-ui/brume-schema@0.1.0-alpha.25`, path `schema/v0.json`, installed using the
frozen `frontend/pnpm-lock.yaml`. Its registry tarball is
`https://registry.npmjs.org/@brume-ui/brume-schema/-/brume-schema-0.1.0-alpha.25.tgz`.
Registry integrity:
`sha512-CpFewQt44jJw/hx9cELJUIyer5/03Gl5wKesPiQEHMw7RslFfhODv+OcHy7Rfss+1c2IghyUesNfutX2gsFOeQ==`.
Schema SHA-256: `cfc3c4b9f16a4375ae9b5204a8314e63ad95a7c340a67b186aeaa3fead6b2b13`.

This is schema `0.4.0` (`BrumeManager::SCHEMA_VERSION`), published from UI commit
`b36fc19044cb639e94e8aef0574ffd06ff5304e6` by the `v0.1.0-alpha.25` release (the same
commit `.ci/ui-integration.json` pins). Since alpha.14 it has gained, among others, the
`richText` form field (`height`, `contentCss`, `images { accept, maxSize }`; value
`string | null`), which is not a plain field: it is rejected in Action forms, quick-create
forms and upload `extra` fields.

`tests/fixtures/conditions/evaluation.json` is the unchanged 23-case shared UI fixture,
byte-identical to `packages/schema/fixtures/conditions/evaluation.json` at that commit.
SHA-256: `bb61fb21bd1f0764c481678cdb780a55d1122570713d0e8bda2bd58c93637d35`.

After a frozen frontend install, `composer sync-schema` copies this published schema.
Verify its byte equality and provenance whenever updating the UI package version.
