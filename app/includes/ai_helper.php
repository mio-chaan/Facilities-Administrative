<?php
/**
 * app/includes/ai_helper.php
 * Thin wrapper around the Google Gemini "generateContent" API, plus a
 * small text-extraction helper for the Document Summarizer feature.
 *
 * MIGRATION NOTE: this file previously called OpenAI's Chat
 * Completions endpoint via t8_openai_chat(). It has been switched to
 * Google Gemini. The function is now named t8_ai_chat() and keeps the
 * exact same call signature (array of ['role' => ..., 'content' =>
 * ...] messages) so callers only need to update the function name -
 * see modules/assistant/index.php and modules/documents/index.php
 * (the 'summarize' action).
 *
 * SETUP REQUIRED:
 *   1. Get an API key at https://aistudio.google.com/apikey
 *   2. Add this line to app/config/config.local.php (gitignored):
 *        define('GEMINI_API_KEY', 'your-key-here');
 *   3. Make sure the PHP curl extension is enabled - open php.ini
 *      (C:\xampp\php\php.ini), find `;extension=curl`, remove the
 *      leading `;`, save, and restart Apache in the XAMPP control panel.
 *   4. Require this file once, early - e.g. alongside where
 *      db_connect.php is required in your front controller
 *      (index.php), OR it's already safely required with a
 *      file_exists guard inside modules/assistant/index.php and
 *      modules/documents/index.php.
 */

declare(strict_types=1);

/**
 * Sends a chat request to Gemini and returns the model's reply text.
 * Throws RuntimeException on any failure (network, API error, missing
 * key) with a message safe to show the user.
 *
 * @param array $messages e.g. [['role'=>'system','content'=>'...'], ['role'=>'user','content'=>'...']]
 *   'system' messages are pulled out and sent via Gemini's separate
 *   systemInstruction field (Gemini's `contents` array only accepts
 *   'user' and 'model' roles - 'assistant' is mapped to 'model').
 */
function t8_ai_chat(array $messages, string $model = 'gemini-3.5-flash', float $temperature = 0.4): string
{
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        throw new RuntimeException('AI features are not configured yet. Add GEMINI_API_KEY to app/config/config.local.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP curl extension is not enabled. Enable extension=curl in php.ini and restart Apache.');
    }

    $systemParts = [];
    $contents = [];
    foreach ($messages as $message) {
        $role = (string) ($message['role'] ?? 'user');
        $text = (string) ($message['content'] ?? '');

        if ($role === 'system') {
            $systemParts[] = ['text' => $text];
            continue;
        }

        $contents[] = [
            'role'  => $role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $text]],
        ];
    }

    $payload = [
        'contents'         => $contents,
        'generationConfig' => ['temperature' => $temperature],
    ];
    if ($systemParts !== []) {
        $payload['systemInstruction'] = ['parts' => $systemParts];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 30,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }

    if ($response === false) {
        throw new RuntimeException('Could not reach the AI service.');
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        throw new RuntimeException('The AI service returned an error.');
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (trim((string) $text) === '') {
        // Most commonly a safety-filter block - candidates[0] exists but
        // has no parts. Surface something useful instead of a blank reply.
        $finishReason = $data['candidates'][0]['finishReason'] ?? 'unknown';
        throw new RuntimeException('The AI service returned no usable content.');
    }

    return trim((string) $text);
}

/**
 * Extracts plain text from a document file for summarization.
 * Supports .txt directly and .docx via ZipArchive (built into PHP -
 * no external library needed). Other types (pdf, doc, xls, xlsx, ppt,
 * pptx, images) are not supported without an additional library and
 * return null - the caller should show a friendly "not supported" message.
 *
 * Unchanged by the Gemini migration - this has nothing to do with the
 * AI provider, only with getting text off disk.
 */
function t8_extract_text_for_summary(string $absolutePath, string $extension): ?string
{
    $extension = strtolower($extension);

    if ($extension === 'txt') {
        $text = file_get_contents($absolutePath);
        return $text !== false ? $text : null;
    }

    if ($extension === 'docx') {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return null;
        }
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $text = strip_tags($xml);
        return trim(html_entity_decode($text));
    }

    return null;
}
