<?php

namespace justinholtweb\dispatch\enums;

enum SubscriberStatus: string
{
    case Active = 'active';
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Complained = 'complained';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Unsubscribed => 'Unsubscribed',
            self::Bounced => 'Bounced',
            self::Complained => 'Complained',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Unsubscribed => 'white',
            self::Bounced => 'orange',
            self::Complained => 'red',
        };
    }
}
