<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\SendLogRecord;
use yii\web\Response;

class WebhookController extends Controller
{
    protected array|int|bool $allowAnonymous = ['handle'];
    public $enableCsrfValidation = false;

    public function actionHandle(string $provider): Response
    {
        Edition::requiresPro('Webhook integrations');

        $request = Craft::$app->getRequest();
        $verifier = Plugin::getInstance()->webhookVerifier;

        if (!in_array($provider, ['ses', 'mailgun', 'postmark', 'sendgrid'], true)) {
            return $this->asJson(['error' => 'Unknown provider'])->setStatusCode(404);
        }

        if (!$verifier->verify($provider, $request)) {
            Craft::warning("Dispatch refused a {$provider} webhook: {$verifier->lastError}", 'dispatch');

            return $this->asJson(['error' => 'Unauthorized'])->setStatusCode(403);
        }

        // Parsed from the raw body: SNS posts JSON as text/plain, which Craft's body parser skips.
        $data = json_decode($request->getRawBody(), true);
        $data = is_array($data) ? $data : [];

        $result = match ($provider) {
            'ses' => $this->_handleSes($data),
            'mailgun' => $this->_handleMailgun($data),
            'postmark' => $this->_handlePostmark($data),
            'sendgrid' => $this->_handleSendgrid($data),
        };

        return $this->asJson($result);
    }

    /**
     * The send log row for a provider's message ID — exact match only, with and without the
     * angle brackets a Message-ID header carries. (A LIKE here let `%` match every row.)
     */
    private function _findLog(string $messageId): ?SendLogRecord
    {
        $id = trim($messageId, " <>");

        if ($id === '') {
            return null;
        }

        /** @var SendLogRecord|null $record */
        $record = SendLogRecord::find()->where(['messageId' => [$id, "<{$id}>"]])->one();

        return $record;
    }

    private function _handleSes(array $data): array
    {
        $processed = 0;

        // SNS won't deliver notifications until the subscription is confirmed. The message is
        // already verified as signed by Amazon for our topic; the URL is checked again anyway.
        if (($data['Type'] ?? '') === 'SubscriptionConfirmation') {
            $url = (string)($data['SubscribeURL'] ?? '');

            if (\justinholtweb\dispatch\services\WebhookVerifier::isAwsSnsUrl($url)) {
                try {
                    Craft::createGuzzleClient(['timeout' => 5, 'allow_redirects' => false])->get($url);

                    return ['processed' => 0, 'subscribed' => true];
                } catch (\Throwable $e) {
                    Craft::warning('Dispatch: SNS subscription confirmation failed: ' . $e->getMessage(), 'dispatch');
                }
            }

            return ['processed' => 0, 'subscribed' => false];
        }

        $message = $data['Message'] ?? null;

        if (is_string($message)) {
            $message = json_decode($message, true);
        }

        if (!$message) {
            return ['processed' => 0];
        }

        $type = $message['notificationType'] ?? '';
        $messageId = $message['mail']['messageId'] ?? '';

        if (!$messageId) {
            return ['processed' => 0];
        }

        $logRecord = $this->_findLog((string)$messageId);
        if (!$logRecord) {
            return ['processed' => 0];
        }

        if ($type === 'Bounce') {
            Plugin::getInstance()->tracker->recordBounce(
                $logRecord->campaignId,
                $logRecord->subscriberId,
                $message['bounce']['bouncedRecipients'][0]['diagnosticCode'] ?? 'Bounced'
            );
            $processed++;
        } elseif ($type === 'Complaint') {
            Plugin::getInstance()->tracker->recordComplaint(
                $logRecord->campaignId,
                $logRecord->subscriberId
            );
            $processed++;
        }

        return ['processed' => $processed];
    }

    private function _handleMailgun(array $data): array
    {
        $processed = 0;
        $event = $data['event-data'] ?? $data;
        $eventType = $event['event'] ?? '';
        $messageId = $event['message']['headers']['message-id'] ?? '';

        if (!$messageId) {
            return ['processed' => 0];
        }

        $logRecord = $this->_findLog((string)$messageId);
        if (!$logRecord) {
            return ['processed' => 0];
        }

        if (in_array($eventType, ['failed', 'bounced'], true)) {
            Plugin::getInstance()->tracker->recordBounce(
                $logRecord->campaignId,
                $logRecord->subscriberId,
                $event['delivery-status']['description'] ?? 'Bounced'
            );
            $processed++;
        } elseif ($eventType === 'complained') {
            Plugin::getInstance()->tracker->recordComplaint(
                $logRecord->campaignId,
                $logRecord->subscriberId
            );
            $processed++;
        }

        return ['processed' => $processed];
    }

    private function _handlePostmark(array $data): array
    {
        $processed = 0;
        $recordType = $data['RecordType'] ?? '';
        $messageId = $data['MessageID'] ?? '';

        if (!$messageId) {
            return ['processed' => 0];
        }

        $logRecord = $this->_findLog((string)$messageId);
        if (!$logRecord) {
            return ['processed' => 0];
        }

        if ($recordType === 'Bounce') {
            Plugin::getInstance()->tracker->recordBounce(
                $logRecord->campaignId,
                $logRecord->subscriberId,
                $data['Description'] ?? 'Bounced'
            );
            $processed++;
        } elseif ($recordType === 'SpamComplaint') {
            Plugin::getInstance()->tracker->recordComplaint(
                $logRecord->campaignId,
                $logRecord->subscriberId
            );
            $processed++;
        }

        return ['processed' => $processed];
    }

    private function _handleSendgrid(array $data): array
    {
        $processed = 0;

        $events = array_is_list($data) ? $data : [$data];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventType = $event['event'] ?? '';
            $messageId = $event['sg_message_id'] ?? '';

            // `smtp-id` is the Message-ID we sent with; sg_message_id is SendGrid's own ID, whose
            // first segment is what some integrations store.
            $logRecord = $this->_findLog((string)($event['smtp-id'] ?? ''))
                ?? ($messageId !== '' ? $this->_findLog(explode('.', (string)$messageId)[0]) : null);

            if (!$logRecord) {
                continue;
            }

            if (in_array($eventType, ['bounce', 'dropped'], true)) {
                Plugin::getInstance()->tracker->recordBounce(
                    $logRecord->campaignId,
                    $logRecord->subscriberId,
                    $event['reason'] ?? 'Bounced'
                );
                $processed++;
            } elseif ($eventType === 'spamreport') {
                Plugin::getInstance()->tracker->recordComplaint(
                    $logRecord->campaignId,
                    $logRecord->subscriberId
                );
                $processed++;
            }
        }

        return ['processed' => $processed];
    }
}
