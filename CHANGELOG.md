# Changelog

All notable changes to this project will be documented in this file.

## 5.0.2 - 2026-04-30

### Security

- Fixed an open redirect in the click-tracking endpoint (`dispatch/track/click`). Previously the controller would redirect to any URL passed in the `url` query parameter regardless of token validity, and the HMAC token only covered `(campaignId, subscriberId)` — letting an attacker craft tracking links pointing anywhere. The token now binds the destination URL too, and invalid/missing tokens redirect to the site home.

### Added

- `TrackingHelper::generateClickToken()` / `verifyClickToken()` — URL-bound HMAC helpers used by the click rewriter and tracker.

### Breaking

- Click links in already-sent campaigns use the old `(cid, sid)`-only token and will fail verification under the new check. Recipients clicking those links will land on the site home rather than the original destination, and clicks will not be recorded. Re-send campaigns whose tracked links must keep working.

## 5.0.0 - 2026-02-12

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
