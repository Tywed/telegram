<?php

declare(strict_types=1);

namespace Tywed\Webtrees\Module\Telegram\RequestHandlers;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tywed\Webtrees\Module\Telegram\Services\TelegramService;
use Tywed\Webtrees\Module\Telegram\Telegram;
use Tywed\Webtrees\Module\Telegram\Helpers\AppHelper;
use Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule;
use Tywed\Webtrees\Module\Telegram\Services\TelegramConfigService;

/**
 * Handle cron requests for Telegram notifications.
 */
class TelegramCronJob implements RequestHandlerInterface
{
    private Telegram $module;
    private TelegramService $telegramService;
    private TelegramConfigService $telegramConfigService;

    /**
     * Constructor for TelegramCronJob
     */
    public function __construct(TelegramService $telegramService, TelegramConfigService $telegramConfigService)
    {
        $this->telegramService = $telegramService;
        $this->telegramConfigService = $telegramConfigService;
    }

    /**
     * Handle the cron request
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $configs = $this->telegramConfigService->getAllConfigs();
        $results = [];
        $now = Registry::timestampFactory()->now();
        $startJd = $now->julianDay();
        $currentTimestamp = $now->timestamp();

        if (empty($configs)) {
            return response([
                'success' => false,
                'message' => 'No Telegram configurations found.',
            ]);
        }

        foreach ($configs as $config) {
            $configId = $config['id'];
            $configName = $config['name'] ?? 'Unnamed Configuration';

            try {
                // Skip disabled configurations
                if (!($config['enabled'] ?? true)) {
                    $results[$configId] = [
                        'success' => true,
                        'message' => "Configuration \"{$configName}\": Disabled, skipping.",
                    ];
                    continue;
                }

                $bot_token = $config['bot_token'] ?? '';
                $chat_id = $config['chat_id'] ?? '';
                $user_id = $config['user_id'] ?? '';
                $tree_id = $config['tree_id'] ?? '';

                if (empty($bot_token) || empty($chat_id) || empty($user_id) || empty($tree_id)) {
                    $results[$configId] = [
                        'success' => false,
                        'message' => "Configuration \"{$configName}\": Required preferences not set.",
                    ];
                    continue;
                }

                // Run only once per day: prefer dedicated JD field, fallback to legacy check
                $lastLaunchJd = $config['last_launch_jd'] ?? null;
                if ($lastLaunchJd === $startJd || (($config['last_launch'] ?? null) === $startJd)) {
                    $results[$configId] = [
                        'success' => false,
                        'message' => "Configuration \"{$configName}\": Already launched today.",
                    ];
                    continue;
                }

                $user = AppHelper::get(\Fisharebest\Webtrees\Services\UserService::class)->find((int)$user_id);
                $tree = AppHelper::get(\Fisharebest\Webtrees\Services\TreeService::class)->find((int)$tree_id);

                if (!$user || !$tree) {
                    $results[$configId] = [
                        'success' => false,
                        'message' => "Configuration \"{$configName}\": User or Tree not found.",
                    ];
                    continue;
                }

                Auth::login($user);
                I18N::init($user->getPreference(User::PREF_LANGUAGE, 'en'));

                $events = $config['events'] ?? CustomOnThisDayModule::getDefaultEvents();
                $event_array = is_array($events) ? $events : (is_string($events) ? explode(',', $events) : []);
                $filter = $config['filter'] ?? true;

                $factList = $this->telegramService->getTodayFacts($event_array, $startJd, $startJd, $tree, $filter);

                if ($factList->isNotEmpty()) {
                    $messages = $this->telegramService->generateTelegramMessage($factList, $config);

                    foreach ($messages as $message) {
                        $this->telegramService->sendTelegramMessage($bot_token, $chat_id, $message);
                        sleep(1); // To avoid hitting Telegram API limits
                    }
                    $results[$configId] = [
                        'success' => true,
                        'message' => "Configuration \"{$configName}\": Messages sent successfully.",
                    ];
                } else {
                    $results[$configId] = [
                        'success' => true,
                        'message' => "Configuration \"{$configName}\": No events found for today.",
                    ];
                }

                Auth::logout();
                
                // Update last launch markers for this configuration (successful run)
                $config['last_launch'] = $currentTimestamp; // keep timestamp for UI display
                $config['last_launch_jd'] = $startJd;       // use JD for daily guard
                $config['last_error'] = null; // Clear any previous error
                $this->telegramConfigService->saveConfig($config, $configId);

            } catch (\Exception $e) {
                Auth::logout(); // Ensure logout even on error
                
                // Check if this is a Telegram API error (not a general error)
                $isTelegramError = strpos($e->getMessage(), 'Telegram') !== false || 
                                 strpos($e->getMessage(), 'bot') !== false ||
                                 strpos($e->getMessage(), 'chat') !== false ||
                                 strpos($e->getMessage(), 'token') !== false;
                
                if ($isTelegramError) {
                    // Update last_error for Telegram-related errors
                    $config['last_error'] = $e->getMessage();
                    $config['last_error_date'] = $currentTimestamp;
                    $this->telegramConfigService->saveConfig($config, $configId);
                }
                
                $results[$configId] = [
                    'success' => false,
                    'message' => "Configuration \"{$configName}\": Error - " . $e->getMessage(),
                ];
            }
        }

        return response([
            'success' => true,
            'results' => $results,
        ]);
    }
} 