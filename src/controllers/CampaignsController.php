<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\helpers\Html;
use craft\web\Controller;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CampaignsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('dispatch:accessPlugin');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('dispatch/campaigns/index', [
            'elementType' => Campaign::class,
        ]);
    }

    public function actionEdit(?int $campaignId = null, ?Campaign $campaign = null): Response
    {
        if ($campaign === null) {
            if ($campaignId !== null) {
                $campaign = Plugin::getInstance()->campaigns->getById($campaignId);
                if (!$campaign) {
                    throw new NotFoundHttpException('Campaign not found.');
                }
            } else {
                $campaign = new Campaign();
            }
        }

        $lists = MailingList::find()->all();
        $listOptions = [['label' => '— Select a list —', 'value' => '']];
        foreach ($lists as $list) {
            $listOptions[] = ['label' => $list->title, 'value' => $list->id];
        }

        $isNew = !$campaign->id;
        $plugin = Plugin::getInstance();
        $isPro = Edition::isPro();

        $segmentOptions = [['label' => Craft::t('dispatch', '— No segment —'), 'value' => '']];
        if ($isPro) {
            foreach ($plugin->segments->getAll() as $segment) {
                $segmentOptions[] = ['label' => $segment->name, 'value' => $segment->id];
            }
        }

        // Sequences that send this campaign as a step — Send would mail it to the list as well.
        $usedInSequences = $isNew ? [] : (new \craft\db\Query())
            ->select(['q.id', 'q.name'])
            ->distinct()
            ->from(['s' => '{{%dispatch_sequencesteps}}'])
            ->innerJoin(['q' => '{{%dispatch_sequences}}'], '[[q.id]] = [[s.sequenceId]]')
            ->where(['s.campaignId' => $campaign->id])
            ->all();

        return $this->renderTemplate('dispatch/campaigns/edit', [
            'campaign' => $campaign,
            'isNew' => $isNew,
            'isPro' => $isPro,
            'segmentOptions' => $segmentOptions,
            'abResults' => (!$isNew && $campaign->abStatus) ? $plugin->abTesting->results($campaign) : null,
            'usedInSequences' => $usedInSequences,
            'listOptions' => $listOptions,
            'title' => $isNew ? Craft::t('dispatch', 'New Campaign') : $campaign->title,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('dispatch:manageCampaigns');

        $request = Craft::$app->getRequest();
        $campaignId = $request->getBodyParam('campaignId');

        if ($campaignId) {
            $campaign = Plugin::getInstance()->campaigns->getById($campaignId);
            if (!$campaign) {
                throw new NotFoundHttpException('Campaign not found.');
            }
        } else {
            $campaign = new Campaign();
        }

        $campaign->title = $request->getBodyParam('title');
        $campaign->subject = $request->getBodyParam('subject');
        $campaign->fromName = $request->getBodyParam('fromName');
        $campaign->fromEmail = $request->getBodyParam('fromEmail');
        $campaign->replyToEmail = $request->getBodyParam('replyToEmail');
        $campaign->templatePath = $request->getBodyParam('templatePath');
        $campaign->body = $request->getBodyParam('body');
        $campaign->mailingListId = $request->getBodyParam('mailingListId') ?: null;

        // Pro fields: only read when posted on a Pro site, so a downgraded site keeps them.
        if (Edition::isPro()) {
            if (($segmentId = $request->getBodyParam('segmentId')) !== null) {
                $campaign->segmentId = (int)$segmentId ?: null;
            }

            // The test settings are fixed once the campaign has started sending.
            $editable = in_array($campaign->campaignStatus, ['draft', 'scheduled'], true);

            if ($editable && $request->getBodyParam('abEnabled') !== null) {
                $campaign->abEnabled = (bool)$request->getBodyParam('abEnabled');
                $rows = $request->getBodyParam('abSubjects');
                $campaign->setAbSubjects(array_map(
                    static fn($row) => is_array($row) ? (string)($row['subject'] ?? '') : (string)$row,
                    is_array($rows) ? array_values($rows) : [],
                ));
                $campaign->abTestPercent = (int)$request->getBodyParam('abTestPercent', 20);
                $campaign->abMetric = (string)$request->getBodyParam('abMetric', 'opens');
                $campaign->abWaitMinutes = (int)round((float)$request->getBodyParam('abWaitHours', 4) * 60);
            }
        }

        if (!Craft::$app->getElements()->saveElement($campaign)) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn\'t save campaign.'));
            Craft::$app->getUrlManager()->setRouteParams(['campaign' => $campaign]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Campaign saved.'));

        return $this->redirectToPostedUrl($campaign);
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('dispatch:sendCampaigns');

        $campaignId = Craft::$app->getRequest()->getRequiredBodyParam('campaignId');

        if (!Plugin::getInstance()->campaigns->send($campaignId)) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn\'t start campaign send.'));
            return $this->redirect("dispatch/campaigns/{$campaignId}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Campaign send started.'));

        return $this->redirect("dispatch/campaigns/{$campaignId}");
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('dispatch:manageCampaigns');

        $campaignId = Craft::$app->getRequest()->getRequiredBodyParam('campaignId');
        $duplicate = Plugin::getInstance()->campaigns->duplicate($campaignId);

        if (!$duplicate) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn\'t duplicate campaign.'));
            return $this->redirect("dispatch/campaigns/{$campaignId}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Campaign duplicated.'));

        return $this->redirect("dispatch/campaigns/{$duplicate->id}");
    }

    public function actionPreview(): Response
    {
        $campaignId = (int)Craft::$app->getRequest()->getRequiredQueryParam('campaignId');

        try {
            $html = Plugin::getInstance()->campaigns->preview($campaignId);
        } catch (\Twig\Sandbox\SecurityError $e) {
            // Tell the editor what the sandbox refused rather than showing a 500.
            $response = Craft::$app->getResponse();
            $response->setStatusCode(422);
            $response->format = Response::FORMAT_HTML;
            $response->content = '<!doctype html><meta charset="utf-8"><body style="font:15px/1.5 system-ui;padding:24px;max-width:640px">'
                . '<h1 style="font-size:18px">' . Html::encode(Craft::t('dispatch', 'This campaign uses Twig that campaigns are not allowed to use.')) . '</h1>'
                . '<p><code>' . Html::encode($e->getMessage()) . '</code></p>'
                . '<p>' . Html::encode(Craft::t('dispatch', 'Campaign content can use subscriber and campaign values, conditions, loops and filters. A developer can allow more in config/twig-sandbox.php.')) . '</p>';

            return $response;
        }

        if ($html === null) {
            throw new NotFoundHttpException('Campaign not found.');
        }

        $response = Craft::$app->getResponse();
        $response->content = $html;
        $response->format = Response::FORMAT_HTML;

        return $response;
    }

    public function actionDashboard(): Response
    {
        $this->requirePermission('dispatch:viewDashboard');

        $recentCampaigns = Campaign::find()
            ->campaignStatus('sent')
            ->orderBy('sentAt DESC')
            ->limit(10)
            ->all();

        $reports = [];
        foreach ($recentCampaigns as $campaign) {
            $reports[$campaign->id] = Plugin::getInstance()->campaigns->getReport($campaign->id);
        }

        return $this->renderTemplate('dispatch/dashboard/index', [
            'recentCampaigns' => $recentCampaigns,
            'reports' => $reports,
        ]);
    }

    public function actionSettings(): Response
    {
        $this->requirePermission('dispatch:manageSettings');

        return $this->renderTemplate('dispatch/settings/general', [
            'settings' => Plugin::getInstance()->getSettings(),
            'plugin' => Plugin::getInstance(),
        ]);
    }

    public function actionSettingsTransport(): Response
    {
        $this->requirePermission('dispatch:manageSettings');
        Edition::requiresLite('Custom transport configuration');

        return $this->renderTemplate('dispatch/settings/transport', [
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    /** Sign-up form settings: double opt-in, the confirmation email and page, consent. */
    public function actionSettingsSignups(): Response
    {
        $this->requirePermission('dispatch:manageSettings');

        return $this->renderTemplate('dispatch/settings/signups', [
            'settings' => Plugin::getInstance()->getSettings(),
            'pendingCount' => Plugin::getInstance()->signups->pendingCount(),
        ]);
    }

    /**
     * The subscriber field layout editor. Admins only, and only where admin changes are allowed:
     * the layout is project config.
     */
    public function actionSettingsSubscriberFields(?\craft\models\FieldLayout $fieldLayout = null): Response
    {
        $this->requireAdmin(true);

        return $this->renderTemplate('dispatch/settings/subscriber-fields', [
            'fieldLayout' => $fieldLayout ?? Craft::$app->getFields()->getLayoutByType(\justinholtweb\dispatch\elements\Subscriber::class),
        ]);
    }

    public function actionSaveSubscriberFields(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(true);

        $layout = Craft::$app->getFields()->assembleLayoutFromPost();
        $layout->type = \justinholtweb\dispatch\elements\Subscriber::class;
        $layout->reservedFieldHandles = ['email', 'firstName', 'lastName', 'fullName', 'status', 'userId', 'subscribedAt', 'unsubscribedAt', 'metadata', 'consentedAt', 'consent'];

        if (!$layout->validate()) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn’t save subscriber fields.'));
            Craft::$app->getUrlManager()->setRouteParams(['fieldLayout' => $layout]);

            return null;
        }

        $uid = $layout->uid ?: \craft\helpers\StringHelper::UUID();
        Craft::$app->getProjectConfig()->set(Plugin::PC_SUBSCRIBER_FIELD_LAYOUT, [$uid => $layout->getConfig()], 'Save the Dispatch subscriber field layout');

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Subscriber fields saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Saves plugin settings — project config, so admins only, and only where admin changes are
     * allowed (a `dispatch:manageSettings` user could otherwise rewrite webhook credentials and
     * the API key, on production too).
     *
     * Each settings page posts only its own fields, and savePluginSettings() writes exactly the
     * keys it is given — so the posted values are merged over the current settings, and only the
     * settings an admin may change are accepted.
     */
    public function actionSaveSettings(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(true);

        $posted = Craft::$app->getRequest()->getBodyParam('settings', []);
        $plugin = Plugin::getInstance();

        $settings = array_merge(
            $plugin->getSettings()->toArray(),
            array_intersect_key(is_array($posted) ? $posted : [], array_flip(\justinholtweb\dispatch\models\Settings::EDITABLE)),
        );

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn\'t save settings.'));
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
