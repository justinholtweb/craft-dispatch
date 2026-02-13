# Changelog

All notable changes to this project will be documented in this file.

## 1.0.0 - 2026-02-12
### Added
- Subscriber element type with custom field layout support
- Campaign element type with Twig email templates
- Mailing list element type
- Queue-based campaign sending with batch processing
- CSV subscriber import/export
- Unsubscribe handling with RFC 8058 one-click support
- Basic send tracking (sent/failed) for all editions
- Open/click analytics (Lite, Pro)
- Delivery monitoring dashboard (Lite, Pro)
- Custom transport configuration for SES, Mailgun, Postmark, SendGrid (Lite, Pro)
- Craft User sync as subscribers (Lite, Pro)
- Webhook integrations for bounce/complaint processing (Pro)
- REST API for subscriber and campaign management (Pro)
- Full user permissions system
- Email template rendering pipeline with CSS inlining
- HMAC-signed tracking URLs to prevent spoofing
