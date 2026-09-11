# Levantar TR3SLOG en tu máquina

TR3SLOG está migrando su login, roles y permisos al SSO de MyGlobalHub (ver
`openspec/changes/integracion-sso/`). Mientras dura la transición, el bloque
`auth:sanctum` original sigue vivo: esta guía es sólo para el camino NUEVO, el
que pasa por el gateway.

**Todo lo que hay acá está probado.** Si algo no funciona como está escrito, es
un error de esta guía y hay que corregirlo, no un problema tuyo.

Esta guía es una adaptación 1:1 de la de MSH (`../MSH/SETUP_LOCAL.md`): mismo
gateway, mismo contrato de SSO, mismos cuatro modos de falla. Lo único que
cambia es el slug (`treslog` en vez de `msh`) y que acá hay DOS clientes en vez
de uno — una web Next y una app Flutter de conductores.

---

## Elegí un perfil

| | **A — SSO del VPS** ⭐ | **B — todo local** |
|---|---|---|
| Levantás | tu backend + gateway | tu backend + gateway + **el SSO entero** |
| Te logueás contra | identidades reales del VPS | usuarios de prueba tuyos |
| Podés romper el SSO | no | sí, y no molestás a nadie |
| Arranca en | ~2 minutos | ~15 minutos |

**Usá el A** salvo que vayas a tocar el SSO. El resto de la guía asume el A y
marca las diferencias del B donde las hay.

En los dos perfiles **tu backend y tu base de datos son locales.** Lo único que
sale a la red es la pregunta «¿este token vale?».

---

## Lo que necesitás pedir

Una sola cosa, y no está en el repo a propósito:

> **el `client_id` del cliente `frontend-dev` de `treslog`**

No es el de producción. Es un cliente OAuth aparte que existe justamente para
que `localhost` no quede registrado en el productivo. Si se filtra, se revoca
sin tocar a ningún usuario.

Quien administra el SSO lo crea así (`Docs/alta_de_aplicacion.md`,
`AppClientCommand.php`):

```bash
php artisan sso:app-client --app=treslog --kind=frontend-dev \
    --redirect=http://localhost:3200/login/sso/callback
```

Si además vas a probar el login desde la app de conductores en modo web
(`flutter run -d chrome`), pedí que agreguen también el redirect de ese puerto
— ver «Paso 3b» más abajo.

---

## Paso 1 — El backend

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve            # queda en http://localhost:8000
```

Hoy `auth:sanctum` sigue siendo el único guard montado sobre las rutas de
dominio (`routes/api.php`): nada de esto cambia todavía por el sólo hecho de
levantar el gateway. Recién cuando el Lote 5 monte
`Route::prefix('treslog')->middleware(['gateway.auth','gateway.user'])`
vas a poder pegarle a `/api/treslog/driver/*` con un token del SSO.

## Paso 2 — El gateway

```bash
cd gateway
cp env.example .env
docker compose up -d
```

Comprobalo:

```bash
curl -s localhost:8003/_health
# {"status":"up","component":"gateway","sso":"https://sso.mysocialhub.social",...}
```

`env.example` ya viene con el perfil A. Para el B, comentá esas líneas y
descomentá las del perfil B, que están abajo en el mismo archivo.

> No hay `Dockerfile` y es a propósito: la imagen oficial de nginx hace todo lo
> que necesitamos. Lo único propio es `templates/default.conf.template`, y eso
> se monta. Un Dockerfile sería una capa más para mantener y una versión de
> nginx que se desactualiza sola.

### Puerto 8003, no 8002 — y por qué importa

El gateway de MSH usa el puerto **8002**. El de TR3SLOG usa **8003**, a
propósito: los dos gateways son dos `docker compose` independientes (cada uno
en su propio `gateway/` de su propio repo), con su propia red y su propio
`container_name` (`msh-gateway` / `treslog-gateway`), así que **corren a la vez
en la misma máquina sin chocar**:

```bash
# En una terminal, desde el repo de MSH:
cd ../../MSH/gateway && docker compose up -d      # puerto 8002

# En otra, desde este repo:
cd gateway && docker compose up -d                # puerto 8003

docker compose -p msh-gateway ps 2>/dev/null; true
docker ps --filter "name=gateway"
# msh-gateway       0.0.0.0:8002->80/tcp
# treslog-gateway   0.0.0.0:8003->80/tcp
```

Cada uno valida contra el `X-Sso-Application` que le corresponde (`msh` o
`treslog`): comparten el mismo SSO_UPSTREAM pero cada uno filtra los roles de
su propia aplicación. Si alguna vez ves que un gateway devuelve roles que no
son los tuyos en esa app, lo primero a revisar es que el `.env` de ESE gateway
tenga el `GATEWAY_PORT` y el backend que corresponden — copiar un `.env` de un
gateway al otro es el error más fácil de cometer acá.

## Paso 3 — Los clientes

### 3a. La web (`tr3slog-website`, Next)

```bash
cd ../tr3slog-website
npm install
npm run dev                  # queda en http://localhost:3200
```

El puerto **3000 no es negociable**: está fijado en `vite.config.js:7`,
coincide con el default de `next dev`, y es el que el gateway tiene habilitado
en `map $http_origin $cors_origin` (`gateway/templates/default.conf.template`)
y el que vas a registrar como `redirect_uri` del cliente `frontend-dev`. Con
otro puerto, el SSO rechaza el login con `invalid_request` y CORS bloquea la
respuesta sin explicar por qué en la consola de red — sólo en la de JS.

### 3b. La app de conductores (`tr3slog_driver_app`, Flutter)

> **Todavía no funciona, y no es un problema tuyo.** La app de conductores no tiene el flujo de
> login OAuth PKCE contra el SSO: hoy se loguea contra `/api/driver/login` con usuario y
> contraseña de TR3SLOG (Lote 6, bloqueado por D6). Levantarla contra el gateway con esta
> configuración la deja sin poder entrar. Esta sección queda escrita para el día que exista;
> mientras tanto, la web Next y `curl` son los clientes con los que se prueba el camino nuevo.

El Lote 6 (build nueva de la app, login PKCE) todavía está bloqueado por D6 —
ver `openspec/changes/integracion-sso/4-tasks.md`. Hoy la app sigue con su
login viejo. Esta sección es para cuando ese lote se desbloquee, y para probar
el gateway en modo web mientras tanto:

```bash
cd ../tr3slog_driver_app
flutter run -d chrome --web-port=3201 \
    --dart-define=API_BASE_URL=http://localhost:8003/api/treslog
```

**3001 y no 3000**: ese puerto ya lo tiene la web Next, y las dos apps se
corren a la vez durante el desarrollo. Por eso `default.conf.template` habilita
`localhost:3201` en el `map` además de `:3000` — es la única entrada que
`3-design.md §G` no trae de MSH, porque TR3SLOG tiene dos clientes de
navegador y MSH tiene uno solo. En un emulador o dispositivo físico (el caso
normal de esta app) esta sección no aplica: CORS sólo lo exige un navegador, y
ahí no viaja `Origin`.

---

## Cómo saber qué se rompió

El gateway distingue cuatro situaciones y cada una dice qué hacer. **Leé el
`error`, no el código HTTP** — algunos comparten código.

| Respuesta | Qué pasó | Qué hacer |
|---|---|---|
| `401 unauthenticated` | no hay token, o venció | logueate de nuevo |
| `403 forbidden` | tu cuenta está inactiva o sin permisos | pedí que te la activen |
| `500 application_header_missing` | falta `set $sso_app` en el gateway | es config del gateway. **Reintentar no sirve** |
| `500 sso_unavailable` con «404» en el mensaje | el SSO al que apuntás **no tiene `/api/v1`** | revisá `SSO_UPSTREAM` en `gateway/.env` |
| `502 backend_unavailable` | el SSO te validó, pero **tu backend** no responde | ¿corriste `php artisan serve`? |
| `503 sso_unavailable` **con `Retry-After`** | el SSO no contestó a tiempo | esperá y reintentá |

> **La regla corta: si trae `Retry-After`, reintentá. Si no lo trae, no hay
> nada que reintentar** — es configuración, y hay que arreglarla.

Toda respuesta trae un `request_id`, en la cabecera y en el cuerpo. **Pasalo
cuando reportes algo:** es lo único con lo que se sigue una petición por los
logs del gateway, del SSO y del backend a la vez.

Estos cuatro modos y sus mensajes exactos están verificados contra este mismo
`gateway/` — ver la nota de estado de abajo.

---

## Estado verificado al 2026-09-10

```
https://sso.mysocialhub.social/api/v1/validate-token  -> 401  (desplegado)
https://sso.mysocialhub.social/api/validate-token     -> 401  (legada, viva)
https://sso.mysocialhub.social/oauth/authorize        -> 400  (existe)
```

A diferencia de la nota del `SETUP_LOCAL.md` de MSH (fechada 2026-09-09),
**`/api/v1` ya está desplegado en el VPS**: el perfil A sirve hoy para login,
perfil y roles, no sólo para login.

Se corrieron los cuatro modos de falla contra este `gateway/` (`docker compose
up -d` + un doble local en `SSO_UPSTREAM` y `TRESLOG_BACKEND`):

| Escenario forzado | Respuesta obtenida |
|---|---|
| `GET /_health` | `200`, JSON con el `SSO_UPSTREAM` configurado |
| Token inválido | `401 unauthenticated` |
| Token válido, backend caído | `502 backend_unavailable` |
| SSO inalcanzable | `503 sso_unavailable` con `Retry-After: 5` |
| Preflight `OPTIONS` desde `:3000` y `:3001` | `204`, con `Access-Control-Allow-Origin` reflejado |
| Preflight `OPTIONS` desde un origen no listado | `204`, sin `Access-Control-Allow-Origin` (el navegador lo bloquea) |

---

## Preguntas que ya nos hicieron

**¿Por qué el gateway y no llamar al SSO directo desde la web o la app?**
Para el perfil y los roles, los clientes **sí** llaman al SSO directo
(`GET /api/v1/user`, PKCE). El gateway es para las rutas de DOMINIO de TR3SLOG
(envíos, rutas, paradas, incidentes, nómina, cotizaciones, tickets): tu backend
necesita saber quién es la persona, y lo sabe porque el gateway se lo dice con
cabeceras que el cliente no puede falsificar. Son dos hosts y dos contratos.

**¿Puedo levantar sólo el gateway de TR3SLOG y no el de MSH (o viceversa)?**
Sí. Son dos `docker compose` completamente independientes — distinta red,
distinto `container_name`, distinto puerto. Ninguno depende de que el otro
esté corriendo.

**¿Puedo usar el `client_id` de producción y ahorrarme el pedido?**
Podés, y no lo hagas. Requiere registrar `http://localhost:3200` en el cliente
productivo, y esa puerta después queda abierta para siempre, en todas las
apps. El `frontend-dev` cuesta un comando (`5-auditoria.md` hallazgo 12).

**¿Por qué `treslog:driver` y no `conductor`, si el plan original decía
`conductor`?** El catálogo real cargado en el SSO usa los nombres en inglés
del código de TR3SLOG (`admin`, `driver`, `customer`, `operations`): son los
que ya existen en `roles`/`role_user`. Traducirlos rompería el mapeo contra el
código. Ver la nota de estado en la tarea de este lote.

**Cambié el `--dart-define` y no pasa nada.**
`String.fromEnvironment` se resuelve **al compilar**. Parar y volver a correr,
no hot reload.
