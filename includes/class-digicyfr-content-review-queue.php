<?php
/**
 * Private post/page review scheduling and administrator queue.
 *
 * @package Digicyfr_Content_Review_Queue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Isolated plugin implementation. */
final class Digicyfr_Content_Review_Queue {
	private const DOMAIN     = 'digicyfr-content-review-queue';
	private const TRACKED    = '_dcrq_tracked';
	private const DUE        = '_dcrq_due';
	private const ASSIGNEE   = '_dcrq_assignee';
	private const REVIEWED   = '_dcrq_reviewed_at_utc';
	private const EVENT      = '_dcrq_event';
	private const PAGE_SIZE  = 20;
	private const SCAN_BATCH = 100;

	/** Register WordPress hooks. */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'on_init' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_editor_box' ) );
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'add_editor_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		foreach ( array( 'schedule', 'review', 'snooze' ) as $action ) {
			add_action( 'admin_post_dcrq_' . $action, array( __CLASS__, 'handle_action' ) );
		}
		add_action( 'admin_post_dcrq_export', array( __CLASS__, 'handle_export' ) );
	}

	/** Register private meta on posts and pages, and load bundled translations. */
	public static function on_init(): void {
		load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( DIGICYFR_CONTENT_REVIEW_QUEUE_PATH . 'digicyfr-content-review-queue.php' ) ) . '/languages' );
		foreach ( array( 'post', 'page' ) as $type ) {
			foreach ( array( self::TRACKED, self::DUE, self::ASSIGNEE, self::REVIEWED ) as $key ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'          => 'string',
						'single'        => true,
						'show_in_rest'  => false,
						'auth_callback' => array( __CLASS__, 'can_read_meta' ),
					)
				);
			}
			register_post_meta(
				$type,
				self::EVENT,
				array(
					'type'          => 'array',
					'single'        => false,
					'show_in_rest'  => false,
					'auth_callback' => array( __CLASS__, 'can_read_meta' ),
				)
			);
		}
	}

	/**
	 * Allow private meta only to a user who can edit that object.
	 *
	 * @param bool   $allowed Default value.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id Object ID.
	 * @param int    $user_id User ID.
	 * @return bool Whether the user can edit the object.
	 */
	public static function can_read_meta( $allowed, $meta_key, $post_id, $user_id ): bool {
		return in_array( get_post_type( $post_id ), array( 'post', 'page' ), true ) && user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Strict site-local calendar date validator.
	 *
	 * @param string $value Date text.
	 * @return bool Whether this is a real YYYY-MM-DD date.
	 */
	public static function valid_date( string $value ): bool {
		if ( ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts ) ) {
			return false;
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * Classify a due date using site-local calendar strings.
	 *
	 * @param string $due Stored due date, or empty.
	 * @param string $today Site-local current date.
	 * @return string One of overdue, today, upcoming, unscheduled.
	 */
	public static function classify( string $due, string $today ): string {
		if ( '' === $due ) {
			return 'unscheduled';
		}
		if ( $due < $today ) {
			return 'overdue';
		}
		return $due === $today ? 'today' : 'upcoming';
	}

	/**
	 * Normalize a submitted note without silently truncating meaning.
	 *
	 * @param mixed $input Raw note.
	 * @return string|WP_Error Sanitized note or validation error.
	 */
	private static function note( $input ) {
		if ( ! is_string( $input ) || strlen( $input ) > 5000 ) {
			return new WP_Error( 'invalid_note', __( 'The note is too long.', 'digicyfr-content-review-queue' ) );
		}
		$note   = sanitize_textarea_field( $input );
		$length = preg_match_all( '/./us', $note );
		if ( false === $length || $length > 500 ) {
			return new WP_Error( 'invalid_note', __( 'The note must be at most 500 characters.', 'digicyfr-content-review-queue' ) );
		}
		return $note;
	}

	/**
	 * Normalize an assignee against the specific editable post.
	 *
	 * @param mixed $input Submitted decimal user ID.
	 * @param int   $post_id Object ID.
	 * @return int|WP_Error Eligible ID or validation error.
	 */
	private static function assignee( $input, int $post_id ) {
		if ( ! is_string( $input ) || ! preg_match( '/^(?:0|[1-9][0-9]*)$/D', $input ) ) {
			return new WP_Error( 'invalid_assignee', __( 'Choose an eligible editor or Unassigned.', 'digicyfr-content-review-queue' ) );
		}
		$id = (int) $input;
		if ( 0 !== $id && ( ! get_user_by( 'id', $id ) || ! user_can( $id, 'edit_post', $post_id ) ) ) {
			return new WP_Error( 'invalid_assignee', __( 'The selected user cannot edit this item.', 'digicyfr-content-review-queue' ) );
		}
		return $id;
	}

	/**
	 * Apply one validated action without touching wp_posts or its modified timestamps.
	 *
	 * State writes happen before the append-only event. Failed writes are rolled back.
	 * The action UUID deduplicates an HTTP retry after a committed event.
	 *
	 * @param int                    $post_id Target post/page ID.
	 * @param string                 $action schedule, review, or snooze.
	 * @param array                  $input Unslashed submitted values.
	 * @param DateTimeImmutable|null $now Optional test clock.
	 * @return array|WP_Error Result status and event, or error.
	 */
	public static function process_action( int $post_id, string $action, array $input, ?DateTimeImmutable $now = null ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot manage review data for this item.', 'digicyfr-content-review-queue' ) );
		}
		if ( ! in_array( $action, array( 'schedule', 'review', 'snooze' ), true ) ) {
			return new WP_Error( 'invalid_action', __( 'Unknown review action.', 'digicyfr-content-review-queue' ) );
		}
		$uuid = $input['uuid'] ?? '';
		if ( ! is_string( $uuid ) || ! wp_is_uuid( $uuid ) ) {
			return new WP_Error( 'invalid_uuid', __( 'This action needs a valid request ID.', 'digicyfr-content-review-queue' ) );
		}
		$note = self::note( $input['note'] ?? '' );
		if ( is_wp_error( $note ) ) {
			return $note;
		}
		$now   = ( $now ?? current_datetime() )->setTimezone( wp_timezone() );
		$today = $now->format( 'Y-m-d' );
		$due   = $input['due'] ?? '';
		if ( ! is_string( $due ) ) {
			return new WP_Error( 'invalid_date', __( 'Enter a valid due date.', 'digicyfr-content-review-queue' ) );
		}
		$due        = trim( $due );
		$unschedule = '1' === ( $input['unschedule'] ?? '0' );
		if ( $unschedule && '' !== $due ) {
			return new WP_Error( 'invalid_date', __( 'Clear the date before choosing Unscheduled.', 'digicyfr-content-review-queue' ) );
		}
		if ( 'snooze' === $action && $unschedule ) {
			return new WP_Error( 'invalid_date', __( 'Snooze needs a future date.', 'digicyfr-content-review-queue' ) );
		}
		if ( $unschedule ) {
			$due = '';
		} elseif ( ! self::valid_date( $due ) ) {
			return new WP_Error( 'invalid_date', __( 'Enter a real YYYY-MM-DD due date or choose Unscheduled.', 'digicyfr-content-review-queue' ) );
		}
		if ( 'review' === $action && '' !== $due && $due <= $today ) {
			return new WP_Error( 'invalid_date', __( 'A review needs a next due date after today, or Unscheduled.', 'digicyfr-content-review-queue' ) );
		}
		if ( 'snooze' === $action && ( $due <= $today || '' === $note ) ) {
			return new WP_Error( 'invalid_snooze', __( 'Snooze needs a future date and a reason.', 'digicyfr-content-review-queue' ) );
		}
		$lock = 'dcrq_lock_' . $post_id;
		if ( ! add_option( $lock, (string) time(), '', false ) ) {
			$acquired_at = (int) get_option( $lock, 0 );
			if ( $acquired_at > 0 && $acquired_at < time() - 120 ) {
				delete_option( $lock );
			}
			if ( ! add_option( $lock, (string) time(), '', false ) ) {
				return new WP_Error( 'busy', __( 'Another review action is in progress. Try again.', 'digicyfr-content-review-queue' ) );
			}
		}
		try {
			foreach ( get_post_meta( $post_id, self::EVENT, false ) as $existing ) {
				if ( is_array( $existing ) && ( $existing['id'] ?? '' ) === $uuid ) {
					return array(
						'status' => 'duplicate',
						'event'  => $existing,
					);
				}
			}
			$old_due      = (string) get_post_meta( $post_id, self::DUE, true );
			$old_assignee = (int) get_post_meta( $post_id, self::ASSIGNEE, true );
			if ( 'snooze' === $action && ( '1' !== get_post_meta( $post_id, self::TRACKED, true ) || '' === $old_due ) ) {
				return new WP_Error( 'invalid_snooze', __( 'Schedule this item before snoozing it.', 'digicyfr-content-review-queue' ) );
			}
			$new_assignee = $old_assignee;
			if ( 'snooze' !== $action ) {
				$new_assignee = self::assignee( $input['assignee'] ?? null, $post_id );
				if ( is_wp_error( $new_assignee ) ) {
					return $new_assignee;
				}
			}
			$at_utc  = $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\\TH:i:s\\Z' );
			$event   = array(
				'v'               => 1,
				'id'              => $uuid,
				'action'          => $action,
				'at_utc'          => $at_utc,
				'actor_id'        => get_current_user_id(),
				'old_due'         => $old_due,
				'new_due'         => $due,
				'old_assignee_id' => $old_assignee,
				'new_assignee_id' => $new_assignee,
				'note'            => $note,
			);
			$changes = array(
				self::TRACKED  => '1',
				self::DUE      => $due,
				self::ASSIGNEE => (string) $new_assignee,
			);
			if ( 'review' === $action ) {
				$changes[ self::REVIEWED ] = $at_utc;
			}
			$originals = array();
			foreach ( $changes as $key => $value ) {
				$originals[ $key ] = array(
					'exists' => metadata_exists( 'post', $post_id, $key ),
					'value'  => (string) get_post_meta( $post_id, $key, true ),
				);
				if ( ! self::write_meta( $post_id, $key, $value ) ) {
					return self::failed_write( $post_id, $originals );
				}
			}
			if ( ! add_post_meta( $post_id, self::EVENT, wp_slash( $event ) ) ) {
				foreach ( get_post_meta( $post_id, self::EVENT, false ) as $stored_event ) {
					if ( is_array( $stored_event ) && ( $stored_event['id'] ?? '' ) === $uuid ) {
						return array(
							'status' => 'saved',
							'event'  => $stored_event,
						);
					}
				}
				return self::failed_write( $post_id, $originals );
			}
			return array(
				'status' => 'saved',
				'event'  => $event,
			);
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Write and verify a string meta value, treating an unchanged value as success.
	 *
	 * @param int    $post_id Object ID.
	 * @param string $key Meta key.
	 * @param string $value Desired value.
	 * @return bool Whether the desired value is stored.
	 */
	private static function write_meta( int $post_id, string $key, string $value ): bool {
		if ( metadata_exists( 'post', $post_id, $key ) && (string) get_post_meta( $post_id, $key, true ) === $value ) {
			return true;
		}
		update_post_meta( $post_id, $key, wp_slash( $value ) );
		return metadata_exists( 'post', $post_id, $key ) && (string) get_post_meta( $post_id, $key, true ) === $value;
	}

	/**
	 * Roll back a failed state/event save and return an explicit error.
	 *
	 * @param int   $post_id Object ID.
	 * @param array $originals Original existence and values.
	 * @return WP_Error Failure detail.
	 */
	private static function failed_write( int $post_id, array $originals ): WP_Error {
		$rolled_back = true;
		foreach ( array_reverse( $originals, true ) as $key => $old ) {
			if ( $old['exists'] ) {
				$rolled_back = self::write_meta( $post_id, $key, $old['value'] ) && $rolled_back;
			} else {
				delete_post_meta( $post_id, $key );
				$rolled_back = ! metadata_exists( 'post', $post_id, $key ) && $rolled_back;
			}
		}
		return new WP_Error( $rolled_back ? 'write_failed' : 'partial_write', $rolled_back ? __( 'Review data was not saved. Please try again.', 'digicyfr-content-review-queue' ) : __( 'Review data was only partly saved. Ask an administrator to inspect it before retrying.', 'digicyfr-content-review-queue' ) );
	}

	/**
	 * Normalize the queue tab without accepting arbitrary query behavior.
	 *
	 * @param mixed $tab Submitted tab.
	 * @return string Known tab key.
	 */
	private static function tab( $tab ): string {
		return is_string( $tab ) && in_array( $tab, array( 'attention', 'overdue', 'today', 'upcoming', 'unscheduled' ), true ) ? $tab : 'attention';
	}

	/**
	 * Read a bounded search string.
	 *
	 * @param mixed $search Search input.
	 * @return string Safe search.
	 */
	private static function search( $search ): string {
		if ( ! is_string( $search ) ) {
			return '';
		}
		$text = sanitize_text_field( $search );
		return preg_match( '/^.{0,100}/us', $text, $matches ) ? $matches[0] : '';
	}

	/**
	 * Yield only published tracked posts/pages the current viewer can edit.
	 *
	 * No global count is returned or exposed, so pagination and CSV do not leak
	 * the existence of another author's private queue rows.
	 *
	 * @param string $tab Known tab key.
	 * @param string $search Safe search text.
	 * @param string $today Site-local calendar date.
	 * @return Generator<int, WP_Post> Authorized posts in stable due/ID order.
	 */
	private static function authorized_posts( string $tab, string $search, string $today ): Generator {
		$due_clause = array(
			'key'     => self::DUE,
			'value'   => '',
			'compare' => '=',
		);
		if ( 'attention' === $tab || 'overdue' === $tab ) {
			$due_clause = array(
				'key'     => self::DUE,
				'value'   => $today,
				'compare' => 'attention' === $tab ? '<=' : '<',
				'type'    => 'CHAR',
			);
		} elseif ( 'today' === $tab ) {
			$due_clause = array(
				'key'     => self::DUE,
				'value'   => $today,
				'compare' => '=',
			);
		} elseif ( 'upcoming' === $tab ) {
			$due_clause = array(
				'key'     => self::DUE,
				'value'   => $today,
				'compare' => '>',
				'type'    => 'CHAR',
			);
		}
		$meta_query = array(
			'relation'   => 'AND',
			array(
				'key'   => self::TRACKED,
				'value' => '1',
			),
			'due_clause' => $due_clause,
		);
		if ( in_array( $tab, array( 'attention', 'overdue' ), true ) ) {
			$meta_query[] = array(
				'key'     => self::DUE,
				'value'   => '',
				'compare' => '!=',
			);
		}
		$page = 1;
		do {
			$query = new WP_Query(
				array(
					'post_type'           => array( 'post', 'page' ),
					'post_status'         => 'publish',
					'fields'              => 'ids',
					'posts_per_page'      => self::SCAN_BATCH,
					'paged'               => $page,
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					's'                   => $search,
					'meta_query'          => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded batches, indexed post IDs, and explicit tracked/due filters are essential to this queue.
					'orderby'             => array(
						'due_clause' => 'ASC',
						'ID'         => 'ASC',
					),
				)
			);
			foreach ( $query->posts as $id ) {
				if ( current_user_can( 'edit_post', $id ) ) {
					$post = get_post( $id );
					if ( $post instanceof WP_Post ) {
						yield $post;
					}
				}
			}
			$batch_size = count( $query->posts );
			++$page;
		} while ( self::SCAN_BATCH === $batch_size );
	}

	/**
	 * Get one page after filtering by object-level permission.
	 *
	 * @param string                 $tab Queue tab.
	 * @param string                 $search Search text.
	 * @param int                    $page One-based authorized page.
	 * @param DateTimeImmutable|null $now Optional test clock.
	 * @return array Posts and whether another authorized page exists.
	 */
	public static function queue_page( string $tab, string $search, int $page = 1, ?DateTimeImmutable $now = null ): array {
		$tab    = self::tab( $tab );
		$search = self::search( $search );
		$page   = max( 1, min( 10000, $page ) );
		$today  = ( $now ?? current_datetime() )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		$skip   = ( $page - 1 ) * self::PAGE_SIZE;
		$rows   = array();
		$seen   = 0;
		foreach ( self::authorized_posts( $tab, $search, $today ) as $post ) {
			if ( $seen++ < $skip ) {
				continue;
			}
			$rows[] = $post;
			if ( count( $rows ) > self::PAGE_SIZE ) {
				break;
			}
		}
		return array(
			'posts'    => array_slice( $rows, 0, self::PAGE_SIZE ),
			'has_next' => count( $rows ) > self::PAGE_SIZE,
		);
	}

	/**
	 * Resolve an assignee or historical actor, including a deleted account.
	 *
	 * @param int $id User ID.
	 * @return string Display text.
	 */
	public static function user_name( int $id ): string {
		if ( 0 === $id ) {
			return __( 'Unassigned', 'digicyfr-content-review-queue' );
		}
		$user = get_user_by( 'id', $id );
		if ( $user instanceof WP_User ) {
			return $user->display_name;
		}
		/* translators: %d is the ID of a deleted WordPress user. */
		return sprintf( __( 'Former user #%d', 'digicyfr-content-review-queue' ), $id );
	}

	/**
	 * Format a stored UTC instant in the current site timezone.
	 *
	 * @param string $utc RFC 3339 UTC timestamp or empty.
	 * @return string Site-local date and time, or an em dash.
	 */
	public static function display_time( string $utc ): string {
		if ( '' === $utc ) {
			return '—';
		}
		try {
			$instant = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $instant->getTimestamp(), wp_timezone() );
		} catch ( Exception $error ) {
			return '—';
		}
	}

	/**
	 * Translate one status key.
	 *
	 * @param string $status Queue classification.
	 * @return string Localized label.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			'overdue' => __( 'Overdue', 'digicyfr-content-review-queue' ),
			'today' => __( 'Due today', 'digicyfr-content-review-queue' ),
			'upcoming' => __( 'Upcoming', 'digicyfr-content-review-queue' ),
			default => __( 'Unscheduled', 'digicyfr-content-review-queue' ),
		};
	}

	/**
	 * Translate an event action for history display.
	 *
	 * @param string $action Stored action key.
	 * @return string Localized label.
	 */
	private static function action_label( string $action ): string {
		return match ( $action ) {
			'schedule' => __( 'Scheduled', 'digicyfr-content-review-queue' ),
			'review' => __( 'Reviewed', 'digicyfr-content-review-queue' ),
			'snooze' => __( 'Snoozed', 'digicyfr-content-review-queue' ),
			default => __( 'Unknown action', 'digicyfr-content-review-queue' ),
		};
	}

	/**
	 * Quote every cell and prefix merchant formula text for human-review CSV.
	 *
	 * @param array $cells Values.
	 * @param array $merchant_indices Zero-based untrusted text columns.
	 * @return string CRLF-terminated row.
	 */
	public static function csv_row( array $cells, array $merchant_indices = array() ): string {
		$quoted = array();
		foreach ( $cells as $index => $value ) {
			$text = (string) $value;
			if ( in_array( $index, $merchant_indices, true ) && preg_match( '/^(?:[\x00-\x20]*[=+\-@]|[\t\r])/u', $text ) ) {
				$text = "'" . $text;
			}
			$quoted[] = '"' . str_replace( '"', '""', $text ) . '"';
		}
		return implode( ',', $quoted ) . "\r\n";
	}

	/**
	 * Build a filtered CSV from only editable queue rows for an administrator.
	 *
	 * @param string                 $tab Queue tab.
	 * @param string                 $search Search text.
	 * @param DateTimeImmutable|null $now Optional test clock.
	 * @return string Fixed-column UTF-8 CSV; empty if unauthorized.
	 */
	public static function csv_contents( string $tab, string $search, ?DateTimeImmutable $now = null ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return implode( '', iterator_to_array( self::csv_rows( $tab, $search, $now ), false ) );
	}

	/**
	 * Yield one CSV line at a time so web exports have bounded memory use.
	 *
	 * @param string                 $tab Queue tab.
	 * @param string                 $search Search text.
	 * @param DateTimeImmutable|null $now Optional test clock.
	 * @return Generator<string> CSV rows.
	 */
	private static function csv_rows( string $tab, string $search, ?DateTimeImmutable $now = null ): Generator {
		$tab    = self::tab( $tab );
		$search = self::search( $search );
		$today  = ( $now ?? current_datetime() )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		yield self::csv_row( array( 'post_id', 'post_type', 'title', 'due_date', 'assignee_id', 'assignee_name', 'last_reviewed_at_utc', 'queue_status' ) );
		foreach ( self::authorized_posts( $tab, $search, $today ) as $post ) {
			$due      = (string) get_post_meta( $post->ID, self::DUE, true );
			$assignee = (int) get_post_meta( $post->ID, self::ASSIGNEE, true );
			yield self::csv_row(
				array( $post->ID, $post->post_type, get_post_field( 'post_title', $post->ID, 'raw' ), $due, $assignee, self::user_name( $assignee ), get_post_meta( $post->ID, self::REVIEWED, true ), self::classify( $due, $today ) ),
				array( 2, 5 )
			);
		}
	}

	/** Register the private Tools queue. */
	public static function admin_menu(): void {
		$capability = current_user_can( 'edit_posts' ) ? 'edit_posts' : 'edit_pages';
		add_management_page( __( 'Content Review Queue', 'digicyfr-content-review-queue' ), __( 'Content Review Queue', 'digicyfr-content-review-queue' ), $capability, 'digicyfr-content-review-queue', array( __CLASS__, 'queue_screen' ) );
	}

	/** Register one identical review panel for classic and block editors. */
	public static function add_editor_box(): void {
		foreach ( array( 'post', 'page' ) as $type ) {
			add_meta_box( 'dcrq-panel', __( 'Content Review Queue', 'digicyfr-content-review-queue' ), array( __CLASS__, 'editor_box' ), $type, 'side', 'default' );
		}
	}

	/** Load local admin assets only on the queue and post/page editors. */
	public static function enqueue_admin(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( 'tools_page_digicyfr-content-review-queue' !== $screen->id && ! ( 'post' === $screen->base && in_array( $screen->post_type, array( 'post', 'page' ), true ) ) ) ) {
			return;
		}
		wp_enqueue_style( 'digicyfr-content-review-queue-admin', DIGICYFR_CONTENT_REVIEW_QUEUE_URL . 'assets/admin.css', array(), DIGICYFR_CONTENT_REVIEW_QUEUE_VERSION );
		wp_enqueue_script( 'digicyfr-content-review-queue-admin', DIGICYFR_CONTENT_REVIEW_QUEUE_URL . 'assets/admin.js', array(), DIGICYFR_CONTENT_REVIEW_QUEUE_VERSION, true );
	}

	/**
	 * Show review controls alongside a post/page editor.
	 *
	 * @param WP_Post $post Current post/page.
	 */
	public static function editor_box( WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		self::render_controls( $post->ID, false );
	}

	/**
	 * Render the same labeled action controls in the editor and queue detail page.
	 *
	 * @param int  $post_id Editable object ID.
	 * @param bool $from_queue Whether to return to the queue after saving.
	 */
	private static function render_controls( int $post_id, bool $from_queue ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$due       = (string) get_post_meta( $post_id, self::DUE, true );
		$assignee  = (int) get_post_meta( $post_id, self::ASSIGNEE, true );
		$tracked   = '1' === get_post_meta( $post_id, self::TRACKED, true );
		$today     = current_datetime()->format( 'Y-m-d' );
		$id_prefix = 'dcrq-' . $post_id . ( $from_queue ? '-queue' : '-editor' );
		$users     = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
		?>
		<div class="dcrq-controls" data-dcrq-post="<?php echo esc_attr( (string) $post_id ); ?>" data-dcrq-endpoint="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dcrq-from-queue="<?php echo $from_queue ? '1' : '0'; ?>">
			<p><strong><?php echo esc_html__( 'Current status:', 'digicyfr-content-review-queue' ); ?></strong> <?php echo esc_html( $tracked ? self::status_label( self::classify( $due, $today ) ) : __( 'Not tracked', 'digicyfr-content-review-queue' ) ); ?></p>
			<p class="description" id="<?php echo esc_attr( $id_prefix . '-help' ); ?>"><?php echo esc_html__( 'Dates use the WordPress site timezone. Review requires a future next date or Unscheduled. Snooze requires a future date and a reason; it does not mark content reviewed.', 'digicyfr-content-review-queue' ); ?></p>
			<label for="<?php echo esc_attr( $id_prefix . '-due' ); ?>"><?php echo esc_html__( 'Next due date', 'digicyfr-content-review-queue' ); ?></label>
			<input id="<?php echo esc_attr( $id_prefix . '-due' ); ?>" data-dcrq-due type="date" value="<?php echo esc_attr( $due ); ?>" aria-describedby="<?php echo esc_attr( $id_prefix . '-help' ); ?>" />
			<label for="<?php echo esc_attr( $id_prefix . '-unschedule' ); ?>"><input id="<?php echo esc_attr( $id_prefix . '-unschedule' ); ?>" data-dcrq-unschedule type="checkbox" <?php checked( '' === $due ); ?> /> <?php echo esc_html__( 'Unscheduled', 'digicyfr-content-review-queue' ); ?></label>
			<label for="<?php echo esc_attr( $id_prefix . '-assignee' ); ?>"><?php echo esc_html__( 'Assignee', 'digicyfr-content-review-queue' ); ?></label>
			<select id="<?php echo esc_attr( $id_prefix . '-assignee' ); ?>" data-dcrq-assignee>
				<option value="0" <?php selected( 0, $assignee ); ?>><?php echo esc_html__( 'Unassigned', 'digicyfr-content-review-queue' ); ?></option>
				<?php if ( $assignee > 0 && ! get_user_by( 'id', $assignee ) ) : ?>
					<option value="<?php echo esc_attr( (string) $assignee ); ?>" selected disabled><?php echo esc_html( self::user_name( $assignee ) ); ?></option>
				<?php endif; ?>
				<?php foreach ( $users as $user ) : ?>
					<?php if ( user_can( $user->ID, 'edit_post', $post_id ) ) : ?>
						<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $user->ID, $assignee ); ?>><?php echo esc_html( $user->display_name ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
			<label for="<?php echo esc_attr( $id_prefix . '-note' ); ?>"><?php echo esc_html__( 'Note or snooze reason (500 characters maximum)', 'digicyfr-content-review-queue' ); ?></label>
			<textarea id="<?php echo esc_attr( $id_prefix . '-note' ); ?>" data-dcrq-note rows="3" maxlength="500"></textarea>
			<div>
				<?php
				foreach ( array(
					'schedule' => __( 'Save schedule', 'digicyfr-content-review-queue' ),
					'review'   => __( 'Mark reviewed', 'digicyfr-content-review-queue' ),
					'snooze'   => __( 'Snooze due date', 'digicyfr-content-review-queue' ),
				) as $action => $label ) :
					?>
					<button type="button" class="button" data-dcrq-action="<?php echo esc_attr( $action ); ?>" data-dcrq-nonce="<?php echo esc_attr( wp_create_nonce( 'dcrq_' . $action . '_' . $post_id ) ); ?>" data-dcrq-uuid="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</div>
			<noscript><p><?php echo esc_html__( 'Enable JavaScript to submit review actions from this panel.', 'digicyfr-content-review-queue' ); ?></p></noscript>
			<?php self::render_history( $post_id ); ?>
		</div>
		<?php
	}

	/**
	 * Show recent private events without changing or truncating stored history.
	 *
	 * @param int $post_id Editable object ID.
	 */
	private static function render_history( int $post_id ): void {
		$events = get_post_meta( $post_id, self::EVENT, false );
		if ( array() === $events ) {
			return;
		}
		?>
		<details class="dcrq-history">
			<summary><?php echo esc_html__( 'Recent review history', 'digicyfr-content-review-queue' ); ?></summary>
			<ol>
				<?php foreach ( array_reverse( array_slice( $events, -10 ) ) as $event ) : ?>
					<?php
					if ( ! is_array( $event ) ) {
						continue; }
					?>
					<li>
						<?php echo esc_html( self::action_label( (string) ( $event['action'] ?? '' ) ) ); ?> — <?php echo esc_html( self::display_time( (string) ( $event['at_utc'] ?? '' ) ) ); ?> — <?php echo esc_html( self::user_name( (int) ( $event['actor_id'] ?? 0 ) ) ); ?>
						<?php
						if ( ! empty( $event['note'] ) ) :
							?>
							<p><?php echo esc_html( (string) $event['note'] ); ?></p><?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
		<?php
	}

	/** Render the Tools queue or one item's action panel. */
	public static function queue_screen(): void {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You cannot view this review queue.', 'digicyfr-content-review-queue' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only queue filters and item link.
		$item_id = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0;
		if ( $item_id > 0 ) {
			$post = get_post( $item_id );
			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $item_id ) ) {
				wp_die( esc_html__( 'You cannot view this item.', 'digicyfr-content-review-queue' ) );
			}
			echo '<div class="wrap"><h1>' . esc_html__( 'Review actions for:', 'digicyfr-content-review-queue' ) . ' ' . esc_html( get_the_title( $post ) ) . '</h1>';
			self::render_controls( $item_id, true );
			echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=digicyfr-content-review-queue' ) ) . '">' . esc_html__( 'Back to queue', 'digicyfr-content-review-queue' ) . '</a></p></div>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only queue tab.
		$tab = self::tab( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'attention' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$search = self::search( isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only authorized page number.
		$page   = isset( $_GET['dcrq_page'] ) ? max( 1, min( 10000, absint( $_GET['dcrq_page'] ) ) ) : 1;
		$result = self::queue_page( $tab, $search, $page );
		$tabs   = array(
			'attention'   => __( 'Overdue + due today', 'digicyfr-content-review-queue' ),
			'overdue'     => __( 'Overdue', 'digicyfr-content-review-queue' ),
			'today'       => __( 'Due today', 'digicyfr-content-review-queue' ),
			'upcoming'    => __( 'Upcoming', 'digicyfr-content-review-queue' ),
			'unscheduled' => __( 'Unscheduled', 'digicyfr-content-review-queue' ),
		);
		$base   = admin_url( 'tools.php?page=digicyfr-content-review-queue' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Content Review Queue', 'digicyfr-content-review-queue' ); ?></h1>
			<p><?php echo esc_html__( 'Due dates are site-local calendar dates. Only published posts and pages you can edit appear here. No reminders are sent.', 'digicyfr-content-review-queue' ); ?></p>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Queue status', 'digicyfr-content-review-queue' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" 
					<?php
					if ( $tab === $key ) :
						?>
						aria-current="page"<?php endif; ?> href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'tab' => $key,
									's'   => $search,
								),
								$base
							)
						);
						?>
						"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
				<input type="hidden" name="page" value="digicyfr-content-review-queue" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<label for="dcrq-search"><?php echo esc_html__( 'Search posts and pages', 'digicyfr-content-review-queue' ); ?></label>
				<input id="dcrq-search" name="s" type="search" maxlength="100" value="<?php echo esc_attr( $search ); ?>" />
				<?php submit_button( __( 'Search', 'digicyfr-content-review-queue' ), 'secondary', '', false ); ?>
			</form>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dcrq_export" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
					<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<?php wp_nonce_field( 'dcrq_export' ); ?>
					<?php submit_button( __( 'Export filtered CSV', 'digicyfr-content-review-queue' ), 'secondary', '', false ); ?>
				</form>
			<?php endif; ?>
			<?php if ( array() === $result['posts'] ) : ?>
				<p><?php echo esc_html__( 'No editable items match this queue view.', 'digicyfr-content-review-queue' ); ?></p>
			<?php else : ?>
				<table class="widefat striped dcrq-table">
					<thead><tr><th scope="col"><?php echo esc_html__( 'Title', 'digicyfr-content-review-queue' ); ?></th><th scope="col"><?php echo esc_html__( 'Type', 'digicyfr-content-review-queue' ); ?></th><th scope="col"><?php echo esc_html__( 'Due date', 'digicyfr-content-review-queue' ); ?></th><th scope="col"><?php echo esc_html__( 'Assignee', 'digicyfr-content-review-queue' ); ?></th><th scope="col"><?php echo esc_html__( 'Last reviewed', 'digicyfr-content-review-queue' ); ?></th><th scope="col"><?php echo esc_html__( 'Status', 'digicyfr-content-review-queue' ); ?></th><th scope="col"><?php echo esc_html__( 'Actions', 'digicyfr-content-review-queue' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $result['posts'] as $post ) : ?>
							<?php $due = (string) get_post_meta( $post->ID, self::DUE, true ); ?>
							<tr>
								<th scope="row"><a href="<?php echo esc_url( get_edit_post_link( $post->ID, 'raw' ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></th>
								<td data-label="<?php echo esc_attr__( 'Type', 'digicyfr-content-review-queue' ); ?>"><?php echo esc_html( 'page' === $post->post_type ? __( 'Page', 'digicyfr-content-review-queue' ) : __( 'Post', 'digicyfr-content-review-queue' ) ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Due date', 'digicyfr-content-review-queue' ); ?>"><?php echo '' !== $due ? esc_html( $due ) : '—'; ?></td>
								<td data-label="<?php echo esc_attr__( 'Assignee', 'digicyfr-content-review-queue' ); ?>"><?php echo esc_html( self::user_name( (int) get_post_meta( $post->ID, self::ASSIGNEE, true ) ) ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Last reviewed', 'digicyfr-content-review-queue' ); ?>"><?php echo esc_html( self::display_time( (string) get_post_meta( $post->ID, self::REVIEWED, true ) ) ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Status', 'digicyfr-content-review-queue' ); ?>"><?php echo esc_html( self::status_label( self::classify( $due, current_datetime()->format( 'Y-m-d' ) ) ) ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Actions', 'digicyfr-content-review-queue' ); ?>"><a href="<?php echo esc_url( add_query_arg( 'item', $post->ID, $base ) ); ?>"><?php echo esc_html__( 'Manage review', 'digicyfr-content-review-queue' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				if ( $page > 1 ) :
					?>
					<a class="button" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'tab'       => $tab,
								's'         => $search,
								'dcrq_page' => $page - 1,
							),
							$base
						)
					);
					?>
					"><?php echo esc_html__( 'Previous page', 'digicyfr-content-review-queue' ); ?></a><?php endif; ?>
				<?php
				if ( $result['has_next'] ) :
					?>
					<a class="button" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'tab'       => $tab,
								's'         => $search,
								'dcrq_page' => $page + 1,
							),
							$base
						)
					);
					?>
					"><?php echo esc_html__( 'Next page', 'digicyfr-content-review-queue' ); ?></a><?php endif; ?>
			</div></div>
		</div>
		<?php
	}

	/** Execute an action-specific nonce-protected administrator POST. */
	public static function handle_action(): void {
		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$raw_action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$action     = str_starts_with( $raw_action, 'dcrq_' ) ? substr( $raw_action, 5 ) : '';
		if ( ! in_array( $action, array( 'schedule', 'review', 'snooze' ), true ) ) {
			wp_die( esc_html__( 'Unknown review action.', 'digicyfr-content-review-queue' ) );
		}
		check_admin_referer( 'dcrq_' . $action . '_' . $post_id, '_dcrq_nonce' );
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot manage review data for this item.', 'digicyfr-content-review-queue' ) );
		}
		$input      = wp_unslash( $_POST );
		$result     = self::process_action( $post_id, $action, $input );
		$from_queue = '1' === ( $input['from_queue'] ?? '0' );
		$target     = $from_queue ? admin_url( 'tools.php?page=digicyfr-content-review-queue&item=' . $post_id ) : get_edit_post_link( $post_id, 'raw' );
		if ( ! is_string( $target ) || '' === $target ) {
			$target = admin_url( 'tools.php?page=digicyfr-content-review-queue' );
		}
		if ( is_wp_error( $result ) ) {
			$target = add_query_arg( 'dcrq_error', sanitize_key( $result->get_error_code() ), $target );
		} else {
			$target = add_query_arg( 'dcrq_notice', $result['status'], $target );
		}
		wp_safe_redirect( $target );
		exit;
	}

	/** Send the currently filtered, authorized queue as a nonce-protected CSV. */
	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot export this queue.', 'digicyfr-content-review-queue' ) );
		}
		check_admin_referer( 'dcrq_export' );
		$input  = wp_unslash( $_POST );
		$tab    = self::tab( $input['tab'] ?? '' );
		$search = self::search( $input['s'] ?? '' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="digicyfr-content-review-queue.csv"' );
		foreach ( self::csv_rows( $tab, $search ) as $row ) {
			echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Quoted, formula-guarded CSV rather than HTML.
		}
		exit;
	}

	/** Show action feedback near the editor and queue controls. */
	public static function admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( 'tools_page_digicyfr-content-review-queue' !== $screen->id && ! ( 'post' === $screen->base && in_array( $screen->post_type, array( 'post', 'page' ), true ) ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag from an action redirect.
		$notice = isset( $_GET['dcrq_notice'] ) ? sanitize_key( wp_unslash( $_GET['dcrq_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only error flag from an action redirect.
		$error = isset( $_GET['dcrq_error'] ) ? sanitize_key( wp_unslash( $_GET['dcrq_error'] ) ) : '';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible dcrq-result" tabindex="-1"><p>' . esc_html__( 'Review action saved.', 'digicyfr-content-review-queue' ) . '</p></div>';
		} elseif ( 'duplicate' === $notice ) {
			echo '<div class="notice notice-info is-dismissible dcrq-result" tabindex="-1"><p>' . esc_html__( 'This action was already recorded; no duplicate event was added.', 'digicyfr-content-review-queue' ) . '</p></div>';
		}
		if ( '' !== $error ) {
			$messages = array(
				'invalid_date'     => __( 'Enter a valid date for this action.', 'digicyfr-content-review-queue' ),
				'invalid_assignee' => __( 'Choose a user who can edit this item.', 'digicyfr-content-review-queue' ),
				'invalid_note'     => __( 'The note must be at most 500 characters.', 'digicyfr-content-review-queue' ),
				'invalid_snooze'   => __( 'Snooze needs a future date and a reason, on a scheduled item.', 'digicyfr-content-review-queue' ),
				'write_failed'     => __( 'Review data was not saved. Please try again.', 'digicyfr-content-review-queue' ),
				'partial_write'    => __( 'Review data was only partly saved. Ask an administrator to inspect it before retrying.', 'digicyfr-content-review-queue' ),
				'busy'             => __( 'Another review action is in progress. Try again.', 'digicyfr-content-review-queue' ),
			);
			$message  = $messages[ $error ] ?? __( 'Review action was not saved. Check the fields and try again.', 'digicyfr-content-review-queue' );
			echo '<div class="notice notice-error dcrq-result" role="alert" tabindex="-1"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}
