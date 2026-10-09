<?php

namespace justinholtweb\dispatch\models;

use craft\base\Model;

/**
 * A drip sequence (Pro): a trigger, then campaign emails sent to each enrolled subscriber on
 * delays. Each step's email is a Dispatch campaign, used as a template — it is never "sent" to its
 * list by the sequence.
 */
class Sequence extends Model
{
    public const TRIGGER_LIST = 'list';
    public const TRIGGER_EVENT = 'event';

    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    /** `list` (joined a mailing list) or `event` (a custom event fired by code or the API). */
    public string $triggerType = self::TRIGGER_LIST;
    public ?int $triggerListId = null;
    public ?string $triggerEvent = null;
    public bool $enabled = true;
    public ?string $uid = null;

    /** @var SequenceStep[] */
    public array $steps = [];

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle', 'triggerEvent'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            [['triggerType'], 'in', 'range' => [self::TRIGGER_LIST, self::TRIGGER_EVENT]],
            [['triggerListId'], 'required', 'when' => fn() => $this->triggerType === self::TRIGGER_LIST],
            [['triggerEvent'], 'required', 'when' => fn() => $this->triggerType === self::TRIGGER_EVENT],
            [['triggerEvent'], 'match', 'pattern' => '/^[a-zA-Z0-9_.:\-]+$/', 'skipOnEmpty' => true],
            [['steps'], 'validateSteps', 'skipOnEmpty' => false],
        ];
    }

    public function validateSteps(string $attribute): void
    {
        foreach ($this->steps as $step) {
            if (!$step->validate()) {
                $this->addError($attribute, implode(' ', $step->getFirstErrors()));
                return;
            }
        }
    }
}
