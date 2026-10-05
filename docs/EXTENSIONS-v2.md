# External action contract, version 2

Normative. Frozen on 5 October 2026 for release 1.4.0. Version 2 is a **minimal, additive** extension of version 1 ([EXTENSIONS.md](EXTENSIONS.md)). Everything version 1 says still holds unless a section below says otherwise. A provider written for version 1 registers, is advertised, previews, applies, journals and undoes exactly as before, with byte-identical `/fields` entries.

Version 2 adds four things and nothing else:

1. **Negotiation:** contract version 2 beside 1, and named features a core may or may not implement yet (§2).
2. **Lookups:** an argument kind `lookup` whose choices the provider searches and pages on demand, with server-side resolution (§4).
3. **Category target:** actions acting on native product categories instead of products (§5).
4. **Row entry:** an action offered on one row, scoped to that row only (§6).

The public interfaces live in `src/Extension/` (namespace `BulkCatalogueManager\Extension`). The text names no other plugin, product family or site; its examples use the neutral test provider of §9.

## 1. Compatibility rules

- `Contract::VERSION` and `BULKCAT_EXTENSION_CONTRACT` are `2`. `Contract::SUPPORTED` is `[ 1, 2 ]`. Both versions are supported together for at least one minor release and six months after any future version 3 (EXTENSIONS.md §12).
- No version 1 interface gains a required method. `Provider`, `ExternalField`, `ExternalAction`, `Subject`, `Scope` and `AbstractProvider` are unchanged. `ActionDescriptor` gains two optional, immutable options with version 1 defaults. `Context` gains one constant (`LOOKUP`), and only the core implements `Context`.
- A provider declaring `contract() === 1` that uses any version 2 option is refused (§2.2). A provider declaring `contract() === 2` that uses none behaves exactly as a version 1 provider.
- Accepted requests, running and finished batches, manifests, envelopes and undo of version 1 work are unchanged. Nothing in a version 1 batch's preview hash, manifest or journal changes shape.
- Interfaces are frozen within version 2. A later change is additive (a new optional interface detected with `instanceof`, a new feature name, a new optional descriptor option) and is announced in the changelog.

## 2. Negotiation

### 2.1 Versions and features

```php
final class Contract {
	public const VERSION   = 2;
	public const SUPPORTED = [ 1, 2 ];
	public const HOOK      = 'bulkcat_register_providers';

	public const FEATURE_LOOKUP          = 'lookup';          // §4
	public const FEATURE_ROW_ENTRY       = 'row_entry';       // §6
	public const FEATURE_CATEGORY_TARGET = 'category_target'; // §5
	public const DEFINED_FEATURES = [ 'lookup', 'row_entry', 'category_target' ];
	public const FEATURES         = [ /* the subset this core implements now */ ];
}

final class Registrar {
	public function contract(): int;                          // Contract::VERSION
	public function supports( int $version ): bool;           // in Contract::SUPPORTED
	public function supports_feature( string $feature ): bool; // in Contract::FEATURES (new in version 2)
	public function register( Provider $provider ): bool|\WP_Error;
}
```

A feature is listed in `Contract::FEATURES` only when the core implements it end to end (advertising, validation, preview, Apply, journal, History and undo). A core may support contract 2 while implementing only some features; a provider asks before declaring one:

```php
add_action( 'bulkcat_register_providers', static function ( Registrar $registrar ): void {
	if ( $registrar->supports( 2 ) && $registrar->supports_feature( 'lookup' ) ) {
		$registrar->register( new My_Provider( row: $registrar->supports_feature( 'row_entry' ),
		                                       categories: $registrar->supports_feature( 'category_target' ) ) );
	} elseif ( $registrar->supports( 1 ) ) {
		$registrar->register( new My_Provider_Without_Lookups() ); // contract() returns 1
	}
} );
```

A provider on a core older than version 2 sees `BULKCAT_EXTENSION_CONTRACT === 1` and no `supports_feature()` method; it registers its version 1 actions only.

### 2.2 What the registrar checks (additions to EXTENSIONS.md §3.2)

The whole provider is refused with `bulkcat_provider_incompatible`, naming the first problem, when:

- an action uses a feature (a `lookup` argument, `with_row_entry()`, or a category target) while the provider declares contract 1;
- an action uses a feature not in `Contract::FEATURES`;
- an action's target is neither `product` nor `product_cat`;
- an action has a `lookup` argument but does not implement `LookupSource`;
- a `lookup` spec carries `choices` or `default`; `multiple` is not boolean; a single lookup sets `min_items` or `max_items`; a multiple lookup's `max_items` is outside 1–100 or below `min_items`; or `multiple` appears on any other kind;
- one field is changed by actions of different targets;
- a field changed by category actions declares `per_variation`;
- the provider has a category action but does not implement `CategoryProvider`.

## 3. Interfaces added in version 2

```php
// ActionDescriptor gains (immutable; each returns a copy):
public const TARGET_PRODUCT  = 'product';      // default
public const TARGET_CATEGORY = 'product_cat';
public function for_target( string $target ): ActionDescriptor;            // feature category_target
public function with_row_entry( bool $row_entry = true ): ActionDescriptor; // feature row_entry
public function target(): string;
public function row_entry(): bool;

// Context gains:
public const LOOKUP = 'lookup';

interface LookupSource {  // implemented by an ExternalAction that declares a lookup argument
	public function lookup( string $argument, string $query, int $page, Context $context ): LookupPage|\WP_Error;
	/** @param list<string> $ids  @return list<LookupItem> */
	public function resolve( string $argument, array $ids, Context $context ): array;
}

final class LookupPage {
	public const SIZE      = 20;   // items per page
	public const MAX_PAGE  = 500;  // highest page the core asks for; pages count from 1
	public const MAX_QUERY = 100;  // longest search text, in characters
	public function __construct( array $items /* list<LookupItem> */, bool $has_more );
	public static function none(): LookupPage;
	public function items(): array;
	public function has_more(): bool;
}

final class LookupItem {
	public const ID = '/^[^\x00-\x1F\x7F]{1,64}$/u';
	public function __construct( string $id, string $label, string $detail = '' );
	public function id(): string;
	public function label(): string;
	public function detail(): string;
}

final class ObjectRef {
	public const PRODUCT = 'product'; public const VARIATION = 'variation'; public const TERM = 'term';
	public const TAXONOMIES = [ 'product_cat' ];
	public static function product( int $id ): ObjectRef;
	public static function variation( int $id ): ObjectRef;
	public static function term( string $taxonomy, int $term_id ): ObjectRef;
	public static function from_key( string $key ): ?ObjectRef;   // 'product:41', 'variation:41', 'term:product_cat:41'
	public function kind(): string;
	public function taxonomy(): string;  // '' for posts
	public function id(): int;
	public function key(): string;
	public function equals( ObjectRef $other ): bool;
}

interface CategorySubject extends Subject {   // core-implemented
	public function ref(): ObjectRef;          // ObjectRef::term( 'product_cat', id() )
	public function taxonomy(): string;        // 'product_cat'
	public function term(): \WP_Term;          // read-only
}

interface CategoryScope extends Scope {       // core-implemented
	public function taxonomy(): string;        // 'product_cat'; chunks() yields term ids
}

interface CategoryProvider {                  // implemented by a Provider with category actions
	public function prime_terms( string $taxonomy, array $term_ids ): void;
	public function flush_terms( string $taxonomy, array $term_ids, Context $context ): void;
}
```

Method rules of EXTENSIONS.md §4 extend to the new methods: `lookup()`, `resolve()` and `prime_terms()` never write; every string is plain text; exceptions are caught and logged and never reach the browser (EXTENSIONS.md §3.3).

## 4. Lookups (feature `lookup`)

### 4.1 Argument spec

```php
'recipients' => [
	'type'      => 'lookup',
	'label'     => 'Recipients',
	'rule'      => 'Choose at least one recipient from the list.', // the refusal sentence
	'required'  => true,
	'multiple'  => true,   // default false: one item
	'min_items' => 1,      // multiple only; default 1 when required, else 0
	'max_items' => 20,     // multiple only; 1–100, default 100
],
```

- No `choices` and no `default`: choices come from the provider, and a lookup starts empty.
- **Canonical value.** Single: one id string, or the argument is absent when not required. Multiple: a list of distinct id strings, sorted in ascending byte order, so two submissions meaning the same thing store and hash the same. Each id matches `LookupItem::ID`.
- An action may declare lookup arguments beside version 1 kinds.

### 4.2 `lookup()` and `resolve()`

- **Ids are provider-owned and opaque.** The core never assumes an id is a user, group, post or anything else, never parses it and never maps it to WordPress data. The provider must recognise an id again in later requests, background chunks, undo and redo, so ids are stable for as long as the choice exists.
- **`lookup( argument, query, page, context )`** answers one page of choices matching `query` (plain text, trimmed, at most `MAX_QUERY` characters; `''` lists everything) in the provider's order. `page` counts from 1 up to `MAX_PAGE`. Context: mode `lookup`, the requester, batch 0. A provider returns at most `SIZE` items; the core keeps the first `SIZE` of a longer page and reports `has_more` true. The core drops an item whose id breaks `LookupItem::ID` or whose label is empty, and trims labels to 80 and details to 120 characters. A `WP_Error` answer is shown as the provider's plain sentence. The provider decides what a match is; it must only list choices that `resolve()` would accept for the same user.
- **`resolve( argument, ids, context )`** returns the subset of the given distinct ids that may be chosen now by `context->user_id()`, with their current labels. Items naming ids that were not asked are ignored. An empty answer means none is valid.
- **Bound.** One `lookup()` call per request. One `resolve()` call per lookup argument per validation. Neither may cost more than a page-sized query; a provider with large directories uses an index.

### 4.3 Authorisation

Before calling `lookup()`, the core checks in order: the screen right (`Access::can_write`), the action exists and is advertised enabled, the provider is available, the requester holds the descriptor's capability, and — when the request names a row (§6) — the core's per-object check for that object and then `Provider::authorise( user, subject )`. Any refusal stops the request before the provider is asked. The same checks apply to `resolve()` at grant issuance, preview and acceptance, as part of validation. The provider may narrow further in `lookup()` and `resolve()` themselves (for example by hiding choices a user may not grant). Responses carry only id, label and detail.

### 4.4 Validation and binding

- **Where.** At grant issuance (`POST /requests`), preview and a new Apply acceptance, after the core's spec check and before `ExternalAction::validate()`, the core calls `resolve()` for every lookup argument. If any submitted id is missing from the answer, the request is refused **400 `bulkcat_choice_unavailable`**, with the argument's `rule` sentence and `data.argument` (the argument name). Nothing is previewed or accepted.
- **Binding.** The resolved ids and labels are bound into the preview hash's `external` member (as `choices`). A choice renamed, withdrawn or denied between preview and Apply therefore refuses the Apply with 409 `bulkcat_preview_stale` (`data.reason: provider_changed`), or 400 `bulkcat_choice_unavailable` when it no longer resolves. The provider should also cover choice state in `state_token()`, as version 1 already asks.
- **Receipts.** Resolution of an already accepted request returns the original batch without calling `resolve()` (EXTENSIONS.md §7.2, unchanged).
- **Chunks, replay, undo and redo** do not call `lookup()` or `resolve()`. A choice that disappears after acceptance is the provider's to detect in `compute()`, which fails that object with its own sentence (EXTENSIONS.md §9 rule 4). Undo and redo write stored absolute values back and never need the choice to exist.

### 4.5 Labels in History

At acceptance the core writes the resolved labels into the batch manifest, beside the action labels:

```json
{ "picks": { "label": "Sample picks", "version": "2.0.0", "contract": 2,
             "fields":  { "x_picks_items": { "label": "Sample picks", "format": 1 } },
             "actions": { "x_picks_add": "Add recipients" },
             "choices": { "x_picks_add": { "recipients": { "r0001": "Recipient 0001", "r0042": "Recipient 0042" } } } } }
```

`choices` appears only for batches with lookup arguments, so version 1 manifests are unchanged. History, batch reports and their exports show a lookup argument as its stored labels, never as raw ids, and only to readers allowed to see that step's arguments (EXTENSIONS.md §18.20). An id with no stored label shows as "A choice that is no longer listed". Values in the journal remain the provider's own `describe()` text (EXTENSIONS.md §8.1).

### 4.6 Advertising

In `/fields`, a lookup argument is advertised with a `lookup` member and never with `enum` or `choices`:

```json
"recipients": { "title": "Recipients", "type": "array", "items": { "type": "string" }, "uniqueItems": true,
                "minItems": 1, "maxItems": 20, "lookup": { "multiple": true }, "required": true,
                "description": "Choose at least one recipient from the list." }
"recipient":  { "title": "Recipient", "type": "string", "lookup": { "multiple": false }, "required": true,
                "description": "Choose one recipient from the list." }
```

A disabled action withholds its argument schema as in EXTENSIONS.md §18.17.

### 4.7 Core lookup route (informative; not part of the provider contract)

`GET /bulkcat/v1/lookup?action=<id>&argument=<name>&query=<text>&page=<n>[&object=<ObjectRef key>]` answers `{ "items": [ { "id", "label", "detail" } ], "page": n, "hasMore": bool }` with `Cache-Control: no-store`. Refusals: 400 `bulkcat_invalid_lookup` (unknown action or argument, not a lookup, page outside 1–`MAX_PAGE`, query too long, malformed object), 403 `bulkcat_forbidden` (screen, capability, object or `authorise()`), 409 `bulkcat_provider_unavailable`, 409 `bulkcat_lookup_failed` (the provider's `WP_Error` sentence), 409 `bulkcat_external_error` (an exception; details logged). Providers add no routes of their own (EXTENSIONS.md §14 unchanged).

## 5. Category target (feature `category_target`)

### 5.1 Declaring

`(new ActionDescriptor( … ))->for_target( ActionDescriptor::TARGET_CATEGORY )`. The action acts on native product categories (`product_cat` terms) only. A field's target is the target of the actions that change it (all must agree; §2.2); a field no action changes is a product field. A category field declares `parent_only`; `apply_to_variations` is never added to a category action. The descriptor's `capability` is checked with `user_can()` as in version 1 (for example `manage_product_terms`).

### 5.2 Subjects

For a category action, every method that takes a `Subject` (`ExternalField::read()`, `describe()`, `describe_change()`, `write()`, `ExternalAction::compute()`, `Provider::authorise()`) receives a `CategorySubject`:

| Method | Answer |
|---|---|
| `id()` | the term id |
| `type()` | `product_cat` |
| `parent_id()` | always `0` (the parent category is `term()->parent`), so no product code reads a category as a variation's parent |
| `product()` | throws `\LogicException` |
| `value( field )` | as version 1 (read, or what an earlier step computed) |
| `ref()` | `ObjectRef::term( 'product_cat', id )` |
| `term()` | the `WP_Term`, read-only |

A provider that serves both targets checks `$subject instanceof CategorySubject` before using `id()`. The core never passes a category subject to a product field or a product subject to a category field.

### 5.3 Selection, scope and preview

- **Exact selection.** A category change acts on an explicit list of term ids chosen by the operator (one category from its row, or a selection in the Category Manager); never on "all categories" by inference. At most 1,000 categories in one change.
- **Include subcategories.** Off by default: a parent category targets that category only. When the operator turns it on, the core expands the chosen categories to their descendants **once, at preview**, removes duplicates from overlapping branches and sorts the set. The preview shows that exact set and its count. Apply carries the captured set and the scope hash binds it, so a category added, moved or deleted later never widens or narrows the accepted set silently: a difference at acceptance refuses 409 `bulkcat_preview_stale` (`scope_changed`), and a term deleted after acceptance is skipped as unavailable (`bulkcat_unavailable`).
- **Scope.** `state_token()` receives a `CategoryScope`; `chunks()` yields ascending term ids of `product_cat`.
- **One kind of change.** A change contains steps of one provider **and one target**. Mixing product and category steps is refused 400 `bulkcat_mixed_change`, at the same points as version 1.
- **Preview** counts categories, not products ("Category picks will change on 12 categories."). Any effect on products is the provider's to describe in notes or warnings; the core does not compute it.

### 5.4 Permissions, write path, journal

- **Per object**, in order: the term still exists in `product_cat`; `user_can( user, 'edit_term', term_id )`; the descriptor's capability; `Provider::authorise( user, category_subject )`. A refusal skips that category with its sentence.
- **Write path** as EXTENSIONS.md §7.3, with these substitutions: load is `get_term( id, 'product_cat' )`; priming calls `CategoryProvider::prime_terms( 'product_cat', ids )` instead of `Provider::prime()`; after each run that wrote, `CategoryProvider::flush_terms( 'product_cat', ids, context )` is called once in `finally` instead of `Provider::flush()`; the term itself is never updated by the core.
- **Journal.** Items and changes record `object_type = 'product_cat'` and `object_id` = the term id. A row is identified by both columns, so a term id never collides with a product id of the same number. Values use the version 1 envelope.
- **Compare-and-set, read-back, intent rows, replay (Context `replay`), stop rule, provider manifest, undo, redo and Revert anyway** are exactly as version 1, per category instead of per product. Undo re-checks `edit_term`, the capability and `authorise()` for the person undoing. A category deleted before undo is skipped as unavailable.
- **History** names each category by its name at the time of reading, or "A category that no longer exists" when gone, and filters by batch as today.

## 6. Row entry (feature `row_entry`)

- `->with_row_entry()` offers the action in a row's menu: a product row in the catalogue (product target) or a category row in the Category Manager (category target). It stays available in the bulk workflow too.
- **Scope is that row only**: the one product (with **Also apply to variations** offered as in bulk for a `per_variation` field) or the one category (with **Include subcategories** offered as in §5.3). The row entry neither reads nor changes the retained selection: unrelated selected rows are not included, and completing the row change does not clear them.
- **Same flow.** The row entry opens the same panel at the action's arguments, then Preview, Apply, background job, History and Undo, exactly as a bulk change of one object. It is never an ordinary details Save. Confirmation follows the same rules (`always_confirm`, confirming warnings, the count threshold).
- A variation row offers the entry only for an action on a `per_variation` field. An entry the user may not use is listed disabled with the reason, as in the chooser (EXTENSIONS.md §5.3).
- `/fields` marks such actions `"row": true`; actions without it carry no `row` key.

## 7. Lifecycle summary for version 2

| Moment | Additions to EXTENSIONS.md §7.1 |
|---|---|
| `/fields` | Lookup argument schema (§4.6); `row` and `target` keys only when not the default |
| Lookup request | §4.3 checks, then `lookup()` |
| Grant issuance, preview, new acceptance | `resolve()` for each lookup argument (§4.4); category scope expansion at preview (§5.3); `state_token()` with a `CategoryScope` for category changes |
| Each chunk, replay, undo | Unchanged; category runs use `prime_terms()` and `flush_terms()` |
| History, exports | Choice labels from the manifest (§4.5); category names (§5.4) |

`/fields` action entries of a version 2 provider gain `"target": "product_cat"` for category actions and `"row": true` for row entries; neither key appears when it would hold the default, so a version 1 provider's entries are byte-identical.

## 8. Reserved names added by version 2

| Kind | Name |
|---|---|
| Constants | `Contract::FEATURE_LOOKUP`, `FEATURE_ROW_ENTRY`, `FEATURE_CATEGORY_TARGET`, `DEFINED_FEATURES`, `FEATURES`; `Context::LOOKUP`; `ActionDescriptor::TARGET_PRODUCT`, `TARGET_CATEGORY` |
| Interfaces and classes | `LookupSource`, `LookupPage`, `LookupItem`, `ObjectRef`, `CategorySubject`, `CategoryScope`, `CategoryProvider` |
| Argument kind | `lookup` (spec key `multiple`) |
| Request refusals | `bulkcat_choice_unavailable` (400), `bulkcat_invalid_lookup` (400), `bulkcat_lookup_failed` (409) |
| Journal object type | `product_cat` |
| Manifest member | `choices` |

## 9. Neutral test provider

`tests/Support/Extension/Picks*.php` (never shipped; excluded like the version 1 provider): provider `picks`, label "Sample picks", contract 2, implementing `CategoryProvider`.

- Fields: `x_picks_items` (product, `parent_only`, post meta `_sample_picks`) and `x_picks_category_items` (category, term meta `_sample_picks`), each a sorted list of recipient ids.
- A directory of 5,000 recipients `r0001`…`r5000` ("Recipient 0001", detail "Team A"–"Team E"), searched by label, detail or id, 20 per page.
- Actions: `x_picks_add` and `x_picks_remove` (multiple lookup `recipients`, row entry), `x_picks_only` (single lookup `recipient`, always confirms), `x_picks_clear`; `x_picks_category_add` and `x_picks_category_remove` (category target, multiple lookup, row entry).
- Fault switches in option `sample_picks_faults`: `unavailable`, `version`, `contract`, `size`, `withdrawn` (ids no longer offered), `restricted` (ids only a user with `manage_options` may choose), `renamed` (id ⇒ label), `lookup_error`, `refuse` (product ids), `refuse_terms` (term ids), `write_conflict`, `state`.

The version 1 provider "Sample labels" (EXTENSIONS.md §15.1) is kept unchanged and proves version 1 compatibility.

## 10. Acceptance tests added by version 2

| # | Test |
|---|---|
| V1 | Negotiation: versions 1 and 2 supported; `supports_feature()` matches `Contract::FEATURES`; a version 1 provider's registration, `/fields` entries, preview hash and manifest unchanged |
| V2 | Registration refusals of §2.2 |
| V3 | Lookup route: paging and search over 5,000 choices, bounds, no-store, every refusal of §4.7, row-scoped `authorise()` |
| V4 | Resolution at issuance, preview and acceptance; withdrawn, denied and renamed choices refused or stale; receipts do not resolve; canonical ordering |
| V5 | History, batch reports and exports show stored labels, never raw ids; withheld from readers without the capability |
| V6 | Row entry on one product: preview, Apply, History, Undo; the retained selection untouched before and after |
| V7 | Category target: typed subject; same-number product and term ids never collide; include subcategories captured once; per-term permissions; write path, replay, undo and redo; `prime_terms()`/`flush_terms()` |
| V8 | End to end on a disposable site: a lookup with 5,000 choices, row and bulk journeys, denied and stale choices, History and Undo, and the version 1 provider still working |
