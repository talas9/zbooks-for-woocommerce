<?php
/**
 * Products tab for settings page.
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

declare(strict_types=1);

namespace Zbooks\Admin;

use Zbooks\Api\ZohoClient;
use Zbooks\Repository\ItemMappingRepository;
use Zbooks\Logger\SyncLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Products tab for mapping WooCommerce products to Zoho items.
 */
class ProductsTab {

	/**
	 * Zoho client.
	 *
	 * @var ZohoClient
	 */
	private ZohoClient $client;

	/**
	 * Item mapping repository.
	 *
	 * @var ItemMappingRepository
	 */
	private ItemMappingRepository $mapping_repo;

	/**
	 * Logger.
	 *
	 * @var SyncLogger
	 */
	private SyncLogger $logger;

	/**
	 * Cached Zoho items.
	 *
	 * @var array|null
	 */
	private ?array $zoho_items_cache = null;

	/**
	 * Constructor.
	 *
	 * @param ZohoClient            $client      Zoho client.
	 * @param ItemMappingRepository $mapping_repo Item mapping repository.
	 * @param SyncLogger            $logger      Logger.
	 */
	public function __construct(
		ZohoClient $client,
		ItemMappingRepository $mapping_repo,
		SyncLogger $logger
	) {
		$this->client       = $client;
		$this->mapping_repo = $mapping_repo;
		$this->logger       = $logger;
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks(): void {
		// AJAX handlers only - menu registration moved to SettingsPage.
		add_action( 'wp_ajax_zbooks_save_mapping', [ $this, 'ajax_save_mapping' ] );
		add_action( 'wp_ajax_zbooks_remove_mapping', [ $this, 'ajax_remove_mapping' ] );
		add_action( 'wp_ajax_zbooks_link_product', [ $this, 'ajax_link_product' ] );
		add_action( 'wp_ajax_zbooks_unlink_product', [ $this, 'ajax_unlink_product' ] );
		add_action( 'wp_ajax_zbooks_auto_map_products', [ $this, 'ajax_auto_map' ] );
		add_action( 'wp_ajax_zbooks_auto_map_single_product', [ $this, 'ajax_auto_map_single_product' ] );
		add_action( 'wp_ajax_zbooks_fetch_zoho_items', [ $this, 'ajax_fetch_zoho_items' ] );
		add_action( 'wp_ajax_zbooks_bulk_create_items', [ $this, 'ajax_bulk_create_items' ] );
		add_action( 'wp_ajax_zbooks_search_zoho_items', [ $this, 'ajax_search_zoho_items' ] );
	}

	/**
	 * Render the tab content.
	 * Called by SettingsPage for the Products tab.
	 */
	public function render_content(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameters for display only.
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameter for display only.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';

		$products       = $this->get_products( $filter, $paged, $per_page );
		$total_products = $this->count_products( $filter );
		$total_pages    = ceil( $total_products / $per_page );

		$zoho_items = $this->get_zoho_items();
		$mappings   = $this->mapping_repo->get_all();
		
		// Count only mappings for published products (not orphaned mappings).
		$mapping_count = $this->count_products( 'mapped' );

		// Enqueue Select2 - use CDN version for consistency.
		// Force re-register to ensure correct version and CDN URL.
		wp_register_style(
			'select2',
			'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
			[],
			'4.1.0-rc.0'
		);
		wp_register_script(
			'select2',
			'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
			[ 'jquery' ],
			'4.1.0-rc.0',
			true
		);
		wp_enqueue_style( 'select2' );
		wp_enqueue_script( 'select2' );
		
		wp_enqueue_style( 'zbooks-admin' );
		wp_enqueue_script( 'zbooks-admin' );
		?>
		<div class="zbooks-products-tab">
			<h2><?php esc_html_e( 'Product Mapping', 'zbooks-for-woocommerce' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Map WooCommerce products to Zoho Books items. Mapped products will use the Zoho item ID when creating invoices.', 'zbooks-for-woocommerce' ); ?>
			</p>

			<div class="zbooks-mapping-stats zbooks-product-totals" style="margin: 15px 0; padding: 10px 15px; background: #f0f0f1; display: inline-flex; gap: 20px;">
				<span>
					<strong><?php esc_html_e( 'Total Products:', 'zbooks-for-woocommerce' ); ?></strong>
					<span class="zbooks-total-products"><?php echo esc_html( $this->count_products( 'all' ) ); ?></span>
				</span>
				<span style="color: #00a32a;">
					<strong><?php esc_html_e( 'Mapped:', 'zbooks-for-woocommerce' ); ?></strong>
					<span class="zbooks-mapped-products"><?php echo esc_html( $mapping_count ); ?></span>
				</span>
				<span style="color: #dba617;">
					<strong><?php esc_html_e( 'Unmapped:', 'zbooks-for-woocommerce' ); ?></strong>
					<span class="zbooks-unmapped-products"><?php echo esc_html( $this->count_products( 'all' ) - $mapping_count ); ?></span>
				</span>
				<span>
					<strong><?php esc_html_e( 'Zoho Items:', 'zbooks-for-woocommerce' ); ?></strong>
					<?php echo esc_html( count( $zoho_items ) ); ?>
				</span>
			</div>

			<div class="zbooks-mapping-actions" style="margin: 15px 0;">
				<button type="button" id="zbooks-auto-map" class="button button-primary">
					<?php esc_html_e( 'Auto-Map by SKU', 'zbooks-for-woocommerce' ); ?>
				</button>
				<button type="button" id="zbooks-bulk-create" class="button button-primary" disabled>
					<?php esc_html_e( 'Create Selected in Zoho', 'zbooks-for-woocommerce' ); ?>
				</button>
				<button type="button" id="zbooks-refresh-items" class="button">
					<?php esc_html_e( 'Refresh Zoho Items', 'zbooks-for-woocommerce' ); ?>
				</button>
				<span id="zbooks-selected-count" style="margin-left: 10px; color: #646970;"></span>
				<span id="zbooks-action-status" style="margin-left: 10px;"></span>
			</div>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'filter', 'all' ) ); ?>"
						class="<?php echo $filter === 'all' ? 'current' : ''; ?>">
						<?php esc_html_e( 'All', 'zbooks-for-woocommerce' ); ?>
						<span class="count">(<?php echo esc_html( $this->count_products( 'all' ) ); ?>)</span>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'filter', 'mapped' ) ); ?>"
						class="<?php echo $filter === 'mapped' ? 'current' : ''; ?>">
						<?php esc_html_e( 'Mapped', 'zbooks-for-woocommerce' ); ?>
						<span class="count">(<?php echo esc_html( $mapping_count ); ?>)</span>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'filter', 'unmapped' ) ); ?>"
						class="<?php echo $filter === 'unmapped' ? 'current' : ''; ?>">
						<?php esc_html_e( 'Unmapped', 'zbooks-for-woocommerce' ); ?>
						<span class="count">(<?php echo esc_html( $this->count_products( 'all' ) - $mapping_count ); ?>)</span>
					</a>
				</li>
			</ul>

			<table class="widefat fixed striped" style="margin-top: 10px;">
				<thead>
					<tr>
						<th style="width: 30px;"><input type="checkbox" id="zbooks-select-all-products"></th>
						<th style="width: 60px;"><?php esc_html_e( 'ID', 'zbooks-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Product', 'zbooks-for-woocommerce' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'SKU', 'zbooks-for-woocommerce' ); ?></th>
						<th style="width: 300px;"><?php esc_html_e( 'Zoho Item', 'zbooks-for-woocommerce' ); ?></th>
						<th style="width: 150px;"><?php esc_html_e( 'Actions', 'zbooks-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No products found.', 'zbooks-for-woocommerce' ); ?></td>
						</tr>
					<?php else : ?>
						<?php
						foreach ( $products as $product ) :
							$product_id   = $product->get_id();
							$zoho_item_id = $mappings[ $product_id ] ?? '';
							$is_mapped    = ! empty( $zoho_item_id );
							
							// Sort items by relevance for this product.
							$sorted_items = $this->sort_items_by_relevance( $zoho_items, $product );
							// Limit to top 10 for initial display, but ensure mapped item is included.
							$display_items = array_slice( $sorted_items, 0, 10 );
							
							// If product is mapped but the mapped item isn't in display_items, add it.
							if ( $is_mapped && ! empty( $zoho_item_id ) ) {
								$mapped_item_in_display = false;
								foreach ( $display_items as $item ) {
									if ( $item['item_id'] === $zoho_item_id ) {
										$mapped_item_in_display = true;
										break;
									}
								}
								
								// If mapped item not in display, find it and add it.
								if ( ! $mapped_item_in_display ) {
									foreach ( $zoho_items as $item ) {
										if ( $item['item_id'] === $zoho_item_id ) {
											// Add mapped item at the beginning.
											array_unshift( $display_items, $item );
											break;
										}
									}
								}
							}
							?>
							<tr data-product-id="<?php echo esc_attr( $product_id ); ?>">
								<td>
									<?php if ( ! $is_mapped ) : ?>
										<input type="checkbox" class="zbooks-product-checkbox" value="<?php echo esc_attr( $product_id ); ?>">
									<?php else : ?>
										<span class="dashicons dashicons-yes" style="color: #00a32a;" title="<?php esc_attr_e( 'Mapped', 'zbooks-for-woocommerce' ); ?>"></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $product_id ); ?></td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>">
										<?php echo esc_html( $product->get_name() ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $product->get_sku() ?: '-' ); ?></td>
								<td>
									<select id="zoho-item-<?php echo esc_attr( $product_id ); ?>"
											name="zoho_item_id[<?php echo esc_attr( $product_id ); ?>]"
											class="zbooks-zoho-item-select"
											data-product-id="<?php echo esc_attr( $product_id ); ?>"
											data-product-name="<?php echo esc_attr( $product->get_name() ); ?>"
											data-product-sku="<?php echo esc_attr( $product->get_sku() ?: '' ); ?>"
											style="width: 100%;">
										<option value=""><?php esc_html_e( '-- Not Mapped --', 'zbooks-for-woocommerce' ); ?></option>
										<?php foreach ( $display_items as $item ) : ?>
											<?php
											// Build option text without extra whitespace.
											$option_text = trim( $item['name'] );
											if ( ! empty( $item['sku'] ) ) {
												$option_text .= ' (' . trim( $item['sku'] ) . ')';
											}
											?>
											<option value="<?php echo esc_attr( $item['item_id'] ); ?>"
													data-sku="<?php echo esc_attr( $item['sku'] ?? '' ); ?>"
													<?php selected( $zoho_item_id, $item['item_id'] ); ?>><?php echo esc_html( $option_text ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<?php if ( ! $is_mapped ) : ?>
										<button type="button" class="button button-small zbooks-create-single"
												data-product-id="<?php echo esc_attr( $product_id ); ?>">
											<?php esc_html_e( 'Create', 'zbooks-for-woocommerce' ); ?>
										</button>
										<button type="button" class="button button-small zbooks-save-mapping"
												data-product-id="<?php echo esc_attr( $product_id ); ?>">
											<?php esc_html_e( 'Link', 'zbooks-for-woocommerce' ); ?>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small zbooks-save-mapping"
												data-product-id="<?php echo esc_attr( $product_id ); ?>"
												disabled
												style="opacity: 0.5; cursor: not-allowed;">
											<?php esc_html_e( 'Linked', 'zbooks-for-woocommerce' ); ?>
										</button>
										<button type="button" class="button button-small zbooks-remove-mapping"
												data-product-id="<?php echo esc_attr( $product_id ); ?>">
											<?php esc_html_e( 'Unlink', 'zbooks-for-woocommerce' ); ?>
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								[
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $paged,
								]
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div><!-- .zbooks-products-tab -->
		<?php
	}

	/**
	 * Get WooCommerce products.
	 *
	 * @param string $filter Filter type (all, mapped, unmapped).
	 * @param int    $page   Page number.
	 * @param int    $per_page Products per page.
	 * @return array Products.
	 */
	private function get_products( string $filter, int $page, int $per_page ): array {
		$args = [
			'status'  => 'publish',
			'limit'   => $per_page,
			'page'    => $page,
			'orderby' => 'name',
			'order'   => 'ASC',
			'return'  => 'objects',
		];

		$mappings   = $this->mapping_repo->get_all();
		$mapped_ids = array_keys( $mappings );

		if ( $filter === 'mapped' && ! empty( $mapped_ids ) ) {
			$args['include'] = $mapped_ids;
		} elseif ( $filter === 'mapped' && empty( $mapped_ids ) ) {
			return [];
		} elseif ( $filter === 'unmapped' && ! empty( $mapped_ids ) ) {
			$args['exclude'] = $mapped_ids;
		}

		return wc_get_products( $args );
	}

	/**
	 * Count products.
	 *
	 * @param string $filter Filter type.
	 * @return int Count.
	 */
	private function count_products( string $filter ): int {
		$args = [
			'status' => 'publish',
			'limit'  => -1,
			'return' => 'ids',
		];

		$mappings   = $this->mapping_repo->get_all();
		$mapped_ids = array_keys( $mappings );

		if ( $filter === 'mapped' ) {
			// Count only mappings for products that actually exist and are published.
			if ( empty( $mapped_ids ) ) {
				return 0;
			}
			$args['include'] = $mapped_ids;
			return count( wc_get_products( $args ) );
		} elseif ( $filter === 'unmapped' ) {
			if ( ! empty( $mapped_ids ) ) {
				$args['exclude'] = $mapped_ids;
			}
		}

		return count( wc_get_products( $args ) );
	}

	/**
	 * Get product totals for AJAX responses.
	 *
	 * @return array
	 */
	private function get_product_totals(): array {
		$total_products = $this->count_products( 'all' );
		$mapped_count   = $this->count_products( 'mapped' );
		$unmapped_count = $total_products - $mapped_count;

		return array(
			'total'    => $total_products,
			'mapped'   => $mapped_count,
			'unmapped' => $unmapped_count,
		);
	}

	/**
	 * Get Zoho items (cached in transient).
	 *
	 * @return array Zoho items.
	 */
	private function get_zoho_items(): array {
		if ( $this->zoho_items_cache !== null ) {
			return $this->zoho_items_cache;
		}

		$cached = get_transient( 'zbooks_zoho_items' );
		if ( $cached !== false ) {
			$this->zoho_items_cache = $cached;
			return $cached;
		}

		$items = $this->fetch_zoho_items();
		if ( ! empty( $items ) ) {
			set_transient( 'zbooks_zoho_items', $items, HOUR_IN_SECONDS );
		}

		$this->zoho_items_cache = $items;
		return $items;
	}

	/**
	 * Fetch Zoho items from API.
	 *
	 * @return array Zoho items.
	 */
	private function fetch_zoho_items(): array {
		if ( ! $this->client->is_configured() ) {
			return [];
		}

		try {
			// Fetch all item types: sales, inventory, and service items.
			// By default, Zoho API only returns 'sales' items, so we need to explicitly
			// request all types to include items with inventory tracking enabled.
			$response = $this->client->request(
				function ( $client ) {
					return $client->items->getList(
						[
							'per_page'  => 200,
							'filter_by' => 'ItemType.All',
						]
					);
				},
				[
					'endpoint' => 'items.getList',
				]
			);

			$items = [];

			// Convert object to array if needed.
			if ( is_object( $response ) ) {
				$response = json_decode( wp_json_encode( $response ), true );
			}

			if ( is_array( $response ) ) {
				$items_data = $response['items'] ?? $response;
				if ( is_array( $items_data ) ) {
					foreach ( $items_data as $item ) {
						if ( is_array( $item ) && isset( $item['item_id'], $item['name'] ) ) {
							$items[] = [
								'item_id' => $item['item_id'],
								'name'    => $item['name'],
								'sku'     => $item['sku'] ?? '',
								'rate'    => $item['rate'] ?? 0,
							];
						}
					}
				}
			}

			usort(
				$items,
				function ( $a, $b ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
			);

			return $items;
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to fetch Zoho items',
				[
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * AJAX handler for saving a mapping.
	 */
	public function ajax_save_mapping(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$zoho_item_id = isset( $_POST['zoho_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zoho_item_id'] ) ) : '';

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'zbooks-for-woocommerce' ) ] );
		}

		if ( empty( $zoho_item_id ) ) {
			$this->mapping_repo->remove_mapping( $product_id );
			wp_send_json_success( [ 'message' => __( 'Mapping removed.', 'zbooks-for-woocommerce' ) ] );
		}

		$result = $this->mapping_repo->set_mapping( $product_id, $zoho_item_id );

		if ( $result ) {
			$this->logger->info(
				'Product mapping saved',
				[
					'product_id'   => $product_id,
					'zoho_item_id' => $zoho_item_id,
				]
			);
			wp_send_json_success( [ 'message' => __( 'Mapping saved.', 'zbooks-for-woocommerce' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to save mapping.', 'zbooks-for-woocommerce' ) ] );
		}
	}

	/**
	 * AJAX handler for removing a mapping.
	 */
	public function ajax_remove_mapping(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'zbooks-for-woocommerce' ) ] );
		}

		$this->mapping_repo->remove_mapping( $product_id );

		$this->logger->info(
			'Product mapping removed',
			[
				'product_id' => $product_id,
			]
		);

		wp_send_json_success( [ 'message' => __( 'Mapping removed.', 'zbooks-for-woocommerce' ) ] );
	}

	/**
		* AJAX handler for linking a product to a Zoho item (without page reload).
		*/
	public function ajax_link_product(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$zoho_item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'zbooks-for-woocommerce' ) ] );
		}

		if ( empty( $zoho_item_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Please select a Zoho item.', 'zbooks-for-woocommerce' ) ] );
		}

		// Get product details.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'zbooks-for-woocommerce' ) ] );
		}

		// Save the mapping.
		$result = $this->mapping_repo->set_mapping( $product_id, $zoho_item_id );

		if ( $result ) {
			// Get the Zoho item details.
			$zoho_items = $this->get_zoho_items();
			$item_details = null;
			foreach ( $zoho_items as $item ) {
				if ( $item['item_id'] === $zoho_item_id ) {
					$item_details = $item;
					break;
				}
			}

			$this->logger->info(
				'Product linked via AJAX',
				[
					'product_id'   => $product_id,
					'zoho_item_id' => $zoho_item_id,
				]
			);

			wp_send_json_success(
				[
					'message'      => __( 'Product linked successfully.', 'zbooks-for-woocommerce' ),
					'product_id'   => $product_id,
					'item_id'      => $zoho_item_id,
					'item_name'    => $item_details ? $item_details['name'] : '',
					'item_sku'     => $item_details ? ( $item_details['sku'] ?? '' ) : '',
					'totals'       => $this->get_product_totals(),
				]
			);
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to link product.', 'zbooks-for-woocommerce' ) ] );
		}
	}

	/**
		* AJAX handler for unlinking a product from a Zoho item (without page reload).
		*/
	public function ajax_unlink_product(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'zbooks-for-woocommerce' ) ] );
		}

		// Get product details.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'zbooks-for-woocommerce' ) ] );
		}

		// Remove the mapping.
		$this->mapping_repo->remove_mapping( $product_id );

		$this->logger->info(
			'Product unlinked via AJAX',
			[
				'product_id' => $product_id,
			]
		);

		wp_send_json_success(
			[
				'message'    => __( 'Product unlinked successfully.', 'zbooks-for-woocommerce' ),
				'product_id' => $product_id,
				'totals'     => $this->get_product_totals(),
			]
		);
	}

	/**
		* AJAX handler for auto-mapping products by SKU.
		*/
	public function ajax_auto_map(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$zoho_items = $this->fetch_zoho_items();
		if ( empty( $zoho_items ) ) {
			wp_send_json_error( [ 'message' => __( 'No Zoho items available for mapping.', 'zbooks-for-woocommerce' ) ] );
		}

		$mapped_count = $this->mapping_repo->auto_map_by_sku( $zoho_items );

		$this->logger->info(
			'Auto-mapping completed',
			[
				'mapped_count' => $mapped_count,
			]
		);

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of products mapped */
					__( 'Auto-mapped %d product(s) by SKU.', 'zbooks-for-woocommerce' ),
					$mapped_count
				),
				'mapped'  => $mapped_count,
			]
		);
	}

	/**
	 * AJAX handler for auto-mapping a single product by SKU.
	 */
	public function ajax_auto_map_single_product(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'zbooks-for-woocommerce' ) ] );
		}

		// Check if product is already mapped.
		if ( $this->mapping_repo->is_mapped( $product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Product is already mapped.', 'zbooks-for-woocommerce' ) ] );
		}

		// Get the product.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'zbooks-for-woocommerce' ) ] );
		}

		$sku = $product->get_sku();
		if ( empty( $sku ) ) {
			wp_send_json_error( [ 'message' => __( 'Product has no SKU.', 'zbooks-for-woocommerce' ) ] );
		}

		// Fetch Zoho items.
		$zoho_items = $this->fetch_zoho_items();
		if ( empty( $zoho_items ) ) {
			wp_send_json_error( [ 'message' => __( 'No Zoho items available for mapping.', 'zbooks-for-woocommerce' ) ] );
		}

		// Find matching Zoho item by SKU.
		$matched_item = null;
		$sku_lower    = strtolower( $sku );

		foreach ( $zoho_items as $item ) {
			$item_sku = $item['sku'] ?? '';
			if ( ! empty( $item_sku ) && strtolower( $item_sku ) === $sku_lower ) {
				$matched_item = $item;
				break;
			}
		}

		if ( ! $matched_item ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: product SKU */
						__( 'No matching Zoho item found for SKU: %s', 'zbooks-for-woocommerce' ),
						$sku
					),
				]
			);
		}

		// Save the mapping.
		$success = $this->mapping_repo->set_mapping( $product_id, $matched_item['item_id'] );

		if ( $success ) {
			$this->logger->info(
				'Single product auto-mapped',
				[
					'product_id'   => $product_id,
					'sku'          => $sku,
					'zoho_item_id' => $matched_item['item_id'],
				]
			);

			wp_send_json_success(
				[
					'message'      => sprintf(
						/* translators: %s: product name */
						__( 'Successfully mapped: %s', 'zbooks-for-woocommerce' ),
						$product->get_name()
					),
					'item_id'      => $matched_item['item_id'],
					'item_name'    => $matched_item['name'],
					'item_sku'     => $matched_item['sku'],
					'product_id'   => $product_id,
					'product_name' => $product->get_name(),
					'totals'       => $this->get_product_totals(),
				]
			);
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to save mapping.', 'zbooks-for-woocommerce' ) ] );
		}
	}

	/**
	 * AJAX handler for fetching Zoho items.
	 */
	public function ajax_fetch_zoho_items(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		delete_transient( 'zbooks_zoho_items' );

		$items = $this->fetch_zoho_items();
		if ( ! empty( $items ) ) {
			set_transient( 'zbooks_zoho_items', $items, HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of items fetched */
					__( 'Fetched %d Zoho item(s).', 'zbooks-for-woocommerce' ),
					count( $items )
				),
				'count'   => count( $items ),
			]
		);
	}

	/**
	 * AJAX handler for bulk creating items in Zoho.
	 */
	public function ajax_bulk_create_items(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['product_ids'] ) ) : [];

		if ( empty( $product_ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No products selected.', 'zbooks-for-woocommerce' ) ] );
		}

		if ( ! $this->client->is_configured() ) {
			wp_send_json_error( [ 'message' => __( 'Zoho Books not configured.', 'zbooks-for-woocommerce' ) ] );
		}

		$success = 0;
		$failed  = 0;
		$errors  = [];

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				++$failed;
				continue;
			}

			// Skip if already mapped.
			if ( $this->mapping_repo->is_mapped( $product_id ) ) {
				continue;
			}

			try {
				$item_data = $this->build_item_data( $product );

				$response = $this->client->request(
					function ( $client ) use ( $item_data ) {
						return $client->items->create( $item_data );
					},
					[
						'endpoint'     => 'items.create',
						'product_id'   => $product_id,
						'product_name' => $product->get_name(),
					]
				);

				// SDK returns Item model object directly, not an array.
				$zoho_item_id = $this->extract_item_id( $response );

				if ( $zoho_item_id ) {
					$this->mapping_repo->set_mapping( $product_id, $zoho_item_id );

					$this->logger->info(
						'Zoho item created via bulk',
						[
							'product_id'   => $product_id,
							'zoho_item_id' => $zoho_item_id,
						]
					);

					++$success;
				} else {
					++$failed;
				}
			} catch ( \Exception $e ) {
				++$failed;
				$errors[] = $product->get_name() . ': ' . $e->getMessage();

				$this->logger->error(
					'Failed to create Zoho item via bulk',
					[
						'product_id' => $product_id,
						'error'      => $e->getMessage(),
					]
				);
			}
		}

		$message = sprintf(
			/* translators: 1: success count, 2: failed count */
			__( 'Created %1$d item(s), %2$d failed.', 'zbooks-for-woocommerce' ),
			$success,
			$failed
		);

		if ( $failed > 0 && ! empty( $errors ) ) {
			$message .= ' ' . implode( '; ', array_slice( $errors, 0, 3 ) );
		}

		wp_send_json_success(
			[
				'message' => $message,
				'success' => $success,
				'failed'  => $failed,
			]
		);
	}

	/**
	 * Build item data for Zoho API.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @return array Item data.
	 */
	private function build_item_data( \WC_Product $product ): array {
		$data = [
			'name'         => $product->get_name(),
			'rate'         => (float) $product->get_price(),
			'description'  => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
			'product_type' => $product->is_virtual() ? 'service' : 'goods',
		];

		$sku = $product->get_sku();
		if ( ! empty( $sku ) ) {
			$data['sku'] = $sku;
		}

		// Tax settings.
		$tax_status = $product->get_tax_status();
		if ( $tax_status === 'taxable' ) {
			$data['is_taxable'] = true;
		} else {
			$data['is_taxable'] = false;
		}

		return apply_filters( 'zbooks_item_data', $data, $product );
	}

	/**
	 * Extract item_id from Zoho API response.
	 *
	 * The SDK may return either an array or an Item model object.
	 *
	 * @param mixed $response API response.
	 * @return string|null Item ID or null.
	 */
	private function extract_item_id( $response ): ?string {
		if ( is_object( $response ) ) {
			// Handle Webleit\ZohoBooksApi\Models\Item object.
			if ( method_exists( $response, 'getId' ) ) {
				return $response->getId();
			}
			if ( property_exists( $response, 'item_id' ) ) {
				return $response->item_id;
			}
			if ( method_exists( $response, 'toArray' ) ) {
				$arr = $response->toArray();
				return $arr['item_id'] ?? null;
			}
		} elseif ( is_array( $response ) ) {
			// Handle array response.
			return $response['item']['item_id'] ?? $response['item_id'] ?? null;
		}

		return null;
	}

	/**
	 * Sort Zoho items by relevance to a WooCommerce product.
	 *
	 * Prioritizes:
	 * 1. Exact SKU match
	 * 2. Name similarity
	 * 3. Alphabetical order
	 *
	 * @param array                $items   Zoho items.
	 * @param \WC_Product|object   $product WooCommerce product or object with get_name() and get_sku() methods.
	 * @return array Sorted items.
	 */
	private function sort_items_by_relevance( array $items, $product ): array {
		$product_sku  = strtolower( $product->get_sku() ?: '' );
		$product_name = strtolower( $product->get_name() );

		usort(
			$items,
			function ( $a, $b ) use ( $product_sku, $product_name ) {
				$a_sku  = strtolower( $a['sku'] ?? '' );
				$b_sku  = strtolower( $b['sku'] ?? '' );
				$a_name = strtolower( $a['name'] );
				$b_name = strtolower( $b['name'] );

				// Priority 1: Exact SKU match.
				if ( ! empty( $product_sku ) ) {
					$a_sku_match = $a_sku === $product_sku;
					$b_sku_match = $b_sku === $product_sku;

					if ( $a_sku_match && ! $b_sku_match ) {
						return -1;
					}
					if ( ! $a_sku_match && $b_sku_match ) {
						return 1;
					}
				}

				// Priority 2: Name similarity (using levenshtein distance).
				$a_distance = levenshtein( $product_name, $a_name );
				$b_distance = levenshtein( $product_name, $b_name );

				// Levenshtein can return -1 if string is too long, fallback to simple comparison.
				if ( $a_distance === -1 ) {
					$a_distance = similar_text( $product_name, $a_name );
				}
				if ( $b_distance === -1 ) {
					$b_distance = similar_text( $product_name, $b_name );
				}

				if ( $a_distance !== $b_distance ) {
					return $a_distance <=> $b_distance;
				}

				// Priority 3: Alphabetical order.
				return strcasecmp( $a_name, $b_name );
			}
		);

		return $items;
	}

	/**
	 * AJAX handler for searching Zoho items.
	 *
	 * Used by Select2 for dynamic searching.
	 */
	public function ajax_search_zoho_items(): void {
		check_ajax_referer( 'zbooks_mapping', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'zbooks-for-woocommerce' ) ] );
		}

		$search       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$product_id   = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
		$product_sku  = isset( $_GET['product_sku'] ) ? sanitize_text_field( wp_unslash( $_GET['product_sku'] ) ) : '';
		$product_name = isset( $_GET['product_name'] ) ? sanitize_text_field( wp_unslash( $_GET['product_name'] ) ) : '';
		$page         = isset( $_GET['page'] ) ? absint( wp_unslash( $_GET['page'] ) ) : 1;

		$zoho_items = $this->get_zoho_items();

		// Filter by search term.
		if ( ! empty( $search ) ) {
			$search_lower = strtolower( $search );
			$zoho_items   = array_filter(
				$zoho_items,
				function ( $item ) use ( $search_lower ) {
					$name = strtolower( $item['name'] );
					$sku  = strtolower( $item['sku'] ?? '' );
					return strpos( $name, $search_lower ) !== false || strpos( $sku, $search_lower ) !== false;
				}
			);
		}

		// Sort by relevance if we have product info.
		if ( $product_id && ! empty( $product_name ) ) {
			// Create a mock product object for sorting.
			$mock_product = new class( $product_name, $product_sku ) {
				private $name;
				private $sku;

				public function __construct( $name, $sku ) {
					$this->name = $name;
					$this->sku  = $sku;
				}

				public function get_name() {
					return $this->name;
				}

				public function get_sku() {
					return $this->sku;
				}
			};

			$zoho_items = $this->sort_items_by_relevance( $zoho_items, $mock_product );
		}

		// Paginate results (10 per page).
		$per_page = 10;
		$offset   = ( $page - 1 ) * $per_page;
		$total    = count( $zoho_items );
		$items    = array_slice( $zoho_items, $offset, $per_page );

		// Format for Select2.
		$results = array_map(
			function ( $item ) {
				$text = $item['name'];
				if ( ! empty( $item['sku'] ) ) {
					$text .= ' (' . $item['sku'] . ')';
				}
				return [
					'id'   => $item['item_id'],
					'text' => $text,
				];
			},
			$items
		);

		wp_send_json_success(
			[
				'results'    => $results,
				'pagination' => [
					'more' => ( $offset + $per_page ) < $total,
				],
			]
		);
	}
}
