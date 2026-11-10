<?php
/**
 * Server-side hCaptcha verification.
 *
 * Fail-closed: if the keys are missing, the HTTP call fails, the response is
 * malformed or the hostname does not match, verification returns false.
 * The secret never leaves the server.
 */

declare(strict_types=1);

final class Captcha
{
    private const VERIFY_URL = 'https://api.hcaptcha.com/siteverify';

    private string $siteKey;
    private string $secret;
    private string $domain;
    private bool $allowLocalhost;

    /**
     * @param array  $config        Mail config (reads $config['captcha']).
     * @param string $defaultDomain Expected hostname when captcha.domain is unset.
     */
    public function __construct(array $config, string $defaultDomain = 'abubu.com.pe')
    {
        $c = $config['captcha'] ?? [];
        $c['domain'] = $c['domain'] ?? $defaultDomain;
        $this->siteKey = trim((string) ($c['site_key'] ?? ''));
        $this->secret = trim((string) ($c['secret'] ?? ''));
        $this->domain = strtolower(trim((string) ($c['domain'] ?? '')));
        $this->allowLocalhost = ($c['allow_localhost'] ?? false) === true;
    }

    /** True when both keys are present. */
    public function configured(): bool
    {
        return $this->siteKey !== '' && $this->secret !== '';
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    /**
     * @param string|null $motivo Internal reason (for the server log only).
     */
    public function verify(string $response, string $ip, ?string &$motivo = null): bool
    {
        if (!$this->configured()) {
            $motivo = 'captcha_sin_claves';
            return false;
        }
        $response = trim($response);
        if ($response === '' || strlen($response) > 4096) {
            $motivo = 'captcha_vacio';
            return false;
        }

        $body = http_build_query([
            'secret'   => $this->secret,
            'response' => $response,
            'sitekey'  => $this->siteKey,
            'remoteip' => $ip,
        ], '', '&');

        $raw = $this->post($body);
        if ($raw === null) {
            $motivo = 'captcha_sin_respuesta';
            return false;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $motivo = 'captcha_respuesta_invalida';
            return false;
        }
        if (($data['success'] ?? false) !== true) {
            $codes = isset($data['error-codes']) && is_array($data['error-codes'])
                ? implode(',', array_map('strval', $data['error-codes'])) : '';
            $motivo = 'captcha_rechazado' . ($codes !== '' ? " ($codes)" : '');
            return false;
        }

        $host = strtolower((string) ($data['hostname'] ?? ''));
        if ($host !== '') {
            $allowed = [$this->domain, 'www.' . $this->domain];
            if ($this->allowLocalhost) {
                $allowed[] = 'localhost';
            }
            if ($this->domain === '' || !in_array($host, $allowed, true)) {
                $motivo = "captcha_hostname ($host)";
                return false;
            }
        }

        return true;
    }

    private function post(string $body): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init(self::VERIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return (is_string($out) && $code === 200) ? $out : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $body,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $out = @file_get_contents(self::VERIFY_URL, false, $ctx);
        return is_string($out) ? $out : null;
    }
}
