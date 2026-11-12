<?php
declare(strict_types=1);

/**
 * IPDAMS v0.2.273 development module.
 * Pure, testable catalog-input and receiver/Division authorization policy.
 * NOT an installable module until integrated with verified v0.2.272 source,
 * persistent versioned storage and server-verified organization ancestry.
 */
final class TextbookBookProfilePolicy
{
    public const TYPES = [
        'TEXTBOOK', 'TEACHERS_MANUAL', 'LEARNERS_MATERIAL',
        'REFERENCE', 'INSTRUCTIONAL_MATERIAL', 'OTHER'
    ];

    public const OPTIONAL = [
        'subtitle'=>255, 'alternate_title'=>255, 'series'=>160,
        'volume'=>80, 'edition'=>120, 'printing'=>120,
        'authors'=>800, 'contributors'=>500, 'editor'=>255,
        'translator'=>255, 'illustrator'=>255, 'publisher'=>255,
        'imprint'=>160, 'publication_place'=>180,
        'language'=>100, 'grade'=>60, 'subject'=>120,
        'quarter'=>60, 'strand'=>120, 'program'=>120,
        'curriculum'=>160, 'intended_audience'=>160,
        'pages'=>20, 'format'=>80, 'dimensions'=>100,
        'source_reference'=>255, 'source_url'=>500,
        'verification_note'=>1000, 'remarks'=>2000,
        'printed_isbn'=>32, 'official_lr_code'=>120
    ];

    public static function normalise(array $body): array
    {
        $title = self::field($body['title'] ?? '', 255);
        if ($title === '') throw new InvalidArgumentException('Book title is required.');
        $type = trim((string)($body['material_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Select a valid material type.');
        }
        $out = ['title'=>$title, 'material_type'=>$type];
        foreach (self::OPTIONAL as $name=>$limit) {
            $out[$name] = self::field($body[$name] ?? '', $limit);
        }
        foreach (['publication_year', 'copyright_year', 'printing_year'] as $name) {
            $value = trim((string)($body[$name] ?? ''));
            if ($value !== '' && (!preg_match('/^\\d{4}$/', $value) || (int)$value < 1000 || (int)$value > (int)gmdate('Y') + 2)) {
                throw new InvalidArgumentException("Invalid {$name}.");
            }
            $out[$name] = $value;
        }
        $isbn = trim((string)($body['isbn'] ?? ''));
        $out['isbn13'] = $isbn === '' ? null : self::canonicalIsbn($isbn);
        $out['official_lr_code_normalized'] = $out['official_lr_code'] !== ''
            ? strtoupper(preg_replace('/\\s+/u',' ', $out['official_lr_code'])) : null;
        $out['identity_key'] = $out['isbn13'] !== null
            ? 'ISBN:'.$out['isbn13']
            : ($out['official_lr_code_normalized'] !== null
                ? 'LR:'.$out['official_lr_code_normalized'] : null);
        $out['verification_ready'] = $out['identity_key'] !== null;
        // No stock, quantity, batch, balance, or actor ownership fields
        // are accepted from client bibliographic input.
        return $out;
    }

    private static function field(mixed $value, int $max): string
    {
        if (!is_string($value) && !is_numeric($value) && $value !== null) {
            throw new InvalidArgumentException('Invalid bibliographic field.');
        }
        $value = trim((string)$value);
        if (preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $value)) {
            throw new InvalidArgumentException('Control characters are not permitted.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $max) throw new InvalidArgumentException("Field exceeds {$max} characters.");
        return $value;
    }

    public static function canonicalIsbn(string $isbn): string
    {
        $s = strtoupper(str_replace([' ', '-'], '', trim($isbn)));
        if (preg_match('/^\\d{9}[\\dX]$/', $s)) {
            $sum=0;
            for ($i=0;$i<10;$i++) $sum += (10-$i)*($s[$i]==='X' ? 10 : (int)$s[$i]);
            if ($sum % 11 !== 0) throw new InvalidArgumentException('Invalid ISBN-10 check digit.');
            $base = '978'.substr($s, 0, 9);
            return $base.self::isbn13Digit($base);
        }
        if (!preg_match('/^97[89]\\d{10}$/', $s)) {
            throw new InvalidArgumentException('Enter a valid ISBN-10 or ISBN-13, or leave blank for a draft.');
        }
        if ((int)$s[12] !== self::isbn13Digit(substr($s, 0, 12))) {
            throw new InvalidArgumentException('Invalid ISBN-13 check digit.');
        }
        return $s;
    }

    private static function isbn13Digit(string $twelve): int
    {
        $sum=0;
        for ($i=0;$i<12;$i++) $sum += (int)$twelve[$i] * ($i%2 ? 3 : 1);
        return (10-$sum%10)%10;
    }

    /**
     * Actor fields must come from signed-in server context and verified DB scopes.
     * Registered organization and owning Division must be immutable DB values.
     */
    public static function canEdit(
        array $actor, int $workspaceOrgId, string $workspaceKind,
        int $registeredOrgId, int $owningDivisionId
    ): bool {
        if (!in_array($actor['role'] ?? '', ['SCHOOL','DIVISION'], true)) return false;
        $allowed = $actor['authorized_organizations'] ?? [];
        if (!is_array($allowed) || !in_array($workspaceOrgId, $allowed, true)) return false;
        if (($actor['role'] ?? '') === 'SCHOOL') {
            return $workspaceKind === 'SCHOOL'
                && $workspaceOrgId === $registeredOrgId;
        }
        return $workspaceKind === 'DIVISION'
            && $workspaceOrgId === $owningDivisionId;
    }

    public static function canCreate(array $actor, int $workspaceOrgId, string $workspaceKind): bool
    {
        return in_array($actor['role'] ?? '', ['SCHOOL','DIVISION'], true)
            && ($actor['role'] ?? '') === $workspaceKind
            && in_array($workspaceOrgId, $actor['authorized_organizations'] ?? [], true);
    }

    public static function diff(array $previous, array $updated): array
    {
        $changed = [];
        foreach ($updated as $key=>$value) {
            if ($key === 'verification_ready' || $key === 'identity_key') continue;
            if (($previous[$key] ?? null) !== $value) {
                $changed[$key] = ['before'=>$previous[$key] ?? null, 'after'=>$value];
            }
        }
        return $changed;
    }
}
