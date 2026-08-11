<?php
/**
 * modules/assistant/index.php
 * AI Chatbot Assistant - answers general questions about how to use
 * the RAM YUM system. This is NOT a nav page; it's reached only via
 * an AJAX POST from the floating widget (see templates/ai_widget.php).
 * A direct GET visit just shows a short explanatory message.
 *
 * Requires GEMINI_API_KEY - see app/includes/ai_helper.php for setup.
 *
 * QA FIX (chatbot returning "Something went wrong reaching the
 * assistant" in the browser even though this file's own logic is
 * correct): the widget POSTs to index.php?page=assistant, which goes
 * through the FULL front controller (public/index.php). That
 * controller opens an output buffer (ob_start()) and echoes the
 * header/navbar/sidebar HTML BEFORE requiring this module. Without
 * clearing that buffer here, this file's json_encode() response gets
 * appended AFTER that leftover HTML in the final response body -
 * i.e. the browser receives "<html>...<div class="t8-shell">...{json}"
 * instead of pure JSON. fetch().then(r => r.json()) then throws a
 * parse error client-side, which is exactly the generic
 * "Something went wrong reaching the assistant" message in
 * templates/ai_widget.php's .catch() handler.
 *
 * The fix: discard every open output buffer the moment we know this
 * is the AJAX POST branch, before sending the JSON content-type
 * header or echoing anything. This does NOT touch or change any of
 * the AI logic itself - t8_ai_chat()/ai_helper.php are unaffected.
 */

declare(strict_types=1);

// Defensive require - safe even if ai_helper.php is already loaded
// centrally by your front controller (require_once is idempotent).
// Adjust this path if your app/ folder isn't two levels up from here.
$aiHelperPath = __DIR__ . '/../../app/includes/ai_helper.php';
if (is_file($aiHelperPath)) {
    require_once $aiHelperPath;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // QA FIX: drop the header/navbar/sidebar HTML the front controller
    // already buffered for this request - see docblock above. Must
    // happen before header()/echo below.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        echo json_encode(['error' => 'Your session expired. Please refresh the page.']);
        exit;
    }

    $userMessage = trim((string) ($_POST['message'] ?? ''));
    if ($userMessage === '') {
        echo json_encode(['error' => 'Please type a message.']);
        exit;
    }
    if (mb_strlen($userMessage) > 1000) {
        echo json_encode(['error' => 'Message is too long. Please keep it under 1000 characters.']);
        exit;
    }

    $systemPrompt = 'You are the AI Assistant for RAM YUM, a Facilities & Administrative Management '
        . 'web system used by Facilities Staff and Administrators. It has modules for Facilities '
        . 'Reservation (book rooms/facilities, admin approves), Visitor Management (check-in/out log), '
        . 'Document Management (upload files with version history, categories, archive), Records '
        . 'Retention (retention schedules and disposition tracking), Legal Management (admin-only case '
        . 'tracking), Contract Management (admin-only contracts, parties, obligations), and a Dashboard. '
        . 'Answer the user\'s question helpfully and concisely, focused on how to use the system. If '
        . 'asked something unrelated to the system or general facilities/admin work, politely redirect '
        . 'them back to system-related topics. Keep replies under 150 words unless the user asks for '
        . 'more detail.';

    try {
        $reply = t8_ai_chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ]);
        if (function_exists('t8_audit_log') && function_exists('t8_current_user_id')) {
            t8_audit_log($pdo, t8_current_user_id(), 'ai_assistant', 0, 'chat');
        }
        echo json_encode(['reply' => $reply]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Direct GET visit (not through the widget) - nothing to show.
$pageTitle = 'AI Assistant';
?>
<h1>AI Assistant</h1>
<p class="t8-help-text">This endpoint powers the floating chat widget and isn't meant to be visited directly.</p>
