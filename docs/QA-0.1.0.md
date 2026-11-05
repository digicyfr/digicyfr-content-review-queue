# QA — Digicyfr Content Review Queue 0.1.0

## Exact candidate

- ZIP: `dist/digicyfr-content-review-queue-0.1.0.zip`; the byte-identical ZIP is published as a GitHub prerelease.
- SHA-256: `ef5fe7dbc2acf8b3601c340aca38ef1a791eb474e5a33494ee1cf5780d590113`
- `tools/verify_zip.py ... --against-source`: passed; all 10 runtime/documentation files match source byte-for-byte.
- Target tested locally: WordPress 7.1, PHP 8.3.33, MariaDB 11.4.13. This evidence does not claim compatibility with untested newer releases.

## Automated gates

- `tools/lint-php.sh digicyfr-content-review-queue`: passed for plugin/runtime and integration PHP.
- `node --check assets/admin.js`: passed.
- WPCS PHPCS WordPress standard for runtime PHP: passed with zero findings. The required `meta_query` slow-query warning is explained and suppressed at its bounded batch query.
- Disposable exact-ZIP install/activate/deactivate/reactivate: passed.
- Real WordPress integration (`tests/run-wordpress-integration.sh`): passed on the exact final hash. Fixtures cover bundled Polish MO loading and English runtime labels, strict dates and Warsaw DST before/after the jump, scheduling/review/snooze state and UTC history, literal backslash round-trip, duplicate UUID, unchanged `post_modified`/GMT, invalid dates/assignees/actions, failed state/event writes and rollback, draft/republish retention, untouched content, filtered queue and CSV, formula guarding, UTF-8 search boundary, 20-row authorized pagination excluding another author's rows/count, denied forged object action, deleted user fallback, and history after deactivation/reactivation.
- Plugin Check on the runtime-identical earlier ZIP: one `plugin_updater_detected` ERROR for the intentional self-hosted `Update URI`, and one `load_plugin_textdomainFound` WARNING for bundled Polish translations. These checks target WordPress.org distribution; this candidate is for manual download from digicyfr.com. No other Plugin Check finding.

## Browser and accessibility

On the runtime-identical earlier ZIP, the orchestrator's fresh Chromium run passed initial schedule from blank (date entry unchecked Unscheduled), keyboard Enter submission, focus on the result notice after redirect, Upcoming queue, 390px layout without overflow, and filtered CSV download. A second disposable Chromium run loaded a saved-notice queue URL and confirmed `document.activeElement` was the `.dcrq-result` notice after 500 ms. A third disposable Chromium run confirmed the panel in both block and classic editors (a test-only must-use filter selected classic), and an authenticated `admin-post.php` schedule POST without its nonce returned HTTP 403. Mobile table keeps real headers in a horizontally scrollable table. Native controls have associated labels, help text, and text status; errors have `role="alert"`.

## Post-tag browser check on the exact GitHub ZIP

On 2026-11-05, the public `v0.1.0` ZIP and sidecar were downloaded from GitHub and passed `sha256sum --check` at the hash above. The ZIP installed, activated, deactivated and reactivated on a new disposable WordPress 7.1/PHP 8.3.33/MariaDB 11.4.13 site. Chromium 156.0.8078.4 exercised a synthetic published page:

- Saved a due date, eligible assignee and note through the private item form. The redirected success notice received focus and the date persisted. [Scheduled item](evidence/0.1.0/digicyfr-review-queue-scheduled.png).
- The item appeared in the **Upcoming** filter; the filtered CSV download contained its title and due date. [Upcoming queue](evidence/0.1.0/digicyfr-review-queue-upcoming.png).
- **Mark reviewed** added a history event and focused the success notice. **Snooze due date** saved a later date and history event. [Review history](evidence/0.1.0/digicyfr-review-queue-reviewed.png) · [Snoozed item](evidence/0.1.0/digicyfr-review-queue-snoozed.png).
- An invalid past review date returned the error notice with focus. [Invalid-date notice](evidence/0.1.0/digicyfr-review-queue-error.png).
- The block editor showed the three action controls with the saved due date. [Editor panel](evidence/0.1.0/digicyfr-review-queue-editor.png). A separate 390px session showed document width equal to the viewport, without page errors. [390px queue](evidence/0.1.0/digicyfr-review-queue-390px.png).

Only synthetic data was used. The disposable containers and volumes were removed. A human screen-reader pass and representative small-team staging flow remain open.

## Automated accessibility audit on the exact public ZIP (2026-11-05)

The public ZIP and SHA-256 sidecar were downloaded again and verified at the hash above; all ten packaged files matched source. In a fresh disposable WordPress 7.1/PHP 8.3.33/MariaDB 11.4.13 site, Playwright Chromium 156 with axe-core 4.10.3 audited the full **Unscheduled** queue, a synthetic page's **Manage review** form, and each plugin-owned `.wrap` subtree against WCAG 2/2.1/2.2 A/AA rules. Both plugin pages had **zero definite axe violations** at desktop and 390px. The WordPress core Pages list baseline had a `link-in-text-block` finding on its **Privacy Policy** row, absent on the plugin pages. [Machine-readable audit](evidence/0.1.0/axe-admin-20261105.json).

Keyboard Enter on **Manage review** opened the form. The date input showed a visible 2px focus outline under keyboard Tab. Chrome's accessibility tree exposed named Unscheduled, Save schedule, Mark reviewed, and Snooze due date controls. The queue and item documents stayed 390px wide, with the queue's 863px table scrolling within its 366px container; no browser script errors occurred. [390px queue](evidence/0.1.0/a11y-queue-390px.png) · [390px item form](evidence/0.1.0/a11y-item-390px.png).

Axe could not determine color contrast for the native assignee select and partly obscured note textarea; those incomplete checks are not passes. Automated coverage and the keyboard check do not replace a human screen-reader session or the representative small-team staging flow.

## Current artifact recheck and limits

On 2026-11-05, the rebuilt ZIP and SHA-256 sidecar matched the standalone and workspace packagers. This exact rebuilt ZIP passed install/activate/deactivate/reactivate and the complete WordPress integration fixture on the tested stack. Earlier browser and Plugin Check evidence is from a byte-different ZIP with identical runtime files.

- Administrator export streams rows; the public `csv_contents()` helper materializes a CSV only for tests and internal calls. Large queue scans may be slow because WordPress post meta is queried in 100-ID batches, then each row is checked with `edit_post` before pagination/CSV. The assignee selector lists eligible users by loading all users, which is suitable for the small-team v0.1 scope and may become slow on a very large user directory.
- Test harness directly calls the action engine for most fixtures; a separate browser POST verifies exact `admin-post.php` missing-nonce rejection. WordPress core's `check_admin_referer` performs the nonce check. Valid action POST and redirect were exercised in the orchestrator's browser run.
- A public GitHub prerelease exists. No digicyfr.com production publication, WordPress.org submission or live customer-site installation was performed.
