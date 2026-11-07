<?php

namespace App\Support;

use RuntimeException;

/**
 * A tiny SIP-over-WebSocket client (RFC 7118) for the self-test: connects to
 * wss://<host>/ws through this server's nginx, exactly like the browser web
 * phone, and REGISTERs with digest authentication. Signalling only; no media.
 */
class SipWebSocketProbe
{
    /** @var resource|null */
    private $socket = null;

    private string $callId;

    private string $tag;

    private string $instance;

    private int $cseq = 0;

    public function __construct(private string $host, private string $address = '127.0.0.1', private int $port = 443)
    {
        $this->callId = bin2hex(random_bytes(10));
        $this->tag = bin2hex(random_bytes(5));
        $this->instance = bin2hex(random_bytes(6));
    }

    /** REGISTER (expires 0 = unregister). Returns the final SIP status code. */
    public function register(string $user, string $password, int $expires): int
    {
        $this->connect();
        $response = $this->transaction($this->registerRequest($user, $expires));
        if (in_array($response['code'], [401, 407], true)) {
            $header = $response['headers'][$response['code'] === 401 ? 'www-authenticate' : 'proxy-authenticate'] ?? '';
            $response = $this->transaction($this->registerRequest($user, $expires, $this->authorization($header, $user, $password, $response['code'])));
        }

        return $response['code'];
    }

    public function close(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, $this->frame('', 0x8));
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        if ($this->socket) {
            return;
        }
        $context = stream_context_create(['ssl' => [
            // Loopback to our own nginx: the certificate may still be self-signed.
            'verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => $this->host, 'SNI_enabled' => true,
        ]]);
        $socket = @stream_socket_client("ssl://{$this->address}:{$this->port}", $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("cannot connect to nginx on {$this->address}:{$this->port}: {$error}");
        }
        stream_set_timeout($socket, 5);
        $key = base64_encode(random_bytes(16));
        fwrite($socket, "GET /ws HTTP/1.1\r\nHost: {$this->host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: sip\r\n\r\n");
        $head = '';
        while (! str_contains($head, "\r\n\r\n") && ($line = fgets($socket)) !== false) {
            $head .= $line;
        }
        if (! preg_match('#^HTTP/1\.1 101#', $head)) {
            fclose($socket);
            throw new RuntimeException('WebSocket upgrade refused: '.strtok($head, "\r\n"));
        }
        $this->socket = $socket;
    }

    private function registerRequest(string $user, int $expires, ?string $authorization = null): string
    {
        $this->cseq++;
        $branch = 'z9hG4bK'.bin2hex(random_bytes(6));

        return "REGISTER sip:{$this->host} SIP/2.0\r\n"
            ."Via: SIP/2.0/WSS {$this->instance}.invalid;branch={$branch}\r\n"
            ."Max-Forwards: 70\r\n"
            ."To: <sip:{$user}@{$this->host}>\r\n"
            ."From: <sip:{$user}@{$this->host}>;tag={$this->tag}\r\n"
            ."Call-ID: {$this->callId}\r\n"
            ."CSeq: {$this->cseq} REGISTER\r\n"
            ."Contact: <sip:{$user}@{$this->instance}.invalid;transport=ws>;expires={$expires}\r\n"
            ."Expires: {$expires}\r\n"
            .($authorization ? "Authorization: {$authorization}\r\n" : '')
            ."User-Agent: Softswitch self-test\r\n"
            ."Content-Length: 0\r\n\r\n";
    }

    private function authorization(string $challenge, string $user, string $password, int $code): string
    {
        preg_match_all('/(\w+)="?([^",]*)"?/', $challenge, $m, PREG_SET_ORDER);
        $c = [];
        foreach ($m as [, $k, $v]) {
            $c[strtolower($k)] = $v;
        }
        $realm = $c['realm'] ?? '';
        $nonce = $c['nonce'] ?? '';
        $uri = "sip:{$this->host}";
        $ha1 = md5("{$user}:{$realm}:{$password}");
        $ha2 = md5("REGISTER:{$uri}");
        $header = "Digest username=\"{$user}\", realm=\"{$realm}\", nonce=\"{$nonce}\", uri=\"{$uri}\", algorithm=MD5";
        if (isset($c['qop']) && in_array('auth', array_map('trim', explode(',', $c['qop'])), true)) {
            $cnonce = bin2hex(random_bytes(8));
            $response = md5("{$ha1}:{$nonce}:00000001:{$cnonce}:auth:{$ha2}");
            $header .= ", qop=auth, nc=00000001, cnonce=\"{$cnonce}\"";
        } else {
            $response = md5("{$ha1}:{$nonce}:{$ha2}");
        }
        if (isset($c['opaque'])) {
            $header .= ", opaque=\"{$c['opaque']}\"";
        }

        return $header.", response=\"{$response}\"";
    }

    /** Send a request, return the final response (1xx skipped). */
    private function transaction(string $request): array
    {
        fwrite($this->socket, $this->frame($request));
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline) {
            $message = $this->readFrame();
            if ($message === null || ! preg_match('#^SIP/2\.0 (\d{3})#', $message, $status)) {
                continue; // a request from the server (e.g. OPTIONS) or a control frame
            }
            $code = (int) $status[1];
            if ($code < 200) {
                continue;
            }
            $headers = [];
            foreach (array_slice(explode("\r\n", strstr($message, "\r\n\r\n", true) ?: $message), 1) as $line) {
                [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
                $headers[strtolower(trim($name))] = trim($value);
            }

            return ['code' => $code, 'headers' => $headers];
        }
        throw new RuntimeException('no SIP response over the WebSocket');
    }

    /** Client frames are masked (RFC 6455). */
    private function frame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($length < 126) {
            $head .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $head .= chr(0x80 | 126).pack('n', $length);
        } else {
            $head .= chr(0x80 | 127).pack('J', $length);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $head.$mask.$masked;
    }

    private function readFrame(): ?string
    {
        $head = $this->read(2);
        $opcode = ord($head[0]) & 0x0F;
        $length = ord($head[1]) & 0x7F;
        if ($length === 126) {
            $length = unpack('n', $this->read(2))[1];
        } elseif ($length === 127) {
            $length = unpack('J', $this->read(8))[1];
        }
        $mask = (ord($head[1]) & 0x80) ? $this->read(4) : null;
        $payload = $length > 0 ? $this->read($length) : '';
        if ($mask) {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }
        if ($opcode === 0x9) { // ping -> pong
            fwrite($this->socket, $this->frame($payload, 0xA));

            return null;
        }
        if ($opcode === 0x8) {
            throw new RuntimeException('the switch closed the WebSocket');
        }

        return in_array($opcode, [0x1, 0x2], true) ? $payload : null;
    }

    private function read(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($this->socket, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($this->socket)['timed_out'] ?? false) {
                    throw new RuntimeException('timed out waiting for the switch');
                }
                if (feof($this->socket)) {
                    throw new RuntimeException('the WebSocket closed');
                }
                continue;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
