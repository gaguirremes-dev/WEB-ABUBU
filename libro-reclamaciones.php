<?php
declare(strict_types=1);

/**
 * Libro de Reclamaciones Virtual – Abubu
 * Conforme a la Ley N° 29571 (Código de Protección y Defensa del Consumidor)
 * y al Reglamento del Libro de Reclamaciones (D.S. N° 011-2011-PCM).
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('America/Lima');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

require_once __DIR__ . '/lib/ReclamoRepository.php';
require_once __DIR__ . '/lib/SmtpMailer.php';
require_once __DIR__ . '/lib/AntiSpam.php';
require_once __DIR__ . '/lib/Captcha.php';

const LR_MIN_SEGUNDOS    = 5;      // llenar el formulario más rápido = bot
const LR_MAX_SEGUNDOS    = 7200;   // la sesión del formulario vale 2 h
const LR_MAX_BODY_BYTES  = 65536;

$repo = new ReclamoRepository(__DIR__ . '/data');
$spam = new AntiSpam(__DIR__ . '/data');
$captcha = new Captcha(mail_config());

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}

/* ─────────────────────────── Helpers ─────────────────────────── */

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function input_attr(string $key, array $values): string
{
    return isset($values[$key]) ? ' value="' . h($values[$key]) . '"' : '';
}

function invalid_attr(string $key, array $errors): string
{
    return isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="err-' . h($key) . '"' : '';
}

function error_html(string $key, array $errors): string
{
    if (!isset($errors[$key])) {
        return '';
    }
    return '<span class="lr-error show" id="err-' . h($key) . '">' . h($errors[$key]) . '</span>';
}

function selected(string $value, string $current): string
{
    return $value === $current ? ' selected' : '';
}

function checked(string $value, string $current): string
{
    return $value === $current ? ' checked' : '';
}

function mail_config(): array
{
    $real    = __DIR__ . '/config/mail.php';
    $example = __DIR__ . '/config/mail.example.php';

    if (is_file($real)) {
        return require $real;
    }

    return require $example;
}

/** Registro interno de rechazos (error_log); nunca se muestra al visitante. */
function lr_block_log(string $reason): void
{
    error_log('[Libro de Reclamaciones] Bloqueado (' . $reason . ') desde ' . AntiSpam::clientIp());
}

/** El POST debe venir de este mismo sitio: se compara el host de Origin o, si falta, de Referer. */
function lr_same_origin(): bool
{
    $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($source === '') {
        $source = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    }
    if ($source === '') {
        return false;
    }

    $host = parse_url($source, PHP_URL_HOST);
    $port = parse_url($source, PHP_URL_PORT);
    if (!is_string($host) || $host === '') {
        return false;
    }
    if ($port) {
        $host .= ':' . $port;
    }

    $own  = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = strtolower($host);
    $own  = preg_replace('/^www\./', '', $own) ?? $own;
    $host = preg_replace('/^www\./', '', $host) ?? $host;

    return $own !== '' && $host === $own;
}

function fecha_larga(string $date): string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
              'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];

    return date('j', $ts) . ' de ' . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}

/* ─────────────────────── Envío de correos ─────────────────────── */

function lr_email_shell(string $title, string $bodyHtml): string
{
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f0f5f8;font-family:Arial,Helvetica,sans-serif;color:#1a2332;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f5f8;padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="background:#1f3a6f;padding:22px 26px;">'
        . '<div style="color:#ffffff;font-size:18px;font-weight:bold;">Abubu</div>'
        . '<div style="color:rgba(255,255,255,.75);font-size:12px;letter-spacing:.5px;text-transform:uppercase;margin-top:2px;">Libro de Reclamaciones</div>'
        . '</td></tr>'
        . '<tr><td style="padding:26px;font-size:14px;line-height:1.65;">' . $bodyHtml . '</td></tr>'
        . '<tr><td style="padding:16px 26px;background:#f7f9fc;border-top:1px solid #dce8f0;font-size:11px;color:#6b7a8d;line-height:1.6;">'
        . 'Este mensaje fue generado automáticamente por el Libro de Reclamaciones Virtual de Abubu.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function lr_rows(array $record): string
{
    $d = $record['datos'];

    $tipoReclamo = $d['tipo_reclamo'] === 'reclamo' ? 'Reclamo' : 'Queja';
    $bienTipo    = $d['bien_tipo'] === 'producto' ? 'Producto' : 'Servicio';

    $rows = [
        'N° de hoja'          => $record['codigo'],
        'Fecha de registro'   => date('d/m/Y H:i', strtotime($record['creado'])),
        'Tipo'                => $tipoReclamo,
        'Bien contratado'     => $bienTipo,
        'Nombre del consumidor' => $d['nombre'],
        'Documento'           => $d['doc_tipo'] . ' ' . $d['doc_numero'],
        'Domicilio'           => $d['domicilio'],
        'Teléfono'            => $d['telefono'],
        'Correo'              => $d['email'],
        'Menor de edad'       => !empty($d['menor']) ? 'Sí (representado por ' . $d['tutor_nombre'] . ' – ' . $d['tutor_documento'] . ')' : 'No',
        'Producto / servicio' => $d['bien_descripcion'],
        'Monto'               => $d['monto'] !== '' ? 'S/ ' . number_format((float) $d['monto'], 2, '.', ',') : 'No consigna',
        'Pedido / comprobante' => $d['pedido_ref'] !== '' ? $d['pedido_ref'] : 'No consigna',
        'Detalle'             => nl2br(h($d['detalle'])),
        'Pedido del consumidor' => nl2br(h($d['pedido'])),
        'Plazo de respuesta'  => fecha_larga($record['fecha_limite']) . ' (máximo 30 días calendario)',
    ];

    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">';
    foreach ($rows as $label => $value) {
        $html .= '<tr>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eef2f6;color:#6b7a8d;width:38%;vertical-align:top;">' . h($label) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eef2f6;color:#1a2332;">' . $value . '</td>'
            . '</tr>';
    }
    $html .= '</table>';

    return $html;
}

/**
 * Envía el aviso al proveedor y la constancia al consumidor.
 * El resultado distingue ambos envíos: si falla la copia al consumidor
 * (por ejemplo, un correo mal escrito) el reclamo igual queda registrado.
 *
 * @return array{ok:bool, consumer:bool, error:?string}
 */
function lr_send_emails(array $record): array
{
    $cfg = mail_config();
    $mailer = new SmtpMailer($cfg);

    $d = $record['datos'];
    $tipoReclamo = $d['tipo_reclamo'] === 'reclamo' ? 'Reclamo' : 'Queja';
    $asunto = sprintf('[Libro de Reclamaciones] %s – %s – %s', $record['codigo'], $tipoReclamo, $d['nombre']);

    $businessBody = '<h2 style="margin:0 0 6px;font-size:16px;color:#1f3a6f;">Nueva hoja de reclamación registrada</h2>'
        . '<p style="margin:0 0 18px;color:#6b7a8d;font-size:13px;">Se registró una hoja en el Libro de Reclamaciones Virtual.</p>'
        . lr_rows($record)
        . '<p style="margin:18px 0 0;font-size:12px;color:#6b7a8d;">Responder al consumidor dentro del plazo legal a <strong>' . h($d['email']) . '</strong>.</p>';

    $consumerBody = '<h2 style="margin:0 0 6px;font-size:16px;color:#1f3a6f;">Recibimos tu reclamo</h2>'
        . '<p style="margin:0 0 18px;color:#6b7a8d;font-size:13px;">Hola ' . h($d['nombre']) . ', registramos tu hoja de reclamaciones. '
        . 'Guarda este correo: es tu constancia.</p>'
        . lr_rows($record)
        . '<p style="margin:18px 0 0;font-size:12px;color:#6b7a8d;">Te responderemos a más tardar el <strong>' . h(fecha_larga($record['fecha_limite'])) . '</strong>. '
        . 'Si necesitas agregar información, responde a este correo indicando tu N° de hoja.</p>';

    $businessOk = false;
    $consumerOk = false;
    $fallas     = [];

    try {
        foreach ($cfg['to'] as $to) {
            $mailer->send(
                $to,
                $asunto,
                lr_email_shell($asunto, $businessBody),
                $d['email'],
                $d['nombre']
            );
        }
        $businessOk = true;
    } catch (Throwable $e) {
        $fallas[] = 'proveedor: ' . $e->getMessage();
        error_log('[Libro de Reclamaciones] Error SMTP (proveedor): ' . $e->getMessage());
    }

    // La copia al consumidor solo sale si el aviso interno ya fue entregado:
    // si falla el interno, el reclamo queda guardado y se atiende desde el archivo.
    if ($businessOk) {
        try {
            $mailer->send(
                $d['email'],
                'Constancia de tu reclamo ' . $record['codigo'] . ' – Abubu',
                lr_email_shell('Constancia de reclamo', $consumerBody),
                $cfg['from'],
                'Abubu'
            );
            $consumerOk = true;
        } catch (Throwable $e) {
            $fallas[] = 'consumidor: ' . $e->getMessage();
            error_log('[Libro de Reclamaciones] Error SMTP (consumidor): ' . $e->getMessage());
        }
    }

    return [
        'ok'       => $businessOk,
        'consumer' => $consumerOk,
        'error'    => $fallas === [] ? null : implode(' | ', $fallas),
    ];
}

/* ───────────────────────── Validación ───────────────────────── */

/**
 * @return array{0:array<string,string>,1:array<string,string>}
 */
function lr_validate(array $input): array
{
    $errors = [];
    $data   = [];

    // Texto UTF-8 válido, sin caracteres de control y sin enlaces en cantidad.
    // Solo detalle y pedido admiten saltos de línea; el resto es de una línea
    // (así ningún CR/LF llega a las cabeceras del correo).
    $multiline = ['detalle', 'pedido'];
    foreach (['nombre', 'doc_numero', 'domicilio', 'telefono', 'email', 'tutor_nombre', 'tutor_documento',
              'bien_descripcion', 'monto', 'pedido_ref', 'detalle', 'pedido'] as $key) {
        $raw = is_string($input[$key] ?? null) ? $input[$key] : '';
        $bad = !mb_check_encoding($raw, 'UTF-8');
        if ($bad) {
            $raw = '';
        }
        $raw = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $raw) ?? '';
        if (!in_array($key, $multiline, true)) {
            $raw = preg_replace('/\s+/u', ' ', $raw) ?? '';
        }
        $raw = trim($raw);
        $input[$key] = $raw;
        if ($bad) {
            $errors[$key] = 'El texto contiene caracteres no válidos.';
        } elseif (preg_match_all('~(?:https?://|www\.)~i', $raw) > 2
            || stripos($raw, '<a ') !== false
            || stripos($raw, '[url=') !== false) {
            $errors[$key] = 'No incluyas enlaces en este campo.';
        }
    }

    $data['tipo_reclamo'] = in_array($input['tipo_reclamo'] ?? '', ['reclamo', 'queja'], true)
        ? (string) $input['tipo_reclamo'] : '';
    if ($data['tipo_reclamo'] === '') {
        $errors['tipo_reclamo'] = 'Indica si es un reclamo o una queja.';
    }

    $data['bien_tipo'] = in_array($input['bien_tipo'] ?? '', ['producto', 'servicio'], true)
        ? (string) $input['bien_tipo'] : '';
    if ($data['bien_tipo'] === '') {
        $errors['bien_tipo'] = 'Selecciona si el bien contratado es un producto o un servicio.';
    }

    $data['nombre'] = trim((string) ($input['nombre'] ?? ''));
    if (mb_strlen($data['nombre']) < 5 || mb_strlen($data['nombre']) > 120) {
        $errors['nombre'] = 'Ingresa tu nombre completo (entre 5 y 120 caracteres).';
    }

    $docs = ['DNI', 'Carné de extranjería', 'Pasaporte', 'RUC'];
    $data['doc_tipo'] = in_array($input['doc_tipo'] ?? '', $docs, true) ? (string) $input['doc_tipo'] : '';
    if ($data['doc_tipo'] === '') {
        $errors['doc_tipo'] = 'Selecciona el tipo de documento.';
    }

    $data['doc_numero'] = strtoupper(trim((string) ($input['doc_numero'] ?? '')));
    if (!preg_match('/^[A-Z0-9\-]{6,15}$/', $data['doc_numero'])) {
        $errors['doc_numero'] = 'Ingresa un número de documento válido (6 a 15 caracteres).';
    }

    $data['domicilio'] = trim((string) ($input['domicilio'] ?? ''));
    if (mb_strlen($data['domicilio']) < 5 || mb_strlen($data['domicilio']) > 200) {
        $errors['domicilio'] = 'Ingresa tu domicilio completo.';
    }

    $data['telefono'] = trim((string) ($input['telefono'] ?? ''));
    if (!preg_match('/^[0-9+()\s\-]{6,20}$/', $data['telefono'])) {
        $errors['telefono'] = 'Ingresa un teléfono válido (solo números, +, espacios o guiones).';
    }

    $data['email'] = trim((string) ($input['email'] ?? ''));
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 120) {
        $errors['email'] = 'Ingresa un correo electrónico válido.';
    }

    $data['menor'] = !empty($input['menor']) ? '1' : '0';

    $data['tutor_nombre']    = trim((string) ($input['tutor_nombre'] ?? ''));
    $data['tutor_documento'] = strtoupper(trim((string) ($input['tutor_documento'] ?? '')));
    if ($data['menor'] === '1') {
        if (mb_strlen($data['tutor_nombre']) < 5) {
            $errors['tutor_nombre'] = 'Ingresa el nombre del padre, madre o tutor.';
        }
        if (!preg_match('/^[A-Z0-9\-]{6,15}$/', $data['tutor_documento'])) {
            $errors['tutor_documento'] = 'Ingresa el documento del padre, madre o tutor.';
        }
    }

    $data['bien_descripcion'] = trim((string) ($input['bien_descripcion'] ?? ''));
    if (mb_strlen($data['bien_descripcion']) < 3 || mb_strlen($data['bien_descripcion']) > 200) {
        $errors['bien_descripcion'] = 'Describe el producto o servicio contratado.';
    }

    $data['monto'] = trim((string) ($input['monto'] ?? ''));
    if ($data['monto'] !== '') {
        $monto = str_replace(',', '.', $data['monto']);
        if (!is_numeric($monto) || (float) $monto < 0 || (float) $monto > 1000000) {
            $errors['monto'] = 'Ingresa un monto válido en soles (opcional).';
        } else {
            $data['monto'] = number_format((float) $monto, 2, '.', '');
        }
    }

    $data['pedido_ref'] = trim((string) ($input['pedido_ref'] ?? ''));
    if (mb_strlen($data['pedido_ref']) > 60) {
        $errors['pedido_ref'] = 'El número de pedido no puede superar los 60 caracteres.';
    }

    $data['detalle'] = trim((string) ($input['detalle'] ?? ''));
    if (mb_strlen($data['detalle']) < 20 || mb_strlen($data['detalle']) > 2000) {
        $errors['detalle'] = 'Detalla lo ocurrido (mínimo 20 caracteres, máximo 2000).';
    }

    $data['pedido'] = trim((string) ($input['pedido'] ?? ''));
    if (mb_strlen($data['pedido']) < 10 || mb_strlen($data['pedido']) > 1000) {
        $errors['pedido'] = 'Indica qué solución solicitas (mínimo 10 caracteres).';
    }

    if (empty($input['acepta'])) {
        $errors['acepta'] = 'Debes aceptar la declaración para enviar la hoja.';
    }
    $data['acepta'] = '1';

    return [$data, $errors];
}

/* ───────────────────────── Controlador ───────────────────────── */

$errors      = [];
$values      = [];
$general     = null;
$constancia  = null;
$httpStatus  = 200;
$enviado     = false;

if (empty($_SESSION['lr_csrf'])) {
    $_SESSION['lr_csrf'] = bin2hex(random_bytes(16));
}
if (empty($_SESSION['lr_ts'])
    || ($_SERVER['REQUEST_METHOD'] !== 'POST' && time() - (int) $_SESSION['lr_ts'] > LR_MAX_SEGUNDOS)) {
    // Al cargar el formulario se renueva la marca de tiempo si ya venció
    $_SESSION['lr_ts'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = [
        'tipo_reclamo'      => post('tipo_reclamo'),
        'bien_tipo'         => post('bien_tipo'),
        'nombre'            => post('nombre'),
        'doc_tipo'          => post('doc_tipo'),
        'doc_numero'        => post('doc_numero'),
        'domicilio'         => post('domicilio'),
        'telefono'          => post('telefono'),
        'email'             => post('email'),
        'menor'             => post('menor'),
        'tutor_nombre'      => post('tutor_nombre'),
        'tutor_documento'   => post('tutor_documento'),
        'bien_descripcion'  => post('bien_descripcion'),
        'monto'             => post('monto'),
        'pedido_ref'        => post('pedido_ref'),
        'detalle'           => post('detalle'),
        'pedido'            => post('pedido'),
        'acepta'            => post('acepta'),
    ];

    $csrfOk    = hash_equals((string) ($_SESSION['lr_csrf'] ?? ''), post('csrf'));
    $honeypot  = post('website') !== '';
    $elapsed   = time() - (int) ($_SESSION['lr_ts'] ?? time());

    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > LR_MAX_BODY_BYTES) {
        lr_block_log('cuerpo excesivo');
        $httpStatus = 413;
        $general = 'No pudimos procesar el formulario. Inténtalo nuevamente.';
    } elseif (!lr_same_origin()) {
        lr_block_log('origen no coincide');
        $httpStatus = 403;
        $general = 'No pudimos procesar el formulario. Recarga la página e inténtalo nuevamente.';
    } elseif (!$csrfOk) {
        $general = 'Tu sesión expiró. Vuelve a cargar la página e inténtalo otra vez.';
        $_SESSION['lr_csrf'] = bin2hex(random_bytes(16));
        $_SESSION['lr_ts']   = time();
    } elseif ($honeypot) {
        lr_block_log('honeypot');
        $general = 'No pudimos procesar el formulario. Inténtalo nuevamente.';
    } elseif ($elapsed < LR_MIN_SEGUNDOS) {
        $general = 'El formulario se envió demasiado rápido. Revisa los datos e inténtalo otra vez.';
    } elseif ($elapsed > LR_MAX_SEGUNDOS) {
        $general = 'Tu sesión expiró. Vuelve a cargar la página e inténtalo otra vez.';
    } else {
        [$data, $errors] = lr_validate($_POST);

        // hCaptcha (fail-closed): después de las comprobaciones baratas y de la
        // validación de campos, y antes de reservar cupo o enviar correo.
        $captchaOk = true;
        if ($errors === []) {
            $captchaOk = $captcha->verify(post('h-captcha-response'), AntiSpam::clientIp(), $captchaReason);
            if (!$captchaOk) {
                lr_block_log('captcha ' . $captchaReason);
                $httpStatus = 400;
                $general = 'No pudimos verificar que eres una persona. Marca la casilla de verificación e inténtalo de nuevo.';
            }
        }

        // Límites por IP y globales, y contenido repetido: se reserva el cupo
        // de forma atómica recién cuando el formulario ya es válido.
        if (!$captchaOk) {
            // ya se informó arriba
        } elseif ($errors === []
            && !$spam->reserve(
                AntiSpam::clientIp(),
                mb_strtolower($data['email'] . '|' . $data['nombre'] . '|' . $data['detalle'] . '|' . $data['pedido']),
                $limitReason
            )) {
            lr_block_log('limite ' . $limitReason);
            $httpStatus = 429;
            $general = 'Registramos varios envíos desde tu conexión. Espera unos minutos o escríbenos a reclamaciones@abubu.com.pe.';
        } elseif ($errors === []) {
            try {
                $record = $repo->save($data);
                $envio  = lr_send_emails($record);
                $repo->markNotification($record['codigo'], $envio['ok'], $envio['error']);

                $_SESSION['lr_csrf']     = bin2hex(random_bytes(16));
                $_SESSION['lr_ts']       = time();

                if (!$envio['consumer']) {
                    $_SESSION['lr_aviso'] = 'Tu hoja quedó registrada correctamente, pero no pudimos enviar la copia a tu correo. '
                        . 'Guarda o imprime esta constancia desde esta página.';
                }

                header(
                    'Location: libro-reclamaciones.php?constancia=' . urlencode($record['codigo'])
                    . '&t=' . $record['token'],
                    true,
                    303
                );
                exit;
            } catch (Throwable $e) {
                error_log('[Libro de Reclamaciones] Error al guardar: ' . $e->getMessage());
                $general = 'No pudimos registrar tu hoja en este momento. Escríbenos a reclamaciones@abubu.com.pe y lo resolvemos de inmediato.';
            }
        }
    }

    if ($general !== null) {
        $_SESSION['lr_csrf'] = bin2hex(random_bytes(16));
        $_SESSION['lr_ts']   = time();
    }
}

if (isset($_GET['constancia'])) {
    $constancia = $repo->find((string) $_GET['constancia'], (string) ($_GET['t'] ?? ''));
    if ($constancia === null) {
        $httpStatus = 404;
    }
}

http_response_code($httpStatus);

$csrf   = (string) ($_SESSION['lr_csrf'] ?? '');
$pageTs = (int) ($_SESSION['lr_ts'] ?? time());

$aspectos = [
    'reclamo' => 'Disconformidad relacionada con el producto o servicio (por ejemplo: un producto defectuoso, un cobro incorrecto o un pedido que no llegó).',
    'queja'   => 'Disconformidad no relacionada con el producto o servicio, o malestar respecto a la atención recibida.',
];

$titulo = $constancia !== null
    ? 'Constancia ' . $constancia['codigo'] . ' – Libro de Reclamaciones | Abubu'
    : 'Libro de Reclamaciones – Abubu';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title><?= h($titulo) ?></title>
  <meta name="description" content="Libro de Reclamaciones Virtual de Abubu. Registra tu reclamo o queja en línea y recibe tu constancia por correo. Plazo de respuesta: 30 días calendario.">
  <meta name="robots" content="<?= $constancia !== null ? 'noindex, nofollow' : 'index, follow' ?>">
  <meta name="theme-color" content="#1f3a6f">
  <link rel="icon" href="img/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="img/favicon-192.png">
  <link rel="apple-touch-icon" href="img/apple-touch-icon.png">
  <link rel="canonical" href="https://abubu.com.pe/libro-reclamaciones.php">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/libro-reclamaciones.css">
</head>
<body>
<a href="#lr-main" class="skip-link">Saltar al contenido principal</a>

<?php $activePage = 'libro'; include __DIR__ . '/partials/site-header.php'; ?>

<main id="lr-main">

<?php if ($constancia !== null): ?>
  <?php
    $d    = $constancia['datos'];
    $tipo = $d['tipo_reclamo'] === 'reclamo' ? 'Reclamo' : 'Queja';
    $bien = $d['bien_tipo'] === 'producto' ? 'Producto' : 'Servicio';
  ?>

  <section class="lr-hero">
    <div class="container">
      <h1>Constancia de Reclamación</h1>
      <p>Tu hoja quedó registrada. Guarda esta constancia: es tu comprobante ante cualquier consulta o trámite.</p>
      <span class="legal-ref">Libro de Reclamaciones Virtual · Ley N° 29571</span>
    </div>
  </section>

  <section class="lr-section">
    <div class="lr-constancia">
      <?php if (!empty($_SESSION['lr_aviso'])): ?>
        <div class="lr-alert lr-alert-info" role="status">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 4a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0V7a1 1 0 0 1 1-1Zm0 11a1.25 1.25 0 1 1 0 2.5A1.25 1.25 0 0 1 12 17Z"/></svg>
          <div><?= h($_SESSION['lr_aviso']) ?></div>
        </div>
        <?php unset($_SESSION['lr_aviso']); ?>
      <?php endif; ?>

      <article class="lr-ticket">
        <header class="lr-ticket-head">
          <div>
            <h2>Hoja de Reclamación Virtual</h2>
            <p>Abubu · Tienda para bebés · Lima, Perú</p>
          </div>
          <div class="lr-code">
            <small>N° de hoja</small>
            <strong><?= h($constancia['codigo']) ?></strong>
          </div>
        </header>

        <div class="lr-ticket-body">
          <dl class="lr-dl">
            <dt>Fecha de registro</dt>
            <dd><?= h(date('d/m/Y H:i', strtotime($constancia['creado']))) ?> (hora de Lima)</dd>

            <dt>Tipo</dt>
            <dd><?= h($tipo) ?></dd>

            <dt>Bien contratado</dt>
            <dd><?= h($bien) ?>: <?= h($d['bien_descripcion']) ?></dd>

            <div class="lr-divider"></div>

            <dt>Consumidor</dt>
            <dd><?= h($d['nombre']) ?></dd>

            <dt>Documento</dt>
            <dd><?= h($d['doc_tipo']) ?> <?= h($d['doc_numero']) ?></dd>

            <dt>Domicilio</dt>
            <dd><?= h($d['domicilio']) ?></dd>

            <dt>Teléfono</dt>
            <dd><?= h($d['telefono']) ?></dd>

            <dt>Correo electrónico</dt>
            <dd><?= h($d['email']) ?></dd>

            <?php if (!empty($d['menor'])): ?>
              <dt>Representante</dt>
              <dd><?= h($d['tutor_nombre']) ?> – <?= h($d['tutor_documento']) ?> (consumidor menor de edad)</dd>
            <?php endif; ?>

            <?php if ($d['monto'] !== ''): ?>
              <dt>Monto reclamado</dt>
              <dd>S/ <?= h(number_format((float) $d['monto'], 2, '.', ',')) ?></dd>
            <?php endif; ?>

            <?php if ($d['pedido_ref'] !== ''): ?>
              <dt>Pedido / comprobante</dt>
              <dd><?= h($d['pedido_ref']) ?></dd>
            <?php endif; ?>

            <div class="lr-divider"></div>

            <dt>Detalle</dt>
            <dd><?= nl2br(h($d['detalle'])) ?></dd>

            <dt>Pedido del consumidor</dt>
            <dd><?= nl2br(h($d['pedido'])) ?></dd>

            <div class="lr-divider"></div>

            <dt>Plazo de respuesta</dt>
            <dd>Hasta el <?= h(fecha_larga($constancia['fecha_limite'])) ?> (máximo 30 días calendario)</dd>
          </dl>

          <p class="lr-ticket-foot">
            La formulación de este reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo
            para presentar una denuncia ante el INDECOPI. El proveedor deberá responder por escrito dentro del plazo máximo
            de treinta (30) días calendario.
          </p>
        </div>
      </article>

      <div class="lr-constancia-actions no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir / guardar PDF</button>
        <a href="libro-reclamaciones.php?nueva=1" class="btn btn-outline">Registrar otra hoja</a>
        <a href="index.html" class="btn btn-outline">Volver al inicio</a>
      </div>
    </div>
  </section>

<?php elseif ($constancia === null && isset($_GET['constancia'])): ?>

  <section class="lr-hero">
    <div class="container">
      <h1>No encontramos esa constancia</h1>
      <p>El código no es válido o la hoja no existe. Verifica el enlace del correo que recibiste.</p>
      <span class="legal-ref">Libro de Reclamaciones Virtual · Ley N° 29571</span>
    </div>
  </section>

  <section class="lr-section">
    <div class="lr-constancia">
      <div class="lr-constancia-actions">
        <a href="libro-reclamaciones.php" class="btn btn-primary">Registrar una hoja</a>
        <a href="index.html" class="btn btn-outline">Volver al inicio</a>
      </div>
    </div>
  </section>

<?php else: ?>

  <section class="lr-hero">
    <div class="container">
      <h1>Libro de Reclamaciones</h1>
      <p>Registra aquí tu reclamo o queja. Recibirás una constancia con tu número de hoja en el correo que indiques.</p>
      <span class="legal-ref">Ley N° 29571 y D.S. N° 011-2011-PCM</span>
    </div>
  </section>

  <section class="lr-section">
    <div class="lr-layout">

      <div class="lr-main">
        <?php if ($general !== null): ?>
          <div class="lr-alert lr-alert-error" role="alert">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 1 21h22L12 2Zm0 6a1 1 0 0 1 1 1v5a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1Zm0 9.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5Z"/></svg>
            <div><?= h($general) ?></div>
          </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
          <div class="lr-alert lr-alert-error" role="alert" id="lr-error-summary" tabindex="-1">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 1 21h22L12 2Zm0 6a1 1 0 0 1 1 1v5a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1Zm0 9.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5Z"/></svg>
            <div>
              <strong>Revisa los siguientes campos:</strong>
              <ul>
                <?php foreach ($errors as $campo => $mensaje): ?>
                  <li><a href="#<?= h($campo) ?>" style="color:inherit;text-decoration:underline;"><?= h($mensaje) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>

        <form method="post" action="libro-reclamaciones.php" class="lr-form" novalidate>
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="ts" value="<?= h((string) $pageTs) ?>">

          <div class="lr-hp" aria-hidden="true">
            <label for="website">No completar este campo</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
          </div>

          <!-- 1. Consumidor -->
          <section class="lr-block" aria-labelledby="lr-h1">
            <div class="lr-block-head">
              <span class="lr-step" aria-hidden="true">1</span>
              <div>
                <h2 id="lr-h1">Identificación del consumidor</h2>
                <p>Datos de la persona que presenta el reclamo.</p>
              </div>
            </div>

            <div class="lr-grid">
              <div class="form-group lr-full">
                <label for="nombre">Nombre completo <span class="lr-req">*</span></label>
                <input type="text" id="nombre" name="nombre" autocomplete="name" required
                       placeholder="Nombres y apellidos"<?= input_attr('nombre', $values) ?><?= invalid_attr('nombre', $errors) ?>>
                <?= error_html('nombre', $errors) ?>
              </div>

              <div class="form-group">
                <label for="doc_tipo">Tipo de documento <span class="lr-req">*</span></label>
                <select id="doc_tipo" name="doc_tipo" required<?= invalid_attr('doc_tipo', $errors) ?>>
                  <option value="">Selecciona</option>
                  <?php foreach (['DNI', 'Carné de extranjería', 'Pasaporte', 'RUC'] as $doc): ?>
                    <option value="<?= h($doc) ?>"<?= selected($doc, $values['doc_tipo'] ?? '') ?>><?= h($doc) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= error_html('doc_tipo', $errors) ?>
              </div>

              <div class="form-group">
                <label for="doc_numero">Número de documento <span class="lr-req">*</span></label>
                <input type="text" id="doc_numero" name="doc_numero" inputmode="numeric" required
                       placeholder="Ej. 45678912"<?= input_attr('doc_numero', $values) ?><?= invalid_attr('doc_numero', $errors) ?>>
                <?= error_html('doc_numero', $errors) ?>
              </div>

              <div class="form-group lr-full">
                <label for="domicilio">Domicilio <span class="lr-req">*</span></label>
                <input type="text" id="domicilio" name="domicilio" autocomplete="street-address" required
                       placeholder="Calle, número, distrito, ciudad"<?= input_attr('domicilio', $values) ?><?= invalid_attr('domicilio', $errors) ?>>
                <?= error_html('domicilio', $errors) ?>
              </div>

              <div class="form-group">
                <label for="telefono">Teléfono <span class="lr-req">*</span></label>
                <input type="tel" id="telefono" name="telefono" autocomplete="tel" required
                       placeholder="+51 999 999 999"<?= input_attr('telefono', $values) ?><?= invalid_attr('telefono', $errors) ?>>
                <?= error_html('telefono', $errors) ?>
              </div>

              <div class="form-group">
                <label for="email">Correo electrónico <span class="lr-req">*</span></label>
                <input type="email" id="email" name="email" autocomplete="email" required
                       placeholder="tucorreo@ejemplo.com"<?= input_attr('email', $values) ?><?= invalid_attr('email', $errors) ?>>
                <span class="lr-hint">Aquí enviaremos tu constancia y la respuesta.</span>
                <?= error_html('email', $errors) ?>
              </div>

              <div class="form-group lr-full">
                <label class="lr-accept" for="menor">
                  <input type="checkbox" id="menor" name="menor" value="1"<?= !empty($values['menor']) ? ' checked' : '' ?>>
                  <span>El consumidor es menor de edad. Completa los datos del padre, madre o tutor en los campos siguientes.</span>
                </label>
              </div>

              <div class="form-group">
                <label for="tutor_nombre">Nombre del padre, madre o tutor</label>
                <input type="text" id="tutor_nombre" name="tutor_nombre" autocomplete="off"
                       placeholder="Solo si es menor de edad"<?= input_attr('tutor_nombre', $values) ?><?= invalid_attr('tutor_nombre', $errors) ?>>
                <?= error_html('tutor_nombre', $errors) ?>
              </div>

              <div class="form-group">
                <label for="tutor_documento">Documento del padre, madre o tutor</label>
                <input type="text" id="tutor_documento" name="tutor_documento" autocomplete="off"
                       placeholder="DNI del tutor"<?= input_attr('tutor_documento', $values) ?><?= invalid_attr('tutor_documento', $errors) ?>>
                <?= error_html('tutor_documento', $errors) ?>
              </div>
            </div>
          </section>

          <!-- 2. Bien contratado -->
          <section class="lr-block" aria-labelledby="lr-h2">
            <div class="lr-block-head">
              <span class="lr-step" aria-hidden="true">2</span>
              <div>
                <h2 id="lr-h2">Identificación del bien contratado</h2>
                <p>Producto o servicio sobre el que presentas el reclamo.</p>
              </div>
            </div>

            <div class="lr-grid">
              <div class="form-group lr-full">
                <label id="label-bien-tipo">Bien contratado <span class="lr-req">*</span></label>
                <div class="lr-choices" role="radiogroup" aria-labelledby="label-bien-tipo">
                  <label class="lr-choice">
                    <input type="radio" name="bien_tipo" value="producto"<?= checked('producto', $values['bien_tipo'] ?? '') ?><?= invalid_attr('bien_tipo', $errors) ?>>
                    <span><strong>Producto</strong><span>Artículo comprado en la tienda.</span></span>
                  </label>
                  <label class="lr-choice">
                    <input type="radio" name="bien_tipo" value="servicio"<?= checked('servicio', $values['bien_tipo'] ?? '') ?>>
                    <span><strong>Servicio</strong><span>Atención, envío o asesoría recibida.</span></span>
                  </label>
                </div>
                <?= error_html('bien_tipo', $errors) ?>
              </div>

              <div class="form-group lr-full">
                <label for="bien_descripcion">Producto o servicio <span class="lr-req">*</span></label>
                <input type="text" id="bien_descripcion" name="bien_descripcion" required
                       placeholder="Ej. Pelelé azul talla 0-3 meses"<?= input_attr('bien_descripcion', $values) ?><?= invalid_attr('bien_descripcion', $errors) ?>>
                <?= error_html('bien_descripcion', $errors) ?>
              </div>

              <div class="form-group">
                <label for="monto">Monto reclamado (S/) <span class="lr-hint" style="display:inline">Opcional</span></label>
                <input type="number" id="monto" name="monto" min="0" step="0.01" inputmode="decimal"
                       placeholder="0.00"<?= input_attr('monto', $values) ?><?= invalid_attr('monto', $errors) ?>>
                <?= error_html('monto', $errors) ?>
              </div>

              <div class="form-group">
                <label for="pedido_ref">N° de pedido o comprobante <span class="lr-hint" style="display:inline">Opcional</span></label>
                <input type="text" id="pedido_ref" name="pedido_ref"
                       placeholder="Ej. AB-00123"<?= input_attr('pedido_ref', $values) ?><?= invalid_attr('pedido_ref', $errors) ?>>
                <?= error_html('pedido_ref', $errors) ?>
              </div>
            </div>
          </section>

          <!-- 3. Detalle -->
          <section class="lr-block" aria-labelledby="lr-h3">
            <div class="lr-block-head">
              <span class="lr-step" aria-hidden="true">3</span>
              <div>
                <h2 id="lr-h3">Detalle del reclamo</h2>
                <p>Cuéntanos qué pasó y qué solución esperas.</p>
              </div>
            </div>

            <div class="form-group">
              <label id="label-tipo-reclamo">Tipo <span class="lr-req">*</span></label>
              <div class="lr-choices" role="radiogroup" aria-labelledby="label-tipo-reclamo">
                <label class="lr-choice">
                  <input type="radio" name="tipo_reclamo" value="reclamo"<?= checked('reclamo', $values['tipo_reclamo'] ?? '') ?><?= invalid_attr('tipo_reclamo', $errors) ?>>
                  <span>
                    <strong>Reclamo</strong>
                    <span>Disconformidad con el producto o servicio: fallas, cobros indebidos, pedidos incompletos.</span>
                  </span>
                </label>
                <label class="lr-choice">
                  <input type="radio" name="tipo_reclamo" value="queja"<?= checked('queja', $values['tipo_reclamo'] ?? '') ?>>
                  <span>
                    <strong>Queja</strong>
                    <span>Malestar por la atención recibida, sin relación directa con el producto o servicio.</span>
                  </span>
                </label>
              </div>
              <?= error_html('tipo_reclamo', $errors) ?>
            </div>

            <div class="form-group">
              <label for="detalle">Detalle del reclamo o queja <span class="lr-req">*</span></label>
              <textarea id="detalle" name="detalle" required maxlength="2000"
                        placeholder="Describe los hechos: qué ocurrió, cuándo, con quién o con qué producto."<?= invalid_attr('detalle', $errors) ?>><?= h($values['detalle'] ?? '') ?></textarea>
              <span class="lr-hint">Mínimo 20 caracteres.</span>
              <?= error_html('detalle', $errors) ?>
            </div>

            <div class="form-group">
              <label for="pedido">Pedido del consumidor <span class="lr-req">*</span></label>
              <textarea id="pedido" name="pedido" required maxlength="1000"
                        placeholder="¿Qué solución solicitas? Ej. cambio del producto, devolución del monto, respuesta por escrito."<?= invalid_attr('pedido', $errors) ?>><?= h($values['pedido'] ?? '') ?></textarea>
              <?= error_html('pedido', $errors) ?>
            </div>
          </section>

          <!-- 4. Declaración -->
          <section class="lr-block" aria-labelledby="lr-h4">
            <div class="lr-block-head">
              <span class="lr-step" aria-hidden="true">4</span>
              <div>
                <h2 id="lr-h4">Declaración</h2>
                <p>Confirma que la información es veraz.</p>
              </div>
            </div>

            <label class="lr-accept" for="acepta">
              <input type="checkbox" id="acepta" name="acepta" value="1" required<?= !empty($values['acepta']) ? ' checked' : '' ?><?= invalid_attr('acepta', $errors) ?>>
              <span>
                Declaro que la información brindada es veraz y autorizo a Abubu a contactarme a través de los datos
                consignados para atender este reclamo. Entiendo que la formulación de este reclamo no impide acudir a
                otras vías de solución de controversias ni es requisito previo para denunciar ante el INDECOPI.
              </span>
            </label>
            <?= error_html('acepta', $errors) ?>

            <div class="lr-captcha">
              <div class="h-captcha" data-sitekey="<?= h($captcha->siteKey()) ?>" data-theme="light"></div>
              <noscript><p class="lr-error show">Activa JavaScript para completar la verificación anti-robots.</p></noscript>
            </div>

            <div class="lr-actions">
              <button type="submit" class="btn btn-primary">Enviar hoja de reclamación</button>
              <p class="lr-actions-note">Al enviar recibirás el número de hoja y una copia en tu correo. Plazo de respuesta: 30 días calendario.</p>
            </div>
          </section>
        </form>
      </div>

      <aside class="lr-aside">
        <div class="lr-card lr-card-highlight">
          <h3>Plazo de atención</h3>
          <p><strong>30 días calendario</strong> como máximo para darte una respuesta por escrito, conforme al Código de Protección y Defensa del Consumidor.</p>
        </div>

        <div class="lr-card">
          <h3>¿Reclamo o queja?</h3>
          <p><strong>Reclamo:</strong> <?= h($aspectos['reclamo']) ?></p>
          <p><strong>Queja:</strong> <?= h($aspectos['queja']) ?></p>
        </div>

        <div class="lr-card">
          <h3>¿Necesitas ayuda?</h3>
          <ul>
            <li>Correo: <a href="mailto:reclamaciones@abubu.com.pe">reclamaciones@abubu.com.pe</a></li>
            <li>WhatsApp: <a href="https://wa.me/51967285628" target="_blank" rel="noopener noreferrer">+51 967 285 628</a></li>
            <li>Atención: 24/7, todos los días</li>
          </ul>
        </div>

        <div class="lr-card">
          <h3>Importante</h3>
          <p>Este libro es gratuito y no reemplaza la denuncia ante el INDECOPI. La hoja queda registrada con fecha y hora, y recibirás una constancia.</p>
        </div>
      </aside>

    </div>
  </section>

<?php endif; ?>

</main>

<?php include __DIR__ . '/partials/site-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="js/main.js"></script>
<?php if ($constancia === null): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>
<script>
  (function () {
    // Enfoca el resumen de errores para lectores de pantalla y teclado
    var summary = document.getElementById('lr-error-summary');
    if (summary) { summary.focus(); }

    // "Nueva hoja" limpia el formulario
    if (location.search.indexOf('nueva=1') !== -1) {
      var form = document.querySelector('.lr-form');
      if (form) { form.reset(); }
      history.replaceState({}, '', 'libro-reclamaciones.php');
    }
  })();
</script>
</body>
</html>
