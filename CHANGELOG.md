# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- **Sign-up forms with double opt-in.** `{{ craft.dispatch.signupForm('newsletter') }}` puts a sign-up form in any template, with options for list choice, name fields, a consent checkbox (enforced on the server), a source label and a redirect. A new address is emailed a confirmation link. Opening it shows a Confirm button, so a mail scanner opening the link confirms nobody, and pressing it makes the person a subscriber. Until then nothing is subscribed, sequenced or sent to a webhook. Links are single-use, stored only as a SHA-256 hash, and expire after 48 hours. Unconfirmed sign-ups are deleted. Hand-built forms post to `dispatch/signup/subscribe` with a signed configuration (`craft.dispatch.signupConfig()`), so a visitor can't add an address to a list the template didn't offer.
- Sign-ups get the same answer whether the address is new, subscribed, bounced or just signed up, so the form doesn't reveal who is on a list. Each address is rate-limited, with a site-wide ceiling. There's a honeypot. A confirmation goes to one address at most every 5 minutes and 5 times a day. Bounced and complained addresses are never mailed.
- **Consent on subscribers.** When, how (double or single opt-in), the form, the checkbox wording and its version, the page, the lists and a keyed hash of the IP are kept on the subscriber and shown on their edit page. With Toss managing cookie consent, the visitor's answer is recorded too. With Lock installed, confirmed sign-ups are written to its consent ledger, marked verified, under the new **Lock consent purpose** setting, and a full unsubscribe is written as a withdrawal.
- **Settings → Sign-up Forms:** double opt-in on or off, the confirmation email's subject and plain-text body, link lifetime, a site template for the confirmation page, sign-ups per minute, the Lock purpose, and a count of sign-ups waiting.
- `Signups::EVENT_BEFORE_SIGNUP`, which can drop a sign-up, and `Signups::EVENT_AFTER_CONFIRM`. `Plugin::getInstance()->signups->start()` / `confirm()` run the same flow from PHP.

### Fixed

- A full unsubscribe was reported to `EVENT_AFTER_UNSUBSCRIBE` listeners, and to the `subscriber.unsubscribed` webhook, as leaving whichever of the subscriber's lists came last. `mailingList` is now null, as documented.
- A subscriber's `metadata` was JSON-encoded twice on save and could not be loaded back into the element. It is stored once now, and metadata saved before this fix still loads.

## 5.2.0 - 2026-10-08

The four Pro features the editions table has listed all along — drip sequences, A/B subject tests, dynamic segments and webhook integrations — were not in the plugin. They are now.

### Added

- **Drip sequences (Pro).** Dispatch → Sequences: a trigger — a subscriber joins a list, or a custom event fires (`Plugin::getInstance()->sequences->fire('order.completed', $email)`, or the new REST `event` endpoint) — then a series of campaigns, each a set number of minutes, hours or days after the last. A subscriber is enrolled once, ever; unsubscribing, bouncing or complaining stops their sequences, leaving the trigger list stops that list's; a step is never sent twice and nobody gets a campaign they already received. Turning a sequence off pauses it. Plugins can name their trigger events (`Sequences::EVENT_REGISTER_TRIGGER_EVENTS`) and veto enrolments (`EVENT_BEFORE_ENROL`).
- **A/B subject line testing (Pro).** On a campaign: alternative subject lines, a test group size, a wait, and open or click rate. The test group is split across the subjects, the winner is picked after the wait without anyone coming back, and everyone else — including people who joined meanwhile — gets the winning subject. Per-subject results are on the campaign.
- **Dynamic segments (Pro).** Dispatch → Segments: saved rules built with Craft's condition builder — mailing list membership, signup date, email, status, opened or clicked a campaign (or any campaign), last activity, and any custom subscriber field. A campaign can be sent to a segment, within its list or on its own; the rules are evaluated when it sends. The same rules filter the Subscribers index.
- **Integrations — outgoing webhooks (Pro).** Dispatch → Integrations (admins): endpoints notified when subscribers join, leave, bounce or complain and when campaigns are sent, opened or clicked. Bodies are signed (`X-Dispatch-Signature`, HMAC-SHA256 with the endpoint's secret), delivered by queue jobs with retries and backoff, and logged per endpoint with Redeliver and Send test. Endpoint URLs must resolve to public addresses (loopback, private and cloud-metadata ranges are refused, the connection is pinned and redirects aren't followed); `allowPrivateIntegrationHosts` in `config/dispatch.php` lifts that for internal services.
- **Custom subscriber fields.** Settings → Subscriber Fields (admins): a field layout for subscribers, kept in project config. The fields appear on the subscriber edit page and in campaign Twig, and each becomes a segment rule. (The README promised field layout support; there was no way to set one up.)
- **A scheduler.** Scheduled campaigns, A/B winners and drip emails go out at their time from a self-arming queue job — no cron needed; `php craft dispatch/scheduler/run` is there as a cron backstop for sites whose queue only runs on web requests.
- Events: `Subscribers::EVENT_AFTER_SUBSCRIBE` / `EVENT_AFTER_UNSUBSCRIBE`, `Tracker::EVENT_ON_COMPLAINT`, `AbTesting::EVENT_AFTER_PICK_WINNER`, `Integrations::EVENT_BEFORE_DISPATCH`.
- Permissions: *Manage segments* and *Manage drip sequences*.
- The settings screens link to each other.

### Fixed

- **Scheduled campaigns never sent.** `Campaigns::schedule()` marked a campaign scheduled and nothing ever picked it up; the scheduler now starts it at its time.
- **A campaign could be sent twice.** Two clicks on Send (or a scheduled campaign started twice) each queued a full send; the campaign is now claimed before the job is queued. A send job retried after failing part way through no longer mails the first part of the list again — recipients already in the send log are skipped.
- **`Subscribers::getByEmail()` treated `*` as a wildcard**, so an API unsubscribe for `*` unsubscribed whoever matched first. Email lookups are exact.
- One subscriber whose email fails to render no longer stops the rest of the batch.
- A campaign's sent date is stored in UTC like every other date.
- Uninstalling removes the subscriber field layout from project config.

## 5.1.0 - 2026-09-29

### Security

- **Campaign content could read any secret on the site.** The body and subject were rendered as unrestricted Twig with the plugin settings in scope, so anyone who could edit a campaign could put `{{ craft.app.config.general.securityKey }}` in it and read it back through Preview. Campaign Twig now runs in Craft's sandbox: subscriber and campaign values, conditions, loops and filters work as before; everything else is refused, and Preview says what. Sites that need more can widen Craft's policy in `config/twig-sandbox.php`. Requires Craft 5.9.
- **Bounce and complaint webhooks were unauthenticated.** The signature check was skipped unless a secret was set — never, by default — and checked a header no provider sends; anyone could post events that marked subscribers bounced or complained and suppress the list. Each provider is now verified with its own scheme (Mailgun HMAC, Postmark basic auth, SendGrid signed events, SES SNS signatures from the configured topic) and refused when its credential is not configured. Message IDs match exactly (a `%` used to match every row).
- **Settings could be changed by non-admins, including on production.** Saving settings needed only *Manage settings*; it now needs an admin where admin changes are allowed, and only known settings are accepted.
- **Tracking tokens are bound to their purpose.** An open-pixel token was also a valid unsubscribe token for a different pair of IDs, so a recipient could unsubscribe other people. The preferences page no longer lets a token holder join lists they have no connection to, and no longer redirects to the `Referer`.
- **CSV import** accepts only CSV/text files up to 20 MB, stored under an unguessable name; the **subscriber export** neutralises cells that would run as spreadsheet formulas.

### Fixes

- **Unsubscribe, one-click unsubscribe, open tracking and every tracked link returned "400 Invalid token".** Their links carried a `token` parameter, which Craft 5.9+ reserves for its own preview tokens and rejects before any plugin code runs — so since Craft 5.9 recipients could not unsubscribe and every tracked link in a campaign was broken. Links now use `dtoken`, and links in mail already sent are rescued and keep working.
- **The unsubscribe and preferences pages could not render** (template not found in site mode), and **RFC 8058 one-click unsubscribe failed CSRF validation**. Both fixed.
- **Saving a draft campaign ran Duplicate.** The Send and Duplicate forms were nested inside the edit form; they are now form-submit buttons, and Send asks for confirmation.
- **Saving one settings page erased the other page's settings.**
- **SES bounce webhooks never started working:** Dispatch never confirmed the SNS subscription. It does now.
- **The REST API answered a wrong key with 404**; it now returns 401.
- Uninstalling removes Dispatch's campaigns, subscribers and lists from the elements table instead of leaving orphans.

### Changed

- **Transport settings removed.** The SES/Mailgun/Postmark/SendGrid keys on the Transport screen were never used — Dispatch always sent through Craft's mailer — but were stored in project config. Configure your provider in Settings → Email; a migration removes the stored keys. The screen is now **Delivery & Webhooks**.
- The REST API has its own **API key** setting (it used the webhook secret); the existing secret is carried over. Webhook credentials and the API key accept environment variables — use them, since plugin settings are stored in project config.
- New `preferencesUrl` variable in campaign templates.
- Requires Craft CMS 5.9 or later.

## 5.0.5 - 2026-08-26

### Fixes

- **The Subscribers and Campaigns indexes listed nothing at all.** Craft 5 expects `statuses()` to return `craft\enums\Color` cases; the string colours these elements returned made `Cp::componentStatusLabelHtml()` fail with "Attempt to read property `value` on string" as soon as the status column rendered. The Ajax call 500'd and the table was never replaced, so a list of fourteen subscribers displayed as an empty screen.

## 5.0.4 - 2026-08-19

### Fixes

- **Settings → Plugins → Dispatch rendered a broken, double-nested settings page.** The plugin's settings screen is a full control panel page — it extends a layout, sets `fullPageForm`, and posts to the plugin's own save action — so rendering it through Craft's `settingsHtml()` embedded a whole page inside Craft's own settings page, nesting a form inside a form and leaving two `action` inputs in the markup. `Plugin::getSettingsResponse()` now redirects to `dispatch/settings` instead.

## 5.0.3 - 2026-07-19

### Fixes

Bugs surfaced by adding PHPStan (level 5) and a Codeception unit suite, then fixed:

- **Email sending was broken on Craft 5.** `services/Sender.php` called `Message::getSwiftMessage()`, which no longer exists now that Craft uses Symfony Mailer — every send would throw a fatal `Call to an undefined method` error. Reworked to add the RFC 8058 `List-Unsubscribe` headers via `Message::addHeader()` and to read the sent message ID from the `Message-ID` header.
- **Custom control-panel index columns never rendered.** `Campaign` and `Subscriber` overrode `tableAttributeHtml()`, which Craft 5 renamed to `attributeHtml()`. The overrides were dead code and the `parent::` call would fatal. Renamed both to `attributeHtml()` so the status badge, mailing-list name, and full-name columns work again.
- **Campaign `beforeSend` / `afterSend` events never fired.** `SendCampaignJob` invoked `Campaign::trigger()` statically with the wrong arguments, the `EVENT_BEFORE_SEND` / `EVENT_AFTER_SEND` constants did not exist, and `CampaignEvent` had no `$isValid` property (so the cancel check silently errored). Added the event constants, switched to an instance `trigger()` call, and gave `CampaignEvent` an `$isValid` flag so handlers can cancel a send.
- **CSS inliner corrupted self-closing tags.** Styling a void element such as `<img … />` or `<br />` swallowed the trailing slash, producing malformed markup like `<img src="x" / style="…">`. Fixed the tag, class, and id selector patterns to preserve the self-closing marker.
- **`Lists::addSubscriber()` assigned a `DateTime` to the string `subscribedAt` column.** Now formatted with `Db::prepareDateForDb()`, consistent with the rest of the plugin.
- Type-safety cleanups: concrete return types on the element query classes, annotations on Active Record lookups, and removal of unused imports.

### Added

- Local DDEV environment plus PHPStan, ECS, and Codeception configuration for running the plugin's static analysis and tests. First unit tests cover `CssInliner`, `TrackingHelper`, and the status enums. Composer scripts: `composer phpstan`, `composer ecs`, `composer test`.

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
