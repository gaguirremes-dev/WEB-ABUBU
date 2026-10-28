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
];
