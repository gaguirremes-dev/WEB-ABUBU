<?php
/**
 * Configuración SMTP – Libro de Reclamaciones
 *
 * Copia este archivo como `config/mail.php` y coloca las credenciales reales.
 * `config/mail.php` está en .gitignore: NUNCA se sube al repositorio.
 */
return [
    'host'      => 'mail.abubu.com.pe',
    'port'      => 465,
    'secure'    => 'ssl',          // 'ssl' (465) | 'tls' (587) | 'none'
    'username'  => 'reclamaciones@abubu.com.pe',
    'password'  => 'CAMBIA_ESTA_CLAVE',
    'from'      => 'reclamaciones@abubu.com.pe',
    'from_name' => 'Libro de Reclamaciones – Abubu',
    'to'        => ['reclamaciones@abubu.com.pe'],
    'timeout'   => 15,

    // hCaptcha (https://dashboard.hcaptcha.com). Registrar abubu.com.pe en el
    // panel. Sin claves el envío del formulario se rechaza (fail-closed).
    'captcha'   => [
        'site_key'        => 'TU_SITE_KEY_AQUI',
        'secret'          => 'TU_SECRET_KEY_AQUI',
        'allow_localhost' => false,   // true solo en desarrollo local
    ],
];
