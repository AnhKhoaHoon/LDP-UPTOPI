<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/**
 * @param array<string, mixed> $data
 */
function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function field(string $name, int $maxLength): string
{
    $value = trim((string) ($_POST[$name] ?? ''));
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

    if ($value === '' || $length > $maxLength) {
        throw new InvalidArgumentException('Thông tin gửi lên chưa đầy đủ hoặc quá dài.');
    }

    return $value;
}

function cleanHeader(string $value): string
{
    return str_replace(["\r", "\n"], '', $value);
}

function shortText(string $value, int $maxLength): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function hasValidSignature(string $path, string $extension): bool
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }

    $signature = fread($handle, 8);
    fclose($handle);

    if ($extension === 'pdf') {
        return strncmp((string) $signature, '%PDF-', 5) === 0;
    }

    if ($extension === 'doc') {
        return $signature === hex2bin('D0CF11E0A1B11AE1');
    }

    if ($extension === 'docx') {
        return in_array(substr((string) $signature, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }

    return false;
}

/**
 * @param resource $socket
 * @param array<int, int> $expectedCodes
 */
function smtpRead($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP từ chối yêu cầu với mã ' . $code . '.');
    }

    return $response;
}

/**
 * @param resource $socket
 * @param array<int, int> $expectedCodes
 */
function smtpCommand($socket, string $command, array $expectedCodes): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Không thể gửi lệnh tới SMTP.');
    }

    return smtpRead($socket, $expectedCodes);
}

function normalizeCrlf(string $value): string
{
    return preg_replace("/\r\n|\r|\n/", "\r\n", $value) ?? $value;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, string> $application
 */
function sendMail(array $config, array $application, string $filePath, string $fileName, string $mimeType): bool
{
    $recipient = cleanHeader((string) $config['mail_to']);
    $sender = cleanHeader((string) $config['mail_from']);
    $senderName = cleanHeader((string) ($config['mail_from_name'] ?? 'UpToPi Recruitment'));
    $host = cleanHeader((string) ($config['mail_smtp_host'] ?? ''));
    $port = (int) ($config['mail_smtp_port'] ?? 587);
    $username = cleanHeader((string) ($config['mail_smtp_username'] ?? ''));
    $password = (string) ($config['mail_smtp_password'] ?? '');
    $encryption = strtolower((string) ($config['mail_smtp_encryption'] ?? 'tls'));
    $ehloDomain = cleanHeader((string) ($config['mail_ehlo_domain'] ?? 'localhost'));

    if (
        !filter_var($recipient, FILTER_VALIDATE_EMAIL)
        || !filter_var($sender, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[A-Za-z0-9.-]+$/', $host)
        || !preg_match('/^[A-Za-z0-9.-]+$/', $ehloDomain)
        || $port < 1
        || $port > 65535
        || $username === ''
        || $password === ''
        || strpos($password, 'THAY_') === 0
    ) {
        throw new RuntimeException('Cấu hình SMTP chưa hoàn tất hoặc không hợp lệ.');
    }

    $boundary = 'uptopi_' . bin2hex(random_bytes(12));
    $subjectText = 'Hồ sơ ứng tuyển mới';
    $subject = '=?UTF-8?B?' . base64_encode($subjectText) . '?=';
    $bodyText = normalizeCrlf(implode("\r\n", [
        'HỒ SƠ ỨNG TUYỂN MỚI',
        '',
        'Họ và tên: ' . $application['fullname'],
        'Email: ' . $application['email'],
        'Số điện thoại: ' . $application['phone'],
        'Vị trí: ' . $application['position'],
        '',
        'Lý do muốn tham gia:',
        $application['why_uptopi'],
    ]));

    $attachment = file_get_contents($filePath);
    if ($attachment === false) {
        throw new RuntimeException('Không thể đọc file CV.');
    }

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s +0000'),
        'From: ' . $senderName . ' <' . $sender . '>',
        'To: <' . $recipient . '>',
        'Reply-To: ' . cleanHeader($application['email']),
        'Subject: ' . $subject,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloDomain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $bodyText . "\r\n\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Type: ' . $mimeType . '; name="' . $fileName . "\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'Content-Disposition: attachment; filename="' . $fileName . "\"\r\n\r\n"
        . chunk_split(base64_encode($attachment))
        . '--' . $boundary . "--\r\n";

    $socketHost = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($socketHost, $errorCode, $errorMessage, 15, STREAM_CLIENT_CONNECT);
    if ($socket === false) {
        throw new RuntimeException('Không thể kết nối SMTP: ' . $errorCode . '.');
    }

    stream_set_timeout($socket, 30);

    try {
        smtpRead($socket, [220]);
        smtpCommand($socket, 'EHLO ' . $ehloDomain, [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Không thể thiết lập mã hóa TLS với SMTP.');
            }
            smtpCommand($socket, 'EHLO ' . $ehloDomain, [250]);
        } elseif ($encryption !== 'ssl' && $encryption !== 'none') {
            throw new RuntimeException('Kiểu mã hóa SMTP không được hỗ trợ.');
        }

        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $sender . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $dotStuffedMessage = preg_replace('/(^|\r\n)\./', '$1..', $message) ?? $message;
        if (fwrite($socket, $dotStuffedMessage . "\r\n.\r\n") === false) {
            throw new RuntimeException('Không thể gửi nội dung email tới SMTP.');
        }
        smtpRead($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }

    return true;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, string> $application
 */
function sendTelegram(array $config, array $application, string $filePath, string $fileName, string $mimeType): bool
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL chưa được bật.');
    }

    $token = trim((string) $config['telegram_bot_token']);
    $chatId = trim((string) $config['telegram_chat_id']);
    if ($token === '' || $chatId === '' || strpos($token, 'THAY_') === 0 || strpos($chatId, 'THAY_') === 0) {
        throw new RuntimeException('Cấu hình Telegram chưa hoàn tất.');
    }

    $caption = implode("\n", [
        'HỒ SƠ ỨNG TUYỂN MỚI',
        'Họ tên: ' . $application['fullname'],
        'Email: ' . $application['email'],
        'Điện thoại: ' . $application['phone'],
        'Vị trí: ' . $application['position'],
        'Lý do: ' . $application['why_uptopi'],
    ]);

    $curl = curl_init('https://api.telegram.org/bot' . $token . '/sendDocument');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'caption' => shortText($caption, 1024),
            'document' => new CURLFile($filePath, $mimeType, $fileName),
        ],
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        error_log('Telegram cURL error: ' . $curlError);
        return false;
    }

    $result = json_decode($response, true);
    if ($statusCode !== 200 || !is_array($result) || ($result['ok'] ?? false) !== true) {
        error_log('Telegram API error (HTTP ' . $statusCode . '): ' . shortText($response, 500));
        return false;
    }

    return true;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        respond(405, ['success' => false, 'message' => 'Phương thức không được hỗ trợ.']);
    }

    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/config.php';
    $mailEnabled = ($config['mail_enabled'] ?? false) === true;
    $telegramEnabled = ($config['telegram_enabled'] ?? false) === true;
    if (!$mailEnabled && !$telegramEnabled) {
        throw new RuntimeException('Chưa bật kênh nhận hồ sơ.');
    }

    $application = [
        'fullname' => field('fullname', 150),
        'email' => field('email', 254),
        'phone' => field('phone', 30),
        'position' => field('position', 250),
        'why_uptopi' => field('why_uptopi', 1500),
    ];

    if (!filter_var($application['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Địa chỉ email không hợp lệ.');
    }
    if (!preg_match('/^[0-9+().\s-]{8,30}$/', $application['phone'])) {
        throw new InvalidArgumentException('Số điện thoại không hợp lệ.');
    }
    if (!isset($_POST['consent'])) {
        throw new InvalidArgumentException('Bạn cần đồng ý để UpToPi liên hệ.');
    }

    if (!isset($_FILES['cv_file']) || !is_array($_FILES['cv_file'])) {
        throw new InvalidArgumentException('Vui lòng đính kèm CV.');
    }

    $file = $_FILES['cv_file'];
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $uploadMessages = [
            UPLOAD_ERR_INI_SIZE => 'CV vượt quá giới hạn upload của máy chủ.',
            UPLOAD_ERR_FORM_SIZE => 'CV vượt quá dung lượng cho phép.',
            UPLOAD_ERR_PARTIAL => 'CV chỉ được tải lên một phần. Vui lòng thử lại.',
            UPLOAD_ERR_NO_FILE => 'Vui lòng đính kèm CV.',
        ];
        throw new InvalidArgumentException($uploadMessages[$uploadError] ?? 'Không thể tải CV lên.');
    }

    $filePath = (string) ($file['tmp_name'] ?? '');
    $fileSize = (int) ($file['size'] ?? 0);
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $maxFileSize = (int) ($config['max_file_size'] ?? 0);
    /** @var array<string, array<int, string>> $allowedFiles */
    $allowedFiles = is_array($config['allowed_files'] ?? null) ? $config['allowed_files'] : [];

    if (!is_uploaded_file($filePath) || $fileSize <= 0) {
        throw new InvalidArgumentException('CV tải lên không hợp lệ.');
    }
    if ($maxFileSize <= 0 || $fileSize > $maxFileSize) {
        throw new InvalidArgumentException('CV vượt quá dung lượng 10 MB.');
    }
    if (!isset($allowedFiles[$extension])) {
        throw new InvalidArgumentException('Chỉ chấp nhận file PDF, DOC hoặc DOCX.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $fileInfo->file($filePath);
    if (!in_array($mimeType, $allowedFiles[$extension], true) || !hasValidSignature($filePath, $extension)) {
        throw new InvalidArgumentException('Nội dung file CV không đúng định dạng.');
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeName = trim((string) $safeName, '._-');
    $safeFileName = ($safeName !== '' ? $safeName : 'CV') . '.' . $extension;

    if ($mailEnabled && !sendMail($config, $application, $filePath, $safeFileName, $mimeType)) {
        throw new RuntimeException('Không thể gửi hồ sơ qua email.');
    }
    if ($telegramEnabled && !sendTelegram($config, $application, $filePath, $safeFileName, $mimeType)) {
        throw new RuntimeException('Không thể gửi hồ sơ qua Telegram.');
    }

    respond(200, [
        'success' => true,
        'message' => 'Đã tiếp nhận hồ sơ! UpToPi sẽ phản hồi qua email trong vòng 48h làm việc.',
    ]);
} catch (InvalidArgumentException $exception) {
    respond(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Recruitment form error: ' . $exception->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Hệ thống chưa thể nhận hồ sơ. Vui lòng thử lại hoặc gửi email trực tiếp.',
    ]);
}
