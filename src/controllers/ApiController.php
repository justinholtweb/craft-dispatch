<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;
use yii\web\Response;

class ApiController extends Controller
{
    protected array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        Edition::requiresPro('REST API');

        // Verify API authentication via Bearer token
        $authHeader = Craft::$app->getRequest()->getHeaders()->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            Craft::$app->getResponse()->setStatusCode(401);
            return false;
        }

        $token = substr($authHeader, 7);
        $settings = Plugin::getInstance()->getSettings();

        if (empty($settings->webhookSecret) || !hash_equals($settings->webhookSecret, $token)) {
            Craft::$app->getResponse()->setStatusCode(401);
            return false;
        }

        return true;
    }

    public function actionSubscribers(): Response
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsGet()) {
            $query = Subscriber::find();

            if ($listId = $request->getQueryParam('listId')) {
                $query->mailingListId((int)$listId);
            }
            if ($status = $request->getQueryParam('status')) {
                $query->subscriberStatus($status);
            }

            $limit = min((int)($request->getQueryParam('limit', 100)), 500);
            $offset = (int)$request->getQueryParam('offset', 0);

            $subscribers = $query->limit($limit)->offset($offset)->all();
            $total = (int)$query->count();

            return $this->asJson([
                'data' => array_map(fn($s) => $this->_serializeSubscriber($s), $subscribers),
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        }

        if ($request->getIsPost()) {
            $subscriber = new Subscriber();
            $subscriber->email = $request->getBodyParam('email');
            $subscriber->firstName = $request->getBodyParam('firstName');
            $subscriber->lastName = $request->getBodyParam('lastName');
            $subscriber->status = $request->getBodyParam('status', 'active');

            if (!Craft::$app->getElements()->saveElement($subscriber)) {
                return $this->asJson(['errors' => $subscriber->getErrors()])->setStatusCode(422);
            }

            // Subscribe to lists
            $listIds = $request->getBodyParam('listIds', []);
            foreach ($listIds as $listId) {
                Plugin::getInstance()->subscribers->subscribe($subscriber->id, (int)$listId);
            }

            return $this->asJson(['data' => $this->_serializeSubscriber($subscriber)])->setStatusCode(201);
        }

        return $this->asJson(['error' => 'Method not allowed'])->setStatusCode(405);
    }

    public function actionLists(): Response
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsGet()) {
            $lists = MailingList::find()->all();

            return $this->asJson([
                'data' => array_map(fn($l) => $this->_serializeList($l), $lists),
            ]);
        }

        return $this->asJson(['error' => 'Method not allowed'])->setStatusCode(405);
    }

    public function actionCampaigns(): Response
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsGet()) {
            $query = Campaign::find();

            if ($status = $request->getQueryParam('status')) {
                $query->campaignStatus($status);
            }

            $limit = min((int)($request->getQueryParam('limit', 100)), 500);
            $offset = (int)$request->getQueryParam('offset', 0);

            $campaigns = $query->limit($limit)->offset($offset)->all();
            $total = (int)$query->count();

            return $this->asJson([
                'data' => array_map(fn($c) => $this->_serializeCampaign($c), $campaigns),
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        }

        return $this->asJson(['error' => 'Method not allowed'])->setStatusCode(405);
    }

    public function actionStats(): Response
    {
        $campaignId = (int)Craft::$app->getRequest()->getQueryParam('campaignId');
        if (!$campaignId) {
            return $this->asJson(['error' => 'campaignId is required'])->setStatusCode(400);
        }

        $report = Plugin::getInstance()->campaigns->getReport($campaignId);

        return $this->asJson(['data' => [
            'campaignId' => $report->campaignId,
            'totalRecipients' => $report->totalRecipients,
            'totalSent' => $report->totalSent,
            'totalFailed' => $report->totalFailed,
            'totalOpens' => $report->totalOpens,
            'uniqueOpens' => $report->uniqueOpens,
            'totalClicks' => $report->totalClicks,
            'uniqueClicks' => $report->uniqueClicks,
            'totalBounces' => $report->totalBounces,
            'openRate' => $report->getOpenRate(),
            'clickRate' => $report->getClickRate(),
            'bounceRate' => $report->getBounceRate(),
            'deliveryRate' => $report->getDeliveryRate(),
        ]]);
    }

    public function actionSubscribe(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $email = $request->getRequiredBodyParam('email');
        $listHandle = $request->getBodyParam('list');
        $listId = $request->getBodyParam('listId');

        if (!$listHandle && !$listId) {
            return $this->asJson(['error' => 'list or listId is required'])->setStatusCode(400);
        }

        $list = $listId
            ? Plugin::getInstance()->lists->getById((int)$listId)
            : Plugin::getInstance()->lists->getByHandle($listHandle);

        if (!$list) {
            return $this->asJson(['error' => 'Mailing list not found'])->setStatusCode(404);
        }

        $subscriber = Plugin::getInstance()->subscribers->getByEmail($email);

        if (!$subscriber) {
            $subscriber = new Subscriber();
            $subscriber->email = $email;
            $subscriber->firstName = $request->getBodyParam('firstName');
            $subscriber->lastName = $request->getBodyParam('lastName');
            $subscriber->status = 'active';

            if (!Craft::$app->getElements()->saveElement($subscriber)) {
                return $this->asJson(['errors' => $subscriber->getErrors()])->setStatusCode(422);
            }
        }

        Plugin::getInstance()->subscribers->subscribe($subscriber->id, $list->id);

        return $this->asJson(['data' => $this->_serializeSubscriber($subscriber)])->setStatusCode(200);
    }

    public function actionUnsubscribe(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $email = $request->getRequiredBodyParam('email');
        $listId = $request->getBodyParam('listId');

        $subscriber = Plugin::getInstance()->subscribers->getByEmail($email);

        if (!$subscriber) {
            return $this->asJson(['error' => 'Subscriber not found'])->setStatusCode(404);
        }

        Plugin::getInstance()->subscribers->unsubscribe($subscriber->id, $listId ? (int)$listId : null);

        return $this->asJson(['success' => true]);
    }

    private function _serializeSubscriber(Subscriber $subscriber): array
    {
        return [
            'id' => $subscriber->id,
            'email' => $subscriber->email,
            'firstName' => $subscriber->firstName,
            'lastName' => $subscriber->lastName,
            'status' => $subscriber->status,
            'subscribedAt' => $subscriber->subscribedAt,
            'dateCreated' => $subscriber->dateCreated?->format('c'),
        ];
    }

    private function _serializeList(MailingList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'handle' => $list->handle,
            'description' => $list->description,
            'subscriberCount' => $list->subscriberCount,
            'dateCreated' => $list->dateCreated?->format('c'),
        ];
    }

    private function _serializeCampaign(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'subject' => $campaign->subject,
            'status' => $campaign->campaignStatus,
            'mailingListId' => $campaign->mailingListId,
            'totalRecipients' => $campaign->totalRecipients,
            'totalSent' => $campaign->totalSent,
            'totalFailed' => $campaign->totalFailed,
            'sentAt' => $campaign->sentAt,
            'dateCreated' => $campaign->dateCreated?->format('c'),
        ];
    }
}
