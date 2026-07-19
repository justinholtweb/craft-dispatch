<?php

namespace justinholtweb\dispatchtests\unit\enums;

use Codeception\Test\Unit;
use justinholtweb\dispatch\enums\SubscriberStatus;

class SubscriberStatusTest extends Unit
{
    public function testValuesMatchExpectedStrings(): void
    {
        self::assertSame('active', SubscriberStatus::Active->value);
        self::assertSame('unsubscribed', SubscriberStatus::Unsubscribed->value);
        self::assertSame('bounced', SubscriberStatus::Bounced->value);
        self::assertSame('complained', SubscriberStatus::Complained->value);
    }

    public function testEveryCaseHasNonEmptyLabelAndColor(): void
    {
        foreach (SubscriberStatus::cases() as $case) {
            self::assertNotSame('', $case->label(), "Missing label for {$case->value}");
            self::assertNotSame('', $case->color(), "Missing color for {$case->value}");
        }
    }

    public function testFromValueRoundTrips(): void
    {
        self::assertSame(SubscriberStatus::Bounced, SubscriberStatus::from('bounced'));
    }
}
