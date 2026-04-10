<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\events\TrackingEvent;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\records\SendLogRecord;
use justinholtweb\dispatch\records\TrackingRecord;
use yii\db\Query;

class Tracker extends Component
{
    public const EVENT_ON_OPEN = 'onOpen';
    public const EVENT_ON_CLICK = 'onClick';
    public const EVENT_ON_BOUNCE = 'onBounce';

    public function recordOpen(int $campaignId, int $subscriberId, ?string $ipAddress = null, ?string $userAgent = null): bool
    {
        Edition::requiresLite('Open tracking');

        $record = new TrackingRecord();
        $record->campaignId = $campaignId;
        $record->subscriberId = $subscriberId;
        $record->type = 'open';
        $record->ipAddress = $ipAddress;
        $record->userAgent = $userAgent;
        $record->trackedAt = Db::prepareDateForDb(new \DateTime());

        $result = $record->save(false);

        if ($result) {
            $event = new TrackingEvent([
                'campaignId' => $campaignId,
                'subscriberId' => $subscriberId,
                'type' => 'open',
            ]);
            $this->trigger(self::EVENT_ON_OPEN, $event);
        }

        return $result;
    }

    public function recordClick(int $campaignId, int $subscriberId, string $url, ?string $ipAddress = null, ?string $userAgent = null): bool
    {
        Edition::requiresLite('Click tracking');

        $record = new TrackingRecord();
        $record->campaignId = $campaignId;
        $record->subscriberId = $subscriberId;
        $record->type = 'click';
        $record->url = $url;
        $record->ipAddress = $ipAddress;
        $record->userAgent = $userAgent;
        $record->trackedAt = Db::prepareDateForDb(new \DateTime());

        $result = $record->save(false);

        if ($result) {
            $event = new TrackingEvent([
                'campaignId' => $campaignId,
                'subscriberId' => $subscriberId,
                'type' => 'click',
                'url' => $url,
            ]);
            $this->trigger(self::EVENT_ON_CLICK, $event);
        }

        return $result;
    }

    public function recordBounce(int $campaignId, int $subscriberId, string $errorMessage = ''): bool
    {
        // Update send log
        $logRecord = SendLogRecord::find()
            ->where(['campaignId' => $campaignId, 'subscriberId' => $subscriberId])
            ->one();

        if ($logRecord) {
            $logRecord->status = 'bounced';
            $logRecord->errorMessage = $errorMessage;
            $logRecord->save(false);
        }

        // Update subscriber status
        $subscriber = Subscriber::find()->id($subscriberId)->one();
        if ($subscriber) {
            $subscriber->status = 'bounced';
            Craft::$app->getElements()->saveElement($subscriber);
        }

        $event = new TrackingEvent([
            'campaignId' => $campaignId,
            'subscriberId' => $subscriberId,
            'type' => 'bounce',
        ]);
        $this->trigger(self::EVENT_ON_BOUNCE, $event);

        return true;
    }

    public function recordComplaint(int $campaignId, int $subscriberId): bool
    {
        // Update subscriber status
        $subscriber = Subscriber::find()->id($subscriberId)->one();
        if ($subscriber) {
            $subscriber->status = 'complained';
            Craft::$app->getElements()->saveElement($subscriber);
        }

        return true;
    }

    public function getStats(int $campaignId): array
    {
        return [
            'totalOpens' => (int)TrackingRecord::find()
                ->where(['campaignId' => $campaignId, 'type' => 'open'])
                ->count(),
            'uniqueOpens' => (int)(new Query())
                ->from('{{%dispatch_tracking}}')
                ->where(['campaignId' => $campaignId, 'type' => 'open'])
                ->select(['subscriberId'])
                ->distinct()
                ->count(),
            'totalClicks' => (int)TrackingRecord::find()
                ->where(['campaignId' => $campaignId, 'type' => 'click'])
                ->count(),
            'uniqueClicks' => (int)(new Query())
                ->from('{{%dispatch_tracking}}')
                ->where(['campaignId' => $campaignId, 'type' => 'click'])
                ->select(['subscriberId'])
                ->distinct()
                ->count(),
            'topLinks' => (new Query())
                ->from('{{%dispatch_tracking}}')
                ->where(['campaignId' => $campaignId, 'type' => 'click'])
                ->select(['url', 'clicks' => 'COUNT(*)'])
                ->groupBy(['url'])
                ->orderBy(['clicks' => SORT_DESC])
                ->limit(10)
                ->all(),
        ];
    }
}
