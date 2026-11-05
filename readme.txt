=== Digicyfr Content Review Queue ===
Tags: content review, editorial, pages, schedule, workflow
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

A private, explicit-date review queue for published posts and pages, with an eligible assignee and action history.

== Description ==

Schedule human reviews of published posts/pages with one site-local due date. The Tools queue offers overdue, due today, upcoming and unscheduled tabs. Review stamps a UTC time; snooze requires a future date and reason without marking reviewed. A private history records each action. Administrators can stream a filtered, formula-guarded CSV. English and Polish strings are bundled. No reminders, recurrence, public badges, remote calls or content edits are made. See README.md for permissions, CSV safety, data lifecycle and full scope.

== Installation ==

1. Upload the plugin ZIP under Plugins > Add New > Upload Plugin and activate it.
2. Open a post or page in the classic or block editor, enter a due date and choose Save schedule in Content Review Queue.
3. Open Tools > Content Review Queue to filter work you can edit. JavaScript is required for action buttons.

== Frequently Asked Questions ==

= Does a review alter a post's public modified date? =

No. Review actions write protected metadata only; they do not update the post.

= Are reminders or recurring intervals sent? =

No. Dates only determine the private Tools queue.

= What happens on uninstall? =

Protected review metadata and history are retained to prevent accidental loss.

== Changelog ==

= 0.1.0 =

* Initial candidate: explicit dates, private queue, schedule/review/snooze history, eligible assignment and guarded CSV.
