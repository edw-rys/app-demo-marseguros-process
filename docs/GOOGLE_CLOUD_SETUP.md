# Configurar Google Cloud para leer Gmail

Guía paso a paso para dejar el sistema leyendo buzones reales. Está pensada para
sacarla una vez, con una cuenta propia, y desde ahí no volver a tocarla salvo que
cambien los buzones.

## ¿Cuál de las tres rutas es la tuya?

La forma de conectarse a Gmail depende de una sola cosa: **si tu cuenta pertenece
a un dominio de Google Workspace o es un `@gmail.com` personal**. No es
cuestión de preferencia, es que la delegación de dominio **no existe** fuera de
Workspace.

| Tu situación | Ruta | Credencial |
|---|---|---|
| El correo sale de un dominio de empresa (osoyadmin de él) | **[Ruta A](#ruta-a--google-workspace-con-service-account)** | Service account + DWD |
| Es tu `@gmail.com` personal | **[Ruta B](#ruta-b--cuenta-personal-con-oauth)** | OAuth 2.0, solo lectura |
| Ninguna de las dos: quieres ver el pipeline funcionando ya | **[Ruta C](#ruta-c--probar-sin-google)** | Ninguna, correos `.eml` |

> **¿Por qué no hay una tercera forma "fácil"?** Una service account sin
> delegación solo puede leer buzones que ella misma posea, y una cuenta nueva no
> tiene ninguno. El puente hacia buzones ajenos es la *delegación de todo el
> dominio*, que se configura en `admin.google.com` y exige ser super-admin de un
> Workspace. Si lo que tenés es un `@gmail.com`, no hay panel de administración
> al que entrar. Por eso la Ruta B usa OAuth: no necesita nada más que la cuenta
> misma.

Las tres rutas llegan al mismo resultado: los correos entran al panel y se
procesan. Cambia quién autoriza y con qué permisos.

---

## Índice

**Ruta A — Google Workspace con service account** *(lo que hay desde el principio)*

1. [Qué vamos a crear](#qué-vamos-a-crear)
2. [Antes de empezar](#antes-de-empezar)
3. [Paso 1 — Crear el proyecto](#paso-1--crear-el-proyecto)
4. [Paso 2 — Habilitar la Gmail API](#paso-2--habilitar-la-gmail-api)
5. [Paso 3 — Crear la service account](#paso-3--crear-la-service-account)
6. [Paso 4 — Instalar el JSON en el proyecto](#paso-4--instalar-el-json-en-el-proyecto)
7. [Paso 5 — Domain-wide delegation](#paso-5--domain-wide-delegation-)
8. [Paso 6 — Configurar el `.env`](#paso-6--configurar-el-env)
9. [Paso 7 — Verificar](#paso-7--verificar)

**Ruta B — Cuenta personal con OAuth**

10. [Por qué OAuth](#ruta-b--cuenta-personal-con-oauth)
11. [B.1 — Crear el cliente OAuth](#b1--crear-el-cliente-oauth)
12. [B.2 — Instalar el JSON y configurar el `.env`](#b2--instalar-el-json-y-configurar-el-env)
13. [B.3 — Autorizar la cuenta](#b3--autorizar-la-cuenta)
14. [B.4 — Verificar](#b4--verificar)

**Resto**

15. [Ruta C — Probar sin Google](#ruta-c--probar-sin-google)
16. [Errores frecuentes](#errores-frecuentes)
17. [Cómo se renueva](#cómo-se-renueva)
18. [Referencia rápida](#referencia-rápida)

---

# Ruta A — Google Workspace con service account

Lo que hay desde el principio. **Requiere ser super-admin de un dominio de Google
Workspace.** Si tu correo es un `@gmail.com` personal, andá directo a la
[Ruta B](#ruta-b--cuenta-personal-con-oauth): esta no te va a funcionar.

## Qué vamos a crear

| Pieza | Qué es | Dónde vive |
|---|---|---|
| **Proyecto** | contenedor de todo lo anterior | [console.cloud.google.com](https://console.cloud.google.com) |
| **Service Account** | robot sin contraseña que llama a la API | el proyecto |
| **Clave JSON** | la credencial en sí | `admin/storage/private/service-account.json` |
| **Delegación de todo el dominio (DWD)** | permiso para actuar *como* un usuario del dominio | Consola de administración de Workspace |

Las tres primeras salen de la consola de Google Cloud. **La cuarta no**: vive en
la consola de administración de Workspace y requiere ser super-admin. Está
marcada abajo como [paso bloqueante](#aviso--el-paso-bloqueante).

---

## Antes de empezar

Tres cosas que conviene tener claras, porque son las que más confunden:

**1. Por qué una service account y no una cuenta normal.**
Una service account es una identidad sin usuario ni contraseña. Le pega directo
a la API. El problema es que por diseño solo puede leer buzones que *ella misma*
posea, y una cuenta nueva no tiene buzones. La **delegación de dominio** es el
puente que le permite actuar como cualquier usuario del dominio.

**2. Por qué siempre el mismo `Subject`.**
Con DHD, la service account **no puede suplantar a quien quiera**: actúa siempre
como el usuario que se indique en `GOOGLE_DELEGATED_USER`. Todos los buzones de
`GOOGLE_MAILBOXES` se leen *como esa persona*, por eso el buzón no se pasa a la
API en ningún punto (mira `GmailClient::serviceFor()`, que lo recibe y lo ignora
a propósito).

> Consecuencia práctica: el usuario delegado tiene que tener **permiso de
> lectura sobre todos los buzones** que quieras vigilar. Si le falta uno, ese
> buzón falla con un error de permisos —no con un error de credenciales— y es
> fácil confundirlo.

**3. Cuántos buzones de verdad.**
El requerimiento pide sostener ≥ 5 buzones sin degradar. `users.history.list`
(cursors de `historyId`) es lo que lo hace posible: no se relee la bandeja
entera, solo lo nuevo. Son 200 correos/día sin problema.

---

## Paso 1 — Crear el proyecto

1. Abre [console.cloud.google.com](https://console.cloud.google.com) e inicia
   sesión con la cuenta que administrará esto.
2. Arriba, junto al nombre del proyecto, hay un selector. Elige **New Project**.
3. Ponle un nombre reconocible, p. ej. `gmail-docs-validator`.
4. **Organization**: déjalo vacío.
5. **Location**: déjalo en *No organization*.
6. **Create Project**.

> **Facturación.** La Gmail API no cobra, pero algunas APIs de Google sí exigen una
> cuenta de facturación activa. Si al habilitar la API te pide billing, activa la
> **Free Tier**; para este proyecto no se te debería cobrar nada. Si no tienes
> tarjeta registrada y te bloquea, considera la [ruta sin Google](#probar-sin-google-workspace).

---

## Paso 2 — Habilitar la Gmail API

1. Con el proyecto seleccionado, ve a **APIs & Services → Library**
   (o abre directamente
   [console.cloud.google.com/apis/library/gmail.googleapis.com](https://console.cloud.google.com/apis/library/gmail.googleapis.com)).
2. Haz clic en **Enable**.

Tarda unos segundos y la página vira a "API enabled".

---

## Paso 3 — Crear la service account

1. **APIs & Services → Credentials** (siempre sobre el proyecto correcto — la
   consola cambia de proyecto según dónde hagas clic, es la trampa más común).
2. **Create credentials → Service account**.
3. **Service account name**: `gmail-docs-validator`.
4. **Service account ID**: se autogenera, del estilo
   `gmail-docs-validator@TU_PROYECTO.iam.gserviceaccount.com`.
5. Omite los pasos de "Grant access to this project" (puedes darle *Editor* si
   quieres, pero no hace falta para leer correo).
6. **Create and continue** → **Done**.

### 3.1 Generar la clave JSON

1. En la lista de credenciales, haz clic en el nombre de la service account.
2. Pestaña **Keys → Add key → Create new key**.
3. Tipo: **JSON** (no uses *Cloud Storage* ni *Spreadsheet*).
4. **Create**.

Se descarga un archivo con un nombre parecido a
`gmail-docs-validator-9f2a1b3c-4d5e.json`. **Guárdalo**: en el siguiente paso
se copia al proyecto y esa descarga es la única copia que vas a tener.

### 3.2 Anotar el Client ID

En la misma pestaña **Details**, al final de la lista, aparece **Client ID** con
un valor así:

```
123456789012345678901.apps.googleusercontent.com
```

**Cópialo.** Lo necesitas para la DWD del paso 5.

---

## Paso 4 — Instalar el JSON en el proyecto

```bash
# desde backend/gmail-docs-validator/admin
cp ~/Downloads/gmail-docs-validator-9f2a1b3c-4d5e.json \
   storage/private/service-account.json

# solo tú debes poder leerlo
chmod 600 storage/private/service-account.json
```

La ruta exacta es `admin/storage/private/service-account.json`, y está en el
`.gitignore` (junto con `**/service-account.json`), así que **no se commitea por
accidente**.

> **¿Por qué el JSON en disco y no en el `.env`?**
> Un `.env` se pega en tickets, se sube a CI, acaba en logs. Este archivo trae la
> `private_key` completa: con ella, quien la tenga puede firmar tokens como la
> service account. Solo se guarda **la ruta** en `.env`
> (`GOOGLE_SERVICE_ACCOUNT_PATH`), nunca la credencial.
>
> Si el JSON llegara a un repositorio, **revócalo**: borra la clave en
> *Credentials → Keys* y crea otra. Borrar el archivo del disco no basta.

Con Docker el archivo no está en la imagen (el `.dockerignore` lo excluye): se
monta en el volumen al arrancar.

---

## Paso 5 — Domain-wide delegation

> ### ⚠️ Aviso: el paso bloqueante
>
> Esta pantalla **no está en la consola de Google Cloud**. Está en la consola de
> administración de tu Workspace, y **solo un super-admin** puede abrirla.
>
> Si no eres super-admin, tienes que pedirle a alguien que lo sea. Sin esto, todo
> lo anterior queda configurado pero inoperante: el error que verás es un 401
> con un texto que habla de `unauthorized_client`, que no dice nada de permisos
> faltantes.
>
> URL: [admin.google.com](https://admin.google.com) → **Security → Access and
> data control → API controls → Domain-wide delegation**.

1. **Manage Domain Wide Delegation**.
2. **Add new**.
3. En **Client ID**, pega el valor del punto 3.2 (el que termina en
   `apps.googleusercontent.com`).
4. En **OAuth scopes**, agrega **exactamente** esta línea:

   ```
   https://www.googleapis.com/auth/gmail.modify
   ```

5. **Authorize**.

### Por qué `gmail.modify` y no `gmail.readonly`

Porque el sistema **responde** los correos, y la respuesta va en el hilo original
(`GmailReplySender` reutiliza `threadId` + `In-Reply-To`). `readonly` permitiría
leer pero no enviar, y el paso `reply` del pipeline fallaría.

> `gmail.modify` es amplio: además de leer y responder, permite borrar mensajes.
> Es el scope que pide el producto. Si en algún momento quieres recortar riesgo,
> `https://www.googleapis.com/auth/gmail.compose` sirve para responder sin poder
> borrar — pero no lo implementé.

### Varios buzones con una sola credencial

No se registra la DWD una vez por buzón. La credencial es la misma para todos; lo
que cambia es la lista en `GOOGLE_MAILBOXES` (paso 6). El sistema itera sobre
ella.

---

## Paso 6 — Configurar el `.env`

En `admin/.env`:

```dotenv
# ── Google / Gmail ─────────────────────────────────────────────────────────
# Solo la RUTA del JSON. La credencial no va aquí (docs/GOOGLE_CLOUD_SETUP.md).
GOOGLE_SERVICE_ACCOUNT_PATH=storage/private/service-account.json

# El usuario de dominio al que se delega. TODOS los buzones se leen como él.
GOOGLE_DELEGATED_USER=watcher@tu-dominio.com

# Buzones a vigilar, coma-separado, sin espacios.
GOOGLE_MAILBOXES=docs@tu-dominio.com,seguros@tu-dominio.com,tramites@tu-dominio.com

# Filtro de asunto: match PARCIAL, sin distinguir mayúsculas.
# Basta con que el asunto contenga alguno. Vacío = aceptar todo (no recomendado).
GMAIL_SUBJECT_FILTER=Trámite:,Solicitud,Renovación

# Remitente de las respuestas. Vacío = el propio buzón vigilado.
GMAIL_REPLY_AS=docs@tu-dominio.com

# false = las respuestas se generan y se guardan, pero NO se envían.
# Déjalo en false hasta que hayas visto el resultado en el admin.
GMAIL_SEND_ENABLED=false
```

Después de tocar el `.env`:

```bash
php artisan optimize:clear
```

---

## Paso 7 — Verificar

### 7.1 Antes de gastar una llamada, una comprobación local

```bash
php artisan tinker --execute='
use App\Services\Gmail\GmailClient;
var_dump(GmailClient::isConfigured());
'
```

`true` significa que están los tres cosas: el JSON se lee, y hay
`GOOGLE_DELEGATED_USER` y `GOOGLE_MAILBOXES`. **No comprueba que el JSON sea
válido** — solo que el archivo existe. Si sale `false`, el propio comando
`gmail:sync` te dice cuál de las tres falta.

### 7.2 El primer sync

```bash
php artisan gmail:sync --dry-run
```

`--dry-run` detecta los correos pero **no despacha el pipeline**: es la forma
segura de confirmar que las credenciales funcionan.

Esperado:

```
  Correos vistos       12
  Pasaron el filtro     3

  Dry-run: no se despachó ningún job.
```

Si ves `0 correos vistos`, casi siempre es que el `historyId` guardado está
adelantado o el filtro de asunto no calza. Se diagnostica mirando el
`WatchState` en el admin.

### 7.3 El sync de verdad

```bash
php artisan gmail:sync
```

Y después, en `http://localhost:8000/admin`, en **Jobs** debe aparecer el correo
con sus etapas. Si el pipeline corrió, `current_stage` llega hasta `done`.

### 7.4 Recién entonces, enviar respuestas

```bash
GMAIL_SEND_ENABLED=true php artisan gmail:sync
```

Hazlo **contra un buzón de pruebas**, no contra clientes reales. La respuesta va
al remitente real del correo.

---

# Ruta B — Cuenta personal con OAuth

Para cuando lo que tenés es tu propia cuenta de Gmail, sin Workspace detrás.

## Por qué OAuth

La [Ruta A](#ruta-a--google-workspace-con-service-account) se apoya en la
**delegación de dominio**, que se configura en `admin.google.com` y exige ser
super-admin. Una cuenta `@gmail.com` no tiene dominio, así que no tiene panel de
administración: no hay a dónde ir.

OAuth no necesita nada de eso. Es el mismo consentimiento que hacés cuando una
app te pide "ver tus correos": vos lo aprobás una vez, y Google le da a la app un
token que vale por esa cuenta y nada más.

Dos diferencias con la Ruta A que conviene tener presentes:

- **Es una sola cuenta, no varios buzones.** No hay lista: se lee el buzón que
  autorizaste. Si querés vigilar el de la empresa *y* el tuyo, esto no alcanza.
- **El scope es de solo lectura** (`gmail.readonly`), por diseño. El sistema no
  puede enviar respuestas. Se siguen generando y guardando en el admin como
  `dry_run`, que para la demo es exactamente lo que se quiere: no sale ningún
  correo real.

> ¿Querés leer **y** enviar desde tu cuenta? Ampliá
> `gmail_docs.google.oauth.scopes` en `config/gmail_docs.php` a
> `gmail.modify`, poné `GMAIL_SEND_ENABLED=true`, y reautorizá. Google te va a
> pedir consentimiento otra vez con los permisos nuevos.

## B.1 — Crear el cliente OAuth

1. En [console.cloud.google.com](https://console.cloud.google.com), creá un
   proyecto (o usá el mismo de la Ruta A — da igual, es lo mismo) y habilitá la
   **Gmail API** desde *APIs & Services → Library*.

2. En *OAuth consent screen*:

   - **User type**: `External`.
   - App name: lo que quieras, se lo ve el usuario al consenting.
   - Scopes: agregá `.../auth/gmail.readonly`.
   - En *Test users* agregá tu propia dirección — mientras la app esté en
     **Testing**, Google solo deja autorizar a las cuentas de esa lista.

3. En *Credentials → Create credentials → OAuth client ID*:

   - Application type: **Web application**. Es el único tipo que trae client
     secret, y lo necesitamos para renovar el token sin que estés presente.
   - Authorized redirect URIs: acá va lo importante 👇

     ```
     http://localhost:8000/gmail/oauth/callback
     ```

     Tiene que coincidir **carácter por carácter** con la que use el sistema:
     mismo esquema, mismo host, mismo puerto, sin barra al final. Si no,
     Google responde `redirect_uri_mismatch` y no hay forma de autorizar.

4. Descargá el JSON (el ícono de descarga al lado del client ID) y guardalo como
   `admin/storage/private/oauth-client.json`.

## B.2 — Instalar el JSON y configurar el `.env`

```bash
# desde backend/gmail-docs-validator/admin
mv ~/Descargas/client_secret_*.json storage/private/oauth-client.json

mkdir -p storage/private
chmod 700 storage/private
chmod 600 storage/private/oauth-client.json   # lleva el client secret adentro
```

En `admin/.env`:

```ini
GMAIL_AUTH_MODE=oauth

# Las rutas. El secret y el token NO van en el .env: son archivos en disco.
GOOGLE_OAUTH_CLIENT_PATH=storage/private/oauth-client.json
GOOGLE_OAUTH_TOKEN_PATH=storage/private/gmail-token.json

# Déjalo vacío y se arma solo con APP_URL + /gmail/oauth/callback.
# Ponelo solo si necesitás que difiera de APP_URL.
GOOGLE_OAUTH_REDIRECT_URI=
```

Y después de tocar el `.env`, siempre:

```bash
php artisan optimize:clear
```

> `GMAIL_AUTH_MODE` es explícito a propósito y viene en `service_account` por
> defecto. No se autodetecta "si existe el JSON": un `service-account.json` viejo
> que quedara en `storage/private/` cambiaría el comportamiento del despliegue
> sin avisar.

## B.3 — Autorizar la cuenta

```bash
php artisan gmail:auth
```

Imprime el estado (si está o no autorizado, desde cuándo) y **la URL de
autorización**. Abrila en el navegador, aceptá el consentimiento, y volvés al
admin. Google vuelve a la URL de callback, el sistema intercambia el código por un
token y lo deja en `storage/private/gmail-token.json`.

Para hacerlo desde el panel en vez de por CLI: entrá al admin → **Watchers →
Conexión con Gmail** → **Autorizar con Google**.

Después:

```bash
php artisan gmail:auth    # ahora dice "Buzón: tu@gmail.com"
ls -l storage/private/gmail-token.json   # -rw------- (0600)
```

> ⚠️ **El refresh token caduca a los ~7 días** si la app quedó en modo
> *Testing*. Es el comportamiento normal de Google, no un bug. Cuando pase, el
> sync va a fallar con `invalid_grant` y hay que volver a autorizar (o pasar la
> app a *In production* en la consola de Google Cloud, que quita el límite).
> `php artisan gmail:auth` avisa a partir del día 6, y la página de Conexión con
> Gmail muestra la antigüedad.

Para desconectar: `php artisan gmail:auth:revoke` borra el token local, y con
`--remote` lo revoca también en Google.

## B.4 — Verificar

```bash
php artisan gmail:sync --dry-run   # qué encontraría, sin escribir nada
php artisan gmail:sync
```

Como en la Ruta A: los correos que entren siguen el mismo pipeline y se ven en el
admin. Lo que **no** va a funcionar es `GMAIL_SEND_ENABLED=true`: con el scope
`gmail.readonly` no se puede enviar, y el intento falla con un mensaje en
`email_responses` explicándolo.

---

# Ruta C — Probar sin Google

Si no querés tocar Google todavía —o si el super-admin aún no aprobó nada—, el
pipeline se demuestra entero sin credenciales. Los workers, el OCR, las reglas y
el admin son los mismos; lo único que se salta es la entrada.

## Generar los correos de ejemplo

```bash
php artisan demo:make-eml
```

Crea cuatro `.eml` en `storage/app/demo-inbox`, cada uno cubriendo un caso que
hay que poder demostrar:

| Archivo | Qué comprueba |
|---|---|
| `01-expediente-completo` | el camino feliz: se valida todo |
| `02-falta-documento` | **CU-02** — Fase 1 falla y responde qué falta |
| `03-ejecutable-renombrado` | **CU-04** — un `.exe` llamado `factura.pdf` |
| `04-pdf-escaneado` | **RF-04** — PDF sin capa de texto, obliga a OCR |

### Procesarlos

```bash
# Con Stack A (determinista, no necesita ninguna key)
php artisan demo:ingest --stack=a

# O con Stack B (semántico, necesita OPENROUTER_API_KEY en stack-b/.env)
php artisan demo:ingest --stack=b

# Para forzar el stack en todos los correos a la vez
php artisan demo:ingest --stack=a
```

Los correos entran por el mismo camino que los reales: crean filas en
`processed_emails` y `attachments`, y recorren las mismas 11 etapas. **La única
diferencia es de dónde sale el `.eml`.**

> Con Docker, los `.eml` van al volumen compartido: `docker compose exec admin
> php artisan demo:make-eml` y luego `docker compose exec admin php artisan
> demo:ingest --stack=a`.

### Reconstruir la base desde cero

```bash
php artisan migrate:fresh --seed
```

---

## Errores frecuentes

### `redirect_uri_mismatch` (400)

Solo [Ruta B](#ruta-b--cuenta-personal-con-oauth). Google compara la URI de
redirección **carácter por carácter** y no perdona ni el puerto ni una barra al
final. Revisá:

1. Que la registrada en Google Cloud sea exactamente
   `http://localhost:8000/gmail/oauth/callback`.
2. Que `APP_URL` en `admin/.env` tenga el mismo puerto que el que estás usando
   para abrir el admin (si no, la que se manda es la otra).
3. Que no quede `GOOGLE_OAUTH_REDIRECT_URI=` con un valor sutilmente distinto.

Después de cambiarlo: `php artisan optimize:clear`.

### Google dice `Access blocked` / la app no pide los permisos

Estás en modo **Testing** y tu cuenta no está en *Test users*. Mientras la app
esté en Testing, Google solo deja autorizar a las cuentas de esa lista —agregá la
tuya en *OAuth consent screen → Test users*—, o pasá la app a **In production**.

### La respuesta llega sin `refresh_token`

Google solo entrega el `refresh_token` la **primera** vez que se autoriza una
cuenta. Si ya habías autorizado antes y volvés a hacerlo sin forzarlo, puede no
aparecer. El sistema lo detecta y falla con un mensaje explícito en vez de
guardar un token inútil. Para obtenerlo: `php artisan gmail:auth:revoke` y
autorizar de nuevo.

### `invalid_grant` a los pocos días

**Normal en modo Testing.** Google caduca los refresh tokens de las apps en
Testing a los ~7 días. No es un error de configuración. Dos salidas:

- Volver a autorizar (lo más simple para una demo).
- Pasar la app a **In production** en *OAuth consent screen*, que saca el límite.

`php artisan gmail:auth` avisa a partir del día 6.

### `The user does not have sufficient scopes` al enviar (Ruta B)

Esperado. El scope es `gmail.readonly` por diseño, así que no se puede enviar.
Dejá `GMAIL_SEND_ENABLED=false`: las respuestas se generan y quedan visibles en el
admin como `dry_run`. Si de verdad necesitás enviar, ampliá
`gmail_docs.google.oauth.scopes` a `gmail.modify` y reautorizá.

### `unauthorized_client` / `Access blocked by the DWD` (401)

La DWD no está configurada, o el scope está mal escrito. Es el paso 5.

Verifica, en este orden:
1. ¿El Client ID que registraste es el de **esta** service account?
2. ¿El scope está **exactamente** `https://www.googleapis.com/auth/gmail.modify`?
3. ¿El proceso tardó unos minutos en propagarse? Google tarda hasta ~10 min
   después de autorizar.

### `invalid_grant` / `unauthorized_client` con las credenciales bien puestas

Casi siempre es que el JSON y la DWD **no son del mismo proyecto**. Se resuelve
creando una clave nueva en la misma service account que autorizaste.

### `File not found: service-account.json`

La ruta de `GOOGLE_SERVICE_ACCOUNT_PATH` es **relativa a la raíz de Laravel**
(`admin/`), no al directorio desde el que corres el comando. Si la pones como
`storage/private/...`, funciona desde cualquier cwd; si la pones como `./...`,
depende de dónde estés parado. Con Docker la ruta también puede ser absoluta
(`/var/www/html/storage/private/...`).

### `The user does not have sufficient scopes` / 403

Credenciales correctas pero **el usuario delegado no tiene acceso a ese buzón**.
Recuerda: con DHD se lee *como* `GOOGLE_DELEGATED_USER`, no como la service
account. Si lees un buzón compartido y el usuario no es miembro, falla con 403.

### `invalid_grant: Invalid grant: account not found`

`GOOGLE_DELEGATED_USER` no existe o no está en el dominio. Ojo con el typo más
común: escribir el **nombre** de la cuenta en vez del **correo**.

### El sync dice `0 correos vistos` pero sí hay correos nuevos

Dos causas habituales:

- **El filtro de asunto no calza.** `GMAIL_SUBJECT_FILTER` exige coincidencia
  *parcial*: si dejaste `Trámite:` y el correo dice `TRÁMITE - Factura`, calza;
  si dice `Solicitud de póliza`, no. Vaciarlo acepta todo.
- **El `historyId` quedó adelantado.** Se guarda en `watch_states`. Para forzarlo
  desde cero, bórralo en el admin (la fila del buzón) o trunca la tabla.

### Las respuestas no se envían

`GMAIL_SEND_ENABLED` está en `false`. Es lo correcto en demo: las respuestas se
generan y quedan en `email_responses` con status `dry_run`, visibles en el admin,
pero no sale ningún correo.

---

## Cómo se renueva

Si más adelante se activa el modo Pub/Sub (`gmail:watch:init`):

> En la [Ruta B](#ruta-b--cuenta-personal-con-oauth) esto no aplica: `users.watch`
> necesita un scope más amplio que `gmail.readonly`, así que el comando avisa y no
> hace nada. Con una cuenta propia el modo es **polling**, que no caduca.

```bash
php artisan gmail:watch:init --topic=projects/TU_PROYECTO/topics/TU_TOPIC
```

**Los watches de Gmail caducan a los 7 días.** Hay que correr ese comando cada
semana; no hay renovación automática. El comando te avisa al terminar.

El modo por defecto del proyecto es **polling** (`gmail:sync` /
`gmail:poll`), que no caduca y no necesita Pub/Sub. Para una demo, quédate con
polling.

---

## Referencia rápida

```bash
# ¿Está todo configurado?
php artisan tinker --execute='var_dump(App\Services\Gmail\GmailClient::isConfigured());'

# Un poll de todos los buzones
php artisan gmail:sync
php artisan gmail:sync --dry-run          # sin despachar el pipeline

# Dejarlo corriendo
php artisan gmail:poll
php artisan gmail:poll --once
php artisan gmail:poll --sleep=30

# Sin credenciales de Google
php artisan demo:make-eml
php artisan demo:ingest --stack=a

# Reprocesar un job concreto
php artisan pipeline:reprocess {uuid}
```

**Solo en la [Ruta B](#ruta-b--cuenta-personal-con-oauth)** (cuenta propia con
OAuth):

```bash
php artisan gmail:auth            # estado de la autorización + la URL para autorizar
php artisan gmail:auth:revoke     # desconectar la cuenta
php artisan gmail:auth:revoke --remote   # además revocando el token en Google
```