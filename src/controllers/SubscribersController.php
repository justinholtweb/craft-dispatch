<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\queue\jobs\ImportSubscribersJob;
use justinholtweb\dispatch\records\SubscriptionRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class SubscribersController extends Controller
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
        return $this->renderTemplate('dispatch/subscribers/index', [
            'elementType' => Subscriber::class,
        ]);
    }

    public function actionEdit(?int $subscriberId = null, ?Subscriber $subscriber = null): Response
    {
        if ($subscriber === null) {
            if ($subscriberId !== null) {
                $subscriber = Plugin::getInstance()->subscribers->getById($subscriberId);
                if (!$subscriber) {
                    throw new NotFoundHttpException('Subscriber not found.');
                }
            } else {
                $subscriber = new Subscriber();
            }
        }

        $lists = MailingList::find()->all();
        $isNew = !$subscriber->id;

        // Get current list subscriptions
        $subscribedListIds = [];
        if (!$isNew) {
            $subscriptions = SubscriptionRecord::findAll(['subscriberId' => $subscriber->id]);
            $subscribedListIds = array_map(fn($s) => $s->mailingListId, $subscriptions);
        }

        return $this->renderTemplate('dispatch/subscribers/edit', [
            'subscriber' => $subscriber,
            'isNew' => $isNew,
            'lists' => $lists,
            'subscribedListIds' => $subscribedListIds,
            'title' => $isNew ? Craft::t('dispatch', 'New Subscriber') : $subscriber->email,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('dispatch:manageSubscribers');

        $request = Craft::$app->getRequest();
        $subscriberId = $request->getBodyParam('subscriberId');

        if ($subscriberId) {
            $subscriber = Plugin::getInstance()->subscribers->getById($subscriberId);
            if (!$subscriber) {
                throw new NotFoundHttpException('Subscriber not found.');
            }
        } else {
            $subscriber = new Subscriber();
        }

        $subscriber->email = $request->getBodyParam('email');
        $subscriber->firstName = $request->getBodyParam('firstName');
        $subscriber->lastName = $request->getBodyParam('lastName');
        $subscriber->status = $request->getBodyParam('status', 'active');

        if (!Craft::$app->getElements()->saveElement($subscriber)) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn\'t save subscriber.'));
            Craft::$app->getUrlManager()->setRouteParams(['subscriber' => $subscriber]);
            return null;
        }

        // Handle list subscriptions
        $listIds = $request->getBodyParam('listIds', []);
        $this->_syncSubscriptions($subscriber->id, $listIds);

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Subscriber saved.'));

        return $this->redirectToPostedUrl($subscriber);
    }

    public function actionImport(): Response
    {
        $this->requirePermission('dispatch:importSubscribers');

        $lists = MailingList::find()->all();
        $listOptions = [];
        foreach ($lists as $list) {
            $listOptions[] = ['label' => $list->title, 'value' => $list->id];
        }

        return $this->renderTemplate('dispatch/subscribers/import', [
            'listOptions' => $listOptions,
        ]);
    }

    public function actionUploadImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('dispatch:importSubscribers');

        $mailingListId = Craft::$app->getRequest()->getRequiredBodyParam('mailingListId');
        $file = UploadedFile::getInstanceByName('csvFile');

        if (!$file) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Please upload a CSV file.'));
            return $this->redirect('dispatch/subscribers/import');
        }

        // Save to temp
        $tempPath = Craft::$app->getPath()->getTempPath() . '/dispatch-import-' . uniqid() . '.csv';
        $file->saveAs($tempPath);

        // Push to queue
        Craft::$app->getQueue()->push(new ImportSubscribersJob([
            'filePath' => $tempPath,
            'mailingListId' => (int)$mailingListId,
        ]));

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Import job queued. Subscribers will be imported in the background.'));

        return $this->redirect('dispatch/subscribers');
    }

    public function actionExport(): Response
    {
        $this->requirePermission('dispatch:manageSubscribers');

        $mailingListId = Craft::$app->getRequest()->getQueryParam('listId');
        $csv = Plugin::getInstance()->subscribers->exportCsv($mailingListId ? (int)$mailingListId : null);

        $response = Craft::$app->getResponse();
        $response->content = $csv;
        $response->setDownloadHeaders('subscribers-' . date('Y-m-d') . '.csv', 'text/csv');

        return $response;
    }

    private function _syncSubscriptions(int $subscriberId, array $listIds): void
    {
        // Get current subscriptions
        $currentSubscriptions = SubscriptionRecord::findAll(['subscriberId' => $subscriberId]);
        $currentListIds = array_map(fn($s) => $s->mailingListId, $currentSubscriptions);

        // Add new subscriptions
        foreach ($listIds as $listId) {
            if (!in_array((int)$listId, $currentListIds, true)) {
                Plugin::getInstance()->subscribers->subscribe($subscriberId, (int)$listId);
            }
        }

        // Remove old subscriptions
        $postedListIds = array_map('intval', $listIds);
        foreach ($currentListIds as $listId) {
            if (!in_array($listId, $postedListIds, true)) {
                Plugin::getInstance()->lists->removeSubscriber($listId, $subscriberId);
            }
        }
    }
}
