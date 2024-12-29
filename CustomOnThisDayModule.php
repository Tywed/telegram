<?php

declare(strict_types=1);

namespace Tywed\Webtrees\Module\Telegram;

use Fisharebest\Webtrees\Module\OnThisDayModule;
use Fisharebest\Webtrees\Registry;
use ReflectionClass;

class CustomOnThisDayModule
{

    public static function getAllEvents(): array
    {
        $reflection = new ReflectionClass(OnThisDayModule::class);
        $constant = $reflection->getConstant('ALL_EVENTS');

        return $constant;
    }

    public static function getDefaultEvents(): array
    {
        $reflection = new ReflectionClass(OnThisDayModule::class);
        $constant = $reflection->getConstant('DEFAULT_EVENTS');

        return $constant;
    }

    public static function getEventLabels(): array
    {
        $all_events = self::getAllEvents();

        foreach ($all_events as $event => $tag) {
            $all_events[$event] = Registry::elementFactory()->make($tag)->label();
        }

        return $all_events;
    }
}
