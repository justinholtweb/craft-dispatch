<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\enums\CampaignStatus;
use justinholtweb\dispatch\models\SendReport;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\queue\jobs\SendCampaignJob;
use justinholtweb\dispatch\records\SendLogRecord;
use justinholtweb\dispatch\records\TrackingRecord;

class Campaigns extends Component
{
    public function getById(int $id): ?Campaign
    {
        return Campaign::find()->id($id)->one();
    }

    public function create(Campaign $campaign): bool
    {
        if (!$campaign->campaignStatus) {
            $campaign->campaignStatus = CampaignStatus::Draft->value;
        }

        return Craft::$app->getElements()->saveElement($campaign);
    }

    public function update(Campaign $campaign): bool
    {
        return Craft::$app->getElements()->saveElement($campaign);
    }

    public function delete(Campaign $campaign): bool
    {
        return Craft::$app->getElements()->deleteElement($campaign);
    }

    public function send(int $campaignId): bool
    {
        $campaign = $this->getById($campaignId);
        if (!$campaign) {
            return false;
        }

        if ($campaign->campaignStatus !== CampaignStatus::Draft->value &&
            $campaign->campaignStatus !== CampaignStatus::Scheduled->value) {
            return false;
        }

        if (!$campaign->mailingListId) {
            return false;
        }

        // Count recipients
        $recipientCount = (int)Subscriber::find()
            ->mailingListId($campaign->mailingListId)
            ->subscriberStatus('active')
            ->count();

        $campaign->campaignStatus = CampaignStatus::Sending->value;
        $campaign->totalRecipients = $recipientCount;
        $campaign->totalSent = 0;
        $campaign->totalFailed = 0;

        if (!Craft::$app->getElements()->saveElement($campaign)) {
            return false;
        }

        // Push queue job
        Craft::$app->getQueue()->push(new SendCampaignJob([
            'campaignId' => $campaign->id,
        ]));

        return true;
    }

    public function schedule(int $campaignId, \DateTime $scheduledAt): bool
    {
        $campaign = $this->getById($campaignId);
        if (!$campaign) {
            return false;
        }

        $campaign->campaignStatus = CampaignStatus::Scheduled->value;
        $campaign->scheduledAt = Db::prepareDateForDb($scheduledAt);

        return Craft::$app->getElements()->saveElement($campaign);
    }

    public function duplicate(int $campaignId): ?Campaign
    {
        $original = $this->getById($campaignId);
        if (!$original) {
            return null;
        }

        $duplicate = new Campaign();
        $duplicate->title = $original->title . ' (Copy)';
        $duplicate->subject = $original->subject;
        $duplicate->fromName = $original->fromName;
        $duplicate->fromEmail = $original->fromEmail;
        $duplicate->replyToEmail = $original->replyToEmail;
        $duplicate->templatePath = $original->templatePath;
        $duplicate->body = $original->body;
        $duplicate->campaignStatus = CampaignStatus::Draft->value;
        $duplicate->mailingListId = $original->mailingListId;

        if (Craft::$app->getElements()->saveElement($duplicate)) {
            return $duplicate;
        }

        return null;
    }

    public function getReport(int $campaignId): SendReport
    {
        $campaign = $this->getById($campaignId);
        $report = new SendReport();

        if (!$campaign) {
            return $report;
        }

        $report->campaignId = $campaign->id;
        $report->totalRecipients = $campaign->totalRecipients;
        $report->totalSent = $campaign->totalSent;
        $report->totalFailed = $campaign->totalFailed;

        // Bounces
        $report->totalBounces = (int)SendLogRecord::find()
            ->where(['campaignId' => $campaignId, 'status' => 'bounced'])
            ->count();

        // Opens (if tracking table exists and edition supports it)
        $report->totalOpens = (int)TrackingRecord::find()
            ->where(['campaignId' => $campaignId, 'type' => 'open'])
            ->count();

        $report->uniqueOpens = (int)TrackingRecord::find()
            ->where(['campaignId' => $campaignId, 'type' => 'open'])
            ->select(['subscriberId'])
            ->distinct()
            ->count();

        // Clicks
        $report->totalClicks = (int)TrackingRecord::find()
            ->where(['campaignId' => $campaignId, 'type' => 'click'])
            ->count();

        $report->uniqueClicks = (int)TrackingRecord::find()
            ->where(['campaignId' => $campaignId, 'type' => 'click'])
            ->select(['subscriberId'])
            ->distinct()
            ->count();

        return $report;
    }

    public function preview(int $campaignId): ?string
    {
        $campaign = $this->getById($campaignId);
        if (!$campaign) {
            return null;
        }

        return Plugin::getInstance()->sender->renderEmail($campaign);
    }
}
