<?php

namespace justinholtweb\dispatch\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class CampaignQuery extends ElementQuery
{
    public ?string $subject = null;
    public ?string $campaignStatus = null;
    public ?int $mailingListId = null;
    public mixed $scheduledAt = null;
    public mixed $sentAt = null;

    public function subject(?string $value): self
    {
        $this->subject = $value;
        return $this;
    }

    public function campaignStatus(?string $value): self
    {
        $this->campaignStatus = $value;
        return $this;
    }

    public function mailingListId(?int $value): self
    {
        $this->mailingListId = $value;
        return $this;
    }

    public function scheduledAt(mixed $value): self
    {
        $this->scheduledAt = $value;
        return $this;
    }

    public function sentAt(mixed $value): self
    {
        $this->sentAt = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('dispatch_campaigns');

        $this->query->select([
            'dispatch_campaigns.subject',
            'dispatch_campaigns.fromName',
            'dispatch_campaigns.fromEmail',
            'dispatch_campaigns.replyToEmail',
            'dispatch_campaigns.templatePath',
            'dispatch_campaigns.body',
            'dispatch_campaigns.campaignStatus',
            'dispatch_campaigns.mailingListId',
            'dispatch_campaigns.scheduledAt',
            'dispatch_campaigns.sentAt',
            'dispatch_campaigns.totalRecipients',
            'dispatch_campaigns.totalSent',
            'dispatch_campaigns.totalFailed',
        ]);

        if ($this->subject !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_campaigns.subject', $this->subject));
        }

        if ($this->campaignStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_campaigns.campaignStatus', $this->campaignStatus));
        }

        if ($this->mailingListId !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_campaigns.mailingListId', $this->mailingListId));
        }

        if ($this->scheduledAt !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('dispatch_campaigns.scheduledAt', $this->scheduledAt));
        }

        if ($this->sentAt !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('dispatch_campaigns.sentAt', $this->sentAt));
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'draft' => ['dispatch_campaigns.campaignStatus' => 'draft'],
            'scheduled' => ['dispatch_campaigns.campaignStatus' => 'scheduled'],
            'sending' => ['dispatch_campaigns.campaignStatus' => 'sending'],
            'sent' => ['dispatch_campaigns.campaignStatus' => 'sent'],
            'failed' => ['dispatch_campaigns.campaignStatus' => 'failed'],
            default => parent::statusCondition($status),
        };
    }
}
