<?php

namespace justinholtweb\dispatch\models;

use craft\base\Model;

/** One email in a drip sequence: a campaign, sent `delayMinutes` after the previous step. */
class SequenceStep extends Model
{
    public ?int $id = null;
    public ?int $sequenceId = null;
    public ?int $campaignId = null;
    public int $sortOrder = 0;
    /** Minutes after the previous step (or after enrolment, for the first step). */
    public int $delayMinutes = 0;

    protected function defineRules(): array
    {
        return [
            [['campaignId'], 'required'],
            [['campaignId', 'sortOrder'], 'integer'],
            [['delayMinutes'], 'integer', 'min' => 0, 'max' => 60 * 24 * 366],
        ];
    }
}
