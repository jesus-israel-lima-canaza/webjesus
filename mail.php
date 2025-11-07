<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const CONTACT_RECIPIENT = 'jesus.israel.lima.canaza@gmail.com';
const CONTACT_SUBJECT   = 'Nuevo mensaje desde el formulario de contacto';

$smtpConfig = [
    'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'port'       => (int) (getenv('SMTP_PORT') ?: 587),
    'encryption' => strtolower((string) getenv('SMTP_ENCRYPTION') ?: 'tls'),
    'username'   => getenv('SMTP_USERNAME') ?: '',
    'password'   => getenv('SMTP_PASSWORD') ?: '',
    'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
    'from_name'  => getenv('SMTP_FROM_NAME') ?: 'Formulario de contacto',
    'timeout'    => (int) (getenv('SMTP_TIMEOUT') ?: 15),
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido.'
    ]);
    exit;
}

$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
$email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';

$errors = [];

if ($name === '') {
    $errors[] = 'El nombre es obligatorio.';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'El correo electrónico no es válido.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $errors)
    ]);
    exit;
}

$sanitizedName = filter_var($name, FILTER_SANITIZE_SPECIAL_CHARS);
$sanitizedNote = $note !== '' ? filter_var($note, FILTER_SANITIZE_SPECIAL_CHARS) : '[Sin mensaje]';

$messageBody = implode(PHP_EOL, [
    'Nombre: ' . $sanitizedName,
    'Correo: ' . $email,
    'Mensaje:',
    $sanitizedNote
]);

$smtpReady = $smtpConfig['username'] !== '' && $smtpConfig['password'] !== '';

$sent = false;
$transportUsed = 'none';
$errorsDuringSend = null;

if ($smtpReady) {
    [$sent, $errorsDuringSend] = sendViaSMTP(
        $smtpConfig,
        [
            'subject'     => CONTACT_SUBJECT,
            'body'        => $messageBody,
            'from_email'  => $smtpConfig['from_email'],
            'from_name'   => $smtpConfig['from_name'],
            'reply_email' => $email !== '' ? $email : $smtpConfig['from_email'],
            'reply_name'  => $sanitizedName !== '' ? $sanitizedName : 'Visitante'
        ]
    );

    if ($sent) {
        $transportUsed = 'smtp';
    }
}

if (!$sent && function_exists('mail')) {
    $headers = implode("\r\n", [
        'From: ' . formatAddress($email !== '' ? $email : $smtpConfig['from_email'], $sanitizedName !== '' ? $sanitizedName : $smtpConfig['from_name']),
        'Reply-To: ' . formatAddress($email !== '' ? $email : $smtpConfig['from_email'], $sanitizedName !== '' ? $sanitizedName : $smtpConfig['from_name']),
        'X-Mailer: PHP/' . phpversion()
    ]);

    $sent = @mail(CONTACT_RECIPIENT, CONTACT_SUBJECT, $messageBody . PHP_EOL, $headers);
    $transportUsed = 'mail()';
}

if (!$sent) {
    $logDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0775, true);
    }
    $logFile = $logDirectory . DIRECTORY_SEPARATOR . 'contact.log';
    $logEntry = sprintf("[%s] %s%s", date('c'), $messageBody, PHP_EOL);
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

$responseMessage = 'Mensaje recibido correctamente.';
if ($sent && $transportUsed === 'smtp') {
    $responseMessage = 'Mensaje enviado correctamente.';
} elseif (!$sent) {
    $responseMessage = 'No se pudo enviar el correo automáticamente, pero tu mensaje ha sido guardado.';
    if ($errorsDuringSend) {
        $responseMessage .= ' Detalles técnicos: ' . $errorsDuringSend;
    }
}

echo json_encode([
    'success' => true,
    'transport' => $transportUsed,
    'message' => $responseMessage
]);

/**
 * @param array<string,string|int> $config
 * @param array<string,string>     $payload
 * @return array{0:bool,1:?string}
 */
function sendViaSMTP(array $config, array $payload): array
{
    $host       = (string) $config['host'];
    $port       = (int) $config['port'];
    $encryption = (string) $config['encryption'];
    $username   = (string) $config['username'];
    $password   = (string) $config['password'];
    $timeout    = (int) $config['timeout'];

    if ($encryption === 'ssl') {
        $remote = 'ssl://' . $host . ':' . $port;
    } else {
        $remote = $host . ':' . $port;
    }

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [false, sprintf('No se pudo conectar al servidor SMTP (%s:%d): %s', $host, $port, $errstr ?: 'error desconocido')];
    }

    stream_set_timeout($socket, $timeout);

    try {
        ensureResponse($socket, [220], 'Conexión inicial');

        smtpWrite($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        ensureResponse($socket, [250], 'EHLO');

        if ($encryption === 'tls') {
            smtpWrite($socket, 'STARTTLS');
            ensureResponse($socket, [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo establecer el canal seguro TLS');
            }
            smtpWrite($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            ensureResponse($socket, [250], 'EHLO tras STARTTLS');
        }

        smtpWrite($socket, 'AUTH LOGIN');
        ensureResponse($socket, [334], 'AUTH LOGIN (usuario)');
        smtpWrite($socket, base64_encode($username));
        ensureResponse($socket, [334], 'AUTH LOGIN (contraseña)');
        smtpWrite($socket, base64_encode($password));
        ensureResponse($socket, [235], 'Autenticación');

        smtpWrite($socket, 'MAIL FROM:<' . $payload['from_email'] . '>');
        ensureResponse($socket, [250], 'MAIL FROM');

        smtpWrite($socket, 'RCPT TO:<' . CONTACT_RECIPIENT . '>');
        ensureResponse($socket, [250, 251], 'RCPT TO');

        smtpWrite($socket, 'DATA');
        ensureResponse($socket, [354], 'DATA');

        $headers = buildHeaders($payload);
        $data = $headers . "\r\n" . normalizeLineEndings($payload['body']) . "\r\n.";
        smtpWrite($socket, $data);
        ensureResponse($socket, [250], 'Envío de datos');

        smtpWrite($socket, 'QUIT');
        ensureResponse($socket, [221], 'Cierre de conexión');

        fclose($socket);
        return [true, null];
    } catch (Throwable $exception) {
        if (is_resource($socket)) {
            fclose($socket);
        }
        return [false, $exception->getMessage()];
    }
}

/**
 * @param resource $socket
 * @param array<int,int> $codes
 */
function ensureResponse($socket, array $codes, string $context): void
{
    $response = readResponse($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(sprintf('%s falló (%s)', $context, trim($response)));
    }
}

/**
 * @param resource $socket
 */
function smtpWrite($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

/**
 * @param resource $socket
 */
function readResponse($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return $response;
}

function buildHeaders(array $payload): string
{
    $subject = $payload['subject'];
    if (function_exists('mb_encode_mimeheader')) {
        $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    $headers = [
        'Date: ' . date('r'),
        'From: ' . formatAddress($payload['from_email'], $payload['from_name']),
        'Reply-To: ' . formatAddress($payload['reply_email'], $payload['reply_name']),
        'To: ' . formatAddress(CONTACT_RECIPIENT, 'Destinatario'),
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    return implode("\r\n", $headers);
}

function formatAddress(string $email, string $name = ''): string
{
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    if ($name === '') {
        return $email;
    }
    $cleanName = addslashes($name);
    return sprintf('"%s" <%s>', $cleanName, $email);
}

function normalizeLineEndings(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return str_replace("\n", "\r\n", $text);
}
