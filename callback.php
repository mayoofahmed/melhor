<?php
/**
 * Melhor — Call-back request handler
 * Sends website call-back requests to info@melhorgroup.ae
 */

// ---------- Settings ----------
$TO_EMAIL   = 'info@melhorgroup.ae';
$FROM_EMAIL = 'info@melhorgroup.ae';   // must be a mailbox on melhorgroup.ae (Hostinger)
$FROM_NAME  = 'Melhor Website';
$MIN_SECONDS_BETWEEN_REQUESTS = 60;
// ------------------------------

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']);

function respond($ok, $error = '', $code = 200) {
    global $isAjax;
    if (!$isAjax) { // fallback for browsers without JavaScript
        header('Content-Type: text/html; charset=utf-8');
        header('Location: /' . ($ok ? '?callback=sent#contact' : '?callback=error#contact'));
        exit;
    }
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request.', 405);
}

// Spam trap — real visitors never fill this in
if (!empty($_POST['website'])) {
    respond(true); // pretend success so bots don't retry
}

// Basic rate limit per visitor session
if (isset($_SESSION['last_callback']) && time() - $_SESSION['last_callback'] < $MIN_SECONDS_BETWEEN_REQUESTS) {
    respond(false, 'You have just sent a request. Please wait a minute before sending another.', 429);
}

// Clean inputs (strip line breaks from single-line fields to prevent header injection)
function clean_line($v, $max) {
    $v = trim((string)$v);
    $v = str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v);
    return mb_substr(strip_tags($v), 0, $max);
}

$name     = clean_line($_POST['name'] ?? '', 80);
$phone    = clean_line($_POST['phone'] ?? '', 25);
$email    = clean_line($_POST['email'] ?? '', 120);
$division = clean_line($_POST['division'] ?? 'Not sure yet', 60);
$time     = clean_line($_POST['time'] ?? 'Any time', 40);
$message  = mb_substr(trim(strip_tags((string)($_POST['message'] ?? ''))), 0, 1000);

// Validation
if ($name === '') {
    respond(false, 'Please enter your name.', 422);
}
if (strlen(preg_replace('/\D/', '', $phone)) < 7) {
    respond(false, 'Please enter a valid phone number.', 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.', 422);
}

// Build email
date_default_timezone_set('Asia/Dubai');
$subject = 'Call-back request: ' . $name . ' (' . $division . ')';

$body  = "New call-back request from melhorgroup.ae\n";
$body .= "------------------------------------------\n\n";
$body .= "Name:           $name\n";
$body .= "Phone:          $phone\n";
$body .= "Email:          " . ($email ?: '-') . "\n";
$body .= "Interested in:  $division\n";
$body .= "Best time:      $time\n\n";
$body .= "Message:\n" . ($message ?: '-') . "\n\n";
$body .= "------------------------------------------\n";
$body .= "Received: " . date('d M Y, h:i A') . " (UAE time)\n";
$body .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n";

$headers   = [];
$headers[] = 'From: ' . $FROM_NAME . ' <' . $FROM_EMAIL . '>';
if ($email !== '') {
    $headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
}
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

$sent = mail($TO_EMAIL, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $FROM_EMAIL);

if (!$sent) {
    respond(false, 'Sorry, your request could not be sent right now.', 500);
}

$_SESSION['last_callback'] = time();
respond(true);
