<?php

/*
|--------------------------------------------------------------------------
| Integracion con el SSO de MyGlobalHub
|--------------------------------------------------------------------------
|
| TR3SLOG no autentica: la identidad la resuelve el API Gateway del SSO, que
| valida el Bearer token contra Laravel Passport y sustituye las cabeceras
| X-User-* antes de hacer proxy hacia este backend.
|
| Este archivo es una COPIA del de MSH (backend/config/sso.php, rama
| feat/sso-integration). Se mantiene con las mismas claves y los mismos
| comentarios a proposito: es el contrato compartido entre las aplicaciones que
| cuelgan del SSO, y dos copias que divergen sin motivo son dos contratos.
| Las divergencias reales estan marcadas abajo, cada una con su porque.
|
*/

return [

    /*
    | Cabeceras que inyecta el gateway. Se centralizan aca para que un cambio en
    | el contrato no obligue a buscar strings sueltos por todo el codigo.
    |
    | NO SON CONFIGURABLES A PROPOSITO. Son constantes del contrato, fijadas en
    | el mismo par de valores que gateway/templates/default.conf.template, donde
    | NGINX hace proxy_set_header sobre cada una de ellas.
    |
    | Antes se leian de variables de entorno, y eso abria un bypass total: si
    | alguien cambiaba SSO_HEADER_ID aca, este backend pasaba a leer una cabecera
    | que el gateway NO sobreescribe, o sea una que el cliente controla. El
    | atacante mandaba esa cabecera con el id que quisiera y entraba como
    | cualquier usuario. El contrato solo puede cambiar en los DOS extremos a la
    | vez; cambiarlo en uno solo no es una customizacion, es un agujero.
    */
    'headers' => [
        'id'       => 'X-User-Id',
        'clerk_id' => 'X-User-Clerk-Id',
        'email'    => 'X-User-Email',
        'name'     => 'X-User-Name',
        'roles'    => 'X-User-Roles',
        'gateway'  => 'X-Auth-Gateway',

        /*
        | Correlacion, no identidad. El gateway la rellena con la variable
        | nativa $request_id de NGINX y la propaga a la subpeticion al SSO, al
        | backend y a la respuesta del cliente.
        |
        | Que un cliente pueda forjarla NO es un problema de seguridad: no
        | concede nada, solo ensucia una traza. Igual RequestContext valida su
        | forma y marca el origen, asi un id inventado no se confunde con uno
        | del gateway. Va aca, junto al resto del contrato, para que el nombre
        | de la cabecera este definido en un unico lugar.
        */
        'request_id' => 'X-Request-Id',
    ],

    /*
    | Firma que el gateway declara en cada peticion.
    |
    | ATENCION, honestamente: esto NO es un secreto y no autentica nada. Viaja en
    | claro en cada peticion y su valor esta escrito en el nginx.conf del gateway,
    | que vive en un repositorio. Cualquiera que pueda alcanzar este backend
    | directamente puede enviarla. Es solo una comprobacion de coherencia para
    | detectar un despliegue mal cableado (alguien pegandole al backend sin pasar
    | por el gateway por error, no por malicia).
    |
    | EL CONTROL DE SEGURIDAD REAL ES DE RED: este backend no debe publicar
    | puerto al host ni estar en ninguna red compartida con otros stacks. Solo el
    | gateway tiene que poder alcanzarlo. Si eso no se cumple, ninguna firma en
    | una cabecera lo salva.
    |
    | -- DIVERGENCIA 2 respecto de MSH (3-design.md §C.2) --------------------
    | En MSH esto es `env('SSO_GATEWAY_SIGNATURE', 'myglobalhub-gateway')` y el
    | middleware comprueba la firma SOLO si el valor esta lleno, o sea que un
    | `.env` con la variable vacia apaga un MUST de la spec. Un test que verifique
    | el 401 pasa en CI y miente en el ambiente donde la variable quedo vacia:
    | la peor combinacion posible, porque deja la sensacion de estar cubierto.
    |
    | Se aplica el mismo criterio que este archivo ya aplica a los nombres de
    | cabecera: CONSTANTE DEL CONTRATO, NO VARIABLE DE AMBIENTE. Mismo nombre de
    | clave y mismo valor que MSH — la divergencia es de comportamiento local,
    | no de contrato. Y si alguien igual la vaciara, AuthenticateFromGateway
    | falla CERRADO (rechaza todo) en vez de abierto.
    */
    'gateway_signature' => 'myglobalhub-gateway',

    /*
    | El slug de esta aplicacion en el SSO.
    |
    | ES INMUTABLE DEL OTRO LADO: el SSO tira `SlugIsImmutableException` si se
    | intenta cambiar (fue la Decision D1 del plan, y por eso hubo que confirmarla
    | con producto ANTES de dar de alta la aplicacion). No es una preferencia
    | local: es el prefijo con el que el SSO emite CADA rol de TR3SLOG en
    | `X-User-Roles`, o sea el criterio para distinguir un rol nuestro de uno de
    | otro inquilino del ecosistema (`msh:user`, `tienda:vendedor`).
    |
    | Vive aca y no como literal en el codigo porque el filtrado de roles por
    | aplicacion (MeController) es defensa en profundidad, y una defensa que
    | repite un string a mano en otro archivo es la que se desincroniza primero.
    */
    'slug' => 'treslog',

    /*
    | Los roles de TR3SLOG en el SSO, indexados por el nombre corto con el que
    | los conoce el codigo de esta aplicacion.
    |
    | POR QUE VIVEN ACA Y NO SUELTOS EN CADA RUTA:
    | un rol sin el prefijo `treslog:` no da error en ningun lado. El SSO lo
    | filtra (ApplicationScope::alcanza) y ese rol NUNCA VIAJA: la persona lo
    | tiene asignado, la aplicacion no se entera, y el sintoma es "no me deja
    | entrar" sin una sola linea de log. Con nueve controladores repitiendo el
    | string suelto, alcanza equivocarse una vez. Un test estructural recorre
    | esta lista y falla si algun valor no empieza con `treslog:` (Lote 4).
    |
    | -- DIVERGENCIA respecto de 3-design.md §D.1/§D.2 ---------------------------
    | El plan escribe estos roles en castellano (`treslog:cliente`,
    | `treslog:conductor`, `treslog:operaciones`). ESTA MAL, y no es cosmetico:
    | los roles YA cargados en el SSO para el slug `treslog` son
    | `treslog:admin`, `treslog:driver`, `treslog:customer`, `treslog:company` y
    | `treslog:operations`, en ingles, igual que los de la tabla `roles` de esta
    | aplicacion (RoleSeeder). Traducirlos no renombra nada: produce un mapeo que
    | no matchea ningun rol emitido por el SSO, o sea la falla silenciosa que el
    | propio §D.2 dice querer evitar. Se usan los nombres REALES.
    |
    | `treslog:company` existe en el catalogo del SSO pero NO se mapea aca a
    | proposito: el rol `company` tiene cero permisos en RolePermissionSeeder y
    | cero comprobaciones en el codigo (3-design.md §D.1). Mapearlo seria
    | inventar una guarda que hoy no existe.
    */
    'roles' => [
        'admin'      => 'treslog:admin',
        'operations' => 'treslog:operations',
        'driver'     => 'treslog:driver',
        'customer'   => 'treslog:customer',
    ],

];
