<?php

define('GEMINI_API_KEY', 'API-KEY-HERE'); // change api-key-here to your Gemini API key. You can get one at https://exchange.gemini.com/settings/api

// Gmail SMTP credentials: copy these definitions into the ignored
// app/config/config.local.php and replace the empty strings.
$GMAIL_SMTP_USER_LOCAL = ''; // Paste the Gmail address used as the SMTP sender.
$GMAIL_SMTP_APP_PASSWORD_LOCAL = ''; // Paste the 16-character Google App Password (not your Gmail login password).
$T8_SIGNING_OTP_PEPPER_LOCAL = ''; // Set a random secret with at least 32 characters for signing OTP hashes.
$T8_SIGNATURE_STORAGE_DIR_LOCAL = ''; // Set a writable private directory outside the web root for drawn signatures.