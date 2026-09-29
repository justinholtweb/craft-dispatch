# Dispatch — AI Agent Guide

## What This Is

Dispatch is a Craft CMS 5 email marketing plugin. It manages subscribers, mailing lists, email campaigns, and delivery tracking — all natively within the Craft control panel. It ships as a single Composer package with three editions: Free, Lite ($49), and Pro ($79).

## Project Structure

```
src/
├── Plugin.php                 # Entry point. Registers elements, routes, permissions, services.
├── icon.svg / icon-mask.svg   # CP icons (mask = fill-only for nav)
├── controllers/               # HTTP controllers (CP + public-facing)
├── elements/                  # Craft element types: Subscriber, Campaign, MailingList
│   └── db/                    # Element query classes
├── enums/                     # CampaignStatus, SubscriberStatus (PHP 8.1 enums)
├── events/                    # Event classes fired during send, subscribe, track
├── helpers/                   # CssInliner, TrackingHelper (HMAC tokens, pixel injection)
├── migrations/                # Install.php creates all 6 tables
├── models/                    # Settings, Edition (feature gating), SendReport
├── queue/jobs/                # SendCampaignJob, ImportSubscribersJob, SyncUsersJob
├── records/                   # ActiveRecord classes for each table
├── services/                  # Core business logic (Subscribers, Campaigns, Lists, Sender, Tracker, Transports)
├── templates/                 # Twig templates for CP pages and email layouts
│   ├── _frontend/             # Public pages: unsubscribe, preferences
│   ├── _layouts/              # Base CP layout
│   ├── campaigns/             # Campaign index + editor
│   ├── subscribers/           # Subscriber index + editor + import
│   ├── lists/                 # Mailing list index + editor
│   ├── dashboard/             # Analytics dashboard (Lite+)
│   ├── settings/              # General + transport settings
│   └── email/                 # Default email layout + template
└── web/assets/dist/           # Compiled CP CSS/JS
```

## Key Architectural Decisions

### Namespace
`justinholtweb\dispatch` — PSR-4 mapped to `src/`.

### Elements
Subscriber, Campaign, and MailingList are **Craft element types**. They have element queries, custom statuses, table attributes, sort options, and index sources. Subscriber and Campaign support custom field layouts (`hasContent(): true`).

### Editions
Defined in `Plugin.php` as constants (`EDITION_FREE`, `EDITION_LITE`, `EDITION_PRO`). Use Craft's native `$this->is()` method with comparison operators, **not** custom comparison logic. The `Edition` model wraps this for convenience (`Edition::isLite()`, `Edition::requiresPro()`).

Feature gating pattern:
```php
// In services/controllers — check before executing gated logic
Edition::requiresLite('Open tracking');

// In Plugin.php nav — conditional UI
if ($this->is(self::EDITION_LITE)) { ... }

// In templates
{% if plugin('dispatch').is('pro') %}
```

### Email Pipeline
1. `Campaigns::send()` pushes a `SendCampaignJob` to the queue
2. Job fetches subscribers in batches (configurable, default 50)
3. Per subscriber: sandboxed Twig render (`Sender::renderEditorTwig()`) → CSS inline → inject tracking pixel → rewrite links → send via mailer → log to `dispatch_sendlog`
4. Campaign status updates as it progresses: draft → sending → sent/failed

### Tracking
- Open tracking: 1×1 transparent GIF served by `TrackingController::actionOpen()`
- Click tracking: redirect through `TrackingController::actionClick()` with 302
- All tracking URLs are HMAC-signed using Craft's `securityKey`, **bound to a purpose** (`open`, `click`, `unsub`, `prefs`) and sent as **`dtoken`** — see `TrackingHelper`
- Webhook controller handles SES/Mailgun/Postmark/SendGrid bounce/complaint callbacks, each verified by `WebhookVerifier` with the provider's own scheme (fails closed when unconfigured)

### Unsubscribe
RFC 8058 compliant: `List-Unsubscribe` + `List-Unsubscribe-Post` headers on every email. One-click POST unsubscribe supported. Public preference center at `/dispatch/preferences`.

## Database Tables

| Table | Type | Notes |
|---|---|---|
| `dispatch_subscribers` | Element | PK references `elements.id` |
| `dispatch_campaigns` | Element | PK references `elements.id` |
| `dispatch_mailinglists` | Element | PK references `elements.id` |
| `dispatch_subscriptions` | Pivot | subscriber↔list, unique composite |
| `dispatch_sendlog` | Log | Per-email delivery record |
| `dispatch_tracking` | Log | Opens/clicks (Lite+) |

All tables created in `migrations/Install.php`. Element tables have cascading deletes from `elements.id`.

## Edition Feature Boundaries

| Feature | Free | Lite | Pro |
|---|---|---|---|
| Mailing lists | 1 | Unlimited | Unlimited |
| Monthly sends | 100 | Unlimited | Unlimited |
| User sync | No | Yes | Yes |
| Open/click tracking | No | Yes | Yes |
| Dashboard | No | Yes | Yes |
| Custom transports | No | Yes | Yes |
| Webhooks | No | No | Yes |
| REST API | No | No | Yes |

## Common Patterns

### Adding a new service method
1. Add method to the service class in `src/services/`
2. Service is registered as a component in `Plugin::config()`
3. Access via `Plugin::getInstance()->serviceName->method()`

### Adding a new CP route
1. Add rule in `Plugin::registerCpRoutes()`
2. Create controller action
3. Create template in `src/templates/`

### Adding a new site-facing route
1. Add rule in `Plugin::registerSiteRoutes()`
2. Set `$allowAnonymous` in the controller for public access
3. Disable CSRF if needed (webhooks, tracking pixels)

### Adding a new table
1. Add to `Install::createTables()`, `createIndexes()`, `addForeignKeys()`
2. Create a Record class in `src/records/`
3. Bump `schemaVersion` in `Plugin.php`
4. Create a numbered migration in `src/migrations/`

## Testing

Integration checks run inside the shared plugin-testing harness:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-dispatch/tests/integration/checks.php  # services
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-dispatch/tests/integration/http.php    # links, webhooks, CP (needs Pro)
```

**Never run the Codeception suite (`tests/unit`, `codecept`) in the harness container.** With no `tests/.env` its database setup falls back to the harness DB and drops every table (it wiped the harness on 2026-09-28).

## Code Style

- PHP 8.2+ features: enums, readonly where appropriate, union types, named arguments
- Follow Craft CMS conventions: element types extend `craft\base\Element`, services extend `craft\base\Component`, records extend `craft\db\ActiveRecord`
- Use `Craft::t('dispatch', '...')` for all user-facing strings
- Use `Craft::$app->getQueue()->push()` for background work, never process inline
- Use `Db::prepareDateForDb()` for all datetime values going to the database

## Things to Watch Out For

- **Never rename Plugin.php or change its class/namespace** after publishing. Craft stores the FQCN in config.
- **Edition changes must not lose data.** A site can downgrade at any time via project config. Gated features should degrade gracefully, not delete anything.
- **Element queries use `joinElementTable()`** — the table name passed must match the unprefixed table name without `{{%}}` wrapping.
- **Never put a `token` query parameter on a URL.** It is Craft's preview-token parameter; since Craft 5.9 an unrecognised one is rejected with "400 Invalid token" before any controller runs. That silently broke every unsubscribe, one-click, pixel and tracked link until 5.1. Use `dtoken` (`TrackingHelper::PARAM`). `TrackingHelper::translateLegacyTokenParam()` rescues links already sent.
- **Tracking tokens are purpose-bound HMACs** — verify with `TrackingHelper::verify($token, PURPOSE_…, …ids)`. Legacy (pre-5.1) tokens are accepted for open/click/unsubscribe only, never preferences.
- **Campaign body and subject are editor-written Twig** — render them only through `Sender::renderEditorTwig()` (Craft's sandbox). Never add `settings` or other secrets to that context. Element fields a campaign may read are allowlisted per element (`SANDBOX_PROPERTIES`).
- **Front-end controller templates render in CP mode** (`View::TEMPLATE_MODE_CP`) — plugin templates don't resolve in site mode.
- **The campaign edit page is one form** — no nested `<form>`s; secondary actions are `formsubmit` buttons.
- **Settings saves merge over current settings and whitelist `Settings::EDITABLE`** — `savePluginSettings()` writes only the keys it is given. Secrets are env-able (`Settings::resolved()`).
- **The Sender service references SwiftMailer** (`getSwiftMessage()`) — this may need updating if Craft migrates to Symfony Mailer. Check for deprecation warnings.
