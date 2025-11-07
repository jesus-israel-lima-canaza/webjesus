<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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

$recipient = 'jesus.israel.lima.canaza@gmail.com';
$subject = 'Nuevo mensaje desde el formulario de contacto';
$body = implode(PHP_EOL, [
    'Nombre: ' . $sanitizedName,
    'Correo: ' . $email,
    'Mensaje:',
    $sanitizedNote
]);

$headers = implode("\r\n", [
    'From: ' . $email,
    'Reply-To: ' . $email,
    'X-Mailer: PHP/' . phpversion()
]);

$sent = false;
if (function_exists('mail')) {
    $sent = @mail($recipient, $subject, $body, $headers);
}

if (!$sent) {
    $logDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0775, true);
    }
    $logFile = $logDirectory . DIRECTORY_SEPARATOR . 'contact.log';
    $logEntry = sprintf("[%s] %s%s", date('c'), $body, PHP_EOL);
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

echo json_encode([
    'success' => true,
    'message' => $sent
        ? 'Mensaje enviado correctamente.'
        : 'Mensaje recibido correctamente. (Configura el servicio de correo en el servidor para el envío automático)'
]);
