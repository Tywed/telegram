<?php

declare(strict_types=1);

namespace Tywed\Webtrees\Module\Telegram\Controllers;

use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tywed\Webtrees\Module\Telegram\Services\TelegramConfigService;
use Tywed\Webtrees\Module\Telegram\Services\TelegramService;
use Tywed\Webtrees\Module\Telegram\Telegram;

/**
 * Controller for Telegram module admin actions
 */
class TelegramController
{
    use ViewResponseTrait;
    
    private TelegramService $telegramService;
    private Telegram $module;
    private TelegramConfigService $telegramConfigService;

    /**
     * Constructor
     */
    public function __construct(
        TelegramService $telegramService,
        Telegram $module,
        TelegramConfigService $telegramConfigService
    ) {
        $this->telegramService = $telegramService;
        $this->module = $module;
        $this->telegramConfigService = $telegramConfigService;
    }

    /**
     * Display a list of all Telegram configurations
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    public function configsIndex(ServerRequestInterface $request): array
    {
        $configs = $this->telegramConfigService->getAllConfigs();
        $users = $this->telegramConfigService->getAvailableUsers();
        $trees = $this->telegramConfigService->getAvailableTrees();
        
        return [
            'configs' => $configs,
            'users' => $users,
            'trees' => $trees,
            'title' => I18N::translate('Telegram Configurations'),
            'module' => $this->module,
        ];
    }

    /**
     * Display the form for adding a new configuration
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    public function configAdd(ServerRequestInterface $request): array
    {
        $users = $this->telegramConfigService->getAvailableUsers();
        $trees = $this->telegramConfigService->getAvailableTrees();
        $events = \Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule::getAllEvents();
        $defaultEvents = \Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule::getDefaultEvents();
        $eventLabels = \Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule::getEventLabels();
        
        return [
            'config' => null,
            'users' => $users,
            'trees' => $trees,
            'events' => $events,
            'defaultEvents' => $defaultEvents,
            'eventLabels' => $eventLabels,
            'title' => I18N::translate('Add Telegram Configuration'),
            'formAction' => route('module', ['module' => $this->module->name(), 'action' => 'Admin']),
            'submitText' => I18N::translate('Add Configuration'),
            'module' => $this->module,
        ];
    }

    /**
     * Display the form for editing an existing configuration
     *
     * @param ServerRequestInterface $request
     * @param string $id
     * @return array
     */
    public function configEdit(ServerRequestInterface $request, string $id): array
    {
        $config = $this->telegramConfigService->getConfigById($id);
        if (!$config) {
            FlashMessages::addMessage(I18N::translate('Configuration not found'), 'danger');
            return [];
        }
        
        $users = $this->telegramConfigService->getAvailableUsers();
        $trees = $this->telegramConfigService->getAvailableTrees();
        $events = \Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule::getAllEvents();
        $defaultEvents = \Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule::getDefaultEvents();
        $eventLabels = \Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule::getEventLabels();
        
        return [
            'config' => $config,
            'users' => $users,
            'trees' => $trees,
            'events' => $events,
            'defaultEvents' => $defaultEvents,
            'eventLabels' => $eventLabels,
            'title' => I18N::translate('Edit Telegram Configuration'),
            'formAction' => route('module', ['module' => $this->module->name(), 'action' => 'Admin']),
            'submitText' => I18N::translate('Update Configuration'),
            'module' => $this->module,
        ];
    }

    /**
     * Store a new configuration
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function configStore(ServerRequestInterface $request): ResponseInterface
    {
        $data = Validator::parsedBody($request);
        
        $name = $data->string('name', '');
        $bot_token = $data->string('bot_token', '');
        $chat_id = $data->string('chat_id', '');
        $user_id = $data->string('user_id', '');
        $tree_id = $data->string('tree_id', '');
        
        // Validate required fields
        if (empty($name) || empty($bot_token) || empty($chat_id) || empty($user_id) || empty($tree_id)) {
            FlashMessages::addMessage(I18N::translate('Please fill in all required fields'), 'danger');
            return redirect(route('module', ['module' => $this->module->name(), 'action' => 'ConfigAdd']));
        }
        
        $config = [
            'id' => uniqid(),
            'name' => $name,
            'bot_token' => $bot_token,
            'chat_id' => $chat_id,
            'user_id' => $user_id,
            'tree_id' => $tree_id,
            'events' => $data->array('events', []),
            'enabled' => $data->boolean('enabled', true),
            'filter' => $data->boolean('filter', true),
            'location_display' => $data->integer('location_display', 2),
            'date_display' => $data->boolean('date_display', true),
            'start_message' => $data->string('start_message', ''),
            'end_message' => $data->string('end_message', ''),
        ];
        
        $this->telegramConfigService->saveConfig($config);
        FlashMessages::addMessage(I18N::translate('Configuration added successfully'), 'success');
        
        return redirect(route('module', ['module' => $this->module->name(), 'action' => 'Admin']));
    }

    /**
     * Update an existing configuration
     *
     * @param ServerRequestInterface $request
     * @param string $id
     * @return ResponseInterface
     */
    public function configUpdate(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $data = Validator::parsedBody($request);
        
        $name = $data->string('name', '');
        $bot_token = $data->string('bot_token', '');
        $chat_id = $data->string('chat_id', '');
        $user_id = $data->string('user_id', '');
        $tree_id = $data->string('tree_id', '');
        
        // Validate required fields
        if (empty($name) || empty($bot_token) || empty($chat_id) || empty($user_id) || empty($tree_id)) {
            FlashMessages::addMessage(I18N::translate('Please fill in all required fields'), 'danger');
            return redirect(route('module', ['module' => $this->module->name(), 'action' => 'ConfigEdit', 'id' => $id]));
        }
        
        $config = [
            'id' => $id,
            'name' => $name,
            'bot_token' => $bot_token,
            'chat_id' => $chat_id,
            'user_id' => $user_id,
            'tree_id' => $tree_id,
            'events' => $data->array('events', []),
            'enabled' => $data->boolean('enabled', true),
            'filter' => $data->boolean('filter', true),
            'location_display' => $data->integer('location_display', 2),
            'date_display' => $data->boolean('date_display', true),
            'start_message' => $data->string('start_message', ''),
            'end_message' => $data->string('end_message', ''),
        ];
        
        $this->telegramConfigService->saveConfig($config, $id);
        FlashMessages::addMessage(I18N::translate('Configuration updated successfully'), 'success');
        
        return redirect(route('module', ['module' => $this->module->name(), 'action' => 'Admin']));
    }

    /**
     * Delete a configuration
     *
     * @param ServerRequestInterface $request
     * @param string $id
     * @return ResponseInterface
     */
    public function configDelete(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $this->telegramConfigService->deleteConfig($id);
        FlashMessages::addMessage(I18N::translate('Configuration deleted successfully'), 'success');
        
        return redirect(route('module', ['module' => $this->module->name(), 'action' => 'Admin']));
    }

    /**
     * Quick test: send a test message to provided bot/chat
     */
    public function configTest(ServerRequestInterface $request): ResponseInterface
    {
        $data = Validator::parsedBody($request);
        $bot_token = $data->string('bot_token', '');
        $chat_id = $data->string('chat_id', '');

        if ($bot_token === '' || $chat_id === '') {
            FlashMessages::addMessage(I18N::translate('Please fill in all required fields'), 'danger');
            return redirect(route('module', ['module' => $this->module->name(), 'action' => 'Admin']));
        }

        try {
            $this->telegramService->sendTelegramMessage($bot_token, $chat_id, '✅ ' . I18N::translate('Test message from Telegram module'));
            FlashMessages::addMessage(I18N::translate('Test message sent successfully'), 'success');
        } catch (\Exception $e) {
            FlashMessages::addMessage(I18N::translate('Test failed') . ': ' . e($e->getMessage()), 'danger');
        }

        return redirect(route('module', ['module' => $this->module->name(), 'action' => 'Admin']));
    }
}
