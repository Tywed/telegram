<?php

namespace Tywed\Webtrees\Module\Telegram;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tywed\Webtrees\Module\Telegram\Services\TelegramService;
use Tywed\Webtrees\Module\Telegram\Controllers\TelegramController;
use Tywed\Webtrees\Module\Telegram\RequestHandlers\TelegramCronJob;
use Tywed\Webtrees\Module\Telegram\RequestHandlers\TelegramChangesCronJob;
use Tywed\Webtrees\Module\Telegram\Helpers\AppHelper;
use Tywed\Webtrees\Module\Telegram\Services\TelegramConfigService;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Validator;

/**
 * Telegram Module
 * 
 * A module that sends Telegram notifications about significant family events
 */
class Telegram extends AbstractModule implements ModuleCustomInterface, ModuleGlobalInterface, ModuleConfigInterface, MiddlewareInterface
{
    use ModuleCustomTrait;
    use ModuleGlobalTrait;
    use ModuleConfigTrait;
    use ViewResponseTrait;

    public const CUSTOM_MODULE = 'Telegram';
    public const CUSTOM_AUTHOR = 'Tywed';
    public const CUSTOM_WEBSITE = 'https://github.com/tywed/' . self::CUSTOM_MODULE . '/';
    public const CUSTOM_VERSION = '0.2.1';
    public const CUSTOM_LAST = self::CUSTOM_WEBSITE . 'raw/main/latest-version.txt';
    public const CUSTOM_SUPPORT_URL = self::CUSTOM_WEBSITE . 'issues';
    public const CUSTOM_MODULE_CONFIGS = 'telegram_configs';

    private TelegramService $telegramService;
    private TelegramConfigService $telegramConfigService;
    private TelegramController $telegramController;

    /**
     * Constructor for the Telegram module
     */
    public function __construct()
    {
        $userService = AppHelper::get(UserService::class);
        $treeService = AppHelper::get(TreeService::class);
        
        $this->telegramService = new TelegramService($this, $userService, $treeService);
        $this->telegramConfigService = new TelegramConfigService($this, $userService, $treeService);
        $this->telegramController = new TelegramController($this->telegramService, $this, $this->telegramConfigService);
    }

    /**
     * {@inheritdoc}
     */
    public function title(): string
    {
        return I18N::translate('Telegram');
    }

    /**
     * {@inheritdoc}
     */
    public function description(): string
    {
        return I18N::translate('Sends Telegram notifications about family events, such as birthdays and anniversaries.');
    }

    /**
     * {@inheritdoc}
     */
    public function customModuleAuthorName(): string
    {
        return self::CUSTOM_AUTHOR;
    }

    /**
     * {@inheritdoc}
     */
    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION;
    }

    /**
     * {@inheritdoc}
     */
    public function customModuleLatestVersionUrl(): string
    {
        return self::CUSTOM_LAST;
    }

    /**
     * {@inheritdoc}
     */
    public function customModuleSupportUrl(): string
    {
        return self::CUSTOM_SUPPORT_URL;
    }

    /**
     * Bootstrap the module
     */
    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/telegram/');
    }

    /**
     * Get cron job handler
     */
    public function getCronAction(ServerRequestInterface $request): ResponseInterface
    {
        $cronJob = new TelegramCronJob($this->telegramService, $this->telegramConfigService);
        return $cronJob->handle($request);
    }

    /**
     * Get changes cron job handler
     */
    public function getChangesCronAction(ServerRequestInterface $request): ResponseInterface
    {
        $cronJob = new TelegramChangesCronJob($this->telegramService, $this->telegramConfigService);
        return $cronJob->handle($request);
    }

    /**
     * HTTP request processing
     * 
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }

    /**
     * Path to module resources
     * 
     * @return string
     */
    public function resourcesFolder(): string
    {
        return dirname(__DIR__) . '/resources/';
    }

    /**
     * Load translations
     * 
     * @param string $language
     * @return array
     */
    public function customTranslations(string $language): array
    {
        $file = $this->resourcesFolder() . "langs/{$language}.php";

        return file_exists($file)
            ? require $file
            : require $this->resourcesFolder() . 'langs/en.php';
    }

    /**
     * Display the admin settings page
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';
        
        $params = $this->telegramController->configsIndex($request);
        return $this->viewResponse($this->name() . '::configs-list', $params);
    }

    /**
     * Handle POST requests to admin page
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $action = Validator::parsedBody($request)->string('action', '');
        
        switch ($action) {
            case 'delete':
                $id = Validator::parsedBody($request)->string('id', '');
                return $this->telegramController->configDelete($request, $id);
            case 'test':
                return $this->telegramController->configTest($request);
            case 'store':
                return $this->telegramController->configStore($request);
            case 'update':
                $id = Validator::parsedBody($request)->string('id', '');
                return $this->telegramController->configUpdate($request, $id);
            default:
                return redirect(route('module', ['module' => $this->name(), 'action' => 'Admin']));
        }
    }

    /**
     * Display the form for adding a new configuration.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getConfigAddAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';
        
        $params = $this->telegramController->configAdd($request);
        return $this->viewResponse($this->name() . '::config-form', $params);
    }

    /**
     * Handle POST request to add configuration form
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postConfigAddAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->telegramController->configStore($request);
    }

    /**
     * Display the form for editing an existing configuration.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getConfigEditAction(ServerRequestInterface $request, string $id = ''): ResponseInterface
    {
        $this->layout = 'layouts/administration';
        
        // If ID not provided as parameter, try to get from query parameters (for backward compatibility)
        if (empty($id)) {
            $id = Validator::queryParams($request)->string('id', '');
        }
              
        if (empty($id)) {
            FlashMessages::addMessage(I18N::translate('Configuration ID not provided'), 'danger');
            return redirect(route('module', ['module' => $this->name(), 'action' => 'Admin']));
        }
        
        $params = $this->telegramController->configEdit($request, $id);
        if (empty($params)) {
            return redirect(route('module', ['module' => $this->name(), 'action' => 'Admin']));
        }
        
        return $this->viewResponse($this->name() . '::config-form', $params);
    }

    /**
     * Handle POST request to edit configuration form
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postConfigEditAction(ServerRequestInterface $request): ResponseInterface
    {
        $id = Validator::parsedBody($request)->string('id', '');
        return $this->telegramController->configUpdate($request, $id);
    }

} 