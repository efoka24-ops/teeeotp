<?php

declare(strict_types=1);

/**
 * Numeros de telephone.
 *
 * Le service n'envoie qu'aux mobiles camerounais (+237 6XX XX XX XX) : la SIM
 * passerelle est camerounaise, et un envoi a l'international serait facture
 * hors forfait.
 */
final class Phone
{
    /** Normalise en +2376XXXXXXXX, ou null si le numero n'est pas un mobile camerounais. */
    public static function normalize(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if (str_starts_with($digits, '00237')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '237') && strlen($digits) === 12) {
            $digits = substr($digits, 3);
        }

        return preg_match('/^6\d{8}$/', $digits) === 1 ? '+237' . $digits : null;
    }

    /** +237690123456 -> +23769******56, pour les reponses et la supervision. */
    public static function mask(string $phone): string
    {
        return substr($phone, 0, 6) . str_repeat('*', max(0, strlen($phone) - 8)) . substr($phone, -2);
    }
}
