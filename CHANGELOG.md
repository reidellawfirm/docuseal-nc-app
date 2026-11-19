# Changelog

## 1.1.17

### Fixed
- Declare `deliveryToken` and `deliveryClaimedAt` on `SignatureRequest` so
  migrated database rows hydrate correctly. This fixes `deliveryToken is not
  a valid attribute`, request-list failures, and embedded-signing lookup
  errors after upgrading to 1.1.16.
- Add an Entity-compatible hydration regression check for the delivery-claim
  fields introduced by the 1.1.16 migration.

## 1.1.16

### Added
- Automatically save each completed signed PDF in the original document's
  current Nextcloud folder, named using the original basename and DocuSeal
  submission ID (for example, `contract_signed_submission_123.pdf`).
- Add an atomic per-request database delivery claim and a small migration for
  its lease, preventing duplicate delivery from overlapping workers.
- Add a 30-assertion regression harness for same-folder delivery, idempotency,
  competing workers, retries, URL/PDF validation, and crash recovery.

### Fixed
- Resolve the original mount before creating the signed file instead of
  silently saving to the user's root or an arbitrary shared mount.
- Reconcile an exact PDF left behind by an interrupted earlier delivery,
  without overwriting a file that contains different content.
- Retry delivery for completed submissions whose signed copy is missing,
  including stale terminal local states, with a safe retry lease.
- Send completion notifications only for the worker that actually records the
  signed document in Nextcloud.
- Restrict credentialed signed-document downloads to the configured DocuSeal
  origin, validate the PDF response, and disable credential redirects.

## 1.1.15

### Fixed
- Fixed embedded signing showing `sign.reidellawfirm.com refused to connect`
  by replacing the raw signing-page iframe with DocuSeal's supported
  `<docuseal-form>` component. The raw `/s/{slug}` page sends
  `X-Frame-Options: SAMEORIGIN`, which blocks cross-origin framing even
  when Nextcloud's own Content Security Policy allows the DocuSeal host.
- Pass the configured self-hosted DocuSeal host to the signing component so
  its supported `/embed/forms` API loads signing data directly with CORS,
  without weakening DocuSeal's response security headers.
- Surface signing-component errors in the Nextcloud modal instead of leaving
  the failed iframe as the only indication of failure.

## 1.1.14

### Fixed
- The embedded DocuSeal field builder now follows the Nextcloud user's
  language instead of always displaying Italian. Regional variants fall back
  to their supported base language, with English as the safe default.
- Missing or unsupported frontend translations now use the English catalog
  rather than exposing Italian source strings.
- Completed the English, Italian, German, French, and Spanish catalogs for
  every translatable application string. Previously, German, French, and
  Spanish each covered only 61 of 130 source strings, causing missing labels
  to fall back to Italian.
- Added a missing translation for the signing-dialog loading error and a
  complete catalog audit that checks generated locale bundles and the
  DocuSeal language-selection mapping.
- Activity messages now use the recipient language supplied by Nextcloud
  instead of the request context's language; decline reasons are translated
  as part of the complete notification message.

## 1.1.13

### Fixed
- Fixed DocuSeal's embedded field builder rejecting every token with
  `'' doesn't exist. Please use your account user email address for user_email.`
  The app now exposes and saves the required DocuSeal API-key owner email in
  Settings > Connected accounts > DocuSeal.
- Validate the DocuSeal account email before generating a builder token and
  display actionable configuration errors in the signing dialog.
- Omit the optional `integration_email` JWT claim when the Nextcloud account
  has no email address instead of sending an empty value or a localhost alias.

## 1.1.12

### Fixed
- Fixed FileAction silent failure: the DocuSealModal dynamic import failed
  with a ChunkLoadError because the webpack `publicPath` was hardcoded to
  `/apps/<app_id>/js/` with Windows backslashes. When the app is installed in
  `custom_apps/`, every on-demand chunk (modal + vendor) 404'd and the click
  appeared to do nothing. Set `publicPath: 'auto'` so webpack derives the
  base from the script's own URL (the crmhub_nc_app precedent).
- Added a `.catch()` on the lazy `DocuSealModal` import so any future chunk
  load failure surfaces a visible error toast instead of a dead click.

## 1.1.11
- Support limited to Nextcloud 34 and 35 (the two most recent stable major versions).
- Declared compatibility narrowed accordingly.

## [1.0.6] - 2026-03-24

### Changed
- Replaced all icons with new DocuSeal branding (monitor + pen design)

### Fixed
- Fixed FileAction API for @nextcloud/files v4 (was not a constructor)
- Fixed null slot props crash in MultiselectWho component
- Fixed NcCheckboxRadioSwitch binding (uses modelValue, not checked)
- Removed taggable from NcSelect to fix null crash on non-email input
- Fixed DocuSeal API 422 error: always include body in message param

## [1.0.0] - 2026-03-23

### Added
- Direct signing of PDF, DOCX and image files
- Template-based signing with DocuSeal template preview
- Embedded signing (iframe) and email-based signing
- Signature status tracking in file sidebar with progress bar
- Automatic download of signed documents
- Real-time webhooks with HMAC-SHA256 validation
- Background polling job every 15 minutes
- Nextcloud notifications (signed, declined, completed, expired)
- Unified Search for signature requests
- Dashboard Widget
- Activity app integration
- Automatic CSP policy for iframes
- Resend reminders to signers
- Cancel pending requests
- Configurable expiry on requests
- Audit trail with timeline and PDF download
- Translations: Italian, English, German, French, Spanish
- API key encryption with ICrypto
- PHPUnit tests
- Support for Nextcloud 28-34, PHP 8.1+, Vue 3
