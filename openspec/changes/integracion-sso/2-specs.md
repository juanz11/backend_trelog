# Especificación: TR3SLOG consume el SSO de MyGlobalHub

**Cambio**: `integracion-sso` · **Fuente**: `1-proposal.md` · **Contrato**: `SSO/Docs/contrato_api_v1_msh.md`,
`SSO/Docs/alta_de_aplicacion.md`

**Tipo de specs**: las tres son NUEVAS y COMPLETAS. No existe `openspec/specs/` previo en este
repositorio (confirmado — el directorio no tenía contenido antes de este cambio, ver Capabilities
en `1-proposal.md`). No hay MODIFIED ni REMOVED porque no hay spec anterior contra la cual diffear.

Cada requisito describe lo que DEBE ser verdad después de aplicar el cambio, no cómo implementarlo.
Cada escenario está escrito para convertirse en un test tal cual.

---

## Dominio 1: `autenticacion-por-gateway`

### Purpose

Toda petición que llega al backend de TR3SLOG se identifica por las cabeceras que pone el gateway
NGINX (`X-User-*`), nunca por un token que el backend valide (`Authorization` no llega, ver
`contrato_api_v1_msh.md:168-169`). El backend rechaza lo que no tenga el sello del gateway.

### Requirements

#### Requirement: Rechazo sin sello de gateway

El sistema MUST rechazar con `401` toda petición a una ruta de dominio protegida cuya cabecera
`X-Auth-Gateway` esté ausente o cuyo valor no sea exactamente `myglobalhub-gateway`.

##### Scenario: Petición directa al backend, sin pasar por el gateway

- GIVEN una ruta de dominio protegida (p. ej. `GET /api/treslog/shipments`)
- WHEN llega sin la cabecera `X-Auth-Gateway`
- THEN el sistema responde `401` con `{"error":"unauthenticated","message":...,"request_id":...}`

##### Scenario: Cabecera presente con valor forjado

- GIVEN la misma ruta
- WHEN llega con `X-Auth-Gateway: cualquier-otra-cosa`
- THEN el sistema responde `401`, igual que si estuviera ausente

#### Requirement: Tolerancia a cabeceras ausentes por valor vacío

El sistema MUST tratar la ausencia de `X-User-Name` y de `X-User-Roles` como "sin dato" / rol
vacío, no como error, porque NGINX omite `proxy_set_header` de valor vacío
(`contrato_api_v1_msh.md:171-178`).

##### Scenario: Persona sin nombre en el SSO

- GIVEN una petición autenticada por el gateway sin `X-User-Name`
- WHEN el backend arma la identidad de la petición
- THEN el sistema no responde error por la ausencia y continúa resolviendo la petición

##### Scenario: Persona sin ningún rol de TR3SLOG ni de plataforma

- GIVEN una petición sin cabecera `X-User-Roles`
- WHEN el backend evalúa cualquier guarda de rol
- THEN el sistema la trata como conjunto de roles vacío (no autoriza, no lanza excepción 500)

#### Requirement: Cabeceras mínimas garantizadas

El sistema MUST asumir que `X-User-Id`, `X-User-Email` y `X-Auth-Gateway` están presentes en toda
petición que pasó el requisito anterior, y MUST NOT responder `500` si falta cualquier otra
cabecera no garantizada.

##### Scenario: Falta X-User-Id pese al sello de gateway (gateway mal configurado)

- GIVEN una petición con `X-Auth-Gateway: myglobalhub-gateway` pero sin `X-User-Id`
- WHEN el backend intenta resolver identidad
- THEN el sistema responde `401`, no `500`

#### Requirement: Correlación por request_id

El sistema MUST incluir el valor de `X-Request-Id` entrante (si existe) en toda respuesta de error
propia y en el log correspondiente; si no viene, MUST generar uno propio no vacío.

##### Scenario: Error propio con X-Request-Id entrante

- GIVEN una petición rechazada por falta de sello de gateway que trae `X-Request-Id: sso-abc123`
- WHEN el backend arma la respuesta de error
- THEN el campo `request_id` del cuerpo es `sso-abc123`

##### Scenario: Error propio sin X-Request-Id entrante

- GIVEN una petición rechazada sin esa cabecera
- WHEN el backend arma la respuesta de error
- THEN el `request_id` del cuerpo es un valor generado por el backend, nunca vacío ni `null`

#### Requirement: Backend inalcanzable fuera del gateway

El sistema, a nivel de despliegue, MUST NOT publicar el puerto del backend a hosts fuera de la red
del gateway. Es el control real de seguridad (`gateway_signature` no es un secreto, ver R5 de la
propuesta).

##### Scenario: Intento de conexión directa al puerto del backend

- GIVEN el backend corriendo detrás del gateway
- WHEN un host fuera de la red del gateway intenta conectar directamente al puerto del backend
- THEN la conexión es rechazada o no resuelve — no hay respuesta HTTP del backend

#### Requirement: Cobertura total de rutas de dominio

El sistema MUST tener cada ruta de dominio (envíos, rutas, paradas, incidentes, nómina,
cotizaciones, tickets) cubierta por la guarda de autenticación de gateway. Ninguna ruta de dominio
MUST quedar montada sin ella.

##### Scenario: Ruta de dominio sin la guarda

- GIVEN el conjunto completo de rutas de dominio registradas en `routes/api.php`
- WHEN se ejecuta la suite que enumera rutas y guardas
- THEN la suite falla si encuentra una ruta de dominio sin el guard de gateway

---

## Dominio 2: `autorizacion-por-rol-sso`

### Purpose

El backend ya no evalúa permisos finos — es una imposibilidad de contrato (H1: `Authorization` no
llega, así que `/api/v1/authorization` nunca es alcanzable desde el backend). Sólo evalúa roles,
leídos de `X-User-Roles`, con prefijo `treslog:`. Esto reemplaza el RBAC propio (`Role`,
`Permission`, tablas pivote) y cierra los tres agujeros abiertos hoy (§1 de la propuesta).

### Requirements

#### Requirement: Guarda por rol exigido

El sistema MUST responder `403` cuando una ruta exige un rol `treslog:X` y `X-User-Roles` no lo
contiene.

##### Scenario: Rol insuficiente

- GIVEN `GET /api/treslog/roles` exige `treslog:admin`
- WHEN llega con `X-User-Roles: treslog:cliente`
- THEN el sistema responde `403`

##### Scenario: Rol exacto presente entre varios

- GIVEN la misma ruta
- WHEN llega con `X-User-Roles: treslog:operaciones,treslog:admin`
- THEN el sistema autoriza la petición

#### Requirement: Roles de otra aplicación no cuentan

El sistema MUST NOT aceptar como válido, en ninguna guarda, un rol que no lleve el prefijo
`treslog:` — defensa en profundidad si el filtrado por aplicación del gateway fallara
(`contrato_api_v1_msh.md:180-181`, `alta_de_aplicacion.md:174-178`).

##### Scenario: Rol de otro inquilino presente en la cabecera

- GIVEN una ruta que exige `treslog:admin`
- WHEN llega con `X-User-Roles: tienda:vendedor`
- THEN el sistema responde `403`

#### Requirement: `Super Admin` no equivale a `treslog:admin`

El sistema MUST NOT tratar el rol de plataforma `Super Admin` como equivalente a ningún rol
`treslog:*` en ninguna guarda (prohibición explícita, R4 de la propuesta).

##### Scenario: Persona con Super Admin sin rol de TR3SLOG

- GIVEN una ruta que exige `treslog:admin`
- WHEN llega con `X-User-Roles: Super Admin`
- THEN el sistema responde `403`

#### Requirement: Cierre del agujero de auto-escalación en alta

El sistema MUST NOT permitir que un cliente determine su propio rol mediante un campo de la
petición de alta o registro. La única fuente de rol es `X-User-Roles`, resuelto por el gateway
(cierra el agujero de `AuthController.php:25,41-46`).

##### Scenario: Intento de auto-escalación a admin

- GIVEN cualquier endpoint de alta de cuenta vigente durante la migración
- WHEN el cuerpo de la petición incluye un campo `role` con valor `admin`
- THEN el sistema lo ignora o lo rechaza; el rol resultante de la persona nunca es `treslog:admin`
  por efecto de ese campo

#### Requirement: CRUD de roles, permisos y zonas protegido

El sistema MUST exigir `treslog:admin` para toda operación de lectura o escritura sobre la gestión
de roles, permisos y zonas (cierra el agujero de `RoleController`/`PermissionController`/
`ZoneController` sin guarda, §1 punto 2 de la propuesta).

##### Scenario: Cliente autenticado sin rol admin

- GIVEN `PUT /api/treslog/roles/{id}/permissions`
- WHEN llega con `X-User-Roles: treslog:cliente`
- THEN el sistema responde `403`

##### Scenario: Sin cabecera de rol

- GIVEN la misma ruta
- WHEN llega sin `X-User-Roles`
- THEN el sistema responde `403` (rol vacío)

#### Requirement: Invitaciones autenticadas

El sistema MUST exigir autenticación de gateway, como mínimo, en todo endpoint de `invitations/*`.
MUST NOT exponer datos de invitaciones a una petición sin identidad resuelta (cierra el agujero de
`routes/api.php:137-145`, "without auth for now").

##### Scenario: Listado de invitaciones sin cabeceras de gateway

- GIVEN `GET /api/treslog/invitations/pending`
- WHEN llega sin `X-Auth-Gateway`
- THEN el sistema responde `401`, no `200` con datos de email y nombre

#### Requirement: Cobertura total de guardas de rol

El sistema MUST tener, para cada acción sensible de escritura, borrado o administración en los
controladores de dominio, una verificación de rol explícita. Ninguna acción sensible MUST depender
únicamente de "estar autenticado".

##### Scenario: Acción sensible sin verificación de rol

- GIVEN el conjunto de controladores bajo `app/Http/Controllers`
- WHEN se ejecuta la suite que busca guardas de rol por acción sensible
- THEN la suite falla si encuentra una acción de escritura/administración sin guarda de rol

---

## Dominio 3: `identidad-espejo`

### Purpose

El dominio de TR3SLOG (envíos, rutas, incidentes, nómina, cotizaciones, tickets) necesita anclarse
a una identidad estable del SSO. La forma exacta del anclaje depende de la Decisión D2, abierta:
el contrato se contradice entre `X-User-Id` (§4.1: "su referencia estable es `id`") y
`X-User-Clerk-Id` (§3: "es la clave estable entre ambientes, no `X-User-Id`"). Los requisitos de
abajo valen para cualquiera de las dos resoluciones de D2 — no prescriben cuál.

### Requirements

#### Requirement: Anclaje único y estable por petición

El sistema MUST resolver, para toda petición autenticada por el gateway, exactamente una identidad
de dominio local a partir de UN identificador estable de cabecera — nunca a partir de
`X-User-Email` ni `X-User-Name`, ambos mutables.

##### Scenario: Misma persona, dos peticiones distintas

- GIVEN dos peticiones separadas con el mismo identificador estable en cabecera
- WHEN el sistema resuelve identidad en cada una
- THEN ambas resuelven a la misma identidad de dominio local

##### Scenario: Cambio de email no rompe el anclaje

- GIVEN una persona que cambió su email en el SSO entre una petición y otra
- WHEN el identificador estable de cabecera es el mismo en ambas
- THEN el sistema sigue resolviendo la misma identidad de dominio local

#### Requirement: No duplicación de identidad

El sistema MUST NOT crear una segunda identidad de dominio local para un identificador estable que
ya tiene una asociada.

##### Scenario: Segunda petición del mismo identificador

- GIVEN un identificador estable con identidad de dominio ya asociada
- WHEN llega una nueva petición con el mismo identificador
- THEN el sistema reutiliza la identidad existente — no crea una fila nueva

#### Requirement: Migración de cuentas existentes fuera de alcance

El sistema MUST NOT vincular automáticamente una cuenta local preexistente (creada antes de la
migración) a una identidad del SSO por coincidencia de email o nombre. El vínculo requiere un paso
de migración explícito, fuera del alcance de este cambio (R10 de la propuesta).

##### Scenario: Cuenta local preexistente sin vínculo SSO

- GIVEN un usuario creado en TR3SLOG antes de la migración, sin identidad SSO vinculada
- WHEN llega una petición autenticada por el gateway con un email que coincide con esa cuenta
- THEN el sistema NO asume que es la misma persona por coincidencia de email

> **Nota de alcance**: D2 bloquea cerrar este dominio a nivel de diseño (qué columna ancla las 9
> FKs de dominio, qué migración corre). Esta spec deja constancia de las invariantes que cualquier
> resolución de D2 tiene que cumplir; el diseño elige entre ellas.

---

## Fuera de alcance de esta spec

- **Permisos finos en el backend** — imposibilidad de contrato (H1), no un recorte de esta spec.
- **Forma del perfil** (`GET`/`PUT /api/v1/profile`, 13 claves) — la gobierna el SSO; los clientes
  (web, apps) la consumen directo, no pasa por TR3SLOG.
- **`client_credentials`** — TR3SLOG no lo necesita para consumir cabeceras del gateway (§2 de la
  propuesta).
