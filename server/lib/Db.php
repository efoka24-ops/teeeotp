<?php

declare(strict_types=1);

/**
 * Acces a la base MySQL.
 *
 * Horodatages en DATETIME(3) UTC, comme apisungku : la session est forcee en
 * UTC pour que les expirations ne dependent pas du fuseau du serveur.
 * Toutes les requetes passent par des instructions preparees non emulees.
 */
final class Db
{
    /** Tables portant une colonne `updatedAt`, tenue a jour a chaque ecriture. */
    private const HORODATEES = ['otp_tenants', 'otp_devices', 'otp_requests', 'otp_sms_messages'];

    private static ?PDO $pdo = null;
    private static bool $down = false;

    public static function pdo(): PDO
    {
        try {
            return self::connect();
        } catch (PDOException $e) {
            error_log('[db] connexion impossible : ' . $e->getMessage());
            Http::fail('Service temporairement indisponible.', 503);
        }
    }

    /**
     * Vrai si la base repond. Ne repond jamais au client : sert au journal,
     * qui s'execute apres la reponse et ne doit rien y ajouter.
     */
    public static function available(): bool
    {
        try {
            self::connect();
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg = Config::db();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['port'],
            $cfg['name']
        );

        // Un echec n'est tente qu'une fois par requete : le journal, appele a
        // la fin, ne doit pas attendre un second delai de connexion.
        if (self::$down) {
            throw new PDOException('base deja injoignable pour cette requete');
        }

        try {
            $pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            self::$down = true;
            throw $e;
        }

        return self::$pdo = $pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): string
    {
        $data['id'] ??= Id::uuid();
        $now = self::now();
        $data['createdAt'] ??= $now;
        if (in_array($table, self::HORODATEES, true)) {
            $data['updatedAt'] ??= $now;
        }

        $columns = array_keys($data);
        self::run(sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        ), $data);

        return (string) $data['id'];
    }

    public static function update(string $table, string $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        if (in_array($table, self::HORODATEES, true)) {
            $data['updatedAt'] = self::now();
        }

        $sets = implode(', ', array_map(static fn (string $c): string => "`$c` = :$c", array_keys($data)));
        $data['__id'] = $id;
        self::run("UPDATE `$table` SET $sets WHERE `id` = :__id", $data);
    }

    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        // Reentrant : une operation transactionnelle appelee depuis une autre
        // rejoint la transaction en cours au lieu d'en ouvrir une seconde.
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Horodatage UTC au format DATETIME(3). */
    public static function now(?int $offsetSeconds = null): string
    {
        $moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($offsetSeconds !== null) {
            $moment = $moment->modify(sprintf('%+d seconds', $offsetSeconds));
        }
        return $moment->format('Y-m-d H:i:s.v');
    }

    /** DATETIME(3) stocke -> ISO 8601 UTC. */
    public static function iso(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $stored, new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored, new DateTimeZone('UTC'));

        return $moment === false ? null : $moment->format('Y-m-d\TH:i:s.v\Z');
    }
}
