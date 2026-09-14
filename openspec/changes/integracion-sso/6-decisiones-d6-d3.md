# D6 y D3: las cuentas de TR3SLOG viven en el SSO

**Fecha**: 2026-09-14 · **Decide**: el usuario (dueño del SSO) · **Estado**: D6 resuelta; D3 con
propuesta y cuatro preguntas para el equipo de TR3SLOG.

---

## D6 — resuelta: no hay instalaciones reales

> «D6 es algo que aún está en desarrollo, no hay nada publicado, solo lo que vemos en repo.»

Consecuencias, todas favorables:

1. **El Lote 6 queda desbloqueado.** La app de conductores puede pasar al login por el SSO
   (PKCE) sin dejar a nadie afuera: no hay choferes con la app instalada.
2. **El camino viejo deja de ser sagrado.** Hoy `/api/driver/*` con Sanctum se mantiene «porque
   hay binarios en la calle». No los hay. El Lote 9 sigue siendo el punto de no retorno, pero
   su bloqueo real pasa a ser uno solo: cómo se crean las cuentas (D3, abajo).
3. La URL congelada en el binario (`api_service.dart`) es un problema del futuro build, no del
   presente.

---

## D3 — decidido en principio: registro Y login los absorbe el SSO

> «Lo ideal es que el registro como el login lo absorbamos nosotros, por lo que esos usuarios de
> TR3SLOG los debemos crear con permisos que permitan manejarse en la app.»

### Lo que el código dice que existe hoy (verificado)

| Punto de entrada | Ruta | Quién | Qué crea |
|---|---|---|---|
| Registro web | `POST /register` (`AuthController`) | cualquiera | `users` + rol `customer` |
| Registro app de clientes | `POST /app/register` (`Api\AuthController`) | cualquiera | `users` + rol `customer` |
| Registro de conductor | `POST /driver/register` (`Api\Driver\DriverAuthController`) | cualquiera | `users` + rol `driver` + `DriverProfile` |
| Alta de conductor por admin | `POST /drivers` (`DriverController::store`) | admin / operaciones | `users` (con contraseña) + rol `driver` + `DriverProfile` |
| Invitación | `POST /invitations/send` → `POST /invitations/accept` | admin invita; el invitado acepta con contraseña | `users` + rol `customer` (+ `company_name`) |
| Admin y operaciones | **sólo `AdminUserSeeder`** | nadie desde una pantalla | rol `admin` / `operations` |
| Login web | `POST /login` | — | token Sanctum |
| Login app de clientes | `POST /app/login` | — | token Sanctum |
| Login app de conductores | `POST /driver/login` (`auth_service.dart`) | — | token Sanctum |

Roles en el SSO para `treslog`: `admin`, `operations`, `driver`, `customer`, `company`.
`default_role` = `treslog:customer`.

### Cómo queda cada uno con el SSO — propuesta

| Hoy | Con el SSO | Estado |
|---|---|---|
| Registro web / app de clientes | **Registrarse en el SSO** (pantalla `login_treslog` → «Cree su cuenta»). El SSO asigna `default_role` = `treslog:customer`. | ✅ ya funciona para la web |
| Login web / app de clientes / app de conductores | **PKCE contra el SSO** | ✅ web · ⏳ app de conductores (Lote 6) · ❓ app de clientes (pregunta 1) |
| Admin y operaciones | El **panel del SSO** asigna `treslog:admin` / `treslog:operations` a una persona ya registrada. Existe hoy (Super Admin). | ✅ existe; sin seeder |
| Alta de conductor por admin | El admin, desde la consola de TR3SLOG, indica el correo → TR3SLOG le pide al SSO que **cree o encuentre** a la persona y le asigne `treslog:driver`; localmente crea el `DriverProfile`. | ❌ falta un API en el SSO (abajo) |
| Invitación de cliente | Igual: TR3SLOG pide al SSO crear/encontrar a la persona con `treslog:customer` y guarda `company` localmente. El correo de invitación lo manda el SSO (o TR3SLOG con un enlace al login del SSO). | ❌ mismo API |
| Fila espejo (`users.sso_user_id`) | **Alta automática** al primer ingreso, como en MSH: sin padrón que proteger (nada publicado), el 403 «no habilitada» sólo estorba. Los roles los sigue decidiendo el SSO; la fila es identidad. | 🔧 cambio chico en `ResolveDomainUser` |

### La pieza que falta en el SSO: aprovisionar y asignar roles desde una aplicación

Dos flujos de TR3SLOG (alta de conductor por admin, invitación de cliente) crean cuentas **con un
rol que no es el default**. Con el registro absorbido, eso sólo puede hacerlo el SSO, a pedido de
la aplicación. Hace falta, en el canal `backend` (servidor a servidor, `client_credentials`, que
hoy «todavía no tiene camino genérico» según `alta_de_aplicacion.md`):

- `POST /api/v1/app/users` — «creá o encontrá a esta persona por correo» → devuelve `sso_user_id`
  y si es nueva. Si es nueva, el SSO manda el correo de bienvenida con el enlace de ingreso.
- `POST /api/v1/app/users/{sso_user_id}/roles` — «asignale este rol **de mi aplicación**». El
  SSO sólo acepta roles con el prefijo del `slug` del cliente que llama (`treslog:*`): una
  aplicación no puede tocar roles de otra ni los de plataforma.

Sin esto, la alternativa que funciona hoy es **manual**: el admin de TR3SLOG entra al panel del SSO
y asigna el rol. Sirve para arrancar; no sirve para operar.

### Lo que NO se decide acá (y por qué)

- **Cuentas locales existentes.** No hay producción, así que no hay cuentas que migrar. Si las
  hubiera, la regla ya escrita en MSH aplica: vincular por correo **verificado por Clerk**, nunca
  crear una segunda fila.
- **El rol `company`.** Existe en el SSO y en `roles`, pero ningún punto de entrada lo asigna.
  Pregunta 4.

---

## Preguntas para el equipo de TR3SLOG

Sólo las que el código no responde. Cada una decide algo concreto.

1. **¿Existe una app de clientes?** Las rutas `/app/*` (`Api\AuthController`, `Api\QuoteController`)
   son «customer facing» y no hay repo. Si existe, va al Lote 6 junto con la de conductores. Si no,
   `/app/*` muere en el Lote 9 sin reemplazo.
2. **¿Un conductor puede registrarse solo?** Hoy `POST /driver/register` lo permite: cualquiera
   con la app se vuelve conductor. Con el SSO, el rol `treslog:driver` **no** puede ser el default
   (sería regalar acceso a la nómina y las rutas). Propuesta: sólo el admin da de alta conductores;
   el registro público siempre es `customer`. ¿De acuerdo?
3. **¿Quién crea a los administradores?** Hoy sólo un seeder. Propuesta: el Super Admin del SSO
   asigna `treslog:admin` / `treslog:operations` desde el panel del SSO. ¿Alcanza, o TR3SLOG
   necesita hacerlo desde su propia consola (entonces entra en el API de arriba)?
4. **¿Qué es `company`?** Rol en el SSO y en `roles`, nunca asignado; y `company_name` viaja en
   las invitaciones. ¿Es un rol (una empresa cliente con varios usuarios) o un dato del cliente?
   Cambia si hace falta un tercer flujo de alta.

---

## Qué se puede hacer YA, sin esperar respuestas

| # | Trabajo | Dónde | Tamaño |
|---|---|---|---|
| a | Lote 6: PKCE en la app de conductores (misma referencia que MSH y la web) | `tr3slog_driver_app` | mediano |
| b | Alta automática del espejo en `ResolveDomainUser` (como MSH) | `backend_trelog` | chico, con tests |
| c | API de aprovisionamiento y roles por aplicación, canal `backend` | `SSO` | mediano; es lo que desbloquea invitaciones y alta de conductores |
| d | `POST /drivers` e invitaciones llamando a (c) | `backend_trelog` | chico una vez que (c) exista |

Las preguntas 1 a 4 sólo afectan a (d) y al Lote 9.
