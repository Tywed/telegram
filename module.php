<?php

namespace Tywed\Webtrees\Module\Telegram;

use Fisharebest\Webtrees\Webtrees;
use Illuminate\Support\Collection;
use Fisharebest\Webtrees\Registry;

use function str_contains;

// webtrees major version switch
if (defined("WT_MODULES_DIR")) {
    // this is a webtrees 2.1 module. it cannot be used with webtrees 1.x (or 2.0.x). See README.md.
    return;
}

require_once __DIR__ . '/autoload.php';

// Use the directory name for the module name (most reliable method)
$module = new Telegram();
$module->setName(basename(__DIR__));

return $module;
