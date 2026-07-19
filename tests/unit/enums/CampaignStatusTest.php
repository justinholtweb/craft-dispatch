<?php

namespace justinholtweb\dispatchtests\unit\enums;

use Codeception\Test\Unit;
use justinholtweb\dispatch\enums\CampaignStatus;

class CampaignStatusTest extends Unit
{
    public function testValuesMatchExpectedStrings(): void
    {
        self::assertSame('draft', CampaignStatus::Draft->value);
        self::assertSame('scheduled', CampaignStatus::Scheduled->value);
        self::assertSame('sending', CampaignStatus::Sending->value);
        self::assertSame('sent', CampaignStatus::Sent->value);
        self::assertSame('failed', CampaignStatus::Failed->value);
    }

    public function testEveryCaseHasNonEmptyLabelAndColor(): void
    {
        foreach (CampaignStatus::cases() as $case) {
            self::assertNotSame('', $case->label(), "Missing label for {$case->value}");
            self::assertNotSame('', $case->color(), "Missing color for {$case->value}");
        }
    }

    public function testFromValueRoundTrips(): void
    {
        self::assertSame(CampaignStatus::Sent, CampaignStatus::from('sent'));
    }

    public function testLabelForSpecificCase(): void
    {
        self::assertSame('Sending', CampaignStatus::Sending->label());
    }

    public function testColorForSpecificCase(): void
    {
        self::assertSame('green', CampaignStatus::Sent->color());
    }
}
