<?php
/**
 * The three tables of SPEC §9.2: batches, their materialised items and the
 * change journal. Each body is in the form dbDelta() requires — one column or
 * index per line, lower-case types, two spaces after PRIMARY KEY, KEY rather
 * than INDEX, no backticks and no trailing comma. Integer types carry the
 * display widths MySQL 5.7 and MariaDB report, so a later dbDelta() run finds
 * nothing to alter there; MySQL 8.0.17 and later drop the width and dbDelta()
 * ignores that difference. The migrator adds `$wpdb->prefix` and
 * `$wpdb->get_charset_collate()`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names, allowed values and CREATE TABLE bodies.
 *
 * Columns the specification marks NULL are nullable; every other column is
 * NOT NULL. Counters and attempts default to zero and item state to pending.
 *
 * Schema version 3 (T34, the chunk runner) adds to §9.2: on batches,
 * `chunk_size` (the size the runner settled on for the rest of the batch; 0
 * until the first chunk) and `note` (why it changed, in plain words); on
 * items, the claim lease `claim_token` and `claimed_gmt` (one locking UPDATE
 * claims a chunk and stamps both; a lease older than the timeout is released)
 * and `message` (the plain reason an item failed or was skipped).
 * Schema version 4 (T38, stale selection) adds `previewed_items` on batches:
 * the count the owner saw in the preview of a filter selection, NULL for an
 * id selection, so a batch whose materialised `total_items` differs can say
 * so in its history row as well as in its report.
 * Schema version 5 (T54, history and audit) adds two columns on batches:
 * `actions`, the action ids the operation list uses, comma-delimited with a
 * comma at each end (`,adjust,round,`) so the history list filters by action
 * type with one `LIKE '%,adjust,%'` and never parses the operations JSON in
 * SQL (BatchRepository::create() derives it); and `pruned_gmt`, NULL until
 * the retention pruner removes the batch's journal and items, after which
 * the batch stays listed as no longer reversible (§9.5).
 * Schema version 6 (T56, undo) adds a nullable `operations` on items: the
 * item's own operation list as JSON, for a batch whose objects each take
 * their own operations (an undo batch puts each object's own old values
 * back); NULL for every other item, which takes the batch's list.
 * Schema version 7 (T81) adds the selection and request tables.
 * Schema version 8 (T107, docs/EXTENSIONS.md §8.2) adds a nullable
 * `extensions` on batches: the provider manifest of a batch with external
 * steps (External\Manifest), NULL for every other batch.
 * Bump Migrator::SCHEMA_VERSION whenever a body here changes.
 */
final class Schema {
	/**
	 * One row per batch.
	 */
	public const BATCHES = 'bulkcat_batches';

	/**
	 * The batch's selection, materialised before any work starts.
	 */
	public const ITEMS = 'bulkcat_batch_items';

	/**
	 * The journal: one row per touched field per object.
	 */
	public const CHANGES = 'bulkcat_changes';

	/**
	 * Batch statuses.
	 */
	public const BATCH_STATUSES = [ 'queued', 'running', 'complete', 'partial', 'failed', 'cancelled', 'undoing', 'undone' ];

	/**
	 * Batch sources.
	 */
	public const BATCH_SOURCES = [ 'bulk', 'inline', 'tree', 'undo' ];

	/**
	 * Object types an item or change refers to.
	 */
	public const OBJECT_TYPES = [ 'product', 'variation', 'term', 'product_cat' ];

	/**
	 * Item states.
	 */
	public const ITEM_STATES = [ 'pending', 'done', 'skipped', 'failed' ];

	/**
	 * An item one chunk has claimed for itself and is writing now (§12.4):
	 * set only by BatchRepository::claim_item(), never a final state, and
	 * put back to pending when its claim goes stale.
	 */
	public const ITEM_WORKING = 'working';

	/**
	 * Change results.
	 */
	public const CHANGE_RESULTS = [ 'applied', 'skipped', 'failed' ];

	/**
	 * The result of an intent row (§12.3): written before save() with the
	 * value about to be saved, then settled to one of CHANGE_RESULTS. A row
	 * still pending means the write was interrupted before it was confirmed;
	 * a retry of the chunk settles it.
	 */
	public const CHANGE_INTENT = 'pending';

	/**
	 * `{prefix}bulkcat_batches`.
	 */
	public const BATCHES_BODY = 'batch_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
uuid char(36) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
created_gmt datetime NOT NULL,
finished_gmt datetime NULL,
status varchar(20) NOT NULL,
source varchar(20) NOT NULL,
undo_of bigint(20) unsigned NULL,
operations longtext NOT NULL,
selection longtext NOT NULL,
total_items int(10) unsigned NOT NULL DEFAULT 0,
previewed_items int(10) unsigned NULL,
done_items int(10) unsigned NOT NULL DEFAULT 0,
changed_items int(10) unsigned NOT NULL DEFAULT 0,
failed_items int(10) unsigned NOT NULL DEFAULT 0,
chunk_size smallint(5) unsigned NOT NULL DEFAULT 0,
note varchar(255) NOT NULL DEFAULT \'\',
actions varchar(255) NOT NULL DEFAULT \'\',
pruned_gmt datetime NULL,
extensions longtext NULL,
PRIMARY KEY  (batch_id),
KEY created_gmt (created_gmt),
KEY status (status),
KEY user_id (user_id)';

	/**
	 * `{prefix}bulkcat_batch_items`.
	 */
	public const ITEMS_BODY = "item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
batch_id bigint(20) unsigned NOT NULL,
object_id bigint(20) unsigned NOT NULL,
object_type varchar(16) NOT NULL,
state varchar(12) NOT NULL DEFAULT 'pending',
attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
claim_token char(32) NULL,
claimed_gmt datetime NULL,
message varchar(255) NOT NULL DEFAULT '',
operations longtext NULL,
PRIMARY KEY  (item_id),
KEY batch_state (batch_id,state),
KEY object (object_id)";

	/**
	 * `{prefix}bulkcat_changes`.
	 */
	public const CHANGES_BODY = 'change_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
batch_id bigint(20) unsigned NOT NULL,
object_id bigint(20) unsigned NOT NULL,
object_type varchar(16) NOT NULL,
prop varchar(64) NOT NULL,
old_value longtext NOT NULL,
new_value longtext NOT NULL,
modified_gmt datetime NOT NULL,
applied_gmt datetime NOT NULL,
result varchar(12) NOT NULL,
message varchar(255) NOT NULL DEFAULT \'\',
PRIMARY KEY  (change_id),
KEY batch_id (batch_id),
KEY object_prop (object_id,prop),
KEY applied_gmt (applied_gmt)';

	/** Immutable selection payload storage. */
	public const SELECTIONS = 'bulkcat_selections';

	/** Durable request results, independent of payload lifetime. */
	public const REQUESTS = 'bulkcat_requests';

	/** Selection payload and separately renewable authorization. */
	public const SELECTIONS_BODY = 'id char(32) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
members mediumtext NOT NULL,
object_count int(10) unsigned NOT NULL,
created_at datetime NOT NULL,
authorization_epoch int(10) unsigned NOT NULL,
authorization_expires_at datetime NOT NULL,
retained_until datetime NOT NULL,
released_at datetime NULL,
PRIMARY KEY  (id),
KEY retained_until (retained_until),
KEY owner_retention (user_id,retained_until)';

	/** One accepted identity per owner, across operation kinds. */
	public const REQUESTS_BODY = 'user_id bigint(20) unsigned NOT NULL,
uuid char(36) NOT NULL,
operation varchar(32) NOT NULL,
fingerprint char(64) NOT NULL,
issued_at datetime NOT NULL,
accept_until datetime NOT NULL,
state varchar(16) NOT NULL,
result_kind varchar(16) NOT NULL,
result_id varchar(32) NOT NULL,
retain_until datetime NULL,
PRIMARY KEY  (user_id,uuid),
KEY retain_until (retain_until),
KEY result_identity (result_kind,result_id)';

	/**
	 * The three tables, unprefixed name => CREATE TABLE body, for the registry.
	 */
	public const TABLES = [
		self::BATCHES    => self::BATCHES_BODY,
		self::ITEMS      => self::ITEMS_BODY,
		self::CHANGES    => self::CHANGES_BODY,
		self::SELECTIONS => self::SELECTIONS_BODY,
		self::REQUESTS   => self::REQUESTS_BODY,
	];
}
