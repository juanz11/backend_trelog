# Dudas para avanzar con el login y el registro de TR3SLOG en el SSO

Contexto en tres líneas: el login y el registro de TR3SLOG pasan al SSO de MyGlobalHub. La web
ya entra así (rama `sso/lote8-web-dominio`). Lo que falta es la app de conductores y decidir cómo
se crean las cuentas que necesitan un rol distinto al de cliente. Para eso necesitamos estas
respuestas; cada una destraba algo concreto.

**1. App de conductores: ¿hay alguien usándola hoy?**
¿Existe una build instalada en teléfonos de conductores reales? Si sí, ¿contra qué URL apunta?
(`https://api.tr3log.com/api` hoy no resuelve.) Esto decide si podemos reemplazar el login de la
app sin dejar a nadie afuera, o si hay que mantener el camino viejo hasta que se actualicen.

**2. ¿Existe una app de clientes?**
El backend tiene rutas `/app/*` (registro, login, cotizaciones) marcadas como «customer facing»,
pero no vimos ningún repo que las use. ¿Hay una app? ¿Dónde está? Si no existe, esas rutas
mueren cuando retiremos el login viejo, sin reemplazo.

**3. ¿Un conductor puede registrarse solo?**
Hoy `POST /driver/register` está abierto: cualquiera con la app se convierte en conductor. Con el
SSO, el rol de conductor no puede ser el que se da por defecto al registrarse (abriría la nómina
y las rutas a cualquiera). Nuestra propuesta: el registro público siempre crea un **cliente**, y
los conductores los da de alta un administrador. ¿Están de acuerdo, o hay un flujo de alta de
conductores que no conocemos?

**4. Administradores y operaciones: ¿quién los crea?**
Hoy solo existen por seeder; no hay pantalla. Propuesta: el administrador del SSO les asigna el
rol desde el panel del SSO. ¿Alcanza, o necesitan crearlos desde la propia consola de TR3SLOG?
(Si es lo segundo, entra en el punto 5.)

**5. Invitaciones: ¿se usan, y qué es `company`?**
Existe un flujo donde un admin invita por correo con nombre y empresa, y el invitado acepta
creando su contraseña. Con el SSO, TR3SLOG tendría que pedirle al SSO que cree a esa persona
con su rol, y eso requiere un API que hoy no existe. Antes de construirlo: ¿ese flujo está en uso?
Y `company`: aparece como rol en la base y como dato en la invitación. ¿Es un rol (una empresa
con varios usuarios) o un campo del cliente?

**6. ¿Hay alguna base con usuarios reales?**
Aunque sea de pruebas o staging. Si la hay, hay que decidir cómo se vinculan esas cuentas con
las del SSO (proponemos: por correo verificado). Si no la hay, no hay migración y es más simple.

**7. Rutas públicas: contacto, cotizar sin cuenta, seguimiento por código.**
Hoy no piden login. En producción el backend solo se alcanza a través del gateway, que exige
identidad, así que esas tres necesitan un camino propio. ¿Se quedan públicas? Si sí, lo
resolvemos; si alguna en realidad debería pedir cuenta, mejor saberlo ahora.

**8. Cotizaciones desde la consola.**
Hoy operaciones puede crear cotizaciones sin que ningún permiso lo diga explícitamente. ¿Quién
debería poder: solo admin, admin y operaciones, también clientes?

**9. El mock de API en `.tmp/`.**
Vimos `.tmp/mock-api.mjs` en la web. Si lo usan para desarrollar sin backend, ya no hace falta:
`npm run dev` en la rama nueva corre la web contra el API real del VPS, sin levantar nada más.
Está en `SETUP_LOCAL.md`.

Con 1, 3 y 4 respondidas se puede cerrar la app de conductores. Con 5 se decide si construimos
el API de alta de usuarios desde la aplicación. El resto ordena lo que ya está andando.
