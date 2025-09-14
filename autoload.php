<?php

namespace Tywed\Webtrees\Module\Telegram;

use Composer\Autoload\ClassLoader;

$loader = new ClassLoader();
$loader->addPsr4('Tywed\\Webtrees\\Module\\Telegram\\', __DIR__ . '/src');
$loader->addPsr4('Tywed\\Webtrees\\Module\\Telegram\\Controllers\\', __DIR__ . '/src/Controllers');
$loader->addPsr4('Tywed\\Webtrees\\Module\\Telegram\\RequestHandlers\\', __DIR__ . '/src/RequestHandlers');
$loader->addPsr4('Tywed\\Webtrees\\Module\\Telegram\\Services\\', __DIR__ . '/src/Services');
$loader->addPsr4('Tywed\\Webtrees\\Module\\Telegram\\Helpers\\', __DIR__ . '/src/Helpers');

$loader->register();
