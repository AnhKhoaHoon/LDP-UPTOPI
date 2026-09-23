<?php

declare(strict_types=1);

/*
 * Bật ít nhất một kênh nhận hồ sơ.
 * Key thật đặt trong secrets.php (không commit) hoặc biến môi trường.
 */
$secrets = is_file(__DIR__ . '/secrets.php') ? require __DIR__ . '/secrets.php' : [];

return [
    'mail_enabled' => true,
    'mail_to' => 'anhkhoa1292003@gmail.com',
    'mail_from' => 'onboarding@resend.dev',
    'mail_from_name' => 'UpToPi Recruitment',
    'mail_smtp_host' => 'smtp.resend.com',
    'mail_smtp_port' => 587,
    'mail_smtp_username' => 'resend',
    'mail_smtp_password' => getenv('RESEND_SMTP_PASSWORD') ?: ($secrets['resend_smtp_password'] ?? ''),
    'mail_smtp_encryption' => 'tls',
    'mail_ehlo_domain' => 'uptopi.com',

    'telegram_enabled' => true,
    'telegram_bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: ($secrets['telegram_bot_token'] ?? ''),
    'telegram_chat_id' => '6392091425',

    'max_file_size' => 10 * 1024 * 1024,
    'allowed_files' => [
        'pdf' => [
            'application/pdf',
        ],
        'doc' => [
            'application/msword',
            'application/vnd.ms-office',
            'application/x-ole-storage',
            'application/CDFV2',
            'application/octet-stream',
        ],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
    ],
];
