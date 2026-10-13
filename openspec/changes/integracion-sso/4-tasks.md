# Tareas: TR3SLOG consume el SSO de MyGlobalHub

**Cambio**: `integracion-sso` · **Fuentes**: `1-proposal.md`, `2-specs.md`, `3-design.md`

Cada lote mapea 1:1 con un PR de `1-proposal.md §8`. El orden es por dependencia real: los
tests van antes del refactor que cubren (Nivel 0/1/2 de `3-design.md §F.1`), y ningún lote
retira una ruta vieja antes de que exista un cliente para la nueva (`3-design.md §E.3`).

## Pronóstico de carga de revisión

| Campo | Valor |
|---|---|
| Líneas estimadas | ~3500-4500 en total, repartidas en 9 PRs |
| Riesgo de presupuesto de 400 líneas | Alto (varios lotes superan 400 por sí solos: 1, 3, 8, 9) |
| PRs encadenados recomendados | Sí |
| Corte sugerido | 9 PRs, uno por lote, en el orden de este documento |
| Estrategia de entrega | ask-on-risk (no se recibió otra explícita) |
| Estrategia de cadena | stacked-to-main — cada PR 1-8 se mergea solo y es reversible por `git revert` (`1-proposal.md`, Plan de rollback); el 9 es el único punto de no retorno |

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High
```

### Unidades de trabajo sugeridas

| Unidad | Objetivo | PR | Notas |
|---|---|---|---|
| 1 | Factories + caracterización del dominio | PR 1 | Base: `feat/sso-integration`. No toca auth. Sin bloqueo |
| 2 | Alta en el SSO + gateway local | PR 2 | Base: PR 1. **Bloqueado por D1** |
| 3 | Middleware + config, sin conectar | PR 3 | Base: PR 2. **Bloqueado por D1** (comparte el gate de PR 2) |
| 4 | Cierre de agujeros con `sso.role` | PR 4 | Base: PR 3. **Bloqueado por D1** |
| 5 | Rutas de conductor en paralelo | PR 5 | Base: PR 4. Sin bloqueo — nada se retira |
| 6 | Build de la app de conductores | PR 6 | Base: PR 5. D6 cerrada (2026-09-14): sin instalaciones, sin cuentas que migrar. **Sin bloqueo** |
| 7 | Web: PKCE + `AppShell` + perfil partido | PR 7 | Base: PR 4 (no depende de 5/6) |
| 8 | Resto del dominio al gateway + RBAC por rol | PR 8 | Base: PR 5 |
| 9 | Retiro de auth, Sanctum y tablas RBAC | PR 9 | Base: PR 8. D3 y D6 resueltas, sin cuentas que migrar. **Punto de no retorno** (respaldo + ventana) |

---

## Lote 1 — Factories y caracterización del dominio (PR 1)

No depende de nada. No toca una línea de auth: todo corre contra el sistema actual (`Sanctum`).

- [ ] 1.1 Crear las 8 factories de dominio que faltan en `database/factories/`: `AddressFactory`, `ShipmentFactory`, `SupportTicketFactory`, `RouteFactory`, `IncidentFactory`, `PayrollPeriodFactory`, `DriverAlertFactory`, `DriverProfileFactory` (hoy sólo existe `UserFactory`, ver `3-design.md §F`).
  > **A medias (2026-09-10):** hechas `DriverProfileFactory`, `DeliveryRouteFactory` y `RouteStopFactory` —las que el camino del conductor necesita— más `HasFactory` en esos tres modelos, que ninguno tenía (hallazgo 11 de la auditoría). **Corrección al plan:** no existe `RouteFactory`; el modelo es `DeliveryRoute` sobre la tabla `routes`, y Laravel deriva el nombre de la clase. Faltan 5.
- [x] 1.2 Test de caracterización de rutas de conductor (`routes/api.php:182-193`): `GET /driver/dashboard`, `/routes`, `/routes/{route}`, `POST /stops/{stop}/confirm`, `/stops/{stop}/fail`, `GET /incidents`, `POST /incidents`, `GET /payroll` — dueño 200, otro conductor 403 (`StopController.php:57`, `RouteController.php:24`). Va primero de todo el lote: es el único cliente que no se puede redesplegar (H3).
  > **Hecho (2026-09-10):** `tests/Feature/Driver/CaracterizacionRutasConductorTest.php`, 8 tests, 20 aserciones. **Con la invariante real, no la del plan:** en la lista (`GET /routes`) el aislamiento es por FILTRO —la ruta ajena no aparece—, y sólo `/routes/{route}` y `stops/{stop}/confirm|fail` dan 403 (hallazgo 14). Los tests capturan cada mecanismo por separado para que unificarlos cueste una decisión.
- [ ] 1.3 Test de caracterización de pertenencia en el resto del dominio: `ShipmentPolicy::view` (`ShipmentPolicy.php:17`), `AddressController.php:194`, `SupportController.php:21` — dueño ve, otro no.
- [ ] 1.4 ~~Test de caracterización N1/D7: congelar el `403` actual de un `admin`~~ en `PATCH /quotes/{quote}/status`, `PATCH /app/quotes/{quote}/status`, `GET /support`, `GET /support/{ticket}`, `PUT /support/{ticket}`, con el motivo en el nombre del test y comentario apuntando a D7 (`3-design.md §F.2`, `quotes.edit`/`support.*` no existen en `PermissionSeeder`).
  > **No se hace como está escrito:** la premisa es falsa. `Gate::before` le concede todo al `admin`, así que esos cinco endpoints NO le dan 403 (hallazgo 10 de la auditoría, verificado). Un test que congele un 403 inexistente falla el primer día. D7 se replantea sobre el diagnóstico real.
- [ ] 1.5 Test de caracterización: `GET /quotes` con `customer` → 403 hoy (`QuotePolicy::viewAny` sólo permite `operations`/`admin`, `3-design.md N1`).

---

## Lote 2 — Alta en el SSO + gateway local (PR 2)

> **D1 resuelto (2026-09-09):** la aplicación está dada de alta en el SSO con slug `treslog`, sus
> 5 roles reales (`admin`, `driver`, `customer`, `company`, `operations`) y 4 permisos, todos con
> prefijo `treslog:`. Cliente OAuth `frontend` creado con `sso:app-client`. Verificado: la misma
> persona obtiene `treslog:driver` aislado de `msh:user` y `tienda:vendedor`. **Los lotes 2, 3 y 4
> quedan desbloqueados.** Ojo: el plan nombra los roles en español; se usan los del código.

Config e infraestructura pura. Nada del backend de TR3SLOG cambia.

- [x] 2.1 Confirmar D1 con producto + equipo SSO antes de crear nada: el slug es inmutable (`SlugIsImmutableException`).
- [x] 2.2 Alta de la aplicación `treslog` en el SSO siguiendo `alta_de_aplicacion.md`: perfil, cliente OAuth público PKCE, vínculo `application_clients` con `kind='frontend'` (checklist contra R6 de la propuesta antes de escribir código).
- [x] 2.3 Cargar el catálogo de roles con prefijo `treslog:`: `cliente` (`default_role`), `conductor`, `operaciones`, `admin` — **nunca** `Super Admin` (`3-design.md §D.1`). Queda abierto cómo distinguir alta de `conductor` vs `cliente` (D4 acotada, fuera del alcance de este lote).
- [x] 2.4 Copiar `MSH/gateway/` a `treslog/backend_trelog/gateway/` con los 4 cambios: `location /api/treslog/`, `set $sso_app "treslog"`, `${TRESLOG_BACKEND}`, `container_name treslog-gateway` + puerto `8003` (`3-design.md §G`).
- [x] 2.5 Revisar (no copiar a ciegas) `map $http_origin $cors_origin`: dejar sólo los orígenes de TR3SLOG (web Next + Flutter en dev).
- [x] 2.6 Escribir `SETUP_LOCAL.md` de TR3SLOG adaptado de MSH, documentando cómo levantar los dos gateways (MSH y TR3SLOG) en la misma máquina sin choque de puertos.

---

## Lote 3 — Middleware e identidad espejo, sin conectar (PR 3)

> **Hecho (2026-09-10), 30 tests, 84 aserciones.** Divergencias justificadas en el código: el sello es
> CONSTANTE (sin `env()`) y falla cerrado si quedara vacío; `ResolveDomainUser` atrapa la
> `QueryException` del refresco del espejo (UNIQUE en email → sería 500 por petición); comparación
> con `!==` y no `hash_equals` porque no es un secreto. **3.6 a medias:** los alias están registrados,
> pero `RequestContext` NO se agregó al grupo `api` global — prependerlo cambia TODAS las rutas vivas
> en un PR que promete ser inerte; va cuando se migre el dominio. `SuperficieAbiertaSelloForjableTest`
> documenta el hallazgo 1 con asserts del comportamiento real.

Código inerte: nada se monta sobre una ruta todavía.

- [x] 3.1 Migración: agregar `users.sso_user_id` string(64) nullable único y `users.sso_clerk_id` string(64) nullable único (`3-design.md §A`).
- [x] 3.2 Copiar `RequestContext.php` de MSH; único cambio: prefijo del id generado `treslog-` en vez de `msh-` (`RequestContext.php:146`).
- [x] 3.3 Copiar `AuthenticateFromGateway.php` con 2 divergencias: slug de error `unauthenticated` (no `unauthorized`) con `request_id` en el cuerpo; sello del gateway comprobado PRIMERO y sin escape por `env()` vacío — es constante del contrato, no config de ambiente (`3-design.md §C.2`).
- [x] 3.4 Copiar `config/sso.php` íntegro, incluidos los comentarios de `§C.1`, agregando `'roles' => [...]` con prefijo `treslog:` para `admin`/`operaciones`/`conductor`/`cliente`.
- [x] 3.5 Crear `ResolveDomainUser` (alias `gateway.user`): `User::where('sso_user_id', $identity['id'])->first()`; si no hay fila → `403 forbidden` explícito, **sin** `firstOrCreate` por email; si la hay, refresca `sso_clerk_id`/`email`/`name` y `auth()->setUser($user)` (`3-design.md §A.2`).
- [x] 3.6 Registrar alias `gateway.auth`, `gateway.user`, `sso.role` sin montarlos sobre ninguna ruta.
- [x] 3.7 Copiar `RequireSsoRole.php` de MSH sin cambios.
- [x] 3.8 Test: tabla de verdad completa de `AuthenticateFromGateway` — sin `X-Auth-Gateway` → 401; valor forjado → 401; sello sin `X-User-Id` → 401 (no 500); sin `X-User-Name` → 200; sin `X-User-Roles` → roles vacíos, sin excepción.
- [x] 3.9 Test: `RequestContext` — `X-Request-Id` entrante se propaga al cuerpo del error; ausente → id generado, nunca vacío.
- [x] 3.10 Test: `ResolveDomainUser` — mismo `sso_user_id` en dos peticiones resuelve la misma fila; email coincidente sin `sso_user_id` → 403, nunca vínculo automático (invariante anti-secuestro de cuenta).

---

## Lote 4 — Cerrar los agujeros de §1 (+N3) con `sso.role` (PR 4)

> **Hecho (2026-09-10), 22 tests.** Con cuatro correcciones al plan, todas medidas con peticiones
> reales: **(4.1)** montaje PARALELO —`auth:sanctum`+`admin` en las URLs de hoy y `gateway.auth`+
> `sso.role:treslog:admin` bajo `/api/treslog/`— porque reemplazar apagaba las rutas mientras el
> gateway no esté en el VPS. **(4.2)** `invitations/verify` y `accept` siguen públicas: quien las llama
> es el invitado, que por definición no tiene cuenta; queda anotado el agujero de fuerza bruta sin
> rate limit. **(4.3)** quitar `role` del validate no arreglaba nada —el validador no filtra el input—;
> se dejó de LEER el campo. **(4.4)** N3 era falso: `POST /users` ya daba 403 a todos (guard `web`);
> se RETIRA. **(4.11)** escrito como allowlist congelada de rutas públicas, no como «toda ruta de
> dominio tiene gateway.auth», que nacería rojo cuatro lotes. Tests con Bearer REAL, no `actingAs`.

Sólo toca `roles/*`, `permissions/*`, `zones/*`, `invitations/*` y el alta pública. El resto del dominio sigue con `auth:sanctum` intacto: nadie pierde login.

- [x] 4.1 Montar `sso.role:treslog:admin` sobre `roles/*`, `permissions/*`, `zones/*` (`routes/api.php:90-108`).
- [x] 4.2 Montar `gateway.auth` como mínimo sobre `invitations/*` (`routes/api.php:137-145`), retirando el comentario "without auth for now".
- [x] 4.3 Quitar el campo `role` del `validate` de `AuthController::register` (`routes/api.php:40`, `AuthController.php:25,41-46`) para cerrar la auto-escalación, mientras el controlador siga vivo hasta el Lote 9.
- [x] 4.4 Retirar `POST /users` público (`routes/api.php:47`, `UserController::store`) o moverlo detrás de `auth:sanctum` + `treslog:admin` — cierre de N3.
- [x] 4.5 Test: `POST /register` con `{"role":"admin"}` ya no produce un admin.
- [x] 4.6 Test: `roles/*`, `permissions/*`, `zones/*` con `treslog:cliente` (o sin `X-User-Roles`) → 403.
- [x] 4.7 Test: `GET /invitations/pending` sin cabeceras de gateway → 401, no 200 con email y nombre.
- [x] 4.8 Test estructural: cada valor de `config('sso.roles')` empieza con `treslog:`; ningún archivo de `app/` contiene el literal `Super Admin` (R3/R4).
- [x] 4.9 Test: `X-User-Roles: Super Admin` sobre una ruta que exige `treslog:admin` → 403.
- [x] 4.10 Test: `X-User-Roles: tienda:vendedor` sobre la misma ruta → 403 (rol de otro inquilino).
- [x] 4.11 Test estructural: suite que enumera las rutas de `routes/api.php` y falla si una ruta de dominio no tiene `gateway.auth` (spec `Cobertura total de rutas de dominio`).

---

## Lote 5 — Rutas de conductor en paralelo por el gateway (PR 5)

> **Hecho (2026-09-10), 10 tests, verificado contra el gateway REAL en 8003.** Con una corrección
> al plan, MEDIDA: el bloque nuevo NO lleva `['gateway.auth','gateway.user']` a secas —eso dejaba
> entrar a `treslog:customer` a `/payroll` (hallazgo 9)— ni el middleware `driver` viejo, que da 403
> a un conductor real del SSO porque `hasRole()` lee `role_user` local (hallazgo 2). Lleva
> `gateway.auth` → `sso.role:treslog:driver` → `gateway.user`, en ese orden. `logout` no se monta
> por el camino del SSO: cerrar sesión de Sanctum no significa nada para una sesión que vive allá.

Nada se retira: `/api/driver/*` con `auth:sanctum` sigue vivo. Sin bloqueo — el riesgo de identidades sin migrar (R10) recién importa cuando haya tráfico real, en el Lote 6.

- [x] 5.1 Extraer las rutas de dominio del conductor (`routes/api.php:182-193`) a `routes/domain-driver.php` compartido, sin cambiar su contenido.
- [x] 5.2 Montar en `routes/api.php`: el bloque viejo `Route::middleware('auth:sanctum')` queda intacto + bloque nuevo `Route::prefix('treslog')->middleware(['gateway.auth','gateway.user'])->group(fn () => require domain-driver.php)` (`3-design.md §E.2`).
- [x] 5.3 Verificar que `StopController.php:57` y `RouteController.php:24` siguen comparando contra `$request->user()->id`, ahora poblado por `auth()->setUser()` de `ResolveDomainUser`.
- [x] 5.4 Correr contra el gateway local (Lote 2) los tests del Lote 1.2 apuntando a `/api/treslog/driver/*` y verificar los mismos 200/403.
- [x] 5.5 Test de cobertura: el grupo `/api/treslog/driver/*` aparece en el listado de rutas con `gateway.auth`.

---

## Lote 6 — Build nueva de la app de conductores (PR 6)

> D6 cerrada (2026-09-14, `8-respuestas-del-equipo.md`): no hay build instalada, la app se reescribe desde cero. Se puede desarrollar y publicar; la referencia de PKCE es la de MSH.
>
> **Hecho (2026-09-16).** App en `tr3slog_driver_app`, rama `sso/lote6-login-pkce`; 37 tests con
> `flutter test`, `flutter analyze` limpio. Dos correcciones al plan, con su porqué:
>
> - La referencia de MSH es **web-only** (`browser_navigator_stub.dart` tira `UnsupportedError`
>   fuera del navegador): en el teléfono el navegador es otro proceso, así que el login abre un
>   Custom Tab / `ASWebAuthenticationSession` (`flutter_web_auth_2`) y vuelve por un esquema
>   propio, `tr3slog://login/sso/callback`. Ese redirect se **sumó** al cliente `frontend` de
>   TR3SLOG en el SSO —el MISMO que usa la web— con una opción nueva del comando del SSO,
>   `sso:app-client --add`, porque la única alternativa que existía (`--force`) revocaba el
>   cliente entero y dejaba a la web afuera.
> - «Validar el token» (6.4) es `GET /driver/me` por el gateway, que responde a la vez si el
>   token vale (401) y si la persona sigue siendo conductora (403). El SSO deja entrar a
>   cualquier cuenta de MyGlobalHub; lo único que separa a un usuario de MSH de la nómina es el
>   rol `treslog:driver`, y un 403 en el login se explica y NO guarda sesión (revoca el token).
>
> 6.5 se resolvió con un workflow de GitHub Actions que compila el APK en cada push (la máquina
> de integración no tiene Android SDK ni Xcode). **iOS no se compiló** en este lote. La release
> se firma con la llave de debug (TODO heredado del equipo): sirve para instalar por APK, no para
> Play Store.

Requiere el Lote 5 desplegado.

- [x] 6.1 Confirmar D6: no hay instalaciones; la app apunta al backend Laravel de prueba del desarrollador (2026-09-14).
- [x] 6.2 `api_service.dart` apunta al gateway (`https://api.mysocialhub.social/api/treslog`) con `--dart-define`, mismo valor en debug y release a propósito; los cuatro valores documentados en `SETUP_LOCAL.md` de la app.
- [x] 6.3 Login PKCE contra el SSO (`services/pkce.dart`, `services/sso_auth.dart`, `services/auth_service.dart`); pantalla con un solo botón «Entrar con MyGlobalHub»; `register_screen.dart` y `login_fields.dart` retirados; tokens en `flutter_secure_storage`, no en SharedPreferences. Logout revoca en el SSO (`POST /api/logout`).
- [x] 6.4 `_bootstrap()` valida contra `GET /driver/me` (401 → un refresh y reintento; 403 → sin rol; sin red → se confía en la guardada). `DriverRepository` pasa todo por `_send`: 401/403 cierran la sesión y mandan al login con el motivo (`SessionEvents`), cualquier otro fallo avisa por SnackBar global en vez de `return;`. Una sola renovación en curso aunque cinco peticiones reciban 401 a la vez.
- [x] 6.5 `.github/workflows/apk.yml`: analyze + test + `flutter build apk --release`, APK como artefacto del run, `--dart-define` desde variables del repositorio. Manifest: `CallbackActivity` para `tr3slog://` y permiso `INTERNET` en el manifest principal (solo estaba en debug/profile).
- [ ] 6.6 Prueba en teléfono real: login, vuelta a la app, tablero con datos; y compilación iOS con Xcode. Pendiente de alguien con el toolchain.

---

## Lote 7 — Web: login PKCE, `AppShell`, perfil partido (PR 7)

> **Hecho (2026-09-10).** Web en `sso/lote7-web-login-pkce`, más UNA ruta aditiva en el backend
> (`GET /api/treslog/me`, rama `sso/lote1-red-de-seguridad`, 14 tests). 15 tests de la web con
> `node --test`, sin dependencias nuevas. Corrección al plan en 7.2: el `GET /user` **no** se
> reemplaza por `GET /api/v1/user` del SSO, porque el SSO NO DEVUELVE ROLES (contrato §4.1,
> explícito) ni conoce el id local del que cuelgan las nueve FKs del dominio. Sin roles, 7.3 no
> tiene de dónde leer: el plan se contradice consigo mismo. Por eso `/me` en el backend, detrás del
> gateway, que devuelve las tres cosas juntas. `POST /logout` sí va directo al SSO, como decía 7.2.
>
> **Auditado (2026-09-11, tercera ronda de `5-auditoria.md`).** Cuatro lentes a mano, sin agentes.
> Dos hallazgos, los dos corregidos en la web: las pantallas nuevas del login ignoraban el idioma
> (ahora trilingües vía `i18n-auth.js`) y un comentario de `config/sso.js` afirmaba una
> `redirect_uri` que no está registrada. OAuth/PKCE y `/me` sin hallazgos.

Bajo riesgo: la web se redespliega en minutos, no hay binarios instalados. Requiere el Lote 2 (cliente OAuth registrado).

- [x] 7.1 Implementar login por `/oauth/authorize` + PKCE en `tr3slog-website`, retirando las llamadas a `POST /register`, `/login`, `/forgot-password`, `/verify-reset-token`, `/reset-password` (`routes/api.php:40-44`).
  > **Hecho:** `src/lib/sso.js` (funciones puras, probadas), `src/config/sso.js`, `pages/login/sso/callback.jsx`. Borrados `AuthModal.jsx`/`.css`, `pages/reset.jsx`, `pages/reset-password.jsx`, y las cuatro funciones de `api.js`. Se borró además la entrada muerta de Vite (`src/App.jsx`, `src/main.jsx`, `index.html`, `vite.config.js`): era una segunda copia del flujo de auth, `vite` ni siquiera está en `package.json`, y dejar una copia muerta del login que acabamos de migrar es la trampa que alguien "arregla" el mes que viene.
- [x] 7.2 `GET /user` y `POST /logout` pasan a llamar directo a `GET /api/v1/user` y `POST /api/logout` del SSO, no al backend de TR3SLOG.
  > **A medias, y a propósito.** `POST /logout` sí: va a `POST ${SSO_URL}/api/logout` con Bearer, best-effort. `GET /user` **no**: se reemplaza por `GET /api/treslog/me` (nuevo, aditivo, backend). `GET /api/v1/user` del SSO devuelve trece claves y ninguna es `roles`, y tampoco el `users.id` local. Con el plan tal cual, `AppShell` se quedaba sin roles para leer.
- [x] 7.3 Arreglar `AppShell.jsx:33`: dejar de leer `user?.roles?.some(r => [...].includes(r.name))` (rotura garantizada, `GET /api/v1/user` no devuelve `roles`) y leer strings prefijados (`treslog:admin`) desde donde el SSO los exponga.
  > **Hecho:** `user?.is_admin === true`. La pregunta se responde una sola vez y en el backend (`MeController::alcanzaLaConsola`), no en cada cliente. `roles` viaja igual, como lista de strings filtrada a `treslog:`.
- [x] 7.4 Partir `PUT /users/{id}` (`:85`): las 7 claves del perfil van a `PUT /api/v1/profile` (SSO); `company` y el resto siguen al backend de TR3SLOG.
  > **Parcial, con el motivo medido.** De las tres claves editables del formulario, sólo `phone` es de las siete del SSO: se escribe en `PUT /api/v1/profile`. `company` sigue yendo al backend, como pedía la tarea. `name` **queda de sólo lectura**: el contrato §4.2 dice que es derivado de `first_name`+`last_name` y mandarlo es **422**, no un 200 que ignora la clave. Partirlo en dos inputs necesita tres claves de i18n en tres idiomas y decidir cómo se migra el `name` de la gente que ya existe: queda anotado como `@todo` en `Profile.jsx`. Las dos mitades se guardan e informan por separado, para que el 401 del backend legado no se coma el "teléfono guardado".
- [x] 7.5 Checklist manual: un login end-to-end completa el flujo PKCE contra el gateway local.
  > **Verificado hasta donde se puede sin navegador, y el límite es real:** el login del SSO es de Clerk (`POST /login/clerk` con un token de Clerk; no hay login por contraseña en `routes/web.php`), así que el salto del medio necesita una persona. Verificado con curl: (a) `GET /oauth/authorize` con challenge S256 válido → **302** a `/login?client_id=…`; (b) con `code_challenge=X` como decía el checklist → **400** `invalid_request`, "Code challenge must follow the specifications of RFC-7636" (el challenge tiene que ser de 43-128 caracteres base64url); (c) `GET /api/treslog/me` por el gateway con token real de `sso:token` → **200** con `roles:["treslog:driver"]`; (d) misma ruta con una identidad del SSO sin fila espejo → **403** `forbidden` con `request_id`, que es la pantalla "tu cuenta no está habilitada". El canje del código lo cubren los tests de `node --test`.

### Ruta aditiva en el backend, no estaba en el plan

- [x] 7.6 `GET /api/treslog/me` bajo `['gateway.auth','gateway.user']`, **sin** `sso.role`: preguntar quién soy no exige ser nada. Devuelve el id local, `sso_user_id`, nombre, correo, `company`, `phone`, `status`, los roles filtrados a `treslog:` y `is_admin` (admin u operations). Los roles salen de la identidad del request, **nunca** de `role_user` — para quien entra por el SSO esa tabla está vacía (hallazgo 2), y leerla devolvería `[]` sin error: un admin viendo el portal del cliente. `tests/Feature/Sso/MeTest.php`, 14 tests; la suite completa queda en **86 tests, 318 aserciones, verde** (incluido `ExampleTest`, que con el `.env` del stack de demo ya no está rojo).

### Lo que este lote NO arregla, y hay que decirlo

- La web entra con un token del SSO, y **todo el dominio sigue detrás de `auth:sanctum`**: envíos, cotizaciones, direcciones y soporte responden **401** hasta el Lote 8. Es el orden que eligió el plan, no una regresión de este lote. `src/api.js` los separa en `LEGACY_API_URL` con la nota.
- **El Lote 8 tiene un agujero en su enunciado.** §8.1 manda "el resto de rutas de dominio" detrás del gateway, pero tres de ellas son **públicas** y las usa gente sin cuenta: `POST /contact`, `POST /app/quotes` y `GET /app/quotes/track/{code}`. El gateway exige Bearer y responde 401 antes de tocar el backend (comprobado: `POST http://localhost:8003/api/treslog/contact` → 401). Necesitan decisión propia.
- `NEXT_PUBLIC_API_URL` **ya existía** en el ambiente de producción de la web apuntando al backend legado (comprobado sobre el bundle compilado, sin abrir ningún `.env`). El gateway estrena `NEXT_PUBLIC_GATEWAY_URL`: reusar la vieja habría mandado `GET /me` al backend viejo y el tráfico legado a `localhost`.

---

## Lote 8 — Resto del dominio al gateway + RBAC por rol (PR 8)

> **Hecho (2026-09-11, de madrugada, a mano y sin agentes).** Backend en la rama
> `sso/lote8-dominio-por-gateway` (worktree aparte, para no tocar el árbol que sirve la demo);
> web en `sso/lote8-web-dominio`. Suite del backend en verde con 4 archivos de tests nuevos;
> web 41/41 con `node --test`, lint limpio.
>
> **Decisiones tomadas para destrabar (D8.x), a falta del usuario dormido:**
> - **D8.1** Las rutas PÚBLICAS (`POST /contact`, `POST /app/quotes`, `GET /app/quotes/track/{code}`)
>   **no** van detrás del gateway: no llevan identidad y el gateway exige Bearer. La web las llama
>   por `PUBLIC_API_URL` (backend directo). Cierra el agujero del enunciado de §8.1.
> - **D8.2** Todo el dominio bajo `/api/treslog/*` lleva `gateway.auth` + `gateway.user`; la consola
>   de operaciones (choferes, incidentes, padrón, cotizaciones, cambiar/borrar envíos, alertas)
>   además `sso.role:treslog:operations,treslog:admin` (OR), definido en `routes/api.php` como
>   `$operaciones` y leído por `routes/domain.php`. Lo del cliente (direcciones, tickets, su
>   usuario, sus envíos, `pending-count`) queda con las Policies de pertenencia.
> - **D8.3** `hasPermission()` borrado; cada call site traducido por la matriz de §D.3. N1
>   (`quotes.*`, `support.*`) → `isAdmin()` con `@todo D7`.
> - **D8.4** `hasRole/hasAnyRole/isAdmin` con TRES comportamientos: (a) hidratada por
>   `ResolveDomainUser` → roles del SSO (acepta el nombre corto `admin` vía `config('sso.roles')`);
>   (b) no hidratada dentro de una petición del gateway → `LogicException`; (c) sin identidad de
>   gateway (Sanctum, jobs, tinker) → `role_user` como siempre. Así el camino viejo no pierde
>   acceso y el nuevo no puede caer en `[]` en silencio (hallazgo 2).
> - **D8.5** `routes/domain.php` montado DOS veces, como `admin-only.php` y `domain-driver.php`.
>
> **Dos hallazgos de la caracterización, dichos sin maquillaje:**
> - `UserController::clients()` daba **500 por los dos caminos** desde siempre: usaba `Role` sin
>   importar `App\Models\Role`. Corregido (un `use`), porque es un bug y no un cambio de
>   comportamiento; la consola de clientes de la web estaba rota antes de este lote.
> - `POST /quotes` **nunca estuvo protegido**: `QuoteController::store` no llama a
>   `authorize('create')`, así que la Policy de N1 era letra muerta ahí y operaciones creaba
>   cotizaciones desde siempre (201 por Sanctum). **No se cambió** (§D.5 prohíbe esconder un cambio
>   de comportamiento acá); se congeló tal cual por los dos caminos y va a **D7** con el dato.

El bloque `auth:sanctum` sigue montado en paralelo hasta el Lote 9: nadie pierde acceso.

- [x] 8.1 Extraer el resto de rutas de dominio (`quotes`, `shipments`, `drivers`, `incidents`, `addresses`, `support`, `payroll`, `dispatch`) a `routes/domain.php` compartido y montarlas en el bloque `Route::prefix('treslog')` junto a las del Lote 5.
- [x] 8.2 Eliminar `User::hasPermission()` (`User.php:71-78`) — no devuelve `false` ni `true`, se borra (`3-design.md §D.3`).
- [x] 8.3 Traducir las 20 llamadas a `hasPermission()` (N2) según la matriz de `§D.3`: `users.*` → `treslog:admin`; `drivers.manage`/`dispatch.manage`/`shipments.{edit,delete}`/`quotes.view` → `treslog:operaciones` o `treslog:admin`.
- [x] 8.4 `quotes.{create,edit,delete}` y `support.{view,edit}` (N1): traducir a la guarda más restrictiva `treslog:admin` con comentario `@todo D7` — no conceder acceso nuevo (`3-design.md §D.5`).
- [x] 8.5 `hasRole()`/`hasAnyRole()`/`isAdmin()` dejan de leer `role_user` y leen la identidad SSO hidratada por `ResolveDomainUser` en la instancia; si el modelo no fue hidratado (job en cola, `tinker`, query directa), los tres métodos **lanzan**, no devuelven `false` (`3-design.md §D.4`).
- [x] 8.6 Verificar que los call sites existentes (`DriverController.php:16,47`; `IncidentAdminController.php:15,26,50`; `UserController.php:169,191,194,240`) siguen recibiendo el usuario autenticado del request.
- [x] 8.7 Test: las 4 Policies (`Shipment`, `SupportTicket`, `Quote`, `User`) siguen resolviendo pertenencia igual que antes de tocar roles.
- [x] 8.8 Test: llamar `hasAnyRole()` sobre un `User` no hidratado (traído por `User::find()`) lanza excepción.
- [x] 8.9 Test de cobertura de guardas de rol: cada acción sensible de escritura/borrado/administración en los controladores de dominio tiene una verificación de rol explícita (spec `Cobertura total de guardas de rol`).
- [x] 8.10 Correr contra el gateway toda la suite de caracterización de los Lotes 1 y 5, verificando que los 403 de N1/D7 siguen dando 403 (no se "arreglan" solos).

---

## Lote 9 — Retiro de auth local, Sanctum y tablas RBAC (PR 9) **[punto de no retorno: respaldo y ventana; ya no bloqueado por decisiones]**

No reversible con `git revert`. Exige respaldo y ventana anunciada.

> **Hecho (2026-09-16).** Decisión del usuario: «que no queden cosas muertas». 114 tests verdes
> (`vendor/bin/phpunit`; `artisan test` sin `.env` en el contenedor reporta warnings espurios).
> Dos correcciones al plan, con su porqué:
>
> - **Listar por rol sin `role_user`.** La consola lista conductores y clientes, y el backend
>   solo conoce los roles de la persona de CADA petición. Un conductor pasa a ser «quien tiene
>   `driver_profiles`» (la fila nace en `POST /drivers`). Un cliente, «quien tiene
>   `treslog:customer` en `users.sso_roles`», una FOTO de los últimos roles que emitió el SSO
>   que `ResolveDomainUser` escribe en cada petición. La foto **no autoriza nada**
>   (`RolesSinHidratarLanzanTest` lo prueba: con la foto diciendo admin y el SSO diciendo
>   customer, gana el SSO); `hasRole()` fuera de una petición ahora **lanza** en vez de leer una
>   tabla que no existe.
> - **`DriverAuthController::me` sobrevive** como `Api\Driver\MeController`: la app de
>   conductores lo usa al abrir. El resto del controlador (`register`, `login`, `logout`) se fue.
>
> También se retiró lo que el plan no listaba pero era del mismo cadáver: el flujo viejo de
> invitaciones con contraseña (`UserInvitationController`, `user_invitations`, sus correos), los
> `Role`/`Permission` y sus controladores, los seeders de roles/admin, `IsAdmin`/`EnsureUserIsDriver`,
> `/app/*` entero, `POST /users` (una persona nace en el SSO) y `TestMail`. `Espejo::asegurar` ya no
> inventa contraseñas aleatorias. `Lote9RetiroTest` es la guarda: rutas viejas ausentes, esquema
> sin credenciales, y un grep de `Hash::check`/`createToken`/`auth:sanctum`/`Models\Role` sobre
> `app/`, `routes/`, `config/` y `bootstrap/` que tiene que dar vacío (9.9).

- [x] 9.1 Confirmar D3: `/app/*` no tiene consumidor (no existe app de clientes; el cliente es web). Se retira sin reemplazo (2026-09-14).
- [x] 9.2 D6: no hay instalaciones contra las rutas viejas (2026-09-14). Sin ventana de migración.
- [x] 9.3 Respaldo verificado antes de migrar: `treslog_en_vps.sh` vuelca la base entera (`mysqldump --single-transaction`) a `/root/respaldos/treslog-db-<sello>.sql` (600) y se niega a migrar si el volcado falla. El `down()` de la migración lanza a propósito: la vuelta atrás es ese volcado.
- [x] 9.4 Retirados `AuthController`, `Api\AuthController`, `DriverAuthController` (su `me` pasó a `Api\Driver\MeController`) y todas sus rutas; `routes/api.php` quedó con los cuatro montajes del gateway más el camino público.
- [x] 9.5 Retirados los tres montajes con `auth:sanctum` (dominio, admin, conductor); `domain.php`, `admin-only.php` y `domain-driver.php` se montan UNA vez, por el gateway.
- [x] 9.6 `2026_09_16_200000_lote9_retirar_autenticacion_local`: borra `role_user`, `role_permission`, `permissions`, `roles`, `personal_access_tokens`, `password_reset_tokens` y `user_invitations`; agrega `users.sso_roles`.
- [x] 9.7 Misma migración: fuera `password`, `remember_token`, `reset_token`, `reset_token_expires`. `User` sin `HasApiTokens`, sin `roles()`, sin `password` en fillable/casts.
- [x] 9.8 `composer remove laravel/sanctum` (json + lock); `config/sanctum.php` no existía; `sanctum/csrf-cookie` fuera de `config/cors.php`. Efecto colateral destapado: el provider de Sanctum resolvía el kernel HTTP al arrancar y por eso los tests estructurales veían los alias de middleware; ahora lo hace `tests/TestCase::setUp` a propósito.
- [x] 9.9 `Lote9RetiroTest::test_no_queda_ni_hash_check_ni_create_token_ni_auth_sanctum_en_app_ni_routes` (también `currentAccessToken`, `HasApiTokens`, `Models\Role`, `role_user`, `personal_access_tokens`; ignora comentarios), más el esquema y las rutas viejas ausentes.
- [x] 9.10 Sin ventana: no hay tráfico real que cortar (D3, D6, un solo ambiente de demostración). El usuario lo pidió para el mismo día. Desplegado con `treslog_en_vps.sh` (volcado previo incluido).
