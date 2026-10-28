<?php
declare(strict_types=1);

/**
 * Cliente SMTP mínimo, sin dependencias externas.
 * Soporta SSL implícito (puerto 465), STARTTLS (587) y autenticación AUTH LOGIN.
 *
 * Config esperada:
 *   host, port, secure ('ssl'|'tls'|'none'), username, password,
 *   from, from_name, timeout
 */
final class SmtpMailer
{
    private array $cfg;

    /** @var resource|null */
    private $socket = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg + [
            'host'      => '',
            'port'      => 465,
            'secure'    => 'ssl',
            'username'  => '',
            'password'  => '',
            'from'      => '',
            'from_name' => '',
            'timeout'   => 15,
        ];
    }

    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $replyTo = null,
        ?string $replyToName = null
    ): void {
        $this->connect();
        try {
            $this->authenticate();
            $this->command('MAIL FROM:<' . $this->cfg['from'] . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            $this->writeRaw($this->buildMessage($to, $subject, $html, $replyTo, $replyToName));
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $host    = $this->cfg['host'];
        $port    = (int) $this->cfg['port'];
        $timeout = (float) $this->cfg['timeout'];

        if ($host === '' || $this->cfg['from'] === '') {
            throw new RuntimeException('Configuración SMTP incompleta.');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);

        $scheme = $this->cfg['secure'] === 'ssl' ? 'ssl' : 'tcp';
        $remote = sprintf('%s://%s:%d', $scheme, $host, $port);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('No se pudo conectar al servidor SMTP (%s).', $errstr ?: $errno));
        }

        stream_set_timeout($socket, (int) $timeout);
        $this->socket = $socket;

        $this->expect([220]);

        $this->command('EHLO ' . $this->localName(), [250]);

        if ($this->cfg['secure'] === 'tls') {
            $this->command('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                throw new RuntimeException('No se pudo iniciar TLS con el servidor SMTP.');
            }
            $this->command('EHLO ' . $this->localName(), [250]);
        }
    }

    private function authenticate(): void
    {
        if ($this->cfg['username'] === '') {
            return;
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->cfg['username']), [334]);
        $this->command(base64_encode($this->cfg['password']), [235]);
    }

    private function buildMessage(
        string $to,
        string $subject,
        string $html,
        ?string $replyTo,
        ?string $replyToName
    ): string {
        $fromAddress = $this->cfg['from'];
        $fromName    = $this->encodeHeader($this->cfg['from_name'] ?: 'Abubu');
        $domain      = substr($fromAddress, strpos($fromAddress, '@') + 1) ?: 'abubu.com.pe';

        $headers   = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = sprintf('From: %s <%s>', $fromName, $fromAddress);
        $headers[] = sprintf('To: <%s>', $to);
        if ($replyTo !== null && $replyTo !== '') {
            $name = $replyToName !== null && $replyToName !== '' ? $this->encodeHeader($replyToName) : $replyTo;
            $headers[] = sprintf('Reply-To: %s <%s>', $name, $replyTo);
        }
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $headers[] = 'X-Mailer: Abubu Mailer';

        $body = chunk_split(base64_encode($html), 76, "\r\n");

        return implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    }

    private function encodeHeader(string $value): string
    {
        // Evita inyección de cabeceras y codifica en UTF-8
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/[\x80-\xFF]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private function localName(): string
    {
        $host = gethostname();
        if (!is_string($host) || $host === '') {
            return 'localhost';
        }
        return preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
    }

    /** @param int[] $expected */
    private function command(string $command, array $expected): void
    {
        $this->writeRaw($command . "\r\n");
        $this->expect($expected);
    }

    private function writeRaw(string $data): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('Sin conexión SMTP.');
        }
        $written = @fwrite($this->socket, $data);
        if ($written === false || $written < strlen($data)) {
            throw new RuntimeException('No se pudo escribir en el socket SMTP.');
        }
    }

    /** @param int[] $expected */
    private function expect(array $expected): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('Sin conexión SMTP.');
        }

        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // La última línea de una respuesta SMTP tiene un espacio en la 4ª posición
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('El servidor SMTP cerró la conexión.');
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException(sprintf('SMTP respondió %d: %s', $code, trim(substr($response, 4, 120))));
        }
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
