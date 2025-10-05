<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Str;

class ExcelUploadController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'excel_file'  => ['required','file','mimes:xlsx,xls'],
            'format_type' => ['required','in:1,2,3,4,5,6'],
        ],[
            'excel_file.required' => 'Please upload an Excel file.',
            'excel_file.mimes'    => 'Excel must be .xlsx or .xls',
            'format_type.required'=> 'Please select a format (1–6).',
        ]);

        $uploaded   = $request->file('excel_file');
        $tempPath   = $uploaded->getRealPath();
        $clientName = $uploaded->getClientOriginalName();

        try {
            if (!$tempPath || !file_exists($tempPath)) {
                return back()->with('error', 'Uploaded file temporary path not found.')->withInput();
            }

            $spreadsheet = IOFactory::load($tempPath);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = $sheet->toArray(null, true, true, true);

            $format = (int) $request->input('format_type');

            $parsed = $this->parseByFormat($rows, $format);
            if (empty($parsed['header']) || empty($parsed['details'])) {
                return back()->with('error', "Parsed data is empty for Format {$format}. Please check the template/mapping.")
                             ->withInput();
            }

            $datContent = $this->buildDatByFormat($parsed, $format);

            // Nama file: {TIN}S{MM}{YYYY}.DAT (mengikuti sample saat ini)
            $ownerTin = preg_replace('/[^0-9]/', '', ($parsed['header']['tin'] ?? 'TIN'));
            $period   = $parsed['header']['period_end'] ?? '01/01/1970'; // mm/dd/YYYY
            $ts       = strtotime($period);
            $mm       = $ts ? date('m', $ts) : '01';
            $yyyy     = $ts ? date('Y', $ts) : '1970';

            $code = match ($format) {
                1 => 'S',   // Sales
                2 => 'P',   // Purchases
                3 => 'F3',
                4 => 'F4',
                5 => 'F5',
                6 => 'F6',
                default => throw new \RuntimeException("Unknown format {$format}."),
            };

            $fileName = sprintf('%s%s%s%s.DAT', $ownerTin, $code, $mm, $yyyy);

            ActivityLog::create([
                'user'    => Auth::check() ? Auth::user()->username : 'guest',
                'action'  => "Generate DAT (Format {$format})",
                'details' => 'Generated file: '.$fileName.' from '.$clientName,
            ]);

            return response()->streamDownload(
                function () use ($datContent) { echo $datContent; },
                $fileName,
                [
                    'Content-Type'        => 'text/plain; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
                    'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
                ]
            );

        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Failed converting Excel to .DAT: '.$e->getMessage())->withInput();
        }
    }

    /* =========================
     *  DISPATCHERS (per-format)
     * ========================= */

    private function parseByFormat(array $rows, int $format): array
    {
        return match ($format) {
            1 => $this->parseFormat1($rows),
            2 => $this->parseFormat2($rows),
            3 => $this->parseFormat3($rows),
            4 => $this->parseFormat4($rows),
            5 => $this->parseFormatNotImplemented(5),
            6 => $this->parseFormatNotImplemented(6),
            default => throw new \RuntimeException("Unknown format {$format}."),
        };
    }

    private function buildDatByFormat(array $parsed, int $format): string
    {
        return match ($format) {
            1 => $this->buildDatContentFormat1($parsed),
            2 => $this->buildDatContentFormat2($parsed),
            3 => $this->buildDatContentFormat3($parsed),
            4 => $this->buildDatContentFormat4($parsed),
            5 => $this->buildDatNotImplemented(5),
            6 => $this->buildDatNotImplemented(6),
            default => throw new \RuntimeException("Unknown format {$format}."),
        };
    }

    private function parseFormatNotImplemented(int $format): array
    {
        throw new \RuntimeException("Parser for Format {$format} is not implemented yet. Please upload the sample Excel and target .DAT mapping.");
    }

    private function buildDatNotImplemented(int $format): string
    {
        throw new \RuntimeException("Builder for Format {$format} is not implemented yet.");
    }

    /* =========================
     *  FORMAT 1 (sales)
     * ========================= */

    private function parseFormat1(array $rows): array
    {
        $tin = null;
        $registeredName = null;
        $tradeName = null;
        $addr1 = null;
        $addr2 = null;

        $rowCount = count($rows);
        for ($idx = 1; $idx <= $rowCount; $idx++) {
            $cellA = trim((string)($rows[$idx]['A'] ?? ''));
            if ($cellA === '') continue;

            if (Str::startsWith($cellA, 'TIN')) {
                $digits = preg_replace('/[^0-9]/', '', $cellA);
                $tin = substr($digits, 0, 9);
            } elseif (Str::startsWith($cellA, "OWNER'S NAME")) {
                $registeredName = trim(str_replace("OWNER'S NAME:", '', $cellA));
            } elseif (Str::startsWith($cellA, "OWNER'S TRADE NAME")) {
                $tradeName = trim(str_replace("OWNER'S TRADE NAME :", '', $cellA));
            } elseif (Str::startsWith($cellA, "OWNER'S ADDRESS")) {
                $addr1 = trim(str_replace("OWNER'S ADDRESS:", '', $cellA));

                $nextA = trim((string)($rows[$idx+1]['A'] ?? ''));
                if ($nextA !== '' && !$this->looksLikeHeaderLabel($nextA)) {
                    $addr2 = $nextA;
                }
            }
        }

        $details = [];
        $firstPeriodEnd = null;
        $started = false;

        foreach ($rows as $r) {
            $a = trim((string)($r['A'] ?? ''));
            $b = trim((string)($r['B'] ?? ''));
            $c = trim((string)($r['C'] ?? ''));
            $d = trim((string)($r['D'] ?? ''));
            $e = trim((string)($r['E'] ?? ''));
            $f = (string)($r['F'] ?? '');
            $g = (string)($r['G'] ?? '');
            $h = (string)($r['H'] ?? '');
            $i = (string)($r['I'] ?? '');
            $j = (string)($r['J'] ?? '');

            if (!$started) {
                if ($this->isDateToken($a) && $this->isTinLike($b)) {
                    $started = true;
                } else {
                    continue;
                }
            }

            if (!$this->isDateToken($a)) {
                $upperA = strtoupper($a);
                if (str_contains($upperA, 'END OF REPORT')) {
                    break;
                }
                continue;
            }

            $hasAnyAmount = ($this->hasNumber($g) || $this->hasNumber($h) || $this->hasNumber($i) || $this->hasNumber($j));
            if (!$hasAnyAmount && $c === '' && $d === '' && $e === '') {
                continue;
            }

            if ($firstPeriodEnd === null) {
                $firstPeriodEnd = $this->excelDateToUS($a);
            }
            $periodEnd = $firstPeriodEnd;

            $name = ($c !== '') ? $c : $d;
            [$addr1D, $addr2D] = $this->splitAddressFromE($e);

            $details[] = [
                'customer_tin'  => preg_replace('/[^0-9]/','', $b),
                'customer_name' => $name,
                'addr1'         => $addr1D,
                'addr2'         => $addr2D,
                'gross_sales'   => $this->toDec($f),
                'exempt'        => $this->toDec($g),
                'zero_rated'    => $this->toDec($h),
                'taxable'       => $this->toDec($i),
                'output_tax'    => $this->toDec($j),
                'owner_tin'     => $tin,
                'period_end'    => $periodEnd,
            ];
        }

        $sumgross       = array_sum(array_column($details, 'gross_sales'));
        $sumOutputTax   = array_sum(array_column($details, 'output_tax'));
        $sumExempt      = array_sum(array_column($details, 'exempt'));
        $sumZeroRated   = array_sum(array_column($details, 'zero_rated'));
        $sumTaxable     = array_sum(array_column($details, 'taxable'));

        return [
            'header' => [
                'tin'               => $tin ?: '000000000',
                'registered_name'   => $registeredName ?: 'REGISTERED NAME',
                'trade_name'        => $tradeName ?: ($registeredName ?: 'TRADE NAME'),
                'addr1'             => $addr1 ?: '',
                'addr2'             => $addr2 ?: '',
                'exempt_total'      => round($sumExempt, 2),
                'zero_rated_total'  => round($sumZeroRated, 2),
                'taxable_total'     => round($sumTaxable, 2),
                'gross_total'       => round($sumgross, 2),
                'output_tax_total'  => round($sumOutputTax, 2),
                'col15'             => '051',
                'period_end'        => $firstPeriodEnd ?: '01/01/1970',
                'col17'             => '12',
            ],
            'details' => $details,
        ];
    }

    private function buildDatContentFormat1(array $parsed): string
    {
        $h = $parsed['header'];
        $lines = [];

        $headerRow = [
            'H','S',
            $this->q($h['tin']),
            $this->q($h['registered_name']),
            $this->q(''), $this->q(''), $this->q(''),
            $this->q($h['trade_name']),
            $this->q($h['addr1']),
            $this->q($h['addr2']),
            $this->n($h['exempt_total']),
            $this->n($h['zero_rated_total']),
            $this->n($h['taxable_total']),
            $this->n($h['output_tax_total']),
            $h['col15'],
            $h['period_end'],
            $h['col17'],
        ];
        $lines[] = implode(',', $headerRow);

        foreach ($parsed['details'] as $d) {
            $detailRow = [
                'D','S',
                $this->q($d['customer_tin']),
                $this->q($d['customer_name']),
                '', '', '',
                $this->q($d['addr1']),
                $this->q($d['addr2']),
                $this->n($d['exempt']),
                $this->n($d['zero_rated']),
                $this->n($d['taxable']),
                $this->n($d['output_tax']),
                preg_replace('/[^0-9]/','', $d['owner_tin']),
                $d['period_end'],
            ];
            $lines[] = implode(',', $detailRow);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /* =========================
     *  FORMAT 2 (purchase)
     * ========================= */
    private function parseFormat2(array $rows): array
    {
        $tin = null;
        $registeredName = null;
        $tradeName = null;
        $addr1 = null;
        $addr2 = null;

        // Header owner name (kolom 5–7) dikosongkan saja
        $ownerLast = '';
        $ownerFirst = '';
        $ownerMiddle = '';

        // === HEADER ===
        foreach ($rows as $r) {
            $a = (string)($r['A'] ?? '');
            if ($a === '') continue;

            if (Str::startsWith($a, 'TIN')) {
                $digits = preg_replace('/[^0-9]/', '', $a);
                $tin = substr($digits, 0, 9);
            } elseif (Str::startsWith($a, "OWNER'S NAME")) {
                $raw = str_replace("OWNER'S NAME:", '', $a);
                // Hapus koma/petik/&/Ñ, dsb (pastikan sanitizeName tersedia)
                $registeredName = $this->sanitizeName(ltrim($raw));
            } elseif (Str::startsWith($a, "OWNER'S TRADE NAME")) {
                $rawTrade  = str_replace("OWNER'S TRADE NAME :", '', $a);
                $tradeName = $this->sanitizeName(ltrim($rawTrade));
            } elseif (Str::startsWith($a, "OWNER'S ADDRESS")) {
                $rawAddr = str_replace("OWNER'S ADDRESS:", '', $a);
                // Jaga spasi kiri seperti sumber, tapi tetap sanitize (alamat boleh koma)
                $addr1   = ltrim($this->sanitizeAddress($rawAddr));
                $addr2   = '  '; // contoh di sample ada dua spasi
            }
        }

        // === DETAILS ===
        $details = [];
        $firstPeriodEnd = null;
        $started = false;

        foreach ($rows as $r) {
            $a = trim((string)($r['A'] ?? '')); // Taxable Month / Date
            $b = trim((string)($r['B'] ?? '')); // Supplier TIN
            $c = trim((string)($r['C'] ?? '')); // Registered Name
            $d = trim((string)($r['D'] ?? '')); // Supplier Name
            $e = (string)($r['E'] ?? '');       // Address

            // Kolom angka (F..N)
            $f = (string)($r['F'] ?? ''); // gross
            $g = (string)($r['G'] ?? ''); // exempt
            $h = (string)($r['H'] ?? ''); // zero-rated
            $i = (string)($r['I'] ?? ''); // taxable
            $j = (string)($r['J'] ?? ''); // services
            $k = (string)($r['K'] ?? ''); // capital goods
            $l = (string)($r['L'] ?? ''); // other goods
            $m = (string)($r['M'] ?? ''); // input tax
            $n = (string)($r['N'] ?? ''); // gross taxable

            if (!$started) {
                if ($this->isDateToken($a) && $this->isTinLike($b)) {
                    $started = true;
                } else {
                    continue;
                }
            }

            if (!$this->isDateToken($a)) {
                if (str_contains(strtoupper($a), 'END OF REPORT')) break;
                continue;
            }

            if ($firstPeriodEnd === null) {
                $firstPeriodEnd = $this->excelDateToUS($a); // mm/dd/YYYY
            }

            // Nama supplier: prioritas registered name (C), fallback ke D — sanitize
            $supplierName = ($c !== '') ? $this->sanitizeName($c) : $this->sanitizeName($d);

            // Address: split ringan lalu sanitize tiap bagian
            [$addr1D, $addr2D] = $this->splitAddressFromE($e);
            $addr1D = $this->sanitizeAddress($addr1D);
            $addr2D = $this->sanitizeAddress($addr2D);

            $details[] = [
                'supplier_tin'  => preg_replace('/[^0-9]/', '', $b),
                'supplier_name' => $supplierName,
                'addr1'         => $addr1D,
                'addr2'         => $addr2D,
                'gross'         => $this->toDec($f),
                'exempt'        => $this->toDec($g),
                'zero_rated'    => $this->toDec($h),
                'taxable'       => $this->toDec($i),
                'services'      => $this->toDec($j),
                'capital_goods' => $this->toDec($k),
                'other_goods'   => $this->toDec($l),
                'input_tax'     => $this->toDec($m),
                'gross_taxable' => $this->toDec($n),
                'owner_tin'     => $tin,
                'period_end'    => $firstPeriodEnd,
            ];
        }

        // === HEADER TOTALS (sesuaikan agar lolos validator) ===
        $sum = fn(string $key) => array_sum(array_column($details, $key));

        return [
            'header' => [
                'tin'             => $tin ?: '000000000',
                'registered_name' => $registeredName ?: '',
                'owner_last'      => $ownerLast,
                'owner_first'     => $ownerFirst,
                'owner_middle'    => $ownerMiddle,
                'blank8'          => '',
                'addr1'           => $addr1 ?? '',
                'addr2'           => $addr2 ?? '  ',

                // >>>>>>> Mapping totals header yang dicek validator <<<<<<<
                // Exempt
                'c11' => $sum('exempt'),
                // Zero Rated
                'c12' => $sum('zero_rated'),
                // Taxable (kalau template pakai ini)
                'c13' => $sum('services'),
                // Capital Goods (aman jika diperlukan)
                'c14' => $sum('capital_goods'),
                // Services (Taxable Services)
                'c15' => $sum('other_goods'),
                // Goods Other Than Capital Goods
                'c16' => $sum('input_tax'),
                // Input Tax
                'c17' => $sum('input_tax'),
                // Gross Taxable (atau 0 jika tidak dipakai)
                'c18' => $sum('gross_taxable'),

                'code'       => '000',
                'period_end' => $firstPeriodEnd ?: '01/01/1970',
                'col21'      => '12',
            ],
            'details' => $details,
        ];
    }

    private function buildDatContentFormat2(array $parsed): string
    {
        $h = $parsed['header'];
        $lines = [];

        // === HEADER (semua di-quote) ===
        $headerRow = [
            $this->q('H'),
            $this->q('P'),
            $this->q($h['tin']),
            $this->q($h['registered_name']),
            $this->q($h['owner_last'] ?? ''),
            $this->q($h['owner_first'] ?? ''),
            $this->q($h['owner_middle'] ?? ''),
            $this->q($h['blank8'] ?? ''),
            $this->q($h['addr1'] ?? ''),
            $this->q($h['addr2'] ?? ''),
            $this->q($this->n($h['c11'] ?? 0)), // Exempt
            $this->q($this->n($h['c12'] ?? 0)), // Zero Rated
            $this->q($this->n($h['c13'] ?? 0)), // Services
            $this->q($this->n($h['c14'] ?? 0)), // Capital Goods
            $this->q($this->n($h['c15'] ?? 0)), // Other Goods
            $this->q($this->n($h['c16'] ?? 0)), // Input Tax
            $this->q($this->n($h['c17'] ?? 0)), // Input Tax
            $this->q($this->n(0)),
            $this->q($h['code']),
            $this->q($h['period_end']),
            $this->q($h['col21']),
        ];
        $lines[] = implode(',', $headerRow);

        // === DETAIL ===
        foreach ($parsed['details'] as $d) {
            // Ambil dari parser “versi benar”: services & input_tax
            // (fallback jika kamu masih pakai r14/r15)
            $exempt  = array_key_exists('exempt', $d)   ? $d['exempt']   : 0;
            $zeroRated  = array_key_exists('zero_rated', $d)   ? $d['zero_rated']   : 0;
            $capitalGoods  = array_key_exists('capital_goods', $d)   ? $d['capital_goods']   : 0;
            $otherGoods  = array_key_exists('other_goods', $d)   ? $d['other_goods']   : 0;

            $services  = array_key_exists('services', $d)   ? $d['services']   : ($d['r14'] ?? 0);
            $inputTax  = array_key_exists('input_tax', $d)  ? $d['input_tax']  : ($d['r15'] ?? 0);


            $detailRow = [
                $this->q('D'),
                $this->q('P'),
                $this->q($d['supplier_tin']),
                $this->q($d['supplier_name']),
                $this->q(''), // kolom 5
                $this->q(''), // kolom 6
                $this->q(''), // kolom 7
                $this->q($d['addr1']),
                $this->q($d['addr2']),
                // kolom 10..13 = 0
                $this->q($this->n($exempt)),
                $this->q($this->n($zeroRated)),
                $this->q($this->n($services)),
                $this->q($this->n($capitalGoods)),
                // kolom 14 = Services, 15 = Input Tax
                $this->q($this->n($otherGoods)),
                $this->q($this->n($inputTax)),
                // owner TIN & tanggal
                $this->q(preg_replace('/[^0-9]/','', $d['owner_tin'])),
                $this->q($d['period_end']),
                // 4 kolom kosong
                $this->q(''),
                $this->q(''),
                $this->q(''),
                $this->q(''),
            ];
            $lines[] = implode(',', $detailRow);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /* =========================
     *  FORMAT 3 (1702Q)
     * ========================= */
    private function parseFormat3(array $rows): array
    {
        // 1) Ambil bulan global dari teks "FOR THE MONTH ..."
        $forMonthToken = $this->extractGlobalMonthToken($rows); // -> "MM/YYYY"

        // 2) Header: TIN & PAYEE NAME dari baris deskriptif (kolom A)
        $tinHeader = null;
        $payeeName = '';
        foreach ($rows as $r) {
            $a = (string)($r['A'] ?? '');
            if ($a === '') continue;
            $up = strtoupper($a);

            if (Str::startsWith($up, 'TIN')) {
                $digits = preg_replace('/[^0-9]/', '', $a);
                $tinHeader = $this->tinBase9($digits);
            } elseif (str_contains($up, 'PAYEE') && str_contains($up, 'NAME')) {
                $raw = trim(preg_replace('/^.*?:/','', $a));
                $payeeName = $this->sanitizeName($raw);
            }
        }
        if (!$tinHeader) $tinHeader = '000000000';

        // 3) Baca tabel detail A..I (sesuai struktur yang lo kasih)
        // A: SEQ NO
        // B: TAXPAYER IDENTIFICATION NUMBER (000-412-382-0000)
        // C: CORPORATION (Registered Name)
        // E: ATC CODE
        // G: AMOUNT OF INCOME PAYMENT (format EU "30.000,00")
        // H: TAX RATE (format EU "2,00")
        // I: AMOUNT OF TAX WITHHELD (format EU "600,00")
        $groups = [];         // key = tin|corp|atc|rate  -> sums
        $order  = [];         // preserve first-seen order for SEQ

        foreach ($rows as $r) {
            $seqRaw  = (string)($r['A'] ?? '');
            $tinRaw  = (string)($r['B'] ?? '');
            $corpRaw = (string)($r['C'] ?? '');
            $atcRaw  = (string)($r['E'] ?? '');
            $amtRaw  = (string)($r['G'] ?? '');
            $rateRaw = (string)($r['H'] ?? '');
            $whRaw   = (string)($r['I'] ?? '');
            $nature  = (string)($r['F'] ?? '');

            // Skip baris sampah/header/pemisah
            if ($this->isRowJunk($seqRaw, $tinRaw, $corpRaw, $atcRaw, $amtRaw, $rateRaw, $whRaw)) continue;

            // Minimal: ada TIN atau ada angka
            $hasTin     = (bool)preg_match('/\d/', $tinRaw);
            $hasNumbers = $this->hasNumber($amtRaw) || $this->hasNumber($whRaw) || $this->hasNumber($rateRaw);
            if (!$hasTin && !$hasNumbers) continue;

            $tinBase = $this->tinBase9($tinRaw);           // 9 digit (tanpa 0000)
            $corp    = $this->sanitizeName($corpRaw);
            $atc     = strtoupper(trim($atcRaw));
            $rate    = $this->toDec($rateRaw);
            $amount  = $this->toDec($amtRaw);
            $wh      = $this->toDec($whRaw);


            $hasTotalWord = preg_match('/\bTOTAL\b/i', $corpRaw.' '.$nature.' '.$atcRaw.' '.$seqRaw) === 1;
            if ($tinBase === '000000000' || $hasTotalWord || ($atc === '' && trim($corp) === '' && $amount == 0.0 && $wh > 0.0)) {
                continue;
            }

            // Group per (TIN, CORP, ATC, RATE)
            $key = implode('|', [$tinBase, $corp, $atc, number_format($rate, 2, '.', '')]);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'tin'      => $tinBase,
                    'corp'     => $corp,
                    'atc'      => $atc,
                    'taxrate'  => $rate,
                    'amount'   => 0.0,
                    'withheld' => 0.0,
                ];
                $order[] = $key; // simpan urutan kemunculan pertama
            }
            $groups[$key]['amount']   += $amount;
            $groups[$key]['withheld'] += $wh;
        }

        // 4) Bangun detail terurut + penomoran SEQ 1..n
        $details = [];
        $seq = 1;
        foreach ($order as $key) {
            $g = $groups[$key];
            $details[] = [
                'seq'       => $seq++,
                'tin'       => $g['tin'],
                'corp'      => $g['corp'],
                'month'     => $forMonthToken,           // semua ikut header
                'atc'       => $g['atc'],
                'taxrate'   => $g['taxrate'],
                'amount'    => $g['amount'],
                'withheld'  => $g['withheld'],
            ];
        }

        // 5) Totals untuk CSAWT
        $sumAmount   = array_sum(array_column($details, 'amount'));
        $sumWithheld = array_sum(array_column($details, 'withheld'));

        return [
            'header' => [
                'tin'       => $tinHeader,
                'payee'     => $payeeName,
                'month'     => $forMonthToken,
                'fixed0000' => '0000',
                'code043'   => '043',
            ],
            'details' => $details,
            'control' => [
                'tin'        => $tinHeader,
                'fixed0000'  => '0000',
                'month'      => $forMonthToken,
                'sum_amount' => $sumAmount,
                'sum_wh'     => $sumWithheld,
            ],
        ];
    }


    private function buildDatContentFormat3(array $parsed): string
    {
        $out = [];

        // HEADER
        $h = $parsed['header'];
        $out[] = implode(',', [
            'HSAWT',
            'H1702Q',
            $h['tin'],
            '0000',
            $this->q($h['payee']),
            $this->q(''),
            $this->q(''),
            $this->q(''),
            $h['month'],
            '043',
        ]);

        // DETAIL (hasil grouping)
        foreach ($parsed['details'] as $d) {
            $out[] = implode(',', [
                'DSAWT',
                'D1702Q',
                (string)$d['seq'],
                $d['tin'],
                '0000',
                $this->q($d['corp']),
                $this->q(''),
                $this->q(''),
                $this->q(''),
                $this->q(''),
                $parsed['header']['month'], // paksa sama dg header
                $this->q(''),
                $d['atc'],
                $this->n($d['taxrate']),
                $this->n($d['amount']),
                $this->n($d['withheld']),
            ]);
        }

        // CONTROL
        $c = $parsed['control'];
        $out[] = implode(',', [
            'CSAWT',
            'C1702Q',
            $c['tin'],
            '0000',
            $parsed['header']['month'],
            $this->n($c['sum_amount']),
            $this->n($c['sum_wh']),
        ]);

        return implode("\r\n", $out) . "\r\n";
    }


    /* =========================
     *  FORMAT 4 (1701Q)
     * ========================= */

    private function parseFormat4(array $rows): array
    {
        // 1) Bulan global dari "FOR THE MONTH ..."
        $forMonthToken = $this->extractGlobalMonthToken($rows); // "MM/YYYY"

        // 2) Ambil TIN & PAYEE'S NAME dari SELURUH sheet
        $tinHeader  = null;
        $lastName   = '';
        $firstName  = '';
        $middleName = '';

        // cari TIN
        foreach ($rows as $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                if (stripos($cell, 'TIN') === 0) {
                    $digits = preg_replace('/[^0-9]/', '', $cell);
                    $tinHeader = $this->tinBase9($digits); // 9 digit base
                    break 2;
                }
            }
        }
        if (!$tinHeader) $tinHeader = '000000000';

        // cari PAYEE'S NAME dan pecah sederhana
        foreach ($rows as $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                if (stripos($cell, "PAYEE'S NAME") !== false || stripos($cell, 'PAYEES NAME') !== false) {
                    // ambil bagian setelah tanda ':'
                    $after = trim(preg_replace('/^.*?:/i', '', $cell));

                    // normalisasi sederhana: ASCII, hapus tanda baca, sisakan huruf & spasi, rapikan spasi
                    if (class_exists('\Normalizer')) {
                        $after = \Normalizer::normalize($after, \Normalizer::FORM_C);
                    }
                    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $after);
                    if ($ascii !== false) $after = $ascii;

                    // buang koma, petik, kurung, tanda baca; sisakan huruf & spasi
                    $after = preg_replace('/[^A-Za-z\s]/', ' ', $after);
                    // rapikan spasi ganda
                    $after = preg_replace('/\s+/', ' ', trim($after));

                    // split: LAST FIRST MIDDLE...
                    $parts = $after === '' ? [] : explode(' ', $after);
                    $lastName   = $parts[0] ?? '';
                    $firstName  = $parts[1] ?? '';
                    $middleName = count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : '';

                    // selesai
                    break 2;
                }
            }
        }

        // 3) Baca tabel detail A..I seperti format 3
        $groups = [];
        $order  = [];

        foreach ($rows as $r) {
            $seqRaw  = (string)($r['A'] ?? '');
            $tinRaw  = (string)($r['B'] ?? '');
            $corpRaw = (string)($r['C'] ?? '');
            $atcRaw  = (string)($r['E'] ?? '');
            $amtRaw  = (string)($r['G'] ?? '');
            $rateRaw = (string)($r['H'] ?? '');
            $whRaw   = (string)($r['I'] ?? '');
            $nature  = (string)($r['F'] ?? '');

            // filter header/pemisah/garis
            $lineGlue = strtoupper(trim($seqRaw.' '.$tinRaw.' '.$corpRaw.' '.$atcRaw.' '.$amtRaw.' '.$rateRaw.' '.$whRaw.' '.$nature));
            if ($lineGlue === '' || str_contains($lineGlue, '----------------')) continue;
            if (str_contains($lineGlue, 'SEQ') && str_contains($lineGlue, 'TAXPAYER')) continue;
            if (preg_match('/\(\s*[1-8]\s*\)/', $lineGlue)) continue;

            $hasTin     = (bool)preg_match('/\d/', $tinRaw);
            $hasNumbers = $this->hasNumber($amtRaw) || $this->hasNumber($whRaw) || $this->hasNumber($rateRaw);
            if (!$hasTin && !$hasNumbers) continue;

            $tinBase = $this->tinBase9($tinRaw);
            $corp    = $this->sanitizeName($corpRaw); // pake sanitizer kamu untuk nama perusahaan
            $atc     = strtoupper(trim($atcRaw));
            $rate    = $this->toDec($rateRaw);
            $amount  = $this->toDec($amtRaw);
            $wh      = $this->toDec($whRaw);

            // skip GRAND TOTAL / agregat nyasar
            $hasTotalWord = preg_match('/\bTOTAL\b/i', $corpRaw.' '.$nature.' '.$atcRaw.' '.$seqRaw) === 1;
            if ($tinBase === '000000000' || $hasTotalWord || ($atc === '' && trim($corp) === '' && $amount == 0.0 && $wh > 0.0)) {
                continue;
            }

            $key = implode('|', [$tinBase, $corp, $atc, number_format($rate, 2, '.', '')]);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'tin'      => $tinBase,
                    'corp'     => $corp,
                    'atc'      => $atc,
                    'taxrate'  => $rate,
                    'amount'   => 0.0,
                    'withheld' => 0.0,
                ];
                $order[] = $key;
            }
            $groups[$key]['amount']   += $amount;
            $groups[$key]['withheld'] += $wh;
        }

        // 4) susun detail + numbering
        $details = [];
        $seq = 1;
        foreach ($order as $k) {
            $g = $groups[$k];
            $details[] = [
                'seq'       => $seq++,
                'tin'       => $g['tin'],
                'corp'      => $g['corp'],
                'month'     => $forMonthToken,
                'atc'       => $g['atc'],
                'taxrate'   => $g['taxrate'],
                'amount'    => $g['amount'],
                'withheld'  => $g['withheld'],
            ];
        }

        // 5) totals
        $sumAmount   = array_sum(array_column($details, 'amount'));
        $sumWithheld = array_sum(array_column($details, 'withheld'));

        return [
            'header' => [
                'tin'        => $tinHeader,
                'blank_col5' => '',      // tetap output "" di kolom 5 header
                'last'       => $lastName,
                'first'      => $firstName,
                'middle'     => $middleName,
                'month'      => $forMonthToken,
                'fixed0000'  => '0000',
                'code041'    => '041',
            ],
            'details' => $details,
            'control' => [
                'tin'        => $tinHeader,
                'fixed0000'  => '0000',
                'month'      => $forMonthToken,
                'sum_amount' => $sumAmount,
                'sum_wh'     => $sumWithheld,
            ],
        ];
    }



    private function buildDatContentFormat4(array $parsed): string
    {
        $out = [];

        // HEADER (HSAWT,H1701Q,TIN,0000,"",LAST,FIRST,MIDDLE,MM/YYYY,041)
        $h = $parsed['header'];
        $out[] = implode(',', [
            'HSAWT',
            'H1701Q',
            $h['tin'],
            '0000',
            $this->q($h['blank_col5'] ?? ''), // kolom 5 kosong tapi di-quote
            $this->q($h['last']   ?? ''),
            $this->q($h['first']  ?? ''),
            $this->q($h['middle'] ?? ''),
            $h['month'],
            '041',
        ]);

        // DETAIL (DSAWT,D1701Q,SEQ,TIN,0000,"CORP",,,,MM/YYYY,,ATC,TAXRATE,AMOUNT,WITHHELD)
        foreach ($parsed['details'] as $d) {
            $out[] = implode(',', [
                'DSAWT',
                'D1701Q',
                (string)$d['seq'],
                $d['tin'],
                '0000',
                $this->q($d['corp']),
                $this->q(''),
                $this->q(''),
                $this->q(''),
                $this->q(''),
                $h['month'],          // samakan semua dgn header
                $this->q(''),
                $d['atc'],
                $this->n($d['taxrate']),
                $this->n($d['amount']),
                $this->n($d['withheld']),
            ]);
        }

        // CONTROL (CSAWT,C1701Q,TIN,0000,MM/YYYY,sum amount,sum withheld)
        $c = $parsed['control'];
        $out[] = implode(',', [
            'CSAWT',
            'C1701Q',
            $c['tin'],
            '0000',
            $h['month'],
            $this->n($c['sum_amount']),
            $this->n($c['sum_wh']),
        ]);

        return implode("\r\n", $out) . "\r\n";
    }




    /* =============
     *  Helpers
     * ============= */

    private function q($v): string
    {
        $v = (string)$v;
        return '"'.str_replace('"','""', trim($v)).'"';
    }

    private function n($v): string
    {
        return number_format((float)$v, 2, '.', '');
    }

    private function toDec($v): float
    {
        $s = (string)$v;
        $s = trim($s);
        if ($s === '' || $s === '-' || $s === '.') return 0.0;

        $isParenNeg = false;
        if (preg_match('/^\s*\(.*\)\s*$/', $s)) {
            $isParenNeg = true;
            $s = trim($s, " ()");
        }

        // buang huruf/simbol kecuali digit, koma, titik, minus
        $s = preg_replace('/[^\d\-,\.]/', '', $s);
        // hilangkan pemisah ribuan koma
        $s = str_replace(',', '', $s);

        if ($s === '' || $s === '-' || $s === '.') return 0.0;

        $num = (float)$s;
        if ($isParenNeg) $num = -$num;

        return $num;
    }


    private function isTinLike(string $s): bool
    {
        $s = str_replace(['-',' '], '', $s);
        return ctype_digit($s) && strlen($s) >= 9;
    }

    private function hasNumber($s): bool
    {
        return preg_match('/\d/', (string)$s) === 1;
    }

    private function isDateToken(string $s): bool
    {
        $s = trim($s);
        if ($s === '') return false;
        if (is_numeric($s)) return true; // Excel serial
        if (preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}(?:\s+\d{1,2}:\d{2}:\d{2})?$#', $s)) return true;
        if (preg_match('#^\d{4}-\d{2}-\d{2}#', $s)) return true;
        return false;
    }

    private function excelDateToUS($value): string
    {
        if (is_numeric($value)) {
            $unix = ((int)$value - 25569) * 86400;
            return gmdate('m/d/Y', $unix);
        }
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('m/d/Y', $ts);
        }
        return trim((string)$value);
    }

    private function looksLikeHeaderLabel(string $text): bool
    {
        $s = strtoupper(trim($text));
        $keywords = [
            'RECONCILIATION OF LISTING FOR ENFORCEMENT',
            'TIN', "OWNER'S NAME", "OWNER'S TRADE NAME", "OWNER'S ADDRESS",
            'TAXABLE', 'TAXPAYER', 'IDENTIFICATION', 'NUMBER',
            'REGISTERED NAME', 'ADDRESS', 'MONTH',
            'END OF REPORT',
        ];
        foreach ($keywords as $kw) {
            if (str_starts_with($s, $kw)) return true;
        }
        if (preg_match('/^\(\d+\)$/', $s)) return true;
        return false;
    }

    private function splitAddressFromE(string $e): array
    {
        $e = trim($e);
        if ($e === '') return ['', ''];

        $partsComma = preg_split('/\s*,\s*/', $e);
        if (count($partsComma) >= 2) {
            return [trim($partsComma[0]), trim(implode(', ', array_slice($partsComma, 1)))];
        }

        if (preg_match('/^(.+?)\s+\1$/u', $e, $m)) {
            $p = trim($m[1]);
            return [$p, $p];
        }

        $partsWs = preg_split('/\s{2,}/', $e);
        if (count($partsWs) >= 2) {
            return [trim($partsWs[0]), trim(implode(' ', array_slice($partsWs, 1)))];
        }

        $words = preg_split('/\s+/', $e);
        if (count($words) >= 4) {
            $mid   = intdiv(count($words), 2);
            $left  = implode(' ', array_slice($words, 0,  $mid));
            $right = implode(' ', array_slice($words, $mid));
            return [trim($left), trim($right)];
        }

        return [$e, $e];
    }

    // ====== Sanitizer ketat untuk nama/alamat ======
    private function cleanText(string $v, bool $removeCommas = false): string
    {
        $v = trim($v);

        if (class_exists('\Normalizer')) {
            $v = \Normalizer::normalize($v, \Normalizer::FORM_C);
        }

        // Map eksplisit biang error
        $map = [
            "Ñ" => "N", "ñ" => "n",
            "&" => " AND ",
            "’" => "", "‘" => "", "“" => "", "”" => "", "'" => "",
        ];
        $v = strtr($v, $map);

        // Transliterate sisa non-ASCII → ASCII
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
        if ($ascii !== false) {
            $v = $ascii;
        }

        // Hapus control chars
        $v = preg_replace('/[^\x20-\x7E]/', '', $v);

        if ($removeCommas) {
            $v = str_replace(',', '', $v);
        }

        // Rapikan spasi
        $v = preg_replace('/\s{2,}/', ' ', $v);
        return trim($v);
    }

    private function sanitizeName(string $v): string
    {
        // Registered Name: larang koma & petik
        return $this->cleanText($v, true);
    }

    private function sanitizeAddress(string $v): string
    {
        // Alamat: koma biasanya boleh; tetap bersihkan simbol & karakter non-ASCII
        return $this->cleanText($v, false);
    }

    private function extractGlobalMonthToken(array $rows): string
    {
        // Cari di seluruh sel yang ada kata "FOR THE MONTH"
        foreach ($rows as $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                if (stripos($cell, 'FOR THE MONTH') !== false) {
                    return $this->monthToken($cell); // -> "MM/YYYY"
                }
            }
        }
        return '01/1970';
    }

    private function monthToken($value): string
{
    // 1) Excel serial → MM/YYYY
    if (is_numeric($value)) {
        $unix = ((int)$value - 25569) * 86400;
        return gmdate('m/Y', $unix);
    }

    $s = trim((string)$value);
    if ($s === '') return '01/1970';

    // 2) Format khusus: "FOR THE MONTH OF AUGUST, 2024" (variasi koma/OF/spacing/case)
    //    Juga handle "FOR THE MONTH AUGUST 2024", "For the Month: AUG 2024", dll.
    if (preg_match('/FOR\s+THE\s+MONTH(?:\s+OF)?[:\s]*([A-Z]+)\s*,?\s*(\d{4})/i', $s, $m)) {
        $monWord = strtoupper($m[1]);
        $year    = $m[2];
        $mm = [
            'JANUARY'=>1,'JAN'=>1,
            'FEBRUARY'=>2,'FEB'=>2,
            'MARCH'=>3,'MAR'=>3,
            'APRIL'=>4,'APR'=>4,
            'MAY'=>5,
            'JUNE'=>6,'JUN'=>6,
            'JULY'=>7,'JUL'=>7,
            'AUGUST'=>8,'AUG'=>8,
            'SEPTEMBER'=>9,'SEP'=>9,'SEPT'=>9,
            'OCTOBER'=>10,'OCT'=>10,
            'NOVEMBER'=>11,'NOV'=>11,
            'DECEMBER'=>12,'DEC'=>12,
        ][$monWord] ?? null;

        if ($mm !== null) {
            return sprintf('%02d/%s', $mm, $year);
        }
    }

    // 3) "AUGUST 2024" atau "AUG 2024"
    if (preg_match('/^\s*([A-Z]+)\s+(\d{4})\s*$/i', $s, $m)) {
        $monWord = strtoupper($m[1]);
        $year    = $m[2];
        $mm = [
            'JANUARY'=>1,'JAN'=>1, 'FEBRUARY'=>2,'FEB'=>2, 'MARCH'=>3,'MAR'=>3,
            'APRIL'=>4,'APR'=>4, 'MAY'=>5, 'JUNE'=>6,'JUN'=>6, 'JULY'=>7,'JUL'=>7,
            'AUGUST'=>8,'AUG'=>8, 'SEPTEMBER'=>9,'SEP'=>9,'SEPT'=>9,
            'OCTOBER'=>10,'OCT'=>10, 'NOVEMBER'=>11,'NOV'=>11, 'DECEMBER'=>12,'DEC'=>12,
        ][$monWord] ?? null;

        if ($mm !== null) {
            return sprintf('%02d/%s', $mm, $year);
        }
    }

    // 4) mm/yyyy
    if (preg_match('#^(\d{1,2})/(\d{4})$#', $s, $m)) {
        $mm = (int)$m[1]; $yy = $m[2];
        return sprintf('%02d/%s', max(1, min(12, $mm)), $yy);
    }

    // 5) yyyy-mm-dd atau mm/dd/yyyy → pakai strtotime
    if (preg_match('#^\d{4}-\d{2}-\d{2}#', $s) || preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}#', $s)) {
        $ts = strtotime($s);
        if ($ts !== false) return date('m/Y', $ts);
    }

    // 6) Fallback: coba parse "01 " + string (buat "Aug 2024", dsb.)
    $ts = strtotime('01 '.$s);
    if ($ts !== false) return date('m/Y', $ts);

    return '01/1970';
}

// Ambil 9 digit TIN dasar dari string TIN apa pun (buang dash & trailing 0000)
private function tinBase9(string $s): string
{
    $digits = preg_replace('/[^0-9]/', '', $s);
    if (strlen($digits) >= 9) return substr($digits, 0, 9);
    return str_pad($digits, 9, '0', STR_PAD_LEFT);
}

// Deteksi baris junk/header pemisah (angka (1)…(8), garis '----', label, dsb.)
private function isRowJunk(...$cells): bool
{
    $line = strtoupper(trim(implode(' ', array_map(fn($c)=> (string)$c, $cells))));
    if ($line === '') return true;
    if (str_contains($line, '----------------')) return true;
    if (preg_match('/\(\s*[1-8]\s*\)/', $line)) return true; // "(1)".."(8)"
    if (str_contains($line, 'SEQ') && str_contains($line, 'TAXPAYER')) return true;
    if (str_contains($line, 'IDENTIFICATION') || str_contains($line, 'REGISTERED NAME')) return true;
    return false;
}


private function extractIndividualName(array $rows): array
{
    $last = $first = $middle = '';

    // 1) Cari label spesifik LAST/FIRST/MIDDLE NAME di SELURUH sheet
    foreach ($rows as $r) {
        foreach ($r as $cell) {
            if (!is_string($cell)) continue;
            // LAST NAME:
            if (preg_match('/\bLAST\s*NAME\s*:?\s*(.+)\s*$/i', $cell, $m)) {
                $last = $this->sanitizeName($m[1]);
            }
            // FIRST NAME:
            if (preg_match('/\bFIRST\s*NAME\s*:?\s*(.+)\s*$/i', $cell, $m)) {
                $first = $this->sanitizeName($m[1]);
            }
            // MIDDLE NAME:
            if (preg_match('/\bMIDDLE\s*NAME\s*:?\s*(.+)\s*$/i', $cell, $m)) {
                $middle = $this->sanitizeName($m[1]);
            }
        }
    }
    if ($last !== '' || $first !== '' || $middle !== '') {
        return [$last, $first, $middle];
    }

    // 2) Pola gabungan: "PAYEE NAME: LAST, FIRST MIDDLE"
    foreach ($rows as $r) {
        foreach ($r as $cell) {
            if (!is_string($cell)) continue;
            if (preg_match('/PAYEE\s*NAME\s*:\s*(.+)$/i', $cell, $m)) {
                $full = $this->sanitizeName($m[1]);
                // split "LAST, FIRST MIDDLE" atau "LAST, FIRST, MIDDLE"
                $partsComma = array_map('trim', array_filter(explode(',', $full), fn($x)=>$x!=='' ));
                if (count($partsComma) >= 2) {
                    $last  = $partsComma[0];
                    $rest  = $partsComma[1];
                    // rest bisa "FIRST MIDDLE" → pisah spasi
                    $restParts = array_values(array_filter(preg_split('/\s+/', $rest)));
                    $first = $restParts[0] ?? '';
                    if (count($restParts) > 1) {
                        $middle = implode(' ', array_slice($restParts, 1));
                    }
                    return [$last, $first, $middle];
                } else {
                    // fallback: tanpa koma -> "LAST FIRST MIDDLE"
                    $tokens = array_values(array_filter(preg_split('/\s+/', $full)));
                    if (count($tokens) >= 2) {
                        $last = $tokens[0];
                        $first = $tokens[1];
                        if (count($tokens) > 2) {
                            $middle = implode(' ', array_slice($tokens, 2));
                        }
                        return [$last, $first, $middle];
                    }
                }
            }
        }
    }

    // 3) Fallback: semua kosong
    return [$last, $first, $middle];
}






}
