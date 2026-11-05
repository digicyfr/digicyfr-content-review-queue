# Contributing

Issues and pull requests are welcome at [digicyfr/digicyfr-content-review-queue](https://github.com/digicyfr/digicyfr-content-review-queue). Please describe the affected WordPress/PHP versions and steps to reproduce. For security findings, follow [SECURITY.md](SECURITY.md).

Changes should preserve the plugin's free GPL-2.0-or-later license, English and Polish strings, stated data-retention behavior and admin permission checks. Keep each change focused and add a real WordPress integration fixture when behavior changes. Run the local checks before requesting review:

```bash
python3 scripts/release.py
python3 scripts/release.py --verify dist/digicyfr-content-review-queue-0.1.0.zip
(cd dist && sha256sum -c digicyfr-content-review-queue-0.1.0.zip.sha256)
bash tests/run-wordpress-integration.sh dist/digicyfr-content-review-queue-0.1.0.zip
```

The integration script uses Docker Compose and removes its disposable volumes. CI lints PHP/JS, builds and verifies the ZIP, then runs the WordPress integration fixture. The `release-files.txt` allowlist determines what is published. Update the main plugin version, `readme.txt` stable tag and `CHANGELOG.md` together for a new release. A release branch such as `release/0.1.0` represents the reviewed source for that version; attach the resulting ZIP and SHA-256 sidecar to the matching GitHub release after CI passes.
