<?php

namespace App\Services;

use Exception;

class SmtpClient
{
    private string $host;
    private int $port;
    private string $encryption; // 'tls', 'ssl', 'none'
    private string $username;
    private string $password;
    private int $timeout;
    private array $transcript = [];

    public function __construct(
        string $host,
        int $port = 587,
        string $encryption = 'tls',
        string $username = '',
        string $password = '',
        int $timeout = 15
    ) {
        $this->host = trim($host);
        $this->port = $port > 0 ? $port : 587;
        $this->encryption = strtolower(trim($encryption));
        $this->username = trim($username);
        $this->password = $password;
        $this->timeout = $timeout > 0 ? $timeout : 15;
    }

    /**
     * Get communication transcript/log for diagnostics
     */
    public function getTranscript(): array
    {
        return $this->transcript;
    }

    /**
     * Send email via SMTP socket connection
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body
     * @param string $fromEmail Sender email
     * @param string $fromName Sender display name
     * @param string $replyTo Optional reply-to email
     * @param bool $isHtml Whether the body is HTML
     * @return bool
     * @throws Exception
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        string $fromEmail,
        string $fromName = 'edvora.chat',
        string $replyTo = '',
        bool $isHtml = false
    ): bool {
        $this->transcript = [];
        $socketHost = $this->host;

        if ($this->encryption === 'ssl' || $this->port === 465) {
            $socketHost = 'ssl://' . $this->host;
        }

        $this->log("Connecting to {$socketHost}:{$this->port} (Timeout: {$this->timeout}s)...");

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $socketHost . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            $msg = "Failed to connect to SMTP host {$this->host}:{$this->port} ({$errno}: {$errstr})";
            $this->log("ERROR: " . $msg);
            throw new Exception($msg);
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            // Read initial greeting
            $response = $this->readResponse($socket);
            if (!$this->isOkResponse($response, [220])) {
                throw new Exception("Unexpected greeting from SMTP server: " . trim($response));
            }

            // Send EHLO
            $localDomain = !empty($_SERVER['HTTP_HOST']) ? preg_replace('/:[0-9]+$/', '', $_SERVER['HTTP_HOST']) : 'edvora.chat';
            $this->sendCommand($socket, "EHLO " . $localDomain);
            $response = $this->readResponse($socket);

            if (!$this->isOkResponse($response, [250])) {
                // Fallback to HELO
                $this->sendCommand($socket, "HELO " . $localDomain);
                $response = $this->readResponse($socket);
                if (!$this->isOkResponse($response, [250])) {
                    throw new Exception("EHLO/HELO rejected: " . trim($response));
                }
            }

            // Negotiate STARTTLS if requested and not already on SSL
            if ($this->encryption === 'tls' && $this->port !== 465) {
                $this->sendCommand($socket, "STARTTLS");
                $response = $this->readResponse($socket);
                if (!$this->isOkResponse($response, [220])) {
                    throw new Exception("STARTTLS negotiation failed: " . trim($response));
                }

                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT |
                                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT |
                                STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

                $cryptoOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if (!$cryptoOk) {
                    throw new Exception("TLS handshake failed with {$this->host}");
                }
                $this->log("TLS encryption established successfully.");

                // Re-send EHLO after TLS handshake
                $this->sendCommand($socket, "EHLO " . $localDomain);
                $response = $this->readResponse($socket);
                if (!$this->isOkResponse($response, [250])) {
                    throw new Exception("EHLO rejected after TLS: " . trim($response));
                }
            }

            // Authenticate if credentials provided
            if (!empty($this->username) && !empty($this->password)) {
                $this->authenticate($socket);
            }

            // MAIL FROM
            $fromClean = trim($fromEmail);
            $this->sendCommand($socket, "MAIL FROM:<" . $fromClean . ">");
            $response = $this->readResponse($socket);
            if (!$this->isOkResponse($response, [250])) {
                throw new Exception("MAIL FROM command rejected: " . trim($response));
            }

            // RCPT TO
            $toClean = trim($to);
            $this->sendCommand($socket, "RCPT TO:<" . $toClean . ">");
            $response = $this->readResponse($socket);
            if (!$this->isOkResponse($response, [250, 251])) {
                throw new Exception("RCPT TO command rejected: " . trim($response));
            }

            // DATA
            $this->sendCommand($socket, "DATA");
            $response = $this->readResponse($socket);
            if (!$this->isOkResponse($response, [354])) {
                throw new Exception("DATA command rejected: " . trim($response));
            }

            // Construct RFC 2822 payload
            $messageData = $this->buildMessage($toClean, $subject, $body, $fromClean, $fromName, $replyTo, $isHtml);
            
            // Send payload with terminating CRLF.CRLF
            $this->writeRaw($socket, $messageData . "\r\n.\r\n");
            $this->log("[Data payload sent (" . strlen($messageData) . " bytes)]");

            $response = $this->readResponse($socket);
            if (!$this->isOkResponse($response, [250])) {
                throw new Exception("Message content rejected by SMTP server: " . trim($response));
            }

            // QUIT
            $this->sendCommand($socket, "QUIT");
            $this->readResponse($socket);
            $this->log("SMTP delivery completed successfully.");

            return true;
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /**
     * Handle SMTP authentication (AUTH LOGIN / AUTH PLAIN)
     */
    private function authenticate($socket): void
    {
        $this->log("Authenticating as user: " . $this->username);
        $this->sendCommand($socket, "AUTH LOGIN");
        $response = $this->readResponse($socket);

        if ($this->isOkResponse($response, [334])) {
            // Send Base64 Username
            $this->writeRaw($socket, base64_encode($this->username) . "\r\n");
            $this->log(">>> [Username transmitted]");
            $response = $this->readResponse($socket);

            if (!$this->isOkResponse($response, [334])) {
                throw new Exception("Username rejected during AUTH LOGIN: " . trim($response));
            }

            // Send Base64 Password
            $this->writeRaw($socket, base64_encode($this->password) . "\r\n");
            $this->log(">>> [Password transmitted]");
            $response = $this->readResponse($socket);

            if (!$this->isOkResponse($response, [235])) {
                throw new Exception("Authentication failed (check SMTP username and password): " . trim($response));
            }

            $this->log("Authentication successful.");
            return;
        }

        // Fallback: AUTH PLAIN
        $plainAuth = base64_encode("\0" . $this->username . "\0" . $this->password);
        $this->sendCommand($socket, "AUTH PLAIN " . $plainAuth);
        $response = $this->readResponse($socket);
        if (!$this->isOkResponse($response, [235])) {
            throw new Exception("SMTP Authentication failed: " . trim($response));
        }

        $this->log("Authentication (PLAIN) successful.");
    }

    /**
     * Build RFC 2822 formatted message
     */
    private function buildMessage(
        string $to,
        string $subject,
        string $body,
        string $fromEmail,
        string $fromName,
        string $replyTo,
        bool $isHtml
    ): string {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = !empty($fromName) ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>' : $fromEmail;
        $messageId = '<' . md5(uniqid((string)mt_rand(), true)) . '@' . (!empty($_SERVER['HTTP_HOST']) ? preg_replace('/:[0-9]+$/', '', $_SERVER['HTTP_HOST']) : 'edvora.chat') . '>';

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $encodedFromName;
        $headers[] = 'To: <' . $to . '>';
        if (!empty($replyTo)) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'Message-ID: ' . $messageId;
        $headers[] = 'X-Mailer: edvora.chat Engine/2.0';
        $headers[] = 'MIME-Version: 1.0';

        if ($isHtml) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
        }

        // Dot-stuffing for RFC 2821
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $normalizedBody = str_replace("\n.", "\n..", $normalizedBody);
        $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);

        return implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody;
    }

    private function sendCommand($socket, string $command): void
    {
        $this->log(">>> " . $command);
        $this->writeRaw($socket, $command . "\r\n");
    }

    private function writeRaw($socket, string $data): void
    {
        $res = @fwrite($socket, $data);
        if ($res === false) {
            throw new Exception("Failed to write to SMTP socket stream.");
        }
    }

    private function readResponse($socket): string
    {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                break;
            }
            $response .= $line;
            $this->log("<<< " . trim($line));

            // SMTP multiline responses have a hyphen at character position 4 (e.g. 250-SIZE...)
            // The last line has a space (e.g. 250 OK)
            if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    private function isOkResponse(string $response, array $expectedCodes): bool
    {
        $code = (int)substr(trim($response), 0, 3);
        return in_array($code, $expectedCodes, true);
    }

    private function log(string $message): void
    {
        $timestamp = date('H:i:s');
        $this->transcript[] = "[{$timestamp}] " . $message;
    }
}
