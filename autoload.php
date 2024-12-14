<?php

namespace Tywed\Webtrees\Module\Telegram;

use Composer\Autoload\ClassLoader;

$loader = new ClassLoader();
$loader->addPsr4('Tywed\\Webtrees\\Module\\Telegram\\', __DIR__);

$loader->register();
