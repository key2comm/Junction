<?php

namespace App\Services;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A minimal S3 client (AWS Signature Version 4, path-style URLs), enough for
 * backups: upload, list and delete. Works with Amazon S3 and S3-compatible
 * storage (DigitalOcean Spaces, Wasabi, Backblaze B2, Cloudflare R2, MinIO...).
 * No SDK: a single PUT takes files up to 5 GB.
 */
class S3Storage
{
    public const MAX_PUT = 5 * 1024 * 1024 * 1024;

    public function __construct(
        private string $endpoint,
        private string $region,
        private string $bucket,
        private string $key,
        private string $secret,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        if (! preg_match('#^https?://[^/]+$#', $this->endpoint)) {
            throw new RuntimeException("the endpoint must be a URL like https://s3.example.com (got \"{$endpoint}\").");
        }
    }

    public function put(string $object, string $file): void
    {
        $size = filesize($file);
        if ($size > self::MAX_PUT) {
            throw new RuntimeException('the backup is over 5 GB, the most one upload can take.');
        }
        $stream = Utils::streamFor(fopen($file, 'r'));
        try {
            $this->send('PUT', $object, [], $stream, hash_file('sha256', $file), ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $size]);
        } finally {
            $stream->close();
        }
    }

    public function delete(string $object): void
    {
        $this->send('DELETE', $object);
    }

    /** @return list<string> object keys starting with $prefix */
    public function list(string $prefix = ''): array
    {
        $keys = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix] + ($token ? ['continuation-token' => $token] : []);
            $xml = @simplexml_load_string($this->send('GET', '', $query)->body());
            if ($xml === false) {
                throw new RuntimeException('the storage sent an unreadable bucket listing.');
            }
            foreach ($xml->Contents as $item) {
                $keys[] = (string) $item->Key;
            }
            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token);

        return $keys;
    }

    private function send(string $method, string $object, array $query = [], mixed $body = null, ?string $payloadHash = null, array $headers = []): Response
    {
        $payloadHash ??= hash('sha256', '');
        $uri = '/'.rawurlencode($this->bucket).($object !== '' ? '/'.implode('/', array_map('rawurlencode', explode('/', $object))) : '');
        ksort($query);
        $qs = implode('&', array_map(fn ($k, $v) => rawurlencode($k).'='.rawurlencode((string) $v), array_keys($query), $query));
        $host = parse_url($this->endpoint, PHP_URL_HOST).(($port = parse_url($this->endpoint, PHP_URL_PORT)) ? ":{$port}" : '');
        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $day = gmdate('Ymd', $now);

        $canonical = implode("\n", [$method, $uri, $qs, "host:{$host}", "x-amz-content-sha256:{$payloadHash}", "x-amz-date:{$amzDate}", '',
            'host;x-amz-content-sha256;x-amz-date', $payloadHash]);
        $scope = "{$day}/{$this->region}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $canonical);
        $k = hash_hmac('sha256', $day, 'AWS4'.$this->secret, true);
        foreach ([$this->region, 's3', 'aws4_request'] as $part) {
            $k = hash_hmac('sha256', $part, $k, true);
        }
        $signature = hash_hmac('sha256', $toSign, $k);

        $request = Http::timeout(600)->connectTimeout(15)->withHeaders([
            ...$headers,
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$signature}",
        ]);
        if ($body !== null) {
            $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/octet-stream');
        }
        $response = $request->send($method, $this->endpoint.$uri.($qs !== '' ? "?{$qs}" : ''));
        if ($response->failed()) {
            $xml = @simplexml_load_string($response->body());
            $why = $xml !== false && isset($xml->Code) ? trim("{$xml->Code}: {$xml->Message}", ': ') : mb_substr(strip_tags($response->body()), 0, 200);
            throw new RuntimeException("{$method} failed (HTTP {$response->status()}".($why !== '' ? ", {$why}" : '').').');
        }

        return $response;
    }
}
