# Site Move Inspector for Joomla

Site Move Inspector is a free, administrator-only component for Joomla 5.4 and 6.1, maintained by [1Gbits](https://1gbits.com/). It runs a private, read-only migration preflight and creates redacted TXT and JSON reports.

[Download Site Move Inspector 1.0.1](https://github.com/1gbitsofficial/site-move-inspector/releases/tag/joomla-v1.0.1)

## What it checks

- Joomla, PHP, database, web-server, and PHP-extension metadata;
- installed extension and template aggregates without reading parameters;
- HTTPS, debug mode, temporary/log path layout, and scheduled-task counts;
- bounded, resumable filesystem metadata within `JPATH_ROOT`;
- aggregate database size and storage-engine information where permitted;
- source disk capacity and an optional destination PHP/database/disk profile.

The component does not create backups, migrate data, read file contents, inspect Joomla content or user records, change configuration, or contact 1Gbits.

## Compatibility

- Joomla 5.4 with PHP 8.1 or newer;
- Joomla 6.1 with PHP 8.3 or newer;
- MySQL/MariaDB and PostgreSQL component-owned job schemas;
- one installable ZIP for both supported Joomla generations.

## Development

From the repository root:

```powershell
composer test:joomla
composer lint:joomla
composer build:joomla
```

The release builder creates:

```text
dist/joomla/com_sitemoveinspector-1.0.1.zip
dist/joomla/com_sitemoveinspector-1.0.1.sha256.txt
```

The manifest is at `component/com_sitemoveinspector.xml`. The update feed is maintained separately under `updates/`, so development files and tests are never included in the installable ZIP. Package validation ties the feed version, supported Joomla branches, download URL, and SHA-256 digest to the release artifact.

## Release policy

Joomla tags use `joomla-vX.Y.Z`. A release ZIP must pass source and archive validation, unit tests, fresh install, scan/export, upgrade, and uninstall tests on both supported Joomla generations before it is submitted to the Joomla Extensions Directory.

See [PRODUCT-SPEC.md](docs/PRODUCT-SPEC.md) for the security, privacy, and lifecycle contract.
The prepared Joomla Extensions Directory copy and submission fields are in [JED-LISTING.md](docs/JED-LISTING.md).
