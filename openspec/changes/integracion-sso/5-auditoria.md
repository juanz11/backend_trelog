# Auditoría del plan — 14 hallazgos confirmados

Cuatro jueces independientes leyeron los cuatro artefactos y los contrastaron **contra el
código real**, no contra los documentos. Cada hallazgo pasó después por dos escépticos con
lentes distintas —uno mirando el código, otro el plan completo— y **sólo sobrevivió lo que
ninguno pudo refutar**.

De 60 agentes, 59 terminaron. Sobrevivieron 14.

| Gravedad | Cuántos | Qué significa |
|---|---|---|
| **Rompe producción** | 9 | si se ejecuta como está escrito, algo deja de funcionar para gente real |
| **Bloquea el plan** | 2 | el plan no puede arrancar |
| **Corregible** | 3 | hay que arreglarlo, no detiene nada |

> **El plan NO se ejecuta como está.** Nueve hallazgos rompen producción, y dos de ellos
> dejan a los conductores sin poder entrar a la app que tienen instalada. El Lote 1, que el
> plan declara «sin bloqueos», tampoco arranca.
>
> Esto no invalida el trabajo: la estructura de 9 lotes y el orden general se sostienen. Lo
> que falla son supuestos concretos, y cada uno tiene su corrección escrita abajo.

---


## 1. El único control de seguridad real del diseño (aislamiento de red) es imposible durante toda la ventana de transición, y la spec lo da por cumplido

**ROMPE PRODUCCION** · lente `seguridad` · 2-specs.md, Requirement "Backend inalcanzable fuera del gateway" (:89-99) + 1-proposal.md R5 (:200) y criterio de éxito (:294)

**El plan afirma**
> "El control real es de red: el backend no publica puerto al host. gateway_signature no es un secreto y no autentica nada" (1-proposal.md:200) · "[ ] El backend no publica puerto al host: sólo el gateway lo alcanza" (:294)

**La realidad**
El gateway sólo define `location /api/treslog/` (adaptado de `default.conf.template:106`; el diseño §G:510-520 fija CUATRO cambios y "todo lo demás se copia literal", sin bloque de passthrough). Las rutas viejas —`/api/register`, `/api/login`, `/api/driver/login`, `/api/app/*`, todo el bloque `auth:sanctum`— NO tienen location en el gateway: nginx les devuelve 404. Los clientes las consumen pegándole directo al backend (`tr3slog-website/src/api.js:1-3` → `http://localhost:8000/api`). O sea: mientras el plan mantenga vivo el camino viejo (pasos 5 a 8, y el paso 9 "no tiene fecha", 1-proposal.md:183), el backend TIENE que estar publicado. Y publicado, `X-Auth-Gateway: myglobalhub-gateway` es una constante impresa en el repo (`default.conf.template:159`, `MSH/backend/config/sso.php:74`, y el propio diseño lo repite en :237-238).

**Evidencia**
`/Volumes/External/sources/myglobalhub/MSH/gateway/templates/default.conf.template:106,159 · /Volumes/External/sources/myglobalhub/MSH/backend/config/sso.php:57-74 · /Volumes/External/sources/myglobalhub/treslog/tr3slog-website/src/api.js:1-3 · /Volumes/External/sources/myglobalhub/treslog/backend_trelog/openspec/changes/integracion-sso/3-design.md:510-520`

**Qué pasa si se ejecuta así**
Desde el momento en que se despliega el Lote 5, cualquiera en internet manda al backend `POST /api/treslog/driver/stops/1/confirm` con `X-Auth-Gateway: myglobalhub-gateway`, `X-User-Id: <cualquiera>`, `X-User-Roles: treslog:admin` y entra como quien quiera, con el rol que quiera. Suplantación total, sin token, sin pasar por el SSO. Los dos escenarios negativos que la spec sí escribe (`X-Auth-Gateway` ausente → 401; valor forjado distinto → 401) NO cubren el único caso que importa: el valor forjado CORRECTO. La divergencia 2 del diseño (§C.2, sello primero y no apagable) suena a endurecimiento y no cambia absolutamente nada contra este ataque.

**Corrección**
O el gateway gana un `location /api/` de passthrough para las rutas viejas (quinto cambio, no cuatro), o la ventana de transición se declara explícitamente como período SIN el control de red, con una mitigación real y con fecha (allowlist de IP de origen en el backend, mTLS gateway↔backend, o un secreto rotado que sí sea secreto). La spec debe agregar el escenario "petición directa con X-Auth-Gateway correcto forjado" y decir qué la detiene. Hoy no la detiene nada.


---


## 2. La "regla dura" del Lote 8 (hasRole lanza si el modelo no fue hidratado) revienta las rutas sanctum que el mismo lote promete dejar vivas — el diseño dice haber verificado los call sites y se saltó tres

**ROMPE PRODUCCION** · lente `seguridad` · 3-design.md §D.4 (:320-337) y 4-tasks.md tarea 8.5 (:147), 8.6 (:148)

**El plan afirma**
> "si esa propiedad no fue hidratada (un User traído de una query, de un job en cola, de tinker), los tres métodos lanzan excepción" (3-design.md:327-328) + "Verificado que hoy todos los call sites pasan el usuario autenticado" (:334-337) · 4-tasks.md:141: "El bloque auth:sanctum sigue montado en paralelo hasta el Lote 9: nadie pierde acceso"

**La realidad**
`ResolveDomainUser` (`gateway.user`) se monta SÓLO en el bloque `Route::prefix('treslog')` (3-design.md §E.2, 4-tasks.md:108). El usuario que resuelve Sanctum en el bloque viejo nunca queda hidratado. Y hay tres llamadas a `hasRole()` que el §D.4 no enumeró, todas en el camino viejo y todas sobre modelos que no vienen del gateway: `EnsureUserIsDriver.php:13` (`$request->user()?->hasRole('driver')`, montado en `routes/api.php:178` sobre TODO `/api/driver/*`), `DriverAuthController.php:89` (`$user` sacado con `User::where('email',...)->first()`), y `DriverController.php:67` (`$user` sacado con `User::findOrFail()`).

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/backend_trelog/app/Http/Middleware/EnsureUserIsDriver.php:13 · app/Http/Controllers/Api/Driver/DriverAuthController.php:89 · app/Http/Controllers/DriverController.php:67 · routes/api.php:178 · openspec/changes/integracion-sso/3-design.md:334-337`

**Qué pasa si se ejecuta así**
Al mergear el PR 8, TODA petición a `/api/driver/*` (la app instalada, la que por H3 no se puede redesplegar) muere con 500 en el middleware `driver`, antes de tocar un controlador. `POST /driver/login` muere con 500: ningún conductor puede volver a entrar. `POST /drivers` (que la web usa hoy, `api.js:97`) muere con 500. El paso 8 está calificado "Riesgo para clientes: Ninguno" (1-proposal.md:180) y el Lote 8 dice "nadie pierde acceso": las dos afirmaciones son falsas. Y la tarea 8.6, que es la que debería atrapar esto, lista exactamente los call sites equivocados — repite los cuatro del §D.4 y omite los tres que rompen.

**Corrección**
La regla "lanza si no está hidratado" no puede convivir con el bloque sanctum. O `hasRole/hasAnyRole/isAdmin` siguen leyendo `role_user` mientras exista el bloque viejo y sólo se cambian en el PR 9 (misma rebanada que retira el bloque), o se introducen dos métodos distintos (`hasSsoRole()` para el camino nuevo, `hasRole()` legado intacto) y el PR 9 borra el segundo. Reescribir 8.6 enumerando los 3 call sites omitidos y agregar un test que ejerza `/api/driver/dashboard` y `POST /driver/login` por el camino sanctum DESPUÉS de aplicar 8.5.


---


## 3. Un cuarto agujero explotable que el plan no vio: POST /forgot-password devuelve el enlace de reseteo en el cuerpo de la respuesta cuando falla el envío de correo

**ROMPE PRODUCCION** · lente `seguridad` · 1-proposal.md §1 ("hoy hay agujeros abiertos. Verificados leyendo el repo", :20-33) — enumera tres, y el diseño agrega N3; ninguno es éste

**El plan afirma**
> §1 presenta el inventario de agujeros como cerrado y verificado: "Los puntos 1-3 son explotables hoy" (:38). El diseño agrega N3 y declara "Cuatro cosas que ni la exploración ni la propuesta detectaron" (3-design.md:18). Este camino no aparece en ninguno de los cuatro artefactos.

**La realidad**
`AuthController::forgotPassword` genera `$resetToken`, lo persiste en `users.reset_token` con una hora de validez, y si `Mail::to(...)->send(...)` lanza, el `catch` responde 200 incluyendo `'reset_link' => $resetLink` — la URL con el token y el email. `AuthController::resetPassword` (`routes/api.php:44`, público) sólo pide `token` y `password`: busca `User::where('reset_token', $request->token)`, sin exigir el email. `MAIL_MAILER=smtp` contra `smtp.gmail.com` (`.env.example:51-53`): una app password vencida, un rate limit de Gmail o un corte de red convierte esa rama en el camino normal. Además `:147-153` responde 404 "Email not found" cuando el email no existe: oráculo de enumeración, sin throttle.

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/backend_trelog/app/Http/Controllers/AuthController.php:156-183 (el `'reset_link' => $resetLink` está en :181, con el comentario "// Include link for development/testing") y :247-268 · routes/api.php:42,44 · .env.example:51-53`

**Qué pasa si se ejecuta así**
Con el SMTP caído, `POST /api/forgot-password {"email":"admin@tr3slog"}` devuelve el token y `POST /api/reset-password` cambia la contraseña: toma de cuenta de cualquier usuario, admin incluido, desde internet, sin credenciales. Es estrictamente más grave que el agujero #1 que la propuesta sí marcó (auto-registro como admin), y el plan lo deja vivo hasta el PR 9 — que "no tiene fecha" (1-proposal.md:183). El Lote 4 toca `AuthController::register` (tarea 4.3) y no toca `forgotPassword`, así que ni siquiera pasa cerca.

**Corrección**
Agregarlo a §1 como agujero 4 y a D5 como parche inmediato en `main`: borrar el `'reset_link'` del `catch` (:181), unificar la respuesta a un 200 genérico también cuando el email no existe (:147-153), y exigir `email` además de `token` en `resetPassword`. Tarea nueva en el Lote 4 al lado de 4.3, con su test.


---


## 4. El Lote 8 tira 500 en TODA la ruta vieja de Sanctum: login y operación de los conductores instalados incluidos

**ROMPE PRODUCCION** · lente `despliegue` · 4-tasks.md — Lote 8, tareas 8.5 y 8.8 (y 3-design.md §D.4)

**El plan afirma**
> 4-tasks.md:141: «El bloque `auth:sanctum` sigue montado en paralelo hasta el Lote 9: nadie pierde acceso». 4-tasks.md:147 (8.5): «si el modelo no fue hidratado (job en cola, `tinker`, query directa), los tres métodos **lanzan**, no devuelven `false`». 3-design.md:334-337: «Verificado que hoy **todos** los call sites pasan el usuario autenticado (... `DriverController.php:16,47`; `IncidentAdminController.php:15,26,50`; `UserController.php:169,191,194,240` ...). O sea que la regla no rompe nada hoy».

**La realidad**
La lista de call sites del diseño está incompleta y omite justamente los que corren sobre el camino viejo. Un grep de `hasRole|hasAnyRole|isAdmin` sobre `app/` devuelve cuatro más que el diseño nunca enumera: (1) `AuthServiceProvider.php:17` — un `Gate::before` que llama `$user?->isAdmin()` en CADA `$this->authorize(...)` de toda la app; (2) `EnsureUserIsDriver.php:13` — corre en CADA petición a `/api/driver/*`, que es el único camino de la app instalada (`routes/api.php:178`); (3) `DriverAuthController.php:89` — `$user->hasRole('driver')` sobre un `User::where('email',...)->first()`, o sea el LOGIN de la app de conductores; (4) `DriverController.php:67` — `$user->hasRole('driver')` sobre un `User::with('driverProfile')->findOrFail(...)`, un usuario que por construcción NUNCA puede estar hidratado (es el usuario objetivo, no el que hace la petición). Bajo Sanctum, `$request->user()` sale de la relación del PAT: jamás pasó por `ResolveDomainUser`, así que NO está hidratado.

**Evidencia**
`app/Providers/AuthServiceProvider.php:17; app/Http/Middleware/EnsureUserIsDriver.php:13; routes/api.php:178; app/Http/Controllers/Api/Driver/DriverAuthController.php:89; app/Http/Controllers/DriverController.php:65-67; app/Models/User.php:47-66; 4-tasks.md:141,147,150`

**Qué pasa si se ejecuta así**
El día que se mergea el Lote 8 —que NO está bloqueado por D6 ni por nada (4-tasks.md:38)— cada conductor con la app instalada recibe 500 en `/driver/login`, `/driver/dashboard`, `/driver/routes`, `/driver/payroll` y en la confirmación de paradas. No es degradación: es el backend caído para todos los que están en la calle, y ocurre en el lote que el propio documento describe como «nadie pierde acceso». La web cae igual en todo lo que pase por una Policy (el `Gate::before` lanza antes de evaluar nada). Y `DriverController.php:67` queda roto de forma PERMANENTE, también después del Lote 9: `POST /drivers` (que la web llama, `src/api.js:97`) tira 500 para siempre.

**Corrección**
La regla «lanza si no está hidratado» no puede convivir con el bloque `auth:sanctum` montado. O el Lote 8 devuelve `false` mientras exista el camino viejo y recién lanza en el Lote 9, o `hasRole/hasAnyRole/isAdmin` distinguen el origen del usuario (hidratado → cabeceras; autenticado por Sanctum → `role_user`) hasta el retiro. Además hay que reescribir la lista de call sites de 3-design.md §D.4 con los cuatro omitidos, y `DriverController.php:67` y `DriverAuthController.php:89` necesitan una respuesta propia porque operan sobre usuarios que nunca estarán hidratados.


---


## 5. El Lote 7 deja a la web con un token que ningún endpoint de dominio acepta, y ningún lote repunta nunca `src/api.js`

**ROMPE PRODUCCION** · lente `despliegue` · 4-tasks.md — Lote 7 completo (7.1, 7.2, 7.4) y su encabezado

**El plan afirma**
> 4-tasks.md:129: «Bajo riesgo: la web se redespliega en minutos, no hay binarios instalados». 7.1 retira las llamadas a `POST /login`/`/register`/etc. y las reemplaza por PKCE. 7.4: «`company` y el resto siguen al backend de TR3SLOG». La tabla de unidades (4-tasks.md:37) no le pone ningún bloqueo: «Base: PR 4 (no depende de 5/6)».

**La realidad**
Después de 7.1 el navegador guarda un token del SSO (`App.jsx:64` lo mete en `tr3slog-token`) y `src/api.js:11` lo manda como `Authorization: Bearer` a 22 endpoints de dominio: `/quotes`, `/shipments`, `/drivers`, `/incidents`, `/addresses`, `/support`, `/users/clients`, `/users/{id}` (api.js:75-153). Todos siguen montados bajo `auth:sanctum` (routes/api.php:53-135) hasta el Lote 9. Sanctum busca ese string en `personal_access_tokens` y no lo encuentra: 401 en todo. El montaje de esas rutas en el gateway recién llega en el Lote 8 (4-tasks.md:143), DESPUÉS del 7. Y un grep de `api.js|API_URL|NEXT_PUBLIC` sobre los cuatro artefactos devuelve una sola línea, y es una nota de prosa (1-proposal.md:187): NINGÚN lote repunta la base de la web a `/api/treslog/...`. Encima `GET /api/v1/user` (7.2) no está en producción: el contrato dice «rama `feat/sso-api-gateway`, local» y aclara que «El VPS tiene hoy el prefijo viejo y `POST /api/logout`».

**Evidencia**
`tr3slog-website/src/api.js:11,75-153; tr3slog-website/src/App.jsx:64; routes/api.php:53,120-134; 4-tasks.md:37,129,131,132,134,143; SSO/Docs/contrato_api_v1_msh.md:340,353-355`

**Qué pasa si se ejecuta así**
Desplegar el Lote 7 tal como está escrito deja la web logueada y con TODO el panel en 401: cotizaciones, envíos, direcciones, tickets, alta de conductores. Y la tarea 7.4 es literalmente irrealizable: dice que `company` «sigue al backend de TR3SLOG», pero después de 7.1 el cliente ya no tiene ninguna credencial que ese backend sepa validar. El lote que el plan clasifica como «bajo riesgo» y sin bloqueos es el que apaga la aplicación web entera.

**Corrección**
El Lote 7 tiene que incluir el repunte de `src/api.js` a `/api/treslog/...` a través del gateway, y por lo tanto no puede ir antes del Lote 8 ni antes de que exista un gateway en producción. Mientras `/api/v1` no esté en el VPS, el Lote 7 hereda el bloqueo de R9 igual que el 6 y el 9 — hoy no lo tiene.


---


## 6. El Lote 8 mata el bloque auth:sanctum que el plan promete mantener vivo hasta el Lote 9

**ROMPE PRODUCCION** · lente `ejecutable` · 4-tasks.md, Lote 8 tarea 8.5 (+ 3-design.md §D.4)

**El plan afirma**
> «hasRole()/hasAnyRole()/isAdmin() ... si el modelo no fue hidratado (job en cola, tinker, query directa), los tres métodos lanzan, no devuelven false» y §D.4: «Verificado que hoy todos los call sites pasan el usuario autenticado (...) O sea que la regla no rompe nada hoy y protege de mañana»

**La realidad**
Hay tres clases de call sites que NO reciben un usuario hidratado por ResolveDomainUser: (a) Gate::before llama $user?->isAdmin() en TODA autorización, y el provider está registrado, así que toda petición del bloque auth:sanctum que dispare authorize() pasa por ahí; (b) EnsureUserIsDriver corre en cada petición vieja del conductor con $request->user() hidratado por Sanctum, no por el gateway; (c) DriverController::store y DriverAuthController::login llaman hasRole('driver') sobre modelos traídos por User::findOrFail() / User::where(...)->first(). El grep completo devuelve ~20 call sites de hasRole/hasAnyRole/isAdmin, no los 8 que enumera §D.4.

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/backend_trelog/app/Providers/AuthServiceProvider.php:15-18 y bootstrap/providers.php:8 (Gate::before registrado); app/Http/Middleware/EnsureUserIsDriver.php:13; app/Http/Controllers/DriverController.php:67 ($user = User::with('driverProfile')->findOrFail(...) y luego $user->hasRole('driver')); app/Http/Controllers/Api/Driver/DriverAuthController.php:89`

**Qué pasa si se ejecuta así**
El día que se mergea el PR 8, cada petición del camino viejo que llame authorize() (quotes, shipments, support, users) o pase por el middleware 'driver' lanza excepción y devuelve 500. Es exactamente el camino que el plan declara intocable hasta el PR 9 («nadie pierde acceso»): los conductores en la calle con la build vieja pierden el backend entero, y el PR 9 no se puede disparar porque su precondición es que el camino viejo siga funcionando.

**Corrección**
O bien 8.5 devuelve false + Log::warning en vez de lanzar mientras el bloque auth:sanctum siga montado, o bien la regla de lanzar se difiere al Lote 9 (mismo PR que retira el bloque viejo). Y §D.4 tiene que enumerar los ~20 call sites reales, incluido Gate::before, no 8.


---


## 7. La tarea 9.5 borra rutas que la 8.1 nunca migró y que la 7.4 exige que sobrevivan

**ROMPE PRODUCCION** · lente `ejecutable` · 4-tasks.md, tareas 8.1, 9.5 y 7.4

**El plan afirma**
> 8.1: «Extraer el resto de rutas de dominio (quotes, shipments, drivers, incidents, addresses, support, payroll, dispatch) a routes/domain.php»; 9.5: «Retirar el bloque Route::middleware('auth:sanctum')->group() completo»; 7.4: «company y el resto siguen al backend de TR3SLOG»

**La realidad**
El bloque auth:sanctum contiene además /alerts, todo users/* (index, clients, show, update, destroy) y roles/permissions/zones, que 8.1 no lista. En cambio 8.1 nombra dos grupos que no están ahí: 'payroll' es ruta de conductor (ya migrada en el Lote 5) y 'dispatch' no existe como ruta en todo el archivo — es sólo el permiso dispatch.manage.

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/backend_trelog/routes/api.php:56 (/alerts), :81-87 (users/*), :90-117 (roles, permissions, zones), :193 (payroll de conductor); /Volumes/External/sources/myglobalhub/treslog/tr3slog-website/src/api.js:42 (updateUser → PUT /users/{id}) y :93 (getClients → GET /users/clients)`

**Qué pasa si se ejecuta así**
Al ejecutar 9.5 desaparecen endpoints que la web sigue llamando y que la propia tarea 7.4 dio por vivos: el guardado de perfil (company, business_name, zone, payment_method) y el listado de clientes pasan a 404 sin reemplazo en ningún lote. Y es el único paso no reversible del plan.

**Corrección**
8.1 debe listar los grupos reales del bloque (alerts, users, addresses, support, quotes, shipments, drivers, incidents) y decidir explícitamente el destino de roles/permissions/zones/invitations bajo el gateway; borrar 'payroll' y 'dispatch' de la lista. 9.5 sólo puede correr sobre un bloque cuyas rutas tengan las dos definiciones.


---


## 8. El Lote 7 deja la web logueada y sin ningún endpoint de dominio

**ROMPE PRODUCCION** · lente `ejecutable` · 4-tasks.md, Lote 7 (7.1-7.5) y 1-proposal.md §8 («PR 7 · se puede desplegar solo: Sí»)

**El plan afirma**
> «Bajo riesgo: la web se redespliega en minutos» y el Lote 8 «El bloque auth:sanctum sigue montado en paralelo hasta el Lote 9: nadie pierde acceso»

**La realidad**
src/api.js define UNA sola API_URL y manda Authorization: Bearer <token> en 21 llamadas de dominio contra rutas protegidas por auth:sanctum. Después de 7.1 el token deja de ser un personal access token de Sanctum y pasa a ser un access token del SSO: el guard de Sanctum no lo encuentra en personal_access_tokens y responde 401. Ninguna tarea del Lote 7 ni del Lote 8 cambia API_URL a la URL del gateway (/api/treslog).

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/tr3slog-website/src/api.js:1-15 (API_URL única + headers(token)) y :75-165 (quotes, drivers, incidents, shipments, addresses, support con ese mismo token); /Volumes/External/sources/myglobalhub/treslog/backend_trelog/routes/api.php:53`

**Qué pasa si se ejecuta así**
Desplegar el PR 7 solo, como dice la propuesta, deja la web con login exitoso y 401 en cotizaciones, envíos, conductores, incidentes, direcciones y soporte. Y el PR 8 tampoco lo arregla: monta las rutas nuevas en el backend pero nadie repunta el cliente.

**Corrección**
El Lote 7 tiene que incluir el cambio de base URL a la del gateway y la verificación de que cada llamada de dominio va a /api/treslog/..., o bien 7.1 se difiere hasta que el Lote 8 esté desplegado y el corte de la web sea uno solo.


---


## 9. El Lote 5 pierde el middleware 'driver' y ningún artefacto lo menciona

**ROMPE PRODUCCION** · lente `ejecutable` · 4-tasks.md, tareas 5.1, 5.2 y 5.3 (+ 3-design.md §E.2)

**El plan afirma**
> «bloque nuevo Route::prefix('treslog')->middleware(['gateway.auth','gateway.user'])» y 5.3 «Verificar que StopController.php:57 y RouteController.php:24 siguen comparando contra $request->user()->id»

**La realidad**
El bloque viejo es Route::middleware(['auth:sanctum', 'driver']): el alias 'driver' es EnsureUserIsDriver, que exige hasRole('driver'). Es la única guarda que impide que un cliente entre a la app de conductores. Un grep de EnsureUserIsDriver sobre los cuatro .md no devuelve nada.

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/backend_trelog/routes/api.php:178; app/Http/Middleware/EnsureUserIsDriver.php:13; bootstrap/app.php:19-21 (alias 'driver')`

**Qué pasa si se ejecuta así**
El montaje nuevo pierde la guarda: cualquier identidad del SSO con fila espejo alcanza /api/treslog/driver/dashboard, /routes, /payroll y POST /incidents. Y la alternativa tampoco está resuelta: si alguien conserva el alias, después de 8.5 hasRole('driver') consulta roles del SSO y 'driver' es un rol sin prefijo que nunca viaja en X-User-Roles, así que serían 403 para TODOS los conductores. El plan no elige ninguna de las dos, y el test 5.4 (dueño 200 / otro conductor 403) no distingue ninguno de los dos casos.

**Corrección**
Añadir una tarea explícita en el Lote 5: reemplazar el alias 'driver' por sso.role:treslog:conductor en el bloque nuevo, y un test de que un treslog:cliente recibe 403 en /api/treslog/driver/*.


---


## 10. N1 es falso para el admin: Gate::before le concede todo, así que la tarea 1.4 congela un 403 que no existe

**BLOQUEA EL PLAN** · lente `ejecutable` · 3-design.md §Hallazgos nuevos N1 y §D.5; 4-tasks.md tarea 1.4

**El plan afirma**
> «cinco endpoints de dominio responden 403 a todo el mundo, incluido el admin» y 1.4: «congelar el 403 actual de un admin en PATCH /quotes/{quote}/status, PATCH /app/quotes/{quote}/status, GET /support, GET /support/{ticket}, PUT /support/{ticket}»

**La realidad**
AuthServiceProvider registra Gate::before(fn($user) => $user?->isAdmin() ? true : null) y el provider está en bootstrap/providers.php. Un before callback que devuelve true cortocircuita CUALQUIER ability antes de llegar a la Policy, así que un admin recibe 200 en los cinco endpoints; hasPermission('quotes.edit') ni se evalúa. El 403 real sólo lo ven operations y customer.

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/backend_trelog/app/Providers/AuthServiceProvider.php:15-18; bootstrap/providers.php:8; app/Http/Controllers/QuoteController.php:58 ($this->authorize('update', $quote)); app/Http/Controllers/SupportController.php:46,55,62; app/Policies/QuotePolicy.php:25-28`

**Qué pasa si se ejecuta así**
Las cinco pruebas de la tarea 1.4 fallan el primer día, y el Lote 1 es el gate bloqueante de A5/A6/A7 (R1 de la propuesta). Peor: D7 se levanta sobre un diagnóstico falso — el acceso del admin a soporte y a cotizaciones existe hoy, así que la migración no está «congelando lo que hay», y si en el Lote 8 se borra isAdmin()/Gate::before sin darse cuenta, el admin PIERDE acceso a soporte en silencio, que es el modo de falla que §D.3 dice querer evitar.

**Corrección**
N1 debe decir «403 para todos menos el admin, por Gate::before». 1.4 debe congelar 200 para admin y 403 para operations/customer, y agregar una tarea en el Lote 8 sobre qué pasa con Gate::before cuando isAdmin() deja de leer role_user.


---


## 11. El Lote 1, declarado «sin bloqueos», no puede correr: los modelos no tienen HasFactory y faltan dos factories que sus propias pruebas necesitan

**BLOQUEA EL PLAN** · lente `ejecutable` · 4-tasks.md, tarea 1.1 (+ 3-design.md §F.1 punto 1)

**El plan afirma**
> «Crear las 8 factories de dominio que faltan en database/factories/: AddressFactory, ShipmentFactory, SupportTicketFactory, RouteFactory, IncidentFactory, PayrollPeriodFactory, DriverAlertFactory, DriverProfileFactory (hoy sólo existe UserFactory)»

**La realidad**
Tres cosas: (1) ninguno de los nueve modelos de dominio usa el trait HasFactory — sólo User y UserInvitation — así que Shipment::factory() lanza BadMethodCallException y ninguna tarea agrega el trait; (2) no existe App\Models\Route: el modelo se llama DeliveryRoute, así que RouteFactory no resuelve modelo por convención; (3) faltan RouteStopFactory (la tarea 1.2 prueba POST /stops/{stop}/confirm y /fail) y QuoteFactory (las tareas 1.4 y 1.5 prueban PATCH /quotes/{quote}/status y GET /quotes).

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/backend_trelog/app/Models/User.php:21 y app/Models/UserInvitation.php:12 son los únicos HasFactory del directorio; app/Models/DeliveryRoute.php:9; app/Models/RouteStop.php:8; app/Models/Quote.php:22; routes/api.php:187-188 y :124`

**Qué pasa si se ejecuta así**
El PR 1 es la red de seguridad de todo el plan y no arranca como está escrito: la primera línea de test explota. Son 10 factories, no 8, más un cambio en 9 modelos que nadie presupuestó.

**Corrección**
1.1 pasa a: agregar 'use HasFactory' a los 9 modelos de dominio + crear 10 factories, con DeliveryRouteFactory (no RouteFactory), RouteStopFactory y QuoteFactory incluidas.


---


## 12. El alta pide `kind='frontend'` y todo el desarrollo corre en localhost: el canal para eso es `frontend-dev`

**CORREGIBLE** · lente `contrato` · 4-tasks.md Lote 2, tarea 2.2 (:60) y Lote 7, tarea 7.5 (:135) · 1-proposal.md §2 A1 (:48-49)

**El plan afirma**
> 4-tasks.md:60: «cliente OAuth publico PKCE, vinculo `application_clients` con `kind='frontend'`», y :135: «Checklist manual: un login end-to-end completa el flujo PKCE contra el gateway local».

**La realidad**
El enum tiene TRES canales, no dos. `ApplicationClientKind::FrontendDev` existe exactamente para este caso: mismo perfil, mismo slug, mismos roles `<slug>:...`, pero con `redirect_uris` apuntando a localhost, «para no tener que registrar `http://localhost:...` en el cliente de PRODUCCION... una superficie que queda abierta para siempre, en todas las aplicaciones». `sso:app-client` avisa por pantalla si se le pasa un redirect local a `--kind=frontend`. El plan no lo vio porque `alta_de_aplicacion.md:104` sigue diciendo «los dos canales posibles».

**Evidencia**
`/Volumes/External/sources/myglobalhub/SSO/app/Enums/ApplicationClientKind.php:29-46,49-52; /Volumes/External/sources/myglobalhub/SSO/app/Console/Commands/AppClientCommand.php:26-31 (los tres canales), :132-139 (el aviso por redirect local en el canal de produccion); /Volumes/External/sources/myglobalhub/SSO/Docs/alta_de_aplicacion.md:104`

**Qué pasa si se ejecuta así**
Todo el desarrollo del plan (Lotes 2 a 8) corre contra el gateway local en el puerto 8003 y la web Next en localhost, asi que el cliente `frontend` de PRODUCCION de `treslog` nace con `http://localhost:...` entre sus `redirect_uris` y se queda asi. Es una superficie permanente de redireccion del authorization code en el cliente productivo, replicada por cada aplicacion que siga este plan como plantilla.

**Corrección**
El Lote 2 crea DOS clientes bajo el mismo perfil: `sso:app-client --app=treslog --kind=frontend --redirect=<URI real de produccion>` y `sso:app-client --app=treslog --kind=frontend-dev --redirect=http://localhost:...`. Ninguna URI local en el cliente productivo. La tarea 7.5 y el `SETUP_LOCAL.md` de la 2.6 usan el `client_id` del canal `frontend-dev`.


---


## 13. La cadena de 9 PRs no existe: los PRs 6 y 7 viven en otros dos repositorios git

**CORREGIBLE** · lente `ejecutable` · 4-tasks.md, tabla «Unidades de trabajo sugeridas» y bloque de pronóstico

**El plan afirma**
> «Chain strategy: stacked-to-main», «6 | Build de la app de conductores | PR 6 | Base: PR 5» y «7 | Web: PKCE + AppShell + perfil partido | PR 7 | Base: PR 4»

**La realidad**
backend_trelog, tr3slog-website y tr3slog_driver_app son tres repositorios independientes con tres remotos distintos; los dos frontends están en main, no en feat/sso-integration. Una rama del backend no puede ser base de un PR de otro repositorio.

**Evidencia**
`backend_trelog/.git → github.com/juanz11/backend_trelog.git (rama feat/sso-integration); tr3slog-website/.git → github.com/juanz11/tr3slog-website.git (main); tr3slog_driver_app/.git → github.com/juanz11/tr3slog_driver_app.git (main)`

**Qué pasa si se ejecuta así**
El corte de entrega y el pronóstico de 3500-4500 líneas «repartidas en 9 PRs» no se pueden ejecutar como una cadena: son tres cadenas con tres ciclos de revisión y tres despliegues. Además el riesgo de presupuesto de 400 líneas se declara sólo para los lotes 1, 3, 8 y 9, y deja fuera justo los dos que implementan un cliente OAuth PKCE completo (Flutter: 3639 líneas de lib/ con auth_service.dart y login_screen.dart a reescribir; web: 21 llamadas + flujo de autorización).

**Corrección**
Declarar tres cadenas (backend 1-5, 8, 9 · web 7 · Flutter 6), con la dependencia entre repos explícita, y sumar los lotes 6 y 7 al riesgo de presupuesto.


---


## 14. La caracterización del conductor pide un 403 que 5 de las 8 rutas no producen

**CORREGIBLE** · lente `ejecutable` · 4-tasks.md, tarea 1.2 (+ 3-design.md §F.1 punto 2)

**El plan afirma**
> «GET /driver/dashboard, /routes, /routes/{route}, POST /stops/{stop}/confirm, /stops/{stop}/fail, GET /incidents, POST /incidents, GET /payroll — dueño 200, otro conductor 403 (StopController.php:57, RouteController.php:24)»

**La realidad**
Sólo tres endpoints tienen un camino de 403: RouteController::show y las dos acciones de StopController, ambas con abort_if. Dashboard, RouteController::index, IncidentController::index/store y PayrollController::index filtran por driver_id = $request->user()->id: otro conductor recibe 200 con su propio conjunto (vacío), nunca 403.

**Evidencia**
`/Volumes/External/sources/myglobalhub/treslog/backend_trelog/app/Http/Controllers/Api/Driver/RouteController.php:14 (index filtra) y :24 (abort_if); StopController.php:57; DashboardController.php:16; IncidentController.php:15,33; PayrollController.php:14`

**Qué pasa si se ejecuta así**
Cinco de los tests del lote bloqueante fallan tal como están especificados, y —peor para lo que el plan quiere proteger— la invariante real de esos cinco endpoints es «el filtro por driver_id devuelve exactamente lo mío», que es justo lo que la costura de identidad de §A puede romper y que ningún test enunciado cubre.

**Corrección**
1.2 debe pedir, para los cinco endpoints con filtro: dueño ve sus N filas y el otro conductor ve 0 (200), y reservar el 403 para /routes/{route} y las dos acciones de /stops.


---

---

## Segunda ronda: auditoría del CÓDIGO de los lotes 2–5 (2026-09-11)

Misma mecánica que la primera —cuatro lentes (contrato, camino viejo, seguridad, gateway) y dos
escépticos por hallazgo—, pero sobre el código escrito, no sobre el plan. 32 agentes, 8 hallazgos
crudos, **1 sobrevivió**; los otros 7 fueron refutados contra el código o ya estaban declarados
como decisión en `4-tasks.md`.

| # | Hallazgo | Estado |
|---|----------|--------|
| 1 | `RequireSsoRole`: el 403 —el error más frecuente del camino nuevo— salía sin `request_id` y con una clave `required` fuera del sobre cerrado del contrato, que le contaba al cliente qué roles abren la puerta | **Corregido** (`b385058` el `request_id`; esta ronda: `required` al log, y el test que congela las dos cosas en las cuatro rutas del conductor) |

De los refutados, tres se corrigieron igual porque eran ciertos aunque no llegaran a «rompe
producción»: el map de CORS del gateway decía 3000/3001 mientras el SSO registraba 3200/3201
(`b385058`); `SETUP_LOCAL.md` §3b mandaba a levantar la app Flutter contra un login que todavía
no existe (`b385058`); y del lado del SSO, un 404 a la subpetición se disfrazaba de caída
transitoria con `Retry-After` (SSO `1a5a99c`, referencia y contrato — las plantillas de MSH y
TR3SLOG ya lo distinguían).

---

## Tercera ronda: auditoría del CÓDIGO del Lote 7 (2026-09-11, de madrugada)

Misma mecánica de cuatro lentes, pero **sin agentes**: la hizo el orquestador a mano, de a un
lente, porque cada subagente en paralelo le pedía permisos al usuario mientras dormía.

| Lente | Qué se verificó | Resultado |
|---|---|---|
| OAuth/PKCE atacado | `state` criptográfico, guardado en `sessionStorage`, comparado ANTES de canjear y borrado al leer; verifier de 32 bytes → 43 chars base64url; challenge S256 sin padding; `redirect_uri` desde config en authorize y en el canje; destino post-login fijo (`/dashboard`); solo `access_token` en `localStorage`; error del canje → pantalla con reintento que ARRANCA de nuevo, sin recargar | sin hallazgos |
| `/me` y el contrato | roles desde la identidad del gateway (`sso_user` en el request), nunca `role_user`; filtro por `config('sso.slug')`; `is_admin` desde `config('sso.roles')`; ruta con `gateway.auth`+`gateway.user`, sin `sso.role`; suite 86 tests / 318 aserciones en verde | sin hallazgos |
| Regresiones de la web | sin referencias al modal ni a `api.login/register/forgot/reset`; `AppShell` lee `is_admin`; el 403 «no habilitada» conserva el token (corta el bucle); lint limpio; 15/15 tests | **1 hallazgo**: las pantallas nuevas (callback y «Puerta») tenían textos fijos en castellano; el modal que reemplazan era trilingüe. **Corregido**: claves `sso*` en `i18n-auth.js` (es/en/zh-CN), `_app.jsx` pasa `lang` al callback y `a` a la Puerta |
| Setup copiando y pegando | defaults de `config/sso.js` = lo registrado en el SSO local; `/oauth/authorize` con challenge S256 real → 302 al login; `SETUP_LOCAL.md` dice la verdad sobre `localhost` vs `127.0.0.1` | **1 hallazgo**: el comentario de `config/sso.js` decía que `127.0.0.1:3200` estaba registrada como `redirect_uri` y no lo está. **Corregido** (comentario) |

No se corrió `npm run build` en esta ronda: comparte `.next/` con el `next dev` que sirve la
demo en :3200 y lo tiraría abajo. El agente del Lote 7 lo corrió antes (callback exportado en
`out/login/sso/callback.html`); la próxima ronda con la web parada lo repite.

Pendientes que NO son de este lote y quedan con dueño: `.env.local` y `.env.production` de la
web están versionados con claves de Stripe (rotar, decisión del usuario); solo `localhost:3200`
registrada como redirect; dominio de la web en 401 hasta el Lote 8 (rama
`sso/lote8-dominio-por-gateway` creada en un worktree, sin cambios).
