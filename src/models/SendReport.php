<?php

namespace justinholtweb\dispatch\models;

use craft\base\Model;

class SendReport extends Model
{
    public int $campaignId = 0;
    public int $totalRecipients = 0;
    public int $totalSent = 0;
    public int $totalFailed = 0;
    public int $totalOpens = 0;
    public int $uniqueOpens = 0;
    public int $totalClicks = 0;
    public int $uniqueClicks = 0;
    public int $totalBounces = 0;
    public int $totalComplaints = 0;
    public int $totalUnsubscribes = 0;

    public function getOpenRate(): float
    {
        return $this->totalSent > 0 ? ($this->uniqueOpens / $this->totalSent) * 100 : 0;
    }

    public function getClickRate(): float
    {
        return $this->totalSent > 0 ? ($this->uniqueClicks / $this->totalSent) * 100 : 0;
    }

    public function getBounceRate(): float
    {
        return $this->totalSent > 0 ? ($this->totalBounces / $this->totalSent) * 100 : 0;
    }

    public function getDeliveryRate(): float
    {
        return $this->totalRecipients > 0
            ? (($this->totalSent - $this->totalBounces) / $this->totalRecipients) * 100
            : 0;
    }
}
