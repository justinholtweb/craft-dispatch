<?php

namespace justinholtweb\dispatch\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class MailingListQuery extends ElementQuery
{
    public ?string $name = null;
    public ?string $handle = null;

    public function name(?string $value): self
    {
        $this->name = $value;
        return $this;
    }

    public function handle(?string $value): self
    {
        $this->handle = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('dispatch_mailinglists');

        $this->query->select([
            'dispatch_mailinglists.name',
            'dispatch_mailinglists.handle',
            'dispatch_mailinglists.description',
            'dispatch_mailinglists.subscriberCount',
        ]);

        if ($this->name !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_mailinglists.name', $this->name));
        }

        if ($this->handle !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_mailinglists.handle', $this->handle));
        }

        return parent::beforePrepare();
    }
}
