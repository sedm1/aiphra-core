<?php

namespace AiphraCore\Objects\Calendars;

use AiphraCore\Support\Arrays\AbstractObjectArray;

/**
 * @extends AbstractObjectArray<Calendar>
 */
final class Calendars extends AbstractObjectArray {

    protected const ITEM_TYPE = Calendar::class;
}
