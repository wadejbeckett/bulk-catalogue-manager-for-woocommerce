# External action contract

Contract version 1. Built in T104–T109 on branch `codex/external-action-contract` (29 September 2026); not merged, not released. [EXTENDING.md](EXTENDING.md) is the short developer guide for writing a provider against it; this document is the normative contract. §18 records the rulings the implementation took where this contract left a choice open.

References are to `docs/SPEC.md` by section, `docs/EXACT-SELECTION.md`, and classes under `src/`. The public interfaces live in `src/Extension/` (namespace `BulkCatalogueManager\Extension`); the core's own implementation lives in `src/External/` and is not part of the contract. The text names no other plugin, product family or site, and its examples use the neutral test provider of §15.1.

## Summary

- A separately installed plugin, a **provider**, registers on one action hook, `bulkcat_register_providers`. It declares **external fields** (values it owns and stores, which the core never reads or writes itself) and **external actions**, each changing one of those fields.
- External actions appear in the existing bulk panel and in `GET /fields`. They act on the same exact selection and go through the same preview, Apply, background job, History and undo. There is no second screen and no provider REST route.
- The provider computes each change (current value to new value) without writing, and supplies one absolute, idempotent, compare-and-set write per field. The core applies it, journals both values as opaque JSON with a plain-text rendering, and undoes by writing the old value back through the same write.
- A missing, unavailable or incompatible provider never breaks the screen. Its actions disappear or are disabled with a reason, a running job stops truthfully, history stays readable from stored text, and undo refuses with a plain reason until the provider returns.
- With no provider installed, the core's behaviour, performance, `/fields` payload and interface are unchanged.

## 1. Scope and boundaries

**In scope (contract version 1).** Registration, advertising in the chooser and `/fields`, argument validation, preview, Apply binding, background execution, journal, history, audit, export of history, undo, redo, permission layering, missing-provider behaviour and versioning.

**Out of scope for version 1.**

- Mixing native steps with external steps, or steps from two providers, in one change.
- Grid columns, filters, sorting, inline or detail-panel editing of external fields, and catalogue CSV export of them. The catalogue CSV decision is separate.
- Provider screens, REST routes, scripts, styles or markup inside the manager screen.
- Actions without a field ("object-level" actions). A provider whose state spans several stored keys models it as one field whose value is the whole state. Journal, conflict detection and undo then stay per field, as they already are for native changes.
- Saved lists, rule engines, scheduling or any general workflow framework.

**Standalone rule.** The core contains no provider, detects no other plugin and names none. "Native only" (SPEC §1.2) still describes everything the core writes through its own actions. A value owned by another plugin changes only through that plugin's registered provider code, which an administrator installed and which runs inside the core's journalled write path. Section 16 gives the generic wording for SPEC §2 and `AGENTS.md`.

## 2. Concepts

| Term | Meaning |
|---|---|
| Provider | A plugin-supplied object implementing `Extension\Provider`. Its id matches `[a-z][a-z0-9]{2,19}` (no underscore) and is unique on the site. |
| External field | A value the provider owns, one per product (`parent_only`) or one per product and per variation (`per_variation`). Key: `x_<provider>_<name>`, at most 64 characters, matching the journal's `prop` column. The `x_` prefix is reserved; no native field key uses it. |
| Value | A JSON-plain value (null, scalar, or arrays of those; see `Data\ValueCodec`) in the provider's canonical form, at most 64 KB encoded. The core treats it as opaque. It compares values only through `ExternalField::same()`. |
| External action | A plugin-supplied object implementing `Extension\ExternalAction`, changing exactly one external field of its own provider. Id: `x_<provider>_<name>`. |
| Value envelope | How the journal stores an external value: the value plus its format version and a plain-text rendering (§8.1). |
| Provider manifest | A per-batch snapshot of the provider's id, label, version, contract version, field labels and formats, and action labels (§8.2). History and undo read it when the provider has gone. |

## 3. Registration

### 3.1 Hook and timing

```php
add_action( 'bulkcat_register_providers', static function ( \BulkCatalogueManager\Extension\Registrar $registrar ): void {
	if ( $registrar->supports( 1 ) ) {
		$registrar->register( new My_Provider() );
	}
} );
```

- The core fires `bulkcat_register_providers` once per request, the first time the action registry (`Operations\Registry::instance()`) is built on or after WordPress `init`. REST requests, Action Scheduler chunks, undo planning and history reads all build it after `init`.
- `Registrar::register()` returns `true` or a `WP_Error`. Registering after the hook has finished is refused with `bulkcat_provider_late`, so a provider cannot appear half-way through a request.
- A provider plugin detects the contract with the constant `BULKCAT_EXTENSION_CONTRACT` (the newest version the installed core implements) before adding its callback. Without it, the provider shows its own dependency notice and does nothing else.

### 3.2 What the core validates at registration

The registrar refuses the whole provider, with a `bulkcat_provider_incompatible` error naming the first problem, when:

- `contract()` is not in `Contract::SUPPORTED`;
- the id is malformed or already registered;
- a field key does not start with `x_<id>_`, is malformed, repeats, or declares a variation rule other than `parent_only` or `per_variation`;
- an action id does not start with `x_<id>_`, repeats, names a field of another provider, names an unknown group, uses an unknown edit kind, declares an unsupported argument kind or a reserved argument name (`field`, `apply_to_variations`, `skip_already`), or has an empty capability;
- a chooser group id does not start with `x_<id>_`;
- a limit in §3.4 is exceeded;
- any provider method throws while being described.

A refused provider contributes nothing. The core writes the reason to the WooCommerce log. The manager screen shows one plain notice naming the provider's label, or "An add-on" when no label could be read, with the reason. Native actions are unaffected.

### 3.3 Isolation

Every provider call is wrapped. An exception or PHP error from a provider method becomes a refusal of that request (registration, preview, Apply) or a failed result for that object (chunk, undo). The details go to the WooCommerce log and never reach the browser. One failing provider never disables another provider or any native action.

### 3.4 Limits

| Item | Limit |
|---|---|
| Fields per provider | 5 |
| Actions per provider | 20 |
| Chooser groups per provider | 2 |
| Choices in one argument | 500 |
| Items chosen in one multichoice argument | set by the spec, at most 100 |
| Label | 80 characters |
| Description | 300 characters |
| Skip or failure reason | 255 characters (the journal `message` column) |
| Preview note | 3 per result, 200 characters each |
| Described value | 1,000 characters |
| Encoded value | 64 KB |

The core trims longer strings with an ellipsis rather than refusing them, except for identifiers, which must match their patterns.

## 4. PHP interfaces (contract version 1)

All names are in namespace `BulkCatalogueManager\Extension` unless shown otherwise. The provider implements `Provider`, `ExternalField` and `ExternalAction`. The core implements `Registrar`, `Subject`, `Context` and `Scope`.

PHP 8.1 (the plugin's minimum) has no standalone `true` type, so every method this contract describes as answering `true` or a `WP_Error` is declared `bool|\WP_Error`; the core treats `false` as a refusal with a generic sentence. A provider on PHP 8.2 or later may narrow its own declarations to `true|\WP_Error`.

```php
final class Contract {
	public const VERSION   = 1;     // newest version this core implements; also BULKCAT_EXTENSION_CONTRACT
	public const SUPPORTED = [ 1 ];  // versions a provider may target
}

final class Registrar {
	public function contract(): int;
	public function supports( int $version ): bool;
	public function register( Provider $provider ): bool|\WP_Error; // true, or the refusal
}

interface Provider {
	public function id(): string;                    // 'sample'
	public function label(): string;                 // translated plain text
	public function version(): string;               // the provider plugin's own version
	public function contract(): int;                 // contract version implemented
	public function availability(): bool|\WP_Error;  // true, or the reason shown (dependencies, configuration)
	/** @return list<ExternalField> */
	public function fields(): array;
	/** @return list<ExternalAction> */
	public function actions(): array;
	/** @return list<ChooserGroup> */
	public function groups(): array;                 // may be []
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error; // true, or the reason; may only narrow (§9)
	/** @param list<array{action: string, args: array<string, mixed>}> $steps Normalised steps. */
	public function state_token( Scope $scope, array $steps, Context $context ): string;
	/** @param list<int> $object_ids */
	public function prime( array $object_ids ): void;                    // batch-load before reads; may be a no-op
	/** @param list<int> $object_ids */
	public function flush( array $object_ids, Context $context ): void;  // once per run that wrote (§7.3)
}

abstract class AbstractProvider implements Provider {
	// groups() returns [], authorise() returns true, prime() and flush() do nothing.
}

interface ExternalField {
	public const PARENT_ONLY   = 'parent_only';
	public const PER_VARIATION = 'per_variation';
	public const CONFLICT      = 'bulkcat_changed_during_write'; // the error code write() returns on a compare-and-set miss

	public function key(): string;                   // 'x_sample_labels'
	public function label(): string;
	public function variation_rule(): string;        // PARENT_ONLY or PER_VARIATION
	public function format(): int;                   // value format version, from 1
	public function read( Subject $subject ): mixed; // canonical value, or an Unknown; never empty for unknown
	public function same( mixed $a, mixed $b ): bool;
	public function describe( mixed $value, Subject $subject ): string;                 // plain text
	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string; // plain text, may be ''
	public function write( Subject $subject, mixed $target, mixed $expected, Context $context ): bool|\WP_Error; // true, or the reason
	public function upgrade( mixed $value, int $from_format ): mixed;                    // a value in format(), or a WP_Error
}

interface ExternalAction {
	public function describe(): ActionDescriptor;
	public function validate( array $args, Context $context ): \BulkCatalogueManager\Operations\Validation;
	public function compute( Subject $subject, array $args, Context $context ): \BulkCatalogueManager\Operations\Result;
}

final class ActionDescriptor {
	public function __construct(
		string $id,              // 'x_sample_add'
		string $field,           // one ExternalField key of the same provider
		string $group,           // an Operations\Group::ALL id, or one of the provider's ChooserGroup ids
		string $label,
		string $description,
		string $edit_kind,       // one of Fields\Field::EDIT_KINDS, such as add, remove, set or clear
		array $arguments,        // name => spec (§5.2)
		string $capability,      // a WordPress capability, checked with user_can() (§9)
		bool $always_confirm = false,
		string $confirm_reason = ''
	);
}

final class ChooserGroup {
	public function __construct( string $id, string $label ); // id: x_<provider>_<name>
}

interface Subject {
	public function id(): int;
	public function type(): string;                  // 'product' or 'variation'
	public function parent_id(): int;                // 0 for a product
	public function product(): \WC_Product;          // read-only: never call a setter or save()
	public function value( string $field ): mixed;   // this provider's field, including earlier steps of this change
}

interface Context {
	public const PREVIEW = 'preview';
	public const APPLY   = 'apply';
	public const REPLAY  = 'replay';
	public const UNDO    = 'undo';
	public function mode(): string;
	public function user_id(): int;                  // the requester, or the batch's recorded user in background work
	public function batch_id(): int;                 // 0 while previewing
}

interface Scope {
	public function count(): int;
	/** @return iterable<list<int>> Ascending ids, products and variations, available members only. */
	public function chunks( int $size ): iterable;
}

final class Unknown {
	public static function because( string $reason, string $code = 'bulkcat_unknown_state' ): self; // code: bulkcat_ or x_<provider>_
	public function reason(): string;
	public function code(): string;
}
```

**Additive changes to `Operations\Result`**, available to any result:

- `with_notes( list<string> $notes ): Result` for a changed result; up to three plain sentences shown under its preview row.
- `with_warning( string $code, string $sentence, bool $confirm = false ): Result`; at most one per result. The preview aggregates warnings by code with an object count (§10).
- For an external result, `code` may start with `bulkcat_` or `x_<provider>_`. Reasons remain plain sentences without codes.

**Method rules.**

- `read()`, `compute()`, `describe()`, `describe_change()`, `same()`, `state_token()` and `prime()` never write anything anywhere, including caches that change behaviour.
- `compute()` reads current values through `Subject::value()`, never directly from storage, so steps chain and preview matches the write.
- `write()` sets the field to `$target` absolutely. When the stored value already equals `$target` it returns `true` without writing. When it equals `$expected` it writes. Otherwise it returns a `WP_Error` with code `ExternalField::CONFLICT` and writes nothing. It is idempotent, it may be called again for the same object after a crash, and it only touches the storage that field declares.
- `same()` is reflexive and symmetric, and compares canonical forms.
- A provider never relies on the current user. `Context::user_id()` is the only identity, and checks use `user_can()`.
- `upgrade()` converts a journalled value from an older `format()`. A provider that cannot convert returns a `WP_Error`, and that undo item is refused.

## 5. Descriptor, arguments and the `/fields` payload

### 5.1 Descriptor

The descriptor carries: id, field, chooser group, label, description, edit kind, argument specs, capability, and the optional always-confirm flag with its reason. The variation rule comes from the field and is repeated on the advertised descriptor. The core adds three arguments, exactly as for native actions:

- `field`: a choice with the descriptor's one field, required, preselected by the panel;
- `skip_already`: on by default (T29);
- `apply_to_variations`: only when the field is `per_variation`. Its default is off unless the action sits in a native group whose setting says otherwise (SPEC §11).

### 5.2 Argument specs

Specs use the format `BaseOperation` already documents: `type`, `label`, `rule` (the sentence a refusal shows), and optionally `required`, `default`, `choices` (value => label), `min`, `max`, `exclusive_min`, `exclusive_max`, `max_length`, `allow_empty`, `min_items`, `max_items`.

Supported types: `boolean`, `text`, `integer`, `decimal`, `choice`, and the new **`multichoice`**: a list of distinct choice values, bounded by `min_items` and `max_items`. `ids` and `value` stay native-only, because they are tied to native fields and terms.

A `multichoice` argument is advertised as:

```json
{
  "title": "Labels",
  "type": "array",
  "items": { "type": "string", "enum": [ "red", "blue" ] },
  "uniqueItems": true,
  "minItems": 1,
  "maxItems": 20,
  "choices": [ { "value": "red", "label": "Red" }, { "value": "blue", "label": "Blue" } ],
  "required": true
}
```

The core validates every argument against its spec, refusing unknown names and malformed values with the existing `bulkcat_invalid_operation` refusal, before calling `ExternalAction::validate()`. The provider's `validate()` handles rules that span arguments and checks that chosen targets still exist and may be used by `Context::user_id()`. It returns `Validation::passed( $args )` or `Validation::refused( $error )`.

Choices are built when descriptors are built: once per request, when `/fields` is read, when a grant is issued and when a preview or Apply is validated. A choice that disappears between preview and Apply is caught by the state token (§7.2) and by `validate()`.

### 5.3 Chooser

- Native groups stay first, in their order. Provider chooser groups follow, in registration order. An external action may also sit in a native group.
- The chooser lists a provider's actions in the provider's order, with the same label and one-line description behaviour as native actions (SPEC §4.3).
- An unavailable provider's actions are listed disabled, carrying the provider's reason. An action whose capability the user lacks is listed disabled with "You do not have permission to use this action."
- Choosing an external action while another step in the change is native or from another provider shows the reason in place: "This action can't be combined with other kinds of change. Make it a separate change." Apply stays disabled, and the server refuses the mix too (§6).
- The Scope step, preview step, confirmation policy and Done step are unchanged.

### 5.4 `/fields` additions

These keys and entries appear **only when at least one provider is registered**. With none, the payload is byte-identical to `tests/Unit/Fields/fields.snapshot.json`.

```json
{
  "fields": [
    { "key": "x_sample_labels", "label": "Sample labels", "type": "external", "source": "external",
      "provider": "sample", "readOnly": false, "nullable": false, "inline": false, "sortable": false,
      "editKinds": [ "add", "remove", "set", "clear" ], "variations": "parent_only",
      "variationNote": "Sample labels belong to the product, not to each variation.",
      "group": "x_sample_labels", "defaultColumn": false, "defaultOrder": null,
      "schema": {}, "dataviews": null, "choices": [] }
  ],
  "actions": [
    { "id": "x_sample_add", "label": "Add labels", "description": "Adds the chosen labels. Other labels are kept.",
      "group": "x_sample_labels", "groups": [ "x_sample_labels" ], "editKinds": [ "add" ],
      "fields": [ "x_sample_labels" ], "args": { "type": "object", "properties": { "…": {} }, "additionalProperties": false },
      "provider": "sample", "variations": "parent_only", "available": true, "unavailableReason": "",
      "alwaysConfirm": false, "confirmReason": "" }
  ],
  "groups": [ { "id": "x_sample_labels", "label": "Sample labels" } ],
  "providers": [ { "id": "sample", "label": "Sample labels", "version": "1.0.0", "available": true, "reason": "" } ]
}
```

Native entries never gain the new keys. Browser code treats a missing `provider` key as native and available. The grid, column chooser, filters, inline editing and detail panel ignore fields whose `source` is `external`.

## 6. Selection, scope and variations

- External actions ride on the exact selection of `docs/EXACT-SELECTION.md`, unchanged. The provider never sees a selection token, never resolves a filter and never receives a whole-catalogue identifier list in the browser. It receives:
  - one `Subject` at a time for each materialised, available member it is authorised to act on;
  - a `Scope` (available expanded members, ascending) for `state_token()` only.
- Unavailable members (deleted, wrong type, no longer readable) are reported by the core, as for native changes, and never passed to the provider.
- **One kind of change at a time.** A change containing an external step contains only steps of that one provider, between 1 and 20 of them. `Operations\Registry::validate_list()` refuses any other mix with 400 `bulkcat_mixed_change`. The rule is enforced at grant issuance, preview, acceptance and on every chunk.
- **Variations.** A `parent_only` field is never pushed down. A selected variation meeting a `parent_only` field is skipped with the core's §8 sentence ("<Field> belongs to the product, not to each variation."). This counts as skipped, so the job is not full success and the submitted selection is retained (EXACT-SELECTION completion rule). The preview's warning block counts these variations before Apply. A `per_variation` field offers **Also apply to variations**, and `BatchFactory::expand()` assigns steps per item exactly as it does for native fields.
- The completion contract is unchanged. Only `complete` with no failed, skipped, unavailable or pending objects clears the submitted selection.

## 7. Lifecycle

### 7.1 Who does what

| Moment | Core | Provider callbacks | Refusals |
|---|---|---|---|
| `/fields` | Builds the registry, which fires the hook once; lists descriptors and availability | `availability()`, `fields()`, `actions()`, `describe()`, `groups()` | None; unavailable actions are disabled with the reason |
| Grant issuance (`POST /requests`, `batch.apply`) | `validate_list()`, mixed-change rule, capability | `availability()`, `validate()` | 400 `bulkcat_invalid_operation`, 400 `bulkcat_mixed_change`, 409 `bulkcat_provider_unavailable` |
| Preview | Exact scope, page loading and priming, per-object permission, read, compute with overlay, result checks, rendering, warning aggregation, state token in the preview hash. Nothing is written or flushed | `prime()`, `authorise()`, `read()`, `compute()`, `describe()`, `describe_change()`, `state_token()` | 409 `bulkcat_provider_unavailable` |
| Apply, accepted receipt | Resolves the receipt first and returns the original batch **without calling the provider** | None | As EXACT-SELECTION |
| Apply, new acceptance | Availability, validation, recomputed `scope_hash`, recomputed state token, so a recomputed preview hash is required. Header, items, receipt and **provider manifest** commit in one transaction | `availability()`, `validate()`, `state_token()` | 409 `bulkcat_preview_stale` (`data.reason`: `scope_changed`, `provider_changed` or `external_state_changed`), 409 `bulkcat_provider_unavailable` |
| Each chunk | Checks the provider against the manifest, then the per-object write path (§7.3); flush in `finally` | `prime()`, `authorise()`, `read()`, `compute()`, `write()`, `describe()`, `flush()` | Object-level results; stop rule (§11) |
| Replay of an interrupted object | §8.5 | `read()`, `same()`, `write()` | `bulkcat_changed_during_write` |
| Completion, history, audit, export | Existing statuses; rendering from the manifest and envelopes | None | None |
| Undo plan and run | §8.4 | `availability()`, `authorise()`, `read()`, `same()`, `describe()`, `upgrade()`, `write()`, `flush()` | 409 `bulkcat_not_reversible` (`data.reason`: `provider_unavailable`) |

### 7.2 Preview binding

The preview hash (`Engine\Preview::hash()`) additionally binds an `external` member: the provider's id, version and contract, each named field's format, and `state_token()`. The token is the provider's fingerprint of everything outside the per-object values that its computation depends on: for example the list of offered choices and their labels, relevant configuration, and cheap aggregate markers of the stored values across the scope. Apply acceptance recomputes it. Any difference refuses 409 `bulkcat_preview_stale` and asks for a new preview. A token must be deterministic and bounded in cost (§14).

As for native changes, this is scope and state validation, not a lock. The writer still computes against current values when each chunk runs.

**Canonical intent.** At receipt resolution (`Requests\BatchIntent::normalize()` on an already canonical intent), external steps are checked for shape only: action id syntax, provider id, and JSON-plain arguments. They are not validated against the live provider, so an accepted Apply still resolves to its original batch after the provider has gone. Full validation happens only at issuance and new acceptance.

### 7.3 Per-object write path (chunk)

For each object, with Context `apply` and the batch's recorded user:

1. **Load** with `wc_get_product()`. A missing object fails with `bulkcat_not_found` (existing).
2. **Permission**, in order: `Access::user_can_edit_object()` (existing, including the variation's parent), then the descriptor capability with `user_can()`, then `Provider::authorise()`. A refusal is reported as skipped with that sentence, and nothing further is read.
3. **Variation rule** (§6).
4. **Read** every field the steps name. An `Unknown` fails every step on that field with its reason and code (default `bulkcat_unknown_state`). It is never treated as an empty value.
5. **Compute** each step in order. `Subject::value()` returns the latest computed value. The core checks that the result names the step's field, that its code prefix is allowed, and that a change is not `same()`. A violation or exception fails that step with `bulkcat_external_error` and is logged.
6. **Nothing changed:** journal as today, as already, skipped and failed rows, following T29 and the *Record unchanged rows* setting.
7. **Intent:** `Journal::intend()` writes intent rows holding the enveloped before value and the enveloped final value, before any write.
8. **Write** each touched field once, in declaration order: `write( subject, final, before, context )`. An error fails that field with the provider's sentence, and a conflict uses `bulkcat_changed_during_write`. A failure on one field does not roll back another; each is reported, as native fields are.
9. **Settle:** read back. When `same( readback, final )` the row is applied with the read-back value. Otherwise it fails with `bulkcat_external_not_stored` ("The change did not stick, so it was reported as not made.").
10. The WooCommerce product is **not saved** and its modified date does not change. Native caches belonging to the provider are the provider's job in `flush()`.

After the run (one chunk, or an undo chunk), `flush()` is called once with the ids written, inside `finally`, even when the run threw. An exception from `flush()` is logged. It never changes reported results.

Chunk sizing, leases, per-item claims, cancellation, the scheduler-stall notice and final statuses are unchanged (SPEC §12.4, §12.6). External work uses the product chunk size.

## 8. Journal, history and undo

### 8.1 Value envelope

For a `prop` starting `x_`, `old_value` and `new_value` in `bulkcat_changes` hold:

```json
{ "external": 1, "format": 1, "value": [ "blue", "red" ], "text": "Blue, Red" }
```

`value` is the provider's canonical value. `text` is `describe()` at write time, trimmed to 1,000 characters. Native rows are unchanged. The envelope is internal: REST responses and CSV exports carry `text`, never `value`.

### 8.2 Provider manifest

Schema migration **7 → 8**, after the exact-selection migration 6 → 7, adds one nullable column:

```
{prefix}bulkcat_batches
  extensions  LONGTEXT NULL   -- JSON provider manifest for a batch with external steps; NULL otherwise
```

Content, written in the acceptance transaction:

```json
{ "sample": { "label": "Sample labels", "version": "1.0.0", "contract": 1,
              "fields":  { "x_sample_labels": { "label": "Sample labels", "format": 1 } },
              "actions": { "x_sample_add": "Add labels" } } }
```

The existing `actions` column records external action ids, so the History action-type filter works. An undo batch copies its source's manifest, refreshing the version to the provider's version at undo time. Migration is idempotent `dbDelta()`. Existing rows stay NULL, and existing jobs and history are untouched.

### 8.3 History, audit and export

The history list, batch changes, audit log, and history and audit CSV exports render external rows from the manifest (field and action labels) and the envelope `text`. They never call the provider, so they stay readable after the provider is deactivated or removed. If the manifest is somehow absent, the field shows as "A value from an add-on that is not active" (no key is shown). The audit log's field filter lists the external fields of registered providers. Rows of other providers remain listed and searchable by date, user and status.

### 8.4 Undo and redo

**Refusal.** Planning refuses the whole batch with 409 `bulkcat_not_reversible`, `data.reason: provider_unavailable`, when a provider named in the manifest is not registered, not available or incompatible. The message is "This change was made with <label>, which is not active now. Turn it on again to undo this change." Reversibility (`Engine\Reversibility`) reports `not_reversible` with that reason. It is distinct from `pruned` and `outside_retention`: it is recomputed on each read, so it clears when the provider returns.

**Plan per object** (Context `undo`, the person undoing):

1. Existing permission checks (not the original author), then the descriptor capability and `authorise()`.
2. Read the current value. An `Unknown` is skipped with its reason.
3. For a journal value whose `format` is older than the field's `format()`, call `upgrade()`. A newer format, or an upgrade error, is skipped as `cannot_restore`.
4. **Conflict** when `! same( current, expected )`, where expected is the newest applied `new_value`. `conflicts` lists the field with described expected and current texts.
5. Item operations: one internal step per field, `{ "action": "restore_external", "args": { "field": "x_sample_labels", "value": <target> } }`, where target is the oldest `old_value`.

`restore_external` is a core action. It is not advertised in `/fields`, and `validate_list()` refuses it anywhere except in materialised undo items. Its compute reads the current value: `same( current, target )` gives already, otherwise changed from current to target. The writer writes with `expected` equal to the value just read, so **Revert anyway** behaves as it does natively. The plan hash covers these operations. Undo batches journal like any batch, so redo (an undo of the undo) works unchanged. A partial, failed or cancelled external batch is undone for the part applied. Unsettled intent rows are planned like applied ones (SPEC §12.3).

### 8.5 Replay after an interrupted chunk

When a retry finds intent rows for an object, nothing is computed again. For each intended field:

- `same( current, new )`: settle applied. The earlier write landed.
- `same( current, old )`: `write( new, old )`, then settle.
- anything else: settle failed with `bulkcat_changed_during_write`.

A relative action therefore cannot apply twice. If the provider is unavailable at that moment, the chunk stops (§11) and the intent rows remain for undo.

## 9. Permissions

Checks are layered. Each layer can only narrow the one before, and the core always checks first.

1. **Screen and routes:** `Access::can_read` and `Access::can_write`, with the existing `bulkcat_capability` filter and administrators-only setting (SPEC §13).
2. **Action capability:** the descriptor's `capability`, checked with `user_can()` for the requester at `/fields`, grant issuance, preview and acceptance; for the batch's recorded user on every chunk; and for the person undoing at undo plan and undo run.
3. **Per object:** `Access::user_can_edit_object()` (`edit_post`, and the parent for a variation), then `Provider::authorise( user_id, subject )`. A refused object is skipped and reported, never silently dropped.
4. **Targets:** the provider's `validate()` confirms the requester may use the chosen targets. `compute()` fails an object whose target has since gone.

Background work has no current user. The core passes the recorded user in `Context`. A batch cannot give its initiator more rights than they had, and a right revoked mid-job skips the remaining objects (SPEC §13, unchanged).

## 10. Preview rendering

- **Summary:** "<Field label> will change on 1,284 products." The existing sentence builder (`Preview::summary()`) takes the external field's label.
- **Counts:** will change, already at the target value, cannot change with each reason. These are unchanged.
- **Sample rows** for an external field carry `fieldLabel`, `old` and `new` as `describe()` text, `change` as `describe_change()` text (may be empty), `notes`, `state` and `reason`. Old text is struck through and new text is bold, as native. Raw values are never sent.
- **Warnings:** the provider's per-result warnings are aggregated by code into the existing warning block ("<sentence> — 1,200 products"). A warning with `confirm: true`, or a descriptor with `always_confirm`, makes Apply need the second click whatever the count (SPEC §4.5), restating the sentence or `confirm_reason`.
- **Sample size, changed-only filter and preview CSV download** work unchanged, using text.
- **States:** computing, ready, nothing to do, partially computable. An object whose state is `Unknown` is listed with its reason under "cannot change". It is never shown as empty.

## 11. Provider missing, unavailable or incompatible

"Unavailable" means registered, but `availability()` returned an error: for example, the plugin the provider depends on is inactive. "Missing" means not registered: deactivated, removed or refused.

| Moment | What the operator sees | Data |
|---|---|---|
| Screen load | Missing: its actions are absent. Unavailable: listed disabled with its reason. Incompatible: absent, plus one notice naming the provider's label and the reason. | Nothing written |
| Preview | 409 `bulkcat_provider_unavailable`, with the reason shown in place. The panel reloads `/fields`. | Nothing written |
| Apply, new acceptance | The same 409, or 409 `bulkcat_preview_stale` (`provider_changed`) when the version, format or state token differs from the preview. | No header, no receipt |
| Retry of an accepted Apply | Resolves by receipt to the original batch without calling the provider. | Unchanged |
| During a job | Before claiming, each chunk checks that every provider in the manifest is registered, available, contract-compatible and able to read each recorded format. If not, it claims nothing. Every still-pending item is skipped with `bulkcat_provider_unavailable`. The batch ends through the existing final-status rule, never `complete`, with the note "Stopped because <label> is not active. 312 products were changed; the rest were left alone." There is no automatic resume, which would be a blind replay. The retained submitted selection lets the operator preview and apply again once the provider returns. | Applied rows kept; intent rows kept for undo |
| History | Readable from the manifest and envelopes. Reversibility shows "Needs <label> to be active to undo." | Unchanged |
| Undo | Refused with 409 `bulkcat_not_reversible` (`provider_unavailable`). It works again after reactivation. | Unchanged |
| Provider removed for good | History stays until normal retention pruning. The core never deletes or rewrites the provider's own stored data. | Unchanged |

## 12. Versioning

- The contract version is an integer, independent of the plugin version. This document defines version 1. The core exposes it as `Contract::VERSION` and `BULKCAT_EXTENSION_CONTRACT`, and accepts `Contract::SUPPORTED`.
- Interfaces are frozen within a version. Additive, optional behaviour arrives as a new optional interface that a provider may also implement, detected with `instanceof` and listed in the changelog. Existing interfaces never gain required methods within a version.
- A breaking change creates version 2. During a transition the core supports the old and new versions together for at least one minor release and six months, showing an admin notice naming each provider still on the old version.
- **Values** carry the field's `format()`. The journal stores it, `upgrade()` converts older values for undo, and values newer than the running provider's format are refused, not guessed.
- **Provider identity** (id, version, contract) is recorded in the manifest and bound into the preview hash, so a provider update between preview and Apply requires a new preview. A version change during a running job is accepted when every recorded format can still be read.
- The core's changelog records contract changes. Provider repositories test against the lowest supported and newest core releases.

## 13. Names reserved by the contract

| Kind | Name |
|---|---|
| Action hook | `bulkcat_register_providers` — argument `Extension\Registrar $registrar`; fired once per request (§3.1) |
| Constant | `BULKCAT_EXTENSION_CONTRACT` (int, newest implemented contract; `1`) |
| Namespace | `BulkCatalogueManager\Extension`: `Contract`, `Registrar`, `Provider`, `AbstractProvider`, `ExternalField`, `ExternalAction`, `ActionDescriptor`, `ChooserGroup`, `Subject`, `Context`, `Scope`, `Unknown` |
| Result additions | `Operations\Result::with_notes()`, `Operations\Result::with_warning()` |
| Internal action | `restore_external` (undo items only) |
| Key prefix | `x_` for external field, action and chooser-group ids: `x_<provider>_<name>` |
| Request refusals | `bulkcat_provider_unavailable` (409), `bulkcat_mixed_change` (400), `bulkcat_external_not_inline` (400, an external step sent to `PATCH /products/{id}`) |
| Registration errors | `bulkcat_provider_incompatible`, `bulkcat_provider_late` |
| Object result codes | `bulkcat_changed_during_write`, `bulkcat_unknown_state`, `bulkcat_external_not_stored`, `bulkcat_external_error`, `bulkcat_value_too_large`, `bulkcat_provider_unavailable` |
| Reasons in `data.reason` | `provider_changed` and `external_state_changed` (on `bulkcat_preview_stale`); `provider_unavailable` (on `bulkcat_not_reversible`, and a Reversibility reason) |
| Schema | `bulkcat_batches.extensions`, schema version 8 |

Existing hooks keep their meaning and also cover external batches: `bulkcat_capability`, `bulkcat_batch_size`, `bulkcat_selection_ttl`, `bulkcat_batch_created`. Every new `bulkcat_` literal that is not storage goes in `tests/Unit/Data/RegistryTest.php` `NOT_STORAGE`. The new column is declared in `Data\Schema` with the migration test.

## 14. Security and performance rules

**Security**

- Provider code is trusted code that an administrator installed. The contract prevents accidental bypass and unsafe output; it cannot sandbox malicious PHP, and it does not claim to.
- Every provider string is plain text. PHP strips tags and control characters and trims to the §3.4 limits. Browser code renders text nodes only (`dangerouslySetInnerHTML` stays forbidden). Choice labels, reasons, notes, warnings and described values follow the same rule.
- Providers add no routes, scripts, styles or markup to the screen. Argument kinds are limited to §5.2, and unknown argument names are refused before the provider sees them.
- The core's capability and per-object checks always run before any provider call and cannot be skipped by a provider (§9).
- Raw external values never appear in REST responses or exports, only described text.
- Registration is accepted only during the hook. The registry cannot be altered later in the request.
- Values pass `ValueCodec` and the 64 KB cap. A larger value fails its object with `bulkcat_value_too_large`.

**Performance**

- With no provider: one `do_action()` without callbacks when the registry is built, and an identical `/fields` payload. The SPEC §12.5 and T76 budgets are unchanged and asserted.
- Descriptors, choices and availability are built once per request.
- Preview calls `prime()` for each loaded page (`Preview::PAGE`) and each chunk. After priming, `read()` and `compute()` must not issue per-object queries. The provider's own suite asserts query counts.
- `write()` should cost a few queries per object. The existing target of at least 300 products a minute at the default chunk size applies to external work. `flush()` runs once per chunk.
- `state_token()` must finish within 1.5 seconds for 20,000 products on the SPEC §12.5 host, asserted by the provider's suite. The core asserts the test provider's cost.

## 15. Core acceptance tests

### 15.1 Test provider

A neutral test double lives only in the core's test tree: `tests/Support/Extension/SampleProvider.php` and its classes, plus the mu-plugin `tests/fixtures/mu-plugins/bulkcat-sample-provider.php` for `@wordpress/env`, the probe and Playwright. `.distignore` excludes both, and the build-zip test asserts their absence.

- Provider `sample`, label "Sample labels", contract 1.
- Field `x_sample_labels` (`parent_only`): a sorted list of short label strings, stored as JSON in product post meta `_sample_labels`. Field `x_sample_note` (`per_variation`): a string in post meta `_sample_note`.
- Actions `x_sample_add`, `x_sample_remove`, `x_sample_replace`, `x_sample_clear` on labels. The first three take a `multichoice` `labels` argument whose choices come from a fixture option. `x_sample_set_note` takes a `text` argument. Chooser group `x_sample_labels`. `x_sample_replace` and `x_sample_clear` set `always_confirm`.
- Fault switches read from a test-only option: unavailable, unsupported contract, malformed descriptor, throw in compute for chosen ids, write error for chosen ids, write conflict for chosen ids, `Unknown` for chosen ids, `authorise()` refusal for chosen ids, result naming the wrong field, changed state token, slow write (lease path), and value above 64 KB.

### 15.2 Tests

| # | Test | Level | Ticket 05 criterion |
|---|---|---|---|
| E1 | No provider: `/fields` byte-identical to the snapshot; the probe's existing checks unchanged; §12.5/T76 budgets unchanged; Plugin Check clean; zip excludes the fixture | PHPUnit, probe, performance | Native behaviour, performance and interface retained |
| E2 | Registration: valid provider; duplicate id; malformed ids and prefixes; another provider's field; unknown group; reserved argument name; unsupported kind; unsupported contract; exception while describing; late registration; each limit; refused provider does not affect natives | PHPUnit | Narrow, versioned interface |
| E3 | Advertising: actions after native ones in provider order; group appended; disabled with reason when unavailable; disabled for a user without the capability; multichoice control, disabled state and mixed-change message in the panel | PHPUnit, Jest | Discoverable action |
| E4 | `validate_list()`: mixed native and external refused; two providers refused; unknown external action; spec refusals before provider `validate()`; `restore_external` refused in a bulk change; external step to `PATCH /products/{id}` refused | PHPUnit | Boundary, no bypass |
| E5 | Preview: storage checksum unchanged; `write()` and `flush()` never called; overlay across steps; the exact selected identities (two composed searches, an off-page member, an unavailable member reported by core) reach `compute()`; parent-only field on a selected variation skipped with the core sentence; `Unknown` listed as cannot change; text-only rows, notes, aggregated warnings, confirm flag | Integration | Receives exact identities; previews without writes |
| E6 | Apply binding: state token, version or format change gives 409 stale; scope drift gives 409; capability lost between preview and Apply is refused; same grant replay returns one batch; changed arguments under the same UUID give 409 `bulkcat_request_conflict`; provider removed after acceptance still resolves the receipt | Integration | Apply binds to preview, rechecks permission and state; idempotent |
| E7 | Chunk path: intent, then write, then settle; conflict, write error and exception each fail only that object and the chunk continues; not-stored detection; flush once per chunk including a throwing run; product modified date unchanged; partial statuses; cancellation | Integration | Journalled; background and partial outcomes |
| E8 | Chunk killed between write and settle: retry replays without a second application of `x_sample_add` | Integration | Idempotent execution |
| E9 | History, batch changes, audit and CSV show manifest labels and envelope text; no raw value in any REST response or export | Integration, Jest | Same history flow |
| E10 | Undo round trip leaves provider storage byte-identical; conflicts listed first with Revert anyway; redo; undo of a partial batch; format upgrade path; newer format refused | Integration | Same undo flow |
| E11 | Provider missing, unavailable or incompatible at screen load, preview, Apply, mid-job, history and undo, matching §11; restoring it re-enables undo and re-preview of the retained selection | Integration | Safe, actionable state; saved history preserved; recovery when restored |
| E12 | Permissions: anonymous and read-only users refused on every route, including an external preview; `edit_post` refusal skips; `authorise()` narrows; background work uses the recorded user with no current user; right revoked mid-job | Integration, probe | Registration cannot bypass capabilities |
| E13 | Provider strings containing HTML or script render as text in the panel, preview, history and notices; long strings are trimmed | Jest, PHPUnit | No unsafe markup |
| E14 | End to end in Playwright on the fixture site: two SKU searches building one selection, **Add labels**, preview, Apply, completion clears the selection, History entry, Undo, readback; keyboard-only; axe-core clean | End to end | Whole synthetic action and reversal |
| E15 | Performance with the fixture: 10,000-object preview and 1,000-object Apply within §12.5 targets; `/fields` time with one provider | Performance | No regression |
| E16 | Migration 7 → 8 is idempotent, existing batches and history are unaffected, and uninstall with data removal leaves the fixture's post meta untouched | Integration | Data preserved |

### 15.3 Review gates

- Independent code review of each core ticket below, as for T81 and T82.
- A generic-content check before merge. It is run from the coordinating workspace, not stored in the core, and searches the core repository for any other plugin, product-family or site name in code, docs, fixtures, strings and test names.
- `docs/EXTENSIONS.md` published in the core with this contract, and the SPEC amendments merged, before any runtime code.

## 16. Proposed core tickets and specification amendments

The tickets are T104–T109 in `docs/TICKETS.md`, built in this order.

| Ticket | Work | Tests | Blocked by |
|---|---|---|---|
| T104 | Contract documentation and generic amendments (below); `src/Extension/` interfaces, `Contract`, `Registrar`, constant; registration validation, isolation and notices | E1, E2 | Acceptance of this document. It touches no runtime path, so it can run beside T83–T85. |
| T105 | Advertising: `/fields` additions, `multichoice` spec and control, chooser groups, disabled states, confirmation flags, grid ignoring external fields | E3, E13 | T104, T84 |
| T106 | Validation, preview and Apply binding: mixed-change rule, `Subject`, `Context` and `Scope`, overlay compute, rendering, warnings, state token in the preview hash, shape-only canonical resolution | E4, E5, E6 | T104, T83 |
| T107 | External write path and journal: writer branch, compare-and-set, intent, settle and replay, envelope, manifest column (schema 8), per-chunk provider check and stop rule, flush | E7, E8, E16 | T106 |
| T108 | History, undo and reversibility: rendering from the manifest and envelopes, `restore_external`, planner branch, `provider_unavailable` reason | E9, E10, E11 | T107 |
| T109 | Test-provider end-to-end acceptance, performance and independence; delivery ticket 05 is accepted when this is green | E12, E14, E15 | T108, T85 |

**Generic amendments** (wording proposed for the core; nothing here names another system):

- **SPEC §2**, replacing the first bullet: "**Per-customer or per-group product access.** Not a WooCommerce concept, and never built into this plugin. A separately installed plugin may add actions for values it owns through the external action contract (§6.1, `EXTENSIONS.md`). This plugin then previews, journals and reverses those changes, but holds none of their logic." And in the later bullet: "editing data belonging to other plugins, except through that plugin's own registered provider (§6.1)."
- **SPEC §6.1 External actions (new):** a one-paragraph summary of §§2–7 of this document, linking to `EXTENSIONS.md`, with the rule that external changes cannot be mixed with native steps in version 1.
- **SPEC §9.2:** the `extensions` column and schema 8; the value envelope for `x_` props.
- **SPEC §9.4:** undo of external changes, `restore_external`, and the `provider_unavailable` reason.
- **SPEC §12.1:** the `/fields` additions and the external limits.
- **SPEC §12.6:** a new failure mode, "Provider unavailable" (§11).
- **SPEC §13:** the permission layering and plain-text rules of §9 and §14.
- **SPEC §15 Data lifecycle:** schema 8; uninstall never touches data owned by providers; the test provider is never shipped.
- **SPEC §16:** E1–E16 as release gates for the contract.
- **AGENTS.md hard rules**, first two bullets:
  - "Standalone: works on a bare WordPress + WooCommerce site with nothing else installed and zero site-specific code. The plugin's own actions edit native WooCommerce product data only, through WooCommerce CRUD classes. A value owned by another plugin changes only through that plugin's provider, registered on the documented external action contract (`docs/EXTENSIONS.md`)."
  - "No dependency on, module for, detection of, or mention of any third-party plugin in code, docs, fixtures or UI. The external action contract is generic and its test provider is neutral. Per-customer or per-group product access is never built into this plugin. No client names or client fields anywhere."

## 17. Points settled in review

Each point below was accepted as proposed when this contract was reviewed on 28 September 2026.

1. A user lacking an action's capability sees it **disabled with a reason** (proposed), rather than hidden.
2. Mixing native and external steps in one change is deferred to a later contract version. Version 1 keeps one kind of change per batch.
3. External grid columns, filters, inline editing and catalogue CSV export are deferred.
4. Schema version 8 assumes the exact-selection migration ships as version 7 first.
5. Losing a provider mid-job **stops and reports** (proposed). Pausing instead would need a new batch status and a resume path, which is not proposed.
6. Support window for an old contract version: at least one minor release and six months.

## 18. Implementation rulings

Where this contract left a choice open, the implementation took the conservative option and records it here.

1. **Return types on PHP 8.1.** Methods answering `true` or a `WP_Error` are declared `bool|\WP_Error` (§4); `false` reads as a refusal with a generic sentence, and from `availability()` as unavailable.
2. **Parameter name.** `ExternalField::describe_change()` names its second parameter `$new_value`, because WordPress coding standards refuse the reserved word `new`. The core calls every provider method positionally.
3. **`Unknown::because()`** takes its code as optional (`bulkcat_unknown_state` by default), and replaces a code that starts with neither `bulkcat_` nor `x_` with that default.
4. **Registration.** A provider must declare at least one field and one action. An argument spec carrying a key outside §5.2 is refused (a typo is caught at registration, not at Apply). A choice value is a non-empty string of at most 64 characters with no control characters. A `multichoice` argument's `max_items` defaults to the smaller of 100 and its number of choices, and its `min_items` to 1 when it is required, else 0. A `default` must pass its own rule. Labels and descriptions are trimmed with an ellipsis; identifiers are refused when they break their pattern.
5. **Where the core's own code lives.** The public contract is `src/Extension/`; everything else (the registry of providers, the checks, the adapters) is `src/External/` and may change without a contract version.

