<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Services\GreenApiGateways;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BotController extends Controller
{
    public function __construct(private readonly GreenApiGateways $gateways)
    {
    }

    public function greenWebhook(Request $request)
    {
        $payload = $request->all();

        if ($this->envBool('BOT_DEBUG', false)) {
            Log::info('GREEN webhook HIT', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'typeWebhook' => $payload['typeWebhook'] ?? null,
                'typeMessage' => $payload['messageData']['typeMessage'] ?? null,
                'senderChatId' => $payload['senderData']['chatId'] ?? null,
                'idMessage' => $payload['idMessage'] ?? null,
            ]);
        }

        $this->handleGreenPayload($payload);

        return response()->json(['ok' => true]);
    }

    public function handleGreenPayload(array $payload, ?string $instanceId = null): void
    {
        $instanceId = trim((string) ($instanceId ?: ($payload['instanceData']['idInstance'] ?? '')));
        $gateway = $this->gateways->findByInstance($instanceId);

        if (!$gateway) {
            Log::warning('Ignored Green API webhook from an unconfigured instance', [
                'instance_id' => $instanceId ?: null,
            ]);
            return;
        }

        $instanceId = $gateway['id'];
        $gatewayManager = $this->managerForGateway($gateway);
        $in = $this->extractGreenInbound($payload);
        if (!$in) return;

        $chatId = $in['chat_id'];
        $phone  = $in['phone'];
        $text   = $in['text'];
        $msgId  = $in['msg_id'];

        // 1) Dedupe
        $dedupeKey = "green:dedupe:{$instanceId}:{$msgId}";
        try {
            if (Cache::get($dedupeKey)) return;
            Cache::put($dedupeKey, 1, now()->addHours(6));
        } catch (\Throwable $e) {
            Log::warning('Cache dedupe failed', ['err' => $e->getMessage()]);
        }

        // 2) Upsert lead всегда
        $lead = null;
        try {
            $lead = $this->findOrCreateLeadByPhone(
                $phone,
                $in['sender_name'] ?? '',
                'whatsapp',
                $text,
                $instanceId,
                $gatewayManager
            );
            $this->appendLeadLog($lead, "USER: {$text}");
        } catch (\Throwable $e) {
            Log::error('Lead upsert failed', ['err' => $e->getMessage(), 'phone' => $phone]);
        }

        // 3) PING-only
        if ($this->envBool('BOT_PING_ONLY', false)) {
            $reply = '✅ PING: бот отвечает';
            $this->sendGreenText($chatId, $reply, $instanceId);
            if ($lead) $this->appendLeadLog($lead, "BOT: {$reply}");
            return;
        }

        $isCustomer = $lead ? $this->isCustomerLead($lead) : false;

        // 4) Основной сценарий
        try {
            $history = $this->appendConversationSafe($phone, 'user', $text, $instanceId);

            $ai = $this->callAi($history, [
                'phone' => $phone,
                'is_customer' => $isCustomer,
                'lead_status' => $lead->status ?? '',
                'client_name' => $lead->client_name ?? '',
                'lead_title'  => $lead->title ?? '',
            ]);

            $reply = trim((string)($ai['reply'] ?? ''));
            if ($reply === '') $reply = $this->fallbackReplyFor($this->detectLang($text), $isCustomer);

            $this->sendGreenText($chatId, $reply, $instanceId);
            $this->appendConversationSafe($phone, 'assistant', $reply, $instanceId);
            if ($lead) $this->appendLeadLog($lead, "BOT: {$reply}");

            $qualified = (bool)($ai['qualified'] ?? false);
            $leadData  = is_array($ai['lead'] ?? null) ? $ai['lead'] : [];

            // 5) Сохраняем данные в leads
            if ($lead) $this->applyAiToLead($lead, $leadData, $qualified, $isCustomer);

            // 6) Если qualified и это НЕ customer — назначаем менеджера и создаём сделку
            if ($qualified && $lead && !$isCustomer) {
                $manager = $gatewayManager ?: $lead->manager ?: $this->pickOnlineManagerSafe();

                if ($manager) {
                    if ($this->hasColumn('leads', 'user_id') && empty($lead->user_id)) {
                        $lead->user_id = $manager->id;
                    }
                    if ($this->hasColumn('leads', 'status')) $lead->status = 'hot';
                    if ($this->hasColumn('leads', 'next_action_at') && empty($lead->next_action_at)) {
                        $lead->next_action_at = Carbon::now()->addHours(2);
                    }
                    $lead->save();

                    $dealId = $this->createDealIfPossible($lead, $manager, $leadData);
                    $this->notifyManagerTelegramSafe($manager, $lead, $dealId);
                    $this->appendLeadLog($lead, 'SYSTEM: qualified=true, manager assigned' . ($dealId ? ", deal={$dealId}" : ""));
                } else {
                    Log::warning('No online manager found', ['lead_id' => $lead->id, 'phone' => $phone]);
                    $this->appendLeadLog($lead, 'SYSTEM: qualified=true, но менеджер не найден online');
                }
            }

            return;
        } catch (\Throwable $e) {
            Log::error('Green webhook error', [
                'err' => $e->getMessage(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
                'chatId' => $chatId,
                'phone' => $phone,
            ]);

            try {
                $fallback = 'Извините, сейчас небольшая пауза. Повторите, пожалуйста.';
                $this->sendGreenText($chatId, $fallback, $instanceId);
                if ($lead) $this->appendLeadLog($lead, "BOT: {$fallback}");
            } catch (\Throwable $ignored) {}
        }
    }

    // -------------------------
    // Inbound parsing
    // -------------------------
    private function extractGreenInbound(array $payload): ?array
    {
        $typeWebhook = strtolower((string)($payload['typeWebhook'] ?? ''));
        if ($typeWebhook !== 'incomingmessagereceived') return null;

        $msgId = (string)($payload['idMessage'] ?? '');
        if ($msgId === '') return null;

        $chatId = (string)($payload['senderData']['chatId'] ?? '');
        if ($chatId === '') return null;

        if (str_ends_with($chatId, '@g.us')) return null;

        $messageData = $payload['messageData'] ?? [];
        $typeMessage = (string)($messageData['typeMessage'] ?? '');

        $text = '';
        if ($typeMessage === 'textMessage') {
            $text = (string)($messageData['textMessageData']['textMessage'] ?? '');
        } elseif ($typeMessage === 'extendedTextMessage') {
            $text = (string)($messageData['extendedTextMessageData']['text'] ?? '');
        } else {
            return null;
        }

        $text = trim($text);
        if ($text === '') return null;

        $phone = preg_replace('/\D+/', '', $chatId);
        if ($phone === '') return null;

        $senderName = (string)($payload['senderData']['senderName'] ?? '');
        $senderContactName = (string)($payload['senderData']['senderContactName'] ?? '');
        $finalName = trim($senderContactName !== '' ? $senderContactName : $senderName);

        return [
            'msg_id' => $msgId,
            'text' => $text,
            'chat_id' => $chatId,
            'phone' => $phone,
            'sender_name' => $finalName,
        ];
    }

    // -------------------------
    // Conversation cache (SAFE)
    // -------------------------
    private function convKey(string $phone, string $instanceId): string
    {
        return "green:conv:{$instanceId}:" . preg_replace('/\D+/', '', $phone);
    }

    private function getConversationSafe(string $phone, string $instanceId): array
    {
        try {
            $h = Cache::get($this->convKey($phone, $instanceId), []);
            return is_array($h) ? $h : [];
        } catch (\Throwable $e) {
            Log::warning('Cache get conversation failed', ['err' => $e->getMessage()]);
            return [];
        }
    }

    private function appendConversationSafe(string $phone, string $role, string $content, string $instanceId): array
    {
        $history = $this->getConversationSafe($phone, $instanceId);

        $history[] = ['role' => $role, 'content' => $content];

        $max = (int)env('AI_MAX_HISTORY', 16);
        if (count($history) > $max) $history = array_slice($history, -$max);

        try {
            Cache::put($this->convKey($phone, $instanceId), $history, now()->addHours(24));
        } catch (\Throwable $e) {
            Log::warning('Cache put conversation failed', ['err' => $e->getMessage()]);
        }

        return $history;
    }

    // -------------------------
    // Lead upsert + log
    // -------------------------
    private function findOrCreateLeadByPhone(
        string $phone,
        string $clientName = '',
        string $source = 'whatsapp',
        string $initialText = '',
        string $instanceId = '',
        ?User $manager = null
    ): Lead {
        $lead = null;

        if ($this->hasColumn('leads', 'phone')) {
            $query = Lead::query()->where('phone', $phone);
            if ($instanceId !== '' && $this->hasColumn('leads', 'green_api_instance_id')) {
                $query->where('green_api_instance_id', $instanceId);
            }
            $lead = $query->latest('id')->first();

            // Adopt an existing pre-migration lead only when it already belongs
            // to the manager linked to this gateway.
            if (!$lead && $instanceId !== '' && $manager && $this->hasColumn('leads', 'green_api_instance_id')) {
                $lead = Lead::query()
                    ->where('phone', $phone)
                    ->whereNull('green_api_instance_id')
                    ->where('user_id', $manager->id)
                    ->latest('id')
                    ->first();
            }
        }

        if (!$lead) $lead = new Lead();

        if ($this->hasColumn('leads', 'phone')) $lead->phone = $phone;
        if ($instanceId !== '' && $this->hasColumn('leads', 'green_api_instance_id')) {
            $lead->green_api_instance_id = $instanceId;
        }
        if ($manager && $this->hasColumn('leads', 'user_id') && empty($lead->user_id)) {
            $lead->user_id = $manager->id;
        }

        if ($clientName !== '') {
            if ($this->hasColumn('leads', 'client_name') && empty($lead->client_name)) {
                $lead->client_name = $clientName;
            }
        }

        if ($this->hasColumn('leads', 'source') && empty($lead->source)) {
            $lead->source = $source;
        }

        if ($this->hasColumn('leads', 'status') && empty($lead->status)) {
            $lead->status = 'new';
        }

        if ($this->hasColumn('leads', 'title')) {
            $title = $this->leadTitleFromMessage($initialText, $clientName);
            if (empty($lead->title) || $this->isPlaceholderTitle((string) $lead->title)) {
                $lead->title = $title;
            }
        }

        $lead->save();
        return $lead;
    }

    private function leadTitleFromMessage(string $text, string $clientName = ''): string
    {
        $text = trim($text);
        if ($text !== '') {
            return mb_strimwidth($text, 0, 50, '...');
        }

        $clientName = trim($clientName);
        if ($clientName !== '') {
            return 'Обращение: ' . mb_strimwidth($clientName, 0, 40, '...');
        }

        return 'Заявка из WhatsApp';
    }

    private function isPlaceholderTitle(string $title): bool
    {
        return in_array($title, ['WhatsApp lead', 'Заявка из WhatsApp'], true);
    }

    private function appendLeadLog(Lead $lead, string $line): void
    {
        $field = null;
        if ($this->hasColumn('leads', 'description')) $field = 'description';
        elseif ($this->hasColumn('leads', 'comment')) $field = 'comment';

        if ($field === null) return;

        $ts = now()->format('Y-m-d H:i:s');
        $entry = "[{$ts}] {$line}";

        $current = (string)($lead->{$field} ?? '');
        $next = trim($current . "\n" . $entry);

        $maxLen = (int)env('LEAD_DESCRIPTION_MAX_LEN', 12000);
        if (mb_strlen($next) > $maxLen) $next = mb_substr($next, -$maxLen);

        $lead->{$field} = $next;
        $lead->save();
    }

    private function applyAiToLead(Lead $lead, array $leadData, bool $qualified, bool $isCustomer): void
    {
        // name -> client_name
        $name = trim((string)($leadData['name'] ?? ''));
        if ($name !== '' && $this->hasColumn('leads', 'client_name')) {
            $lead->client_name = $name;
        }

        // need -> title (НЕ перезатираем у customer, если там уже хранится “купленный авто/статус”)
        $need = trim((string)($leadData['need'] ?? ''));
        if ($need !== '' && $this->hasColumn('leads', 'title')) {
            $canUpdateTitle = !$isCustomer || empty($lead->title) || $this->isPlaceholderTitle((string) $lead->title);
            if ($canUpdateTitle) $lead->title = $need;
        }

        // budget -> price
        $budget = trim((string)($leadData['budget'] ?? ''));
        if ($budget !== '' && $this->hasColumn('leads', 'price')) {
            $price = $this->parseBudgetToNumber($budget);
            if ($price !== null) $lead->price = $price;
        }

        // city/comment -> в историю
        $city = trim((string)($leadData['city'] ?? ''));
        if ($city !== '') $this->appendLeadLog($lead, "AI: city={$city}");

        $comment = trim((string)($leadData['comment'] ?? ''));
        if ($comment !== '') $this->appendLeadLog($lead, "AI: {$comment}");

        // email из comment
        if ($this->hasColumn('leads', 'email') && empty($lead->email)) {
            $email = $this->extractEmail($comment);
            if ($email) $lead->email = $email;
        }

        // status + next_action_at
        if ($isCustomer) {
            // customer статус не трогаем, только если он пустой — выставим customer
            if ($this->hasColumn('leads', 'status') && empty($lead->status)) $lead->status = 'customer';

            if ($this->hasColumn('leads', 'next_action_at')) {
                // послепродажи: мягкий follow-up через 7 дней по умолчанию
                if (empty($lead->next_action_at)) $lead->next_action_at = Carbon::now()->addDays(7)->setTime(12, 0);
            }
        } else {
            $segment = $this->segmentFromComment($comment, $qualified); // HOT/WARM/COLD
            if ($this->hasColumn('leads', 'status')) $lead->status = strtolower($segment);
            if ($this->hasColumn('leads', 'next_action_at')) $lead->next_action_at = $this->nextActionAtForSegment($segment);
        }

        if ($this->hasColumn('leads', 'source') && empty($lead->source)) {
            $lead->source = 'whatsapp';
        }

        $lead->save();
    }

    private function isCustomerLead(Lead $lead): bool
    {
        $status = strtolower((string)($lead->status ?? ''));
        $list = $this->customerStatuses();
        return in_array($status, $list, true);
    }

    private function customerStatuses(): array
    {
        $raw = trim((string)env('CUSTOMER_STATUSES', 'customer,won,sold'));
        $parts = array_filter(array_map('trim', explode(',', strtolower($raw))));
        return $parts ?: ['customer', 'won', 'sold'];
    }

    private function extractEmail(string $text): ?string
    {
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m)) {
            return strtolower($m[0]);
        }
        return null;
    }

    private function segmentFromComment(string $comment, bool $qualified): string
    {
        if ($qualified) return 'HOT';

        if (preg_match('/\bSEGMENT\s*=\s*(HOT|WARM|COLD)\b/i', $comment, $m)) {
            return strtoupper($m[1]);
        }

        return 'WARM';
    }

    private function nextActionAtForSegment(string $segment): Carbon
    {
        $segment = strtoupper($segment);

        if ($segment === 'HOT') return Carbon::now()->addHours(2);
        if ($segment === 'COLD') return Carbon::now()->addDays(3)->setTime(12, 0);

        return Carbon::now()->addDay()->setTime(12, 0);
    }

    private function parseBudgetToNumber(string $raw): ?float
    {
        $s = preg_replace('/[^\d\.,]/u', '', $raw);
        $s = str_replace(' ', '', $s);
        if ($s === '') return null;

        if (str_contains($s, ',') && !str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        }

        if (!is_numeric($s)) return null;
        return (float)$s;
    }

    // -------------------------
    // AI (МФПО: лиды на обучение + поддержка слушателей)
    // -------------------------
    private function callAi(array $history, array $context = []): array
    {
        $isCustomer = (bool)($context['is_customer'] ?? false);
        $clientName = trim((string)($context['client_name'] ?? ''));
        $leadTitle  = trim((string)($context['lead_title'] ?? ''));

        $systemBase = <<<'SYS'
Ты — консультант Международного фонда по продвижению образования в СНГ (МФПО). Помогаешь педагогам и организациям с выбором обучения: повышение квалификации, переподготовка педагогических кадров, языковая подготовка, профессиональная подготовка (512 часов) для дошкольных организаций, школ, ТиПО и ВУЗов.

Важное:
- Не обещай точные цены/даты/наличие мест, если не уверен — говори “уточню и вернусь”.
- Пиши коротко: 2–6 предложений.
- Вежливо, на «Вы». 1–3 вопроса за сообщение.
- Всегда предлагай следующий шаг с выбором: консультация в WhatsApp / звонок 10–15 минут / помощь с записью на курс / отправка программы курса.

Язык:
- Отвечай на языке клиента: русский или қазақша.
- Если язык неочевиден — начни на русском и в конце одной фразой предложи қазақша.

Сегментация для лидов (пиши первой строкой в lead.comment):
- SEGMENT=HOT  -> клиент готов записаться/оплатить/оставляет контакты/просит счёт или программу для оформления
- SEGMENT=WARM -> интерес есть, выбирает курс/уточняет, но не готов фиксировать шаг
- SEGMENT=COLD -> нет конкретного запроса (“привет”, “что есть?” без конкретики и уходит от вопросов)

Квалификация:
qualified=true только если клиент явно готов к шагу (записаться/оплатить/просит договор/счёт/программу, оставляет контакты, согласен на звонок).
Иначе qualified=false.

Правила данных:
- Не выдумывай факты. Нет данных — пустые строки.
- lead.need — 3–10 слов (какой курс/направление интересует).
- lead.budget — если называл бюджет/способ оплаты/количество слушателей.
- lead.comment — важные детали: направление/категория педагога (дошкольное/школа/ТиПО/ВУЗ)/курс/сроки/город/организация/контакт/удобное время.
Формат:
Верни ровно один JSON без текста вокруг:
{"reply":"...","qualified":false,"lead":{"name":"","need":"","city":"","budget":"","comment":""}}
SYS;

        $system = $systemBase;

        if ($isCustomer) {
            $system .= "\n\n";
            $system .= "КОНТЕКСТ: это действующий слушатель (уже проходил обучение у нас). Твоя задача — поддержка и предложение новых программ (повышение квалификации, новые направления, переподготовка) без навязчивости.\n";
            $system .= "- Сначала уточни, всё ли в порядке с обучением/сертификатом и что актуально сейчас.\n";
            $system .= "- Обязательно спроси 1–2 уточнения: категория педагога/направление и удобное время.\n";
            $system .= "- qualified=true если клиент готов записаться/оплатить новый курс.\n";
            $system .= "- В lead.comment первой строкой пиши CUSTOMER_STAGE=UPSELL|SUPPORT|INFO.\n";
        } else {
            $system .= "\n\n";
            $system .= "КОНТЕКСТ: это новый/активный лид на обучение.\n";
            $system .= "- Собери минимум: имя, категория (дошкольное/школа/ТиПО/ВУЗ или организация), интересующее направление/курс, город, сроки, количество слушателей.\n";
            $system .= "- Предложи шаг: звонок 10–15 минут или отправку программы курса.\n";
        }

        if ($clientName !== '') {
            $system .= "\nИзвестное имя клиента: {$clientName}. Если уместно — обращайся по имени.\n";
        }
        if ($leadTitle !== '') {
            $system .= "Текущий запрос/заметка по лиду (title): {$leadTitle}.\n";
        }

        $messages = array_merge([['role' => 'system', 'content' => $system]], $history);

        $openai = trim((string)env('OPENAI_API_KEY', ''));
        if ($openai === '') return $this->fallbackAi($this->detectLang(end($history)['content'] ?? ''), $isCustomer);

        $verify = $this->envBool('OPENAI_VERIFY_SSL', true);

        try {
            $resp = Http::timeout((int)env('AI_TIMEOUT', 10))
                ->withOptions(['verify' => $verify])
                ->withToken($openai)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                    'temperature' => 0.4,
                    'messages' => $messages,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if ($resp->ok()) {
                $data = $resp->json();
                $content = (string)($data['choices'][0]['message']['content'] ?? '{}');
                return $this->safeJson($content);
            }

            Log::warning('OpenAI bad response', ['status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 800)]);
            return $this->fallbackAi($this->detectLang(end($history)['content'] ?? ''), $isCustomer);
        } catch (\Throwable $e) {
            Log::warning('OpenAI request failed', ['err' => $e->getMessage(), 'verify_ssl' => $verify]);
            return $this->fallbackAi($this->detectLang(end($history)['content'] ?? ''), $isCustomer);
        }
    }

    private function fallbackAi(string $lang = 'ru', bool $isCustomer = false): array
    {
        return [
            'reply' => $this->fallbackReplyFor($lang, $isCustomer),
            'qualified' => false,
            'lead' => ['name' => '', 'need' => '', 'city' => '', 'budget' => '', 'comment' => $isCustomer ? 'CUSTOMER_STAGE=INFO' : 'SEGMENT=WARM'],
        ];
    }

    private function safeJson(string $raw): array
    {
        $raw = trim($raw);

        if (!str_starts_with($raw, '{')) {
            $s = strpos($raw, '{');
            $e = strrpos($raw, '}');
            if ($s !== false && $e !== false && $e > $s) $raw = substr($raw, $s, $e - $s + 1);
        }

        $d = json_decode($raw, true);
        if (!is_array($d)) {
            Log::warning('AI invalid JSON', ['raw' => mb_substr($raw, 0, 800)]);
            return $this->fallbackAi('ru', false);
        }

        return [
            'reply' => (string)($d['reply'] ?? ''),
            'qualified' => (bool)($d['qualified'] ?? false),
            'lead' => is_array($d['lead'] ?? null)
                ? $d['lead']
                : ['name' => '', 'need' => '', 'city' => '', 'budget' => '', 'comment' => ''],
        ];
    }

    // -------------------------
    // Language + fallback reply
    // -------------------------
    private function detectLang(string $text): string
    {
        $t = mb_strtolower((string)$text);
        if (preg_match('/[әғқңөұүіһ]/u', $t)) return 'kz';
        if (preg_match('/[а-яё]/u', $t)) return 'ru';
        return 'ru';
    }

    private function fallbackReplyFor(string $lang, bool $isCustomer): string
    {
        if ($lang === 'kz') {
            if ($isCustomer) {
                return 'Сәлем! Оқуыңыз қалай өтті, сертификат алдыңыз ба? Қазір қандай бағыт қызықтырады: біліктілікті арттыру, қайта даярлау немесе тіл курстары? Категорияңызды (мектепке дейінгі/мектеп/ТжКБ/ЖОО) айта аласыз ба?';
            }
            return 'Сәлем! МФПО білім беру қоры. Қандай курс іздеп жүрсіз: біліктілікті арттыру, қайта даярлау, тіл курстары? Категорияңыз қандай (мектепке дейінгі/мектеп/ТжКБ/ЖОО)?';
        }

        if ($isCustomer) {
            return 'Здравствуйте! Как прошло Ваше обучение, получили сертификат? Сейчас что актуально: повышение квалификации, переподготовка или языковые курсы? Подскажите Вашу категорию (дошкольное/школа/ТиПО/ВУЗ), пожалуйста.';
        }

        return 'Здравствуйте! Международный фонд по продвижению образования (МФПО). Подскажите, какой курс рассматриваете: повышение квалификации, переподготовка или языковая подготовка? Какая у Вас категория (дошкольное/школа/ТиПО/ВУЗ)?';
    }

    // -------------------------
    // GreenAPI send
    // -------------------------
    private function sendGreenText(string $chatId, string $text, string $instanceId): void
    {
        if ($this->envBool('WHATSAPP_DRY_RUN', false)) {
            Log::info('DRY_RUN sendGreenText', ['chatId' => $chatId, 'text' => $text]);
            return;
        }

        $gateway = $this->gateways->findByInstance($instanceId);
        if (!$gateway) {
            Log::warning('Green API instance not configured for reply', ['instance_id' => $instanceId]);
            return;
        }

        $token = $gateway['token'];
        $base = $gateway['host'];

        $verify = $this->envBool('GREENAPI_VERIFY_SSL', true);

        $url = "{$base}/waInstance{$instanceId}/sendMessage/{$token}";

        try {
            $resp = Http::timeout(10)
                ->withOptions(['verify' => $verify])
                ->acceptJson()
                ->post($url, ['chatId' => $chatId, 'message' => $text]);

            if ($this->envBool('BOT_DEBUG', false)) {
                Log::info('GreenAPI sendMessage', [
                    'verify' => $verify,
                    'status' => $resp->status(),
                    'chatId' => $chatId,
                    'body' => mb_substr($resp->body(), 0, 800),
                ]);
            }

            if (!$resp->ok()) {
                Log::warning('GreenAPI sendMessage failed', ['status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 800)]);
            }
        } catch (\Throwable $e) {
            Log::error('GreenAPI HTTP exception', ['verify' => $verify, 'url' => $url, 'chatId' => $chatId, 'err' => $e->getMessage()]);
        }
    }

    // -------------------------
    // Deal/Manager/TG
    // -------------------------
    private function createDealIfPossible(Lead $lead, ?User $manager, array $leadData): ?int
    {
        $need = trim((string)($leadData['need'] ?? ''));
        $title = 'WhatsApp: ' . ($need !== '' ? $need : ((string)($lead->title ?? 'lead')));
        $dueAt = Carbon::now()->endOfDay();

        if (class_exists(\App\Models\Deal::class)) {
            try {
                $dealClass = \App\Models\Deal::class;
                $deal = new $dealClass();

                if ($this->hasColumn('deals', 'lead_id')) $deal->lead_id = $lead->id;
                if ($manager && $this->hasColumn('deals', 'manager_id')) $deal->manager_id = $manager->id;
                if ($this->hasColumn('deals', 'title')) $deal->title = $title;
                if ($this->hasColumn('deals', 'status')) $deal->status = 'open';
                if ($this->hasColumn('deals', 'source')) $deal->source = 'whatsapp';
                if ($this->hasColumn('deals', 'due_at')) $deal->due_at = $dueAt;

                $deal->save();
                return (int)$deal->id;
            } catch (\Throwable $e) {
                Log::warning('Deal model create failed', ['err' => $e->getMessage()]);
            }
        }

        if ($this->hasTable('deals')) {
            try {
                $data = [];
                if ($this->hasColumn('deals', 'lead_id')) $data['lead_id'] = $lead->id;
                if ($manager && $this->hasColumn('deals', 'manager_id')) $data['manager_id'] = $manager->id;
                if ($this->hasColumn('deals', 'title')) $data['title'] = $title;
                if ($this->hasColumn('deals', 'status')) $data['status'] = 'open';
                if ($this->hasColumn('deals', 'source')) $data['source'] = 'whatsapp';
                if ($this->hasColumn('deals', 'due_at')) $data['due_at'] = $dueAt;
                if ($this->hasColumn('deals', 'created_at')) $data['created_at'] = now();
                if ($this->hasColumn('deals', 'updated_at')) $data['updated_at'] = now();

                if (!empty($data)) return (int)DB::table('deals')->insertGetId($data);
            } catch (\Throwable $e) {
                Log::warning('Deal table insert failed', ['err' => $e->getMessage()]);
            }
        }

        return null;
    }

    private function pickOnlineManagerSafe(): ?User
    {
        $onlineSec = (int)env('MANAGER_ONLINE_WINDOW_SEC', 120);
        $since = Carbon::now()->subSeconds($onlineSec);

        $q = User::query();
        if ($this->hasColumn('users', 'role')) $q->where('role', 'manager');

        if ($this->hasColumn('users', 'last_seen_at')) {
            $q->where('last_seen_at', '>=', $since)->orderByDesc('last_seen_at');
        } else {
            $q->orderByDesc('id');
        }

        return $q->first();
    }

    /** @param array{manager_email: string} $gateway */
    private function managerForGateway(array $gateway): ?User
    {
        $email = $gateway['manager_email'];
        if ($email === '') return null;

        return User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    private function notifyManagerTelegramSafe(User $manager, Lead $lead, ?int $dealId): void
    {
        $token = trim((string)env('TELEGRAM_BOT_TOKEN', ''));
        if ($token === '') return;

        $chatId = $this->hasColumn('users', 'telegram_chat_id') ? ($manager->telegram_chat_id ?? null) : null;
        if (empty($chatId)) return;

        $phone = (string)($lead->phone ?? '');
        $name  = $this->hasColumn('leads', 'client_name') ? (string)($lead->client_name ?? '') : '';
        $title = $this->hasColumn('leads', 'title') ? (string)($lead->title ?? '') : '';

        $text = "⚡️ Новый лид!\n"
            . "Лид #{$lead->id}\n"
            . ($dealId ? "Сделка #{$dealId}\n" : "")
            . ($phone ? "Тел: {$phone}\n" : "")
            . ($name ? "Имя: {$name}\n" : "")
            . ($title ? "Запрос: {$title}\n" : "");

        Http::timeout(10)->acceptJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);
    }

    // -------------------------
    // Helpers
    // -------------------------
    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . ':' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];
        return $cache[$key] = Schema::hasColumn($table, $column);
    }

    private function hasTable(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) return $cache[$table];
        return $cache[$key = $table] = Schema::hasTable($table);
    }

    private function envBool(string $key, bool $default = false): bool
    {
        $val = env($key);
        if ($val === null) return $default;
        $parsed = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed === null ? $default : $parsed;
    }
}
