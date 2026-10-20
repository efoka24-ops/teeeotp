# Changelog

## v1.0.0 — 2026-10-20

Premiere version de teeeotp : service OTP et SMS mutualise pour les projets
TRU GROUP, dont les SMS partent d'un telephone Android equipe d'un forfait.

En production sur <https://trugroup.cm/teeeotp> (base `trugro9159_tru`,
tables prefixees `otp_`).

### API (PHP 8.1 + MySQL, hebergement Camoo)

- **OTP** : `POST /v1/otp/send`, `POST /v1/otp/verify`, `GET /v1/otp/{id}`.
  Le code est genere et verifie par teeeotp, stocke en HMAC poivre ; le
  projet ne voit qu'un `requestId`.
- **SMS libres** : `POST /v1/sms`, `GET /v1/sms/{id}`.
- **Cles de projet** : `sk_live_…` (production) et `sk_test_…` (quelques
  vrais SMS, 5 par defaut, puis simulation avec `testCode` dans la
  reponse). Les premieres cles `sk_<prefixe>_…` restent valides en
  production.
- **Protections** : 3 codes par numero sur 15 min, 5 essais et 5 min par
  code, quota par projet, limite par SIM, plafond global quotidien ; un
  code n'est jamais envoye apres expiration.
- **Telephones passerelles** : `POST /v1/gateway/claim` et `/report`,
  reservation atomique, remise en file des envois abandonnes.
- **Journal des appels** (`otp_api_logs`) : appelant, route, statut, code
  d'erreur, duree ; sans secret ni contenu ; purge a 30 jours.
- **Migrations** : `POST /v1/admin/migrate`, idempotent.

### Back-office — `/teeeotp/admin/`

Vue d'ensemble, integrations (cles, routes appelees, erreurs expliquees,
taux de verification), journal API, SMS, telephones, demandes d'acces,
SMS de test. Connexion par lien personnel `#k=<ADMIN_API_KEY>`.

### Documentation publique — `/teeeotp/docs/`

Demarrage rapide, reference, erreurs, limites, bonnes pratiques, exemples
PHP et Node, client PHP a telecharger, formulaire de demande d'acces et
page de suivi. Une demande validee ouvre un projet avec une cle de test,
scellee (libsodium) avec le jeton du demandeur et lisible une seule fois.

### Appli passerelle Android — 1.0.0

Kotlin, sans dependance. Service au premier plan qui reserve, envoie et
rend compte (envoye, livre, echec) ; redemarrage au boot ; reprise apres
coupure reseau. APK : `android/app/build/outputs/apk/debug/app-debug.apk`.

### Client PHP

`clients/php/TeeeOtpClient.php` : `sendOtp`, `verifyOtp`, `otpStatus`,
`sendSms`, `smsStatus`, `me`.

### Integrations

- **KoliGo** : branche (cle de production), envois livres.
- **Scholaris ERP** : projet cree, integration cote application a faire.

### Limites connues

- L'expediteur affiche est le numero de la SIM : pas de nom de marque sans
  canal SMPP.
- Le SMS vers un numero MTN a echoue depuis la SIM actuelle (« echec
  generique ») : forfait a verifier, ou ajouter un telephone avec une SIM
  MTN.
- L'envoi d'un vrai SMS avec une cle de test n'a pas ete recette en
  conditions reelles (meme chemin que la production, qui l'est).
