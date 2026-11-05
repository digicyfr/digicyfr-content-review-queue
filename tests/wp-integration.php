<?php
/** Exercise the installed exact ZIP in a disposable WordPress site. */

declare( strict_types=1 );

$port = getenv( 'DIGICYFR_TEST_PORT' );
$_SERVER['HTTP_HOST']   = '127.0.0.1:' . $port;
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PORT'] = $port;
$_SERVER['HTTPS']       = 'off';

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}

function item( string $title, int $author, string $type = 'post', string $status = 'publish' ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_author' => $author ), true );
	check( is_int( $id ) && $id > 0, 'created ' . $title );
	return $id;
}

function action( int $id, string $name, string $due, string $unschedule = '0', string $assignee = '0', string $note = '', ?DateTimeImmutable $now = null, ?string $uuid = null ) {
	return Digicyfr_Content_Review_Queue::process_action( $id, $name, array( 'uuid' => $uuid ?? wp_generate_uuid4(), 'due' => $due, 'unschedule' => $unschedule, 'assignee' => $assignee, 'note' => $note ), $now );
}

function ids( array $page ): array {
	return array_map( static fn( WP_Post $post ): int => $post->ID, $page['posts'] );
}

$plugin = 'digicyfr-content-review-queue/digicyfr-content-review-queue.php';
check( is_plugin_active( $plugin ), 'exact ZIP active' );
check( '0.1.0' === DIGICYFR_CONTENT_REVIEW_QUEUE_VERSION, 'version 0.1.0' );
check( 'Content Review Queue' === __( 'Content Review Queue', 'digicyfr-content-review-queue' ), 'English runtime label' );
check( load_textdomain( 'digicyfr-content-review-queue', WP_PLUGIN_DIR . '/digicyfr-content-review-queue/languages/digicyfr-content-review-queue-pl_PL.mo' ), 'bundled Polish MO loads' );
check( 'Kolejka przeglądu treści' === __( 'Content Review Queue', 'digicyfr-content-review-queue' ), 'bundled Polish runtime label' );
unload_textdomain( 'digicyfr-content-review-queue' );
$meta = get_registered_meta_keys( 'post', 'post' );
foreach ( array( '_dcrq_tracked', '_dcrq_due', '_dcrq_assignee', '_dcrq_reviewed_at_utc', '_dcrq_event' ) as $key ) {
	check( isset( $meta[ $key ] ) && false === $meta[ $key ]['show_in_rest'], $key . ' private' );
}
check( false === Digicyfr_Content_Review_Queue::valid_date( '2027-02-30' ) && false === Digicyfr_Content_Review_Queue::valid_date( '2027-3-28' ), 'strict date validation' );
check( 'today' === Digicyfr_Content_Review_Queue::classify( '2027-03-28', '2027-03-28' ), 'exact local day' );
update_option( 'timezone_string', 'Europe/Warsaw' );
$before_jump = new DateTimeImmutable( '2027-03-28 00:30:00', wp_timezone() );
$after_jump = new DateTimeImmutable( '2027-03-28 03:30:00', wp_timezone() );
foreach ( array( $before_jump, $after_jump ) as $clock ) {
	$today = $clock->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	check( 'overdue' === Digicyfr_Content_Review_Queue::classify( '2027-03-27', $today ), 'DST overdue' );
	check( 'today' === Digicyfr_Content_Review_Queue::classify( '2027-03-28', $today ), 'DST today' );
	check( 'upcoming' === Digicyfr_Content_Review_Queue::classify( '2027-03-29', $today ), 'DST upcoming' );
}
check( 7200 === $after_jump->getTimestamp() - $before_jump->getTimestamp(), 'DST fixture spans two elapsed hours' );

$admin = get_user_by( 'login', 'digicyfr_test_admin' );
check( $admin instanceof WP_User, 'admin user' );
wp_set_current_user( $admin->ID );
$author_a = wp_create_user( 'dcrq_author_a', 'test-password-only', 'a@example.invalid' );
$author_b = wp_create_user( 'dcrq_author_b', 'test-password-only', 'b@example.invalid' );
$subscriber = wp_create_user( 'dcrq_subscriber', 'test-password-only', 'subscriber@example.invalid' );
check( is_int( $author_a ) && is_int( $author_b ) && is_int( $subscriber ), 'users created' );
( new WP_User( $author_a ) )->set_role( 'author' );
( new WP_User( $author_b ) )->set_role( 'author' );
( new WP_User( $subscriber ) )->set_role( 'subscriber' );
$main = item( '=HYPERLINK("https://evil.invalid")', $author_a );
$page = item( 'Strona z ą', $author_a, 'page' );
$hidden = item( 'Hidden B', $author_b );
$untouched = item( 'Untouched', $author_a );
$input_clock = new DateTimeImmutable( '2027-03-28 00:30:00', wp_timezone() );
$original = get_post( $main );
$old_modified = array( $original->post_modified, $original->post_modified_gmt );
$uuid = wp_generate_uuid4();
$saved = action( $main, 'schedule', '2027-03-27', '0', (string) $author_a, 'Sprawdź C:\\katalog', $input_clock, $uuid );
check( is_array( $saved ) && 'saved' === $saved['status'], 'first schedule saved' );
check( '2027-03-27' === get_post_meta( $main, '_dcrq_due', true ), 'due date stored' );
check( '1' === get_post_meta( $main, '_dcrq_tracked', true ), 'tracked marker stored' );
check( 'Sprawdź C:\\katalog' === get_post_meta( $main, '_dcrq_event', false )[0]['note'], 'event backslash round-trip' );
check( $old_modified === array( get_post( $main )->post_modified, get_post( $main )->post_modified_gmt ), 'action does not modify public post timestamps' );
$again = action( $main, 'schedule', '2027-03-27', '0', (string) $author_a, 'Sprawdź C:\\katalog', $input_clock, $uuid );
check( is_array( $again ) && 'duplicate' === $again['status'] && 1 === count( get_post_meta( $main, '_dcrq_event', false ) ), 'UUID retry deduplicated' );
check( is_wp_error( action( $main, 'schedule', '2027-03-27', '1', '0', '', $input_clock ) ), 'date and unschedule conflict rejected' );
check( is_wp_error( action( $main, 'schedule', '2027-02-30', '0', '0', '', $input_clock ) ), 'impossible date rejected' );
check( is_wp_error( action( $main, 'schedule', '2027-03-29', '0', (string) $subscriber, '', $input_clock ) ), 'ineligible assignee rejected' );
check( is_wp_error( action( $main, 'review', '2027-03-28', '0', '0', '', $input_clock ) ), 'review same-day due rejected' );
check( is_wp_error( action( $main, 'snooze', '2027-03-29', '0', '0', '', $input_clock ) ), 'snooze missing reason rejected' );
$review = action( $main, 'review', '2027-03-29', '0', (string) $author_a, 'Aktualne', $input_clock );
check( is_array( $review ) && 'saved' === $review['status'], 'review saved' );
$reviewed = get_post_meta( $main, '_dcrq_reviewed_at_utc', true );
check( '2027-03-27T23:30:00Z' === $reviewed, 'review stamps UTC instant before DST jump' );
check( '2027-03-27' === $review['event']['old_due'] && '2027-03-29' === $review['event']['new_due'] && $admin->ID === $review['event']['actor_id'], 'event old/new and actor' );
$snooze = action( $main, 'snooze', '2027-03-30', '0', '0', 'Potrzebne zdjęcie', $input_clock );
check( is_array( $snooze ) && 'saved' === $snooze['status'] && $reviewed === get_post_meta( $main, '_dcrq_reviewed_at_utc', true ), 'snooze retains review time' );
check( $old_modified === array( get_post( $main )->post_modified, get_post( $main )->post_modified_gmt ), 'all actions preserve modified timestamps' );
check( 3 === count( get_post_meta( $main, '_dcrq_event', false ) ), 'history append-only' );
$unscheduled = action( $page, 'schedule', '', '1', '0', '', $input_clock );
check( is_array( $unscheduled ) && 'saved' === $unscheduled['status'], 'explicit unscheduled page saved' );
check( is_wp_error( action( $page, 'snooze', '2027-03-30', '0', '0', 'reason', $input_clock ) ), 'cannot snooze unscheduled' );
$hidden_action = action( $hidden, 'schedule', '2027-03-27', '0', (string) $author_b, '', $input_clock );
check( is_array( $hidden_action ), 'hidden owner item scheduled' );

// A failure after a changed due value rolls back to the old state and appends no event.
$events_before = count( get_post_meta( $main, '_dcrq_event', false ) );
$fail_add = static function ( $check, $object_id, $meta_key ) {
	return '_dcrq_event' === $meta_key ? false : $check;
};
add_filter( 'add_post_metadata', $fail_add, 10, 3 );
$failed = action( $main, 'schedule', '2027-04-01', '0', (string) $author_a, '', $input_clock );
remove_filter( 'add_post_metadata', $fail_add, 10 );
check( is_wp_error( $failed ) && 'write_failed' === $failed->get_error_code(), 'failed append reports error' );
check( '2027-03-30' === get_post_meta( $main, '_dcrq_due', true ) && $events_before === count( get_post_meta( $main, '_dcrq_event', false ) ), 'failed append rolled back' );
$fail_update = static function ( $check, $object_id, $meta_key ) {
	return '_dcrq_due' === $meta_key ? false : $check;
};
add_filter( 'update_post_metadata', $fail_update, 10, 3 );
$failed = action( $main, 'schedule', '2027-04-01', '0', (string) $author_a, '', $input_clock );
remove_filter( 'update_post_metadata', $fail_update, 10 );
check( is_wp_error( $failed ) && '2027-03-30' === get_post_meta( $main, '_dcrq_due', true ), 'failed state write reports error and retains state' );

wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ) );
check( ! in_array( $page, ids( Digicyfr_Content_Review_Queue::queue_page( 'unscheduled', '', 1, $input_clock ) ), true ), 'draft excluded from queue' );
wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ) );
check( in_array( $page, ids( Digicyfr_Content_Review_Queue::queue_page( 'unscheduled', '', 1, $input_clock ) ), true ), 'republished item returns with meta' );
check( ! in_array( $untouched, ids( Digicyfr_Content_Review_Queue::queue_page( 'unscheduled', '', 1, $input_clock ) ), true ), 'never tracked stays out' );
check( in_array( $main, ids( Digicyfr_Content_Review_Queue::queue_page( 'upcoming', '', 1, $input_clock ) ), true ), 'reviewed item in upcoming' );
check( in_array( $hidden, ids( Digicyfr_Content_Review_Queue::queue_page( 'overdue', '', 1, $input_clock ) ), true ), 'overdue item in queue' );

$csv = Digicyfr_Content_Review_Queue::csv_contents( 'upcoming', '', $input_clock );
check( str_starts_with( $csv, '"post_id","post_type","title","due_date","assignee_id","assignee_name","last_reviewed_at_utc","queue_status"' . "\r\n" ), 'fixed CSV header' );
check( str_contains( $csv, '"\'=HYPERLINK(' ) && str_contains( $csv, '"upcoming"' ), 'formula title guarded and status emitted' );
check( ! str_contains( $csv, 'Hidden B' ), 'filtered CSV excludes different status' );
$guarded = Digicyfr_Content_Review_Queue::csv_row( array( '=x', '  +x', "\t-x", '"quoted"', "a,b\nc" ), range( 0, 4 ) );
check( str_contains( $guarded, '"\'=x"' ) && str_contains( $guarded, '"\'  +x"' ) && str_contains( $guarded, "\"'\t-x\"" ), 'CSV formula starts protected' );
check( str_contains( $guarded, '"""quoted"""' ) && str_contains( $guarded, "a,b\nc" ), 'CSV quoting' );
$long_search = str_repeat( 'a', 99 ) . 'ą';
$search_item = item( $long_search, $author_a );
action( $search_item, 'schedule', '2027-03-27', '0', '0', '', $input_clock );
$searched = Digicyfr_Content_Review_Queue::queue_page( 'overdue', $long_search . 'ignored', 1, $input_clock );
check( in_array( $search_item, ids( $searched ), true ), 'search 100-character cap retains accented UTF-8 boundary' );
check( str_contains( Digicyfr_Content_Review_Queue::csv_contents( 'overdue', $long_search . 'ignored', $input_clock ), $long_search ), 'CSV search boundary works' );

// Object-level authorization happens before visible rows and pagination are counted.
for ( $i = 0; $i < 22; ++$i ) {
	$id = item( sprintf( 'Author A %02d', $i ), $author_a );
	action( $id, 'schedule', '2027-03-27', '0', (string) $author_a, '', $input_clock );
}
wp_set_current_user( $author_a );
check( ! current_user_can( 'edit_post', $hidden ), 'author cannot edit another owner' );
check( is_wp_error( action( $hidden, 'schedule', '2027-03-29', '0', '0', '', $input_clock ) ), 'forged object action blocked' );
$p1 = Digicyfr_Content_Review_Queue::queue_page( 'overdue', '', 1, $input_clock );
$p2 = Digicyfr_Content_Review_Queue::queue_page( 'overdue', '', 2, $input_clock );
check( 20 === count( $p1['posts'] ) && true === $p1['has_next'], 'authorized page one full with next' );
check( 3 === count( $p2['posts'] ) && false === $p2['has_next'], 'authorized page two excludes hidden row/count' );
check( ! in_array( $hidden, array_merge( ids( $p1 ), ids( $p2 ) ), true ), 'hidden row absent from both pages' );
check( '' === Digicyfr_Content_Review_Queue::csv_contents( 'overdue', '', $input_clock ), 'author cannot export CSV' );
wp_set_current_user( $admin->ID );
$admin_csv = Digicyfr_Content_Review_Queue::csv_contents( 'overdue', '', $input_clock );
check( 25 === substr_count( $admin_csv, "\r\n" ) && str_contains( $admin_csv, 'Hidden B' ), 'admin filtered export includes 24 data rows including other owner' );
wp_delete_user( $author_a, $admin->ID );
check( 'Former user #' . $author_a === Digicyfr_Content_Review_Queue::user_name( $author_a ), 'deleted assignee fallback' );
check( $author_a === get_post_meta( $main, '_dcrq_event', false )[0]['new_assignee_id'], 'historical user ID retained' );
$history_count = count( get_post_meta( $main, '_dcrq_event', false ) );
deactivate_plugins( $plugin );
check( $history_count === count( get_post_meta( $main, '_dcrq_event', false ) ), 'deactivation retains history' );
activate_plugin( $plugin );
check( $history_count === count( get_post_meta( $main, '_dcrq_event', false ) ), 'reactivation retains history' );
check( ! str_contains( apply_filters( 'the_content', 'Public marker' ), 'dcrq-' ), 'no public content injected' );

printf( "PASS: exact ZIP WordPress integration (version %s, WP %s, PHP %s)\n", DIGICYFR_CONTENT_REVIEW_QUEUE_VERSION, get_bloginfo( 'version' ), PHP_VERSION );
