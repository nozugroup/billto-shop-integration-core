# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres
to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-16

First stable release, and the baseline every later version builds on.

### Added

- The shop-agnostic core shared by the BillTo e-commerce plugins: order mapping, buyer scenarios
  (domestic, EU consumer with OSS, EU company, outside the EU), VAT resolution, gross and net amount
  modes, correction (refund) mapping and settings validation.
- OAuth 2.0 client for shop installations: registration with a one-time code issued in BillTo,
  Authorization Code with PKCE, and a clean disconnect.
- `UserAgent` builder producing the identification header the BillTo API expects from integrations.
- Written against PHP 7.2 so the plugins can run on the shop versions their merchants actually have,
  with no runtime dependencies beyond the host platform.
