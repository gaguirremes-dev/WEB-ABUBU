# Libro de Reclamaciones Virtual – Abubu

Implementación del Libro de Reclamaciones conforme a la **Ley N° 29571** (Código de
Protección y Defensa del Consumidor) y al **D.S. N° 011-2011-PCM**.

## Requisitos del hosting

- **PHP 8.0 o superior** con las extensiones `openssl`, `mbstring` y `session`.
- Permiso de escritura en la carpeta `data/`.
- El correo `reclamaciones@abubu.com.pe` debe existir y aceptar SMTP en el puerto 465 (SSL).

> Si el hosting es solo estático (sin PHP), esta página no funcionará: el archivo `.php`
> se descargaría en lugar de ejecutarse.

## Archivos

| Archivo | Función |
| --- | --- |
| `libro-reclamaciones.php` | Formulario, validación, registro y constancia |
| `lib/SmtpMailer.php` | Cliente SMTP sin dependencias (SSL 465 / STARTTLS 587) |
| `lib/ReclamoRepository.php` | Guarda cada hoja como archivo y administra el correlativo |
| `config/mail.php` | Credenciales SMTP reales — **no se versiona** |
| `config/mail.example.php` | Plantilla de configuración |
| `css/libro-reclamaciones.css` | Estilos del formulario y de la constancia |
| `img/aviso-libro-reclamaciones.png` | Aviso del libro en el footer (recorte optimizado del póster) |
| `img/AvisoVirtual_page1.png` | Póster original de origen, sin modificar |
| `partials/site-header.php` / `site-footer.php` | Header y footer compartidos |
| `data/` | Registros y correlativo — **protegido, no accesible por web** |

## Puesta en producción

1. Subir todos los archivos al hosting.
2. Crear `config/mail.php` en el servidor (copiarlo de `config/mail.example.php`) con la
   contraseña real del buzón. No se sube al repositorio a propósito.
3. Verificar que `data/` tenga permisos de escritura (normalmente `755` o `775`).
4. Enviar una hoja de prueba y confirmar:
   - que llegue el aviso a `reclamaciones@abubu.com.pe`,
   - que llegue la constancia al correo del consumidor,
   - que el enlace del footer abra la página.

## Cómo revisar los reclamos

Cada hoja se guarda en `data/records/AB-AAAA-0001.php`. El archivo es JSON legible
precedido por una línea de protección; se puede abrir con cualquier editor de texto o
descargar por FTP. El número correlativo está en `data/counter.txt`.

## Notas de seguridad

- `config/mail.php` está en `.gitignore`: nunca se sube al repositorio.
- Los registros se guardan como `.php` con una guarda de salida, de modo que pedir su URL
  directamente devuelve `403` incluso en servidores que ignoran `.htaccess` (nginx).
- `data/.htaccess` y `config/.htaccess` bloquean el acceso web como segunda capa.
- Se recomienda cambiar la contraseña del buzón `reclamaciones@` por una más larga: es la
  misma clave de IMAP/POP3 y protege el historial completo de reclamos.

## Comportamiento esperado

- Plazo de respuesta mostrado al consumidor: **30 días calendario**.
- La constancia se puede imprimir o guardar como PDF desde el navegador.
- Si el correo del consumidor está mal escrito, el reclamo igual queda registrado y la
  constancia se muestra en pantalla con un aviso.
- Protecciones incluidas: CSRF, honeypot, tiempo mínimo de llenado, límite de 5 envíos por
  hora por sesión y escape de toda la salida.
