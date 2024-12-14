<?php

namespace Tywed\Webtrees\Module\Telegram;

use Fisharebest\Webtrees\Webtrees;
use Illuminate\Support\Collection;
use Fisharebest\Webtrees\Registry;

use function str_contains;

// webtrees major version switch
if (defined("WT_MODULES_DIR")) {
    // this is a webtrees 2.2 module. it cannot be used with webtrees 1.x (or 2.0.x). See README.md.
    return;
}

$pattern = Webtrees::MODULES_DIR . '*/autoload.php';
$filenames = glob($pattern, GLOB_NOSORT);

Collection::make($filenames)
    ->filter(static function (string $filename): bool {
        $module_name = basename(dirname($filename));

        foreach (['.', ' ', '[', ']'] as $character) {
            if (str_contains($module_name, $character)) {
                return false;
            }
        }

        return strlen($module_name) <= 30;
    })
    ->each(static function (string $filename): void {
        require_once $filename;
    });

return Registry::container()->get(Telegram::class);
