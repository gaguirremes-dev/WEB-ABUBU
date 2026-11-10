<?php
declare(strict_types=1);

/**
 * Defensas anti-spam del Libro de Reclamaciones: IP real, límites por IP y
 * globales, contenido repetido. Todo en un único archivo JSON bajo flock;
 * nunca se guarda la IP en claro, solo un HMAC con una sal propia del servidor.
 */
final class AntiSpam
{
    public const INTERVALO_SEGUNDOS = 1800;  // 1 hoja cada 30 min por IP
    public const MAX_POR_DIA        = 5;     // y 5 cada 24 h por IP
    public const GLOBAL_POR_HORA    = 20;    // tope del sitio completo
    public const GLOBAL_POR_DIA     = 80;

    /** Rangos publicados por Cloudflare (https://www.cloudflare.com/ips/). */
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private string $stateFile;
    private string $saltFile;

    public function __construct(string $dataDir)
    {
        $dataDir         = rtrim($dataDir, '/\\');
        $this->stateFile = $dataDir . DIRECTORY_SEPARATOR . 'ratelimit.json';
        $this->saltFile  = $dataDir . DIRECTORY_SEPARATOR . 'ratelimit.salt';
    }

    /**
     * IP real: REMOTE_ADDR, salvo que la conexión llegue desde Cloudflare, en
     * cuyo caso se acepta CF-Connecting-IP. X-Forwarded-For nunca se usa.
     */
    public static function clientIp(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && self::inCloudflare($remote)) {
            return $cf;
        }

        return $remote;
    }

    private static function inCloudflare(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach (self::CLOUDFLARE as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = inet_pton($net);
            if (strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits  = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (strncmp($bin, $netBin, $bytes) !== 0) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** Sal secreta generada la primera vez; vive en data/, cerrado por HTTP. */
    private function salt(): string
    {
        $value = is_file($this->saltFile) ? trim((string) @file_get_contents($this->saltFile)) : '';
        if (strlen($value) < 32) {
            $new = bin2hex(random_bytes(32));
            $fp  = @fopen($this->saltFile, 'x');   // falla si otro proceso la creó antes
            if ($fp !== false) {
                fwrite($fp, $new);
                fclose($fp);
                @chmod($this->saltFile, 0600);
            }
            $value = trim((string) @file_get_contents($this->saltFile));
            if (strlen($value) < 32) {
                $value = $new;
            }
        }
        return $value;
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->salt());
    }

    /**
     * Comprueba los límites y reserva el cupo en la misma operación (flock):
     * dos envíos simultáneos no pueden colarse entre lectura y escritura.
     * Si el almacén no se puede abrir, rechaza (fail-closed).
     *
     * @param string $content texto del reclamo, para detectar repetidos en 24 h
     * @param string|null $reason motivo interno del rechazo (solo para el log)
     */
    public function reserve(string $ip, string $content, ?string &$reason = null): bool
    {
        $now = time();
        $fp  = @fopen($this->stateFile, 'c+');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if ($fp !== false) {
                fclose($fp);
            }
            $reason = 'store';
            return false;
        }

        try {
            $state = json_decode((string) stream_get_contents($fp), true);
            $state = is_array($state) ? $state : [];
            $alive = static fn($t) => is_int($t) && $t > $now - 86400;

            $ips = [];
            foreach ((array) ($state['ips'] ?? []) as $k => $marks) {
                $m = array_values(array_filter((array) $marks, $alive));
                if ($m !== []) {
                    $ips[$k] = $m;
                }
            }
            $global = array_values(array_filter((array) ($state['global'] ?? []), $alive));
            $dups   = array_filter((array) ($state['dups'] ?? []), $alive);

            $key   = $this->hash($ip);
            $marks = $ips[$key] ?? [];
            $dup   = $this->hash('c|' . $content);

            if (count(array_filter($global, fn($t) => $t > $now - 3600)) >= self::GLOBAL_POR_HORA) {
                $reason = 'global_hour';
            } elseif (count($global) >= self::GLOBAL_POR_DIA) {
                $reason = 'global_day';
            } elseif ($marks !== [] && max($marks) > $now - self::INTERVALO_SEGUNDOS) {
                $reason = 'interval';
            } elseif (count($marks) >= self::MAX_POR_DIA) {
                $reason = 'day';
            } elseif (isset($dups[$dup])) {
                $reason = 'duplicate';
            } else {
                $marks[]     = $now;
                $ips[$key]   = $marks;
                $global[]    = $now;
                $dups[$dup]  = $now;
                $reason      = null;
            }

            if ($reason === null) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode(['ips' => $ips, 'global' => $global, 'dups' => $dups]));
                fflush($fp);
            }
            return $reason === null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
