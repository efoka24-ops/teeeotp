# teeeotp

Service OTP et SMS mutualise pour les projets TRU GROUP (KoliGo, ERP scolaire,
ERP Pressing…). Les SMS partent d'un **telephone Android equipe d'un forfait
SMS**, sans operateur ni agregateur.

```
KoliGo ─┐                                         ┌──► 📱 telephone passerelle (SIM + forfait)
ERP    ─┼──► API teeeotp (trugroup.cm/teeeotp) ◄──┤        envoie le SMS au client
Pressing┘     genere / verifie les codes          └── interroge toutes les 3 s
              file d'attente des SMS
```

Le projet appelant **ne voit jamais le code** : il recoit un `requestId`, puis
demande « ce code est-il bon ? ». Le code est genere, stocke (HMAC poivre) et
verifie par teeeotp.

| Dossier | Contenu |
|---|---|
| [server/](server/) | API PHP 8.1 + MySQL, deployee dans `public_html/teeeotp` |
| [android/](android/) | Appli passerelle (Kotlin, aucune dependance) |
| [clients/php/](clients/php/) | `TeeeOtpClient.php`, a copier dans chaque projet |

---

## Parcours d'une inscription

1. L'utilisateur saisit son numero dans KoliGo.
2. KoliGo appelle `POST /v1/otp/send` → recoit `requestId`.
3. teeeotp genere le code et met le SMS en file.
4. Le telephone passerelle le reserve (`/v1/gateway/claim`), l'envoie avec sa
   SIM, et rend compte (`/v1/gateway/report`).
5. L'utilisateur tape le code dans KoliGo.
6. KoliGo appelle `POST /v1/otp/verify` → `{ "valid": true }`.

## Integration dans un projet PHP

```php
require 'TeeeOtpClient.php';
$otp = new TeeeOtpClient('https://trugroup.cm/teeeotp', getenv('TEEEOTP_API_KEY'));

// Envoi
try {
    $demande = $otp->sendOtp($telephone, 'signup');
    $_SESSION['otp_request_id'] = $demande['requestId'];
} catch (TeeeOtpException $e) {
    // $e->errorCode : TOO_MANY_REQUESTS, QUOTA_EXCEEDED, INVALID_PHONE, UNREACHABLE…
}

// Verification
$res = $otp->verifyOtp($_SESSION['otp_request_id'], $_POST['code']);
if ($res['valid']) {
    // activer le compte
} elseif ($res['reason'] === 'invalid_code') {
    // "Code incorrect, il vous reste {$res['attemptsLeft']} essai(s)"
} else {
    // expired, blocked, already_used : proposer un nouveau code
}

// Notification simple (commande prete…)
$otp->sendSms($telephone, 'Votre commande KoliGo est en route.');
```

## Ouvrir l'API a d'autres projets

| Page | Pour qui |
|---|---|
| [`/teeeotp/docs/`](https://trugroup.cm/teeeotp/docs/) | Les developpeurs : documentation, exemples, formulaire **Demander un acces**, page de suivi |
| [`/teeeotp/admin/`](https://trugroup.cm/teeeotp/admin/) | L'equipe TRU GROUP : integrations, journal API, SMS, telephones, demandes d'acces |

**Deux sortes de cles** :

- `sk_test_…` : quelques **vrais SMS** (`testSmsLimit`, 5 par defaut), puis **simulation**
  (`"status": "simulated"`, rien ne part). Les reponses portent `testCode` pour les tests automatises.
- `sk_live_…` : production, dans la limite de `dailyQuota`. Les premieres cles (`sk_<prefixe>_…`)
  restent valides et sont de production.

**Libre-service** : le developpeur remplit le formulaire de la documentation ; la demande arrive dans
l'onglet *Demandes d'acces* du back-office. Une fois validee, un projet est cree avec une cle de **test**
seulement, scellee (libsodium) avec le jeton du demandeur : elle ne se lit qu'avec son lien de suivi,
une seule fois. La cle de production reste une decision de l'administrateur (fiche du projet →
*Nouvelle cle de production*).

## API

Tables prefixees `otp_` : la base est partagee avec le site trugroup.cm.

Erreurs au format `{ "error": { "code", "message" } }`, comme apisungku.

### Projets (en-tete `X-Api-Key: sk_…`)

| Methode | Chemin | Corps | Reponse |
|---|---|---|---|
| POST | `/v1/otp/send` | `phone`, `purpose`?, `length`? (4–8) | 202 `requestId`, `expiresIn` |
| POST | `/v1/otp/verify` | `requestId`, `code` | `valid`, `reason`?, `attemptsLeft`? |
| GET | `/v1/otp/{requestId}` | — | etat de la demande et du SMS |
| POST | `/v1/sms` | `phone`, `message` (≤ 480), `ttlSeconds`? | 202 `id` |
| GET | `/v1/sms/{id}` | — | `queued`, `sending`, `sent`, `delivered`, `failed`, `expired` |
| GET | `/v1/me` | — | mode (`test`/`live`), quota, allocation de test, consommation du jour |

Numeros acceptes : mobiles camerounais (`6XXXXXXXX`, `2376XXXXXXXX`, `+2376XXXXXXXX`).

### Telephones (en-tete `Authorization: Bearer dv_…`)

| Methode | Chemin | Role |
|---|---|---|
| POST | `/v1/gateway/claim` | reserve jusqu'a 20 SMS ; sert aussi de signal de vie |
| POST | `/v1/gateway/report` | `{ "reports": [{ "id", "status": "sent\|delivered\|failed", "error"? }] }` |

### Administration (en-tete `X-Admin-Key`)

| Methode | Chemin | Corps |
|---|---|---|
| POST | `/v1/projects` | `name`, `slug`, `senderLabel`?, `dailyQuota`?, `testSmsLimit`? → `apiKey` + `testApiKey` (affichees une fois) |
| POST | `/v1/projects/{slug}/keys` | `env` (`live`/`test`) → nouvelle cle (rotation) |
| POST | `/v1/admin/projects/{slug}/test` | `testSmsLimit`?, `reset`? |
| GET | `/v1/admin/access-requests` | demandes d'acces |
| POST | `/v1/admin/access-requests/{id}/approve` | `slug`?, `senderLabel`?, `testSmsLimit`?, `dailyQuota`?, `note`? |
| POST | `/v1/admin/access-requests/{id}/reject` | `note`? |
| POST | `/v1/admin/migrate` | cree les tables et colonnes ajoutees depuis l'installation |
| POST | `/v1/devices` | `name`, `dailyLimit`? → `deviceToken` (affiche une fois) |
| POST | `/v1/devices/{id}/active` | `active` |
| GET | `/v1/admin/status` | telephones en ligne, SMS du jour, derniers envois |

## Protections

- **Par numero** : 3 codes au plus sur 15 min (`OTP_RATE_*`).
- **Par code** : 5 tentatives, puis la demande est bloquee ; expiration 5 min.
- **Par projet** : `dailyQuota` SMS par jour.
- **Par SIM** : `dailyLimit` du telephone, pour ne pas etre bloque par l'operateur.
- **Global** : `GLOBAL_DAILY_CAP` SMS par jour, tous projets confondus.
- Un OTP non envoye avant son expiration n'est jamais envoye en retard.

---

## Deploiement du serveur (Camoo)

1. Creer une base MySQL et son utilisateur dans cPanel.
2. Copier `server/.env.example` en `/home/<compte>/teeeotp.env` (**hors** de
   `public_html`) et le remplir. Generer les secrets avec
   `php -r "echo bin2hex(random_bytes(32));"`.
3. Televerser le contenu de `server/` dans `public_html/teeeotp/`.
4. Installer le schema : `curl -X POST "https://trugroup.cm/teeeotp/install.php?token=<INSTALL_TOKEN>"`,
   puis **supprimer** `install.php` et `schema.sql` du serveur et vider `INSTALL_TOKEN`.
   Completer ensuite le schema : `curl -X POST https://trugroup.cm/teeeotp/v1/admin/migrate -H "X-Admin-Key: $ADMIN_API_KEY"`.
   **A chaque mise a jour**, televerser `lib/Migrations.php` et lancer cette migration **avant** le reste du code :
   le nouveau code peut lire des colonnes qui n'existent pas encore.
5. Verifier : `curl https://trugroup.cm/teeeotp/v1/health`.
6. Creer un projet :
   ```bash
   curl -X POST https://trugroup.cm/teeeotp/v1/projects \
     -H "X-Admin-Key: $ADMIN_API_KEY" -H "Content-Type: application/json" \
     -d '{"name":"KoliGo","slug":"koligo","senderLabel":"KoliGo","dailyQuota":500}'
   ```
7. Enroler le telephone :
   ```bash
   curl -X POST https://trugroup.cm/teeeotp/v1/devices \
     -H "X-Admin-Key: $ADMIN_API_KEY" -H "Content-Type: application/json" \
     -d '{"name":"Tecno SIM MTN","dailyLimit":300}'
   ```

## Le telephone passerelle

### Compiler l'APK

```bash
cd android
./gradlew assembleDebug        # app/build/outputs/apk/debug/app-debug.apk
```

JDK 17 ou 21 et le SDK Android (`local.properties` : `sdk.dir=…`). Pour une
version signee : creer `keystore.properties` (hors depot) puis `./gradlew assembleRelease`.

### Preparer le telephone

1. Android 8 ou plus, **SIM dediee** avec forfait SMS, branche en permanence, Wi-Fi + donnees.
2. Installer l'APK (sources inconnues autorisees). L'appli n'est pas
   publiable sur le Play Store : Google y reserve `SEND_SMS` aux applis SMS par defaut.
3. Saisir l'URL `https://trugroup.cm/teeeotp` et le jeton `dv_…`, puis **Demarrer**.
4. Toucher **Exempter de l'optimisation de batterie**. Sur Tecno, Infinix et
   Xiaomi, autoriser aussi le demarrage automatique et verrouiller l'appli dans
   les applis recentes.
5. Lever la limite anti-spam d'Android (une fois, telephone en USB) :
   ```bash
   adb shell settings put global sms_outgoing_check_max_count 5000
   adb shell settings put global sms_outgoing_check_interval_ms 60000
   ```
6. Sur un telephone double SIM, choisir la SIM du forfait comme **SIM par defaut pour les SMS**.

### Limites a connaitre

- **L'expediteur affiche est le numero de la SIM.** Aucune appli ne peut le
  masquer ou le remplacer : c'est l'operateur qui l'inscrit. Afficher « KOLIGO »
  exige un sender ID alphanumerique, donc un acces SMPP ou un agregateur — le
  serveur est concu pour qu'on ajoute ce canal sans rien changer cote projets.
- Volume : quelques centaines de SMS par jour et par SIM. Au-dela, l'operateur
  peut suspendre la ligne. Ajouter des telephones pour monter en charge : ils se
  partagent la file automatiquement.
