# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added an RMS-managed advance-order storefront with catalog publication, preparation lead times, closed dates, delivery-app links, popularity signals, customer checkout, confirmations, and conversion reporting (see spec 0011).
- Added an independently enabled checkout add-on step for Daily Dish, membership, covered membership booking, and advance-menu purchases, with one selected storefront category and per-service-date selections (see spec 0012).

### Changed

- Extended SkipCash checkout accounting so eligible add-ons share the dated order, invoice, payment, and allocation while remaining outside membership meal credits and promotion discounts.

### Fixed

- Kept printable blank Order Sheet pages compatible with Livewire's Linux-side component root detection.
- Made customer item price-history lookup portable across the supported MySQL and MariaDB databases.

### Security

- Updated Livewire and the Tiptap browser dependency set to releases containing their current security fixes.
