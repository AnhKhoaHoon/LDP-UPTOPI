# Cấu hình form ứng tuyển trên aaPanel

## 1. Cấu hình kênh nhận hồ sơ

Mở `config.php`:

- Email: đặt `mail_enabled` thành `true`; điền `mail_to` là email admin nhận CV.
- Resend SMTP: điền địa chỉ gửi đã xác minh vào `mail_from`.
- Telegram: tạo bot bằng `@BotFather`, nhắn tin cho bot, lấy `chat_id`, sau đó đặt
  `telegram_enabled` thành `true` và điền chat ID.
- Có thể bật một hoặc đồng thời cả hai kênh. Không được tắt cả hai.

`config.php` chứa key thật (Resend API key, Telegram bot token) nên đã nằm trong
`.gitignore` và không có trên GitHub. Khi deploy, tải `config.php` trực tiếp lên thư mục
`backend/` trên server (qua File Manager của aaPanel).

File `.htaccess` chặn truy cập trực tiếp `config.php` khi dùng Apache/OpenLiteSpeed.
Nếu website dùng Nginx, thêm vào cấu hình website rồi reload Nginx:

```nginx
location = /backend/config.php {
    deny all;
}
```

## 2. PHP trên aaPanel

- Khuyến nghị PHP 7.4 trở lên.
- Bật extension `fileinfo` và `curl`.
- Đặt `upload_max_filesize = 10M` (hoặc lớn hơn).
- Đặt `post_max_size = 12M` (phải lớn hơn `upload_max_filesize`).
- Đảm bảo `file_uploads = On`, sau đó restart PHP.

## 3. Gửi email qua Resend SMTP

Xác minh domain gửi trong Resend và cấu hình các DNS record SPF/DKIM mà Resend cung
cấp. `mail_from` phải thuộc domain đã xác minh.

Chạy local:

```bash
php -S 127.0.0.1:8080
```

Sau đó mở `http://127.0.0.1:8080/html/career.html`. Email sẽ gồm thông tin ứng viên
và CV đính kèm, gửi tới địa chỉ `mail_to`.

## 4. Kiểm tra

Mở `html/career.html` qua domain thật và gửi lần lượt một file PDF, DOC, DOCX nhỏ.
Kiểm tra thêm các trường hợp sai định dạng và file lớn hơn 10 MB. Khi có lỗi máy chủ,
xem PHP error log trong aaPanel; token Telegram không được trả về trình duyệt.
