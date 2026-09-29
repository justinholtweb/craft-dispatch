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

        return $this->renderTemplate('dispatch/campaigns/edit', [
            'campaign' => $campaign,
            'isNew' => $isNew,
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
