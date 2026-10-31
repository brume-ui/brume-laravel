# Scoped options

`Brume\Options\OptionSource` is shared by Form and Grid. A string creates a model query with its
global scopes; a closure receives `OptionContext` and must return an Eloquent Builder. Declare the
tenant/ownership scope in that query. Request parameters never select model classes or attributes.

```php
use Brume\Form\ConditionPath;
use Brume\Options\OptionContext;
use Brume\Options\OptionSource;

$source = OptionSource::make(fn (OptionContext $context) => Product::query()
    ->where('shop_id', $context->record->shop_id)
    ->where('category_id', $context->dependencies['category']))
    ->label('name')
    ->search(['name', 'sku'])
    ->columns(['sku' => 'SKU', 'price' => 'Price'])
    ->sections(['active' => 'Active', 'archived' => 'Archived'],
        fn ($query, $section, $context) => $query->where('active', $section === 'active'));

$form->select('product')->remote($source, [
    'category' => ConditionPath::root('category'),
]);
```

For create forms `OptionContext::record` is null: use a host-authorized tenant scope that also handles
creation. `record` is always the scoped root, while `rowContext` identifies verified nested owners.
`dependencies` contains only declared names after validation/canonicalization and readonly authority
resolution. JSON callbacks retain the global root; related-row callbacks receive the actual related
model or null for a new row. A picker selected ID belongs to the selectable source, not its intermediary.

`label`, `search`, `columns`, and optional `depth` accept server-declared attribute identifiers.
Hidden attributes and attributes excluded by `$visible` cannot be labels, keys, search inference,
projections, or depths. Depth must be a nonnegative integer. Search escapes LIKE metacharacters.
Results are ordered by declared label and primary key, and every operation clones the scoped builder.

`query(OptionContext): Builder` exposes the same base selectable scope for persistence eligibility.
Task 9 must use it within its locked post-hook validation; no internal HTTP request is necessary.
Section filters refine browsing/hydration, not the base eligibility query. `response(context,
parameters, picker: false): array` returns OptionsResponse, or PickerSearchResponse with declared
columns/sections. It consumes q/page/per_page/value/section only. Unknown sections and malformed
paging/IDs return ValidationException. Defaults are page 1/per_page 20; per_page is at most 50,
page at most 999999, q at most 1000 bytes, hydration at most 100 distinct IDs. Numeric identity uses
RowLookup normalization and rejects canonical duplicates; string identity matches exactly even on
case-insensitive database collations. Hydration preserves requested order and omits unavailable IDs.
It never uses an unscoped label fallback.

`Route::brumeResource` registers GET `/_brume/form-options` and
`/{_brume_record}/_brume/form-options`, named `options` and `record-options`. They inherit every shared
route modifier and resource name synchronization. The edit endpoint resolves the root through
`Resource::query`. Declarations are initialized through creating/editing hooks before lookup and URL
binding. Generated remote URLs contain the canonical `field` declaration selector; nested wildcards
are literal declaration paths, not arbitrary model paths. Unknown fields return 404. Standalone Forms
can bind a real host endpoint using `Form::optionsUrl`, then dispatch to `OptionsController::respond`.
The host must provide its own scoped record and authorization for that endpoint.

The Form SDK sends explicit JSON `depends`, owner `rowContext`, target `dependencyContexts`, and when
needed `ancestorSources`. The latter is an array of `{path,rowContext,depends,dependencyContexts?}`
instances, keyed by picker declaration plus owner prefix before its selection. Full protocol rules
are in brume-ui `packages/schema/docs/protocol.md`. Object/list semantics of custom dependencies are
preserved. Missing ambiguous target identities, unknown names, duplicate/unreachable ancestor
instances, and recursive authority cycles fail with 422. New picker owners must pass the ancestor's
scoped query before child options execute. Actual related models are never loaded globally.

`FormOptionContext` is an internal, operation-local read-only resolver shared across owner, target
and collection-child traversal. Whole collection dependencies project only declared child values
and identity; readonly/static-disabled descendants are read through scoped parents or stable JSON
keys. It makes no persistence/file mutation. Its validation projection is not the trusted raw JSON
preservation branch of FormSaver. Task 9 must reconcile this helper with locked relation planning,
including full form activity rules, write allowlists, source eligibility and post-hook validation.

Grid table pickers use the existing generated options endpoint:

```php
$source = OptionSource::make(fn () => Product::where('shop_id', $shopId))->label('name');
$grid->filters()->equal('product', 'Product')->selectTable($source);
$grid->filters()->in('products', 'Products')->multipleSelectTable($source);
```

Their URL values remain a scalar string or string array. Grid contexts have null root and no Form
dependencies; capture the authorized tenant in the declared source closure. Existing
`remoteSelect(...)` and `remoteMultipleSelect(...)` remain remote comboboxes with their original
RemoteOptions API, tolerant paging and bounded/truncated hydration behavior.
