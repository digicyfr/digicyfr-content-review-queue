# digicyfr-content-review-queue 0.1.0 release notes

This is the first free GPL-2.0-or-later release for manual ZIP installation. Source: https://github.com/digicyfr/digicyfr-content-review-queue. The `release/0.1.0` branch records the reviewed version. The branch alone is source code; use the attached `digicyfr-content-review-queue-0.1.0.zip` and verify its `.sha256` sidecar before installation.

## Included

Private post/page due dates, eligible editor assignment, action history, queue filters, classic/block editor controls, and guarded CSV export.

## Verification and compatibility

WordPress 7.1, PHP 8.3.33 and MariaDB 11.4.13. The exact GitHub prerelease ZIP passed install, integration fixtures, and a post-tag Chromium run covering schedule, review, snooze, invalid-date feedback, focused notices, filtered CSV, editor panel and 390px layout. PHP/JS syntax and WPCS passed. Plugin Check ran on an earlier runtime-identical ZIP and reported only the documented self-host distribution findings.

## Limits and follow-up checks

A post-tag axe-core 4.10.3 check of the exact public ZIP found no definite violations on the private queue or item form at desktop or 390px; keyboard focus and named action controls were also checked. Some native-control contrast cases remained inconclusive to axe. See the [dated QA evidence](docs/QA-0.1.0.md). A manual screen-reader pass, a representative small-team staging flow, multisite behavior, and very large editorial teams remain untested. Assignee selection and queue scans may be slow at large scale. The plugin sends no reminders or email.

The `Update URI` protects this self-hosted plugin from an unrelated WordPress.org plugin with the same slug. It does not provide automatic updates. WordPress.org submission would need a separate review and distribution-specific changes. See [QA record](docs/QA-0.1.0.md) for the detailed local evidence.
