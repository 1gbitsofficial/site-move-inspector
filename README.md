# 1Gbits Site Move Inspector

A cross-platform, read-only migration preflight toolkit maintained by [1Gbits](https://1gbits.com/). Each application inspects hosting-relevant metadata, compares optional destination requirements, and produces privacy-safe reports without migrating or modifying site data.

## Applications

- [WordPress](apps/wordpress/README.md) — production-ready plugin, currently awaiting WordPress.org review.
- [Joomla](apps/joomla/README.md) — installable administrator component for Joomla 5.4 and 6.1, in release-candidate testing.

## Live WordPress demo

[Try Site Move Inspector in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2F1gbitsofficial%2Fsite-move-inspector%2Fmain%2Fapps%2Fwordpress%2F.wordpress-org%2Fblueprints%2Fblueprint.json). Playground creates a disposable WordPress site in your browser and installs the exact production ZIP from the `v1.0.0` release.

## Repository layout

```text
apps/
  wordpress/   WordPress plugin, tests, and WordPress.org assets
  joomla/      Joomla administrator component
packages/
  core/        CMS-neutral inspection and reporting code
scripts/       Platform-specific release builders
```

The published artifacts remain independent. A WordPress release contains only the installable WordPress plugin, while Joomla releases use their own package and version series.

## Development

Install the shared development tools from the repository root:

```sh
composer install
```

Run the platform checks:

```sh
composer test
composer lint
composer test:joomla
composer lint:joomla
```

Build installable artifacts with `composer build:wordpress` or `composer build:joomla`.

## Versioning

- WordPress keeps the existing `vX.Y.Z` sequence.
- Joomla uses `joomla-vX.Y.Z`.
- A future independently published core package will use `core-vX.Y.Z`.

The existing `v1.0.0` tag remains the immutable first WordPress release.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
