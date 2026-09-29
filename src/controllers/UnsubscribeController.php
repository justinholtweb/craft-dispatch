<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\helpers\TrackingHelper;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\SubscriptionRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The public unsubscribe and preferences pages.
 *
 * Their templates live in the plugin, so they render in CP template mode — in site mode (the
 * default on a front-end request) `dispatch/_frontend/…` does not resolve and the page 500s.
 */
class UnsubscribeController extends Controller
{
    protected array|int|bool $allowAnonymous = ['index', 'confirm', 'preferences', 'update-preferences'];

    /**
     * RFC 8058 one-click unsubscribe is a POST from the mailbox provider (Gmail, Yahoo…) to the
     * List-Unsubscribe URL, and it carries no CSRF token. The signed link is the authentication,
     * so CSRF is off for that one action only.
     */
    public function beforeAction($action): bool
    {
        if ($action->id === 'index') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $subscriberId = (int)$request->getQueryParam('sid');
        $listId = (int)$request->getQueryParam('lid');
        $token = TrackingHelper::requestToken();

        if (!$subscriberId || !TrackingHelper::verify($token, TrackingHelper::PURPOSE_UNSUBSCRIBE, $subscriberId, $listId)) {
            throw new NotFoundHttpException('Invalid unsubscribe link.');
        }

        $subscriber = Subscriber::find()->id($subscriberId)->one();
        if (!$subscriber) {
            throw new NotFoundHttpException('Subscriber not found.');
        }

        $list = $listId ? MailingList::find()->id($listId)->one() : null;

        // Handle one-click unsubscribe (RFC 8058 POST)
        if ($request->getIsPost()) {
            Plugin::getInstance()->subscribers->unsubscribe($subscriberId, $listId ?: null);

            return $this->renderTemplate('dispatch/_frontend/unsubscribe', [
                'subscriber' => $subscriber,
                'list' => $list,
                'confirmed' => true,
            ], View::TEMPLATE_MODE_CP);
        }

        return $this->renderTemplate('dispatch/_frontend/unsubscribe', [
            'subscriber' => $subscriber,
            'list' => $list,
            'confirmed' => false,
            'token' => $token,
        ], View::TEMPLATE_MODE_CP);
    }

    public function actionConfirm(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $subscriberId = (int)$request->getBodyParam('sid');
        $listId = (int)$request->getBodyParam('lid');
        $token = TrackingHelper::requestToken();

        if (!$subscriberId || !TrackingHelper::verify($token, TrackingHelper::PURPOSE_UNSUBSCRIBE, $subscriberId, $listId)) {
            throw new NotFoundHttpException('Invalid unsubscribe request.');
        }

        Plugin::getInstance()->subscribers->unsubscribe($subscriberId, $listId ?: null);

        $subscriber = Subscriber::find()->id($subscriberId)->one();
        $list = $listId ? MailingList::find()->id($listId)->one() : null;

        return $this->renderTemplate('dispatch/_frontend/unsubscribe', [
            'subscriber' => $subscriber,
            'list' => $list,
            'confirmed' => true,
        ], View::TEMPLATE_MODE_CP);
    }

    public function actionPreferences(): Response
    {
        $request = Craft::$app->getRequest();
        $subscriberId = (int)$request->getQueryParam('sid');
        $token = TrackingHelper::requestToken();

        if (!$subscriberId || !TrackingHelper::verify($token, TrackingHelper::PURPOSE_PREFERENCES, $subscriberId)) {
            throw new NotFoundHttpException('Invalid preferences link.');
        }

        $subscriber = Subscriber::find()->id($subscriberId)->one();
        if (!$subscriber) {
            throw new NotFoundHttpException('Subscriber not found.');
        }

        $listIds = $this->_manageableListIds($subscriberId);
        $subscribedListIds = array_map(
            fn($s) => $s->mailingListId,
            SubscriptionRecord::findAll(['subscriberId' => $subscriberId])
        );

        return $this->renderTemplate('dispatch/_frontend/preferences', [
            'subscriber' => $subscriber,
            'allLists' => $listIds ? MailingList::find()->id($listIds)->all() : [],
            'subscribedListIds' => $subscribedListIds,
            'token' => $token,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * The lists a subscriber may manage from the preferences page: the ones they are on, and the
     * ones they have been sent a campaign from (so a list they untick can be ticked again). A
     * signed preferences link must not become a way onto lists they never had anything to do with.
     *
     * @return int[]
     */
    private function _manageableListIds(int $subscriberId): array
    {
        $current = array_map(
            fn($s) => (int)$s->mailingListId,
            SubscriptionRecord::findAll(['subscriberId' => $subscriberId])
        );

        $mailed = (new \craft\db\Query())
            ->select(['c.mailingListId'])
            ->distinct()
            ->from(['l' => '{{%dispatch_sendlog}}'])
            ->innerJoin(['c' => '{{%dispatch_campaigns}}'], '[[c.id]] = [[l.campaignId]]')
            ->where(['l.subscriberId' => $subscriberId])
            ->andWhere(['not', ['c.mailingListId' => null]])
            ->column();

        return array_values(array_unique(array_merge($current, array_map('intval', $mailed))));
    }

    public function actionUpdatePreferences(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $subscriberId = (int)$request->getBodyParam('sid');
        $token = TrackingHelper::requestToken();

        if (!$subscriberId || !TrackingHelper::verify($token, TrackingHelper::PURPOSE_PREFERENCES, $subscriberId)) {
            throw new NotFoundHttpException('Invalid preferences request.');
        }

        $selectedListIds = $request->getBodyParam('listIds', []);
        $manageable = $this->_manageableListIds($subscriberId);

        // Get current subscriptions
        $currentSubscriptions = SubscriptionRecord::findAll(['subscriberId' => $subscriberId]);
        $currentListIds = array_map(fn($s) => $s->mailingListId, $currentSubscriptions);

        // Only lists this subscriber already belongs to (see _manageableListIds()).
        $selectedListIdsInt = array_values(array_intersect(array_map('intval', (array)$selectedListIds), $manageable));

        // Subscribe to new lists
        foreach ($selectedListIdsInt as $listId) {
            if (!in_array($listId, $currentListIds, true)) {
                Plugin::getInstance()->subscribers->subscribe($subscriberId, $listId);
            }
        }

        // Unsubscribe from removed lists
        foreach ($currentListIds as $listId) {
            if (!in_array($listId, $selectedListIdsInt, true)) {
                Plugin::getInstance()->lists->removeSubscriber($listId, $subscriberId);
            }
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Your preferences have been updated.'));

        // Back to this subscriber's own preferences page — never to the Referer, which is
        // whatever the browser (or an attacker's page) says it is.
        return $this->redirect(TrackingHelper::preferencesUrl($subscriberId));
    }
}
