# Changelog

All notable changes to this project will be documented in this file.

## 5.0.2 - 2026-04-30

### Security

- Fixed an open redirect in the click-tracking endpoint (`dispatch/track/click`). Previously the controller would redirect to any URL passed in the `url` query parameter regardless of token validity, and the HMAC token only covered `(campaignId, subscriberId)` — letting an attacker craft tracking links pointing anywhere. The token now binds the destination URL too, and invalid/missing tokens redirect to the site home.

### Added

- `TrackingHelper::generateClickToken()` / `verifyClickToken()` — URL-bound HMAC helpers used by the click rewriter and tracker.

### Breaking

- Click links in already-sent campaigns use the old `(cid, sid)`-only token and will fail verification under the new check. Recipients clicking those links will land on the site home rather than the original destination, and clicks will not be recorded. Re-send campaigns whose tracked links must keep working.

## 5.0.1 - 2026-02-20

### Fixes
Critical bug fixed:
  - services/Sender.php:172 was calling craft\helpers\UrlHelper::siteUrl(...) with a bare namespace — inside the plugin namespace PHP would have resolved this to a nonexistent  justinholtweb\dispatch\services\craft\helpers\UrlHelper and crashed. Added the use craft\helpers\UrlHelper; import.

Also fixed a secondary crash-waiting-to-happen: SendCampaignJob.php referenced Campaign::EVENT_BEFORE_SEND ?? 'beforeSend' — but those constants don't exist, and ?? doesn't catch undefined class constants. Replaced with string literals.

Craft coding-standards violations fixed:

1. Private methods prefixed with _ (Craft requires it) — 15 methods across 9 files: Plugin, Install migration, TrackingHelper, CssInliner, Sender, SubscribersController, WebhookController, ApiController, SendCampaignJob.
3. PHP 8.4 implicit-nullable deprecation — string $x = null → ?string $x = null in defineSources/defineActions across Subscriber, Campaign, MailingList (6 signatures).
4. Inline FQCNs replaced with imports — \craft\elements\User in 3 element files, \RuntimeException in 2 jobs, \yii\db\Query in Tracker, \yii\base\InvalidConfigException in Edition, \justinholtweb\dispatch\records\SubscriptionRecord in SubscribersController, and \justinholtweb\dispatch\elements\Subscriber in Tracker and ImportSubscribersJob.
5. in_array() strict flag added — 4 call sites in SubscribersController, UnsubscribeController, WebhookController.
6. Query select/groupBy → array form — Tracker::getStats() and Campaigns::getReport() now use ['col'] instead of 'col'; also fixed the COUNT(*) as clicks expression to Yii's ['alias' => 'expr'] form.


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
