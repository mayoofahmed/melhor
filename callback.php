<?php
/**
 * Melhor — Call-back request handler
 * Sends website call-back requests to info@melhorgroup.ae via Zoho SMTP.
 *
 * SMTP login details are NOT stored here (this repository is public).
 * They are read from a private file one level ABOVE public_html:
 *   /home/<user>/domains/melhorgroup.ae/melhor-mail-config.php
 */

// ---------- Settings ----------
$TO_EMAIL  = 'info@melhorgroup.ae';
$FROM_NAME = 'Melhor Website';
$MIN_SECONDS_BETWEEN_REQUESTS = 60;
$CONFIG_FILE = dirname(__DIR__) . '/melhor-mail-config.php';
// ------------------------------

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']);

function respond($ok, $error = '', $code = 200) {
    global $isAjax;
    if (!$isAjax) {
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

// Spam trap
if (!empty($_POST['website'])) {
    respond(true);
}

// Rate limit
if (isset($_SESSION['last_callback']) && time() - $_SESSION['last_callback'] < $MIN_SECONDS_BETWEEN_REQUESTS) {
    respond(false, 'You have just sent a request. Please wait a minute before sending another.', 429);
}

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

if ($name === '') respond(false, 'Please enter your name.', 422);
if (strlen(preg_replace('/\D/', '', $phone)) < 7) respond(false, 'Please enter a valid phone number.', 422);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(false, 'Please enter a valid email address.', 422);

// ---------- Build the email ----------
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

// ---------- Send via Zoho SMTP ----------
if (!is_file($CONFIG_FILE)) {
    error_log('Melhor callback: mail config file missing at ' . $CONFIG_FILE);
    respond(false, 'Sorry, your request could not be sent right now.', 500);
}
$cfg = require $CONFIG_FILE; // returns ['host','port','user','pass']

function smtp_send($cfg, $fromName, $to, $replyTo, $replyName, $subject, $body) {
    $host = $cfg['host'] ?? 'smtp.zoho.com';
    $port = (int)($cfg['port'] ?? 465);
    $user = $cfg['user'];
    $pass = $cfg['pass'];

    $fp = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 15);
    if (!$fp) { error_log("SMTP connect failed: $errstr ($errno)"); return false; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c, $expect) use ($fp, $read) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read();
        if ((int)substr($r, 0, 3) !== $expect) { error_log("SMTP error after '" . strtok((string)$c, ' ') . "': $r"); return false; }
        return true;
    };

    $hostname = $_SERVER['SERVER_NAME'] ?? 'melhorgroup.ae';
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

    $headers  = "Date: " . date('r') . "\r\n";
    $headers .= "From: $encFrom <$user>\r\n";
    $headers .= "To: <$to>\r\n";
    if ($replyTo) $headers .= "Reply-To: =?UTF-8?B?" . base64_encode($replyName) . "?= <$replyTo>\r\n";
    $headers .= "Subject: $encSubject\r\n";
    $headers .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@melhorgroup.ae>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";

    $data = $headers . "\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n.";

    $ok = $cmd(null, 220)
       && $cmd("EHLO $hostname", 250)
       && $cmd("AUTH LOGIN", 334)
       && $cmd(base64_encode($user), 334)
       && $cmd(base64_encode($pass), 235)
       && $cmd("MAIL FROM:<$user>", 250)
       && $cmd("RCPT TO:<$to>", 250)
       && $cmd("DATA", 354)
       && $cmd($data, 250);

    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

$sent = smtp_send($cfg, $FROM_NAME, $TO_EMAIL, $email ?: '', $name, $subject, $body);

if (!$sent) {
    respond(false, 'Sorry, your request could not be sent right now.', 500);
}

$_SESSION['last_callback'] = time();
respond(true);
