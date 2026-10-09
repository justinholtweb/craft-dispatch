<?php

namespace justinholtweb\dispatch\models;

use Craft;
use craft\base\Model;
use justinholtweb\dispatch\elements\conditions\SubscriberCondition;
use justinholtweb\dispatch\elements\Subscriber;

/**
 * A dynamic segment (Pro): a saved subscriber condition, evaluated each time it is used — so a
 * campaign sent to a segment goes to whoever matches at send time, not when the segment was made.
 */
class Segment extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    /** @var array<string, mixed> The condition's config (`SubscriberCondition::getConfig()`). */
    public array $condition = [];
    public ?string $uid = null;

    public function getCondition(): SubscriberCondition
    {
        $config = $this->condition;
        $config['class'] = SubscriberCondition::class;
        $config['elementType'] = Subscriber::class;

        /** @var SubscriberCondition $condition */
        $condition = Craft::$app->getConditions()->createCondition($config);
        $condition->mainTag = 'div';
        $condition->name = 'condition';

        return $condition;
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            [['description'], 'string'],
        ];
    }
}
