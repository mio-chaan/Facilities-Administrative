<?php
/**
 * app/includes/ai_helper.php
 * Thin wrapper around the OpenAI Chat Completions API, plus a small
 * text-extraction helper for the Document Summarizer feature.
 *
 * SETUP REQUIRED:
 *   1. Get an API key at https://platform.openai.com/api-keys
 *   2. Add this line to app/config/config.php (near the other define()s):
 *        define('OPENAI_API_KEY', 'sk-your-key-here');
 *   3. Make sure the PHP curl extension is enabled - open php.ini
 *      (C:\xampp\php\php.ini), find `;extension=curl`, remove the
 *      leading `;`, save, and restart Apache in the XAMPP control panel.
 *   4. Require this file once, early - e.g. alongside where
 *      db_connect.php is required in your front controller
 *      (index.php), OR it's already safely required with a
 *      file_exists guard inside modules/assistant/index.php.
 */

declare(strict_types=1);

/**
 * Sends a chat completion request to OpenAI and returns the assistant's
 * reply text. Throws RuntimeException on any failure (network, API
 * error, missing key) with a message safe to show the user.
 *
 * @param array $messages e.g. [['role'=>'system','content'=>'...'], ['role'=>'user','content'=>'...']]
 */
function t8_openai_chat(array $messages, string $model = 'gpt-4o-mini', float $temperature = 0.4): string
{
    if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
        throw new RuntimeException('AI features are not configured yet. Add OPENAI_API_KEY to app/config/config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP curl extension is not enabled. Enable extension=curl in php.ini and restart Apache.');
    }

    $payload = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT    => 30,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Could not reach OpenAI: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $apiMessage = $data['error']['message'] ?? 'Unknown error from OpenAI.';
        throw new RuntimeException('OpenAI API error: ' . $apiMessage);
    }

    return trim((string) ($data['choices'][0]['message']['content'] ?? ''));
}

/**
 * Extracts plain text from a document file for summarization.
 * Supports .txt directly and .docx via ZipArchive (built into PHP -
 * no external library needed). Other types (pdf, doc, xls, xlsx, ppt,
 * pptx, images) are not supported without an additional library and
 * return null - the caller should show a friendly "not supported" message.
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
