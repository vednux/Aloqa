<?php

/**
 * TELEGRAM FEEDBACK BOT (PHP + JSON)
 * 
 * Token: 8147426386:AAEirksVeUQjI3gV1w8qC5psgbFQczBYyOk
 * Admin ID: 8414409772
 */

// ==================== CONFIG ====================
define('BOT_TOKEN', '8147426386:AAEirksVeUQjI3gV1w8qC5psgbFQczBYyOk');
define('ADMIN_ID',  8414409772);
define('API_URL',   'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('DATA_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'data.json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot_error.log');

// ==================== JSON HELPERS ====================
function loadData(): array {
    $empty = ['users' => [], 'pending_replies' => [], 'delete_queue' => []];
    if (!file_exists(DATA_FILE)) {
        saveData($empty);
        return $empty;
    }
    $raw = @file_get_contents(DATA_FILE);
    if ($raw === false) return $empty;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $empty;
}

function saveData(array $data): void {
    @file_put_contents(
        DATA_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

// ==================== ADMIN PANEL API ====================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $data = loadData();
    
    // API logic
    switch ($_GET['api']) {
        case 'get_stats':
            $res = ['total' => count($data['users']), 'blocked' => 0, 'list' => []];
            foreach ($data['users'] as $id => $u) {
                if (!empty($u['is_blocked'])) $res['blocked']++;
                $res['list'][] = [
                    'id' => $id,
                    'name' => $u['first_name'] ?? 'Noma\'lum',
                    'username' => $u['username'] ?? '',
                    'blocked' => !empty($u['is_blocked'])
                ];
            }
            echo json_encode($res);
            break;
            
        case 'toggle_block':
            $input = json_decode(file_get_contents('php://input'), true);
            $uId = (string)($input['user_id'] ?? '');
            if ($uId && isset($data['users'][$uId])) {
                $newStatus = !($data['users'][$uId]['is_blocked'] ?? false);
                $data['users'][$uId]['is_blocked'] = $newStatus;
                saveData($data);
                if ($newStatus) sendMessage((int)$uId, "<tg-emoji emoji-id='5447644880824181073'>⚠️</tg-emoji> <b>Siz botdan bloklandingiz!</b>");
                echo json_encode(['ok' => true, 'blocked' => $newStatus]);
            } else { echo json_encode(['ok' => false]); }
            break;
            
        case 'broadcast':
            $input = json_decode(file_get_contents('php://input'), true);
            $text = $input['text'] ?? '';
            if ($text) {
                $count = 0;
                foreach ($data['users'] as $uId => $u) {
                    if (empty($u['is_blocked'])) {
                        $sent = sendMessage((int)$uId, "📢 <b>Xabar:</b>\n\n" . $text);
                        if ($sent && $sent['ok']) $count++;
                    }
                }
                echo json_encode(['ok' => true, 'count' => $count]);
            } else { echo json_encode(['ok' => false]); }
            break;
    }
    exit;
}

// ==================== TELEGRAM API ====================
function api(string $method, array $params = []): array {
    if (!function_exists('curl_init')) return [];
    $ch = curl_init(API_URL . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) { error_log("cURL Error: " . $err); return []; }
    return json_decode($res, true) ?? [];
}

function sendMessage(int $chatId, string $text, array $extra = []): array {
    return api('sendMessage', array_merge([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ], $extra));
}

function forwardMessage(int $chatId, int $fromChatId, int $messageId): array {
    return api('forwardMessage', [
        'chat_id'      => $chatId,
        'from_chat_id' => $fromChatId,
        'message_id'   => $messageId,
    ]);
}

function deleteMsg(int $chatId, int $msgId): void {
    api('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
}

// ==================== DELETE QUEUE ====================
function queueDelete(int $chatId, int $msgId, int $afterSec = 5): void {
    if ($msgId <= 0) return;
    $data = loadData();
    $data['delete_queue'][] = [
        'chat_id'    => $chatId,
        'message_id' => $msgId,
        'delete_at'  => time() + $afterSec,
    ];
    saveData($data);
}

function processQueue(): void {
    $data = loadData();
    if (empty($data['delete_queue'])) return;
    $keep = [];
    $now = time();
    foreach ($data['delete_queue'] as $item) {
        if ($now >= (int)$item['delete_at']) {
            deleteMsg((int)$item['chat_id'], (int)$item['message_id']);
        } else {
            $keep[] = $item;
        }
    }
    $data['delete_queue'] = $keep;
    saveData($data);
}

// ==================== WEBHOOK INPUT ====================
$rawInput = file_get_contents('php://input');
if (!$rawInput) {
    echo "Bot is running. Webhook info:<br>";
    echo "<pre>" . json_encode(api('getWebhookInfo'), JSON_PRETTY_PRINT) . "</pre>";
    exit;
}

$update = json_decode($rawInput, true);
if (!$update) exit;

processQueue();

// ==================== CALLBACKS ====================
if (isset($update['callback_query'])) {
    $cb     = $update['callback_query'];
    $fromId = (int)$cb['from']['id'];
    $cbData = $cb['data'] ?? '';

    api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

    $data = loadData();

    // Javob berish (Reply)
    if (strpos($cbData, 'reply_') === 0) {
        $targetUser = (int)substr($cbData, 6);
        $data['pending_replies'][(string)ADMIN_ID] = ['type' => 'reply', 'target' => $targetUser];
        saveData($data);
        sendMessage(ADMIN_ID, "✏️ <b>Xabarni yozing:</b>\nFoydalanuvchiga javob yuboriladi.");
        exit;
    }

    // Tushunarsiz (Unclear)
    if (strpos($cbData, 'unclear_') === 0) {
        $targetUser = (int)substr($cbData, 8);
        sendMessage($targetUser, "<blockquote>🗒 <i>Iltimos qaytadan yozing, tushunarsiz gap yoki so'z!</i></blockquote>");
        $confirm = sendMessage(ADMIN_ID, "<tg-emoji emoji-id='5427009714745517609'>✅</tg-emoji> <i>Foydalanuvchiga \"tushunarsiz\" xabari yuborildi.</i>");
        queueDelete(ADMIN_ID, (int)($confirm['result']['message_id'] ?? 0), 4);
        exit;
    }

    // Bloklash (Block) — added via WebApp logic normally, but we handle it here if needed
    if (strpos($cbData, 'block_') === 0) {
        $targetUser = (int)substr($cbData, 6);
        $data['users'][$targetUser]['is_blocked'] = true;
        saveData($data);
        sendMessage($targetUser, "<tg-emoji emoji-id='5447644880824181073'>⚠️</tg-emoji> <b>Siz botdan bloklandingiz!</b>");
        $confirm = sendMessage(ADMIN_ID, "🚫 <i>Foydalanuvchi bloklandi.</i>");
        queueDelete(ADMIN_ID, (int)($confirm['result']['message_id'] ?? 0), 4);
        exit;
    }

    // Bloklash (Block)
    if (strpos($cbData, 'block_') === 0) {
        $targetUser = (int)substr($cbData, 6);
        $data['users'][$targetUser]['is_blocked'] = true;
        saveData($data);
        sendMessage($targetUser, "<tg-emoji emoji-id='5447644880824181073'>⚠️</tg-emoji> <b>Siz botdan bloklandingiz!</b>");
        $confirm = sendMessage(ADMIN_ID, "🚫 <i>Foydalanuvchi bloklandi.</i>");
        queueDelete(ADMIN_ID, (int)($confirm['result']['message_id'] ?? 0), 4);
        exit;
    }

    // Broadcast (Forward)
    if ($cbData === 'broadcast') {
        $data['pending_replies'][(string)ADMIN_ID] = ['type' => 'broadcast'];
        saveData($data);
        sendMessage(ADMIN_ID, "📢 <b>Xabar yuboring (Forward bo'ladi)</b> — barcha foydalanuvchilarga yuboriladi:");
        exit;
    }

    exit;
}

// ==================== MESSAGES ====================
if (!isset($update['message'])) exit;

$msg       = $update['message'];
$msgId     = (int)$msg['message_id'];
$fromId    = (int)$msg['from']['id'];
$text      = isset($msg['text']) ? trim($msg['text']) : '';
$username  = isset($msg['from']['username']) ? $msg['from']['username'] : null;
$firstName = htmlspecialchars($msg['from']['first_name'] ?? 'Foydalanuvchi');

$data = loadData();

// Migration: Convert old array format [id1, id2] to [id1 => [], id2 => []]
if (isset($data['users']) && !empty($data['users']) && isset($data['users'][0])) {
    $newUsers = [];
    foreach ($data['users'] as $u) {
        if (is_numeric($u)) $newUsers[(string)$u] = ['joined_at' => time()];
    }
    $data['users'] = $newUsers;
    saveData($data);
}

// Register / Update User
if (!isset($data['users'][$fromId]) || !is_array($data['users'][$fromId])) {
    $data['users'][$fromId] = ['joined_at' => time()];
}
$data['users'][$fromId]['username'] = $username;
$data['users'][$fromId]['first_name'] = $firstName;
saveData($data);

// Blocked Check
if ($fromId !== ADMIN_ID && isset($data['users'][$fromId]['is_blocked']) && $data['users'][$fromId]['is_blocked']) {
    sendMessage($fromId, "<tg-emoji emoji-id='5447644880824181073'>⚠️</tg-emoji> <b>Siz botdan bloklangansiz!</b>");
    exit;
}

// /start
if ($text === '/start') {
    sendMessage($fromId,
        "<tg-emoji emoji-id='5472055112702629499'>👋</tg-emoji> <b>Assalomu alaykum!</b>\n\n" .
        "<b>Agar sizda Ramiziddin <tg-emoji emoji-id='5084979757905347540'>👑</tg-emoji> (@Ramizvip) bilan bog'lanishda qiyinchilik tug'ilgan bo'lsa, barcha murojaatlaringizni shu yerda qoldirishingiz mumkin.</b>\n\n" .
        "<blockquote><tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> <b>Iltimos, so'rovingizni aniq va batafsil bayon qiling. Bu sizga tezroq va sifatli yordam berishimizga xizmat qiladi.</b></blockquote>\n\n" .
        "<i><tg-emoji emoji-id='5447644880824181073'>⚠️</tg-emoji> Noaniq yuborilgan xabarlar e'tiborsiz qoldirilishi mumkin.</i>"
    );
    exit;
}

// /panel (Admin only)
if ($text === '/panel' && $fromId === ADMIN_ID) {
    // WebApp URL (AdminPanel.html)
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $scriptName = basename($_SERVER['SCRIPT_NAME']);
    $webAppUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . str_replace($scriptName, 'admin.html', $_SERVER['SCRIPT_NAME']) . '?api=' . $scriptName;
    
    sendMessage(ADMIN_ID, "🗄 <b>Boshqaruv Paneli</b>\n\n🔗 <b>Panel havolasi:</b>\n<code>$webAppUrl</code>", [
        'reply_markup' => [
            'inline_keyboard' => [
                [['text' => '🌐 Web Admin Panel', 'web_app' => ['url' => $webAppUrl]]],
                [['text' => '📢 Xabar yuborish (Forward)', 'callback_data' => 'broadcast']],
            ]
        ]
    ]);
    exit;
}

// Handle Pending States (Reply/Broadcast)
$pendingKey = (string)$fromId;
if ($fromId === ADMIN_ID && isset($data['pending_replies'][$pendingKey])) {
    $pending = $data['pending_replies'][$pendingKey];
    unset($data['pending_replies'][$pendingKey]);
    saveData($data);

    if ($pending['type'] === 'reply') {
        sendMessage((int)$pending['target'], "📬 <b>Admin javobi:</b>\n\n<blockquote>" . htmlspecialchars($text) . "</blockquote>");
        $confirm = sendMessage(ADMIN_ID, "<tg-emoji emoji-id='5427009714745517609'>✅</tg-emoji> <i>Foydalanuvchiga xabar yetkazildi!</i>");
        queueDelete(ADMIN_ID, (int)($confirm['result']['message_id'] ?? 0), 4);
        exit;
    }

    if ($pending['type'] === 'broadcast') {
        $users = array_keys($data['users']);
        $count = 0;
        foreach ($users as $uId) {
            $res = forwardMessage((int)$uId, ADMIN_ID, $msgId);
            if ($res && $res['ok']) $count++;
            if ($count % 20 === 0) usleep(100000); 
        }
        $confirm = sendMessage(ADMIN_ID, "<tg-emoji emoji-id='5406631276042002796'>🚀</tg-emoji> <tg-emoji emoji-id='5427009714745517609'>✅</tg-emoji> <i>Xabar barcha foydalanuvchilarga (Forward: {$count}) yuborildi!</i>");
        queueDelete(ADMIN_ID, (int)($confirm['result']['message_id'] ?? 0), 4);
        exit;
    }
}

// User -> Admin forwarding
if ($fromId !== ADMIN_ID) {
    // 5-minute cooldown check
    $lastMsg = isset($data['users'][$fromId]['last_message_at']) ? (int)$data['users'][$fromId]['last_message_at'] : 0;
    $diff = time() - $lastMsg;
    if ($diff < 300) {
        $left = 300 - $diff;
        $waitMsg = sendMessage($fromId, "<tg-emoji emoji-id='5447644880824181073'>⚠️</tg-emoji> <b>Iltimos, har 5 daqiqada faqat bitta xabar yubora olasiz.</b>\nSiz yana <code>$left</code> soniyadan so'ng yozishingiz mumkin.");
        queueDelete($fromId, (int)($waitMsg['result']['message_id'] ?? 0), 10);
        exit;
    }

    // Update last message time
    $data['users'][$fromId]['last_message_at'] = time();
    saveData($data);

    $userTag = $username ? "@" . htmlspecialchars($username) : "<a href='tg://user?id={$fromId}'>{$firstName}</a>";

    $confirm = sendMessage($fromId, "<tg-emoji emoji-id='5427009714745517609'>✅</tg-emoji> <i>Murojaatingiz adminga yetkazildi!</i>");
    queueDelete($fromId, (int)($confirm['result']['message_id'] ?? 0), 5);

    sendMessage(ADMIN_ID,
        "<tg-emoji emoji-id='5373012449597335010'>👤</tg-emoji> <b>User :</b> {$userTag}\n\n" .
        "<blockquote>📨 <b>Xabar :</b> " . htmlspecialchars($text) . "</blockquote>",
        [
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => '📝 Javob berish', 'callback_data' => "reply_{$fromId}"],
                    ['text' => '🔍 Tushunarsiz',  'callback_data' => "unclear_{$fromId}"],
                ]]
            ]
        ]
    );
    exit;
}
