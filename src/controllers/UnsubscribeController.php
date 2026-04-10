<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\helpers\TrackingHelper;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\SubscriptionRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class UnsubscribeController extends Controller
{
    protected array|int|bool $allowAnonymous = ['index', 'confirm', 'preferences', 'update-preferences'];

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $subscriberId = (int)$request->getQueryParam('sid');
        $listId = (int)$request->getQueryParam('lid');
        $token = $request->getQueryParam('token', '');

        if (!$subscriberId || !TrackingHelper::verifyToken($token, $subscriberId, $listId)) {
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
            ]);
        }

        return $this->renderTemplate('dispatch/_frontend/unsubscribe', [
            'subscriber' => $subscriber,
            'list' => $list,
            'confirmed' => false,
            'token' => $token,
        ]);
    }

    public function actionConfirm(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $subscriberId = (int)$request->getBodyParam('sid');
        $listId = (int)$request->getBodyParam('lid');
        $token = $request->getBodyParam('token', '');

        if (!$subscriberId || !TrackingHelper::verifyToken($token, $subscriberId, $listId)) {
            throw new NotFoundHttpException('Invalid unsubscribe request.');
        }

        Plugin::getInstance()->subscribers->unsubscribe($subscriberId, $listId ?: null);

        $subscriber = Subscriber::find()->id($subscriberId)->one();
        $list = $listId ? MailingList::find()->id($listId)->one() : null;

        return $this->renderTemplate('dispatch/_frontend/unsubscribe', [
            'subscriber' => $subscriber,
            'list' => $list,
            'confirmed' => true,
        ]);
    }

    public function actionPreferences(): Response
    {
        $request = Craft::$app->getRequest();
        $subscriberId = (int)$request->getQueryParam('sid');
        $token = $request->getQueryParam('token', '');

        if (!$subscriberId || !TrackingHelper::verifyToken($token, $subscriberId, 0)) {
            throw new NotFoundHttpException('Invalid preferences link.');
        }

        $subscriber = Subscriber::find()->id($subscriberId)->one();
        if (!$subscriber) {
            throw new NotFoundHttpException('Subscriber not found.');
        }

        $allLists = MailingList::find()->all();
        $subscribedListIds = array_map(
            fn($s) => $s->mailingListId,
            SubscriptionRecord::findAll(['subscriberId' => $subscriberId])
        );

        return $this->renderTemplate('dispatch/_frontend/preferences', [
            'subscriber' => $subscriber,
            'allLists' => $allLists,
            'subscribedListIds' => $subscribedListIds,
            'token' => $token,
        ]);
    }

    public function actionUpdatePreferences(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $subscriberId = (int)$request->getBodyParam('sid');
        $token = $request->getBodyParam('token', '');

        if (!$subscriberId || !TrackingHelper::verifyToken($token, $subscriberId, 0)) {
            throw new NotFoundHttpException('Invalid preferences request.');
        }

        $selectedListIds = $request->getBodyParam('listIds', []);

        // Get current subscriptions
        $currentSubscriptions = SubscriptionRecord::findAll(['subscriberId' => $subscriberId]);
        $currentListIds = array_map(fn($s) => $s->mailingListId, $currentSubscriptions);

        $selectedListIdsInt = array_map('intval', $selectedListIds);

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

        return $this->redirect(Craft::$app->getRequest()->getReferrer());
    }
}
