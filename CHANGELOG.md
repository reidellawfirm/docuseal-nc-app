# Changelog

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
