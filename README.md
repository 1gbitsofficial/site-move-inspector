# 1Gbits Site Move Inspector

A WordPress.org-ready, read-only migration preflight plugin. It inspects hosting-relevant metadata, compares optional destination requirements, and produces redacted TXT or JSON reports.

Maintained by [1Gbits](https://1gbits.com/).

## Live demo

[Try Site Move Inspector in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2F1gbitsofficial%2Fsite-move-inspector%2Fmain%2F.wordpress-org%2Fblueprints%2Fblueprint.json). Playground creates a disposable WordPress site in your browser, installs the exact production ZIP from the `v1.0.0` release, and opens the plugin's Tools screen.

## Product boundaries

- Runs only when an authorized administrator starts it.
- Does not migrate, back up, edit, or delete site data.
- Reads filesystem metadata but never file contents and never follows symbolic links.
- Sends no telemetry or site data to 1Gbits or another third party.
- Uses short-lived WordPress transients and user metadata for resumable scan state and the latest report.

The complete version-one acceptance criteria are in `docs/PRODUCT-SPEC.md`.

## Development

Requirements:

- PHP 7.4 or newer
- Composer 2

Install development dependencies:

```sh
composer install
```

Run unit tests:

```sh
composer test
```

Run WordPress Coding Standards and PHP compatibility checks:

```sh
composer lint
```

## Release

From PowerShell:

```powershell
.\scripts\build-release.ps1
```

The script validates version alignment and creates a deterministic, production-only ZIP under `dist/`. Development dependencies, tests, internal docs, and marketing assets are excluded.

Before submission, also run Plugin Check against an installed copy of the generated ZIP.

## License

GPL-2.0-or-later. See `LICENSE.txt`.
