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
use Tywed\Webtrees\Module\Telegram\Services\TelegramConfigService;

/**
 * Handle cron requests for Telegram notifications about recent changes.
 */
class TelegramChangesCronJob implements RequestHandlerInterface
{
    private TelegramService $telegramService;
    private TelegramConfigService $telegramConfigService;

    public function __construct(TelegramService $telegramService, TelegramConfigService $telegramConfigService)
    {
        $this->telegramService = $telegramService;
        $this->telegramConfigService = $telegramConfigService;
    }

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
                
                // Daily guard for changes
                $lastChangesLaunchJd = $config['last_changes_launch_jd'] ?? null;
                if ($lastChangesLaunchJd === $startJd) {
                    $results[$configId] = [
                        'success' => false,
                        'message' => "Configuration \"{$configName}\": Changes already sent today.",
                    ];
                    continue;
                }
                
                // Prepare context (user, language, tree) via service
                $context = $this->telegramService->prepareCronContext(
                    $config,
                    $configName,
                    function (string $message) use (&$results, $configId): void {
                        $results[$configId] = [
                            'success' => false,
                            'message' => $message,
                        ];
                    }
                );

                if ($context['user'] === null) {
                    continue;
                }

                $tree = $context['tree'];

                // Changes within last 1 day
                $changes = $this->telegramService->getRecentChanges($tree, 1);

                if ($changes->isNotEmpty()) {
                    $messages = $this->telegramService->generateTelegramChangesMessage($changes, $config);
                    foreach ($messages as $message) {
                        $this->telegramService->sendTelegramMessage($bot_token, $chat_id, $message);
                        sleep(1);
                    }
                    $results[$configId] = [
                        'success' => true,
                        'message' => "Configuration \"{$configName}\": Change notifications sent successfully.",
                    ];
                } else {
                    $results[$configId] = [
                        'success' => true,
                        'message' => "Configuration \"{$configName}\": No changes in the last day.",
                    ];
                }

                Auth::logout();

                // Mark last changes launch
                $config['last_changes_launch'] = $currentTimestamp;
                $config['last_changes_launch_jd'] = $startJd;
                $this->telegramConfigService->saveConfig($config, $configId);

            } catch (\Exception $e) {
                Auth::logout();

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


