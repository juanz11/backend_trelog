# Respuestas del equipo de TR3SLOG (2026-09-14, 18:43–19:01)

Fuente: mensajes y nota de voz del desarrollador de TR3SLOG (llegó casi de último al equipo y lo
dice: «quiero estar seguro para no cagarla»). Lo que sigue es lo que respondió, pregunta por
pregunta de `7-preguntas-al-equipo.md`, y qué decide cada respuesta. Lo que quedó sin responder
está marcado así: ❓.

| # | Pregunta | Respuesta | Decide |
|---|---|---|---|
| 1 | ¿Alguien usa la app de conductores? | **No.** No existe build en ningún teléfono; la app se está creando **desde cero**, «apenas está la portada». Apunta al backend Laravel de él (de prueba). | **D6 CERRADA**: no hay a quién dejar afuera. El Lote 6 se puede desarrollar Y publicar; `/api/driver/*` viejo se retira con el Lote 9 sin ventana de migración. |
| 2 | ¿Existe una app de clientes? | **No.** El cliente es **modo web** (la `tr3slog-website`). Una app nativa «va a ser más adelante». | Las rutas `/app/*` no tienen consumidor: se retiran en el Lote 9 sin reemplazo. Cuando exista la app de clientes, entra por PKCE como la web. |
| 3 | ¿Un conductor puede registrarse solo? | **No.** Lo crea **operaciones** desde un formulario (React + Laravel) que guarda al usuario conductor. Faltan definir los datos que se le piden (licencia, etc.); va a pasar un ejemplo. | Confirma la propuesta: registro público = `treslog:customer`; conductor = alta por operaciones. Requiere el **API de aprovisionamiento del SSO** (crear/encontrar persona + asignar `treslog:driver`). Los datos del conductor son locales (`DriverProfile`). |
| 4 | ¿Quién crea administradores y operaciones? | Administrador: **manual**. Operaciones: «sería estupendo» que lo cree el administrador. Hay un rol previsto que cambia roles de personas, todavía no existe. | Administrador → panel del SSO (Super Admin), como hoy. Operaciones → el admin desde la consola de TR3SLOG, por el **mismo API** de la fila anterior (asignar `treslog:operations`). |
| 5 | Invitaciones y `company` | **Sí se usan.** Hay una parte «business/empresarial»: una **empresa cliente que maneja varios envíos** invita a varias personas y les da roles **dentro de la empresa**. El admin haría esa interfaz de roles; tiene el diseño. | `company` **no es un rol de persona**: es una **cuenta empresarial** con miembros. Los roles *dentro* de la empresa son del dominio de TR3SLOG (tabla local de membresías), NO roles del SSO. En el SSO la persona invitada es `treslog:customer`; el API de aprovisionamiento la crea/encuentra. El rol `treslog:company` del SSO queda sin uso: se retira. |
| 6 | ¿Base con usuarios reales? | **No.** Solo usuarios de prueba (cliente, operaciones, driver) del flujo que están armando. | No hay migración de cuentas. La alta automática de la fila espejo (como MSH) es segura. |
| 7 | Rutas públicas (contacto, cotizar, tracking) | Son públicas «por tiempo», y ofrece hacerlas privadas. **Pero** describe el producto: cualquiera cotiza en la web y le llega por correo; el tracking por número de referencia «como UPS». | Se quedan **públicas**: son funciones del producto, no un descuido. Necesitan un **camino público en el gateway** (`/api/treslog/public/*` sin `auth_request`), que hoy no existe. |
| 8 | ¿Quién crea cotizaciones? | Dos formas: **anónimo** en la web (resultado por correo) y **cliente registrado** (más campos, pantalla propia). | D7: cotiza el cliente (anónimo o registrado). Operaciones/admin **no** crean cotizaciones: nadie lo pidió (segunda ronda). |
| 9 | Mock de API | No lo conocía («tendríamos que hablar»). | Informativo. Queda `SETUP_LOCAL.md`. |

## El catálogo de roles del PDF

Mandó un PDF «Roles del sistema definidos hoy en TR3SLOG»: administrativos `sysadmin`, `secadmin`,
`compliance`, `opsmgr`, `finance`, `support` (consola con permisos por módulo y «segunda firma»
para cambios sensibles) y `driver` (único rol de conductor, «credencial emitida por Operaciones»,
sin sub-roles).

**El código no tiene nada de eso.** Lo que existe en `roles` y en el SSO es `admin`, `operations`,
`driver`, `customer`, `company`. El PDF es el diseño de la consola, no el estado del sistema. La
correspondencia obvia es `sysadmin` ≈ `admin` y `opsmgr` ≈ `operations`; los otros cuatro no
tienen ni módulo ni policy que los use hoy.

Propuesta: **no** dar de alta seis roles en el SSO para módulos que no existen. Se mantienen
`treslog:admin`, `treslog:operations`, `treslog:driver`, `treslog:customer`; cada rol del PDF entra
al SSO el día que entra el módulo de la consola que lo necesita (y con su policy). ✅ Confirmado
en la segunda ronda: cuatro roles y ya.

## Materiales que dijo que va a mandar

- Ejemplo de la app de conductores (build/proyecto) «para que lo veas».
- Ejemplo de qué datos se le piden al conductor (licencia, datos personales).
- Diseño de la interfaz de roles dentro de la empresa (portal empresarial).

## Qué destraba esto, en orden

1. **Lote 6** (app de conductores con PKCE): sin bloqueo. Y como la app se está reescribiendo
   desde cero, la referencia es la de MSH; no hay que respetar pantallas viejas.
2. **Alta automática del espejo** en `ResolveDomainUser` (como MSH): sin bloqueo.
3. **API de aprovisionamiento por aplicación en el SSO** (canal `backend`): ahora tiene TRES
   consumidores confirmados —conductores por operaciones, operaciones por el admin, invitados de
   empresa— y es el camino crítico de todo lo demás.
4. **Camino público del gateway** para contacto, cotización anónima y tracking.
5. **Lote 9**: D3 y D6 resueltas, no hay cuentas que migrar. Queda como punto de no retorno con
   respaldo, pero ya no está bloqueado por decisiones.

---

## Segunda ronda (2026-09-14, 20:44–21:29)

| Duda | Respuesta | Decide |
|---|---|---|
| ¿El PDF de roles es diseño o hay que armarlo ya? | Se enfocan en **admin, operaciones, driver y customer**. El PDF era la versión ampliada «a nivel internacional»; el proyecto se fue reduciendo «porque lo quieren rápido». Lo estaba acordando con Josa: «define estos roles y ya». | **Cuatro roles, cerrado.** En el SSO quedan `treslog:admin`, `treslog:operations`, `treslog:driver`, `treslog:customer`. `treslog:company` se retira (una empresa es una cuenta, no un rol). Los roles del PDF entran el día que entre su módulo. |
| ¿Operaciones/admin cotizan a nombre de un cliente? | Volvió sobre lo mismo: «cotizaciones es libre: el cliente pone el correo y zas, le sale el precio por correo». La cotización de registrado existe «pero no afecta». No mencionó ningún flujo de operaciones cotizando. | **D7 queda así**: cotizar es del cliente (anónimo por correo, o registrado). No se construye «cotizar a nombre de» para operaciones/admin: nadie lo pidió. Los `@todo D7` de `QuotePolicy` se resuelven en el lote que la toque: `quotes.create` → `customer` (y el camino público); admin/operaciones **no** crean. Si aparece el pedido, se decide entonces. |
| Datos que se le piden al conductor | `initials`, `name`, `vehicle` (asignado), `hub` (sede asignada), `available`. La documentación (licencia, DNI) la tiene que pedir a Edwins/Carmen; pendiente de ellos. | `DriverProfile` **ya tiene** `initials`, `vehicle`, `hub`, `available` (y `driver_id`, `shift`); `name` es de la persona (SSO). **No falta esquema** para el alta de conductores por operaciones. La documentación (licencia, DNI) entra cuando la definan; es dato local, no del SSO. |

Sigue pendiente de ellos: el proyecto de la app de conductores y la lista de documentos del
conductor (licencia, DNI).

### Lo que esto fija para el diseño del alta de conductor (Lote 6 / API de aprovisionamiento)

`POST /drivers` desde la consola, hecho por operaciones o admin: (1) TR3SLOG le pide al SSO
«creá o encontrá a esta persona por correo y dale `treslog:driver`»; (2) el SSO devuelve el
`sso_user_id` y, si es nueva, le manda el correo de bienvenida con el enlace de ingreso; (3)
TR3SLOG crea la fila espejo y el `DriverProfile` con `initials`, `vehicle`, `hub`, `available`.
La contraseña desaparece del formulario: la persona entra por el SSO.
