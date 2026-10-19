<?php
declare(strict_types=1);

/**
 * Minimal mail sender with two drivers:
 *  - log:  writes each email to storage/mail/*.html (no network; safe default)
 *  - smtp: plain SMTP client with STARTTLS/SSL and AUTH LOGIN (no external libraries)
 * Configuration comes from config/mail.php (see config/mail.example.php).
 */
final class MailService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::loadConfig();
    }

    public static function loadConfig(): array
    {
        $config = [
            'driver' => 'log',
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'timeout' => 15,
            'verify_peer' => true,
            'base_url' => 'http://localhost',
        ];
        $file = dirname(__DIR__, 2) . '/config/mail.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $config = array_merge($config, $loaded);
            }
        }
        return $config;
    }

    public function driver(): string
    {
        return strtolower((string)$this->config['driver']) === 'smtp' ? 'smtp' : 'log';
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
        ?string $replyTo,
        string $fallbackFromName,
        string $fallbackFromEmail
    ): void {
        $toEmail = $this->cleanHeader($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Recipient email address is not valid.');
        }
        $fromEmail = $this->cleanHeader((string)($this->config['from_email'] !== '' ? $this->config['from_email'] : $fallbackFromEmail));
        $fromName = $this->cleanHeader((string)($this->config['from_name'] !== '' ? $this->config['from_name'] : $fallbackFromName));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'no-reply@localhost.localdomain';
        }
        $replyTo = $replyTo !== null ? $this->cleanHeader($replyTo) : null;
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = null;
        }

        if ($this->driver() === 'log') {
            $this->writeLogFile($toEmail, $subject, $html);
            return;
        }

        $raw = $this->buildMessage($fromEmail, $fromName, $toEmail, $this->cleanHeader($toName), $subject, $html, $text, $replyTo);
        $this->sendSmtp($fromEmail, $toEmail, $raw);
    }

    private function writeLogFile(string $to, string $subject, string $html): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/mail';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create the storage/mail folder.');
        }
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $banner = '<div style="font:13px Arial,sans-serif;background:#fff8e1;border-bottom:1px solid #e6d9a8;padding:10px 16px">'
            . '<strong>TEST MODE — this email was not sent.</strong><br>To: ' . $e($to) . '<br>Subject: ' . $e($subject) . '</div>';
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.html';
        if (@file_put_contents($file, $banner . $html) === false) {
            throw new RuntimeException('Unable to write to the storage/mail folder.');
        }
    }

    private function buildMessage(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $html, string $text, ?string $replyTo): string
    {
        $boundary = '=_af_' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($fromName, $fromEmail),
            'To: ' . $this->formatAddress($toName, $toEmail),
        ];
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $headers[] = 'Subject: ' . $this->encodeHeader($this->cleanHeader($subject));
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . '--' . $boundary . "--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function formatAddress(string $name, string $email): string
    {
        if ($name === '') {
            return '<' . $email . '>';
        }
        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            if (preg_match('/[()<>@,;:\\\\".\[\]]/', $name) === 1) {
                $name = '"' . addcslashes($name, '"\\') . '"';
            }
            return $name . ' <' . $email . '>';
        }
        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function sendSmtp(string $fromEmail, string $toEmail, string $rawMessage): void
    {
        $host = (string)$this->config['host'];
        $port = (int)$this->config['port'];
        $encryption = strtolower((string)$this->config['encryption']);
        $timeout = max(5, (int)$this->config['timeout']);
        if ($host === '') {
            throw new RuntimeException('SMTP host is not configured (config/mail.php).');
        }

        $verify = (bool)$this->config['verify_peer'];
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'SNI_enabled' => true,
        ]]);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            throw new RuntimeException('SMTP connection failed: ' . $errstr . ' (' . $errno . ').');
        }
        stream_set_timeout($fp, $timeout);

        try {
            $this->expect($fp, [220]);
            $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)gethostname());
            $ehlo = $ehlo !== '' && $ehlo !== null ? $ehlo : 'localhost';
            $this->command($fp, 'EHLO ' . $ehlo, [250]);

            if ($encryption === 'tls') {
                $this->command($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP TLS negotiation failed. If this is a local machine without CA certificates, see verify_peer in config/mail.php.');
                }
                $this->command($fp, 'EHLO ' . $ehlo, [250]);
            }

            $username = (string)$this->config['username'];
            if ($username !== '') {
                $this->command($fp, 'AUTH LOGIN', [334]);
                $this->command($fp, base64_encode($username), [334]);
                $this->command($fp, base64_encode((string)$this->config['password']), [235]);
            }

            $this->command($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command($fp, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($fp, 'DATA', [354]);
            $data = preg_replace('/^\./m', '..', $rawMessage);
            fwrite($fp, $data . "\r\n.\r\n");
            $this->expect($fp, [250]);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp */
    private function command($fp, string $command, array $okCodes): string
    {
        fwrite($fp, $command . "\r\n");
        return $this->expect($fp, $okCodes);
    }

    /** @param resource $fp */
    private function expect($fp, array $okCodes): string
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if ($response === '') {
            throw new RuntimeException('The SMTP server closed the connection or timed out.');
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
        return $response;
    }
}
