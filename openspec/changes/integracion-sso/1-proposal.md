# Propuesta: TR3SLOG deja de autenticar y consume el SSO de MyGlobalHub

**Rama**: `feat/sso-integration` · **Slug propuesto**: `treslog` (inmutable, ver Decisión D1)

---

## 1. Qué problema resuelve

TR3SLOG mantiene **tres sistemas de autenticación paralelos**, sin código compartido, sobre el
mismo modelo `User`:

| Controlador | Cliente | Registro/login | Reset de contraseña |
|---|---|---|---|
| `app/Http/Controllers/AuthController.php` | web Next | `:19`, `:60` | columnas propias `reset_token`/`reset_token_expires` |
| `app/Http/Controllers/Api/AuthController.php` | app clientes `/app/*` | `:22`, `:59` | tabla estándar `password_reset_tokens` |
| `app/Http/Controllers/Api/Driver/DriverAuthController.php` | app conductores | `:16`, `:57` | **no existe** |

Dos mecanismos de reset incompatibles para el mismo usuario, y un tercer cliente sin ninguno.

**El costo no es sólo duplicación: hoy hay agujeros abiertos.** Verificados leyendo el repo:

1. **Auto-escalación a admin desde una ruta pública.** `AuthController.php:25` valida
   `'role' => 'nullable|string|max:255'` y `:41-46` hace `$user->roles()->attach($role)` con el
   nombre que mandó el cliente. `routes/api.php:40` es pública. Un `POST /register` con
   `{"role":"admin"}` crea un administrador sin login previo.
2. **CRUD de roles y permisos sin ninguna guarda.** `routes/api.php:90-108` los monta bajo
   `auth:sanctum` solamente. Un grep de `authorize|hasRole|hasPermission|isAdmin|hasAnyRole|Gate::`
   sobre `app/Http/Controllers/` **no devuelve ni una línea** de `RoleController.php`,
   `PermissionController.php` ni `ZoneController.php`. Cualquier `customer` autoregistrado puede
   reescribir los permisos del rol `admin`.
3. **`invitations/*` sin autenticación**, con el comentario "without auth for now"
   (`routes/api.php:137-145`). `GET /invitations/pending` expone email y nombre de todas las
   invitaciones; `POST /invitations/bulk` manda correo ilimitado desde el dominio de TR3SLOG.

**Y no hay red de seguridad.** `tests/` contiene exactamente `Feature/ExampleTest.php`,
`Unit/ExampleTest.php` y `TestCase.php`. Cero tests de negocio, cero tests de autorización.

Los puntos 1-3 son explotables hoy y **no dependen del cronograma del SSO**. La migración los cierra
gratis, pero esperar a la migración completa para taparlos es una decisión que hay que tomar a
conciencia, no por omisión (Decisión D5).

---

## 2. Alcance

### Entra

- **A1** — Alta de `treslog` en el SSO: perfil, cliente OAuth público (PKCE), vínculo
  `application_clients` con `kind='frontend'`, catálogo de roles con prefijo `treslog:`.
- **A2** — Suite de tests de caracterización sobre las rutas de dominio **antes** de tocar auth.
- **A3** — Gateway NGINX local para TR3SLOG, adaptado de `MSH/gateway/`.
- **A4** — `AuthenticateFromGateway`, `RequireSsoRole`, `RequestContext` y `config/sso.php`,
  adaptados de MSH sin divergencias de contrato.
- **A5** — Migración de las rutas de dominio al gateway, por grupos, con las rutas viejas vivas.
- **A6** — Reemplazo del RBAC local por lectura de `X-User-Roles`.
- **A7** — Retiro de los tres controladores de auth y de las tablas `roles`/`permissions`/
  `role_permission`/`role_user`.
- **A8** — Clientes: web Next (login PKCE, `AppShell.jsx:33`, partir el `PUT` de perfil) y nueva
  build de la app de conductores.

### NO entra (explícito)

- **Migrar las cuentas existentes al SSO.** Es un proyecto propio: hay que partir `name` en
  `first_name`/`last_name` y crear identidades en Clerk. Sin esto resuelto, nada de A5 se despliega.
- **`UserInvitation`.** No tiene contraparte en el contrato del SSO. Se congela como está
  (con su agujero cerrado en A5) hasta la Decisión D4.
- **Permisos finos en el backend.** Ver §4: es una imposibilidad del contrato, no un recorte.
- **El tercer cliente `/app/*`.** No estaba en el alcance dado, pero existe
  (`routes/api.php:150-169`) y se rompe igual. Decisión D3.
- **Cliente confidencial `client_credentials`.** TR3SLOG no lo necesita para consumir cabeceras del
  gateway. Además hoy no hay camino genérico: `SSO/database/seeders/OAuthClientSeeder.php:157`
  cablea literalmente `where('slug','msh')`.
- **Datos de negocio en el perfil.** `company`, `business_name`, `street_address`, `zone`,
  `payment_method` se quedan en TR3SLOG. Ninguna de las 13 claves del SSO los cubre.
- **Reescribir las 9 FKs de dominio a string.** Ver Decisión D2.
- **Deploy a producción del gateway.** El SSO `/api/v1` todavía **no está en el VPS**
  (`contrato_api_v1_msh.md:353-355`). Es una dependencia externa, no nuestra.

---

## 3. Enfoque

Tres hallazgos del contrato y del código mandan sobre el diseño. Los tres son verificables:

**H1 — El backend no puede autorizar por permiso fino. Es una restricción, no una preferencia.**
`contrato_api_v1_msh.md:168-169`: "`Authorization` y `Cookie` no llegan. Su backend no ve el token".
`GET /api/v1/authorization` (que sí devuelve permisos) es del **canal persona** y exige el Bearer de
esa persona. El backend no lo tiene y no puede pedirlo. Por lo tanto **la única autorización posible
en el backend es por rol, leyendo `X-User-Roles`**. Los 29 permisos de `PermissionSeeder` no se
migran: se re-expresan como roles. No se pierde nada dinámico — hoy no existe `user_permission`,
todo permiso ya se resuelve vía rol.

**H2 — Las URLs de todos los endpoints cambian.** El gateway enruta en
`location /api/msh/` y hace `proxy_pass ${MSH_BACKEND}` sin componente de path
(`gateway/templates/default.conf.template:106,183` + `env.example:11`). NGINX pasa la URI completa:
el backend recibe `/api/msh/...`. Para TR3SLOG será `/api/treslog/...`. **Cada endpoint que hoy vive
en `/api/x` pasa a `/api/treslog/x`.** Ninguna exploración lo detectó y condiciona todo el §4.

**H3 — La app de conductores no se puede redirigir.** `api_service.dart:4-7` define `baseUrl` con
`String.fromEnvironment`, que es **constante de compilación**. Una app ya instalada tiene la URL
congelada en el binario. Sumado a H2, un corte tipo *flag day* deja a esos binarios pegándole a
rutas que ya no existen, sin forma de avisarles: un grep de
`force_update|minimum_version|version_check` sobre `lib/` devuelve **cero**.

**Mapeo de roles** (prefijo obligatorio, `alta_de_aplicacion.md:164-178`):

| Hoy | SSO |
|---|---|
| `customer` | `treslog:cliente` — y `default_role` de la app |
| `driver` | `treslog:conductor` |
| `operations` | `treslog:operaciones` |
| `admin` | `treslog:admin` — **NUNCA `Super Admin`** |
| `company`, `dispatcher`, `manager` | sin mapeo (D4) |

> **Dos trampas de este mapeo.** (a) `Super Admin` es rol de **plataforma**: viaja a *todas* las
> aplicaciones. Mapear el admin de TR3SLOG ahí le da poder de admin en todo el ecosistema.
> (b) Un rol sin `:` **no da error** — simplemente no viaja nunca, ni en `X-User-Roles` ni en
> `/api/v1/authorization`. La persona lo tiene asignado y la app no se entera. Con 9 controladores
> repitiendo `hasAnyRole(['admin','operations'])`, alcanza equivocarse una vez.

---

## 4. Qué se rompe y para quién

### Web Next (`tr3slog-website`)

| Endpoint hoy | Qué pasa | Reemplazo |
|---|---|---|
| `POST /register` (`routes/api.php:40`) | **muere** | `/oauth/authorize` + PKCE |
| `POST /login` (`:41`) | **muere** | ídem |
| `POST /forgot-password`, `/verify-reset-token`, `/reset-password` (`:42-44`) | **mueren** | los gobierna Clerk |
| `GET /user` (`:54`) | **muere** | `GET /api/v1/user`, **directo al SSO** |
| `POST /logout` (`:55`) | **muere** | `POST /api/logout` — cierra sesión en **todas** las apps |
| `PUT /users/{id}` (`:85`) | **se parte en dos** | perfil → `PUT /api/v1/profile` (7 claves); `company` y el resto → backend |
| dominio: `/quotes`, `/shipments`, `/drivers`, `/incidents`, `/addresses`, `/support` (`:56-134`) | sobreviven; **cambian de URL** (H2) y de guardia | `/api/treslog/...` con cabeceras |
| `POST /app/quotes`, `/app/quotes/track/{code}`, `/contact` (`:50,156-157`) | públicas, **sin cambios** | — |
| `roles/*`, `permissions/*` (`:90-108`) | **se eliminan** | catálogo en el SSO (`/apps/me`) |

**Rotura silenciosa ya garantizada**: `AppShell.jsx:33` hace
`user?.roles?.some(r => ['admin','operations'].includes(r.name))`. `GET /api/v1/user` **no devuelve
`roles`** (`contrato_api_v1_msh.md:221-224`), y cuando lleguen serán strings prefijados
(`treslog:admin`), no objetos `{name}`. Falla sin excepción: la UI de admin desaparece.

### App de conductores (`tr3slog_driver_app`)

| Endpoint hoy | Qué pasa |
|---|---|
| `POST /driver/register` (`:175`) | **muere.** El alta pasa a ser OAuth (ver D4) |
| `POST /driver/login` (`:176`) | **muere.** La pantalla de email+password deja de tener sentido |
| `GET /driver/me` (`:179`), `POST /driver/logout` (`:180`) | **mueren** |
| `/driver/dashboard`, `/routes`, `/routes/{id}`, `/stops/{id}/confirm`, `/stops/{id}/fail`, `/incidents`, `/payroll` (`:182-193`) | sobreviven; cambian URL (H2) y guardia |

Por H3 esto exige **build nueva y distribución**. Además hoy la app degrada mal: `_bootstrap()`
(`app.dart:84-90`) sólo comprueba que el token exista, nunca lo valida, y `DriverRepository` hace
`if (res.statusCode != 200) return;`. Con el token inválido el conductor ve un dashboard vacío o
viejo, **sin aviso y sin cierre de sesión** — en plena calle.

### App de clientes `/app/*`

`POST /app/register`, `/app/login`, `/app/forgot-password`, `/app/reset-password`
(`routes/api.php:151-154`), `GET /app/user` y `POST /app/logout` (`:160-161`) mueren igual.
No estaba en el alcance dado. **D3.**

---

## 5. Orden de despliegue

Es la decisión de más riesgo: hay conductores repartiendo. La regla es una sola:
**nunca se retira una ruta vieja antes de que exista un cliente que use la nueva.**

| # | Paso | Riesgo para clientes | Reversible |
|---|---|---|---|
| 1 | Alta de `treslog` en el SSO (A1). Config pura, ni una línea en TR3SLOG | **Ninguno** | Sí, borrando filas |
| 2 | **Tests de caracterización** (A2): congelan el comportamiento actual de las rutas de dominio | **Ninguno** | — |
| 3 | Gateway local + middleware + `config/sso.php` (A3, A4), **sin conectar a ninguna ruta** | **Ninguno** — código inerte | Sí |
| 4 | Cerrar los agujeros de §1 con `sso.role:treslog:admin` en `roles/*`, `permissions/*`, `zones/*`, `invitations/*` | Bajo: son rutas que hoy **nadie debería** estar usando legítimamente | Sí |
| 5 | Montar `/api/treslog/driver/*` por el gateway **en paralelo**, dejando `/api/driver/*` intacto | **Ninguno** — nada se retira | Sí |
| 6 | Build nueva de la app de conductores (PKCE + URL del gateway + arreglar el release URL, que hoy no resuelve) y distribución | **Ninguno mientras 5 esté en paralelo** | Sí |
| 7 | Web: login PKCE, `AppShell.jsx:33`, partir el `PUT` de perfil. Redeploy | Bajo: la web se redespliega en minutos, no hay binarios instalados | Sí |
| 8 | Resto de las rutas de dominio al gateway, en paralelo | Ninguno | Sí |
| 9 | **Retirar** `/api/driver/*`, los tres controladores de auth, Sanctum y las tablas de RBAC | **Alto, y es el único punto de no retorno** | No sin revertir migraciones |

El paso 9 **no tiene fecha**: se dispara por una métrica de adopción, no por calendario. Y esa
métrica hoy no se puede medir — no hay telemetría de versión en la app. Si D6 confirma que no hay
instalaciones en producción, los pasos 5-6 se colapsan y el paso 9 se adelanta.

La asimetría web/conductores es deliberada: la web no tiene binarios que proteger (`src/api.js:1-3`
lee la URL de entorno y se redespliega), la app de conductores sí (H3).

---

## 6. Riesgos y mitigación

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | **No hay tests.** Refactorizar autorización sin red es a ciegas | **Alta** | **Alto** | El paso 2 es **bloqueante**: nada de A5/A6/A7 arranca sin tests de caracterización por grupo de rutas (200/403 con y sin cabeceras, con y sin rol), más un test que falle si una ruta de dominio queda sin `gateway.auth`. No es "sería bueno": es el precio de entrada |
| R2 | Un conductor queda sin backend en plena calle | Media | **Alto** | Nunca retirar antes de tiempo (paso 9); la app degrada en silencio hoy — arreglarlo en la build del paso 6 |
| R3 | Rol mapeado sin prefijo `treslog:` → la persona queda bloqueada **sin síntoma** | **Alta** | Medio | Constantes en `config/sso.php`, nunca strings sueltos; test que falle ante cualquier rol sin `:` |
| R4 | `admin` mapeado a `Super Admin` → admin en **todas** las apps del ecosistema | Baja | **Crítico** | Prohibido explícitamente aquí; test que falle si `Super Admin` aparece en una guarda de TR3SLOG |
| R5 | Backend alcanzable sin pasar por el gateway → cualquiera manda `X-User-Id` y entra como quien quiera | Media | **Crítico** | El control real es de red (`AuthenticateFromGateway.php:17-20`): el backend no publica puerto al host. `gateway_signature` **no** es un secreto y no autentica nada |
| R6 | Se olvida la fila en `application_clients` | Media | Medio | Falla no obvia: **el login anda** y `/authorization` y `/apps/me` dan 403 (`alta_de_aplicacion.md:146-158`). Checklist del Paso 5 antes de escribir código |
| R7 | `default_role` nulo o inexistente → la persona entra **sin rol**, con `Log::warning`, sin error | Media | Alto | Verificar el catálogo antes del paso 5; el SSO hoy ni siquiera valida que el rol sea de esta app |
| R8 | Se copia el patrón de MSH creyendo que está probado | **Alta** | Medio | **No lo está.** `MSH/backend/bootstrap/app.php:34-38` dice textualmente que `jwt.draft` sigue vivo y que la migración "va en un commit propio". Ni una ruta de MSH está cortada, y no hay tests de gateway. El middleware es sólido y se adapta; el corte de rutas **lo estrena TR3SLOG** |
| R9 | El SSO `/api/v1` no está en el VPS (`contrato_api_v1_msh.md:353-355`) | Alta | Alto | Bloquea el despliegue, no el desarrollo: todo se hace contra el gateway local |
| R10 | Migración de cuentas existentes sin resolver | Alta | **Alto** | Fuera de alcance y **bloqueante del paso 5**. Sin identidades en Clerk, migrar una ruta deja afuera a todos los usuarios actuales |

---

## 7. Decisiones abiertas — **necesitan una persona**

> Ninguna se puede resolver leyendo el repo. D1, D2 y D6 bloquean el arranque.

| # | Decisión | Por qué no la puedo tomar yo | Bloquea |
|---|---|---|---|
| **D1** | ¿El slug es `treslog`? | **Inmutable** (`SlugIsImmutableException`). Prefija cada rol y cada URL del gateway. Cambiarlo después es una migración completa | Paso 1 |
| **D2** | **El ancla de identidad**: ¿`users` local sobrevive con una columna `sso_id`, o las 9 FKs pasan a string? Y si sobrevive, ¿ancla en `X-User-Id` o en `X-User-Clerk-Id`? | Hay una **contradicción en el contrato**: §3 dice que `X-User-Clerk-Id` "es la clave estable entre ambientes, **no** `X-User-Id`"; §4.1 dice que `clerk_id` no se devuelve porque "su referencia estable es `id`". No pueden ser las dos. Nueve tablas (`routes`, `incidents`, `payroll_periods`, `driver_alerts`, `shipments`, `addresses`, `support_tickets`, `driver_profiles`, `role_user`) cuelgan de esa columna. **Hay que preguntarle al equipo del SSO antes de elegir** | Pasos 5-9 |
| **D3** | ¿El tercer cliente `/app/*` está en producción? | No estaba en el alcance dado, pero existe y se rompe igual | Alcance |
| **D4** | Alta de conductores: `default_role` es **uno solo por slug** (`alta_de_aplicacion.md:197-217`), así que no distingue `treslog:cliente` de `treslog:conductor`. ¿Se usa `UserInvitation` como compuerta, o el alta pasa a ser manual? Y: ¿`company`, `dispatcher`, `manager` existen o se borran? | Decisión de producto. `company` tiene 0 permisos y 0 checks; `dispatcher` y `manager` ni siquiera existen como `Role` | Paso 1 (catálogo) |
| **D5** | Los tres agujeros de §1 son explotables **hoy**. ¿Parche inmediato en `main`, o se dejan morir en el paso 4? | Es una decisión de riesgo aceptado, con dueño. Mi recomendación: **el `/register` de `AuthController.php:25` se parchea ya** — es una línea y es escalación a admin desde internet | Nada, pero urge |
| **D6** | ¿Cuántas instalaciones reales tiene la app de conductores, y contra qué URL? | El release apunta a `https://api.tr3log.com/api`, que **no resuelve** — o los conductores usan una build con `--dart-define`, o no hay instalaciones. Si no las hay, los pasos 5-6 se simplifican y el 9 se adelanta semanas | Pasos 5, 6, 9 |

---

## 8. Tamaño: **esto no entra en una entrega**

Toca 3 controladores de auth, 4 Policies, 9 controladores con guardas a mano, `routes/api.php`
entero, 2 clientes y un servicio de infraestructura nuevo. Presentarlo como un PR es garantizar que
nadie lo revise de verdad. Corte propuesto, cada rebanada con inicio, fin y rollback propios:

| PR | Contenido | Se puede desplegar solo |
|---|---|---|
| **1** | Tests de caracterización del dominio (A2) | Sí — no cambia comportamiento |
| **2** | Alta en el SSO (A1) + `gateway/` (A3) + `SETUP_LOCAL.md` | Sí — nada del backend cambia |
| **3** | Middleware + `config/sso.php` + alias, **sin conectar** (A4) | Sí — código inerte |
| **4** | Cerrar los agujeros de §1 con `sso.role` | Sí |
| **5** | `/api/treslog/driver/*` en paralelo (A5 parcial) | Sí — nada se retira |
| **6** | Build de la app de conductores (A8 parcial) | Sí |
| **7** | Web: PKCE + `AppShell` + perfil partido (A8) | Sí |
| **8** | Resto del dominio al gateway (A5) + RBAC por rol (A6) | Sí |
| **9** | Retiro de auth, Sanctum y tablas RBAC (A7) | **Punto de no retorno** |

---

## Capabilities

### New Capabilities
- `autenticacion-por-gateway`: identidad resuelta desde cabeceras `X-User-*`, rechazo de todo lo que
  no venga del gateway, correlación por `request_id`.
- `autorizacion-por-rol-sso`: guarda por rol con prefijo `treslog:` sobre `X-User-Roles`, incluida
  la prohibición explícita de `Super Admin`.
- `identidad-espejo`: anclaje del dominio de TR3SLOG a la identidad del SSO (forma sujeta a D2).

### Modified Capabilities
- Ninguna: no existe `openspec/specs/` en este repositorio (verificado, el directorio no existía
  antes de este cambio).

---

## Plan de rollback

Por diseño, los PRs 1-8 son reversibles con `git revert` **sin pérdida de datos**: ninguno borra
tablas ni retira rutas. Las rutas viejas y Sanctum siguen operativos hasta el PR 9.

- **PRs 3-5, 8**: revertir el commit devuelve las rutas a `auth:sanctum`. Los clientes viejos nunca
  dejaron de funcionar, así que no hay nada que restaurar.
- **PR 6**: la build vieja sigue funcionando mientras el paso 5 esté en paralelo.
- **PR 9**: **no es reversible con un revert.** Borra tablas. Exige respaldo verificado de
  `users`, `roles`, `permissions`, `role_permission`, `role_user` y `personal_access_tokens`, y una
  ventana anunciada. No se ejecuta sin que D6 esté cerrada con datos.

---

## Dependencias

- **Externa, bloqueante para producción**: `/api/v1` del SSO no está desplegado en el VPS
  (`contrato_api_v1_msh.md:353-355`). Todo el desarrollo va contra el gateway local.
- **Externa, bloqueante para D2**: la contradicción `X-User-Id` vs `X-User-Clerk-Id` la resuelve el
  equipo del SSO.
- **Interna, bloqueante para el paso 5**: plan de migración de las cuentas existentes a Clerk.
- Distribución de la app de conductores (tiendas o canal propio).

---

## Criterios de éxito

- [ ] Existe una suite que falla si una ruta de dominio queda sin `gateway.auth`.
- [ ] Ninguna ruta de dominio responde 200 sin cabeceras del gateway.
- [ ] `POST /register` ya no acepta `role` (o el controlador no existe).
- [ ] `roles/*`, `permissions/*`, `zones/*` e `invitations/*` responden 403 a un `treslog:cliente`.
- [ ] Un grep de `Super Admin` en `app/` de TR3SLOG no devuelve nada.
- [ ] Todo rol referenciado en el código lleva el prefijo `treslog:`.
- [ ] `GET /api/v1/authorization` con un token de `treslog` devuelve `{"application":"treslog",...}`
      y **no** 403 (verifica el vínculo del Paso 2 del alta).
- [ ] El backend no publica puerto al host: sólo el gateway lo alcanza.
- [ ] Un conductor con la build nueva completa una ruta de punta a punta contra el gateway.
- [ ] Cero referencias a `Hash::check`, `createToken` y `auth:sanctum` al cerrar el PR 9.
