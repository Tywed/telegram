<?php

declare(strict_types=1);

namespace Tywed\Webtrees\Module\Telegram\Services;

use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\User;
use Fisharebest\Webtrees\Tree;
use Tywed\Webtrees\Module\Telegram\Telegram;
use Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule;

class TelegramConfigService
{
    private Telegram $module;
    private UserService $userService;
    private TreeService $treeService;

    public function __construct(Telegram $module, UserService $userService, TreeService $treeService)
    {
        $this->module = $module;
        $this->userService = $userService;
        $this->treeService = $treeService;
    }

    /**
     * Get all Telegram configurations, with backward compatibility.
     *
     * @return array<int, array>
     */
    public function getAllConfigs(): array
    {
        $configs = json_decode($this->module->getPreference(Telegram::CUSTOM_MODULE_CONFIGS, '[]'), true);

        // Ensure configs is always an array
        if (!is_array($configs)) {
            $configs = [];
        }

        // Check if migration has already been done
        $migrationDone = $this->module->getPreference('telegram_migration_done', '0') === '1';

        if (!$migrationDone) {
            // Migrate existing configs to new structure if needed
            $configs = $this->migrateConfigs($configs);

            // Mark migration as done
            $this->module->setPreference('telegram_migration_done', '1');
        }

        // Backward compatibility: If old single preferences exist, convert them to a new config
        if (empty($configs)) {
            $oldTelegramToken = $this->module->getPreference('telegram_token');
            $oldTelegramId = $this->module->getPreference('telegram_id');
            $oldUserId = $this->module->getPreference('user');
            $oldTreeId = $this->module->getPreference('tree');
            $oldFilter = $this->module->getPreference('filter', '1');
            $oldEvents = $this->module->getPreference('events', implode(',', CustomOnThisDayModule::getDefaultEvents()));
            $oldLocationDisplay = $this->module->getPreference('location_display', '2');
            $oldDateDisplay = $this->module->getPreference('date_display', '1');
            $oldStartMessage = $this->module->getPreference('start_message', '');
            $oldEndMessage = $this->module->getPreference('end_message', '');

            if (!empty($oldTelegramToken) && !empty($oldTelegramId) && !empty($oldUserId) && !empty($oldTreeId)) {
                $newConfig = [
                    'id' => uniqid(),
                    'bot_token' => $oldTelegramToken,
                    'chat_id' => $oldTelegramId,
                    'user_id' => $oldUserId,
                    'tree_id' => $oldTreeId,
                    'filter' => (bool)$oldFilter,
                    'events' => $oldEvents === '' ? [] : array_filter(array_map('trim', explode(',', $oldEvents)), fn($e) => $e !== ''),
                    'location_display' => (int)$oldLocationDisplay,
                    'date_display' => (bool)$oldDateDisplay,
                    'start_message' => $oldStartMessage,
                    'end_message' => $oldEndMessage,
                    'name' => 'Default Configuration',
                    'enabled' => true,
                    'last_launch' => null,
                    'last_error' => null,
                    'last_error_date' => null,
                ];
                $configs[] = $newConfig;
                $this->saveAllConfigs($configs);

                // Delete old preferences
                $this->module->setPreference('telegram_token', '');
                $this->module->setPreference('telegram_id', '');
                $this->module->setPreference('user', '');
                $this->module->setPreference('tree', '');
                $this->module->setPreference('filter', '');
                $this->module->setPreference('events', '');
                $this->module->setPreference('location_display', '');
                $this->module->setPreference('date_display', '');
                $this->module->setPreference('start_message', '');
                $this->module->setPreference('end_message', '');
                $this->module->setPreference('launch', '');
            }
        }

        return $configs;
    }

    /**
     * Migrate existing configs to new structure if needed
     *
     * @param array $configs
     * @return array
     */
    private function migrateConfigs(array $configs): array
    {
        $migrated = false;

        foreach ($configs as $key => $config) {
            // Check if config needs migration (has old field names)
            if (isset($config['telegram_token']) || isset($config['telegram_id']) || isset($config['user']) || isset($config['tree'])) {
                $newConfig = $config;

                // Migrate field names
                if (isset($config['telegram_token'])) {
                    $newConfig['bot_token'] = $config['telegram_token'];
                    unset($newConfig['telegram_token']);
                }
                if (isset($config['telegram_id'])) {
                    $newConfig['chat_id'] = $config['telegram_id'];
                    unset($newConfig['telegram_id']);
                }
                if (isset($config['user'])) {
                    $newConfig['user_id'] = $config['user'];
                    unset($newConfig['user']);
                }
                if (isset($config['tree'])) {
                    $newConfig['tree_id'] = $config['tree'];
                    unset($newConfig['tree']);
                }

                // Add missing fields
                if (!isset($newConfig['enabled'])) {
                    $newConfig['enabled'] = true;
                }
                if (!isset($newConfig['name'])) {
                    $newConfig['name'] = 'Migrated Configuration';
                }
                if (!isset($newConfig['filter'])) {
                    $newConfig['filter'] = true;
                }
                if (!isset($newConfig['location_display'])) {
                    $newConfig['location_display'] = 2;
                }
                if (!isset($newConfig['date_display'])) {
                    $newConfig['date_display'] = true;
                }
                if (!isset($newConfig['start_message'])) {
                    $newConfig['start_message'] = '';
                }
                if (!isset($newConfig['end_message'])) {
                    $newConfig['end_message'] = '';
                }
                if (!isset($newConfig['last_launch'])) {
                    $newConfig['last_launch'] = null;
                }
                if (!isset($newConfig['last_error'])) {
                    $newConfig['last_error'] = null;
                }
                if (!isset($newConfig['last_error_date'])) {
                    $newConfig['last_error_date'] = null;
                }

                $configs[$key] = $newConfig;
                $migrated = true;
            }
        }

        // Save migrated configs if any changes were made
        if ($migrated) {
            $this->saveAllConfigs($configs);
        }

        return $configs;
    }

    /**
     * Save all configurations.
     *
     * @param array<int, array> $configs
     */
    private function saveAllConfigs(array $configs): void
    {
        $this->module->setPreference(Telegram::CUSTOM_MODULE_CONFIGS, json_encode($configs));
    }

    /**
     * Get a single configuration by ID.
     *
     * @param string $id
     * @return array|null
     */
    public function getConfigById(string $id): ?array
    {
        $configs = $this->getAllConfigs();

        foreach ($configs as $config) {
            if ($config['id'] === $id) {
                return $config;
            }
        }
        return null;
    }

    /**
     * Save (add or update) a configuration.
     *
     * @param array $configData
     * @param string|null $id
     * @return array The saved configuration with its ID.
     */
    public function saveConfig(array $configData, ?string $id = null): array
    {
        $configs = $this->getAllConfigs();

        if ($id !== null) {
            // Update existing config
            foreach ($configs as $key => $config) {
                if ($config['id'] === $id) {
                    $configs[$key] = array_merge($config, $configData);
                    $this->saveAllConfigs($configs);
                    return $configs[$key];
                }
            }
            // If config with ID not found, this is an error - don't create duplicate
            throw new \InvalidArgumentException("Configuration with ID '{$id}' not found for update");
        } else {
            // Add new config - use the ID from configData if it exists
            if (!isset($configData['id'])) {
                $configData['id'] = uniqid();
            }

            // Check if ID already exists
            foreach ($configs as $config) {
                if ($config['id'] === $configData['id']) {
                    // Generate new unique ID
                    $configData['id'] = uniqid();
                    break;
                }
            }

            $configs[] = $configData;
            $this->saveAllConfigs($configs);
            return $configData;
        }
    }

    /**
     * Delete a configuration by ID.
     *
     * @param string $id
     * @return bool
     */
    public function deleteConfig(string $id): bool
    {
        $configs = $this->getAllConfigs();
        $initialCount = count($configs);
        $configs = array_filter($configs, fn($config) => $config['id'] !== $id);

        if (count($configs) < $initialCount) {
            $this->saveAllConfigs(array_values($configs));
            return true;
        }
        return false;
    }

    /**
     * Get available users for dropdown.
     *
     * @return array<int, \Fisharebest\Webtrees\User>
     */
    public function getAvailableUsers(): array
    {
        $users = $this->userService->all();
        $options = [];
        foreach ($users as $user) {
            $options[$user->id()] = $user;
        }
        return $options;
    }

    /**
     * Get available trees for dropdown.
     *
     * @return array<int, \Fisharebest\Webtrees\Tree>
     */
    public function getAvailableTrees(): array
    {
        $trees = $this->treeService->all();
        $options = [];
        foreach ($trees as $tree) {
            $options[$tree->id()] = $tree;
        }
        return $options;
    }
}
