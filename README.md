# Menú digital con QR para restaurantes

Demo de venta para restaurantes y marisquerías (restaurante ficticio: **Marisquería La Gaviota**, Puerto Jelí, Santa Rosa, El Oro). PHP puro, sin WordPress.

## Qué hace

**Para el cliente (celular)**
- Menú por categorías con fotos, favoritos de la casa, platos agotados y búsqueda por categorías fija arriba.
- **QR por mesa** (`/?mesa=4`): el pedido llega a la **pantalla de cocina** con el número de mesa. No hace falta WhatsApp.
- Carrito con indicaciones por plato ("sin cebolla"), **en mesa, para llevar o a domicilio** (con costo de envío).
- Pago para llevar/domicilio: **efectivo o transferencia** (muestra banco, número de cuenta con botón Copiar y titular).
- Página del pedido **en vivo**: recibido → preparando (con minutos estimados) → listo → servido. Suena y vibra cuando está listo.
- Desde la mesa: **pedir algo más** (se suma al mismo pedido como "ronda 2"), **llamar al mesero** y **pedir la cuenta**.
- **Reservas**: formulario con fecha, hora y personas; el cliente ve si ya se confirmó.
- WhatsApp es **opcional** (copia del pedido o de la reserva), se activa en Ajustes.

**Para el restaurante (`/admin`)**
- **Cocina**: tablero en vivo (Nuevos, Preparando, Listos, Mesas atendidas). Un toque para dar el tiempo (10, 15, 20, 30, 45 min), +5 min, Listo, Servido y Cobrado. Avisos de "llama al mesero" / "pide la cuenta", sonido y pantalla completa.
- **Menú**: platos con foto, precio editable en la lista, interruptor **Disponible/Agotado**, favoritos, etiquetas y categorías.
- **Pedidos**: historial por día con totales (mesa, efectivo, transferencia).
- **Reservas**: confirmar, sin mesa, llegó, canceló, y botón para avisar al cliente por WhatsApp.
- **Mesas y QR**: imprime un QR por mesa y otro para pedidos para llevar.
- **Ajustes**: nombre, textos, dirección, mapa, horario, redes, tipos de pedido, envío, cuenta bancaria, reservas, WhatsApp, logo, colores y foto de portada.
- **Personal**: usuarios de cocina/meseros que solo ven Cocina y Reservas (sin precios ni ventas).
- **Respaldo**: descarga la base de datos (.sql) o un ZIP con base y fotos.

## Probar la demo en la computadora (sin configurar nada)

Sin `config/config.php`, el sistema crea solo una base **SQLite** en `storage/demo.sqlite` con el menú de ejemplo y dos usuarios:

| Usuario | Contraseña | Ve |
|---|---|---|
| `admin` | `gaviota2026` | Todo el panel |
| `cocina` | `cocina2026` | Cocina y Reservas |

```bash
php -S 0.0.0.0:8000 -t public
```

- Menú: http://localhost:8000 · Mesa 4: http://localhost:8000/?mesa=4 · Panel: http://localhost:8000/admin
- **Con XAMPP**: copia la carpeta en `htdocs/` (el `.htaccess` de la raíz sirve `public/`). Necesita la extensión `pdo_sqlite` (viene activa en XAMPP; si no, en `php.ini` quita el `;` de `extension=pdo_sqlite`).
- **Desde el celular** en el mismo WiFi: usa la IP de la computadora (por ejemplo `http://192.168.1.10:8000/?mesa=4`). En **Mesas y QR** escribe esa dirección para que los QR funcionen.
- En el **Resumen** del panel están los botones **Crear pedidos de ejemplo** y **Vaciar pedidos y reservas** para preparar cada visita.

Para una visita de venta: abre el panel en la laptop o tablet en **Cocina**, dale al cliente el celular con el QR de una mesa, que pida, y verá cómo llega el pedido y cómo cambia el estado en su celular.

## Usarlo para un restaurante real

1. Crea la base de datos:
   - **MySQL** (Hostinger, cPanel, XAMPP): crea la base y su usuario.
   - **Supabase** (PostgreSQL): en *Project Settings → Database* copia host, puerto, usuario y contraseña. El hosting debe tener la extensión `pdo_pgsql`.
2. Copia `config/config.example.php` como `config/config.php`, completa los datos y escribe una `admin_setup_key` larga.
3. Abre `/admin/`, escribe la clave para crear las tablas (se carga el menú de ejemplo) y crea tu usuario.
4. Borra `admin_setup_key` de `config/config.php`.
5. En **Ajustes**: cambia nombre, textos, horario, cuenta bancaria y desmarca «Mostrar el aviso Demostración». En **Menú** carga los platos reales con sus fotos. En **Mesas y QR** pon la dirección pública e imprime los códigos.

**Publicar**: sube todo el proyecto (por ejemplo a `public_html/` o `htdocs/`). El `.htaccess` de la raíz sirve `public/` y bloquea `config/`, `src/` y `storage/`. `public/uploads/` debe tener permiso de escritura para las fotos.

> El modo demo (SQLite) muestra los usuarios de prueba en la pantalla de ingreso. Para un restaurante real usa siempre `config/config.php` con MySQL o Supabase.

## Seguridad

- Contraseñas con `password_hash`, bloqueo de 15 minutos tras 5 intentos fallidos y sesiones con token CSRF en cada formulario.
- Los precios y totales se calculan en el servidor; el navegador solo manda qué platos y cuántos.
- Cada pedido tiene un código aleatorio: no se puede ver el pedido de otra mesa adivinando números.
- Fotos validadas como imagen real (máx. 4 MB), con nombre aleatorio; `uploads/.htaccess` impide ejecutar código.

## Archivos

- `public/`: menú (`index.php`), pedido (`pedido.php`), estado (`estado.php`), reservas (`reservar.php`) y el panel (`admin/`).
- `src/`: conexión (MySQL, PostgreSQL o SQLite), pedidos, menú, instalación y respaldo.
- `config/demo.php`: datos del restaurante de ejemplo (se copian a la base al instalar).
- Fuentes Fraunces e Inter (licencia SIL OFL) y generador de QR `qrcode-generator` (MIT) incluidos en `public/assets/`.
