<?php

namespace justinholtweb\dispatch;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\models\Settings;
use justinholtweb\dispatch\services\Campaigns;
use justinholtweb\dispatch\services\Lists;
use justinholtweb\dispatch\services\Sender;
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
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_FREE = 'free';
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.0.0';
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

        Craft::$app->onInit(function () {
            $this->_registerElementTypes();
            $this->_registerCpRoutes();
            $this->_registerSiteRoutes();
            $this->_registerPermissions();
        });
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
            function (RegisterComponentTypesEvent $event) {
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
            function (RegisterUrlRulesEvent $event) {
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

                // Settings
                $event->rules['dispatch/settings'] = 'dispatch/campaigns/settings';
                $event->rules['dispatch/settings/transport'] = 'dispatch/campaigns/settings-transport';
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                // Tracking
                $event->rules['dispatch/track/open'] = 'dispatch/tracking/open';
                $event->rules['dispatch/track/click'] = 'dispatch/tracking/click';

                // Unsubscribe
                $event->rules['dispatch/unsubscribe'] = 'dispatch/unsubscribe/index';
                $event->rules['dispatch/preferences'] = 'dispatch/unsubscribe/preferences';

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
            function (RegisterUserPermissionsEvent $event) {
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
                    ],
                ];
            }
        );
    }
}
