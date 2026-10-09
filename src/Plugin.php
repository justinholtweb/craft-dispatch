<?php

namespace justinholtweb\dispatch;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\helpers\TrackingHelper;
use justinholtweb\dispatch\models\Settings;
use justinholtweb\dispatch\services\AbTesting;
use justinholtweb\dispatch\services\Campaigns;
use justinholtweb\dispatch\services\Integrations;
use justinholtweb\dispatch\services\Lists;
use justinholtweb\dispatch\services\Scheduler;
use justinholtweb\dispatch\services\Segments;
use justinholtweb\dispatch\services\Sender;
use justinholtweb\dispatch\services\Sequences;
use justinholtweb\dispatch\services\Subscribers;
use justinholtweb\dispatch\services\Tracker;
use justinholtweb\dispatch\services\Transports;
use yii\base\Event;

/**
 * Dispatch — Email Marketing for Craft CMS
 *
 * @property Subscribers $subscribers
 * @property Campaigns $campaigns
 * @property Lists $lists
 * @property Sender $sender
 * @property Tracker $tracker
 * @property Transports $transports
 * @property \justinholtweb\dispatch\services\WebhookVerifier $webhookVerifier
 * @property Scheduler $scheduler
 * @property Sequences $sequences
 * @property AbTesting $abTesting
 * @property Segments $segments
 * @property Integrations $integrations
 * @property \justinholtweb\dispatch\services\Signups $signups
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_FREE = 'free';
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.3.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'subscribers' => Subscribers::class,
                'campaigns' => Campaigns::class,
                'lists' => Lists::class,
                'sender' => Sender::class,
                'tracker' => Tracker::class,
                'transports' => Transports::class,
                'webhookVerifier' => \justinholtweb\dispatch\services\WebhookVerifier::class,
                'scheduler' => Scheduler::class,
                'sequences' => Sequences::class,
                'abTesting' => AbTesting::class,
                'segments' => Segments::class,
                'integrations' => Integrations::class,
                'signups' => \justinholtweb\dispatch\services\Signups::class,
            ],
        ];
    }

    public static function editions(): array
    {
        return [
            self::EDITION_FREE,
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public function init(): void
    {
        parent::init();

        // Before anything else: Craft rejects an unknown `?token=` with "400 Invalid token" as
        // soon as plugins have loaded, and every link Dispatch sent before 5.1 carries one.
        TrackingHelper::translateLegacyTokenParam();

        Craft::$app->onInit(function() {
            $this->_registerElementTypes();
            $this->_registerCpRoutes();
            $this->_registerSiteRoutes();
            $this->_registerPermissions();
            $this->_registerAutomation();
            $this->_registerVariable();
        });

        $this->_registerSubscriberFieldLayout();
    }

    /** Project config path of the subscriber field layout (one layout, `[uid => config]`). */
    public const PC_SUBSCRIBER_FIELD_LAYOUT = 'dispatch.subscriberFieldLayouts';

    /**
     * Wires the Pro automation to the events it reacts to: list joins start drip sequences,
     * unsubscribes/bounces/complaints stop them, and every one of these (plus campaign sends,
     * opens and clicks) is offered to the outgoing webhooks. Each handler checks the edition
     * itself, so a Lite site pays one cheap check.
     */
    private function _registerAutomation(): void
    {
        $integrations = fn() => $this->integrations;

        Event::on(Subscribers::class, Subscribers::EVENT_AFTER_SUBSCRIBE, function(\justinholtweb\dispatch\events\SubscriberEvent $event) use ($integrations) {
            if (!$event->subscriber || !$event->mailingList) {
                return;
            }

            $this->sequences->handleListJoin((int)$event->subscriber->id, (int)$event->mailingList->id);
            $integrations()->dispatch(models\Endpoint::EVENT_SUBSCRIBED, [
                'subscriber' => Integrations::subscriberData($event->subscriber),
                'list' => Integrations::listData($event->mailingList),
            ]);
        });

        Event::on(Subscribers::class, Subscribers::EVENT_AFTER_UNSUBSCRIBE, function(\justinholtweb\dispatch\events\SubscriberEvent $event) use ($integrations) {
            if (!$event->subscriber) {
                return;
            }

            // Leaving one list stops that list's sequences; the status change of a full
            // unsubscribe stops the rest (below).
            if ($event->mailingList) {
                $this->sequences->stopForSubscriber((int)$event->subscriber->id, 'left the list', (int)$event->mailingList->id);
            }

            $integrations()->dispatch(models\Endpoint::EVENT_UNSUBSCRIBED, [
                'subscriber' => Integrations::subscriberData($event->subscriber),
                'list' => Integrations::listData($event->mailingList),
            ]);
        });

        // Unsubscribed, bounced or complained — however it happened (CP, API, webhook, import).
        Event::on(Subscriber::class, \craft\base\Element::EVENT_AFTER_SAVE, function(\yii\base\Event $event) {
            /** @var Subscriber $subscriber */
            $subscriber = $event->sender;

            if ($subscriber->status !== 'active' && $subscriber->id) {
                $this->sequences->stopForSubscriber((int)$subscriber->id, (string)$subscriber->status);
            }
        });

        $tracking = function(string $name) use ($integrations) {
            return function(\justinholtweb\dispatch\events\TrackingEvent $event) use ($name, $integrations) {
                $data = [
                    'subscriber' => Integrations::subscriberData(Subscriber::find()->id($event->subscriberId)->status(null)->one()),
                    'campaign' => Integrations::campaignData(Campaign::find()->id($event->campaignId)->status(null)->one()),
                ];

                if ($event->url !== null) {
                    $data['url'] = $event->url;
                }

                $integrations()->dispatch($name, $data);
            };
        };

        Event::on(Tracker::class, Tracker::EVENT_ON_OPEN, $tracking(models\Endpoint::EVENT_OPENED));
        Event::on(Tracker::class, Tracker::EVENT_ON_CLICK, $tracking(models\Endpoint::EVENT_CLICKED));
        Event::on(Tracker::class, Tracker::EVENT_ON_BOUNCE, $tracking(models\Endpoint::EVENT_BOUNCED));
        Event::on(Tracker::class, Tracker::EVENT_ON_COMPLAINT, $tracking(models\Endpoint::EVENT_COMPLAINED));

        Event::on(Campaign::class, Campaign::EVENT_AFTER_SEND, function(\justinholtweb\dispatch\events\CampaignEvent $event) use ($integrations) {
            $integrations()->dispatch(models\Endpoint::EVENT_CAMPAIGN_SENT, [
                'campaign' => Integrations::campaignData($event->campaign),
            ]);
        });

        // A full unsubscribe of someone a sign-up form recorded consent for → a withdrawal in
        // Lock's ledger, when Lock is installed.
        Event::on(Subscribers::class, Subscribers::EVENT_AFTER_UNSUBSCRIBE, fn(\justinholtweb\dispatch\events\SubscriberEvent $event) => $this->signups->handleUnsubscribe($event));

        Event::on(\craft\services\Gc::class, \craft\services\Gc::EVENT_RUN, [$this, 'collectGarbage']);
    }

    /** `craft.dispatch` — the sign-up form helper. */
    private function _registerVariable(): void
    {
        Event::on(
            \craft\web\twig\variables\CraftVariable::class,
            \craft\web\twig\variables\CraftVariable::EVENT_INIT,
            function(\yii\base\Event $event) {
                /** @var \craft\web\twig\variables\CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('dispatch', \justinholtweb\dispatch\variables\DispatchVariable::class);
            }
        );
    }

    /**
     * Trims the outgoing-webhook delivery log and deletes expired, unconfirmed sign-ups. A named
     * method so tests can call it directly.
     */
    public function collectGarbage(): void
    {
        $this->integrations->pruneDeliveries($this->getSettings()->integrationLogDays);
        $this->signups->purgeExpired();
    }

    /**
     * The subscriber field layout lives in project config, like Craft's own user field layout,
     * so custom subscriber fields deploy with the rest of the schema.
     */
    private function _registerSubscriberFieldLayout(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $handler = [$this, 'handleChangedSubscriberFieldLayout'];

        $projectConfig
            ->onAdd(self::PC_SUBSCRIBER_FIELD_LAYOUT, $handler)
            ->onUpdate(self::PC_SUBSCRIBER_FIELD_LAYOUT, $handler)
            ->onRemove(self::PC_SUBSCRIBER_FIELD_LAYOUT, $handler);

        Event::on(\craft\services\ProjectConfig::class, \craft\services\ProjectConfig::EVENT_REBUILD, function(\craft\events\RebuildConfigEvent $event) {
            $layout = Craft::$app->getFields()->getLayoutByType(Subscriber::class);

            if ($layout->id && $layout->uid && ($config = $layout->getConfig())) {
                $event->config['dispatch']['subscriberFieldLayouts'] = [$layout->uid => $config];
            }
        });
    }

    public function handleChangedSubscriberFieldLayout(\craft\events\ConfigEvent $event): void
    {
        $data = $event->newValue;
        $fields = Craft::$app->getFields();

        if (empty($data) || !is_array($data) || empty($config = reset($data))) {
            $fields->deleteLayoutsByType(Subscriber::class);
            return;
        }

        \craft\helpers\ProjectConfig::ensureAllFieldsProcessed();

        $layout = \craft\models\FieldLayout::createFromConfig($config);
        $layout->id = $fields->getLayoutByType(Subscriber::class)->id;
        $layout->type = Subscriber::class;
        $layout->uid = (string)key($data);
        $fields->saveLayout($layout, false);

        Craft::$app->getElements()->invalidateCachesForElementType(Subscriber::class);
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = 'Dispatch';

        $nav['url'] = 'dispatch/campaigns';
        $nav['subnav'] = [];

        if ($this->is(self::EDITION_LITE) && Craft::$app->getUser()->checkPermission('dispatch:viewDashboard')) {
            $nav['subnav']['dashboard'] = [
                'label' => Craft::t('dispatch', 'Dashboard'),
                'url' => 'dispatch/dashboard',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('dispatch:manageCampaigns') ||
            Craft::$app->getUser()->checkPermission('dispatch:accessPlugin')) {
            $nav['subnav']['campaigns'] = [
                'label' => Craft::t('dispatch', 'Campaigns'),
                'url' => 'dispatch/campaigns',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('dispatch:manageSubscribers') ||
            Craft::$app->getUser()->checkPermission('dispatch:accessPlugin')) {
            $nav['subnav']['subscribers'] = [
                'label' => Craft::t('dispatch', 'Subscribers'),
                'url' => 'dispatch/subscribers',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('dispatch:manageLists') ||
            Craft::$app->getUser()->checkPermission('dispatch:accessPlugin')) {
            $nav['subnav']['lists'] = [
                'label' => Craft::t('dispatch', 'Lists'),
                'url' => 'dispatch/lists',
            ];
        }

        if ($this->is(self::EDITION_PRO)) {
            if (Craft::$app->getUser()->checkPermission('dispatch:manageSegments')) {
                $nav['subnav']['segments'] = [
                    'label' => Craft::t('dispatch', 'Segments'),
                    'url' => 'dispatch/segments',
                ];
            }

            if (Craft::$app->getUser()->checkPermission('dispatch:manageSequences')) {
                $nav['subnav']['sequences'] = [
                    'label' => Craft::t('dispatch', 'Sequences'),
                    'url' => 'dispatch/sequences',
                ];
            }

            if (Craft::$app->getUser()->getIsAdmin()) {
                $nav['subnav']['integrations'] = [
                    'label' => Craft::t('dispatch', 'Integrations'),
                    'url' => 'dispatch/integrations',
                ];
            }
        }

        if (Craft::$app->getUser()->getIsAdmin() ||
            Craft::$app->getUser()->checkPermission('dispatch:manageSettings')) {
            $nav['subnav']['settings'] = [
                'label' => Craft::t('dispatch', 'Settings'),
                'url' => 'dispatch/settings',
            ];
        }

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Sends Settings → Plugins to the plugin's own settings screen.
     *
     * That screen is a full control panel page — it `extends` a layout, sets `fullPageForm` and
     * posts to this plugin's own save action. Rendering it through `settingsHtml()` as well put a
     * whole page inside Craft's settings page, which nests a form inside a form and leaves two
     * `action` inputs in it. Same approach as the rest of the family.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('dispatch/settings'));
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('dispatch/settings/general', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Subscriber::class;
                $event->types[] = Campaign::class;
                $event->types[] = MailingList::class;
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Dashboard
                $event->rules['dispatch/dashboard'] = 'dispatch/campaigns/dashboard';

                // Campaigns
                $event->rules['dispatch/campaigns'] = 'dispatch/campaigns/index';
                $event->rules['dispatch/campaigns/new'] = 'dispatch/campaigns/edit';
                $event->rules['dispatch/campaigns/<campaignId:\d+>'] = 'dispatch/campaigns/edit';

                // Subscribers
                $event->rules['dispatch/subscribers'] = 'dispatch/subscribers/index';
                $event->rules['dispatch/subscribers/new'] = 'dispatch/subscribers/edit';
                $event->rules['dispatch/subscribers/<subscriberId:\d+>'] = 'dispatch/subscribers/edit';
                $event->rules['dispatch/subscribers/import'] = 'dispatch/subscribers/import';

                // Lists
                $event->rules['dispatch/lists'] = 'dispatch/lists/index';
                $event->rules['dispatch/lists/new'] = 'dispatch/lists/edit';
                $event->rules['dispatch/lists/<listId:\d+>'] = 'dispatch/lists/edit';

                // Segments, sequences, integrations [Pro]
                $event->rules['dispatch/segments'] = 'dispatch/segments/index';
                $event->rules['dispatch/segments/new'] = 'dispatch/segments/edit';
                $event->rules['dispatch/segments/<segmentId:\d+>'] = 'dispatch/segments/edit';
                $event->rules['dispatch/sequences'] = 'dispatch/sequences/index';
                $event->rules['dispatch/sequences/new'] = 'dispatch/sequences/edit';
                $event->rules['dispatch/sequences/<sequenceId:\d+>'] = 'dispatch/sequences/edit';
                $event->rules['dispatch/integrations'] = 'dispatch/integrations/index';
                $event->rules['dispatch/integrations/new'] = 'dispatch/integrations/edit';
                $event->rules['dispatch/integrations/<endpointId:\d+>'] = 'dispatch/integrations/edit';

                // Settings
                $event->rules['dispatch/settings/subscriber-fields'] = 'dispatch/campaigns/settings-subscriber-fields';
                $event->rules['dispatch/settings'] = 'dispatch/campaigns/settings';
                $event->rules['dispatch/settings/transport'] = 'dispatch/campaigns/settings-transport';
                $event->rules['dispatch/settings/signups'] = 'dispatch/campaigns/settings-signups';
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Tracking
                $event->rules['dispatch/track/open'] = 'dispatch/tracking/open';
                $event->rules['dispatch/track/click'] = 'dispatch/tracking/click';

                // Unsubscribe
                $event->rules['dispatch/unsubscribe'] = 'dispatch/unsubscribe/index';
                $event->rules['dispatch/preferences'] = 'dispatch/unsubscribe/preferences';

                // Sign-up forms: the double opt-in confirmation page
                $event->rules['dispatch/confirm'] = 'dispatch/signup/confirm';

                // Webhooks [Pro]
                $event->rules['dispatch/webhook/<provider:\w+>'] = 'dispatch/webhook/handle';

                // API [Pro]
                $event->rules['dispatch/api/v1/<action:[\w-]+>'] = 'dispatch/api/<action>';
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('dispatch', 'Dispatch'),
                    'permissions' => [
                        'dispatch:accessPlugin' => [
                            'label' => Craft::t('dispatch', 'Access Dispatch'),
                        ],
                        'dispatch:manageCampaigns' => [
                            'label' => Craft::t('dispatch', 'Manage campaigns'),
                            'nested' => [
                                'dispatch:sendCampaigns' => [
                                    'label' => Craft::t('dispatch', 'Send campaigns'),
                                ],
                            ],
                        ],
                        'dispatch:manageSubscribers' => [
                            'label' => Craft::t('dispatch', 'Manage subscribers'),
                            'nested' => [
                                'dispatch:importSubscribers' => [
                                    'label' => Craft::t('dispatch', 'Import subscribers'),
                                ],
                            ],
                        ],
                        'dispatch:manageLists' => [
                            'label' => Craft::t('dispatch', 'Manage mailing lists'),
                        ],
                        'dispatch:viewDashboard' => [
                            'label' => Craft::t('dispatch', 'View dashboard'),
                        ],
                        'dispatch:manageSettings' => [
                            'label' => Craft::t('dispatch', 'Manage settings'),
                        ],
                        'dispatch:manageSegments' => [
                            'label' => Craft::t('dispatch', 'Manage segments (Pro)'),
                        ],
                        'dispatch:manageSequences' => [
                            'label' => Craft::t('dispatch', 'Manage drip sequences (Pro)'),
                            'info' => Craft::t('dispatch', 'Sequences send campaigns to subscribers automatically.'),
                        ],
                    ],
                ];
            }
        );
    }
}
