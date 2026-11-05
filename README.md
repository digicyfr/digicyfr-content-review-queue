# Digicyfr Content Review Queue

A free, GPL-licensed WordPress plugin for scheduling human review of published posts and pages. It keeps one explicit next due date, one eligible editor, and a private append-only action history. English and Polish strings are bundled. It sends no reminders and changes no public content when a review action is saved.

## Website guides and stable release

- [Content Review Queue: English overview and installation](https://www.digicyfr.com/en/wordpress-plugins/digicyfr-content-review-queue)
- [Kolejka przeglądu treści: opis i instalacja po polsku](https://www.digicyfr.com/pl/wordpress-plugins/digicyfr-content-review-queue)
- [Stable v0.1.0 release, ZIP and checksum](https://github.com/digicyfr/digicyfr-content-review-queue/releases/tag/v0.1.0)

<a href="https://www.digicyfr.com/en/wordpress-plugins/digicyfr-content-review-queue"><img src="https://www.digicyfr.com/images/wordpress-plugins/digicyfr-content-review-queue.png" alt="Private content review queue showing upcoming work" width="520"></a>

_Example screenshot from a disposable test site._

## Install and use

1. Upload the release ZIP under **Plugins → Add New → Upload Plugin** and activate it. Requires WordPress 7.1+ and PHP 8.3+.
2. Open an existing post or page in the classic or block editor. In **Content Review Queue**, set a date and optional eligible assignee, then choose **Save schedule**. Dates use the site's WordPress timezone. Choose **Unscheduled** to explicitly clear the date. A past date is allowed for scheduling.
3. **Mark reviewed** records the actor and UTC time. Enter a next date after today, or choose **Unscheduled**. **Snooze due date** requires an already scheduled item, a future date, and a reason. Snoozing does not mark the content reviewed.
4. Open **Tools → Content Review Queue** to see only published posts/pages you can edit. The default tab includes overdue and due-today items. Other tabs show each status, including tracked items without a due date. Search and pagination apply to your editable rows. A post moved to Draft leaves the active queue and returns on republishing with its review data intact.
5. Administrators can export the current filtered queue to UTF-8 CSV. Columns are `post_id,post_type,title,due_date,assignee_id,assignee_name,last_reviewed_at_utc,queue_status`. Text cells are quoted and spreadsheet formula-like titles/names receive a leading apostrophe. The CSV is for human review; that safety prefix affects machine imports. The export streams rows, so large queues do not need a full CSV in server memory.

Actions require JavaScript in the editor/queue panel. Each action has a separate nonce and checks permission on the specific post/page; selected assignees must also be able to edit it. The Tools menu supports roles with `edit_posts` or `edit_pages`; each row is still checked with `edit_post`. CSV additionally requires `manage_options`. The plugin creates no public output, REST endpoint, cron task, remote request, or custom table. A change to the site timezone can change which tab a due date falls under, but does not rewrite saved dates or event timestamps.

**Data lifecycle:** Deactivation and uninstall retain protected review meta and history to prevent accidental loss. Reactivate the plugin to restore its UI. To remove data, edit WordPress post meta deliberately or delete the relevant posts/pages. A deleted assignee appears as “Former user #ID” until reassigned; historical actor IDs remain.

**Updates:** Version 0.1.0 uses manual ZIP updates from the Digicyfr plugin page. The unique Update URI prevents unrelated directory updates; no automatic update endpoint is provided yet.

Source: [github.com/digicyfr/digicyfr-content-review-queue](https://github.com/digicyfr/digicyfr-content-review-queue). For ordinary support use [Issues](https://github.com/digicyfr/digicyfr-content-review-queue/issues); for vulnerabilities use [private security reporting](SECURITY.md).

## Development

This plugin is independent of other Digicyfr plugins. `release-files.txt` lists only runtime files and user documentation. From the repository root:

```bash
python3 scripts/release.py
python3 scripts/release.py --verify dist/digicyfr-content-review-queue-0.1.0.zip
(cd dist && sha256sum -c digicyfr-content-review-queue-0.1.0.zip.sha256)
bash tests/run-wordpress-integration.sh dist/digicyfr-content-review-queue-0.1.0.zip
```

License: GPL-2.0-or-later. See [LICENSE](LICENSE).
