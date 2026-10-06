<?php
/**
 * The Categories tab's All fields (SPEC §4.7): WordPress's own product
 * category edit form shown inside the details pane, so every field another
 * plugin adds to it appears and saves through WordPress. Its admin chrome is
 * hidden only when the plugin's flag asks for it and the user may edit that
 * category.
 *
 * @package BulkCatalogueManager
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Admin;

use BulkCatalogueManager\Admin\Screen;
use BulkCatalogueManager\Admin\TermEmbed;
use BulkCatalogueManager\Plugin;
use PHPUnit\Framework\TestCase;

final class TermEmbedTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [ 'edit_term:12' => true ];
		$_GET                            = [
			'taxonomy'      => 'product_cat',
			'tag_ID'        => '12',
			'post_type'     => 'product',
			TermEmbed::FLAG => '1',
		];
		$GLOBALS['pagenow']              = 'term.php';
	}

	protected function tearDown(): void {
		$_GET = [];
		unset( $GLOBALS['pagenow'] );
	}

	public function test_the_form_is_embedded_only_for_a_product_category_the_user_may_edit(): void {
		$this->assertSame( 12, TermEmbed::embedded_term() );

		$GLOBALS['bulkcat_test']['caps']['edit_term:12'] = false;
		$this->assertSame( 0, TermEmbed::embedded_term(), 'Without permission to edit it, nothing changes.' );
		$GLOBALS['bulkcat_test']['caps']['edit_term:12'] = true;

		$_GET['taxonomy'] = 'product_tag';
		$this->assertSame( 0, TermEmbed::embedded_term(), 'Only product categories.' );
		$_GET['taxonomy'] = 'product_cat';

		unset( $_GET[ TermEmbed::FLAG ] );
		$this->assertSame( 0, TermEmbed::embedded_term(), 'WooCommerce’s own screen, opened normally, is untouched.' );
		$_GET[ TermEmbed::FLAG ] = '1';

		$_GET['tag_ID'] = 'abc';
		$this->assertSame( 0, TermEmbed::embedded_term() );
	}

	public function test_its_stylesheet_hides_the_admin_chrome_and_the_delete_link_only_there(): void {
		TermEmbed::enqueue( 'term.php' );
		$this->assertSame( [ [ TermEmbed::HANDLE, false, [], null, 'all' ] ], bulkcat_test_admin_calls( 'wp_register_style' ) );
		$this->assertSame( [ [ TermEmbed::HANDLE ] ], array_map( static fn( array $call ): array => [ $call[0] ], bulkcat_test_admin_calls( 'wp_enqueue_style' ) ) );
		$inline = bulkcat_test_admin_calls( 'wp_add_inline_style' );
		$this->assertCount( 1, $inline );
		$this->assertSame( TermEmbed::HANDLE, $inline[0][0] );
		foreach ( [ '#wpadminbar', '#adminmenumain', '#wpfooter', '#screen-meta-links', '#delete-link', '#wpcontent' ] as $selector ) {
			$this->assertStringContainsString( $selector, $inline[0][1] );
		}
		$this->assertSame( 0, preg_match( '/(?<!:not\()#message\b/', $inline[0][1] ), 'WordPress’s own "Category updated." notice stays.' );

		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [ 'edit_term:12' => true ];
		TermEmbed::enqueue( 'edit-tags.php' );
		$this->assertSame( [], bulkcat_test_admin_calls( 'wp_add_inline_style' ), 'Not on any other admin page.' );

		$GLOBALS['bulkcat_test']['caps']['edit_term:12'] = false;
		TermEmbed::enqueue( 'term.php' );
		$this->assertSame( [], bulkcat_test_admin_calls( 'wp_add_inline_style' ) );
	}

	public function test_the_embedded_page_carries_a_body_class(): void {
		$this->assertSame( 'folded bulkcat-embedded', TermEmbed::body_class( 'folded' ) );
		unset( $_GET[ TermEmbed::FLAG ] );
		$this->assertSame( 'folded', TermEmbed::body_class( 'folded' ) );
	}

	public function test_the_screen_tells_the_client_where_the_form_is(): void {
		$GLOBALS['bulkcat_test']['caps'] = [ 'manage_woocommerce' => true ];
		$settings                        = ( new Screen() )->client_settings();
		$this->assertSame(
			[
				'url'  => 'https://example.test/wp-admin/term.php?taxonomy=product_cat&post_type=product',
				'flag' => TermEmbed::FLAG,
			],
			$settings['categoryEdit']
		);
	}

	public function test_the_plugin_hooks_it(): void {
		$hooks = ( new Plugin() )->hooks();
		$this->assertContains( [ 'admin_enqueue_scripts', [ TermEmbed::class, 'enqueue' ], 10, 1 ], $hooks );
		$this->assertContains( [ 'admin_body_class', [ TermEmbed::class, 'body_class' ], 10, 1 ], $hooks );
	}

	public function test_it_warns_before_the_first_save_through_this_form(): void {
		$tag = (object) [
			'term_id'  => 12,
			'taxonomy' => 'product_cat',
		];
		// Never saved through WordPress's own form: WooCommerce's display type has never been stored.
		ob_start();
		TermEmbed::warn( $tag, 'product_cat' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'bulkcat-embed-warning', $html );
		$this->assertStringContainsString( 'has not been saved with this form before', $html );
		$this->assertStringNotContainsString( 'class="notice', $html, 'Not a notice the embedded page hides.' );

		$GLOBALS['bulkcat_test']['term_meta'][12]['display_type'] = '';
		ob_start();
		TermEmbed::warn( $tag, 'product_cat' );
		$this->assertSame( '', (string) ob_get_clean(), 'Saved through it before: no warning.' );

		unset( $GLOBALS['bulkcat_test']['term_meta'][12] );
		unset( $_GET[ TermEmbed::FLAG ] );
		ob_start();
		TermEmbed::warn( $tag, 'product_cat' );
		$this->assertSame( '', (string) ob_get_clean(), 'WordPress’s own screen, opened normally, is untouched.' );
	}

	public function test_the_warning_hook_is_registered(): void {
		$this->assertContains( [ 'product_cat_pre_edit_form', [ TermEmbed::class, 'warn' ], 10, 2 ], ( new Plugin() )->hooks() );
	}
}
