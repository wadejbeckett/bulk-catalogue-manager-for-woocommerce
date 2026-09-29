# Extending Bulk Catalogue Manager: writing a provider

A short guide for writing a separately installed plugin (a **provider**) that adds actions for values it owns to the manager's bulk panel. The normative contract, with every rule, limit and error code, is [EXTENSIONS.md](EXTENSIONS.md); section numbers below point into it. Contract version: **1**. Status on branch `codex/external-action-contract`: the interfaces and registration are live from T104, advertising from T105, validation, preview and Apply binding from T106, the background write path and journal from T107, History, undo and redo from T108, and T109 proves it end to end on a real site (native probes, performance and a keyboard browser journey).

What you get: your actions appear in the same bulk panel, act on the owner's exact selection, and go through the same preview, Apply, background job, History and undo as the built-in actions. You write no screen, no REST route and no script. What you must do: compute changes without writing, and give one exact, repeatable, compare-and-set write per value.

## 1. Register

Check for the core, then register on the one action. Do it from `plugins_loaded` or later: the core may load after your plugin.

```php
add_action( 'plugins_loaded', static function (): void {
	if ( ! defined( 'BULKCAT_EXTENSION_CONTRACT' ) ) {
		return; // Show your own "needs Bulk Catalogue Manager" notice and do nothing else.
	}
	add_action( 'bulkcat_register_providers', static function ( \BulkCatalogueManager\Extension\Registrar $registrar ): void {
		if ( $registrar->supports( 1 ) ) {
			$result = $registrar->register( new My_Provider() );
			// true, or a WP_Error: bulkcat_provider_incompatible (names the first problem) or bulkcat_provider_late.
		}
	} );
}, 20 );
```

The core fires `bulkcat_register_providers` once per request, the first time its action registry is built after `init`, and refuses registrations after that (`bulkcat_provider_late`). A refused provider contributes nothing; the reason goes to the WooCommerce log (source `bulk-catalogue-manager-for-woocommerce`) and to one notice on the manager screen (§3).

## 2. The interfaces

Namespace `BulkCatalogueManager\Extension`. You implement `Provider` (usually by extending `AbstractProvider`), `ExternalField` and `ExternalAction`. The core implements `Registrar`, `Subject`, `Context` and `Scope`. `Operations\Result` and `Operations\Validation` are the core's existing value classes.

PHP 8.1 has no `true` type: every method answering "true or a refusal" is declared `bool|\WP_Error` (return `true`, never `false`).

```php
final class Contract  { const VERSION = 1; const SUPPORTED = [ 1 ]; const HOOK = 'bulkcat_register_providers'; }

final class Registrar {
	public function contract(): int;
	public function supports( int $version ): bool;
	public function register( Provider $provider ): bool|\WP_Error;
}

interface Provider {
	public function id(): string;                    // [a-z][a-z0-9]{2,19}, no underscore, unique on the site
	public function label(): string;                 // translated plain text, ≤ 80 characters
	public function version(): string;               // your plugin's version
	public function contract(): int;                 // 1
	public function availability(): bool|\WP_Error;  // true, or the reason shown beside your disabled actions
	public function fields(): array;                 // list<ExternalField>, 1–5
	public function actions(): array;                // list<ExternalAction>, 1–20, in chooser order
	public function groups(): array;                 // list<ChooserGroup>, 0–2
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error; // may only narrow
	public function state_token( Scope $scope, array $steps, Context $context ): string;
	public function prime( array $object_ids ): void;
	public function flush( array $object_ids, Context $context ): void;
}
// AbstractProvider: groups() [], authorise() true, prime() and flush() do nothing.

interface ExternalField {
	const PARENT_ONLY = 'parent_only'; const PER_VARIATION = 'per_variation';
	const CONFLICT = 'bulkcat_changed_during_write';    // write()'s error code on a compare-and-set miss
	public function key(): string;                      // x_<provider id>_<name>, ≤ 64 characters
	public function label(): string;
	public function variation_rule(): string;           // PARENT_ONLY or PER_VARIATION
	public function format(): int;                      // value shape version, from 1
	public function read( Subject $subject ): mixed;    // canonical value, or Unknown::because( … )
	public function same( mixed $a, mixed $b ): bool;
	public function describe( mixed $value, Subject $subject ): string;
	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string; // may be ''
	public function write( Subject $subject, mixed $target, mixed $expected, Context $context ): bool|\WP_Error;
	public function upgrade( mixed $value, int $from_format ): mixed; // value in format(), or WP_Error
}

interface ExternalAction {
	public function describe(): ActionDescriptor;
	public function validate( array $args, Context $context ): \BulkCatalogueManager\Operations\Validation;
	public function compute( Subject $subject, array $args, Context $context ): \BulkCatalogueManager\Operations\Result;
}

final class ActionDescriptor {
	public function __construct( string $id, string $field, string $group, string $label, string $description,
		string $edit_kind, array $arguments, string $capability, bool $always_confirm = false, string $confirm_reason = '' );
}
final class ChooserGroup { public function __construct( string $id, string $label ); } // id x_<provider id>_<name>

interface Subject {   // one available product or variation
	public function id(): int; public function type(): string; // 'product' or 'variation'
	public function parent_id(): int; public function product(): \WC_Product; // read-only
	public function value( string $field ): mixed;               // your field, including earlier steps of this change
}
interface Context {   // PREVIEW, APPLY, REPLAY, UNDO
	public function mode(): string; public function user_id(): int; public function batch_id(): int; // 0 while previewing
}
interface Scope {     // for state_token() only
	public function count(): int; public function chunks( int $size ): iterable; // ascending id lists
}
final class Unknown { public static function because( string $reason, string $code = 'bulkcat_unknown_state' ): self; }
```

`Operations\Result` (existing): `Result::changed( $field, $old, $new )`, `already( $field, $value )`, `skipped( $field, $old, $reason, $code )`, `failed( $field, $old, $reason, $code )`; for external results the code may start `bulkcat_` or `x_<provider id>_`. Additions any result may carry: `->with_notes( [ 'Up to three plain sentences.' ] )` under its preview row, and `->with_warning( $code, $sentence, $confirm = false )`, at most one, which the preview aggregates by code with a count; `confirm: true` makes Apply need the second click.

`Operations\Validation` (existing): `Validation::passed( $args )` or `Validation::refused( \WP_Error )`.

## 3. Describing an action

```php
new ActionDescriptor(
	'x_myaddon_add',            // id: x_<provider id>_<name>
	'x_myaddon_things',         // the one field it changes, one of yours
	'x_myaddon_things',         // chooser group: one of yours, or a native one (pricing, stock, organisation, shipping_tax, publishing)
	'Add things',               // label, plain
	'Adds the chosen things. Other things are kept.', // one-line description, plain
	'add',                      // edit kind: set, clear, adjust, round, copy_from_sale, copy_from_regular, find_replace, prefix, suffix, add, remove
	[ 'things' => [ 'type' => 'multichoice', 'label' => 'Things', 'rule' => 'Choose at least one thing.',
		'required' => true, 'choices' => [ 'a' => 'Thing A', 'b' => 'Thing B' ], 'max_items' => 20 ] ],
	'edit_products',            // capability, checked with user_can()
	false, ''                   // always_confirm, confirm_reason
);
```

Argument kinds: `boolean`, `text`, `integer`, `decimal`, `choice`, `multichoice`. Spec keys: `type`, `label`, `rule` (the sentence a refusal shows), and optionally `required`, `default`, `choices` (value => label, at most 500), `min`, `max`, `exclusive_min`, `exclusive_max`, `max_length`, `allow_empty`, `min_items`, `max_items` (at most 100). Any other key is refused at registration. Do not declare `field`, `skip_already` or `apply_to_variations`: the core adds them (`apply_to_variations` only for a per-variation field). The core checks every argument against its spec, and refuses unknown names, before it calls your `validate()`; a multichoice value reaches you as distinct values in the order your choices are offered.

Choices are read when descriptors are read (once per request). A choice that disappears between preview and Apply is caught by your `state_token()` and your `validate()`.

## 4. Lifecycle

| Moment | What the core does | What it calls on you |
|---|---|---|
| `GET /fields` | Lists your fields, actions (after the native ones), groups and availability | `availability()`, `fields()`, `actions()`, `describe()`, `groups()` |
| Grant issuance, preview, new Apply | Validates the steps: capability, one kind of change, specs | `availability()`, `validate()` |
| Preview | Loads each page, checks its own permissions, then yours; reads, computes with an overlay (a later step sees an earlier step's value); renders text; nothing is written or flushed | `prime()`, `authorise()`, `read()`, `compute()`, `describe()`, `describe_change()`, `state_token()` |
| Apply, new acceptance | Recomputes the exact scope and your state token; a difference asks for a new preview (409 `bulkcat_preview_stale`); records your manifest (label, version, field labels and formats, action labels) with the batch | `availability()`, `validate()`, `state_token()` |
| Apply, retried | Answers the original batch by its receipt, without calling you | — |
| Each chunk (background) | Checks you are still registered, available and able to read the recorded formats; per object: core permission, capability, `authorise()`, variation rule, read, compute, intent row, one write per field, read-back | `prime()`, `authorise()`, `read()`, `compute()`, `write()`, `describe()`, then `flush()` once, even if the run threw |
| Replay of an interrupted object | Compares the stored value with the recorded before and after; writes only when it still holds the before value | `read()`, `same()`, `write()` |
| History, audit, CSV | Renders from the manifest and the stored text | nothing |
| Undo, redo | Plans per object: permissions, `authorise()`, read, `upgrade()` older formats, conflict when the value moved; writes the old value back | `availability()`, `authorise()`, `read()`, `same()`, `describe()`, `upgrade()`, `write()`, `flush()` |

## 5. The rules your code must keep

- `read()`, `compute()`, `describe()`, `describe_change()`, `same()`, `state_token()` and `prime()` write nothing anywhere, including caches that change behaviour.
- `compute()` reads current values only through `Subject::value()`, never from storage, and answers a Result on its own action's field. A change must not be `same()` as the old value.
- `write( $subject, $target, $expected, $context )` is absolute and compare-and-set: already `$target` → `true` without writing; holds `$expected` → write, `true`; anything else → `new \WP_Error( ExternalField::CONFLICT, 'plain sentence' )` and nothing written. It must be safe to call again for the same object after a crash, and touch only that field's storage. The core reads the value back afterwards and reports "did not stick" when `same( read-back, target )` is false.
- `read()` returns `Unknown::because( 'plain sentence', 'x_myaddon_code' )` when it cannot read a value, never an empty value.
- `same()` is reflexive and symmetric and compares canonical forms.
- Never rely on the current user: `Context::user_id()` is the only identity (background work has none), and checks use `user_can()`.
- Values are JSON-plain (null, scalars, arrays of those), at most 64 KB encoded (`bulkcat_value_too_large`).
- Every string you give is shown as plain text: tags are stripped and long strings trimmed (labels 80, descriptions 300, reasons 255, notes 3 × 200, described values 1,000).
- Bump `format()` when your value's shape changes, and convert older values in `upgrade()`; the core refuses to undo a value newer than your running format.
- `state_token()` must be deterministic and cheap: at most 1.5 seconds for 20,000 products (§14). After `prime()`, `read()` and `compute()` must not query per object.

## 5a. What the core guarantees you

- `prime()` is called before any `read()` or `compute()` of the objects it names: once per page of a preview (available members only) and once per chunk of a job.
- `flush()` is called once per run that wrote (a chunk, or an undo chunk), with the ids of the objects your `write()` answered `true` for, in `finally`, so even when the run threw; never in a preview.
- `Subject::value()` returns what an earlier step of the same change computed for that field, else the value your `read()` gave (read once per object), else the `Unknown` it gave; `compute()` is never called for a field whose value is an `Unknown`.
- A selected variation meets a `parent_only` field before `compute()`: the core skips it with “<label>” is kept on the product, not on each variation. (after the capability check and `authorise()`), so `compute()` only sees products for such a field.
- `validate()` receives arguments the core has already checked against your spec and normalised: unknown names refused, defaults filled in, `multichoice` values distinct and in the order your choices are offered, `apply_to_variations` present only for a `per_variation` field, `skip_already` present. What you return in `Validation::passed()` is not used; return the arguments you were given.
- `Result::with_notes()`, `Result::with_warning()` and `x_<provider id>_` result codes are in the core from T106, as §2 describes; notes are shown under the preview row (at most three, 200 characters each), warnings aggregate by code with a count of objects (each once, products and variations apart), and `confirm: true` makes Apply need its second click.
- `write( $subject, $target, $expected, $context )` gets as `$expected` the value the core read for this object in the same run (for an undo, the value read just before, so Revert anyway works), and the core reads the field back after it.
- An action the current user cannot use now (they lack its capability, or you are unavailable) is listed disabled with only its label and the reason. Its description, argument schema (choices, defaults, argument labels and rules) and confirmation text are not sent to that browser. `authorise()` is per object and is never consulted for `/fields`; an object it refuses shows only your reason as that object's skip.
- Limits and registration checks refuse the **whole provider**, not only the offending action: for example an argument offering more than 500 choices gives one notice on the manager screen ("<your label> could not be added to Bulk Catalogue Manager: its action "x_myaddon_add" has an argument "things" that offers more than 500 choices. Update the add-on, or ask its author. Everything else works as usual."), and none of your actions is listed. Cap your own lists below the §3.4 limits.

## 6. Refusals and results

- Registration: `bulkcat_provider_incompatible` (the message names the first problem), `bulkcat_provider_late`.
- Requests: 409 `bulkcat_provider_unavailable` (your `availability()` reason, or "not active"), 400 `bulkcat_mixed_change` (in version 1 a change holds either native steps or one provider's steps), 400 `bulkcat_invalid_operation` (argument spec or your `validate()` refusal), 403 `bulkcat_forbidden` (the action's capability), 409 `bulkcat_preview_stale` (`data.reason`: `scope_changed` or `provider_changed`), 400 `bulkcat_external_not_inline` (your field sent to the single-product edit route).
- Object results: `bulkcat_changed_during_write`, `bulkcat_unknown_state`, `bulkcat_external_not_stored`, `bulkcat_external_error` (your code threw or broke the contract), `bulkcat_value_too_large`, `bulkcat_provider_unavailable`. Your own result codes start `x_<provider id>_`; reasons are plain sentences with no codes in them.
- Undo: 409 `bulkcat_not_reversible` with `data.reason: provider_unavailable` until you are active again.
- Your exceptions never reach the browser: they become a refusal or a failed result, and the details go to the WooCommerce log.

## 7. When you are missing

Deactivated or refused: your actions disappear; unavailable: they are listed disabled with your reason. A job that loses you stops before its next chunk, skips what is left and says how many products were changed. History stays readable from the stored text and your manifest. Undo waits until you are back. The core never deletes or rewrites your data, and its uninstall leaves your data alone.

## 8. An example: the test provider

The core's own acceptance tests use a neutral provider, "Sample labels", in `tests/Support/Extension/` (never shipped). Read it as a worked example:

- `SampleProvider.php` — `extends AbstractProvider`: id `sample`, contract 1, `authorise()`, `state_token()` over its choices, `prime()`, `flush()`.
- `LabelsField.php` — `x_sample_labels`, parent only: a sorted list stored as JSON in post meta, with a compare-and-set `write()`.
- `NoteField.php` — `x_sample_note`, per variation: a line of text.
- `LabelsAction.php` — add, remove, replace and clear labels with a `multichoice` argument; notes and warnings on the result; replace and clear always confirm.
- `NoteAction.php` — set the note, in the native Organisation group.
- `tests/fixtures/mu-plugins/bulkcat-sample-provider.php` — the registration above as a must-use plugin.

## 9. Testing your provider

Test against the lowest supported and the newest core release. Assert in your own suite that `read()` and `compute()` issue no per-object queries after `prime()`, that `state_token()` stays within budget at 20,000 products, and that `write()` refuses a stale `$expected` and is a no-op when repeated.
