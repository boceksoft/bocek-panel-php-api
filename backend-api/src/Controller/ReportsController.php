<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

/*
 * Raporlar kaynağı.
 * Ön yüzdeki rapor sayfalarına (BocekSoftV26 /reports/...) veri sağlar.
 *
 *   GET /backend-api/reports/monthlyperformance -> aylık performans raporu
 *   GET /backend-api/reports/potansiyel         -> potansiyel satış raporu (henüz eklenmedi)
 *
 * Endpoint'ler adım adım eklenecek; her yeni endpoint'te versions.txt'teki "reports" sürümü artırılır.
 */
final class ReportsController extends Controller
{
    /**
     * Aylık performans raporu: kesinleşen ciro, kazanç, rezervasyon, doluluk ve grafikler.
     *
     * @Get("monthlyperformance")
     * @query site int Site kimliği (varsayılan 1)
     * @query year int Rapor yılı (varsayılan cari yıl)
     * @query months string|array Ay veya aylar listesi (1-12)
     * @query villas string|array Villa id'leri (virgülle ayrılmış veya dizi)
     */
    public function monthlyperformance(): void
    {
        $pdo = $this->db->pdo();
        $siteId = $this->siteId();

        $year = (int) ($this->request->query('year') ?? $this->request->input('year') ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $monthsRaw = $this->request->query('months')
            ?? $this->request->input('months')
            ?? $this->request->query('month')
            ?? $this->request->input('month')
            ?? $this->request->query('ay')
            ?? $this->request->input('ay');
        $months = $this->parseIntList($monthsRaw);

        $villasRaw = $this->request->query('villas')
            ?? $this->request->input('villas')
            ?? $this->request->query('villa_ids')
            ?? $this->request->input('villa_ids')
            ?? $this->request->query('villa')
            ?? $this->request->input('villa');
        $villaIds = $this->parseIntList($villasRaw);

        $durumSql = $this->normalizedStatusSql('d.[Durum]');

        $cleanTutarSql = $this->hasFnTemizle($pdo)
            ? 'dbo.fnTemizle(k.toplam_tutar)'
            : "REPLACE(REPLACE(ISNULL(k.toplam_tutar, '0'), '.', ''), ',', '.')";
        $cleanOnOdemeSql = $this->hasFnTemizle($pdo)
            ? 'dbo.fnTemizle(k.on_odeme)'
            : "REPLACE(REPLACE(ISNULL(k.on_odeme, '0'), '.', ''), ',', '.')";
        $cleanKarSql = $this->hasFnTemizle($pdo)
            ? 'dbo.fnTemizle(k.kar)'
            : "REPLACE(REPLACE(ISNULL(k.kar, '0'), '.', ''), ',', '.')";

        $kisiJoinSql = $this->hasKisiBilgileriTable($pdo)
            ? "OUTER APPLY (
                SELECT CASE WHEN COUNT(kb.id) > 0 THEN COUNT(kb.id) ELSE 1 END AS total
                FROM dbo.kisi_bilgileri kb
                WHERE kb.siparis_kodu = k.id
            ) AS kisi"
            : "CROSS APPLY (SELECT 1 AS total) AS kisi";

        $islemDateExpr = "COALESCE(
            TRY_CONVERT(date, k.islem_tarihi, 104),
            TRY_CONVERT(date, k.islem_tarihi, 103),
            TRY_CONVERT(date, k.islem_tarihi, 120),
            TRY_CONVERT(date, k.islem_tarihi)
        )";

        $dateExpr = "COALESCE(
            TRY_CONVERT(date, d.tarih, 104),
            TRY_CONVERT(date, d.tarih, 103),
            TRY_CONVERT(date, k.rez_tarihi, 104),
            TRY_CONVERT(date, k.rez_tarihi, 103),
            {$islemDateExpr}
        )";
        $date2Expr = "COALESCE(
            TRY_CONVERT(date, d.tarih2, 104),
            TRY_CONVERT(date, d.tarih2, 103),
            TRY_CONVERT(date, k.gelecek_tarih, 104),
            TRY_CONVERT(date, k.gelecek_tarih, 103),
            DATEADD(day, 7, {$dateExpr})
        )";

        $where = [];
        $params = [];

        // Silinmişler hariç (durum 5)
        $deletedStatus = $this->deletedStatus();
        $where[] = "(ISNULL({$durumSql}, 0) <> 5)";
        if ($deletedStatus !== 5) {
            $where[] = "(ISNULL({$durumSql}, 0) <> {$deletedStatus})";
        }

        // Site filtresi
        if ($siteId > 0) {
            $where[] = '(k.site = :site OR k.site IS NULL)';
            $params[':site'] = $siteId;
        }

        // Villa filtresi: boş ise TÜM villaları getir
        if ($villaIds !== []) {
            $vPhs = [];
            foreach ($villaIds as $idx => $vid) {
                $ph = ':villa_' . $idx;
                $vPhs[] = $ph;
                $params[$ph] = $vid;
            }
            $where[] = 'k.evid IN (' . implode(',', $vPhs) . ')';
        }

        $prevYear = $year - 1;

        // Yıl filtresi: islem_tarihi üzerinden cari yıl ve önceki yıl (karşılaştırma için)
        $where[] = "YEAR({$islemDateExpr}) IN (:year, :prev_year)";
        $params[':year'] = $year;
        $params[':prev_year'] = $prevYear;

        // Ay filtresi: islem_tarihi üzerinden (boş ise TÜM aylar)
        if ($months !== []) {
            $mPhs = [];
            foreach ($months as $idx => $m) {
                $ph = ':month_' . $idx;
                $mPhs[] = $ph;
                $params[$ph] = $m;
            }
            $where[] = "MONTH({$islemDateExpr}) IN (" . implode(',', $mPhs) . ')';
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                k.id,
                k.site,
                k.evid,
                ISNULL(h.baslik, k.adi) AS villa_adi,
                CONVERT(varchar(10), {$dateExpr}, 23) AS giris_tarihi,
                CONVERT(varchar(10), {$date2Expr}, 23) AS cikis_tarihi,
                CONVERT(varchar(10), {$islemDateExpr}, 23) AS islem_tarihi,
                YEAR({$islemDateExpr}) AS rez_yil,
                ISNULL({$durumSql}, 0) AS durum,
                ISNULL({$durumSql}, 0) AS [Durum],
                ISNULL(TRY_CONVERT(int, k.odeme), 0) AS odeme_sekli,
                ISNULL(kisi.total, 1) AS kisi,
                tutar.toplam_tutar * tutar.kur_carpan AS toplam_tutar_tl,
                tutar.on_odeme * tutar.kur_carpan AS on_odeme_tl,
                tutar.kar * tutar.kur_carpan AS kar_tl,
                COALESCE(NULLIF(TRY_CONVERT(float, k.kazancorani), 0), NULLIF(TRY_CONVERT(float, h.kazancorani), 0), 0) AS kazancorani
            FROM dbo.kayitlar k
            LEFT JOIN dbo.dolu d ON d.kayitid = k.id
            LEFT JOIN dbo.homes h ON h.id = k.evid
            {$kisiJoinSql}
            CROSS APPLY (
                SELECT
                    ISNULL(TRY_CONVERT(float, {$cleanTutarSql}), 0) AS toplam_tutar,
                    ISNULL(TRY_CONVERT(float, {$cleanOnOdemeSql}), 0) AS on_odeme,
                    ISNULL(TRY_CONVERT(float, {$cleanKarSql}), 0) AS kar,
                    CASE
                        WHEN k.doviz = 'tl' OR k.doviz IS NULL OR k.doviz = '' THEN 1
                        ELSE ISNULL(TRY_CONVERT(float, k.kur), 1)
                    END AS kur_carpan
            ) AS tutar
            WHERE {$whereSql}
            ORDER BY {$islemDateExpr} ASC
            ";

        // sqlx parametresi gönderildiyse ham SQL metnini dön
        if ($this->request->query('sqlx') !== null || isset($_GET['sqlx'])) {
            $executableSql = $sql;
            foreach ($params as $pKey => $pVal) {
                $valStr = is_int($pVal) || is_float($pVal) ? (string) $pVal : "'" . str_replace("'", "''", (string) $pVal) . "'";
                $executableSql = preg_replace('/' . preg_quote($pKey, '/') . '\b/', $valStr, $executableSql);
            }

            $mode = (string) ($this->request->query('sqlx') ?? $_GET['sqlx'] ?? '');
            if ($mode === 'json') {
                $this->response->success([
                    'sql' => trim($sql),
                    'executableSql' => trim($executableSql),
                    'params' => $params,
                ]);
                return;
            }

            header('Content-Type: text/plain; charset=utf-8');
            if ($mode === 'raw' || $mode === 'template') {
                echo trim($sql);
            } else {
                echo trim($executableSql);
            }
            exit;
        }

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rowsCurrent = [];
        $rowsPrevious = [];
        foreach ($rows as $r) {
            $rYear = isset($r['rez_yil']) ? (int) $r['rez_yil'] : $year;
            if ($rYear === $prevYear) {
                $rowsPrevious[] = $r;
            } else {
                $rowsCurrent[] = $r;
            }
        }

        // ── 9 Kart Hesabı ──────────────────────────────────────────
        $kesinlesenCiro = 0.0;
        $kesinlesenKazanc = 0.0;
        $kesinlesenRez = 0;
        $kesinlesmeyenRez = 0;
        $toplamPax = 0;
        $kkTahsilat = 0.0;
        $bankaTahsilat = 0.0;

        foreach ($rowsCurrent as $row) {
            $durum = (int) ($row['durum'] ?? $row['Durum'] ?? 0);
            $ciro = (float) $row['toplam_tutar_tl'];
            $pax = max(1, (int) $row['kisi']);
            $odeme = (int) $row['odeme_sekli'];

            $kar = (float) $row['kar_tl'];
            if ($kar <= 0 && $ciro > 0) {
                $oran = (float) $row['kazancorani'];
                $kar = ($ciro * $oran) / 100.0;
            }

            if ($durum === 3) {
                // Onaylanmış
                $kesinlesenRez++;
                $kesinlesenCiro += $ciro;
                $kesinlesenKazanc += $kar;
                $toplamPax += $pax;

                // Odeme kanalı (1: Sanal POS / Kredi Kartı, 2: Havale / EFT)
                if ($odeme === 1 || $odeme === 4) {
                    $kkTahsilat += $ciro;
                } elseif ($odeme === 2 || $odeme === 5) {
                    $bankaTahsilat += $ciro;
                }
            } else {
                // İptal / Onaylanmayan
                $kesinlesmeyenRez++;
            }
        }

        $toplamTalep = $kesinlesenRez + $kesinlesmeyenRez;
        $donusumOrani = $toplamTalep > 0 ? round(($kesinlesenRez / $toplamTalep) * 100, 1) : 0.0;
        $kacanOran = $toplamTalep > 0 ? round(($kesinlesmeyenRez / $toplamTalep) * 100, 1) : 0.0;

        // Ort. Kazanç Oranı = Kazanç ÷ Ciro * 100
        $ortKazancOrani = $kesinlesenCiro > 0 ? round(($kesinlesenKazanc / $kesinlesenCiro) * 100, 2) : 0.0;

        // Ort. Kazanç Tutarı = Kazanç ÷ Rezervasyon Sayısı
        $ortKazancTutari = $kesinlesenRez > 0 ? round($kesinlesenKazanc / $kesinlesenRez, 2) : 0.0;
        $ortSatisTutari = $kesinlesenRez > 0 ? round($kesinlesenCiro / $kesinlesenRez, 2) : 0.0;

        // Toplam Pax Ortalama
        $ortPax = $kesinlesenRez > 0 ? round($toplamPax / $kesinlesenRez, 1) : 0.0;

        // Toplam Tahsilat: Ciro - Kazanç (kullanıcı tanımı)
        $toplamTahsilat = round($kesinlesenCiro - $kesinlesenKazanc, 2);
        $toplamKanal = $kkTahsilat + $bankaTahsilat;
        $kkOran = $toplamKanal > 0 ? (int) round(($kkTahsilat / $toplamKanal) * 100) : 0;
        $bankaOran = $toplamKanal > 0 ? (int) round(($bankaTahsilat / $toplamKanal) * 100) : 0;

        // ── Günlük Grafik ve Konaklayan Verileri ────────────────────
        $isSingleMonth = count($months) === 1;
        $targetMonth = $isSingleMonth ? $months[0] : (int) date('n');
        if ($targetMonth < 1 || $targetMonth > 12) {
            $targetMonth = (int) date('n');
        }

        // Tek ay seçildiyse o ayın gün sayısı; tüm aylar (veya çoklu) seçildiyse 31 gün
        $numDays = $isSingleMonth
            ? (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $targetMonth)))
            : 31;

        // Dönemdeki toplam gün sayısı (ortalama günlük konaklayan hesabı için)
        if ($isSingleMonth) {
            $totalDaysInPeriod = $numDays;
        } elseif ($months !== []) {
            $totalDaysInPeriod = 0;
            foreach ($months as $m) {
                $totalDaysInPeriod += (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $m)));
            }
        } else {
            $totalDaysInPeriod = (int) date('z', strtotime(sprintf('%04d-12-31', $year))) + 1;
        }
        $totalDaysInPeriod = max(1, $totalDaysInPeriod);

        $daily = [];
        for ($d = 1; $d <= $numDays; $d++) {
            $daily[$d] = [
                'gun' => $d,
                'day' => $d,
                'date' => sprintf('%04d-%02d-%02d', $year, $targetMonth, min($d, 28)),
                'girisKisi' => 0,
                'cikisKisi' => 0,
                'konaklayanKisi' => 0,
                'konaklayanRez' => 0,
                'kesinlesen' => 0,
                'kacanTalep' => 0,
                'donusumOrani' => 0.0,
                'brutCiro' => 0.0,
                'sirketKazanci' => 0.0,
                'sanalPos' => 0.0,
                'bankaHavalesi' => 0.0,
            ];
        }

        $totalGuestNights = 0;
        $totalRezNights = 0;

        foreach ($rowsCurrent as $row) {
            $durum = (int) ($row['durum'] ?? $row['Durum'] ?? 0);
            $ciro = (float) $row['toplam_tutar_tl'];
            $pax = max(1, (int) $row['kisi']);
            $odeme = (int) $row['odeme_sekli'];

            $kar = (float) $row['kar_tl'];
            if ($kar <= 0 && $ciro > 0) {
                $oran = (float) $row['kazancorani'];
                $kar = ($ciro * $oran) / 100.0;
            }

            $girisStr = (string) ($row['giris_tarihi'] ?? '');
            $cikisStr = (string) ($row['cikis_tarihi'] ?? '');
            if ($girisStr === '') {
                continue;
            }

            $girisTs = strtotime($girisStr);
            $cikisTs = $cikisStr !== '' ? strtotime($cikisStr) : ($girisTs + 86400 * 7);

            $girisM = (int) date('n', $girisTs);
            $girisD = (int) date('j', $girisTs);

            $cikisM = (int) date('n', $cikisTs);
            $cikisD = (int) date('j', $cikisTs);

            $islemStr = (string) ($row['islem_tarihi'] ?? '');
            $islemTs = $islemStr !== '' ? strtotime($islemStr) : $girisTs;
            $islemM = (int) date('n', $islemTs);
            $islemD = (int) date('j', $islemTs);

            $matchIslem = $isSingleMonth ? ($islemM === $targetMonth) : true;
            $matchGiris = $isSingleMonth ? ($girisM === $targetMonth) : true;
            $matchCikis = $isSingleMonth ? ($cikisM === $targetMonth) : true;

            $nights = max(1, (int) round(($cikisTs - $girisTs) / 86400));

            if ($durum === 3) {
                $totalGuestNights += ($pax * $nights);
                $totalRezNights += $nights;

                // İşlem günü: ciro, kazanç, kesinleşen ve tahsilat kanalları
                if ($matchIslem && isset($daily[$islemD])) {
                    $daily[$islemD]['kesinlesen'] += 1;
                    $daily[$islemD]['brutCiro'] += $ciro;
                    $daily[$islemD]['sirketKazanci'] += $kar;

                    if ($odeme === 1 || $odeme === 4) {
                        $daily[$islemD]['sanalPos'] += $ciro;
                    } elseif ($odeme === 2 || $odeme === 5) {
                        $daily[$islemD]['bankaHavalesi'] += $ciro;
                    }
                }

                // Giriş günü (misafir doluluk grafiği)
                if ($matchGiris && isset($daily[$girisD])) {
                    $daily[$girisD]['girisKisi'] += $pax;
                }

                // Çıkış günü (misafir doluluk grafiği)
                if ($matchCikis && isset($daily[$cikisD])) {
                    $daily[$cikisD]['cikisKisi'] += $pax;
                }

                // Konaklayan günler (giris <= day < cikis)
                if ($isSingleMonth) {
                    for ($d = 1; $d <= $numDays; $d++) {
                        $currDayTs = strtotime(sprintf('%04d-%02d-%02d', $year, $targetMonth, $d));
                        if ($currDayTs >= $girisTs && $currDayTs < $cikisTs) {
                            $daily[$d]['konaklayanKisi'] += $pax;
                            $daily[$d]['konaklayanRez'] += 1;
                        }
                    }
                } else {
                    for ($d = 1; $d <= $numDays; $d++) {
                        if ($girisD <= $cikisD) {
                            if ($d >= $girisD && $d < $cikisD) {
                                $daily[$d]['konaklayanKisi'] += $pax;
                                $daily[$d]['konaklayanRez'] += 1;
                            }
                        } else {
                            if ($d >= $girisD || $d < $cikisD) {
                                $daily[$d]['konaklayanKisi'] += $pax;
                                $daily[$d]['konaklayanRez'] += 1;
                            }
                        }
                    }
                }
            } else {
                // Kesinleşmeyen talep (işlem günü)
                if ($matchIslem && isset($daily[$islemD])) {
                    $daily[$islemD]['kacanTalep'] += 1;
                }
            }
        }

        $toplamKonaklayanKisi = 0;
        $toplamKonaklayanRez = 0;
        $enYuksekKisi = 0;

        foreach ($daily as &$dData) {
            $totalDailyTalep = $dData['kesinlesen'] + $dData['kacanTalep'];
            $dData['donusumOrani'] = $totalDailyTalep > 0
                ? round(($dData['kesinlesen'] / $totalDailyTalep) * 100, 1)
                : 0.0;

            $toplamKonaklayanKisi += $dData['konaklayanKisi'];
            $toplamKonaklayanRez += $dData['konaklayanRez'];
            if ($dData['konaklayanKisi'] > $enYuksekKisi) {
                $enYuksekKisi = $dData['konaklayanKisi'];
            }
        }
        unset($dData);

        // Günlük Konaklayan metrikleri (dönem geneli)
        $gunlukKonaklayan = (int) round($totalGuestNights / $totalDaysInPeriod);
        $ortRezGun = round($totalRezNights / $totalDaysInPeriod, 1);

        // ── Geçmiş Yıl Kıyaslaması ve Kümülatif Büyüme (Prev vs Curr) ──
        $prevCiro = 0.0;
        $prevKazanc = 0.0;
        $prevRez = 0;
        $prevPax = 0;
        $dailyCiroPrevious = array_fill(1, $numDays, 0.0);

        foreach ($rowsPrevious as $row) {
            $durum = (int) ($row['durum'] ?? $row['Durum'] ?? 0);
            $ciro = (float) $row['toplam_tutar_tl'];
            $pax = max(1, (int) $row['kisi']);
            $kar = (float) $row['kar_tl'];
            if ($kar <= 0 && $ciro > 0) {
                $oran = (float) $row['kazancorani'];
                $kar = ($ciro * $oran) / 100.0;
            }

            if ($durum === 3) {
                $prevRez++;
                $prevCiro += $ciro;
                $prevKazanc += $kar;
                $prevPax += $pax;

                $girisStr = (string) ($row['giris_tarihi'] ?? '');
                $girisTs = $girisStr !== '' ? strtotime($girisStr) : 0;

                $islemStr = (string) ($row['islem_tarihi'] ?? '');
                $islemTs = $islemStr !== '' ? strtotime($islemStr) : $girisTs;
                $islemM = (int) date('n', $islemTs);
                $islemD = (int) date('j', $islemTs);

                $matchIslem = $isSingleMonth ? ($islemM === $targetMonth) : true;
                if ($matchIslem && isset($dailyCiroPrevious[$islemD])) {
                    $dailyCiroPrevious[$islemD] += $ciro;
                }
            }
        }

        // Kümülatif Ciro Eğrisi (1..numDays)
        $cumCurrent = 0.0;
        $cumPrevious = 0.0;
        $cumulativeGrowth = [];
        for ($d = 1; $d <= $numDays; $d++) {
            $cumCurrent += (float) ($daily[$d]['brutCiro'] ?? 0.0);
            $cumPrevious += (float) ($dailyCiroPrevious[$d] ?? 0.0);
            $cumulativeGrowth[] = [
                'gun' => $d,
                'day' => $d,
                'current' => round($cumCurrent, 2),
                'previous' => round($cumPrevious, 2),
                'currentYear' => $year,
                'previousYear' => $prevYear,
                'currentYearCiro' => round($cumCurrent, 2),
                'previousYearCiro' => round($cumPrevious, 2),
                "ciro_{$year}" => round($cumCurrent, 2),
                "ciro_{$prevYear}" => round($cumPrevious, 2),
            ];
        }

        $calcDiff = function (float $curr, float $prev): array {
            if ($prev > 0) {
                $rate = round((($curr - $prev) / $prev) * 100, 1);
                $intRate = (int) round($rate);
                $sign = $rate >= 0 ? '+' : '';
                $formatted = $sign . $intRate . '%';
                $badgeFormatted = $sign . number_format($rate, 1, '.', '') . '% Geçen Yıla Göre Büyüme';
            } elseif ($curr > 0) {
                $rate = 100.0;
                $formatted = '+100%';
                $badgeFormatted = '+100% Geçen Yıla Göre Büyüme';
            } else {
                $rate = 0.0;
                $formatted = '0%';
                $badgeFormatted = '0% Geçen Yıla Göre Büyüme';
            }

            return [
                'rate' => $rate,
                'formatted' => $formatted,
                'badgeFormatted' => $badgeFormatted,
                'isPositive' => $rate >= 0,
            ];
        };

        $ciroDiff = $calcDiff($kesinlesenCiro, $prevCiro);
        $kazancDiff = $calcDiff($kesinlesenKazanc, $prevKazanc);
        $rezDiff = $calcDiff((float) $kesinlesenRez, (float) $prevRez);
        $paxDiff = $calcDiff((float) $toplamPax, (float) $prevPax);

        $monthNamesUpper = [
            1 => 'OCAK', 2 => 'ŞUBAT', 3 => 'MART', 4 => 'NİSAN', 5 => 'MAYIS', 6 => 'HAZİRAN',
            7 => 'TEMMUZ', 8 => 'AĞUSTOS', 9 => 'EYLÜL', 10 => 'EKİM', 11 => 'KASIM', 12 => 'ARALIK',
        ];
        $monthNamesTitle = [
            1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
            7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
        ];

        $periodTitle = $isSingleMonth ? ($monthNamesTitle[$targetMonth] ?? '') : 'Tüm Dönem';
        $periodUpper = $isSingleMonth ? ($monthNamesUpper[$targetMonth] ?? '') : '';
        $prevColHeader = $periodUpper !== '' ? "{$periodUpper} {$prevYear}" : (string) $prevYear;
        $currColHeader = $periodUpper !== '' ? "{$periodUpper} {$year}" : (string) $year;
        $subTitleText = $isSingleMonth
            ? "{$periodTitle} {$prevYear} ve {$periodTitle} {$year} kümülatif ciro artış eğrisi"
            : "{$prevYear} ve {$year} kümülatif ciro artış eğrisi";

        $comparison = [
            'title' => "Geçmiş Yıl Kıyaslaması ve Kümülatif Büyüme ({$prevYear} vs {$year})",
            'subTitle' => $subTitleText,
            'growthBadge' => [
                'rate' => $ciroDiff['rate'],
                'formatted' => $ciroDiff['badgeFormatted'],
                'isPositive' => $ciroDiff['isPositive'],
            ],
            'years' => [
                'current' => $year,
                'previous' => $prevYear,
            ],
            'headers' => [
                'metric' => 'METRİK',
                'previous' => $prevColHeader,
                'current' => $currColHeader,
                'diff' => 'FARK',
            ],
            'metrics' => [
                'toplamCiro' => [
                    'key' => 'toplamCiro',
                    'label' => 'Toplam Ciro',
                    'previous' => $prevCiro,
                    'previousFormatted' => $this->formatShortNumber($prevCiro),
                    'current' => $kesinlesenCiro,
                    'currentFormatted' => $this->formatShortNumber($kesinlesenCiro),
                    'fark' => $ciroDiff['formatted'],
                    'farkRate' => $ciroDiff['rate'],
                    'isPositive' => $ciroDiff['isPositive'],
                ],
                'sirketKazanci' => [
                    'key' => 'sirketKazanci',
                    'label' => 'Şirket Kazancı',
                    'previous' => $prevKazanc,
                    'previousFormatted' => $this->formatShortNumber($prevKazanc),
                    'current' => $kesinlesenKazanc,
                    'currentFormatted' => $this->formatShortNumber($kesinlesenKazanc),
                    'fark' => $kazancDiff['formatted'],
                    'farkRate' => $kazancDiff['rate'],
                    'isPositive' => $kazancDiff['isPositive'],
                ],
                'rezervasyon' => [
                    'key' => 'rezervasyon',
                    'label' => 'Rezervasyon',
                    'previous' => $prevRez,
                    'previousFormatted' => (string) $prevRez,
                    'current' => $kesinlesenRez,
                    'currentFormatted' => (string) $kesinlesenRez,
                    'fark' => $rezDiff['formatted'],
                    'farkRate' => $rezDiff['rate'],
                    'isPositive' => $rezDiff['isPositive'],
                ],
                'konaklayanPax' => [
                    'key' => 'konaklayanPax',
                    'label' => 'Konaklayan Pax',
                    'previous' => $prevPax,
                    'previousFormatted' => (string) $prevPax,
                    'current' => $toplamPax,
                    'currentFormatted' => (string) $toplamPax,
                    'fark' => $paxDiff['formatted'],
                    'farkRate' => $paxDiff['rate'],
                    'isPositive' => $paxDiff['isPositive'],
                ],
            ],
            'table' => [
                [
                    'metric' => 'Toplam Ciro',
                    'previous' => $this->formatShortNumber($prevCiro),
                    'current' => $this->formatShortNumber($kesinlesenCiro),
                    'fark' => $ciroDiff['formatted'],
                    'isPositive' => $ciroDiff['isPositive'],
                    'previousValue' => $prevCiro,
                    'currentValue' => $kesinlesenCiro,
                ],
                [
                    'metric' => 'Şirket Kazancı',
                    'previous' => $this->formatShortNumber($prevKazanc),
                    'current' => $this->formatShortNumber($kesinlesenKazanc),
                    'fark' => $kazancDiff['formatted'],
                    'isPositive' => $kazancDiff['isPositive'],
                    'previousValue' => $prevKazanc,
                    'currentValue' => $kesinlesenKazanc,
                ],
                [
                    'metric' => 'Rezervasyon',
                    'previous' => (string) $prevRez,
                    'current' => (string) $kesinlesenRez,
                    'fark' => $rezDiff['formatted'],
                    'isPositive' => $rezDiff['isPositive'],
                    'previousValue' => $prevRez,
                    'currentValue' => $kesinlesenRez,
                ],
                [
                    'metric' => 'Konaklayan Pax',
                    'previous' => (string) $prevPax,
                    'current' => (string) $toplamPax,
                    'fark' => $paxDiff['formatted'],
                    'isPositive' => $paxDiff['isPositive'],
                    'previousValue' => $prevPax,
                    'currentValue' => $toplamPax,
                ],
            ],
            'chart' => $cumulativeGrowth,
        ];

        // Yanıt zarfı
        $this->response->success([
            'cards' => [
                'kesinlesenCiro' => [
                    'value' => $kesinlesenCiro,
                    'formatted' => $this->formatShortNumber($kesinlesenCiro),
                    'rawFormatted' => $this->formatCurrency($kesinlesenCiro),
                    'title' => 'KESİNLEŞEN CİRO',
                ],
                'kesinlesenKazanc' => [
                    'value' => $kesinlesenKazanc,
                    'formatted' => $this->formatShortNumber($kesinlesenKazanc),
                    'rawFormatted' => $this->formatCurrency($kesinlesenKazanc),
                    'title' => 'KESİNLEŞEN KAZANÇ',
                ],
                'ortKazancOrani' => [
                    'value' => $ortKazancOrani,
                    'formatted' => '%' . number_format($ortKazancOrani, 2, '.', ''),
                    'subText' => 'Kazanç ÷ Ciro (Aylık)',
                    'title' => 'ORT. KAZANÇ ORANI',
                ],
                'ortKazancTutari' => [
                    'value' => $ortKazancTutari,
                    'formatted' => $this->formatCurrency($ortKazancTutari),
                    'ortSatis' => $ortSatisTutari,
                    'ortSatisFormatted' => $this->formatCurrency($ortSatisTutari),
                    'subText' => 'Ort. satış ' . $this->formatCurrency($ortSatisTutari),
                    'title' => 'ORT. KAZANÇ TUTARI',
                ],
                'kesinlesenRez' => [
                    'count' => $kesinlesenRez,
                    'toplamTalep' => $toplamTalep,
                    'donusumOrani' => $donusumOrani,
                    'formatted' => $kesinlesenRez . ' Rez',
                    'subText' => $toplamTalep . ' talep • Dönüşüm %' . number_format($donusumOrani, 1, '.', ''),
                    'title' => 'KESİNLEŞEN REZ.',
                ],
                'kesinlesmeyenRez' => [
                    'count' => $kesinlesmeyenRez,
                    'kacanOran' => $kacanOran,
                    'formatted' => (string) $kesinlesmeyenRez,
                    'subText' => "Talebin %" . number_format($kacanOran, 1, '.', '') . "'i kaçtı",
                    'title' => 'KESİNLEŞMEYEN REZ.',
                ],
                'toplamPax' => [
                    'count' => $toplamPax,
                    'ortPax' => $ortPax,
                    'formatted' => $toplamPax . ' Kişi',
                    'subText' => 'Ort. ' . number_format($ortPax, 1, '.', '') . ' kişi / rez',
                    'title' => 'TOPLAM PAX (KİŞİ)',
                ],
                'toplamTahsilat' => [
                    'value' => $toplamTahsilat,
                    'formatted' => $this->formatShortNumber($toplamTahsilat),
                    'rawFormatted' => $this->formatCurrency($toplamTahsilat),
                    'kkOran' => $kkOran,
                    'bankaOran' => $bankaOran,
                    'subText' => 'KK %' . $kkOran . ' • Banka %' . $bankaOran,
                    'title' => 'TOPLAM TAHSİLAT',
                ],
                'gunlukKonaklayan' => [
                    'ortKisiGun' => $gunlukKonaklayan,
                    'ortRezGun' => $ortRezGun,
                    'enYuksekKisi' => $enYuksekKisi,
                    'formatted' => $gunlukKonaklayan . ' Kişi / Gün',
                    'subText' => 'Ort. ' . number_format($ortRezGun, 1, '.', '') . ' rez/gün • En yüksek: ' . $enYuksekKisi . ' kişi',
                    'title' => 'GÜNLÜK KONAKLAYAN',
                ],
            ],

            // Kısayol alanları
            'kesinlesenCiro' => $kesinlesenCiro,
            'kesinlesenKazanc' => $kesinlesenKazanc,
            'ortKazancOrani' => $ortKazancOrani,
            'ortKazancTutari' => $ortKazancTutari,
            'ortSatisTutari' => $ortSatisTutari,
            'kesinlesenRez' => $kesinlesenRez,
            'kesinlesmeyenRez' => $kesinlesmeyenRez,
            'toplamTalep' => $toplamTalep,
            'donusumOrani' => $donusumOrani,
            'toplamPax' => $toplamPax,
            'ortPax' => $ortPax,
            'toplamTahsilat' => $toplamTahsilat,
            'gunlukKonaklayan' => $gunlukKonaklayan,
            'ortRezGun' => $ortRezGun,
            'enYuksekKisi' => $enYuksekKisi,

            // Grafikler (Image 5'teki 4 grafik)
            'charts' => [
                'doluluk' => array_map(function ($d) {
                    return [
                        'gun' => $d['gun'],
                        'day' => $d['day'],
                        'girisKisi' => $d['girisKisi'],
                        'cikisKisi' => $d['cikisKisi'],
                        'konaklayanKisi' => $d['konaklayanKisi'],
                        'konaklayanRez' => $d['konaklayanRez'],
                    ];
                }, array_values($daily)),

                'donusum' => array_map(function ($d) {
                    return [
                        'gun' => $d['gun'],
                        'day' => $d['day'],
                        'kesinlesen' => $d['kesinlesen'],
                        'kacanTalep' => $d['kacanTalep'],
                        'donusumOrani' => $d['donusumOrani'],
                    ];
                }, array_values($daily)),

                'ciroKazanc' => array_map(function ($d) {
                    return [
                        'gun' => $d['gun'],
                        'day' => $d['day'],
                        'brutCiro' => $d['brutCiro'],
                        'sirketKazanci' => $d['sirketKazanci'],
                    ];
                }, array_values($daily)),

                'tahsilatKanallari' => array_map(function ($d) {
                    return [
                        'gun' => $d['gun'],
                        'day' => $d['day'],
                        'sanalPos' => $d['sanalPos'],
                        'bankaHavalesi' => $d['bankaHavalesi'],
                    ];
                }, array_values($daily)),

                'kumulatifBuyume' => $cumulativeGrowth,
                'cumulativeGrowth' => $cumulativeGrowth,
            ],

            // Geçmiş Yıl Kıyaslaması ve Kümülatif Büyüme (Prev vs Curr)
            'comparison' => $comparison,

            // Aktif Filtre Bilgisi
            'activeFilters' => [
                'year' => $year,
                'previousYear' => $prevYear,
                'months' => $months,
                'villas' => $villaIds,
                'site' => $siteId,
                'chartMonth' => $targetMonth,
                'chartDays' => $numDays,
            ],
        ]);
    }

    /**
     * Misafir ve Pax Performans Raporu.
     *
     * 9 özet kart, 4 operasyonel grafik (Konaklayan Misafir, Kesinleşen Pax & Grup Büyüklüğü, Kişi Başı Ciro & Kazanç Dağılımı, Haftalık Misafir Yoğunluğu)
     * ve Geçmiş Yıl Kıyaslaması & Ay Sonu Pax Potansiyeli tablosu ile Kümülatif Pax Büyümesi eğrisini döner.
     *
     * @Get("paxperformance")
     * @query year int Raporlama yılı (varsayılan cari yıl)
     * @query months string|int Seçili ay veya aylar (1-12, 'all' veya virgülle ayrılmış)
     * @query villas string|int Seçili villa ID'leri (virgülle ayrılmış veya 'all')
     * @query site int Site kimliği (varsayılan 1)
     * @query cutoff int Projeksiyon kesme günü (varsayılan 29 veya ayın son günü)
     * @query sqlx string Ham SQL çıktısı modu ('json', 'raw', 'template')
     */
    public function paxperformance(): void
    {
        $pdo = $this->db->pdo();
        $siteId = $this->siteId();

        $year = (int) ($this->request->query('year') ?? $this->request->input('year') ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $monthsRaw = $this->request->query('months')
            ?? $this->request->input('months')
            ?? $this->request->query('month')
            ?? $this->request->input('month')
            ?? $this->request->query('ay')
            ?? $this->request->input('ay');
        $months = $this->parseIntList($monthsRaw);

        $villasRaw = $this->request->query('villas')
            ?? $this->request->input('villas')
            ?? $this->request->query('villa_ids')
            ?? $this->request->input('villa_ids')
            ?? $this->request->query('villa')
            ?? $this->request->input('villa');
        $villaIds = $this->parseIntList($villasRaw);

        $durumSql = $this->normalizedStatusSql('d.[Durum]');

        $cleanTutarSql = $this->hasFnTemizle($pdo)
            ? 'dbo.fnTemizle(k.toplam_tutar)'
            : "REPLACE(REPLACE(ISNULL(k.toplam_tutar, '0'), '.', ''), ',', '.')";
        $cleanOnOdemeSql = $this->hasFnTemizle($pdo)
            ? 'dbo.fnTemizle(k.on_odeme)'
            : "REPLACE(REPLACE(ISNULL(k.on_odeme, '0'), '.', ''), ',', '.')";
        $cleanKarSql = $this->hasFnTemizle($pdo)
            ? 'dbo.fnTemizle(k.kar)'
            : "REPLACE(REPLACE(ISNULL(k.kar, '0'), '.', ''), ',', '.')";

        $kisiJoinSql = $this->hasKisiBilgileriTable($pdo)
            ? "OUTER APPLY (
                SELECT CASE WHEN COUNT(kb.id) > 0 THEN COUNT(kb.id) ELSE 1 END AS total
                FROM dbo.kisi_bilgileri kb
                WHERE kb.siparis_kodu = k.id
            ) AS kisi"
            : "CROSS APPLY (SELECT 1 AS total) AS kisi";

        $islemDateExpr = "COALESCE(
            TRY_CONVERT(date, k.islem_tarihi, 104),
            TRY_CONVERT(date, k.islem_tarihi, 103),
            TRY_CONVERT(date, k.islem_tarihi, 120),
            TRY_CONVERT(date, k.islem_tarihi)
        )";

        $dateExpr = "COALESCE(
            TRY_CONVERT(date, d.tarih, 104),
            TRY_CONVERT(date, d.tarih, 103),
            TRY_CONVERT(date, k.rez_tarihi, 104),
            TRY_CONVERT(date, k.rez_tarihi, 103),
            {$islemDateExpr}
        )";
        $date2Expr = "COALESCE(
            TRY_CONVERT(date, d.tarih2, 104),
            TRY_CONVERT(date, d.tarih2, 103),
            TRY_CONVERT(date, k.gelecek_tarih, 104),
            TRY_CONVERT(date, k.gelecek_tarih, 103),
            DATEADD(day, 7, {$dateExpr})
        )";

        $where = [];
        $params = [];

        // Silinmişler hariç (durum 5)
        $deletedStatus = $this->deletedStatus();
        $where[] = "(ISNULL({$durumSql}, 0) <> 5)";
        if ($deletedStatus !== 5) {
            $where[] = "(ISNULL({$durumSql}, 0) <> {$deletedStatus})";
        }

        // Site filtresi
        if ($siteId > 0) {
            $where[] = '(k.site = :site OR k.site IS NULL)';
            $params[':site'] = $siteId;
        }

        // Villa filtresi: boş ise TÜM villaları getir
        if ($villaIds !== []) {
            $vPhs = [];
            foreach ($villaIds as $idx => $vid) {
                $ph = ':villa_' . $idx;
                $vPhs[] = $ph;
                $params[$ph] = $vid;
            }
            $where[] = 'k.evid IN (' . implode(',', $vPhs) . ')';
        }

        $prevYear = $year - 1;

        // Yıl filtresi: islem_tarihi üzerinden cari yıl ve önceki yıl (karşılaştırma için)
        $where[] = "YEAR({$islemDateExpr}) IN (:year, :prev_year)";
        $params[':year'] = $year;
        $params[':prev_year'] = $prevYear;

        // Ay filtresi: islem_tarihi üzerinden (boş ise TÜM aylar)
        if ($months !== []) {
            $mPhs = [];
            foreach ($months as $idx => $m) {
                $ph = ':month_' . $idx;
                $mPhs[] = $ph;
                $params[$ph] = $m;
            }
            $where[] = "MONTH({$islemDateExpr}) IN (" . implode(',', $mPhs) . ')';
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                k.id,
                k.site,
                k.evid,
                ISNULL(h.baslik, k.adi) AS villa_adi,
                CONVERT(varchar(10), {$dateExpr}, 23) AS giris_tarihi,
                CONVERT(varchar(10), {$date2Expr}, 23) AS cikis_tarihi,
                CONVERT(varchar(10), {$islemDateExpr}, 23) AS islem_tarihi,
                YEAR({$islemDateExpr}) AS rez_yil,
                ISNULL({$durumSql}, 0) AS durum,
                ISNULL({$durumSql}, 0) AS [Durum],
                ISNULL(TRY_CONVERT(int, k.odeme), 0) AS odeme_sekli,
                ISNULL(kisi.total, 1) AS kisi,
                tutar.toplam_tutar * tutar.kur_carpan AS toplam_tutar_tl,
                tutar.on_odeme * tutar.kur_carpan AS on_odeme_tl,
                tutar.kar * tutar.kur_carpan AS kar_tl,
                COALESCE(NULLIF(TRY_CONVERT(float, k.kazancorani), 0), NULLIF(TRY_CONVERT(float, h.kazancorani), 0), 0) AS kazancorani
            FROM dbo.kayitlar k
            LEFT JOIN dbo.dolu d ON d.kayitid = k.id
            LEFT JOIN dbo.homes h ON h.id = k.evid
            {$kisiJoinSql}
            CROSS APPLY (
                SELECT
                    ISNULL(TRY_CONVERT(float, {$cleanTutarSql}), 0) AS toplam_tutar,
                    ISNULL(TRY_CONVERT(float, {$cleanOnOdemeSql}), 0) AS on_odeme,
                    ISNULL(TRY_CONVERT(float, {$cleanKarSql}), 0) AS kar,
                    CASE
                        WHEN k.doviz = 'tl' OR k.doviz IS NULL OR k.doviz = '' THEN 1
                        ELSE ISNULL(TRY_CONVERT(float, k.kur), 1)
                    END AS kur_carpan
            ) AS tutar
            WHERE {$whereSql}
            ORDER BY {$islemDateExpr} ASC
            ";

        // sqlx parametresi gönderildiyse ham SQL metnini dön
        if ($this->request->query('sqlx') !== null || isset($_GET['sqlx'])) {
            $executableSql = $sql;
            foreach ($params as $pKey => $pVal) {
                $valStr = is_int($pVal) || is_float($pVal) ? (string) $pVal : "'" . str_replace("'", "''", (string) $pVal) . "'";
                $executableSql = preg_replace('/' . preg_quote($pKey, '/') . '\b/', $valStr, $executableSql);
            }

            $mode = (string) ($this->request->query('sqlx') ?? $_GET['sqlx'] ?? '');
            if ($mode === 'json') {
                $this->response->success([
                    'sql' => trim($sql),
                    'executableSql' => trim($executableSql),
                    'params' => $params,
                ]);
                return;
            }

            header('Content-Type: text/plain; charset=utf-8');
            if ($mode === 'raw' || $mode === 'template') {
                echo trim($sql);
            } else {
                echo trim($executableSql);
            }
            exit;
        }

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rowsCurrent = [];
        $rowsPrevious = [];
        foreach ($rows as $r) {
            $rYear = isset($r['rez_yil']) ? (int) $r['rez_yil'] : $year;
            if ($rYear === $prevYear) {
                $rowsPrevious[] = $r;
            } else {
                $rowsCurrent[] = $r;
            }
        }

        // Gün ve ay hesaplamaları
        $isSingleMonth = count($months) === 1;
        if ($isSingleMonth) {
            $targetMonth = $months[0];
        } elseif ($months !== []) {
            $targetMonth = (int) reset($months);
        } else {
            $targetMonth = ($year === (int) date('Y')) ? (int) date('n') : 9;
        }

        $numDays = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $targetMonth)));

        if ($isSingleMonth) {
            $totalDaysInPeriod = $numDays;
        } elseif ($months !== []) {
            $totalDaysInPeriod = 0;
            foreach ($months as $m) {
                $totalDaysInPeriod += (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $m)));
            }
        } else {
            $totalDaysInPeriod = (int) date('z', strtotime(sprintf('%04d-12-31', $year))) + 1;
        }
        $totalDaysInPeriod = max(1, $totalDaysInPeriod);

        // Projeksiyon Kesme Günü (Örn: 29)
        $cutoffRaw = $this->request->query('cutoff') ?? $this->request->input('cutoff');
        if ($cutoffRaw !== null && is_numeric($cutoffRaw) && (int) $cutoffRaw >= 1 && (int) $cutoffRaw <= $numDays) {
            $cutoffDay = (int) $cutoffRaw;
        } elseif ($year === (int) date('Y') && $targetMonth === (int) date('n')) {
            $cutoffDay = min((int) date('j'), $numDays);
        } else {
            $cutoffDay = min(29, $numDays);
        }
        $cutoffDay = max(1, $cutoffDay);

        // Günlük veri yapıları (Cari Yıl ve Önceki Yıl)
        $dailyCurrent = [];
        $dailyPrevious = [];
        for ($d = 1; $d <= $numDays; $d++) {
            $dailyCurrent[$d] = [
                'day' => $d,
                'gun' => $d,
                'date' => sprintf('%04d-%02d-%02d', $year, $targetMonth, min($d, 28)),
                'girisKisi' => 0,
                'cikisKisi' => 0,
                'konaklayanKisi' => 0,
                'konaklayanRez' => 0,
                'kesinlesenPax' => 0,
                'kesinlesenRez' => 0,
                'ciro' => 0.0,
                'kazanc' => 0.0,
                'tahsilat' => 0.0,
            ];
            $dailyPrevious[$d] = [
                'day' => $d,
                'gun' => $d,
                'date' => sprintf('%04d-%02d-%02d', $prevYear, $targetMonth, min($d, 28)),
                'girisKisi' => 0,
                'cikisKisi' => 0,
                'konaklayanKisi' => 0,
                'konaklayanRez' => 0,
                'kesinlesenPax' => 0,
                'kesinlesenRez' => 0,
                'ciro' => 0.0,
                'kazanc' => 0.0,
                'tahsilat' => 0.0,
            ];
        }

        // Cari Yıl İstatistikleri
        $toplamPax = 0;
        $kesinlesenRez = 0;
        $kesinlesenCiro = 0.0;
        $kesinlesenKazanc = 0.0;
        $toplamTahsilat = 0.0;
        $totalGuestNights = 0;
        $totalRezNights = 0;
        $totalGirisKisi = 0;
        $totalCikisKisi = 0;

        foreach ($rowsCurrent as $row) {
            $durum = (int) ($row['durum'] ?? $row['Durum'] ?? 0);
            if ($durum !== 3) {
                continue;
            }

            $ciro = (float) $row['toplam_tutar_tl'];
            $pax = max(1, (int) $row['kisi']);
            $odeme = (int) $row['odeme_sekli'];

            $kar = (float) $row['kar_tl'];
            if ($kar <= 0 && $ciro > 0) {
                $oran = (float) $row['kazancorani'];
                $kar = ($ciro * $oran) / 100.0;
            }

            $tahsilat = ($odeme === 1 || $odeme === 4 || $odeme === 2 || $odeme === 5)
                ? $ciro
                : (float) ($row['on_odeme_tl'] ?? 0);

            $toplamPax += $pax;
            $kesinlesenRez++;
            $kesinlesenCiro += $ciro;
            $kesinlesenKazanc += $kar;
            $toplamTahsilat += $tahsilat;

            $girisStr = (string) ($row['giris_tarihi'] ?? '');
            $cikisStr = (string) ($row['cikis_tarihi'] ?? '');
            if ($girisStr === '') {
                continue;
            }

            $girisTs = strtotime($girisStr);
            $cikisTs = $cikisStr !== '' ? strtotime($cikisStr) : ($girisTs + 86400 * 7);

            $girisM = (int) date('n', $girisTs);
            $girisD = (int) date('j', $girisTs);
            $cikisM = (int) date('n', $cikisTs);
            $cikisD = (int) date('j', $cikisTs);

            $islemStr = (string) ($row['islem_tarihi'] ?? '');
            $islemTs = $islemStr !== '' ? strtotime($islemStr) : $girisTs;
            $islemM = (int) date('n', $islemTs);
            $islemD = (int) date('j', $islemTs);

            $matchIslem = $isSingleMonth ? ($islemM === $targetMonth) : true;
            $matchGiris = $isSingleMonth ? ($girisM === $targetMonth) : true;
            $matchCikis = $isSingleMonth ? ($cikisM === $targetMonth) : true;

            $nights = max(1, (int) round(($cikisTs - $girisTs) / 86400));
            $totalGuestNights += ($pax * $nights);
            $totalRezNights += $nights;

            // İşlem günü: pax, rez, ciro, kazanç
            if ($matchIslem && isset($dailyCurrent[$islemD])) {
                $dailyCurrent[$islemD]['kesinlesenPax'] += $pax;
                $dailyCurrent[$islemD]['kesinlesenRez'] += 1;
                $dailyCurrent[$islemD]['ciro'] += $ciro;
                $dailyCurrent[$islemD]['kazanc'] += $kar;
                $dailyCurrent[$islemD]['tahsilat'] += $tahsilat;
            }

            // Giriş günü
            if ($matchGiris) {
                $totalGirisKisi += $pax;
                if (isset($dailyCurrent[$girisD])) {
                    $dailyCurrent[$girisD]['girisKisi'] += $pax;
                }
            }

            // Çıkış günü
            if ($matchCikis) {
                $totalCikisKisi += $pax;
                if (isset($dailyCurrent[$cikisD])) {
                    $dailyCurrent[$cikisD]['cikisKisi'] += $pax;
                }
            }

            // Konaklayan günler (giris <= day < cikis)
            if ($isSingleMonth) {
                for ($d = 1; $d <= $numDays; $d++) {
                    $currDayTs = strtotime(sprintf('%04d-%02d-%02d', $year, $targetMonth, $d));
                    if ($currDayTs >= $girisTs && $currDayTs < $cikisTs) {
                        $dailyCurrent[$d]['konaklayanKisi'] += $pax;
                        $dailyCurrent[$d]['konaklayanRez'] += 1;
                    }
                }
            } else {
                for ($d = 1; $d <= $numDays; $d++) {
                    if ($girisD <= $cikisD) {
                        if ($d >= $girisD && $d < $cikisD) {
                            $dailyCurrent[$d]['konaklayanKisi'] += $pax;
                            $dailyCurrent[$d]['konaklayanRez'] += 1;
                        }
                    } else {
                        if ($d >= $girisD || $d < $cikisD) {
                            $dailyCurrent[$d]['konaklayanKisi'] += $pax;
                            $dailyCurrent[$d]['konaklayanRez'] += 1;
                        }
                    }
                }
            }
        }

        // Önceki Yıl İstatistikleri
        $prevPax = 0;
        $prevRez = 0;
        $prevCiro = 0.0;
        $prevKazanc = 0.0;
        $prevGuestNights = 0;
        $prevRezNights = 0;
        $prevGirisKisi = 0;
        $prevCikisKisi = 0;

        foreach ($rowsPrevious as $row) {
            $durum = (int) ($row['durum'] ?? $row['Durum'] ?? 0);
            if ($durum !== 3) {
                continue;
            }

            $ciro = (float) $row['toplam_tutar_tl'];
            $pax = max(1, (int) $row['kisi']);
            $kar = (float) $row['kar_tl'];
            if ($kar <= 0 && $ciro > 0) {
                $oran = (float) $row['kazancorani'];
                $kar = ($ciro * $oran) / 100.0;
            }

            $prevPax += $pax;
            $prevRez++;
            $prevCiro += $ciro;
            $prevKazanc += $kar;

            $girisStr = (string) ($row['giris_tarihi'] ?? '');
            $cikisStr = (string) ($row['cikis_tarihi'] ?? '');
            if ($girisStr === '') {
                continue;
            }

            $girisTs = strtotime($girisStr);
            $cikisTs = $cikisStr !== '' ? strtotime($cikisStr) : ($girisTs + 86400 * 7);

            $girisM = (int) date('n', $girisTs);
            $girisD = (int) date('j', $girisTs);
            $cikisM = (int) date('n', $cikisTs);
            $cikisD = (int) date('j', $cikisTs);

            $islemStr = (string) ($row['islem_tarihi'] ?? '');
            $islemTs = $islemStr !== '' ? strtotime($islemStr) : $girisTs;
            $islemM = (int) date('n', $islemTs);
            $islemD = (int) date('j', $islemTs);

            $matchIslem = $isSingleMonth ? ($islemM === $targetMonth) : true;
            $matchGiris = $isSingleMonth ? ($girisM === $targetMonth) : true;
            $matchCikis = $isSingleMonth ? ($cikisM === $targetMonth) : true;

            $nights = max(1, (int) round(($cikisTs - $girisTs) / 86400));
            $prevGuestNights += ($pax * $nights);
            $prevRezNights += $nights;

            if ($matchIslem && isset($dailyPrevious[$islemD])) {
                $dailyPrevious[$islemD]['kesinlesenPax'] += $pax;
                $dailyPrevious[$islemD]['kesinlesenRez'] += 1;
                $dailyPrevious[$islemD]['ciro'] += $ciro;
                $dailyPrevious[$islemD]['kazanc'] += $kar;
            }

            if ($matchGiris) {
                $prevGirisKisi += $pax;
                if (isset($dailyPrevious[$girisD])) {
                    $dailyPrevious[$girisD]['girisKisi'] += $pax;
                }
            }

            if ($matchCikis) {
                $prevCikisKisi += $pax;
                if (isset($dailyPrevious[$cikisD])) {
                    $dailyPrevious[$cikisD]['cikisKisi'] += $pax;
                }
            }

            if ($isSingleMonth) {
                for ($d = 1; $d <= $numDays; $d++) {
                    $currDayTs = strtotime(sprintf('%04d-%02d-%02d', $prevYear, $targetMonth, $d));
                    if ($currDayTs >= $girisTs && $currDayTs < $cikisTs) {
                        $dailyPrevious[$d]['konaklayanKisi'] += $pax;
                        $dailyPrevious[$d]['konaklayanRez'] += 1;
                    }
                }
            } else {
                for ($d = 1; $d <= $numDays; $d++) {
                    if ($girisD <= $cikisD) {
                        if ($d >= $girisD && $d < $cikisD) {
                            $dailyPrevious[$d]['konaklayanKisi'] += $pax;
                            $dailyPrevious[$d]['konaklayanRez'] += 1;
                        }
                    } else {
                        if ($d >= $girisD || $d < $cikisD) {
                            $dailyPrevious[$d]['konaklayanKisi'] += $pax;
                            $dailyPrevious[$d]['konaklayanRez'] += 1;
                        }
                    }
                }
            }
        }

        // Günlük konaklayan kişi Min & Max (Cari yıl)
        $maxKonaklayan = 0;
        $minKonaklayan = null;
        foreach ($dailyCurrent as $dData) {
            $k = (int) $dData['konaklayanKisi'];
            if ($k > $maxKonaklayan) {
                $maxKonaklayan = $k;
            }
            if ($minKonaklayan === null || $k < $minKonaklayan) {
                $minKonaklayan = $k;
            }
        }
        if ($minKonaklayan === null) {
            $minKonaklayan = 0;
        }

        // Metrik hesaplamaları
        $ortGunlukKonaklayan = (int) round($totalGuestNights / $totalDaysInPeriod);
        $ortRezGun = round($totalRezNights / $totalDaysInPeriod, 1);
        $ortGrup = $kesinlesenRez > 0 ? round($toplamPax / $kesinlesenRez, 1) : 0.0;
        $ortKonaklamaSuresi = $totalGirisKisi > 0 ? round($totalGuestNights / $totalGirisKisi, 1) : 0.0;
        $kisiBasiCiro = $toplamPax > 0 ? round($kesinlesenCiro / $toplamPax, 2) : 0.0;
        $kisiBasiKazanc = $toplamPax > 0 ? round($kesinlesenKazanc / $toplamPax, 2) : 0.0;
        $ortKazancOrani = $kesinlesenCiro > 0 ? round(($kesinlesenKazanc / $kesinlesenCiro) * 100) : 0;
        $kisiGeceBasiCiro = $totalGuestNights > 0 ? round($kesinlesenCiro / $totalGuestNights, 2) : 0.0;
        $kisiBasiTahsilat = $toplamPax > 0 ? round($toplamTahsilat / $toplamPax, 2) : 0.0;

        // ── 9 Özet Kart (Image 1) ──────────────────────────────────
        $cards = [
            'toplamPax' => [
                'key' => 'toplamPax',
                'title' => 'TOPLAM KESİNLEŞEN PAX',
                'value' => $toplamPax,
                'formatted' => number_format($toplamPax, 0, ',', '.') . ' kişi',
                'subText' => "{$kesinlesenRez} rezervasyon · ort. grup " . number_format($ortGrup, 1, ',', '.') . ' kişi',
                'kesinlesenRez' => $kesinlesenRez,
                'ortGrup' => $ortGrup,
                'ortGrupFormatted' => number_format($ortGrup, 1, ',', '.') . ' kişi',
            ],
            'ortGunlukKonaklayan' => [
                'key' => 'ortGunlukKonaklayan',
                'title' => 'ORT. GÜNLÜK KONAKLAYAN',
                'value' => $ortGunlukKonaklayan,
                'formatted' => number_format($ortGunlukKonaklayan, 0, ',', '.') . ' kişi',
                'subText' => "Ort. " . number_format($ortRezGun, 1, ',', '.') . " rez/gün · Max {$maxKonaklayan} · Min {$minKonaklayan}",
                'ortRezGun' => $ortRezGun,
                'maxKonaklayan' => $maxKonaklayan,
                'minKonaklayan' => $minKonaklayan,
            ],
            'toplamKisiGece' => [
                'key' => 'toplamKisiGece',
                'title' => 'TOPLAM KİŞİ-GECE',
                'value' => $totalGuestNights,
                'formatted' => number_format($totalGuestNights, 0, ',', '.'),
                'subText' => "{$totalGirisKisi} giriş (kişi) · {$totalCikisKisi} çıkış (kişi)",
                'girisKisi' => $totalGirisKisi,
                'cikisKisi' => $totalCikisKisi,
            ],
            'ortKonaklamaSuresi' => [
                'key' => 'ortKonaklamaSuresi',
                'title' => 'ORT. KONAKLAMA SÜRESİ',
                'value' => $ortKonaklamaSuresi,
                'formatted' => number_format($ortKonaklamaSuresi, 1, ',', '.') . ' gece',
                'subText' => 'Kişi-gece ÷ giriş yapan kişi sayısı',
            ],
            'kisiBasiCiro' => [
                'key' => 'kisiBasiCiro',
                'title' => 'KİŞİ BAŞI CİRO',
                'value' => $kisiBasiCiro,
                'formatted' => '₺' . number_format($kisiBasiCiro, 2, ',', '.'),
                'subText' => 'Toplam ciro: ' . $this->formatShortNumber($kesinlesenCiro),
                'toplamCiro' => $kesinlesenCiro,
                'toplamCiroFormatted' => $this->formatShortNumber($kesinlesenCiro),
            ],
            'kisiBasiKazanc' => [
                'key' => 'kisiBasiKazanc',
                'title' => 'KİŞİ BAŞI KAZANÇ',
                'value' => $kisiBasiKazanc,
                'formatted' => '₺' . number_format($kisiBasiKazanc, 2, ',', '.'),
                'subText' => 'Toplam acente kazancı: ' . $this->formatShortNumber($kesinlesenKazanc),
                'toplamKazanc' => $kesinlesenKazanc,
                'toplamKazancFormatted' => $this->formatShortNumber($kesinlesenKazanc),
            ],
            'ortKazancOrani' => [
                'key' => 'ortKazancOrani',
                'title' => 'ORT. KAZANÇ ORANI',
                'value' => $ortKazancOrani,
                'formatted' => $ortKazancOrani . '%',
                'subText' => 'Aylık kazanç ÷ brüt ciro',
            ],
            'kisiGeceBasiCiro' => [
                'key' => 'kisiGeceBasiCiro',
                'title' => 'KİŞİ-GECE BAŞI CİRO',
                'value' => $kisiGeceBasiCiro,
                'formatted' => '₺' . number_format($kisiGeceBasiCiro, 2, ',', '.'),
                'subText' => 'Ciro ÷ konaklanan kişi-gece',
            ],
            'kisiBasiTahsilat' => [
                'key' => 'kisiBasiTahsilat',
                'title' => 'KİŞİ BAŞI TAHSİLAT',
                'value' => $kisiBasiTahsilat,
                'formatted' => '₺' . number_format($kisiBasiTahsilat, 2, ',', '.'),
                'subText' => 'Toplam tahsilat: ' . $this->formatShortNumber($toplamTahsilat),
                'toplamTahsilat' => $toplamTahsilat,
                'toplamTahsilatFormatted' => $this->formatShortNumber($toplamTahsilat),
            ],
        ];

        // ── 4 Operasyonel Grafik (Image 2) ─────────────────────────
        // Grafik 1: Konaklayan Misafir Sayısı
        $chartKonaklayan = [];
        // Grafik 2: Kesinleşen Pax & Ortalama Grup Büyüklüğü
        $chartPaxGrup = [];
        // Grafik 3: Kişi Başı Ciro ve Kazanç Dağılımı
        $chartKisiBasi = [];

        for ($d = 1; $d <= $numDays; $d++) {
            $cData = $dailyCurrent[$d];

            $chartKonaklayan[] = [
                'day' => $d,
                'gun' => $d,
                'date' => sprintf('%04d-%02d-%02d', $year, $targetMonth, min($d, 28)),
                'girisKisi' => (int) $cData['girisKisi'],
                'cikisKisi' => (int) $cData['cikisKisi'],
                'konaklayanKisi' => (int) $cData['konaklayanKisi'],
                'konaklayanRez' => (int) $cData['konaklayanRez'],
            ];

            $dailyRez = (int) $cData['kesinlesenRez'];
            $dailyPax = (int) $cData['kesinlesenPax'];
            $dailyOrtGrup = $dailyRez > 0 ? round($dailyPax / $dailyRez, 1) : 0.0;

            $chartPaxGrup[] = [
                'day' => $d,
                'gun' => $d,
                'kesinlesenPax' => $dailyPax,
                'kesinlesenRez' => $dailyRez,
                'ortGrupBuyuklugu' => $dailyOrtGrup,
            ];

            $dailyCiro = (float) $cData['ciro'];
            $dailyKar = (float) $cData['kazanc'];
            $kbCiro = $dailyPax > 0 ? round($dailyCiro / $dailyPax, 2) : 0.0;
            $kbKazanc = $dailyPax > 0 ? round($dailyKar / $dailyPax, 2) : 0.0;

            $chartKisiBasi[] = [
                'day' => $d,
                'gun' => $d,
                'kesinlesenPax' => $dailyPax,
                'kisiBasiCiro' => $kbCiro,
                'kisiBasiKazanc' => $kbKazanc,
                'ciro' => round($dailyCiro, 2),
                'kazanc' => round($dailyKar, 2),
            ];
        }

        // Grafik 4: Haftalık Misafir Yoğunluğu (Kişi-Gece)
        $weekdayDefs = [
            1 => ['code' => 'Pzt', 'name' => 'Pazartesi', 'isWeekend' => false],
            2 => ['code' => 'Sal', 'name' => 'Salı', 'isWeekend' => false],
            3 => ['code' => 'Car', 'name' => 'Çarşamba', 'isWeekend' => false],
            4 => ['code' => 'Per', 'name' => 'Perşembe', 'isWeekend' => false],
            5 => ['code' => 'Cum', 'name' => 'Cuma', 'isWeekend' => false],
            6 => ['code' => 'Cmt', 'name' => 'Cumartesi', 'isWeekend' => true],
            7 => ['code' => 'Paz', 'name' => 'Pazar', 'isWeekend' => true],
        ];

        $weekdaySums = array_fill(1, 7, 0);
        $weekdayCounts = array_fill(1, 7, 0);

        for ($d = 1; $d <= $numDays; $d++) {
            $ts = strtotime(sprintf('%04d-%02d-%02d', $year, $targetMonth, $d));
            $w = (int) date('N', $ts); // 1 (Pzt) .. 7 (Paz)
            $weekdaySums[$w] += (int) $dailyCurrent[$d]['konaklayanKisi'];
            $weekdayCounts[$w]++;
        }

        $chartHaftalik = [];
        foreach ($weekdayDefs as $w => $def) {
            $cnt = max(1, $weekdayCounts[$w]);
            $avg = round($weekdaySums[$w] / $cnt, 1);
            $chartHaftalik[] = [
                'dayIndex' => $w,
                'dayCode' => $def['code'],
                'dayName' => $def['name'],
                'averageGuests' => $avg,
                'formatted' => number_format($avg, 0, ',', '.'),
                'isWeekend' => $def['isWeekend'],
            ];
        }

        // ── Geçmiş Yıl Kıyaslaması ve Ay Sonu Pax Potansiyeli (Image 3) ──
        $calcDiff = function (float $curr, float $prev): array {
            if ($prev > 0) {
                $rate = (($curr - $prev) / $prev) * 100.0;
                $sign = $rate >= 0 ? '+' : '';
                $formatted = $sign . number_format($rate, 1, '.', '') . '%';
            } elseif ($curr > 0) {
                $rate = 100.0;
                $formatted = '+100.0%';
            } else {
                $rate = 0.0;
                $formatted = '0.0%';
            }

            return [
                'rate' => round($rate, 1),
                'formatted' => $formatted,
                'isPositive' => $rate >= 0,
            ];
        };

        // 1..cutoffDay arası kümülatif toplamlar
        $currPaxCutoff = 0;
        $currRezCutoff = 0;
        $currCiroCutoff = 0.0;
        $currKazancCutoff = 0.0;
        $currNightsCutoff = 0;
        $currGirisCutoff = 0;

        $prevPaxCutoff = 0;
        $prevRezCutoff = 0;
        $prevCiroCutoff = 0.0;
        $prevKazancCutoff = 0.0;
        $prevNightsCutoff = 0;
        $prevGirisCutoff = 0;

        for ($d = 1; $d <= $cutoffDay; $d++) {
            $currPaxCutoff += (int) $dailyCurrent[$d]['kesinlesenPax'];
            $currRezCutoff += (int) $dailyCurrent[$d]['kesinlesenRez'];
            $currCiroCutoff += (float) $dailyCurrent[$d]['ciro'];
            $currKazancCutoff += (float) $dailyCurrent[$d]['kazanc'];
            $currNightsCutoff += (int) $dailyCurrent[$d]['konaklayanKisi'];
            $currGirisCutoff += (int) $dailyCurrent[$d]['girisKisi'];

            $prevPaxCutoff += (int) $dailyPrevious[$d]['kesinlesenPax'];
            $prevRezCutoff += (int) $dailyPrevious[$d]['kesinlesenRez'];
            $prevCiroCutoff += (float) $dailyPrevious[$d]['ciro'];
            $prevKazancCutoff += (float) $dailyPrevious[$d]['kazanc'];
            $prevNightsCutoff += (int) $dailyPrevious[$d]['konaklayanKisi'];
            $prevGirisCutoff += (int) $dailyPrevious[$d]['girisKisi'];
        }

        // Önceki Yıl Ay Toplamları
        $prevPaxFull = 0;
        $prevRezFull = 0;
        $prevCiroFull = 0.0;
        $prevKazancFull = 0.0;
        $prevNightsFull = 0;
        $prevGirisFull = 0;

        for ($d = 1; $d <= $numDays; $d++) {
            $prevPaxFull += (int) $dailyPrevious[$d]['kesinlesenPax'];
            $prevRezFull += (int) $dailyPrevious[$d]['kesinlesenRez'];
            $prevCiroFull += (float) $dailyPrevious[$d]['ciro'];
            $prevKazancFull += (float) $dailyPrevious[$d]['kazanc'];
            $prevNightsFull += (int) $dailyPrevious[$d]['konaklayanKisi'];
            $prevGirisFull += (int) $dailyPrevious[$d]['girisKisi'];
        }

        // Performans Çarpanı (bu yıl 1..cutoff ÷ geçen yıl 1..cutoff)
        $paxMultiplier = $prevPaxCutoff > 0 ? ($currPaxCutoff / $prevPaxCutoff) : 1.0;
        $ciroMultiplier = $prevCiroCutoff > 0 ? ($currCiroCutoff / $prevCiroCutoff) : 1.0;
        $nightsMultiplier = $prevNightsCutoff > 0 ? ($currNightsCutoff / $prevNightsCutoff) : 1.0;
        $girisMultiplier = $prevGirisCutoff > 0 ? ($currGirisCutoff / $prevGirisCutoff) : 1.0;

        // Potansiyel Projeksiyonları (1..numDays)
        $potPax = (int) round($currPaxCutoff + max(0, $prevPaxFull - $prevPaxCutoff) * $paxMultiplier);
        $potCiro = round($currCiroCutoff + max(0, $prevCiroFull - $prevCiroCutoff) * $ciroMultiplier);
        $potNights = (int) round($currNightsCutoff + max(0, $prevNightsFull - $prevNightsCutoff) * $nightsMultiplier);
        $potGiris = (int) round($currGirisCutoff + max(0, $prevGirisFull - $prevGirisCutoff) * $girisMultiplier);

        // Kıyaslama Tablosu Satırları
        // 1. Ort. Günlük Konaklayan Kişi
        $m1Curr = (int) round($currNightsCutoff / $cutoffDay);
        $m1Prev = (int) round($prevNightsCutoff / $cutoffDay);
        $m1PrevFull = (int) round($prevNightsFull / $numDays);
        $m1Pot = (int) round($potNights / $numDays);
        $d1 = $calcDiff((float) $m1Curr, (float) $m1Prev);
        $d1Pot = $calcDiff((float) $m1Pot, (float) $m1PrevFull);

        // 2. Toplam Kişi-Gece
        $m2Curr = $currNightsCutoff;
        $m2Prev = $prevNightsCutoff;
        $m2PrevFull = $prevNightsFull;
        $m2Pot = $potNights;
        $d2 = $calcDiff((float) $m2Curr, (float) $m2Prev);
        $d2Pot = $calcDiff((float) $m2Pot, (float) $m2PrevFull);

        // 3. Giriş Yapan Kişi
        $m3Curr = $currGirisCutoff;
        $m3Prev = $prevGirisCutoff;
        $m3PrevFull = $prevGirisFull;
        $m3Pot = $potGiris;
        $d3 = $calcDiff((float) $m3Curr, (float) $m3Prev);
        $d3Pot = $calcDiff((float) $m3Pot, (float) $m3PrevFull);

        // 4. Kesinleşen Pax
        $m4Curr = $currPaxCutoff;
        $m4Prev = $prevPaxCutoff;
        $m4PrevFull = $prevPaxFull;
        $m4Pot = $potPax;
        $d4 = $calcDiff((float) $m4Curr, (float) $m4Prev);
        $d4Pot = $calcDiff((float) $m4Pot, (float) $m4PrevFull);

        // 5. Ort. Grup Büyüklüğü
        $m5Curr = $currRezCutoff > 0 ? round($currPaxCutoff / $currRezCutoff, 1) : 0.0;
        $m5Prev = $prevRezCutoff > 0 ? round($prevPaxCutoff / $prevRezCutoff, 1) : 0.0;
        $m5PrevFull = $prevRezFull > 0 ? round($prevPaxFull / $prevRezFull, 1) : 0.0;
        $m5Pot = $m5Curr;
        $d5 = $calcDiff($m5Curr, $m5Prev);
        $d5Pot = $calcDiff($m5Pot, $m5PrevFull);

        // 6. Kişi Başı Ciro (ort.)
        $m6Curr = $currPaxCutoff > 0 ? round($currCiroCutoff / $currPaxCutoff) : 0.0;
        $m6Prev = $prevPaxCutoff > 0 ? round($prevCiroCutoff / $prevPaxCutoff) : 0.0;
        $m6PrevFull = $prevPaxFull > 0 ? round($prevCiroFull / $prevPaxFull) : 0.0;
        $m6Pot = $potPax > 0 ? round($potCiro / $potPax) : $m6Curr;
        $d6 = $calcDiff($m6Curr, $m6Prev);
        $d6Pot = $calcDiff($m6Pot, $m6PrevFull);

        // 7. Kişi Başı Kazanç (ort.)
        $m7Curr = $currPaxCutoff > 0 ? round($currKazancCutoff / $currPaxCutoff) : 0.0;
        $m7Prev = $prevPaxCutoff > 0 ? round($prevKazancCutoff / $prevPaxCutoff) : 0.0;
        $m7PrevFull = $prevPaxFull > 0 ? round($prevKazancFull / $prevPaxFull) : 0.0;
        $m7Pot = $m7Curr;
        $d7 = $calcDiff($m7Curr, $m7Prev);
        $d7Pot = $calcDiff($m7Pot, $m7PrevFull);

        // 8. Kesinleşen Ciro
        $m8Curr = $currCiroCutoff;
        $m8Prev = $prevCiroCutoff;
        $m8PrevFull = $prevCiroFull;
        $m8Pot = $potCiro;
        $d8 = $calcDiff($m8Curr, $m8Prev);
        $d8Pot = $calcDiff($m8Pot, $m8PrevFull);

        $comparisonTable = [
            [
                'metric' => 'Ort. Günlük Konaklayan Kişi',
                'currentCutoff' => number_format($m1Curr, 0, ',', '.') . ' kişi',
                'previousCutoff' => number_format($m1Prev, 0, ',', '.') . ' kişi',
                'fark' => $d1['formatted'],
                'isPositive' => $d1['isPositive'],
                'previousTotal' => number_format($m1PrevFull, 0, ',', '.') . ' kişi',
                'currentPotential' => number_format($m1Pot, 0, ',', '.') . ' kişi',
                'potentialDiff' => $d1Pot['formatted'],
                'isPotentialPositive' => $d1Pot['isPositive'],
            ],
            [
                'metric' => 'Toplam Kişi-Gece',
                'currentCutoff' => number_format($m2Curr, 0, ',', '.'),
                'previousCutoff' => number_format($m2Prev, 0, ',', '.'),
                'fark' => $d2['formatted'],
                'isPositive' => $d2['isPositive'],
                'previousTotal' => number_format($m2PrevFull, 0, ',', '.'),
                'currentPotential' => number_format($m2Pot, 0, ',', '.'),
                'potentialDiff' => $d2Pot['formatted'],
                'isPotentialPositive' => $d2Pot['isPositive'],
            ],
            [
                'metric' => 'Giriş Yapan Kişi',
                'currentCutoff' => number_format($m3Curr, 0, ',', '.') . ' kişi',
                'previousCutoff' => number_format($m3Prev, 0, ',', '.') . ' kişi',
                'fark' => $d3['formatted'],
                'isPositive' => $d3['isPositive'],
                'previousTotal' => number_format($m3PrevFull, 0, ',', '.') . ' kişi',
                'currentPotential' => number_format($m3Pot, 0, ',', '.') . ' kişi',
                'potentialDiff' => $d3Pot['formatted'],
                'isPotentialPositive' => $d3Pot['isPositive'],
            ],
            [
                'metric' => 'Kesinleşen Pax',
                'currentCutoff' => number_format($m4Curr, 0, ',', '.') . ' kişi',
                'previousCutoff' => number_format($m4Prev, 0, ',', '.') . ' kişi',
                'fark' => $d4['formatted'],
                'isPositive' => $d4['isPositive'],
                'previousTotal' => number_format($m4PrevFull, 0, ',', '.') . ' kişi',
                'currentPotential' => number_format($m4Pot, 0, ',', '.') . ' kişi',
                'potentialDiff' => $d4Pot['formatted'],
                'isPotentialPositive' => $d4Pot['isPositive'],
            ],
            [
                'metric' => 'Ort. Grup Büyüklüğü',
                'currentCutoff' => number_format($m5Curr, 1, ',', '.') . ' kişi',
                'previousCutoff' => number_format($m5Prev, 1, ',', '.') . ' kişi',
                'fark' => $d5['formatted'],
                'isPositive' => $d5['isPositive'],
                'previousTotal' => number_format($m5PrevFull, 1, ',', '.') . ' kişi',
                'currentPotential' => number_format($m5Pot, 1, ',', '.') . ' kişi',
                'potentialDiff' => $d5Pot['formatted'],
                'isPotentialPositive' => $d5Pot['isPositive'],
            ],
            [
                'metric' => 'Kişi Başı Ciro (ort.)',
                'currentCutoff' => '₺' . number_format($m6Curr, 0, ',', '.'),
                'previousCutoff' => '₺' . number_format($m6Prev, 0, ',', '.'),
                'fark' => $d6['formatted'],
                'isPositive' => $d6['isPositive'],
                'previousTotal' => '₺' . number_format($m6PrevFull, 0, ',', '.'),
                'currentPotential' => '₺' . number_format($m6Pot, 0, ',', '.'),
                'potentialDiff' => $d6Pot['formatted'],
                'isPotentialPositive' => $d6Pot['isPositive'],
            ],
            [
                'metric' => 'Kişi Başı Kazanç (ort.)',
                'currentCutoff' => '₺' . number_format($m7Curr, 0, ',', '.'),
                'previousCutoff' => '₺' . number_format($m7Prev, 0, ',', '.'),
                'fark' => $d7['formatted'],
                'isPositive' => $d7['isPositive'],
                'previousTotal' => '₺' . number_format($m7PrevFull, 0, ',', '.'),
                'currentPotential' => '₺' . number_format($m7Pot, 0, ',', '.'),
                'potentialDiff' => $d7Pot['formatted'],
                'isPotentialPositive' => $d7Pot['isPositive'],
            ],
            [
                'metric' => 'Kesinleşen Ciro',
                'currentCutoff' => '₺' . number_format($m8Curr, 0, ',', '.'),
                'previousCutoff' => '₺' . number_format($m8Prev, 0, ',', '.'),
                'fark' => $d8['formatted'],
                'isPositive' => $d8['isPositive'],
                'previousTotal' => '₺' . number_format($m8PrevFull, 0, ',', '.'),
                'currentPotential' => '₺' . number_format($m8Pot, 0, ',', '.'),
                'potentialDiff' => $d8Pot['formatted'],
                'isPotentialPositive' => $d8Pot['isPositive'],
            ],
        ];

        // Kümülatif Pax Büyümesi Eğrisi (Prev, Current ve Potansiyel)
        $cumPaxPrev = 0;
        $cumPaxCurr = 0;
        $cumulativePaxChart = [];

        for ($d = 1; $d <= $numDays; $d++) {
            $cumPaxPrev += (int) $dailyPrevious[$d]['kesinlesenPax'];

            if ($d <= $cutoffDay) {
                $cumPaxCurr += (int) $dailyCurrent[$d]['kesinlesenPax'];
                $potPaxDay = $cumPaxCurr;
                $realPaxVal = $cumPaxCurr;
            } else {
                $prevAddition = (int) $dailyPrevious[$d]['kesinlesenPax'];
                $potAddition = (int) round($prevAddition * $paxMultiplier);
                $potPaxDay = ($cumulativePaxChart[$d - 2]['potentialCumulative'] ?? $cumPaxCurr) + $potAddition;
                $realPaxVal = null; // Cutoff sonrası reel veri null
            }

            $cumulativePaxChart[] = [
                'day' => $d,
                'gun' => $d,
                'previousCumulative' => $cumPaxPrev,
                'currentCumulative' => $realPaxVal,
                'potentialCumulative' => $potPaxDay,
                'previousYear' => $prevYear,
                'currentYear' => $year,
            ];
        }

        $monthNames = [
            1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
            7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
        ];
        $monthTitle = $monthNames[$targetMonth] ?? 'Ay';

        $this->response->success([
            // 9 Özet Kart (Image 1)
            'cards' => $cards,

            // 4 Operasyonel Grafik (Image 2)
            'charts' => [
                'konaklayanMisafir' => $chartKonaklayan,
                'paxGrupBuyuklugu' => $chartPaxGrup,
                'kisiBasiCiroKazanc' => $chartKisiBasi,
                'haftalikMisafirYogunlugu' => $chartHaftalik,
            ],

            // Geçmiş Yıl Kıyaslaması ve Ay Sonu Pax Potansiyeli (Image 3)
            'comparison' => [
                'title' => 'Geçmiş Yıl Kıyaslaması ve Ay Sonu Pax Potansiyeli',
                'period' => "{$monthTitle} {$prevYear} vs {$year}",
                'subTitle' => "1-{$cutoffDay} {$monthTitle} arası reel gerçekleşme oranı baz alınarak kalan günlerin projeksiyonu hesaplanmıştır",
                'cutoffDay' => $cutoffDay,
                'numDays' => $numDays,
                'paxMultiplier' => round($paxMultiplier, 2),
                'note' => "Potansiyel Projeksiyon Formülü: {$year} yılı 1–{$cutoffDay}. gün reel pax verisine, {$prevYear} yılının kalan günleri bu yılın performans çarpanı (bu yıl ÷ geçen yıl aynı dönem) uygulanarak eklenmiştir.",
                'headers' => [
                    'metric' => 'Metrik',
                    'currentCutoff' => "{$year} (1-{$cutoffDay})",
                    'previousCutoff' => "{$prevYear} (1-{$cutoffDay})",
                    'diff' => 'Fark',
                    'previousTotal' => "{$prevYear} Ay Toplamı",
                    'currentPotential' => "{$year} Ay Sonu Potansiyeli",
                    'potentialDiff' => 'Potansiyel Fark',
                ],
                'table' => $comparisonTable,
                'cumulativePax' => $cumulativePaxChart,
            ],

            // Aktif Filtre Bilgisi
            'activeFilters' => [
                'year' => $year,
                'previousYear' => $prevYear,
                'months' => $months,
                'villas' => $villaIds,
                'site' => $siteId,
                'chartMonth' => $targetMonth,
                'chartDays' => $numDays,
                'cutoffDay' => $cutoffDay,
            ],
        ]);
    }

    /**
     * Aylık performans ve pax raporu filtre seçenekleri: villalar, yıllar ve aylar.
     *
     * @Get("monthlyperformance/filters")
     * @Get("paxperformance/filters")
     * @Get("pax/filters")
     * @query site int Site kimliği (varsayılan 1)
     */
    public function monthlyperformancefilters(): void
    {
        $pdo = $this->db->pdo();
        $villasList = $this->fetchVillasList($pdo);
        $currentYear = (int) date('Y');

        $this->response->success([
            'villas' => $villasList,
            'years' => [$currentYear + 1, $currentYear, $currentYear - 1, $currentYear - 2],
            'months' => [
                ['id' => 1, 'name' => 'Ocak'],
                ['id' => 2, 'name' => 'Şubat'],
                ['id' => 3, 'name' => 'Mart'],
                ['id' => 4, 'name' => 'Nisan'],
                ['id' => 5, 'name' => 'Mayıs'],
                ['id' => 6, 'name' => 'Haziran'],
                ['id' => 7, 'name' => 'Temmuz'],
                ['id' => 8, 'name' => 'Ağustos'],
                ['id' => 9, 'name' => 'Eylül'],
                ['id' => 10, 'name' => 'Ekim'],
                ['id' => 11, 'name' => 'Kasım'],
                ['id' => 12, 'name' => 'Aralık'],
            ],
        ]);
    }

    /**
     * @return array<int,array{id:int,name:string,region:string,code:string,roomCount:int}>
     */
    private function fetchVillasList(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('
                SELECT
                    h.id,
                    ISNULL(h.baslik, \'\') AS name,
                    ISNULL(h.gavelBelgeNo, \'\') AS code,
                    ISNULL(TRY_CONVERT(int, h.yatak_odasi), 0) AS roomCount,
                    d.baslik AS dest_name,
                    p.baslik AS parent_name,
                    gp.baslik AS grand_name
                FROM dbo.homes h
                LEFT JOIN dbo.destinations d ON d.id = h.emlak_bolgesi
                LEFT JOIN dbo.destinations p ON p.id = d.cat
                LEFT JOIN dbo.destinations gp ON gp.id = p.cat
                ORDER BY h.baslik ASC
            ');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $villas = [];
            foreach ($rows as $r) {
                $grand = trim((string) ($r['grand_name'] ?? ''));
                $parent = trim((string) ($r['parent_name'] ?? ''));
                $dest = trim((string) ($r['dest_name'] ?? ''));

                if ($grand !== '' && $parent !== '') {
                    $region = $grand . '/' . $parent;
                } elseif ($parent !== '' && $dest !== '') {
                    $region = $parent . '/' . $dest;
                } elseif ($dest !== '') {
                    $region = $dest;
                } elseif ($parent !== '') {
                    $region = $parent;
                } else {
                    $region = '';
                }

                $villas[] = [
                    'id' => (int) $r['id'],
                    'name' => trim((string) $r['name']),
                    'region' => $region,
                    'code' => trim((string) $r['code']),
                    'roomCount' => (int) $r['roomCount'],
                ];
            }
            return $villas;
        } catch (\Throwable $e) {
            try {
                $stmt = $pdo->query('SELECT h.id, ISNULL(h.baslik, \'\') AS name FROM dbo.homes h ORDER BY h.baslik ASC');
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                $villas = [];
                foreach ($rows as $r) {
                    $villas[] = [
                        'id' => (int) $r['id'],
                        'name' => trim((string) $r['name']),
                        'region' => '',
                        'code' => '',
                        'roomCount' => 0,
                    ];
                }
                return $villas;
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    private function hasFnTemizle(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fnTemizle') AND type in (N'FN', N'IF', N'TF', N'FS', N'FT')");
            return $stmt && (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasKisiBilgileriTable(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.kisi_bilgileri') AND type = 'U'");
            return $stmt && (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Parametre 'all', 'tumu', 'tum', '0', boş ya da belirtilmemişse boş dizi döner (tümü seçili demektir).
     *
     * @param mixed $raw
     * @return int[]
     */
    private function parseIntList($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            $list = [];
            foreach ($raw as $val) {
                $valStr = strtolower(trim((string) $val));
                if ($valStr === 'all' || $valStr === 'tumu' || $valStr === 'tum' || $valStr === '0' || $valStr === '') {
                    return []; // 'all' seçeneği içeriyorsa tümünü getir
                }
                if (is_numeric($val) && (int) $val > 0) {
                    $list[] = (int) $val;
                }
            }
            return array_values(array_unique($list));
        }

        $rawStr = strtolower(trim((string) $raw));
        if ($rawStr === 'all' || $rawStr === 'tumu' || $rawStr === 'tum' || $rawStr === '0' || $rawStr === '') {
            return [];
        }

        $parts = explode(',', (string) $raw);
        $list = [];
        foreach ($parts as $p) {
            $p = trim($p);
            $pLower = strtolower($p);
            if ($pLower === 'all' || $pLower === 'tumu' || $pLower === 'tum' || $pLower === '0' || $pLower === '') {
                return []; // İçinde 'all' varsa tümünü getir
            }
            if (is_numeric($p) && (int) $p > 0) {
                $list[] = (int) $p;
            }
        }

        return array_values(array_unique($list));
    }

    private function siteId(): int
    {
        foreach (['site', 'Site', 'site_id', 'SiteId', 'siteId'] as $key) {
            $value = $this->request->query($key) ?? $this->request->input($key);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return defined('PRICE_SITE') ? max(1, (int) constant('PRICE_SITE')) : 1;
    }

    private function formatShortNumber(float $num): string
    {
        $abs = abs($num);
        if ($abs >= 1000000) {
            return '₺' . number_format($num / 1000000, 2, ',', '.') . ' M';
        }
        if ($abs >= 1000) {
            return '₺' . number_format($num / 1000, 0, ',', '.') . ' B';
        }

        return '₺' . number_format($num, 0, ',', '.');
    }

    private function formatCurrency(float $num): string
    {
        return '₺' . number_format($num, 0, ',', '.');
    }

    private function deletedStatus(): int
    {
        return (int) ($this->app['reservation_deleted_status'] ?? 5);
    }

    private function normalizedStatusSql(string $column): string
    {
        $deleted = $this->deletedStatus();

        return $deleted === 5 ? $column : "(CASE WHEN {$column} = {$deleted} THEN 5 ELSE {$column} END)";
    }
}
