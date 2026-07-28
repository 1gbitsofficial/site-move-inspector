# 1Gbits Site Move Inspector for Joomla 1.0

## Product promise

Give a Joomla administrator a private, read-only migration preflight before changing hosting. The component records concrete compatibility and capacity risks, compares an optional destination profile, and produces a redacted support report.

## Supported runtime

- Joomla 5.4 on PHP 8.1 or newer.
- Joomla 6.1 on PHP 8.3 or newer.
- MySQL/MariaDB and PostgreSQL for component-owned temporary job storage.
- One administrator component ZIP with no frontend component, module, plugin, or external runtime dependency.

## Version 1.0 scope

- Manual scans initiated by a signed-in administrator with `core.manage`.
- Local processing with no telemetry or requests to 1Gbits.
- Batched metadata-only filesystem scan contained within `JPATH_ROOT`.
- Joomla/PHP/database/server inventory and PHP runtime limits.
- Anonymous extension and template aggregates from `#__extensions`; the `params` column is never read.
- Aggregate database table count, size, and storage-engine checks where the database account permits them.
- HTTPS, debug mode, temporary/log path layout, scheduled-task counts, symlinks, filesystem access, and disk-capacity checks.
- Optional destination PHP, database family/version, and disk profile.
- Direct TXT and JSON exports generated in memory.
- One short-lived, user-bound scan job stored in the component-owned jobs table.

## Explicitly out of scope

- Backup, migration, restore, rollback, or database search-and-replace.
- SSH, control-panel, hosting-provider, or 1Gbits API connections.
- Malware/security scanning, performance benchmarking, or cache changes.
- Scheduled scans, retained history, email, telemetry, advertising, or upsells.
- Reading file contents, `configuration.php`, Joomla content/user records, extension parameters, credentials, secrets, logs, or configuration values containing private identifiers.
- Accepting filesystem roots or paths from the browser.

## Security and privacy

- Page access, scan start/step/cancel, and export require `core.manage` for `com_sitemoveinspector`.
- Every stateful request requires Joomla's POST CSRF token.
- Scan jobs use 128-bit random identifiers and are bound to the creating user.
- A short atomic lock prevents concurrent steps from overwriting a cursor.
- Active jobs expire after 30 minutes; completed reports expire after 60 minutes. Expired rows are deleted opportunistically.
- Symlinks and special files are never followed or opened.
- The scan stops after 100,000 entries, 60 cumulative seconds, 10,000 entries in one directory, or 25,000 visited directories and marks the report partial.
- Exports are rebuilt from an allowlist and omit absolute paths, domains, URLs, IPs, emails, credentials, extension/template names, filenames, table names, parameters, content, and raw configuration.
- Export bodies are generated in memory and are not retained as files.
- Uninstall removes only the component-owned jobs table and installed component files.

## Overall result

- `high_risk`: at least one critical result.
- `review_recommended`: warning, unknown result, or partial scan.
- `no_blockers`: complete scan with no critical, warning, or unknown results.

The result is migration-planning guidance, not a guarantee of success.
