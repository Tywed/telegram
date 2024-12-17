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
            $message = $this->generateTelegramMessage($factList);
            $this->sendTelegramMessage($telegram_token, $telegram_id, $message);
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

    private function generateTelegramMessage(Collection $factList): string
    {
        $start_message = base64_decode($this->module->getPreference('start_message'));

        $message = !empty($start_message) ? $start_message . "\n" : "📅 <b>" . I18N::translate("Today's events:") . "</b>\n\n";

        $types = CustomOnThisDayModule::getEventLabels();
        $messages = [];

        foreach ($factList as $n => $fact) {
            $factType = $fact->tag();
            $factType = explode(":", $factType)[1] ?? $factType;
            if (array_key_exists($factType, $types)) {
                if (!isset($messages[$factType])) {
                    $messages[$factType] = "🔸 <b>{$types[$factType]}</b>:\n";
                }

                $record = $fact->record();
                $fullName = strip_tags($record->fullName());
                $link = $record->url();

                $date = strip_tags($fact->date()->display($record->tree(), null, true));

                if (PHP_INT_SIZE >= 8 || $fact->date()->gregorianYear() > 1901) {
                    $age = '(' . Registry::timestampFactory()->now()->subtractYears($fact->anniv)->diffForHumans() . ')';
                } else {
                    $age = '(' . I18N::plural('%s year', '%s years', $fact->anniv, I18N::number($fact->anniv)) . ')';
                }

                $year = $fact->date()->gregorianYear();

                $factText =  "<a href=\"$link\">$fullName</a>";

                if (!empty($date)) {
                    $factText .= " — <b>$date</b>";

                    if ($year > 0) {
                        $factText .= " <b> $age</b>";
                    }
                }
               
                if ($fact->place()->gedcomName() !== '') {
                    $placeUrl = $fact->place()->url();
                    $placeFullName = strip_tags($fact->place()->fullName());

                    $factText .= " — <a href=\"$placeUrl\">$placeFullName</a>";
                }

                $factText .= ".\n";
               
                $messages[$factType] .= $factText . "\n";
            }
        }

        foreach ($messages as $factType => $factMessage) {
            $message .= $factMessage;
        }

        $end_message = $this->module->getPreference('end_message');
        if (!empty($end_message)) {
            $message .= "\n" . base64_decode($end_message);
        }

        return $message;
    }

    private function sendTelegramMessage(string $telegramToken, string $telegramId, string $message): void
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
