<?php

namespace Tywed\Webtrees\Module\Telegram;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\User;
use Illuminate\Support\Collection;

class CronJob implements RequestHandlerInterface
{
    protected Telegram $module;

    protected TreeService $trees;

    protected CalendarService $events;

    protected UserService $users;

    public function __construct(Telegram $module)
    {
        $this->module = $module;

        $this->trees = Registry::container()->get(TreeService::class);
        $this->events = Registry::container()->get(CalendarService::class);
        $this->users = Registry::container()->get(UserService::class);
    }

    public function handle(Request $request): Response
    {
        $telegram_token = $this->module->getPreference('telegram_token');
        $telegram_id = $this->module->getPreference('telegram_id');
        $user = $this->module->getPreference('user');
        $tree = $this->module->getPreference('tree');

        if (empty($telegram_token) || empty($telegram_id) || empty($user) || empty($tree)) {
            return response([
                'success' => false,
                'error' => 'Preferences not set',
            ]);
        }

        $startJd = Registry::timestampFactory()->now()->julianDay();
        $endJd = $startJd;

        $launch = $this->module->getPreference('launch');

        if ($startJd == $launch) {
            return response([
                'success' => false,
                'error' => 'The cron has already been launched today',
            ]);
        }

        $user = $this->users->find($user);

        Auth::login($user);
        I18N::init($user->getPreference(User::PREF_LANGUAGE, 'en'));

        $tree = $this->trees->find($tree);

        $filter    = (bool) $this->module->getPreference('filter', '1');
        $default_events = implode(',', CustomOnThisDayModule::getDefaultEvents());
        $events    = $this->module->getPreference('events', $default_events);
        $event_array = explode(',', $events);

        if ($filter) {
            $event_array = array_diff($event_array, Gedcom::DEATH_EVENTS);
        }

        $factList =  $this->todayFacts($event_array, $startJd, $endJd, $tree, $filter);

        if ($factList->isNotEmpty()) {
            $messages = $this->generateTelegramMessage($factList);

            foreach ($messages as $message) {
                $this->sendTelegramMessage($telegram_token, $telegram_id, $message);
                sleep(1);
            }
        }

        Auth::logout();

        $this->module->setPreference('launch', $startJd);

        return response([
            'success' => true,
        ]);
    }

    private function todayFacts($types, $startJd, $endJd, $tree, $filter)
    {

        $facts = $this->events->getEventsList(
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

    private function generateTelegramMessage(Collection $factList): array
    {
        $start_message = base64_decode($this->module->getPreference('start_message'));
        $start_message = !empty($start_message) ? $start_message . "\n" : "🗓 <b>" . I18N::translate("Today's events:") . "</b>\n\n";
        $start_length = mb_strlen($start_message);

        $end_message = $this->module->getPreference('end_message');
        $end_message = !empty($end_message) ? "\n" . base64_decode($end_message) : '';
        $end_length = mb_strlen($end_message);

        $location_display    = $this->module->getPreference('location_display', '2');
        $date_display    = (bool) $this->module->getPreference('date_display', '1');

        $types = CustomOnThisDayModule::getEventLabels();
        $messages = [];

        foreach ($factList as $fact) {
            $factType = explode(":", $fact->tag())[1] ?? $fact->tag();

            if (isset($types[$factType])) {
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
        }

        $message = $start_message;
        $messageLength = $start_length;

        foreach ($messages as $factMessage) {
            $message .= $factMessage;
            $messageLength += mb_strlen($factMessage);
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

    private function sendTelegramMessage(string $telegramToken, string $telegramId, string $message): void
    {
        $url = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
        $data = [
            'chat_id' => $telegramId,
            'text' => $message,
            'parse_mode' => 'html',
            'disable_web_page_preview' => true,
            'disable_notification' => (bool) $this->module->getPreference('disable_notification'),
            'protect_content' => (bool) $this->module->getPreference('protect_content'),
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ],
        ];

        $context  = stream_context_create($options);

        try {
            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                throw new \Exception('Error sending the message');
            }

            $responseData = json_decode($response, true);

            if (isset($responseData['ok']) && !$responseData['ok']) {
                throw new \Exception('Telegram API error: ' . $responseData['description']);
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
            echo "An error occurred while sending the message. Please check the correctness of the tags supported by Telegram in the custom parts of the message.";
        }
    }
}
