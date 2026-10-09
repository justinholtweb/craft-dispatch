<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\enums\CampaignStatus;
use justinholtweb\dispatch\models\Edition;
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

    /**
     * Starts sending a draft or scheduled campaign to its audience.
     *
     * The campaign is claimed first — a conditional UPDATE from draft/scheduled to sending — so a
     * double-clicked Send, or a scheduled campaign picked up by two scheduler runs, starts once.
     * A campaign with an A/B subject test (Pro) starts with the test phase.
     */
    public function send(int $campaignId): bool
    {
        $campaign = $this->getById($campaignId) ?? Campaign::find()->id($campaignId)->status(null)->one();
        if (!$campaign) {
            return false;
        }

        if ($campaign->campaignStatus !== CampaignStatus::Draft->value &&
            $campaign->campaignStatus !== CampaignStatus::Scheduled->value) {
            return false;
        }

        $audience = $this->audienceQuery($campaign);

        if ($audience === null) {
            return false;
        }

        $claimed = Db::update('{{%dispatch_campaigns}}', ['campaignStatus' => CampaignStatus::Sending->value], [
            'id' => $campaign->id,
            'campaignStatus' => [CampaignStatus::Draft->value, CampaignStatus::Scheduled->value],
        ], [], false);

        if ($claimed !== 1) {
            return false;
        }

        $isTest = Plugin::getInstance()->abTesting->isTest($campaign);

        $campaign->campaignStatus = CampaignStatus::Sending->value;
        $campaign->totalRecipients = (int)$audience->count();
        $campaign->totalSent = 0;
        $campaign->totalFailed = 0;
        $campaign->abStatus = $isTest ? 'testing' : null;
        $campaign->abWinner = null;
        $campaign->abDecideAt = null;

        if (!Craft::$app->getElements()->saveElement($campaign)) {
            Db::update('{{%dispatch_campaigns}}', ['campaignStatus' => CampaignStatus::Draft->value], ['id' => $campaign->id], [], false);
            return false;
        }

        Craft::$app->getQueue()->push(new SendCampaignJob([
            'campaignId' => $campaign->id,
            'phase' => $isTest ? SendCampaignJob::PHASE_TEST : SendCampaignJob::PHASE_ALL,
        ]));

        return true;
    }

    /**
     * Who a campaign goes to: active subscribers on its list, narrowed by its segment (Pro) — or,
     * with no list, everyone the segment matches. Null when it has no usable audience: neither a
     * list nor a segment, a segment that was deleted, or a segment on a site that is no longer
     * Pro (sending to the whole list instead would mail people the segment excluded).
     */
    public function audienceQuery(Campaign $campaign): ?SubscriberQuery
    {
        $query = Subscriber::find()->subscriberStatus('active');

        if ($campaign->mailingListId) {
            $query->mailingListId($campaign->mailingListId);
        }

        if ($campaign->segmentId) {
            $segment = Edition::isPro() ? Plugin::getInstance()->segments->getById($campaign->segmentId) : null;

            if (!$segment) {
                return null;
            }

            Plugin::getInstance()->segments->applyTo($query, $segment);
        } elseif (!$campaign->mailingListId) {
            return null;
        }

        return $query;
    }

    /**
     * Schedules a draft campaign to send at `$scheduledAt`; the scheduler starts it then.
     */
    public function schedule(int $campaignId, \DateTime $scheduledAt): bool
    {
        $campaign = $this->getById($campaignId);
        if (!$campaign) {
            return false;
        }

        $campaign->campaignStatus = CampaignStatus::Scheduled->value;
        $campaign->scheduledAt = Db::prepareDateForDb($scheduledAt);

        if (!Craft::$app->getElements()->saveElement($campaign)) {
            return false;
        }

        Plugin::getInstance()->scheduler->arm($scheduledAt);

        return true;
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
        $duplicate->segmentId = $original->segmentId;
        $duplicate->abEnabled = $original->abEnabled;
        $duplicate->setAbSubjects($original->getAbSubjects());
        $duplicate->abTestPercent = $original->abTestPercent;
        $duplicate->abMetric = $original->abMetric;
        $duplicate->abWaitMinutes = $original->abWaitMinutes;

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
