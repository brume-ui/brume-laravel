# Vendored schema provenance

The committed `v0.json` is copied byte-for-byte from the actual npm package
`@brume-ui/brume-schema@0.1.0-alpha.27`, path `schema/v0.json`, installed using the
frozen `frontend/pnpm-lock.yaml`. Its registry tarball is
`https://registry.npmjs.org/@brume-ui/brume-schema/-/brume-schema-0.1.0-alpha.27.tgz`.
Registry integrity:
`sha512-P680oTFc7Al+VrqjawZ5NPEKpX5CAYhdoiPiLltQFRnnG/3cM5wY5GJKm323OmSL9zNizjP35EmQKdJJ+aCpMQ==`.
Schema SHA-256: `c2fe3268438a7f0ac48cd5a40a59c0c8e23eddf4b0888c009beabc36cde93ade`.

This is schema `0.4.0` (`BrumeManager::SCHEMA_VERSION`), published from UI commit
`4b7632ca1566b076b373d6f34c7c578c1fa09a0d` by the `v0.1.0-alpha.27` release (the same
commit `.ci/ui-integration.json` pins). Since alpha.14 it has gained, among others, the
`richText` form field (`height`, `contentCss`, `images { accept, maxSize }`; value
`string | null`), which is not a plain field: it is rejected in Action forms, quick-create
forms and upload `extra` fields. Alpha.26 adds the plain `color` and `tel` form fields and the
static `html` block (no value, not a field). Alpha.27 adds the plain `ip`, `rate`, `slider` and `icon`
and `time` form fields, the two-column `range` and `timeRange` fields, and the `button` node.

`tests/fixtures/conditions/evaluation.json` is the unchanged 23-case shared UI fixture,
byte-identical to `packages/schema/fixtures/conditions/evaluation.json` at that commit.
SHA-256: `bb61fb21bd1f0764c481678cdb780a55d1122570713d0e8bda2bd58c93637d35`.

After a frozen frontend install, `composer sync-schema` copies this published schema.
Verify its byte equality and provenance whenever updating the UI package version.
