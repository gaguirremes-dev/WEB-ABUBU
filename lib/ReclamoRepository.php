<?php
declare(strict_types=1);

/**
 * Persistencia de hojas de reclamación en archivos JSON.
 * No requiere base de datos: funciona en cualquier hosting con PHP.
 *
 * Estructura:
 *   data/records/AB-2026-0001.php   (JSON precedido por una guarda PHP)
 *   data/counter.txt                (correlativo, protegido con flock)
 *
 * Los registros se guardan con extensión .php y una primera línea que corta
 * la ejecución: así, aunque el servidor ignore .htaccess (nginx), pedir la
 * URL del archivo nunca expone datos personales.
 */
final class ReclamoRepository
{
    private const GUARD = "<?php http_response_code(403); exit; ?>\n";

    private string $recordsDir;
    private string $counterFile;

    public function __construct(string $baseDir)
    {
        $this->recordsDir  = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'records';
        $this->counterFile = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'counter.txt';
    }

    /**
     * Guarda una hoja nueva y devuelve el registro completo (con código y token).
     */
    public function save(array $data): array
    {
        $this->ensureDirectories();

        $code  = $this->nextCode();
        $token = bin2hex(random_bytes(16));

        $now = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));

        $record = [
            'codigo'       => $code,
            'token'        => $token,
            'creado'       => $now->format('Y-m-d H:i:s'),
            'fecha_limite' => $now->modify('+30 days')->format('Y-m-d'),
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
            'agente'       => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'notificado'   => false,
            'error_envio'  => null,
            'datos'        => $data,
        ];

        $this->writeRecord($code, $record);

        return $record;
    }

    public function find(string $code, string $token): ?array
    {
        if (!preg_match('/^AB-\d{4}-\d{4,}$/', $code) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $path = $this->recordPath($code);
        if (!is_file($path)) {
            return null;
        }

        $record = $this->readRecord($this->recordPath($code));
        if ($record === null || !isset($record['token'])) {
            return null;
        }

        return hash_equals((string) $record['token'], $token) ? $record : null;
    }

    public function markNotification(string $code, bool $ok, ?string $error = null): void
    {
        $record = $this->readRecord($this->recordPath($code));
        if ($record === null) {
            return;
        }

        $record['notificado']  = $ok;
        $record['error_envio'] = $error !== null ? substr($error, 0, 300) : null;

        $this->writeRecord($code, $record);
    }

    private function nextCode(): string
    {
        $year = date('Y');

        $handle = fopen($this->counterFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el contador de reclamos.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el contador de reclamos.');
            }

            $content  = stream_get_contents($handle);
            $current  = (int) trim((string) $content);
            $current++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $current);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return sprintf('AB-%s-%04d', $year, $current);
    }

    private function recordPath(string $code): string
    {
        return $this->recordsDir . DIRECTORY_SEPARATOR . $code . '.php';
    }

    /**
     * Lee el registro y quita la guarda PHP inicial.
     */
    private function readRecord(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        if (str_starts_with($raw, self::GUARD)) {
            $raw = substr($raw, strlen(self::GUARD));
        }

        $record = json_decode($raw, true);

        return is_array($record) ? $record : null;
    }

    private function writeRecord(string $code, array $record): void
    {
        $this->ensureDirectories();

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar la hoja de reclamación.');
        }

        $path = $this->recordPath($code);
        $tmp  = $path . '.tmp.php';

        if (file_put_contents($tmp, self::GUARD . $json, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar la hoja de reclamación.');
        }

        @chmod($tmp, 0640);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo publicar la hoja de reclamación.');
        }
    }

    private function ensureDirectories(): void
    {
        if (!is_dir($this->recordsDir) && !@mkdir($this->recordsDir, 0750, true) && !is_dir($this->recordsDir)) {
            throw new RuntimeException('No se pudo crear el directorio de reclamos.');
        }
    }
}
