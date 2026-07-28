# 1Gbits Site Move Inspector 1.0

## Product promise

Give a WordPress administrator a private, read-only migration preflight before
they change hosting. The plugin identifies concrete risks, compares the source
site with an optional destination profile, and produces a redacted support
report.

## Version 1.0 scope

- Manual scans only.
- Local processing with no telemetry or requests to 1Gbits.
- Batched metadata-only filesystem scan contained within `ABSPATH`.
- Environment, PHP extension, plugin/theme requirement, database-size, cron,
  URL/HTTPS, custom-path, drop-in, symlink, and disk-capacity checks.
- Optional self-request tests to the site's own home and REST URLs.
- Optional destination PHP, database, disk, and multisite profile.
- Direct TXT and JSON exports generated in memory.
- One short-lived scan job per authorized user, stored in plugin-owned
  transients and user metadata.

## Explicitly out of scope

- Backup, migration, restore, rollback, or database search-and-replace.
- SSH, control-panel, hosting-provider, or 1Gbits API connections.
- Malware/security scanning, performance benchmarking, or cache changes.
- Scheduled scans, history, email, telemetry, advertising, or upsells.
- Reading file contents, WordPress content, option values, user data, secrets,
  credentials, logs, or configuration values.

## Security and privacy

- Single-site access requires `manage_options`.
- Multisite access requires a Super Admin with `manage_network_options`.
- REST requests require cookie authentication, a REST nonce, and capability
  checks; scan jobs are also bound to the creating user.
- Symlinks and special files are never followed or opened.
- Paths supplied by the browser are never accepted.
- The scan stops after 100,000 entries or 60 cumulative seconds and marks the
  result partial.
- Multisite software inventory is bounded to 250 sites and marks larger
  networks partial.
- Active cursors expire after 30 minutes; completed reports expire after 60
  minutes. Exports are generated on demand and are not retained as files.
- Reports are built from an allowlist. Exports omit absolute paths, site
  domains, IPs, emails, database credentials, option/content values, cookies,
  headers, stack traces, and upload filenames.

## Overall result

- `high_risk`: at least one critical result.
- `review_recommended`: warning, unknown result, or partial scan.
- `no_blockers`: complete scan with no critical, warning, or unknown results.

The result is guidance, not a guarantee that a migration will succeed.
