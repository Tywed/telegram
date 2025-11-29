<?php

namespace Tywed\Webtrees\Module\Telegram\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\UserService as CoreUserService;
use Fisharebest\Webtrees\Services\TreeService as CoreTreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\DB;
use Illuminate\Database\Query\Expression;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;
use Illuminate\Support\Collection;
use Tywed\Webtrees\Module\Telegram\Telegram;
use Tywed\Webtrees\Module\Telegram\Helpers\AppHelper;
use Tywed\Webtrees\Module\Telegram\CustomOnThisDayModule;

/**
 * Service class for Telegram module
 */
class TelegramService
{
    private Telegram $module;
    private UserService $userService;
    private TreeService $treeService;
    private CalendarService $calendarService;

    /**
     * Constructor for TelegramService
     *
     * @param Telegram $module
     * @param UserService $userService
     * @param TreeService $treeService
     */
    public function __construct(Telegram $module, UserService $userService, TreeService $treeService)
    {
        $this->module = $module;
        $this->userService = $userService;
        $this->treeService = $treeService;
        $this->calendarService = AppHelper::get(CalendarService::class);
    }

    /**
     * Get all users
     *
     * @return Collection<User>
     */
    public function getAllUsers(): Collection
    {
        return $this->userService->all();
    }

    /**
     * Get all trees
     *
     * @return Collection<Tree>
     */
    public function getAllTrees(): Collection
    {
        return $this->treeService->all();
    }

    /**
     * Get today's facts
     *
     * @param array $types
     * @param int $startJd
     * @param int $endJd
     * @param Tree $tree
     * @param bool $filter
     * @return Collection<Fact>
     */
    public function getTodayFacts(array $types, int $startJd, int $endJd, $tree, bool $filter): Collection
    {
        $facts = $this->calendarService->getEventsList(
            $startJd,
            $endJd,
            implode(',', $types),
            $filter,
            'alpha',
            $tree
        )->filter(static function (Fact $fact) {
            $record = $fact->record();

            if ($record instanceof Family) {
                return $record->facts(Gedcom::DIVORCE_EVENTS)->isEmpty();
            }

            return true;
        });

        return $facts;
    }

    /**
     * Prepare execution context for cron jobs: finds user and tree,
     * performs Auth::login() and initializes I18N.
     *
     * If the user is not found, calls the provided callback to set
     * an appropriate error message and returns ['user' => null, 'tree' => null].
     * Responsibility for calling Auth::logout() remains with the caller.
     *
     * @param array         $config
     * @param string        $configName
     * @param callable      $onUserNotFound function(string $message): void
     *
     * @return array{user: ?User, tree: ?Tree}
     */
    public function prepareCronContext(array $config, string $configName, callable $onUserNotFound): array
    {
        $userId = $config['user_id'] ?? '';
        $treeId = $config['tree_id'] ?? '';

        $user = AppHelper::get(CoreUserService::class)->find((int) $userId);

        if (!$user) {
            $onUserNotFound("Configuration \"{$configName}\": User not found.");

            return ['user' => null, 'tree' => null];
        }

        Auth::login($user);
        I18N::init($user->getPreference(User::PREF_LANGUAGE, 'en'));

        // Tree may not exist (same behaviour as before in cron jobs) – keep it unchanged.
        $tree = AppHelper::get(CoreTreeService::class)->find((int) $treeId);

        return ['user' => $user, 'tree' => $tree];
    }

    /**
     * Generate Telegram message from facts
     *
     * @param Collection<Fact> $factList
     * @return array
     */
    public function generateTelegramMessage(Collection $factList, array $config = null): array
    {
        // Prefer per-configuration messages; fall back to legacy module preferences
        $start_message_raw = $config['start_message'] ?? $this->module->getPreference('start_message', '');
        // Legacy values may be base64-encoded in old single-preference storage
        $start_message_decoded = base64_decode($start_message_raw, true);
        $start_message_value = $start_message_decoded !== false ? $start_message_decoded : $start_message_raw;
        $start_message = !empty($start_message_value) ? $start_message_value . "\n" : "🗓 <b>" . I18N::translate("Today's events:") . "</b>\n\n";
        $start_length = mb_strlen($start_message);

        $end_message_raw = $config['end_message'] ?? $this->module->getPreference('end_message', '');
        $end_message_decoded = base64_decode($end_message_raw, true);
        $end_message_value = $end_message_decoded !== false ? $end_message_decoded : $end_message_raw;
        $end_message = !empty($end_message_value) ? "\n" . $end_message_value : '';
        $end_length = mb_strlen($end_message);

        $location_display = $config['location_display'] ?? $this->module->getPreference('location_display', '2');
        $date_display = (bool) ($config['date_display'] ?? $this->module->getPreference('date_display', '1'));

        $types = CustomOnThisDayModule::getEventLabels();
        $messages = [];

        foreach ($factList as $fact) {
            // Safely extract event type from fact tag (e.g., 'BIRT' from 'INDI:BIRT')
            $tagParts = explode(":", $fact->tag(), 2);
            $factType = trim($tagParts[1] ?? $tagParts[0] ?? '');

            // Skip if factType is empty or not in allowed types
            if ($factType === '' || !isset($types[$factType])) {
                continue;
            }

            if (!isset($messages[$factType])) {
                $messages[$factType] = "🔸 <b>{$types[$factType]}</b>:\n";
            }

            $record = $fact->record();
            $fullName = strip_tags($record->fullName());
            $link = $record->url();

            $date = strip_tags($fact->date()->display($record->tree(), null, true));

            $age = (PHP_INT_SIZE >= 8 || $fact->date()->gregorianYear() > 1901)
                ? '(' . Registry::timestampFactory()->now()->subtractYears($fact->anniv)->diffForHumans() . ')'
                : '(' . I18N::plural('%s year', '%s years', $fact->anniv, I18N::number($fact->anniv)) . ')';

            $factText = "<a href=\"$link\">$fullName</a>";

            if ($date && $date_display) {
                $factText .= " — <b>$date</b>";

                if ($fact->date()->gregorianYear() > 0) {
                    $factText .= " <b>$age</b>";
                }
            }

            if ($location_display !== 0 && $fact->place()->gedcomName() !== '') {
                if ($location_display == 1) {
                    $placeName = strip_tags($fact->place()->shortName());
                } else {
                    $placeName = strip_tags($fact->place()->fullName());
                }

                $placeUrl = $fact->place()->url();
                $factText .= " — <a href=\"$placeUrl\">$placeName</a>";
            }

            $factText .= ".\n";
            $messages[$factType] .= $factText;
        }

        $message = $start_message;
        $messageLength = $start_length;

        // Determine event type order based on configuration settings (same order as in the admin UI)
        $events = $config['events'] ?? CustomOnThisDayModule::getDefaultEvents();
        $eventOrder = is_array($events)
            ? $events
            : (is_string($events) ? explode(',', $events) : []);

        // Normalize event types: trim whitespace and filter empty values
        $eventOrder = array_filter(array_map('trim', $eventOrder), fn($e) => $e !== '');

        // First, append blocks in the order defined in the configuration
        foreach ($eventOrder as $eventType) {
            $eventType = trim((string) $eventType);
            if ($eventType !== '' && isset($messages[$eventType])) {
                $message .= $messages[$eventType];
                $messageLength += mb_strlen($messages[$eventType]);
                unset($messages[$eventType]); // avoid duplication below
            }
        }

        $message .= $end_message;
        $messageLength += $end_length;

        if ($messageLength <= 4096) {
            return [$message];
        }

        $messagesToSend = [];
        $currentMessage = $start_message;
        $currentLength = $start_length;

        foreach ($messages as $factMessage) {
            if ($currentLength + mb_strlen($factMessage) <= 4096) {
                $currentMessage .= $factMessage;
                $currentLength += mb_strlen($factMessage);
            } else {
                $messagesToSend[] = $currentMessage;
                $currentMessage = $factMessage;
                $currentLength = mb_strlen($factMessage);
            }
        }

        if ($currentLength + mb_strlen($end_message) <= 4096) {
            $currentMessage .= $end_message;
        } else {
            $messagesToSend[] = $currentMessage;
            $currentMessage = $start_message . $end_message;
        }

        $messagesToSend[] = $currentMessage;

        return $messagesToSend;
    }

    /**
     * Fetch recent accepted changes from database for the given tree and period in days
     *
     * @param Tree $tree
     * @param int $days
     * @return Collection<object>
     */
    public function getRecentChanges(Tree $tree, int $days): Collection
    {
        $subquery = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('status', '=', 'accepted')
            ->where('new_gedcom', '<>', '')
            ->where('change_time', '>', Registry::timestampFactory()->now()->subtractDays($days)->toDateTimeString())
            ->groupBy(['xref'])
            ->select([new Expression('MAX(change_id) AS recent_change_id')]);

        $query = DB::table('change')
            ->joinSub($subquery, 'recent', 'recent_change_id', '=', 'change_id')
            ->select(['change.*']);

        return $query
            ->get()
            ->map(function (object $row) use ($tree) {
                return (object) [
                    'record' => Registry::gedcomRecordFactory()->make($row->xref, $tree, $row->new_gedcom),
                    'time'   => Registry::timestampFactory()->fromString($row->change_time),
                    'user'   => AppHelper::get(CoreUserService::class)->find((int) $row->user_id),
                ];
            })
            ->filter(static fn (object $row): bool => $row->record !== null && $row->record->canShow());
    }

    /**
     * Build Telegram messages for recent changes, using same formatting style
     *
     * @param Collection<object> $changes
     * @param array|null $config
     * @return array<int,string>
     */
    public function generateTelegramChangesMessage(Collection $changes, array $config = null): array
    {
        // For changes notifications, ignore custom start/end and use a fixed localized header
        $start_message = "📝 <b>" . I18N::translate('Recent changes') . ":</b>\n\n";
        $start_length = mb_strlen($start_message);
        $end_message = '';
        $end_length = 0;

        // Build list by record type
        $messagesByType = [];
        foreach ($changes as $row) {
            $record = $row->record;
            $type = $record->tag();
            $label = Registry::elementFactory()->make($type)->label();
            if (!isset($messagesByType[$label])) {
                $messagesByType[$label] = "🔸 <b>{$label}</b>:\n";
            }

            $fullName = strip_tags($record->fullName());
            $link = $record->url();
            $when = strip_tags($row->time->isoFormat('LLL'));
            $who = $row->user ? e($row->user->realName()) : I18N::translate('…');

            $messagesByType[$label] .= "<a href=\"{$link}\">{$fullName}</a> — <b>{$when}</b> — {$who}.\n";
        }

        $message = $start_message;
        $messageLength = $start_length;
        foreach ($messagesByType as $chunk) {
            if ($messageLength + mb_strlen($chunk) <= 4096) {
                $message .= $chunk;
                $messageLength += mb_strlen($chunk);
            } else {
                $messagesToSend[] = $message;
                $message = $chunk;
                $messageLength = mb_strlen($chunk);
            }
        }
        $message .= $end_message;

        $messagesToSend[] = $message;

        return $messagesToSend;
    }

    /**
     * Send Telegram message
     *
     * @param string $telegramToken
     * @param string $telegramId
     * @param string $message
     * @return void
     * @throws \Exception
     */
    public function sendTelegramMessage(string $telegramToken, string $telegramId, string $message): void
    {
        $url = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
        $data = [
            'chat_id' => $telegramId,
            'text' => $message,
            'parse_mode' => 'html',
            'disable_web_page_preview' => true,
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ],
        ];

        $context = stream_context_create($options);

        try {
            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                throw new \Exception('Error sending the message');
            }

            $responseData = json_decode($response, true);

            if (!$responseData['ok']) {
                throw new \Exception('Telegram API error: ' . $responseData['description']);
            }
        } catch (\Exception $e) {
            echo "An error occurred while sending the message. Please check the correctness of the tags supported by Telegram in the custom parts of the message.";
            throw $e;
        }
    }
}
