<?php

namespace App\Services\Freeswitch;

use RuntimeException;

/**
 * Minimal FreeSWITCH event socket (ESL) client for "api" commands, on the
 * localhost-only socket the installer configures.
 */
class EventSocket
{
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 8021,
        private ?string $password = null,
        private float $timeout = 5.0,
    ) {}

    /** Run an API command (e.g. "sofia profile carrier rescan") and return its output. */
    public function api(string $command, ?float $timeout = null): string
    {
        $timeout ??= $this->timeout;
        if (preg_match('/[\r\n]/', $command)) {
            throw new RuntimeException('ESL commands cannot contain line breaks.');
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new RuntimeException("Cannot reach FreeSWITCH event socket: {$error}");
        }
        stream_set_timeout($socket, (int) ceil($timeout));

        try {
            $hello = $this->readMessage($socket);
            if (($hello['headers']['Content-Type'] ?? '') !== 'auth/request') {
                throw new RuntimeException('Unexpected event socket greeting.');
            }
            fwrite($socket, "auth {$this->password}\n\n");
            $auth = $this->readMessage($socket);
            if (! str_starts_with($auth['headers']['Reply-Text'] ?? '', '+OK')) {
                throw new RuntimeException('Event socket authentication failed.');
            }

            fwrite($socket, "api {$command}\n\n");
            $reply = $this->readMessage($socket);

            return $reply['body'];
        } finally {
            fclose($socket);
        }
    }

    /** @return array{headers: array<string, string>, body: string} */
    private function readMessage($socket): array
    {
        $headers = [];
        while (($line = fgets($socket)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($headers === []) {
                    continue; // stray blank line between messages
                }
                break;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[trim($name)] = urldecode(trim($value));
        }
        if ($headers === []) {
            throw new RuntimeException('Event socket closed unexpectedly.');
        }

        $body = '';
        $length = (int) ($headers['Content-Length'] ?? 0);
        while (strlen($body) < $length && ! feof($socket)) {
            $chunk = fread($socket, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return ['headers' => $headers, 'body' => $body];
    }
}
