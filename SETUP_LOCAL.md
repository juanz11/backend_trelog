# Levantar el backend de TR3SLOG en tu máquina

Por el camino del SSO, TR3SLOG ya no autentica: confía en las cabeceras `X-User-*`
que le pone el gateway, y el gateway valida el token contra el SSO. Esta guía te deja
**tu backend** corriendo con identidades reales, sin levantar el SSO.

**Todo lo que hay acá está probado.** Si algo no funciona como está escrito, es un
error de esta guía y hay que corregirlo, no un problema tuyo.

> Si vas a tocar **la web** y no el backend, no necesitás nada de esto: andá al repo
> `tr3slog-website` y corré `npm run dev`. Ese es el perfil A del equipo, y corre solo
> contra el VPS.

---

## Elegí un perfil

Estás en este repo porque tocás el backend. Entonces tu perfil es el **B**, salvo que
además vayas a tocar el SSO.

| | **B — tu backend local** ⭐ | **C — todo local** |
|---|---|---|
| Levantás | tu backend + el gateway | tu backend + el gateway + **el SSO entero** |
| Te logueás contra | el SSO del VPS | usuarios de prueba tuyos |
| Docker | sí (el gateway: un nginx) | sí (gateway + SSO) |
| Arranca en | ~3 minutos | ~15 minutos |

El gateway **no es el SSO**: es un contenedor de nginx sin estado que arranca en
segundos. Lo que pesa —Laravel, MySQL, Redis, Clerk— es el SSO, y solo lo levanta el
perfil C.

---

## Perfil B — tu backend local

### 1. El backend

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve            # queda en http://localhost:8000
```

### 2. Tu espejo

Por el camino del SSO, TR3SLOG **no crea usuarios**: la persona existe en el SSO y acá
tiene que existir una fila en `users` con su `sso_user_id`. Sin ella, la web te dice
«tu cuenta existe en el SSO pero no está dada de alta en TR3SLOG».

```bash
php artisan sso:espejo tu@correo.com <tu id en el SSO del VPS>
php artisan sso:espejo tu@correo.com <id> --conductor    # si vas a probar rutas de conductor
```

> **El id es del SSO contra el que trabajás.** La misma persona tiene un id en el SSO
> local y **otro** en el del VPS. Con el id cruzado, la web dice «no habilitada» y
> parece un fallo del SSO cuando es un dato mal copiado. Tu id del VPS lo ves en
> `https://sso.mysocialhub.social/api/v1/user` con tu token, o en el panel del SSO.
> Si te equivocaste, volvé a correr el comando con el id correcto: lo corrige, no
> duplica.

Los **roles** no viven acá: los decide el SSO y llegan en `X-User-Roles`. Si te falta
`treslog:admin` o `treslog:driver`, se pide en el SSO, no en esta base.

### 3. El gateway

```bash
cd gateway
cp env.example .env          # ya viene apuntando al SSO del VPS y a tu backend en :8000
docker compose up -d
curl -s localhost:8003/_health
# {"status":"up","component":"gateway","sso":"https://sso.mysocialhub.social",...}
```

### 4. Cómo sabés que quedó bien

| Petición | Respuesta esperada | Por qué |
|---|---|---|
| `curl localhost:8003/_health` | `"sso":"https://sso.mysocialhub.social"` | el gateway declara contra quién valida |
| `curl localhost:8003/api/treslog/me` sin token | `401 unauthenticated` con `request_id` | rechaza antes de tocar tu backend |
| `curl -H 'X-User-Id: 3' localhost:8000/api/treslog/me` | `401` | tu backend **no acepta cabeceras a mano**, solo las selladas por el gateway |

Y con un token real (el que la web obtiene al loguearse): `GET /api/treslog/me` responde
tu identidad con `sso_user_id` = el que pusiste en el espejo y tus roles del SSO.

> **La primera petición tarda.** PHP arrancando sobre un volumen de Docker en macOS
> puede tardar 10–15 segundos la primera vez; la segunda responde en menos de un
> segundo. Un `503 sso_unavailable` en el primer clic se reintenta una vez antes de
> buscar un problema.

Verificado el 2026-09-14 con un token real del SSO del VPS: `200` con la identidad, los
roles de producción y las rutas del conductor, gateway y backend locales.

### 5. La web

En el repo `tr3slog-website`: `npm run dev:backend-local`. Le habla a **tu** gateway
en `:8003` y se loguea contra el SSO del VPS.

---

## Perfil C — todo local

Es el B más el SSO en tu máquina (`SSO/`, `docker compose up -d`, queda en
`http://localhost`). Tres diferencias:

1. En `gateway/.env`, comentá las líneas del VPS y descomentá las del perfil local, que
   están en el mismo archivo.
2. El espejo lleva tu id **del SSO local** (`sso:espejo tu@correo.com <id local>`).
3. La web se levanta con `npm run dev:local`.

---

## Puerto 8003, no 8002 — y por qué importa

El gateway de MSH usa el **8002**; el de TR3SLOG, el **8003**. Son dos `docker compose`
independientes —cada uno en el `gateway/` de su repo, con su red y su `container_name`
(`msh-gateway` / `treslog-gateway`)— así que **corren a la vez** en la misma máquina.
Lo que sí chocan son los **backends**: los dos usan `:8000` por defecto. Si levantás
los dos, uno va a otro puerto y se lo decís a su gateway (`MSH_BACKEND` /
`TRESLOG_BACKEND` en el `.env` correspondiente).

Cada gateway declara su aplicación (`X-Sso-Application`: `msh` o `treslog`) y el SSO
filtra los roles a esa aplicación. Copiar el `.env` de un gateway al otro es el error
más fácil de cometer acá.

---

## Cómo saber qué se rompió

**Leé el `error`, no el código HTTP** — algunos comparten código.

| Respuesta | Qué pasó | Qué hacer |
|---|---|---|
| `401 unauthenticated` | no hay token, venció, **o lo revocó un logout desde otra app** | logueate de nuevo |
| `403 forbidden` «no está dada de alta en TR3SLOG» | falta tu **espejo**, o tiene el id de otro SSO | `php artisan sso:espejo` (paso 2) |
| `403 forbidden` en una ruta concreta | tu identidad no tiene el **rol** que esa ruta exige | se pide en el SSO |
| `403 wrong_channel` | el token es de **otra aplicación** (audiencia) | la web está usando un `client_id` que no es de `treslog` |
| `500 application_header_missing` | falta `set $sso_app` en el gateway | es config del gateway. **Reintentar no sirve** |
| `500 sso_unavailable` con «404» en el mensaje | el SSO al que apuntás **no tiene `/api/v1`** | revisá `SSO_UPSTREAM` en `gateway/.env` |
| `502 backend_unavailable` | el SSO te validó, pero **tu backend** no responde | ¿corriste `php artisan serve`? |
| `503 sso_unavailable` **con `Retry-After`** | el SSO no contestó a tiempo | esperá y reintentá |

> **La regla corta: si trae `Retry-After`, reintentá. Si no lo trae, no hay nada que
> reintentar** — es configuración, y hay que arreglarla.

Toda respuesta trae un `request_id`, en la cabecera y en el cuerpo. **Pasalo cuando
reportes algo:** es lo único con lo que se sigue una petición por los logs del gateway,
del SSO y del backend a la vez.

---

## Los dos clientes

- **La web** (`tr3slog-website`): ya entra por el SSO. Sus perfiles están en su
  `next.config.mjs`; no hay nada que pedir.
- **La app de conductores** (`tr3slog_driver_app`): **todavía no** entra por el SSO.
  Sigue con su login propio (`/api/driver/login`, usuario y contraseña de TR3SLOG),
  que este backend mantiene vivo en el camino viejo. Es el Lote 6 del plan, bloqueado
  hasta saber cuántas instalaciones reales hay.

---

## Preguntas que ya nos hicieron

**¿Por qué el gateway y no validar el token en el backend?**
Porque un middleware que autentique «por Sanctum o por cabeceras» es un `if` en el punto
más sensible del sistema, y ese `if` no se retira nunca. El gateway valida, el backend
confía en un sello que no se puede forjar desde afuera. Son dos piezas, y cada una hace
una cosa.

**¿Y un modo de desarrollo donde el backend acepte `X-User-*` sin gateway?**
No. Es exactamente el agujero de cabeceras falsificables con una bandera adelante, y una
bandera que arranca permisiva en local se queda permisiva. Levantar el gateway es un
`docker compose up`; es el precio correcto.

**Cerré sesión en otra app y TR3SLOG me sacó también. ¿Es un bug?**
No: el logout del SSO cierra la sesión en **todas** las aplicaciones. Es un solo botón
de salida.

**¿Por qué `treslog:driver` y no `conductor`?**
El catálogo del SSO usa los nombres en inglés del código de TR3SLOG (`admin`, `driver`,
`customer`, `operations`): son los que ya existían en `roles`. Traducirlos rompería el
mapeo contra el código.
