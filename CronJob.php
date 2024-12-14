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
use Fisharebest\Webtrees\Individual;

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
        
        $user = $this->users->find($user);

        Auth::login($user);
        I18N::init($user->getPreference(User::PREF_LANGUAGE, 'en'));

        $startJd = Registry::timestampFactory()->now()->julianDay();
        $endJd = Registry::timestampFactory()->now()->julianDay();
        
        $tree = $this->trees->find($tree);

        $factList =  $this->todayFacts(['BIRT', 'MARR'], $startJd, $endJd, $tree);

        if ($factList->isNotEmpty()) {
            $message = $this->generateTelegramMessage($factList);
            $this->sendTelegramMessage($telegram_token, $telegram_id, $message);
        }

        Auth::logout();

        return response([
            'success' => true,
        ]);
    }

    private function todayFacts($types, $startJd, $endJd, $tree)
    {

        $facts = $this->events->getEventsList(
            $startJd,
            $endJd,
            implode(',', $types),
            true,
            'alpha',
            $tree
        )->filter(static function (Fact $fact) {
            $record = $fact->record();

            if ($record instanceof Family) {
                return $record->facts(Gedcom::DIVORCE_EVENTS)->isEmpty();
            }

            return true;
        });

        $results = $facts->map(static function (Fact $fact) use ($tree) {
            $record = $fact->record();

            if ($fact->tag() === 'INDI:BIRT') {
                if ($record instanceof Individual) {
                    $fullName = $record->fullName();
                    $birthDate = $fact->date();
                    $personLink = $record->url(); 

                    return [
                        'type' => 'BIRTHDAY',
                        'fullName' => $fullName,
                        'birthDate' => $birthDate,
                        'link' => $personLink,
                    ];
                }
            }

            if ($fact->tag() === 'FAM:MARR') {
                if ($record instanceof Family) {
                    $husband = $record->husband();  
                    $wife = $record->wife();  
                    $marriageDate = $fact->date();
                    $familyLink = $record->url(); 

                    return [
                        'type' => 'MARRIAGE',
                        'husband' => $husband->fullName(),
                        'wife' => $wife->fullName(),
                        'marriageDate' => $marriageDate,
                        'link' => $familyLink
                    ];
                }
            }

            return null;
        })->filter();

        return $results;
    }

    private function generateTelegramMessage(Collection $factList): string
    {
        $message = "🎉 <b>". I18N::translate("Today's events:") . "</b>\n\n";

        $birthdayFacts = $factList->filter(function ($fact) {
            return $fact['type'] === 'BIRTHDAY';
        });

        if ($birthdayFacts->isNotEmpty()) {
            $message .= "🎂 <b>". I18N::translate("Birthdays:") . "</b>\n";

            foreach ($birthdayFacts as $fact) {
                $fullName = strip_tags($fact['fullName']);
                $profileLink = $fact['link']; 
                $message .= "<a href=\"$profileLink\">$fullName</a>";
                if (!empty($fact['birthDate'])){
                    $year = $fact['birthDate']->gregorianYear();
                    if ($year > 0) {
                        $age = $this->age($year);
                        $message .= " <b>($year/$age)</b>\n";
                    }
                }
            }
        }

        $marriageFacts = $factList->filter(function ($fact) {
            return $fact['type'] === 'MARRIAGE';
        });

        if ($marriageFacts->isNotEmpty()) {
            $message .= "\n💍 <b>". I18N::translate("Wedding days:") . "</b>\n";

            foreach ($marriageFacts as $fact) {
                $husband =  strip_tags($fact['husband']);
                $wife =  strip_tags($fact['wife']);
                
                $familyLink = $fact['link'];

                $message .= "<a href=\"$familyLink\">$husband & $wife</a>";
                if (!empty($fact['marriageDate'])){
                    $year = $fact['marriageDate']->gregorianYear();
                    if ($year > 0) {
                        $age = $this->age($year);
                        $message .= " <b>($year/$age)</b>\n";
                    }
                }
            }
        }

        return $message;
    }

    private function age ($date) {
        $currentYear = (new \DateTime())->format('Y');
        $age = $currentYear -  $date;
        return $age;
    }

    private function sendTelegramMessage(string $telegramToken, string $telegramId, string $message): void
    {
        $url = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
        $data = [
            'chat_id' => $telegramId,
            'text' => $message,
            'parse_mode' => 'html',
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ],
        ];

        $context  = stream_context_create($options);
        file_get_contents($url, false, $context);
    }
}
