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
    . 'web system used by Facilities Staff and Administrators. '

    . 'Your job is to answer ONLY what the user asks. '
    . 'Do not add unnecessary introductions, greetings, summaries, suggestions, or extra information. '
    . 'Do not explain features that the user did not ask about. '
    . 'Keep the answer short and direct. '

    . 'RAM YUM has these modules: Facilities Reservation (book rooms/facilities, admin approval), '
    . 'Visitor Management (check-in/out logs), Document Management (upload files, version history, '
    . 'categories, archive), Records Retention (retention schedules and disposition tracking), '
    . 'Legal Management (admin-only case tracking), Contract Management (admin-only contracts, '
    . 'parties, obligations), and Dashboard. '

    . 'If the user asks a question about one specific module, answer only that question. '
    . 'If the user asks something unrelated to RAM YUM, politely say that you can only help with '
    . 'RAM YUM and its related facilities/administrative functions. '

    . 'Use short paragraphs and bullet points only when they make the answer easier to read. '
    . 'Keep replies under 80 words unless the user asks for more detail.';

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
        error_log('AI assistant request failed: ' . $e->getMessage());
        http_response_code(502);
        echo json_encode(['error' => 'The AI assistant is temporarily unavailable.']);
    }
    exit;
}

// Direct GET visit (not through the widget) - nothing to show.
$pageTitle = 'AI Assistant';
?>
<h1>AI Assistant</h1>
<p class="t8-help-text">This endpoint powers the floating chat widget and isn't meant to be visited directly.</p>
