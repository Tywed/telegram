<?php

/*
Copyright (C) 2024 Tywed

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

declare(strict_types=1);

namespace Tywed\Webtrees\Module\Telegram;

use Aura\Router\RouterContainer;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Services\TreeService;
use Tywed\Webtrees\Module\Telegram\CronJob as TelegramCronJob;

class Telegram extends AbstractModule implements ModuleCustomInterface, ModuleGlobalInterface, ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleGlobalTrait;
    use ModuleConfigTrait;

    public const CUSTOM_MODULE = 'Telegram';
    public const CUSTOM_AUTHOR = 'Tywed';
    public const CUSTOM_WEBSITE = 'https://github.com/tywed/' . self::CUSTOM_MODULE . '/';
    public const CUSTOM_VERSION = '0.1.3';
    public const CUSTOM_LAST = self::CUSTOM_WEBSITE . 'raw/main/latest-version.txt';
    public const CUSTOM_SUPPORT_URL = self::CUSTOM_WEBSITE . 'issues';
    public const PREFIX = 'telegram';

    protected UserService $users;
    protected TreeService $trees;

    public function __construct()
    {
        $this->users = Registry::container()->get(UserService::class);
        $this->trees = Registry::container()->get(TreeService::class);
    }


    public function title(): string
    {
        return I18N::translate('Telegram');
    }

    public function description(): string
    {
        return I18N::translate('Description.');
    }

    public function customModuleAuthorName(): string
    {
        return self::CUSTOM_AUTHOR;
    }

    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION;
    }

    public function customModuleLatestVersionUrl(): string
    {
        return self::CUSTOM_LAST;
    }

    public function customModuleSupportUrl(): string
    {
        return self::CUSTOM_SUPPORT_URL;
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        $router = Registry::container()->get(RouterContainer::class);
        $map = $router->getMap();

        $map->get('telegram', '/cron/telegram', TelegramCronJob::class)->allows(RequestMethodInterface::METHOD_POST);
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function isUserBlock(): bool
    {
        return false;
    }

    public function isTreeBlock(): bool
    {
        return false;
    }

    public function customTranslations(string $language): array
    {
        $file = $this->resourcesFolder() . "langs/{$language}.php";

        return file_exists($file)
            ? require $file
            : require $this->resourcesFolder() . 'langs/en.php';
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        if (!Auth::isAdmin()) {
            throw new HttpAccessDeniedException();
        }

        $users = $this->users->all();
        $userOptions = [];
        foreach ($users as $user) {
            $userOptions[$user->id()] = $user->userName();
        }

        $trees = $this->trees->all();
        $treeOptions = [];
        foreach ($trees as $tree) {
            $treeOptions[$tree->id()] = $tree->name();
        }

        $default_events = implode(',', CustomOnThisDayModule::getDefaultEvents());
        $events     = $this->getPreference('events', $default_events);
        $event_array = explode(',', $events);
        $all_events = CustomOnThisDayModule::getEventLabels();

        $filter     = $this->getPreference('filter', '1');
        $start_message     = $this->getPreference('start_message', '');
        $end_message     = $this->getPreference('end_message', '');

        $location_display     = $this->getPreference('location_display', '2');
        $date_display     = $this->getPreference('date_display', '1');

        return $this->viewResponse($this->name() . '::settings', [
            'title' => $this->title(),
            'telegram_token' => $this->getPreference('telegram_token', 'Not set'),
            'telegram_id' => $this->getPreference('telegram_id', 'Not set'),
            'user' => $this->getPreference('user', ''),
            'users' => $userOptions,
            'tree' => $this->getPreference('tree', ''),
            'trees' => $treeOptions,
            'event_array' => $event_array,
            'all_events'  => $all_events,
            'filter'      => $filter,
            'start_message'      => base64_decode($start_message),
            'end_message'      => base64_decode($end_message),
            'location_display'      => $location_display,
            'date_display'      => $date_display,
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getParsedBody();

        $this->setPreference('telegram_token', $params['telegram_token']);
        $this->setPreference('telegram_id', $params['telegram_id']);
        $this->setPreference('user', $params['users']);
        $this->setPreference('tree', $params['trees']);
        $this->setPreference('events', implode(',', $params['events']));
        $this->setPreference('filter', $params['filter']);
        $this->setPreference('start_message', base64_encode($params['start_message']));
        $this->setPreference('end_message', base64_encode($params['end_message']));
        $this->setPreference('location_display', $params['location_display']);
        $this->setPreference('date_display', $params['date_display']);

        $message = I18N::translate('The preferences for the module “%s” have been updated.', $this->title());
        FlashMessages::addMessage($message, 'success');

        return redirect($this->getConfigLink());
    }
}
