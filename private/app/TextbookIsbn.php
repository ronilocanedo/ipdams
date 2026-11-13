<?php
declare(strict_types=1);
/**
 * Deterministic ISBN identity only. No database queries, writes, user identity,
 * or implicit legacy record corrections. To be wired to catalog after migration
 * preflight and product-owner review.
 */
final class TextbookIsbn {
    public static function canonical(string $input): string {
        if (strlen($input) > 80) {
            throw new InvalidArgumentException('ISBN is too long');
        }
        // Keep hyphens in display metadata, but use the unpunctuated canonical
        // ISBN-13 for uniqueness. Reject unexpected punctuation/letters.
        $flat = preg_replace('/[\s\x{2010}-\x{2015}\x{2212}\-]+/u', '', $input);
        if ($flat === null) {
            throw new InvalidArgumentException('ISBN must be valid text');
        }
        $flat = strtoupper($flat);
        if (preg_match('/^[0-9]{13}$/D', $flat) === 1) {
            if (!self::valid13($flat)) {
                throw new InvalidArgumentException('ISBN-13 check digit is invalid');
            }
            return $flat;
        }
        if (preg_match('/^[0-9]{9}[0-9X]$/D', $flat) === 1) {
            if (!self::valid10($flat)) {
                throw new InvalidArgumentException('ISBN-10 check digit is invalid');
            }
            $twelve='978'.substr($flat,0,9);
            return $twelve.self::digit13($twelve);
        }
        throw new InvalidArgumentException('Enter a valid 10- or 13-digit ISBN; books without ISBN need an official LR code');
    }

    public static function canonicalOrNull(?string $input): ?string {
        if (trim((string)$input) === '') return null;
        return self::canonical((string)$input);
    }

    private static function valid10(string $digits): bool {
        $sum=0;
        for ($i=0;$i<9;$i++) $sum+=(10-$i)*(ord($digits[$i])-48);
        $last=$digits[9]==='X'?10:ord($digits[9])-48;
        return ($sum+$last)%11===0;
    }

    private static function valid13(string $digits): bool {
        return $digits[12]===self::digit13(substr($digits,0,12));
    }

    private static function digit13(string $twelve): string {
        $sum=0;
        for ($i=0;$i<12;$i++) $sum+=(ord($twelve[$i])-48)*($i%2===0?1:3);
        return (string)((10-($sum%10))%10);
    }
}
