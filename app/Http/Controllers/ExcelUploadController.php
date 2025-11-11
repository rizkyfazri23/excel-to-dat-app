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
            $ownerTin = preg_replace('/\D/', '', $parsed['header']['tin'] ?? 'TIN');
            $period   = $parsed['header']['period_end'] ?? $parsed['header']['month'] ?? $parsed['header']['period'] ?? $parsed['header']['date'] ?? '01/01/1970';
            $monthFormat5 = $parsed['header']['month_format_5'] ?? '';

            if (($format === 3 || $format === 4 || $format === 5 ) && preg_match('/^(\d{2})\/(\d{4})$/', $period, $m)) {
                [$mm, $yyyy] = [$m[1], $m[2]];
            } else if ($format === 6) {
                if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $period, $m)) {
                    [$mm, $dd, $yyyy] = [$m[1], $m[2], $m[3]];
                } else {
                    $ts = strtotime($period);
                    $dd = $ts ? date('d', $ts) : '01';
                    $mm = $ts ? date('m', $ts) : '01';
                    $yyyy = $ts ? date('Y', $ts) : '1970';
                }
            }else {
                $ts = strtotime($period);
                $mm = $ts ? date('m', $ts) : '01';
                $yyyy = $ts ? date('Y', $ts) : '1970';
            }

            $code = match ($format) {
                1 => "S{$mm}{$yyyy}",
                2 => "P{$mm}{$yyyy}",
                3 => "0000{$mm}{$yyyy}1702Q",
                4 => "0000{$mm}{$yyyy}1701Q",
                5 => "0000{$mm}{$yyyy}1601EQ",
                6 => "0000{$mm}{$dd}{$yyyy}1604E",
                default => throw new \RuntimeException("Unknown format {$format}."),
            };

            $fileName = sprintf('%s%s.DAT', $ownerTin, $code);

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
            5 => $this->parseFormat5($rows),
            6 => $this->parseFormat6($rows),
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
            5 => $this->buildDatContentFormat5($parsed),
            6 => $this->buildDatContentFormat6($parsed),
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
                '',
                '',
                '',
                $parsed['header']['month'], // paksa sama dg header
                '',
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
                '',
                '',
                '',
                $h['month'],          // samakan semua dgn header
                '',
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


    /* =========================
     *  FORMAT 5 (1601EQ)
     * ========================= */

private function parseFormat5(array $rows): array
{
    // 1) Periode global
    $period = $this->extractGlobalMonthToken($rows); // MM/YYYY

    // 2) Header: TIN + branch + agent name
    $tinHeader = '000000000'; 
    $branchHeader = '0000'; 
    $agentName = '';

    // TIN : 008740080-0000
    foreach ($rows as $r) {
        foreach ($r as $cell) {
            if (!is_string($cell)) continue;
            if (stripos($cell, 'TIN') === 0) {
                $digits = preg_replace('/[^0-9\-]/', '', $cell);
                if (preg_match('/(\d+)\-(\d{4})/', $digits, $m)) {
                    $tinHeader    = $this->tinBase9($m[1]);
                    $branchHeader = $m[2];
                } else {
                    $tinHeader = $this->tinBase9($digits);
                }
                break 2;
            }
        }
    }

    // Withholding Agent Name
    foreach ($rows as $r) {
        foreach ($r as $cell) {
            if (!is_string($cell)) continue;
            if (preg_match('/WITH(H)?OLDING\s+AGENT.*?:/i', $cell)
                || preg_match("/AGENT'S\s*NAME\s*:/i", $cell)
                || preg_match('/COMPANY\s+NAME\s*:/i', $cell)
                || preg_match('/TAXPAYER\s+NAME\s*:/i', $cell)
            ) {
                $val = trim(preg_replace('/^.*?:/i', '', $cell));
                $agentName = $this->cleanText($val, false);
                break 2;
            }
        }
    }

    $details = [];
    $sumAmt = 0.0; 
    $sumWh = 0.0;
    $headerMonthCode = null;   // nanti diisi dari baris pertama yang valid

    foreach ($rows as $r) {
        $seqRaw  = (string)($r['A'] ?? '');
        $tinRaw  = (string)($r['B'] ?? '');
        $nameRaw = (string)($r['C'] ?? '');
        $atcRaw  = (string)($r['E'] ?? '');
        $nature  = (string)($r['F'] ?? '');

        $amt1 = (string)($r['G'] ?? '');
        $rate1= (string)($r['H'] ?? '');
        $wh1  = (string)($r['I'] ?? '');

        $amt2 = (string)($r['J'] ?? '');
        $rate2= (string)($r['K'] ?? '');
        $wh2  = (string)($r['L'] ?? '');

        $amt3 = (string)($r['M'] ?? '');
        $rate3= (string)($r['N'] ?? '');
        $wh3  = (string)($r['O'] ?? '');

        // Filter baris sampah/header/total
        if ($this->isRowJunk($seqRaw, $tinRaw, $nameRaw, $atcRaw, $nature)) continue;

        $seq = trim($seqRaw);
        if ($seq === '') continue;

        // TIN base + branch
        $digitsAll = preg_replace('/[^0-9\-]/', '', $tinRaw);
        $tinBase   = $this->tinBase9($digitsAll);
        $branchDet = '0000';
        if (preg_match('/\-(\d{4})$/', $digitsAll, $m)) {
            $branchDet = $m[1];
        }

        $name = $this->cleanText($nameRaw, false);
        if ($agentName === '' && $name !== '') {
            $agentName = $name; // fallback header name
        }
        $atc  = strtoupper(trim($atcRaw));

        // --- BULAN DARI RATE ---
        $r1 = $this->toDec($rate1);
        $r2 = $this->toDec($rate2);
        $r3 = $this->toDec($rate3);

        // logika: kolom yang KEISI itulah bulannya
        if ($r1 != 0.0) {
            $monthCode = '04'; // April
        } elseif ($r2 != 0.0) {
            $monthCode = '05'; // May
        } elseif ($r3 != 0.0) {
            $monthCode = '06'; // June
        } else {
            $monthCode = '00'; // gak ketebak
        }

        // simpan ke header kalau belum ada
        if ($headerMonthCode === null && $monthCode !== '00') {
            $headerMonthCode = $monthCode;
        }

        // Rate fallback H -> K -> N -> 0
        $rate = $r1;
        if ($rate == 0.0) $rate = $r2;
        if ($rate == 0.0) $rate = $r3;

        // Amount fallback G -> J -> M -> 0
        $amt = $this->toDec($amt1);
        if ($amt == 0.0) {
            $tmp = $this->toDec($amt2);
            if ($tmp != 0.0) $amt = $tmp;
        }
        if ($amt == 0.0) {
            $tmp = $this->toDec($amt3);
            if ($tmp != 0.0) $amt = $tmp;
        }

        // Withheld fallback I -> L -> O -> 0
        $wh = $this->toDec($wh1);
        if ($wh == 0.0) {
            $tmp = $this->toDec($wh2);
            if ($tmp != 0.0) $wh = $tmp;
        }
        if ($wh == 0.0) {
            $tmp = $this->toDec($wh3);
            if ($tmp != 0.0) $wh = $tmp;
        }

        // Validasi minimal
        $hasTin = (bool)preg_match('/\d/', $tinRaw);
        $hasNum = ($amt != 0.0 || $wh != 0.0 || $rate != 0.0);
        if (!$hasTin && !$hasNum) continue;

        $details[] = [
            'seq'      => $seq,
            'tin'      => $tinBase,
            'branch'   => $branchDet,
            'name'     => $name,
            'period'   => $period,
            'atc'      => $atc,
            'rate'     => $rate,
            'amount'   => $amt,
            'withheld' => $wh,
        ];
        $sumAmt += $amt; 
        $sumWh  += $wh;
    }

    if ($agentName === '') {
        $agentName = '';
    }

    // kalau sepanjang sheet gak ketemu bulan, pakai '00'
    if ($headerMonthCode === null) {
        $headerMonthCode = '00';
    }

    return [
        'header' => [
            'tin'             => $tinHeader,
            'branch'          => $branchHeader,
            'agent'           => $agentName,
            'period'          => $period,
            'code'            => '033',
            'month_format_5'  => $headerMonthCode, // ← ini sekarang bener
        ],
        'details' => $details,
        'control' => [
            'tin'    => $tinHeader,
            'branch' => $branchHeader,
            'period' => $period,
            'sumAmt' => $sumAmt,
            'sumWh'  => $sumWh,
        ],
    ];
}


    private function buildDatContentFormat5(array $parsed): string
    {
        $lines = [];

        // HEADER: HQAP,H1601EQ,TIN,BRANCH,"AGENT",MM/YYYY,033
        $h = $parsed['header'];
        $lines[] = implode(',', [
            'HQAP',
            'H1601EQ',
            $h['tin'],
            $h['branch'],
            $this->q($h['agent']),
            $h['period'],
            '033',
        ]);

        // DETAIL: D1,1601EQ,SEQ,TIN,BRANCH,"NAME",,,,MM/YYYY,ATC,RATE,AMOUNT,WITHHELD
        foreach ($parsed['details'] as $d) {
            $lines[] = implode(',', [
                'D1',
                '1601EQ',
                (string)$d['seq'],
                $d['tin'],
                $d['branch'],
                $this->q($d['name']),
                '', '', '',                
                $d['period'],
                $d['atc'],
                $this->n($d['rate']),
                $this->n($d['amount']),
                $this->n($d['withheld']),
            ]);
        }

        // CONTROL: C1,1601EQ,TIN,BRANCH,MM/YYYY,sumAmt,sumWh
        $c = $parsed['control'];
        $lines[] = implode(',', [
            'C1',
            '1601EQ',
            $c['tin'],
            $c['branch'],
            $c['period'],
            $this->n($c['sumAmt']),
            $this->n($c['sumWh']),
        ]);

        return implode("\r\n", $lines) . "\r\n";
    }

    private function parseFormat6(array $rows): array
    {
        // 1) HEADER: TIN header + branch, tanggal "AS OF ..."
        $tinHeader = '000000000';
        $branchHdr = '0000';
        $asOfDate  = '01/01/1970';

        // TIN : 009288158-0000
        foreach ($rows as $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                if (stripos($cell, 'TIN') === 0) {
                    $digits = preg_replace('/[^0-9\-]/', '', $cell);
                    if (preg_match('/(\d+)\-(\d{4})/', $digits, $m)) {
                        $tinHeader = $this->tinBase9($m[1]);
                        $branchHdr = $m[2];
                    } else {
                        $tinHeader = $this->tinBase9($digits);
                    }
                    break 2;
                }
            }
        }

        // AS OF DECEMBER 31, 2025
        foreach ($rows as $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                if (stripos($cell, 'AS OF') !== false || preg_match('/[A-Z]+\s+\d{1,2},\s*\d{4}/i', $cell)) {
                    $asOfDate = $this->asOfDateToken($cell);
                    break 2;
                }
            }
        }

        // 2) DETAILS
        // Mapping utama: A=SEQ, B=TIN, C=NAME, E=ATC, F=AMOUNT, G=RATE, H=WITHHELD
        // Fallback geser 1/2 kolom kalau kosong.
        $details = [];
        $sumWithheld = 0.0;

        foreach ($rows as $r) {
            $seqRaw  = (string)($r['A'] ?? '');
            $tinRaw  = (string)($r['B'] ?? '');
            $nameRaw = (string)($r['C'] ?? '');

            // Skip baris junk/header/pemisah
            if ($this->isRowJunk($seqRaw, $tinRaw, $nameRaw)) continue;

            $seq = trim($seqRaw);
            if ($seq === '') continue; // wajib ada SEQ dari kolom A

            // TIN detail + branch
            $digits = preg_replace('/[^0-9\-]/', '', $tinRaw);
            $tinDet = $this->tinBase9($digits);
            $branchDet = '0000';
            if (preg_match('/\-(\d{4})$/', $digits, $mB)) $branchDet = $mB[1];

            // Registered name (bersihin)
            $regName = $this->cleanText($nameRaw, false);

            // Kolom angka/ATC fleksibel: coba slot (E,F,G,H) → (F,G,H,I) → (G,H,I,J)
            $slots = [
                ['E','F','G','H'],
                ['F','G','H','I'],
                ['G','H','I','J'],
            ];
            $atc=''; $amt=0.0; $rate=0.0; $wh=0.0;

            foreach ($slots as [$cAtc,$cAmt,$cRate,$cWh]) {
                $atcTry  = strtoupper(trim((string)($r[$cAtc] ?? '')));
                $amtTry  = $this->toDec((string)($r[$cAmt] ?? ''));
                $rateTry = $this->toDec((string)($r[$cRate] ?? ''));
                $whTry   = $this->toDec((string)($r[$cWh] ?? ''));

                // ambil kalau salah satu meaningful
                if ($atc === '' && $atcTry !== '') $atc = $atcTry;
                if ($amt == 0.0 && $amtTry != 0.0)   $amt = $amtTry;
                if ($rate == 0.0 && $rateTry != 0.0) $rate = $rateTry;
                if ($wh == 0.0 && $whTry != 0.0)     $wh = $whTry;

                // early exit kalau sudah dapat semua
                if ($atc !== '' && ($amt != 0.0 || $wh != 0.0 || $rate != 0.0)) break;
            }

            // Kalau semuanya kosong, tetep 0.00 (sesuai kebijakan kamu)
            // Filter minimal: ada TIN atau ada angka; kalau nihil semua, skip
            $hasTin = (bool)preg_match('/\d/', $tinRaw);
            $hasNum = ($amt != 0.0 || $wh != 0.0 || $rate != 0.0);
            if (!$hasTin && !$hasNum) continue;

            $details[] = [
                'seq'      => $seq,
                'tin_h'    => $tinHeader,
                'branch_h' => $branchHdr,
                'date'     => $asOfDate,
                'tin_d'    => $tinDet,
                'branch_d' => $branchDet,
                'name'     => $regName,
                'atc'      => $atc,
                'amount'   => $amt,
                'rate'     => $rate,
                'withheld' => $wh,
            ];
            $sumWithheld += $wh;
        }

        return [
            'header' => [
                'tin'    => $tinHeader,
                'branch' => $branchHdr,
                'date'   => $asOfDate,
            ],
            'details' => $details,
            'control' => [
                'tin'    => $tinHeader,
                'branch' => $branchHdr,
                'date'   => $asOfDate,
                'sum_wh' => $sumWithheld,
            ],
        ];
    }

    private function buildDatContentFormat6(array $parsed): string
    {
        $lines = [];

        // HEADER: H1604E,TIN,BRANCH,MM/DD/YYYY
        $h = $parsed['header'];
        $lines[] = implode(',', [
            'H1604E',
            $h['tin'],
            $h['branch'],
            $h['date'],
        ]);

        // DETAIL: D3,1604E,TIN_H,BRANCH_H,DATE,SEQ,TIN_D,BRANCH_D,"NAME",,,,ATC,AMOUNT,RATE,WITHHELD
        foreach ($parsed['details'] as $d) {
            $lines[] = implode(',', [
                'D3',
                '1604E',
                $d['tin_h'],
                $d['branch_h'],
                $d['date'],
                (string)$d['seq'],
                $d['tin_d'],
                $d['branch_d'],
                $this->q($d['name']),
                '', '', '',                 // 4 kolom kosong
                $d['atc'],
                $this->n($d['amount']),
                $this->n($d['rate']),
                $this->n($d['withheld']),
            ]);
        }

        // CONTROL: C3,1604E,TIN,BRANCH,DATE,SUM_WITHHELD
        $c = $parsed['control'];
        $lines[] = implode(',', [
            'C3',
            '1604E',
            $c['tin'],
            $c['branch'],
            $c['date'],
            $this->n($c['sum_wh']),
        ]);

        return implode("\r\n", $lines) . "\r\n";
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
        // Cari QUARTER dulu
        foreach ($rows as $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                if (stripos($cell, 'FOR THE QUARTER') !== false) {
                    return $this->monthToken($cell);
                }
            }
        }
        // Lalu MONTH
        foreach ($rows as $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                if (stripos($cell, 'FOR THE MONTH') !== false) {
                    return $this->monthToken($cell);
                }
            }
        }
        return '01/1970';
    }

    private function monthToken($value): string
    {
        // Excel serial
        if (is_numeric($value)) {
            $unix = ((int)$value - 25569) * 86400;
            return gmdate('m/Y', $unix);
        }

        $s = trim((string)$value);
        if ($s === '') return '01/1970';

        // Map bulan
        $monMap = [
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
        ];

        // QUARTER: "FOR THE QUARTER ENDING August, 2025" / "FOR THE QUARTER ENDING 08/2025"
        if (preg_match('/FOR\s+THE\s+QUARTER\s+ENDING[:\s]*(?:OF\s+)?([A-Z]+)\s*,?\s*(\d{4})/i', $s, $m)) {
            $mon = strtoupper($m[1]); $yy = $m[2];
            if (isset($monMap[$mon])) return sprintf('%02d/%s', $monMap[$mon], $yy);
        }
        if (preg_match('/FOR\s+THE\s+QUARTER\s+ENDING[:\s]*(\d{1,2})\/(\d{4})/i', $s, $m)) {
            $mm = max(1, min(12, (int)$m[1])); $yy = $m[2];
            return sprintf('%02d/%s', $mm, $yy);
        }

        // MONTH: "FOR THE MONTH OF AUGUST, 2024" / "FOR THE MONTH AUG 2024"
        if (preg_match('/FOR\s+THE\s+MONTH(?:\s+OF)?[:\s]*([A-Z]+)\s*,?\s*(\d{4})/i', $s, $m)) {
            $mon = strtoupper($m[1]); $yy = $m[2];
            if (isset($monMap[$mon])) return sprintf('%02d/%s', $monMap[$mon], $yy);
        }
        if (preg_match('/FOR\s+THE\s+MONTH[:\s]*(\d{1,2})\/(\d{4})/i', $s, $m)) {
            $mm = max(1, min(12, (int)$m[1])); $yy = $m[2];
            return sprintf('%02d/%s', $mm, $yy);
        }

        // "Aug 2024" / "August 2024"
        if (preg_match('/^\s*([A-Z]+)\s+(\d{4})\s*$/i', $s, $m)) {
            $mon = strtoupper($m[1]); $yy = $m[2];
            if (isset($monMap[$mon])) return sprintf('%02d/%s', $monMap[$mon], $yy);
        }

        // "mm/yyyy"
        if (preg_match('#^(\d{1,2})/(\d{4})$#', $s, $m)) {
            $mm = max(1, min(12, (int)$m[1])); $yy = $m[2];
            return sprintf('%02d/%s', $mm, $yy);
        }

        // ISO/US date → pakai strtotime
        if (preg_match('#^\d{4}-\d{2}-\d{2}#', $s) || preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}#', $s)) {
            $ts = strtotime($s);
            if ($ts !== false) return date('m/Y', $ts);
        }

        // Fallback: coba "01 ".$s
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

    private function isRowJunk(...$cells): bool
    {
        $line = strtoupper(trim(implode(' ', array_map(fn($c)=>(string)$c, $cells))));
        if ($line === '') return true;
        if (str_contains($line, '----------------')) return true;
        if (preg_match('/\(\s*\d+\s*\)/', $line)) return true; // "(1)".."(n)"
        if (str_contains($line, 'SEQ') && str_contains($line, 'TAXPAYER')) return true;
        if (str_contains($line, 'IDENTIFICATION') || str_contains($line, 'REGISTERED NAME')) return true;
        if (preg_match('/\b(TOTAL|SUBTOTAL|GRAND\s+TOTAL)\b/', $line)) return true;
        return false;
    }

    private function asOfDateToken($value): string
    {
        if (is_numeric($value)) {
            // Excel serial number → UTC
            $unix = ((int)$value - 25569) * 86400;
            return gmdate('m/d/Y', $unix);
        }
        $s = trim((string)$value);
        if ($s === '') return '01/01/1970';

        // "AS OF DECEMBER 31, 2025" / "DECEMBER 31, 2025"
        if (preg_match('/(?:AS\s+OF\s+)?([A-Z]+)\s+(\d{1,2}),\s*(\d{4})/i', $s, $m)) {
            $mon = strtoupper($m[1]); $dd = (int)$m[2]; $yy = $m[3];
            $monMap = [
                'JANUARY'=>1,'JAN'=>1,'FEBRUARY'=>2,'FEB'=>2,'MARCH'=>3,'MAR'=>3,
                'APRIL'=>4,'APR'=>4,'MAY'=>5,'JUNE'=>6,'JUN'=>6,'JULY'=>7,'JUL'=>7,
                'AUGUST'=>8,'AUG'=>8,'SEPTEMBER'=>9,'SEP'=>9,'SEPT'=>9,
                'OCTOBER'=>10,'OCT'=>10,'NOVEMBER'=>11,'NOV'=>11,'DECEMBER'=>12,'DEC'=>12,
            ];
            if (isset($monMap[$mon])) {
                $mm = sprintf('%02d', $monMap[$mon]);
                $dd = sprintf('%02d', max(1, min(31, $dd)));
                return "{$mm}/{$dd}/{$yy}";
            }
        }

        // ISO/US date-friendly → pakai strtotime
        $ts = strtotime($s);
        if ($ts !== false) return date('m/d/Y', $ts);

        return '01/01/1970';
    }







}
