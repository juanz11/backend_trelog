# Diseño: TR3SLOG consume el SSO de MyGlobalHub

**Cambio**: `integracion-sso` · **Fuente**: `1-proposal.md`, `2-specs.md` · **Rama**: `feat/sso-integration`
**Contrato**: `SSO/Docs/contrato_api_v1_msh.md`, `SSO/Docs/alta_de_aplicacion.md`
**Referencia**: `MSH/backend/app/Http/Middleware/`, `MSH/backend/config/sso.php`, `MSH/gateway/`

Este documento toma decisiones. Cada una dice **qué se decidió**, **por qué**, y **qué se descartó**.
Un diseño que no dice lo que descartó obliga a volver a discutirlo en el primer code review.

> **Estado de las decisiones abiertas de la propuesta.** Este diseño **resuelve D2** (§A) con evidencia
> del código del SSO, y **acota D4** (§D.4). **D1, D3, D5 y D6 siguen necesitando una persona** y están
> listadas en §H. Ninguna de las cuatro bloquea escribir tareas.

---

## Hallazgos nuevos que mandan sobre este diseño

Cuatro cosas que ni la exploración ni la propuesta detectaron, verificadas leyendo el repo. Las cuatro
cambian decisiones, no son color.

**N1 — El RBAC actual está roto hoy, y no de forma menor.** `RolePermissionSeeder.php:28-96` define la
matriz rol→permiso. `PermissionSeeder.php` sólo declara `quotes.view` y `quotes.create` del módulo
quotes (`:34-35`), y **ni un solo permiso de `support`**. Cruzando eso con lo que las Policies exigen:

| Endpoint | Policy | Permiso exigido | ¿Quién lo tiene? |
|---|---|---|---|
| `PATCH /quotes/{quote}/status` (`QuoteController.php:58`) | `QuotePolicy::update` | `quotes.edit` | **nadie — el permiso no existe** |
| `PATCH /app/quotes/{quote}/status` (`Api/QuoteController.php:89`) | ídem | `quotes.edit` | **nadie** |
| `GET /support` (`SupportController.php:46`) | `SupportTicketPolicy::viewAny` | `support.view` | **nadie — el permiso no existe** |
| `GET /support/{ticket}` (`:55`), `PUT /support/{ticket}` (`:62`) | `view` / `update` | `support.view` / `support.edit` | **nadie** |
| `GET /quotes` (`QuoteController.php:34`) | `QuotePolicy::viewAny` | `quotes.view` | sólo `operations` y `admin` — **un `customer` recibe 403** |

O sea: **cinco endpoints de dominio responden 403 a todo el mundo, incluido el admin.** No es una
hipótesis: `Role::where('name','quotes.edit')`… el permiso simplemente no está en la tabla, y
`hasPermission()` (`User.php:71-78`) hace `whereHas('permissions', ...)->exists()`, que devuelve
`false` sin error.

**Consecuencia de diseño**: los tests de caracterización (A2, PR 1) **van a congelar comportamiento
roto**. Eso no es un motivo para no escribirlos — es un motivo para escribirlos sabiéndolo. Ver §F.2.

**N2 — Sólo 14 de los 29 permisos se consultan alguna vez.** Un grep de `hasPermission\('([a-z.]+)'\)`
sobre `app/` devuelve 20 llamadas con 14 nombres distintos: `users.{view,create,edit,delete}`,
`quotes.{view,create,edit,delete}`, `shipments.{edit,delete}`, `support.{view,edit}`, `drivers.manage`,
`dispatch.manage`. Los otros 15 son inventario muerto. Esto abarata muchísimo el §D: no hay que
"migrar 29 permisos", hay que reemplazar **20 llamadas**.

**N3 — Hay una CUARTA vía de alta, no tres.** `routes/api.php:47` monta `POST /users` **fuera** de
`auth:sanctum`, y `UserController::store` hace `$user->roles()->attach($request->roles)` (`:103`) con
ids de rol arbitrarios del cuerpo. Es peor que el agujero de `AuthController::register`. **No es
explotable**, y esto sí hay que decirlo con precisión: `:43` llama `$this->authorize('create', User::class)`
y `UserPolicy::create(User $user)` (`UserPolicy.php:19`) tipa el primer parámetro como **no anulable**,
así que el Gate de Laravel deniega a los invitados antes de ejecutar el método. El resultado real es
**una ruta pública que siempre responde 403**. Sigue siendo un cuarto camino de registro que hay que
retirar, y su seguridad depende hoy de un detalle de tipado que nadie escribió a propósito.

**N4 — `driver_id` significa dos cosas distintas en el esquema.** En `routes`, `incidents`,
`payroll_periods` y `driver_alerts`, `driver_id` es un `foreignId` a `users.id` (bigint). En
`driver_profiles`, `driver_id` es un `string` nullable único agregado por
`2026_09_03_160000_add_driver_id_to_driver_profiles_table.php:13` — un código de negocio, **no** una FK.
Cualquiera que escriba la costura de identidad leyendo nombres de columna se va a equivocar acá.

---

## A. La costura de identidad — **tabla espejo local, anclada en `X-User-Id`**

### Decisión

`users` **sobrevive** como tabla espejo de la identidad del SSO. Se le agregan dos columnas:

```
users.sso_user_id   string(64)  nullable  unique   <- EL ANCLA. Viene de X-User-Id.
users.sso_clerk_id  string(64)  nullable  unique   <- NO autoritativo. Viene de X-User-Clerk-Id.
```

Las nueve FKs de dominio **no se tocan**: siguen apuntando a `users.id` (bigint). El `id` local sigue
siendo la clave del dominio de TR3SLOG; `sso_user_id` es la clave de correlación con el SSO.

Un envío se cruza con la persona que lo creó **exactamente como hoy** (`shipments.user_id → users.id`).
Lo único que cambia es **cómo se llega a esa fila `users`**: antes por sesión de Sanctum, ahora por
`sso_user_id = X-User-Id`.

### Por qué

**1. `X-User-Id` es el único identificador que el SSO devuelve por más de un canal.** `GatewayIdentity.php:65`
emite `'X-User-Id' => (string) $user->id`. Ese mismo `id` es el que devuelven `GET /api/v1/user` y
`GET /api/v1/profile` (`contrato:221-224`). `clerk_id`, en cambio, **está explícitamente prohibido en
las respuestas de la API**: `UserResource.php:51` lo excluye y `UserResourceKeysTest.php:139` tiene un
test que falla si aparece. Si TR3SLOG anclara en `clerk_id`, **no habría ningún endpoint del SSO capaz
de resolver una fila de TR3SLOG**. Ni para soporte, ni para migración, ni para conciliar. Eso es
decisivo por sí solo.

**2. La contradicción del contrato (D2) no es una contradicción: son dos alcances distintos.**
`contrato:153` dice que `X-User-Clerk-Id` "es la clave estable **entre ambientes**" — habla de local vs
VPS, dos bases de datos del SSO con autoincrementales distintos. `contrato:224` dice que "su referencia
estable es `id`" — habla **dentro de un ambiente**, que es donde vive un despliegue. Las dos son
ciertas. TR3SLOG no comparte filas entre ambientes: la estabilidad que necesita es la intra-ambiente.

**3. `clerk_id` no siempre es un id de Clerk.** `SSO/app/Http/Controllers/UserController.php:37` crea
usuarios desde el panel con `'clerk_id' => 'local_' . uniqid()`. Un `uniqid()` es un timestamp del
proceso que lo generó: **se genera distinto en cada ambiente**, que es justo la propiedad por la que
el contrato lo recomienda. Para ese subconjunto de cuentas, la premisa de `contrato:153` es falsa.

**4. `sso_clerk_id` se guarda igual, y por eso la decisión es reversible.** Se persiste en cada
petición (§A.2) sin ser autoritativo. Si el equipo del SSO respondiera que el ancla debe ser `clerk_id`,
la migración es un `UPDATE ... SET sso_user_id = sso_clerk_id` sobre datos que ya están en la tabla, no
un proyecto de reconciliación. **El costo de equivocarse en esta decisión es una migración de una
columna, no de nueve FKs.**

### Qué se descartó

| Alternativa | Por qué no |
|---|---|
| **Sin tabla `users` local; las 9 FKs pasan a `string` con el id del SSO** (lo que hizo MSH: `contrato:315-317`, `user_hubs` anclado en `clerk_id` "sin FK a una tabla `users` local, que no va a existir") | MSH tiene `hubs` y `preferences`: dos tablas y ninguna integridad relacional que perder. TR3SLOG tiene **nueve** FKs con `cascadeOnDelete`/`nullOnDelete`, `driver_profiles` con `unique`, y `payroll_periods` — plata. Sin tabla destino no hay FK posible, o sea que se cambia integridad garantizada por el motor por integridad garantizada por convención. Además rompe **30+ llamadas** a `$request->user()->id` y las 4 Policies de golpe, en el mismo PR. El punto de no retorno del paso 9 pasaría a ser el paso 5 |
| **Anclar en `X-User-Clerk-Id`** | Puntos 1 y 3 de arriba. Ningún endpoint del SSO devuelve `clerk_id`, y para las cuentas creadas desde el panel el valor es un `local_<uniqid>` que no es estable entre ambientes |
| **Anclar en `X-User-Email`** | La spec lo prohíbe (`Anclaje único y estable por petición`). Es mutable: la persona lo cambia desde su perfil de Clerk. Un cambio de email reasignaría todos sus envíos a nadie |
| **Guardar los dos y resolver por el que esté** | Dos anclas es ninguna. La primera petición donde discrepan crea la segunda identidad que la spec prohíbe (`No duplicación de identidad`) |

### A.2 Cómo se resuelve, en concreto

Middleware nuevo `ResolveDomainUser` (alias `gateway.user`), que corre **después** de `gateway.auth`:

1. Lee `sso_user` del request (lo dejó `AuthenticateFromGateway`).
2. `User::where('sso_user_id', $identity['id'])->first()`.
3. **Si no hay fila → `403 forbidden`**, con mensaje explícito de que la cuenta existe en el SSO pero
   no está dada de alta en TR3SLOG. **No se crea la fila, y no se busca por email**: la spec
   (`Migración de cuentas existentes fuera de alcance`) lo prohíbe, y con razón — el email del SSO lo
   controla el usuario, así que un `firstOrCreate` por email es un secuestro de cuenta con otro nombre.
4. Si la hay, refresca `sso_clerk_id`, `email` y `name` con lo que trajo la cabecera (el SSO es la
   fuente de verdad de esos tres) y **la deja como usuario autenticado del request** vía
   `auth()->setUser($user)`, para que `$request->user()` siga funcionando.

> **Por qué `403 forbidden` y no un slug nuevo tipo `account_not_provisioned`**: el catálogo de errores
> es **cerrado, trece slugs**, y está congelado con un `assertSame` sobre `ApiErrorCatalog::slugs()`
> (`contrato:97-99`). Inventar un slug es un cambio de contrato unilateral, y la app cliente que siga
> el documento no lo reconoce. Si hace falta el slug, se pide; mientras tanto, `forbidden` con mensaje.

> **Por qué `auth()->setUser()` y no reescribir 30+ call sites**: es el único punto donde una línea
> preserva `$request->user()->id`, `$request->user()->addresses()`, las 4 Policies y los 9 controladores.
> `setUser()` no toca la sesión ni emite eventos de login. La alternativa —un `SsoUser` propio inyectado
> a mano en cada controlador— es el mismo diff gigante que descartamos arriba, pero en otro lugar.

---

## B. `users` y sus foreign keys — una por una

Nueve FKs apuntan a `users`. Enumeradas leyendo `database/migrations/`:

| # | Tabla.columna | Definición (archivo) | Decisión |
|---|---|---|---|
| 1 | `addresses.user_id` | `foreignId()->constrained()->cascadeOnDelete()` (`2026_08_19_185301:13`) | **Se queda igual.** Dominio puro |
| 2 | `shipments.user_id` | `foreignId()->nullable()->constrained()->nullOnDelete()` (`2026_08_12_192713:19`) | **Se queda igual** |
| 3 | `support_tickets.user_id` | `foreignId()->constrained()->cascadeOnDelete()` (`2026_08_19_201033:13`) | **Se queda igual** |
| 4 | `routes.driver_id` | `foreignId()->constrained('users')->cascadeOnDelete()` (`2026_08_12_200100:16`) | **Se queda igual** |
| 5 | `incidents.driver_id` | `constrained('users')->cascadeOnDelete()` (`2026_08_12_200400:16`) | **Se queda igual** |
| 6 | `payroll_periods.driver_id` | `constrained('users')->cascadeOnDelete()` (`2026_08_12_200500:16`) | **Se queda igual** |
| 7 | `driver_alerts.driver_id` | `constrained('users')->cascadeOnDelete()` (`2026_08_12_200600:16`) | **Se queda igual** |
| 8 | `driver_profiles.user_id` | `foreignId()->unique()->constrained()->cascadeOnDelete()` (`2026_08_12_200000:16`) | **Se queda igual** |
| 9 | `role_user.user_id` | `foreignId()->constrained()->onDelete('cascade')` (`2026_07_26_155240:19`) | **SE ELIMINA en el PR 9**, junto con la tabla entera. Es la única FK de RBAC, no de dominio |

Más dos referencias que **no** son FKs y conviene no confundir:

- `sessions.user_id` (`0001_01_01_000000_create_users_table.php:32`): `foreignId()->nullable()->index()`
  **sin `constrained()`** — es un índice, no una restricción. Se queda; `SESSION_DRIVER` no depende de esto.
- `driver_profiles.driver_id`: `string` nullable único (`2026_09_03_160000:13`). **No apunta a `users`**
  (ver N4). Es un código de negocio del conductor. No se toca.

**Columnas de `users` que se retiran en el PR 9** (las gobierna el SSO): `password`, `remember_token`,
`reset_token`, `reset_token_expires` (`2026_07_24_081643`). Y las tablas `password_reset_tokens` y
`personal_access_tokens`.

**Columnas de `users` que se quedan porque son de TR3SLOG, no del SSO**: `company`, `business_name`,
`street_address`, `city`, `zone`, `payment_method`, `phone`, `status`. Ninguna de las trece claves del
perfil del SSO las cubre (`1-proposal.md §2`). `name` y `email` se quedan **como caché de lectura**,
refrescada en cada petición desde las cabeceras: el `unique` de `email` se conserva porque sigue siendo
válido, pero deja de ser una credencial.

**Descartado**: reescribir las 9 FKs a `string` (§A) · borrar `users` y dejar las FKs huérfanas (SQLite,
que es lo que usa la suite — `phpunit.xml:26-27` —, valida FKs y la migración fallaría) · un `deleted_at`
en lugar de la baja real (nadie pidió borrado lógico y agregarlo acá lo esconde en un PR de auth).

---

## C. El middleware — adaptar el de MSH, con tres divergencias justificadas

### Decisión

Se copian los tres middlewares de MSH y `config/sso.php` **con los mismos nombres de clase, los mismos
alias y las mismas claves de config**. Cadena resultante:

```
RequestContext        (prepend en el grupo api, global)   <- idéntico a MSH salvo el prefijo del id
  gateway.auth        AuthenticateFromGateway             <- 3 divergencias, abajo
    gateway.user      ResolveDomainUser                   <- NUEVO. No existe en MSH
      sso.role:...    RequireSsoRole                      <- idéntico a MSH
```

`config/sso.php` se copia **entero**, incluidos los comentarios que explican por qué los nombres de
cabecera no son configurables. Ese bloque (`config/sso.php:20-34`) documenta un bypass total y vale
tanto acá como allá.

### C.1 Lo que NO diverge, y es lo importante

Los nombres de cabecera, el formato CSV de `X-User-Roles` con descarte de vacíos
(`AuthenticateFromGateway.php:65-68`), la decisión de guardar la identidad en
`$request->attributes` y **no** en un singleton del contenedor (`:78-82`, el comentario sobre Octane es
correcto y se copia tal cual), y la política de logging que registra **presencia y no contenido** de las
cabeceras (`:87-102`). Eso es el contrato compartido entre aplicaciones. Ahí no se toca nada.

### C.2 Las tres divergencias

**Divergencia 1 — el slug de error pasa de `unauthorized` a `unauthenticated`, y el cuerpo lleva
`request_id`.**
MSH devuelve `'error' => 'unauthorized'` (`AuthenticateFromGateway.php:40,54`). Ese slug **no está en el
catálogo cerrado de trece** (`contrato:101-115`). El propio gateway, para la misma condición, emite
`unauthenticated` (`gateway/templates/default.conf.template:206`), y hay un comentario ahí mismo
explicando por qué: *"con dos vocabularios, un cliente que siga el documento no reconoce su propio 401"*.
El backend de MSH contradice a su propio gateway. Además la spec de TR3SLOG lo exige literal
(`Rechazo sin sello de gateway`: `{"error":"unauthenticated",...,"request_id":...}`).
**Esto es un bug de MSH, no una preferencia de TR3SLOG. Se reporta y se porta hacia allá.**

**Divergencia 2 — el sello del gateway se comprueba PRIMERO, y no se puede desactivar.**
MSH comprueba identidad primero y firma después, y la firma sólo si `filled($expectedSignature)`
(`:45-46`), con el valor leído de `env('SSO_GATEWAY_SIGNATURE', ...)` (`config/sso.php:74`). Dos
problemas para TR3SLOG:

- El escape por `env` vacío **apaga un MUST de la spec**. Un test que verifique el 401 pasa en CI y
  miente en el ambiente donde la variable quedó vacía. Se aplica el mismo criterio que `config/sso.php`
  ya aplica a los nombres de cabecera: **constante del contrato, no variable de entorno.**
- El orden importa para el diagnóstico, que es literalmente lo que pide el escenario
  `Falta X-User-Id pese al sello de gateway`. Con el sello primero hay dos causas separables: *"esto no
  vino del gateway"* y *"vino del gateway pero el gateway está mal configurado"*. Con el orden de MSH,
  una petición directa con un `X-User-Id` forjado y sin sello se loguea con el motivo equivocado.

Se conserva **la clave de config con el mismo nombre** (`sso.gateway_signature`) y el mismo valor
(`myglobalhub-gateway`): la divergencia es de comportamiento local, no de contrato.

> Y se conserva, palabra por palabra, la advertencia de `config/sso.php:57-72`: **esto no es un secreto
> y no autentica nada.** El control real es de red (spec: `Backend inalcanzable fuera del gateway`).

**Divergencia 3 — existe `ResolveDomainUser`, que en MSH no existe.**
Es la divergencia estructural, y es aditiva a propósito: **vive en un middleware que MSH no tiene, en
vez de ensuciar uno que MSH sí tiene.** MSH no tiene tabla `users` (`contrato:315-317`); TR3SLOG sí y
tiene nueve FKs colgando (§A). Que la costura viva en su propia clase quiere decir que
`AuthenticateFromGateway` se puede seguir sincronizando entre las dos aplicaciones sin merges a mano.

**Cambio cosmético, no divergencia**: `RequestContext::generateId()` genera `'msh-'.Str::uuid()`
(`RequestContext.php:146`). En TR3SLOG será `'treslog-'`. El prefijo existe justamente para saber quién
lo generó; copiarlo sería vaciarlo de sentido.

### Qué se descartó

| Alternativa | Por qué no |
|---|---|
| Copiar los tres archivos sin tocar nada | Habría que aceptar un slug fuera del catálogo y un MUST de la spec apagable por `env`. "No divergir" no puede significar "copiar un bug conocido" |
| Un paquete Composer compartido entre MSH y TR3SLOG | Es la respuesta correcta a mediano plazo y la recomendamos. Pero hoy son dos repos sin registry privado ni CI compartido, y montarlo dentro de este cambio lo convierte en dos proyectos. Se anota como deuda con dueño |
| Fusionar `gateway.auth` y `gateway.user` en un middleware | Garantiza que el archivo compartido con MSH diverja para siempre |
| Un `UserProvider`/guard de Laravel en lugar de `auth()->setUser()` | Un guard existe para **validar credenciales**, y acá no hay ninguna que validar: el gateway ya decidió. Sería ceremonia que además reintroduce el hábito de `Auth::attempt` que estamos retirando |

---

## D. El mapeo de roles

### D.1 La tabla (prefijo obligatorio, `alta_de_aplicacion.md:164-178`)

| Hoy (`RoleSeeder.php`) | SSO | Nota |
|---|---|---|
| `customer` | `treslog:cliente` | `default_role` de la aplicación |
| `driver` | `treslog:conductor` | |
| `operations` | `treslog:operaciones` | |
| `admin` | `treslog:admin` | **nunca `Super Admin`** |
| `company` | **se borra** | 0 permisos en `RolePermissionSeeder`, 0 checks en el código |
| `dispatcher`, `manager` | **no existen** | Aparecen en `RolePermissionSeeder.php:47,56` pero `RoleSeeder.php` nunca los crea: el `if (!$role) continue;` (`:100`) los descarta en silencio. Son un mapa de intenciones, no roles |

### D.2 Los nombres viven en `config/sso.php`, no sueltos en el código

```php
'roles' => [
    'admin'       => 'treslog:admin',
    'operaciones' => 'treslog:operaciones',
    'conductor'   => 'treslog:conductor',
    'cliente'     => 'treslog:cliente',
],
```

**Por qué**: un rol sin `:` no da error — `ApplicationScope::alcanza()` (`GatewayIdentity.php:110`) lo
filtra y **nunca viaja**. La persona lo tiene asignado, la aplicación no se entera, y el síntoma es
"no me deja entrar" sin una sola línea de log (R3). Con 9 controladores repitiendo el string, alcanza
equivocarse una vez. Un test recorre `config('sso.roles')` y falla si algún valor no empieza con
`treslog:` o si el literal `Super Admin` aparece en cualquier archivo de `app/` (R4, y es un criterio de
éxito de la propuesta).

### D.3 `hasPermission()` se ELIMINA. No devuelve `false`, no existe.

Es la decisión con más consecuencias de esta sección. H1 dice que el backend no puede resolver permisos
finos: `Authorization` no llega (`contrato:168-169`) y `GET /api/v1/authorization` es del canal persona.
Entonces `User::hasPermission()` (`User.php:71-78`) no tiene forma de responder bien.

- Si devolviera `false`: el admin pierde acceso a todo, en silencio.
- Si devolviera `true`: cualquiera gana acceso a todo, en silencio.

**Las dos son catastróficas y ninguna falla ruidosamente.** Por eso el método se borra y las **20
llamadas** (N2) se reemplazan **una por una** por una guarda de rol. La traducción sale de
`RolePermissionSeeder.php:28-96`, que es la matriz real:

| Permiso | Roles que lo tienen hoy | Guarda nueva |
|---|---|---|
| `users.{view,create,edit,delete}` | sólo `admin` | `treslog:admin` |
| `drivers.manage`, `dispatch.manage` | `operations`, `admin` | `treslog:operaciones` o `treslog:admin` |
| `shipments.{edit,delete}` | `operations`, `admin` | ídem |
| `quotes.view` | `operations`, `admin` | ídem |
| `quotes.{create,edit,delete}`, `support.{view,edit}` | **nadie (N1)** | **decisión de producto pendiente**, ver D.5 |

Los 15 permisos que nadie consulta (N2) no se traducen: se borran con la tabla en el PR 9.

**Descartado**: dejar `hasPermission()` leyendo `X-User-Roles` con un mapa interno permiso→rol. Suena
conservador y es lo contrario: deja veinte call sites que *parecen* chequear permisos finos, cuando el
sistema ya no los tiene. La próxima persona agrega un permiso nuevo, no funciona, y no hay nada que
leer que se lo explique. **Si algo dejó de ser posible, tiene que dejar de compilar.**

### D.4 Las Policies: `hasAnyRole()` sobrevive, con una regla dura

Las 4 Policies siguen existiendo — la lógica de **pertenencia** (`$shipment->user_id === $user->id`,
`ShipmentPolicy.php:17`) no la puede hacer el SSO, es del dominio. Lo que cambia es de dónde salen los
roles: `hasRole/hasAnyRole/isAdmin` dejan de consultar `role_user` y leen la identidad SSO **de la
instancia**, que `ResolveDomainUser` hidrata en el momento de resolverla.

**La regla dura**: si esa propiedad **no fue hidratada** (un `User` traído de una query, de un job en
cola, de `tinker`), los tres métodos **lanzan excepción**. No devuelven `false`.

**Por qué**: un `false` silencioso convierte cualquier `User::find($id)->isAdmin()` escrito en el futuro
en una denegación fantasma, y ese bug no deja rastro. Los roles ya no son un atributo de la fila: son un
atributo de **esta petición**. El código tiene que enterarse cuando confunde las dos cosas.

Verificado que hoy **todos** los call sites pasan el usuario autenticado (`$request->user()->hasAnyRole(...)`
en `DriverController.php:16,47`, `IncidentAdminController.php:15,26,50`, `UserController.php:169,191,194,240`;
y en las Policies el Gate siempre inyecta el autenticado). O sea que la regla no rompe nada hoy y protege
de mañana.

### D.5 Los cinco endpoints muertos de N1 — no se arreglan acá, se marcan

`quotes.edit`, `quotes.delete`, `support.view` y `support.edit` no existen como permiso y **nadie los
tiene**. Traducirlos a un rol es **decidir a quién le damos un acceso que hoy no tiene nadie**, y eso es
producto, no migración. Este diseño hace tres cosas y ninguna más:

1. Los tests de caracterización congelan el 403 actual, con el motivo escrito en el test (§F.2).
2. La guarda nueva es `treslog:admin` **más un `@todo` con el número de esta decisión** — la traducción
   más restrictiva posible, que no concede nada nuevo respecto de hoy.
3. Se levanta como **D7** (§H).

**Descartado**: aprovechar la migración para "arreglarlo" dándole `treslog:operaciones`. Sería un cambio
de comportamiento escondido dentro de un PR de infraestructura. Si mañana un ticket de soporte se
modifica de más, nadie va a ir a buscar la causa en el PR del SSO.

---

## E. Los tres logins (cuatro, con N3): qué muere, qué se redirige, cómo conviven

### E.1 El inventario, con destino

| Camino | Rutas | Destino | En qué PR |
|---|---|---|---|
| `AuthController` (web Next) | `POST /register`, `/login`, `/forgot-password`, `/verify-reset-token`, `/reset-password` (`routes/api.php:40-44`), `GET /user`, `POST /logout` (`:54-55`) | **Muere.** Login por `/oauth/authorize` + PKCE contra el SSO; `GET /api/v1/user` y `POST /api/logout` los llama **el cliente, directo al SSO** | 9 |
| `ApiAuthController` (`/app/*`) | `POST /app/register`, `/login`, `/forgot-password`, `/reset-password` (`:151-154`), `GET /app/user`, `POST /app/logout` (`:160-161`) | **Muere**, igual que el anterior. **Bloqueado por D3** | 9 |
| `DriverAuthController` (Flutter) | `POST /driver/register`, `/login` (`:175-176`), `GET /driver/me`, `POST /driver/logout` (`:179-180`) | **Muere.** `/driver/me` lo reemplaza `GET /api/treslog/driver/dashboard` con las cabeceras | 9 |
| `UserController::store` (**N3**) | `POST /users` público (`:47`) | **Muere.** El alta la hace el SSO | 4 |
| `invitations/*` | `:137-145`, sin auth | **Sobrevive congelada**, detrás de `gateway.auth` (spec `Invitaciones autenticadas`). Su futuro es D4 | 4 |

**Nada de esto se borra antes del PR 9.** Es el único punto de no retorno del plan.

### E.2 Cómo conviven — montaje en paralelo, no bifurcación

**Decisión: las rutas nuevas se montan en un `Route::prefix('treslog')` con su propia cadena de
middleware, duplicando el registro de las rutas de dominio. Cero `if` dentro de los controladores.**

```php
// routes/api.php  — durante la transición conviven los dos bloques

Route::middleware('auth:sanctum')->group(function () {   // <- el de hoy, intacto hasta el PR 9
    ...
});

Route::prefix('treslog')                                  // <- H2: el gateway entrega /api/treslog/...
    ->middleware(['gateway.auth', 'gateway.user'])
    ->group(function () {
        require __DIR__.'/domain.php';                    // <- una sola definición, dos montajes
    });
```

**Por qué en paralelo y no un middleware que acepte las dos cosas**: un middleware que autentique "por
Sanctum o por cabeceras" es un `if` en el punto más sensible del sistema, y ese `if` **no se retira
nunca** — no hay ninguna señal que diga cuándo dejó de hacer falta. Con dos montajes, retirar es borrar
un `Route::middleware('auth:sanctum')`, y el diff del PR 9 se lee en un minuto.

**Por qué `require` de un archivo de rutas compartido y no copiar y pegar**: si se duplican las
definiciones, la primera ruta que alguien agregue en un solo bloque produce un endpoint que existe para
la web vieja y no para el gateway (o al revés). El test de cobertura de la spec
(`Cobertura total de rutas de dominio`) enumera rutas registradas; con un archivo compartido, ese test
tiene una respuesta computable.

**Por qué H2 no es negociable**: el gateway hace `proxy_pass ${MSH_BACKEND}` sin componente de path
(`default.conf.template:183`), así que NGINX pasa la URI **completa**. Con `location /api/treslog/`, el
backend recibe `/api/treslog/...`. No es una preferencia de diseño: es cómo enruta NGINX.

**Quién queda afuera, dicho claro**: durante la convivencia, una persona que ya existe en el SSO pero
**no tiene fila en `users` con `sso_user_id`** recibe 403 por el camino nuevo (§A.2) y sigue entrando
por el viejo. Por eso el paso 5 del despliegue está bloqueado por la migración de cuentas (R10) y no
por este diseño. **Nadie queda afuera mientras el bloque viejo siga montado.**

### E.3 El orden de retiro

El único orden seguro es el de la propuesta §5, y su regla es una: **nunca se retira una ruta vieja
antes de que exista un cliente que use la nueva.** La app de conductores tiene la URL congelada en el
binario (H3, `api_service.dart` con `String.fromEnvironment`), así que su retiro se dispara por una
métrica de adopción — y **esa métrica hoy no se puede medir** (D6). El diseño no puede resolverlo.

**Descartado**: *flag day* con redirección `301` de las rutas viejas a las nuevas. Un `301` no arregla
nada acá: el cliente viejo manda `Authorization: Bearer <token de Sanctum>`, y el destino nuevo no valida
tokens — no puede, por diseño. Redirigiría a un 401 garantizado, con un salto extra para confundir.

---

## F. Tests desde cero: qué se escribe PRIMERO y por qué

Punto de partida verificado: `tests/` tiene `Feature/ExampleTest.php`, `Unit/ExampleTest.php` y
`TestCase.php`. Nada más. `database/factories/` tiene **una sola** factory (`UserFactory.php`) para
**nueve** tablas de dominio. Eso es trabajo real, no un `composer require`.

Infraestructura confirmada: SQLite en memoria (`phpunit.xml:26-27`), que **sí valida FKs** — relevante
para §B.

### F.1 El orden, y el criterio que lo determina

El criterio es uno solo: **primero lo que cubre el camino que estás por romper.** No cobertura, no
pirámide: el filo del cuchillo.

**Nivel 0 — antes de tocar una línea de auth (PR 1, bloqueante).**

1. **Factories de las 9 tablas de dominio.** Sin esto no hay nivel 1. Es lo primero porque es la única
   dependencia dura de todo lo demás.
2. **Caracterización de las rutas del conductor**: `GET /driver/dashboard`, `/routes`, `/routes/{route}`,
   `POST /stops/{stop}/confirm`, `/stops/{stop}/fail`, `GET /incidents`, `POST /incidents`,
   `GET /payroll` (`routes/api.php:182-193`). **Van primero de todo el nivel** porque es el grupo que la
   propuesta migra primero (paso 5) y el único donde el cliente **no se puede redesplegar** (H3): si
   rompemos esto, se entera un conductor en la calle. Cada ruta con dos casos: el conductor dueño (200)
   y otro conductor (403 — `StopController.php:57`, `RouteController.php:24` hacen `abort_if` por
   `driver_id`). **Esa comparación por `driver_id` es exactamente lo que la costura de identidad de §A
   puede romper**, y es lo que estos tests protegen.
3. **Caracterización de pertenencia en el resto del dominio**: `ShipmentPolicy::view`
   (`ShipmentPolicy.php:17`), `AddressController.php:194`, `SupportController.php:21`. Mismo motivo: todo
   eso compara contra `$request->user()->id`.

**Nivel 1 — junto con el middleware (PR 3).**

4. **`AuthenticateFromGateway`, tabla de verdad completa**: sin `X-Auth-Gateway` → 401; con valor forjado
   → 401; con sello pero sin `X-User-Id` → **401 y no 500**; sin `X-User-Name` → 200; sin `X-User-Roles`
   → roles vacíos, no excepción. Son los cinco escenarios negativos de la spec, uno a uno.
5. **`RequestContext`**: `X-Request-Id` entrante se propaga al cuerpo del error; ausente → id generado,
   nunca vacío.
6. **`ResolveDomainUser`**: `sso_user_id` conocido → misma fila en dos peticiones; email coincidente sin
   `sso_user_id` → **403, no vínculo** (la invariante de la spec que evita el secuestro de cuenta).

**Nivel 2 — junto con el cierre de agujeros (PR 4).**

7. Los tres agujeros de §1 de la propuesta, más el cuarto (N3): `POST /register` con `{"role":"admin"}`
   no produce un admin; `roles/*`, `permissions/*`, `zones/*` con `treslog:cliente` → 403;
   `GET /invitations/pending` sin cabeceras → 401.
8. **`Super Admin` → 403.** Un test explícito, porque el modo de falla es conceder de más.
9. **Rol de otro inquilino** (`tienda:vendedor`) → 403.

**Nivel 3 — invariantes estructurales, no de comportamiento (PR 4 en adelante).**

10. Enumerar las rutas registradas y **fallar si una ruta de dominio no tiene `gateway.auth`**.
11. **Fallar si algún valor de `config('sso.roles')` no empieza con `treslog:`**, o si el literal
    `Super Admin` aparece en `app/`.

Estos dos son los que sobreviven al cambio: no verifican una respuesta, verifican que **no se puede
volver a cometer el error**. Son la respuesta a R3 y R4, que son fallas silenciosas.

### F.2 La regla de oro de la caracterización, por N1

**Un test de caracterización registra lo que el sistema hace HOY, no lo que debería hacer.** Con N1 eso
significa escribir cinco tests que afirman `403` para un admin, y eso se ve mal en una revisión. Se
escriben igual, con el motivo en el nombre y un comentario que apunte a D7:

```php
/** El admin recibe 403: `quotes.edit` no existe en PermissionSeeder y ningún rol lo tiene.
 *  Congelado a propósito (N1/D7). Si este test empieza a fallar, ALGUIEN CONCEDIÓ UN ACCESO
 *  NUEVO — que puede estar bien, pero es una decisión de producto y va en su propio PR. */
```

**Por qué importa tanto**: sin esto, la migración a roles "arregla" cinco endpoints sin que nadie lo
decida. El día que un ticket de soporte aparezca modificado por quien no debía, la causa va a estar
enterrada en un PR de infraestructura de tres meses antes.

### F.3 Qué NO se escribe, y por qué

| Descartado | Por qué |
|---|---|
| Tests unitarios de las Policies antes que los de integración | Las Policies dependen de `hasPermission()`, que **se va a borrar** (§D.3). Serían tests de código muerto escritos a sabiendas |
| Tests de los tres controladores de auth | Mueren en el PR 9. Es la única parte del sistema donde escribir un test es tirar el trabajo |
| Cobertura mínima como criterio de entrada | Un porcentaje premia el archivo fácil. El criterio acá es **caminos rotos por el cambio**, y esos están enumerados arriba |
| Tests end-to-end contra el gateway real en CI | Necesitan Docker, NGINX y el SSO levantado. El contrato del gateway con el backend son **cabeceras HTTP**, y eso se prueba con `withHeaders()`. El gateway se valida a mano con `SETUP_LOCAL.md` |

---

## G. El gateway

### Decisión

Se copia `MSH/gateway/` completo a `treslog/backend_trelog/gateway/` con **cuatro cambios y ninguno
más**:

| Qué | De | A |
|---|---|---|
| El `location` | `/api/msh/` (`default.conf.template:106`) | `/api/treslog/` |
| `set $sso_app` | `"msh"` (`:110`) | `"treslog"` — es lo que viaja en `X-Sso-Application` y decide **contra qué aplicación se filtran los roles** (`:80`) |
| La variable de upstream | `${MSH_BACKEND}` (`:183`, `env.example:11`) | `${TRESLOG_BACKEND}` |
| `container_name` y `GATEWAY_PORT` | `msh-gateway`, `8002` (`compose.yaml:18,22`) | `treslog-gateway`, `8003` — **puerto distinto a propósito**: los dos gateways tienen que poder correr a la vez en la misma máquina |

**Todo lo demás se copia literal**, y conviene decir qué es "todo lo demás", porque es la parte cara:

- Los **cuatro modos de falla** de `@sso_unavailable` (`:219-274`), que distinguen *gateway mal
  configurado* (`$sso_error` → 500 **sin** `Retry-After`), *`/api/v1` no desplegado* (`$sso_status = 404`
  → 500 con mensaje que apunta a `SSO_UPSTREAM`), *backend caído* (`$sso_user_id` presente → 502) y *SSO
  caído* (503 con `Retry-After`). Sin esa separación, "el backend no está levantado" —el error más
  común en local— se le presenta al cliente como *"el SSO no responde"*, que es exactamente el lugar
  donde no hay que mirar.
- El preflight **antes** del `auth_request` (`:114-129`): el navegador no manda `Authorization` en un
  `OPTIONS`, así que validarlo daría 401 siempre. Y el `Vary: Origin` repetido dentro del `if`, porque
  en NGINX un `add_header` dentro de un `if` **reemplaza** los heredados en vez de sumarse (`:121-126`).
- El `proxy_set_header Authorization ""` y `Cookie ""` (`:163-164`): el backend no necesita ni debe ver
  el token.
- `/_sso_validate` contra `/api/v1/validate-token` y **no** contra `/api/validate-token` (`:54-59`): la
  ruta legada devuelve **todos** los roles de la persona sin filtrar por aplicación. Con dos
  aplicaciones conectadas, TR3SLOG recibiría los roles que esa persona tiene en MSH.
- Los timeouts acotados de la subpetición (`:92-100`). Todo `/api/treslog/` pasa por ahí: con los
  defaults de 60s, un SSO lento agota los workers y el gateway deja de responder incluso lo que no
  necesita al SSO.

El `map $http_origin $cors_origin` (`:25-29`) hay que **revisarlo**, no copiarlo a ciegas: hoy lista los
orígenes de MSH. Los de TR3SLOG son la web Next y, en desarrollo, la app Flutter.

### Por qué copiar y no escribir uno nuevo

Cada uno de los bloques de arriba es una tarde de alguien depurando un síntoma que apuntaba al lugar
equivocado. Los comentarios del template lo dicen con nombre y apellido. Escribir un NGINX "más simple"
es garantizar que se vuelvan a pagar esas tardes, y con menos contexto.

### Qué se descartó

| Alternativa | Por qué no |
|---|---|
| Un solo gateway con dos `location` (`/api/msh/` y `/api/treslog/`) | Es lo correcto **en producción**, y hacia ahí va. En local no: obliga a levantar los dos backends para trabajar en uno, y acopla el ciclo de vida de dos equipos. La copia local se justifica **porque el archivo es idéntico salvo cuatro valores** — la convergencia es un `envsubst` más, no una reescritura |
| Traefik / Caddy / un gateway en Laravel | `auth_request` es una primitiva de NGINX y es el corazón del diseño. Cambiar de servidor es rehacer el contrato |
| Un `Dockerfile` propio | La imagen oficial ya corre `envsubst` sobre `/etc/nginx/templates/*.template` (`compose.yaml:25-29`). Un Dockerfile sería una capa más que mantener y una versión de NGINX que se desactualiza sola |
| Que el backend valide el token además del gateway | **No puede**: `Authorization` no llega (`contrato:168-169`). No es una elección |

---

## H. Lo que este diseño NO resuelve

| # | Qué | Quién decide | Qué bloquea |
|---|---|---|---|
| **D1** | ¿El slug es `treslog`? | Producto + equipo SSO | El alta (paso 1). **Inmutable** una vez creado: prefija cada rol y cada URL |
| **D3** | ¿`/app/*` está en producción? | Producto | El alcance del PR 9 |
| **D4** | Alta de conductores: `default_role` es **uno solo por slug** (`alta_de_aplicacion.md:197-217`), no distingue cliente de conductor | Producto | El catálogo de roles del paso 1 |
| **D5** | Los agujeros de §1 (+N3): ¿parche en `main` ya, o mueren en el PR 4? | Dueño del riesgo | Nada, pero urge |
| **D6** | Instalaciones reales de la app de conductores | Datos | Los pasos 5, 6 y 9 |
| **D7** | **NUEVA (N1)**: cinco endpoints hoy dan 403 a todo el mundo, admin incluido. ¿Se arregla, y para quién? | Producto | Nada. La migración los deja como están |
| — | **Migración de cuentas existentes a Clerk** | Equipo SSO + producto | **El paso 5.** §A.2 rechaza el vínculo automático por email a propósito. Sin identidades creadas, migrar una ruta deja afuera a todos los usuarios de hoy |

**Confirmación pendiente, no bloqueante**: D2 se decidió acá (§A) con evidencia del código del SSO. Vale
la pena que el equipo del SSO lo confirme y corrija `contrato:153` vs `:224`, que hoy se leen como una
contradicción. Si respondieran `clerk_id`, la migración es un `UPDATE` de una columna que ya vamos a
estar guardando.

---

## Consecuencias

**Lo que se gana.** El PR 9 es el único punto de no retorno y su diff se lee en un minuto (borrar un
`Route::middleware('auth:sanctum')` y tres controladores). Las 9 FKs y la integridad referencial no se
tocan. `AuthenticateFromGateway` queda sincronizable con MSH. Equivocarse en D2 cuesta una columna.

**Lo que se paga.** Las rutas de dominio están registradas dos veces durante la transición (mitigado con
el `require` compartido). `users` sobrevive con columnas que ya no son fuente de verdad — el riesgo real
es que alguien lea `users.name` creyendo que es autoritativo, y por eso `ResolveDomainUser` lo refresca
en cada petición. Y `hasRole()` que lanza excepción sobre un modelo no hidratado va a sorprender a
alguien en un job en cola; es el precio elegido a cambio de que no falle en silencio.

**El riesgo que queda abierto.** El más grande no es técnico: es que el paso 5 se despliegue antes de que
existan las identidades en Clerk. El diseño lo hace visible —un 403 con mensaje explícito, no un vínculo
automático por email— pero no lo puede impedir.
