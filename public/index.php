<?php
declare(strict_types=1);
/*testing
/**
 * FAQ Chatbot — landing page + JSON API
 * Routes:
 *   GET  /                  -> chat HTML UI
 *   GET  /?message=...      -> JSON answer
 *   POST / {"message":"…"}  -> JSON answer (preferred; matches the front-end client)
 *
 * The API branch instantiates OpenRouterProvider directly (no empty-stub chain).
 * Customer-service system prompt is defined as SYSTEM_PROMPT below — single
 * source of truth, no fragmentation.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

require __DIR__ . '/../src/AI/OpenRouterProvider.php';
require __DIR__ . '/../src/Agent/CustomerServiceAgent.php';
require __DIR__ . '/../src/FAQ/mdService.php';

use Agent\CustomerServiceAgent;
use FAQ\MdService;

const BOT_NAME = CustomerServiceAgent::NAME;
const MAX_INPUT = 2000;     // chars — soft cap against obvious abuse

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isApi  = isset($_GET['message']) || $method === 'POST';

if ($isApi) {
    header('Content-Type: application/json; charset=utf-8');

    // --- 1) Pull message from POST body or GET query
    $rawMessage = '';
    if ($method === 'POST') {
        $body = file_get_contents('php://input');
        $data = is_string($body) ? json_decode($body, true) : null;
        if (is_array($data) && isset($data['message'])) {
            $rawMessage = (string) $data['message'];
        }
    }
    if ($rawMessage === '' && isset($_GET['message'])) {
        $rawMessage = (string) $_GET['message'];
    }

    $message = trim($rawMessage);

    // --- 2) Validate input
    if ($message === '') {
        http_response_code(400);
        echo json_encode([
            'error'     => 'missing message',
            'question'  => '',
            'answer'    => 'Please provide a ?message=... or POST {"message":"..."}.',
            'timestamp' => date('c'),
            'bot'       => BOT_NAME,
        ]);
        return;
    }
    if (mb_strlen($message) > MAX_INPUT) {
        http_response_code(413);
        echo json_encode([
            'error'     => 'message too long',
            'question'  => $message,
            'answer'    => 'Please keep your message under ' . MAX_INPUT . ' characters.',
            'timestamp' => date('c'),
            'bot'       => BOT_NAME,
        ]);
        return;
    }

    // --- 3) Instantiate provider (throws if OPENROUTER_API_KEY is missing)
    try {
        $provider = new \AI\OpenRouterProvider();
    } catch (\Throwable $e) {
        error_log('openrouter init: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error'     => 'provider unavailable',
            'question'  => $message,
            'answer'    => 'Sorry — the assistant is unavailable right now. Please try again soon.',
            'timestamp' => date('c'),
            'bot'       => BOT_NAME,
        ]);
        return;
    }

    // --- 4) Load FAQ data and find relevant entries for context
    $mdService = new MdService();
    $relevantFaqs = $mdService->search($message);
    
    // Build system prompt with FAQ context
    $systemPrompt = CustomerServiceAgent::getSystemPrompt();
    if (!empty($relevantFaqs)) {
        $faqContext = "\n\nRelevant FAQ entries (use these to answer accurately):\n";
        foreach (array_slice($relevantFaqs, 0, 5) as $faq) {
            $faqContext .= "- Q: {$faq['question']}\n  A: {$faq['answer']}\n\n";
        }
        $systemPrompt .= $faqContext;
    }
    
    // --- 5) Call the model with the enhanced system prompt
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $message],
    ];
    $res = $provider->chat($messages);

    // --- 5) Surface friendly errors; never leak upstream details to the user
    if (!$res['ok']) {
        error_log('openrouter chat: status=' . $res['status'] . ' err=' . ($res['error'] ?? '?'));
        $code = ($res['status'] >= 400 && $res['status'] < 600) ? $res['status'] : 502;
        http_response_code($code);
        echo json_encode([
            'error'     => 'upstream error',
            'question'  => $message,
            'answer'    => 'Sorry — I am having trouble reaching the assistant right now. Please try again in a moment.',
            'timestamp' => date('c'),
            'bot'       => BOT_NAME,
        ]);
        return;
    }

    $answer = (string) ($res['content'] ?? '');
    if (trim($answer) === '') {
        $answer = '(empty response from assistant)';
    }

    echo json_encode([
        'question'  => $message,
        'answer'    => $answer,
        'bot'       => BOT_NAME,
        'model'     => $provider->model(),
        'timestamp' => date('c'),
    ]);
    return;
}

// ---------- HTML UI (unchanged from the public UI scaffold) ----------
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#007aff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Aria — FAQ Chatbot</title>
    <link rel="stylesheet" href="/style/style.css">
</head>
<body>
    <main class="chat-container" role="main">
        <header class="chat-header">
            <div class="bot-avatar" aria-hidden="true">
                <image class="bot-avatar" src="assets/aria-small.gif">
            </div>
            <div class="bot-info">
                <h1>Aria</h1>
                <span>Online · usually replies instantly</span>
            </div>
            <button id="theme-toggle" class="theme-toggle" aria-label="Toggle theme">🎨</button>
        </header>

        <section id="messages" class="chat-messages" aria-live="polite">
            <div id="welcome" class="welcome-screen">
                <image class="welcome-avatar" src="assets/aria-small.gif">
                <h2 class="welcome-title">Hi there!</h2>
                <p class="welcome-subtitle">I'm Aria. Ask me about hours, HowTos, or say "help" to see what I can do.</p>
                <div class="suggestion-chips">
                    <button class="suggestion-chip" type="button" data-text="What are your opening hours?">Opening hours</button>
                    <button class="suggestion-chip" type="button" data-text="how can I recover lost ktp?">Recover Lost KTP</button>
                    <button class="suggestion-chip" type="button" data-text="how do I change address on my KTP?">Change address</button>
                    <button class="suggestion-chip" type="button" data-text="help">Help</button>
                </div>
            </div>
            <div id="typing" class="typing-indicator" aria-hidden="true">
                <div class="typing-dots"><span></span><span></span><span></span></div>
            </div>
        </section>

        <footer class="chat-input-area">
            <form id="composer" class="input-wrapper" autocomplete="off">
                <textarea
                    id="input"
                    class="input-field"
                    placeholder="Type a message…"
                    rows="1"
                    aria-label="Message"></textarea>
                <button id="send" class="send-btn" type="submit" aria-label="Send">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 2 11 13" />
                        <path d="M22 2 15 22 11 13 2 9 22 2z" />
                    </svg>
                </button>
            </form>
        </footer>
    </main>

    <aside class="api-info">
        API: <code>GET ?message=.....</code> or <code>POST {"message":"..."}</code>
    </aside>

    <script src="/script/script.js" defer></script>
    <script>
    </script>
</body>
</html>