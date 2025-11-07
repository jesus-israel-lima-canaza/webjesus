<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$composerAutoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_readable($composerAutoload)) {
    require_once $composerAutoload;
}

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

const CONTACT_RECIPIENT = 'jesus.israel.lima.canaza@gmail.com';
const CONTACT_SUBJECT   = 'Nuevo mensaje desde el formulario de contacto';

$smtpConfig = [
    'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'port'       => (int) (getenv('SMTP_PORT') ?: 587),
    'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
    'username'   => getenv('SMTP_USERNAME') ?: '',
    'password'   => getenv('SMTP_PASSWORD') ?: '',
    'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
    'from_name'  => getenv('SMTP_FROM_NAME') ?: 'Formulario de contacto',
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

$smtpReady = class_exists(PHPMailer::class)
    && $smtpConfig['username'] !== ''
    && $smtpConfig['password'] !== '';

$sent = false;
$transportUsed = 'none';
$errorsDuringSend = null;

if ($smtpReady) {
    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host       = $smtpConfig['host'];
        $mailer->Port       = $smtpConfig['port'];
        $mailer->SMTPAuth   = true;
        $mailer->SMTPSecure = $smtpConfig['encryption'];
        $mailer->Username   = $smtpConfig['username'];
        $mailer->Password   = $smtpConfig['password'];
        $mailer->CharSet    = 'UTF-8';

        $mailer->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
        $mailer->addReplyTo($email ?: $smtpConfig['from_email'], $sanitizedName ?: 'Visitante');
        $mailer->addAddress(CONTACT_RECIPIENT);

        $mailer->Subject = CONTACT_SUBJECT;
        $mailer->Body    = $messageBody;

        $sent = $mailer->send();
        $transportUsed = 'smtp';
    } catch (PHPMailerException $exception) {
        $errorsDuringSend = $exception->getMessage();
    }
}

if (!$sent && function_exists('mail')) {
    $headers = implode("\r\n", [
        'From: ' . ($email !== '' ? $email : $smtpConfig['from_email']),
        'Reply-To: ' . ($email !== '' ? $email : $smtpConfig['from_email']),
        'X-Mailer: PHP/' . phpversion()
    ]);

    $sent = @mail(CONTACT_RECIPIENT, CONTACT_SUBJECT, $messageBody, $headers);
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
