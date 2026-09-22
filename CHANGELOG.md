# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Plugin skeleton: main plugin file with the WordPress and WooCommerce headers, Composer PSR-4 autoload with a plain fallback autoloader, and an idempotent boot that declares compatibility with WooCommerce's order tables and cart and checkout blocks.
- Test and coding-standards harness: PHPUnit 10 unit suite (`composer test`), a standalone runner for hosts without Composer (`composer test:standalone`), and PHPCS with WordPress-Extra and PHPCompatibilityWP (`composer lint`).
- Release packaging: `bin/build-zip.sh`, `.distignore` and `.gitattributes` export rules.
