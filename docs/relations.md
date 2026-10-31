# Transactional form relations

Form saves now lock and refresh the scoped root on its own connection before model-aware editing declarations, validation and the saving hook. Rendering still initializes once; an edit save reruns the editing hook against the locked record, restoring pre-hook declarations first. The caller's model object is preserved. Creates keep a null root callback context. The saving hook receives validated values and may adjust values or ordinary model attributes. The actual adjusted graph is validated again before any model save or upload copy; root identity and connection cannot change in that hook.

All submitted active editable source selections must belong to the current base `OptionSource::query`, including unchanged selections. An unchanged selection that is no longer eligible rejects the entire save. Omitted fields remain untouched. Readonly/inactive selections retain authoritative state without a new eligibility requirement; removing a selection does not require the removed source to remain selectable. Source sections only filter browsing. Remote Select and MultipleSelect use the same eligibility rules; remote Tags remain free text suggestions. Dependencies come from the complete submitted graph with authoritative readonly values, canonical values and actual row identity, including cross-row numeric paths and whole collections. Source queries are local builders, never HTTP requests.

| Declaration | Persistence |
| --- | --- |
| `relationPicker(...)->relation('owner')->multiple(false)` with BelongsTo | Assign the selected owner's key; null requires a nullable owning foreign key. Declared extras update only the selected related model's declared editable fields. |
| Picker with direct HasMany | Reassign selected children to the parent; null the nullable foreign key of removed children. |
| Picker with BelongsToMany | Preserve selected pivot rows and undeclared columns; extras read/write pivot columns. Detach removed associations. |
| Picker with intermediary HasMany and `associateBy('product_id')` | Match the parent's intermediary by source ID; update its declared fields, preserving its primary key and counters. |
| Intermediary with `restoreRemoved()` | Include the parent's soft-deleted rows when matching; restore the same row on reselection. Multiple historical matches reject atomically. |
| `repeater(...)->relation('children')->orderColumn('position')` | Create/update/delete children recursively; retained rows keep their identities and undeclared attributes. Order is zero-based. |

Repeater rows carry `_brume: {key, id?}`; picker selections carry a source `id`. Numeric model identities must use canonical string spelling; string keys are exact, including case and leading zeroes. Duplicate keys and IDs reject. IDs are resolved through the authoritative parent relation, then source eligibility where applicable. Identity/FK/association/order attributes cannot be declared as editable row aliases; pivot keys and timestamps are also protected. Every read, lock and write belongs to the root model connection; mixed-connection graphs reject before persistence.

Callbacks for existing related rows receive the actual parent-owned model, while newly associated/created rows receive null. For BelongsToMany extras, the existing callback model is the actual pivot, and a new association receives null. JSON callbacks always receive the original nullable global root, including JSON inside relation rows or picker extras. JSON custom objects/lists and exact decimal strings keep their existing semantics.

There is no depth limit for supported ownership chains. An explicit Eloquent relation cannot be owned by a JSON row: that row has no model/FK owner. For example, a JSON `repeater('data', ..., fn ($row) => $row->repeater('children', ...)->relation('children'))` is rejected at declaration resolution. Make the outer repeater relation-mode when rows own related records, or store selected IDs in JSON remote Select/MultipleSelect fields. Layout sections/embeds do not change the persistence owner. JSON inside relations and recursive JSON inside JSON remain supported.

## Internal planning and upload seam

`RelationPlanner::plan(Form, Model, array, ?Model): RelationPlan` runs only inside the root transaction. It resolves and locks the complete graph, prepares authoritative condition/source projections, validates declared fields, then freezes scalar, association, order, restoration and removal operations. `RelationSynchronizer::save(RelationPlan): void` executes that tree. No row is filled/saved and no file is copied while planning.

The plan returns defensive copies through `values()`, `operations()` and `inventory()`. Its model/declaration snapshots cannot be mutated by changing those returned copies. The synchronizer updates the caller's root model after executing the frozen tree. `withOriginalInventory()` carries the pre-saving-hook inventory into the final plan. `withArchivedValues()` returns a new plan replacing only previously validated root Upload leaves after the existing archiver converts tokens to final paths; it does not re-plan or authorize a graph after file copies.

`inventory()` contains:

- `original`: complete locked pre-hook field inventory, including omitted/removed descendants. Entries have `field`, numeric `path`, canonical `template`, ordered `rowContext`, cloned storage `owner`, raw semantic `stored` value and `rawAttributes`.
- `fields`: current resolved field inventory keyed by numeric path, with the same declaration/context/owner information, `submitted` and `validated` flags. Validated entries additionally have `value` and `active`; consumers must require both `validated` and `active` for editable upload work. Preserved inactive JSON values are not new upload submissions.
- `rows`: resolved row contexts and authoritative callback owners, keyed by numeric row prefix.
- `removed`: removal records with the containing path/context, original model and child form declaration. The complete old file traversal is in `original`.

Task 10 must adapt the archiver to this graph-wide inventory and replace the temporary nested-upload guard. Until then, nested upload tokens/replacement/removal changes reject; unchanged authoritative existing values and omitted uploads are retained, and relation removal does not clean up nested files. Root upload lifecycle and outer transaction cleanup remain supported. This is not a claim of completed nested upload archival.

SQLite tests establish validation, ordering and rollback behavior. Production-driver execution and MySQL locking acceptance remain CI gates; SQLite does not prove contention safety.
