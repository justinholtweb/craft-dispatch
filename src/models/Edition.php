<?php

namespace justinholtweb\dispatch\models;

use justinholtweb\dispatch\Plugin;
use yii\base\InvalidConfigException;

class Edition
{
    public const FREE = 'free';
    public const LITE = 'lite';
    public const PRO = 'pro';

    public static function is(string $edition): bool
    {
        return Plugin::getInstance()->is($edition);
    }

    public static function isAtLeast(string $edition): bool
    {
        return Plugin::getInstance()->is($edition, '>=');
    }

    public static function isFree(): bool
    {
        return self::is(self::FREE);
    }

    public static function isLite(): bool
    {
        return self::isAtLeast(self::LITE);
    }

    public static function isPro(): bool
    {
        return self::isAtLeast(self::PRO);
    }

    public static function requiresLite(string $feature = ''): void
    {
        if (!self::isLite()) {
            throw new InvalidConfigException(
                $feature ? "$feature requires Dispatch Lite or Pro." : 'This feature requires Dispatch Lite or Pro.'
            );
        }
    }

    public static function requiresPro(string $feature = ''): void
    {
        if (!self::isPro()) {
            throw new InvalidConfigException(
                $feature ? "$feature requires Dispatch Pro." : 'This feature requires Dispatch Pro.'
            );
        }
    }

    public static function maxMailingLists(): int
    {
        return self::isFree() ? 1 : PHP_INT_MAX;
    }

    public static function monthlySendLimit(): int
    {
        return self::isFree() ? 100 : PHP_INT_MAX;
    }
}
