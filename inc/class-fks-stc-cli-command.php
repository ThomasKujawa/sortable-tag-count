<?php
/**
 * WP-CLI command for Sortable Tag Count.
 *
 * @package sortable-tag-count
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Manage the tag counts of Sortable Tag Count.
 */
class FKS_STC_CLI_Command {

	/**
	 * Recalculate the tag count of all objects.
	 *
	 * Runs the calculation directly in batches instead of in the background.
	 * A pending background calculation is finished by this command.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Number of objects per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--network]
	 * : Recalculate the tag counts on all sites of the multisite network.
	 *
	 * ## EXAMPLES
	 *
	 *     # Recalculate the tag counts of the current site.
	 *     $ wp sortable-tag-count recalculate
	 *
	 *     # Recalculate the tag counts of all sites in a multisite network.
	 *     $ wp sortable-tag-count recalculate --network
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function recalculate( array $args, array $assoc_args ): void {
		$batch_size = max( 1, absint( WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 100 ) ) );

		// set the batch size for this run.
		$filter = static function () use ( $batch_size ): int {
			return $batch_size;
		};
		add_filter( 'fks_stc_batch_size', $filter );

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false ) ) {
			if ( ! is_multisite() ) {
				WP_CLI::error( 'This is not a multisite installation.' );
			}
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				$this->recalculate_site();
				restore_current_blog();
			}
		} else {
			$this->recalculate_site();
		}

		remove_filter( 'fks_stc_batch_size', $filter );

		WP_CLI::success( 'Tag counts have been recalculated.' );
	}

	/**
	 * Show the state of the background calculation.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Show the state for all sites of the multisite network.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp sortable-tag-count status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		$site_ids = array( get_current_blog_id() );
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false ) && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
		}

		$items = array();
		foreach ( $site_ids as $site_id ) {
			if ( is_multisite() ) {
				switch_to_blog( $site_id );
			}
			$state   = fks_stc_get_calculation_state();
			$items[] = array(
				'site'          => $site_id,
				'status'        => is_null( $state ) ? 'finished' : 'pending',
				'done'          => is_null( $state ) ? '' : absint( $state['done'] ),
				'total'         => is_null( $state ) || is_null( $state['total'] ) ? '' : absint( $state['total'] ),
				'next_cron_run' => wp_next_scheduled( FKS_STC_CALCULATION_HOOK ) ? gmdate( 'Y-m-d H:i:s', (int) wp_next_scheduled( FKS_STC_CALCULATION_HOOK ) ) : '',
			);
			if ( is_multisite() ) {
				restore_current_blog();
			}
		}

		WP_CLI\Utils\format_items( 'table', $items, array( 'site', 'status', 'done', 'total', 'next_cron_run' ) );
	}

	/**
	 * Recalculate the tag counts of the current site.
	 *
	 * @return void
	 */
	private function recalculate_site(): void {
		fks_stc_start_calculation();

		// run the first batch to get the total.
		$more  = fks_stc_process_batch();
		$state = fks_stc_get_calculation_state();
		$total = is_null( $state ) ? 0 : absint( $state['total'] );

		$progress = WP_CLI\Utils\make_progress_bar( sprintf( 'Site %d: calculating tag counts', get_current_blog_id() ), max( 1, $total ) );
		$progress->tick( is_null( $state ) ? max( 1, $total ) : absint( $state['done'] ) );

		while ( $more ) {
			$before = absint( fks_stc_get_calculation_state()['done'] ?? 0 );
			$more   = fks_stc_process_batch();
			$state  = fks_stc_get_calculation_state();
			$progress->tick( is_null( $state ) ? max( 0, $total - $before ) : absint( $state['done'] ) - $before );

			// free memory between batches.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		$progress->finish();
	}
}
