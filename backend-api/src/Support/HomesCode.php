<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Ev kodu (homes.evkodu). Config 'homes_code':
 *   mode   off    : ilan ekranında alan yok, otomatik kod yok (varsayilan; eski davranis).
 *          manual : alan var, kullanici yazar; otomatik kod yok.
 *          auto   : alan var; bos birakilirsa kayitta format'a gore uretilir.
 *   format : {id}, {id+N} (orn. {id+1000}), {bolge_harf} (emlak bolgesi basliginin ilk harfi), {bolge},
 *            {tip_prefix} (tip.prefix). Ornekler: ovillam '{bolge_harf}-{id+1000}', villavillam 'VR-{id}'.
 * Otomatik kod sadece evkodu bossa yazilir; doldurulmus kod ezilmez. Gerekli bilgi (orn. bolge) yoksa kod
 * uretilmez, sonraki kayitta tekrar denenir.
 */
final class HomesCode
{
    public const DEFAULT_FORMAT = '{bolge_harf}-{id+1000}';

    /**
     * @param array<string,mixed> $app
     * @return array{mode:string,format:string}
     */
    public static function config(array $app): array
    {
        $raw = $app['homes_code'] ?? [];
        $raw = is_array($raw) ? $raw : [];
        $mode = strtolower(trim((string) ($raw['mode'] ?? 'off')));
        if (!in_array($mode, ['off', 'manual', 'auto'], true)) {
            $mode = 'off';
        }
        $format = trim((string) ($raw['format'] ?? ''));

        return ['mode' => $mode, 'format' => $format !== '' ? $format : self::DEFAULT_FORMAT];
    }

    /**
     * Frontend icin: mod, format ve (id henuz yokken onizleme icin) tahmini sonraki id.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    public static function definition(PDO $pdo, array $app): array
    {
        $config = self::config($app);
        if ($config['mode'] === 'off' || !self::hasColumn($pdo)) {
            return ['mode' => 'off'];
        }

        return [
            'mode' => $config['mode'],
            'format' => $config['format'],
            'sonrakiId' => self::nextIdEstimate($pdo),
        ];
    }

    /**
     * Payload'daki "evkodu" -> homes.evkodu (mod off degilse).
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    public static function updates(array $app, array $payload): array
    {
        if (self::config($app)['mode'] === 'off' || !array_key_exists('evkodu', $payload) || is_array($payload['evkodu'])) {
            return [];
        }

        return ['evkodu' => trim((string) $payload['evkodu'])];
    }

    /**
     * Otomatik kod: mod auto, evkodu bos ve kayitta gonderilmemis/bos ise uretip yazar.
     * Update'te sadece payload'da "evkodu" anahtari varsa (alan formdaysa) calisir; eski villalara kendiliginden dokunmaz.
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $payload
     */
    public static function applyAuto(PDO $pdo, array $app, int $homeId, array $payload, bool $isCreate): ?string
    {
        $config = self::config($app);
        if ($homeId <= 0 || $config['mode'] !== 'auto' || !self::hasColumn($pdo)) {
            return null;
        }
        if (!$isCreate && !array_key_exists('evkodu', $payload)) {
            return null;
        }
        if (array_key_exists('evkodu', $payload) && !is_array($payload['evkodu']) && trim((string) $payload['evkodu']) !== '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT evkodu FROM homes WHERE id = :id');
        $stmt->execute([':id' => $homeId]);
        if (trim((string) $stmt->fetchColumn()) !== '') {
            return null;
        }

        $code = self::generate($pdo, $config['format'], $homeId);
        if ($code === null) {
            return null;
        }

        $stmt = $pdo->prepare("UPDATE homes SET evkodu = :code WHERE id = :id AND ISNULL(LTRIM(RTRIM(evkodu)), '') = ''");
        $stmt->execute([':code' => $code, ':id' => $homeId]);

        return $stmt->rowCount() > 0 ? $code : null;
    }

    /**
     * Kayittaki bilgilerle kodu uretir; formatin istedigi bilgi bossa null.
     */
    public static function generate(PDO $pdo, string $format, int $homeId): ?string
    {
        $vars = ['id' => $homeId, 'bolge' => '', 'tip_prefix' => ''];

        if (self::uses($format, 'bolge')) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT d.baslik FROM homes h INNER JOIN destinations d ON d.id = h.emlak_bolgesi WHERE h.id = :id'
                );
                $stmt->execute([':id' => $homeId]);
                $vars['bolge'] = trim((string) $stmt->fetchColumn());
            } catch (\Throwable $e) {
                $vars['bolge'] = '';
            }
        }

        if (self::uses($format, 'tip_prefix')) {
            try {
                $stmt = $pdo->prepare('SELECT t.prefix FROM homes h INNER JOIN tip t ON t.id = h.emlak_tipi WHERE h.id = :id');
                $stmt->execute([':id' => $homeId]);
                $vars['tip_prefix'] = trim((string) $stmt->fetchColumn());
            } catch (\Throwable $e) {
                $vars['tip_prefix'] = '';
            }
        }

        return self::render($format, $vars);
    }

    /**
     * @param array{id:int,bolge:string,tip_prefix:string} $vars
     */
    public static function render(string $format, array $vars): ?string
    {
        $missing = false;
        $code = preg_replace_callback(
            '/\{(id(?:\s*\+\s*(\d+))?|bolge_harf|bolge|tip_prefix)\}/',
            static function (array $m) use ($vars, &$missing): string {
                $token = preg_replace('/\s+/', '', $m[1]);
                if (strpos($token, 'id') === 0) {
                    return (string) ($vars['id'] + (isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0));
                }
                if ($token === 'bolge_harf') {
                    $value = self::firstChar($vars['bolge']);
                } else {
                    $value = (string) $vars[$token];
                }
                if ($value === '') {
                    $missing = true;
                }

                return $value;
            },
            $format
        );

        return ($missing || $code === null || trim($code) === '') ? null : trim($code);
    }

    private static function uses(string $format, string $prefix): bool
    {
        return strpos($format, '{' . $prefix) !== false;
    }

    private static function firstChar(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 1, 'UTF-8') : substr($value, 0, 1);
    }

    private static function nextIdEstimate(PDO $pdo): int
    {
        try {
            $row = $pdo->query(
                "SELECT CAST(ISNULL(IDENT_CURRENT('homes'), 0) + ISNULL(IDENT_INCR('homes'), 1) AS bigint) AS identNext,
                        ISNULL(MAX(id), 0) + 1 AS maxNext,
                        COLUMNPROPERTY(OBJECT_ID('homes'), 'id', 'IsIdentity') AS isIdentity
                 FROM homes"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return 0;
            }

            return (int) ($row['isIdentity'] ?? 0) === 1 ? (int) $row['identNext'] : (int) $row['maxNext'];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function hasColumn(PDO $pdo): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = (int) $pdo->query("SELECT CASE WHEN COL_LENGTH('homes', 'evkodu') IS NULL THEN 0 ELSE 1 END")->fetchColumn() === 1;
            } catch (\Throwable $e) {
                $has = false;
            }
        }

        return $has;
    }
}
