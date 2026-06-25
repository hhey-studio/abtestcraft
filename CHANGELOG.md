# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-06-25

### Fixed
- `TestsService` now accepts `int|string` IDs/site IDs and casts to `int`,
  fixing the `TypeError` thrown when saving tests under `strict_types`
  (previously applied downstream via composer-patches; refs upstream issue #1 / PR #3).

## [1.0.0] - 2025-01-10

### Added
- Initial release for Craft CMS 5
- Server-side A/B testing with entry-based variants
- Multi-goal conversion tracking:
  - Phone click tracking (`tel:` links)
  - Email click tracking (`mailto:` links)
  - Form submission tracking (with Freeform/Formie integration)
  - File download tracking (PDF, DOC, XLS, ZIP, etc.)
  - Page visit tracking (exact, starts with, contains matching)
  - Custom event tracking via JavaScript API
- Chi-squared statistical significance testing
- Configurable confidence threshold (80-99%)
- Minimum detectable effect (MDE) calculator
- Traffic split configuration (0-100%)
- GA4/GTM dataLayer integration
- Automatic SEO protection for variant entries (noindex, canonical)
- Cascade support for nested entry structures
- Soft delete support with audit trail
- Email notifications when tests reach significance
- Rate limiting for conversion tracking
- User permissions: Manage Tests, View Results
- Twig variables for template integration
- Console command for cascade rebuilding
- Control panel interface for test management and results
