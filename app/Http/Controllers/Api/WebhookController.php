<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Message;
use App\Models\Lead;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $type = $request->input('typeWebhook');

        // ЛОВИМ ВХОДЯЩЕЕ СООБЩЕНИЕ
        if ($type === 'incomingMessageReceived') {
            $sender = $request->input('senderData.sender'); // 79991234567@c.us
            $phone = str_replace('@c.us', '', $sender);
            $text = $request->input('messageData.textMessageData.textMessage');

            $lead = Lead::where('phone', 'like', "%$phone%")->first();

            if ($lead) {
                Message::create([
                    'lead_id' => $lead->id,
                    'text' => $text,
                    'direction' => 'in',
                    'external_id' => $request->input('idMessage')
                ]);
            }
        }

        // ЛОВИМ СТАТУС ПРОЧИТАНО
        if ($type === 'outgoingMessageStatus') {
            $idMessage = $request->input('idMessage');
            $status = $request->input('status'); // delivered, read
            
            Message::where('external_id', $idMessage)->update(['status' => $status]);
        }

        return response()->json(['status' => 'ok']);
    }
}