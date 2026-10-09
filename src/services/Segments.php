<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\models\Segment;

/**
 * Dynamic segments (Pro): saved subscriber conditions built with Craft's condition builder,
 * evaluated whenever they are used — a campaign sent to a segment reaches whoever matches at
 * send time.
 *
 * Segments are content (like lists), not configuration, so they live in the database rather than
 * project config and can be edited on production.
 */
class Segments extends Component
{
    private const TABLE = '{{%dispatch_segments}}';

    /** @return Segment[] */
    public function getAll(): array
    {
        return array_map(fn(array $row) => $this->_model($row), $this->_query()->orderBy(['name' => SORT_ASC])->all());
    }

    public function getById(int $id): ?Segment
    {
        $row = $this->_query()->where(['id' => $id])->one();

        return $row ? $this->_model($row) : null;
    }

    public function getByHandle(string $handle): ?Segment
    {
        $row = $this->_query()->where(['handle' => $handle])->one();

        return $row ? $this->_model($row) : null;
    }

    public function save(Segment $segment): bool
    {
        if (!$segment->validate()) {
            return false;
        }

        $clash = (new Query())->from(self::TABLE)->where(['handle' => $segment->handle])
            ->andFilterWhere(['not', ['id' => $segment->id]])->exists();

        if ($clash) {
            $segment->addError('handle', Craft::t('dispatch', 'That handle is already in use.'));
            return false;
        }

        // Normalise through the condition itself, so what is stored is what Craft would rebuild.
        $config = $segment->getCondition()->getConfig();
        unset($config['class'], $config['elementType'], $config['fieldContext']);

        $data = [
            'name' => $segment->name,
            'handle' => $segment->handle,
            'description' => $segment->description,
            'condition' => Json::encode($config),
        ];

        if ($segment->id) {
            Db::update(self::TABLE, $data, ['id' => $segment->id]);
        } else {
            $data['uid'] = StringHelper::UUID();
            Db::insert(self::TABLE, $data);
            $segment->id = (int)Craft::$app->getDb()->getLastInsertID();
            $segment->uid = $data['uid'];
        }

        $segment->condition = $config;

        return true;
    }

    /** Deletes a segment. Campaigns aimed at it lose it (the foreign key sets it to null). */
    public function delete(int $id): bool
    {
        return Db::delete(self::TABLE, ['id' => $id]) > 0;
    }

    /** Narrows a subscriber query to the segment's condition. */
    public function applyTo(SubscriberQuery $query, Segment $segment): SubscriberQuery
    {
        $segment->getCondition()->modifyQuery($query);

        return $query;
    }

    /** Everyone the segment matches right now — any status unless `$activeOnly`. */
    public function query(Segment $segment, bool $activeOnly = false): SubscriberQuery
    {
        $query = Subscriber::find()->status(null);

        if ($activeOnly) {
            $query->subscriberStatus('active');
        }

        return $this->applyTo($query, $segment);
    }

    public function count(Segment $segment, bool $activeOnly = true): int
    {
        return (int)$this->query($segment, $activeOnly)->count();
    }

    /** Whether one subscriber is in the segment, evaluated in SQL like a send would. */
    public function matches(Segment $segment, Subscriber $subscriber): bool
    {
        return $this->query($segment)->id($subscriber->id)->exists();
    }

    private function _query(): Query
    {
        return (new Query())
            ->select(['id', 'name', 'handle', 'description', 'condition', 'uid'])
            ->from(self::TABLE);
    }

    private function _model(array $row): Segment
    {
        $row['condition'] = Json::decodeIfJson((string)$row['condition']);
        $row['condition'] = is_array($row['condition']) ? $row['condition'] : [];

        return new Segment($row);
    }
}
