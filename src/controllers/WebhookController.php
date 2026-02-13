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
        $settings = Plugin::getInstance()->getSettings();

        // Verify webhook secret
        if ($settings->webhookSecret) {
            $signature = $request->getHeaders()->get('X-Dispatch-Signature', '');
            $payload = $request->getRawBody();
            $expected = hash_hmac('sha256', $payload, $settings->webhookSecret);

            if (!hash_equals($expected, $signature)) {
                Craft::warning("Invalid webhook signature from provider: {$provider}", 'dispatch');
                return $this->asJson(['error' => 'Invalid signature'])->setStatusCode(403);
            }
        }

        $data = $request->getBodyParams();

        $result = match ($provider) {
            'ses' => $this->handleSes($data),
            'mailgun' => $this->handleMailgun($data),
            'postmark' => $this->handlePostmark($data),
            'sendgrid' => $this->handleSendgrid($data),
            default => ['processed' => 0, 'error' => "Unknown provider: {$provider}"],
        };

        return $this->asJson($result);
    }

    private function handleSes(array $data): array
    {
        $processed = 0;
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

        $logRecord = SendLogRecord::find()->where(['messageId' => $messageId])->one();
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

    private function handleMailgun(array $data): array
    {
        $processed = 0;
        $event = $data['event-data'] ?? $data;
        $eventType = $event['event'] ?? '';
        $messageId = $event['message']['headers']['message-id'] ?? '';

        if (!$messageId) {
            return ['processed' => 0];
        }

        $logRecord = SendLogRecord::find()->where(['messageId' => $messageId])->one();
        if (!$logRecord) {
            return ['processed' => 0];
        }

        if (in_array($eventType, ['failed', 'bounced'])) {
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

    private function handlePostmark(array $data): array
    {
        $processed = 0;
        $recordType = $data['RecordType'] ?? '';
        $messageId = $data['MessageID'] ?? '';

        if (!$messageId) {
            return ['processed' => 0];
        }

        $logRecord = SendLogRecord::find()->where(['messageId' => $messageId])->one();
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

    private function handleSendgrid(array $data): array
    {
        $processed = 0;

        $events = is_array($data) && isset($data[0]) ? $data : [$data];

        foreach ($events as $event) {
            $eventType = $event['event'] ?? '';
            $messageId = $event['sg_message_id'] ?? '';

            if (!$messageId) {
                continue;
            }

            // SendGrid message IDs have a filter suffix
            $messageId = explode('.', $messageId)[0];

            $logRecord = SendLogRecord::find()->where(['like', 'messageId', $messageId])->one();
            if (!$logRecord) {
                continue;
            }

            if (in_array($eventType, ['bounce', 'dropped'])) {
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
