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

        // Baca langsung dari tmp (stabil lintas OS/hosting)
        $uploaded   = $request->file('excel_file');
        $tempPath   = $uploaded->getRealPath();
        $clientName = $uploaded->getClientOriginalName();

        try {
            if (!$tempPath || !file_exists($tempPath)) {
                return back()->with('error', 'Uploaded file temporary path not found.')->withInput();
            }

            // Load Excel
            $spreadsheet = IOFactory::load($tempPath);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = $sheet->toArray(null, true, true, true);

            $format = (int) $request->input('format_type');

            // === Parse per-format ===
            $parsed = $this->parseByFormat($rows, $format);

            if (empty($parsed['header']) || empty($parsed['details'])) {
                return back()->with('error', "Parsed data is empty for Format {$format}. Please check the template/mapping.")
                             ->withInput();
            }

            // === Build .DAT per-format ===
            $datContent = $this->buildDatByFormat($parsed, $format);

            // Nama file: {TIN}S{MM}{YYYY}.DAT (mengikuti sample saat ini)
            $ownerTin = preg_replace('/[^0-9]/', '', ($parsed['header']['tin'] ?? 'TIN'));
            $period   = $parsed['header']['period_end'] ?? '01/01/1970'; // mm/dd/YYYY
            $mm       = date('m', strtotime($period));
            $yyyy     = date('Y', strtotime($period));
            $fileName = sprintf('%sS%s%s.DAT', $ownerTin, $mm, $yyyy);

            // Log aktivitas
            ActivityLog::create([
                'user'    => Auth::check() ? Auth::user()->username : 'guest',
                'action'  => "Generate DAT (Format {$format})",
                'details' => 'Generated file: '.$fileName.' from '.$clientName,
            ]);

            // Stream download tanpa simpan ke disk
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
            3 => $this->parseFormatNotImplemented(3),
            4 => $this->parseFormatNotImplemented(4),
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
            3 => $this->buildDatNotImplemented(3),
            4 => $this->buildDatNotImplemented(4),
            5 => $this->buildDatNotImplemented(5),
            6 => $this->buildDatNotImplemented(6),
            default => throw new \RuntimeException("Unknown format {$format}."),
        };
    }

    private function parseFormatNotImplemented(int $format): array
    {
        // “Kosongan” dulu: bikin error yang eksplisit biar gak salah generate
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

        // Ambil data header dari kolom A sesuai sample
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

        // Ambil baris detail
        $details = [];
        $firstPeriodEnd = null;
        $started = false;

        foreach ($rows as $r) {
            $a = trim((string)($r['A'] ?? '')); // taxable month
            $b = trim((string)($r['B'] ?? '')); // TIN customer
            $c = trim((string)($r['C'] ?? '')); // Registered Name
            $d = trim((string)($r['D'] ?? '')); // Name of Customer
            $e = trim((string)($r['E'] ?? '')); // Customer Address (gabung)
            $f = trim((string)($r['F'] ?? '')); // gross (kalau ada)
            $g = (string)($r['G'] ?? '');       // Exempt
            $h = (string)($r['H'] ?? '');       // Zero
            $i = (string)($r['I'] ?? '');       // Taxable
            $j = (string)($r['J'] ?? '');       // Output Tax

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
                $firstPeriodEnd = $this->excelDateToUS($a); // format mm/dd/YYYY (leading zero)
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

        // HEADER: kolom 5–7 kosong (quoted empty)
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
            $h['period_end'],   // mm/dd/YYYY
            $h['col17'],
        ];
        $lines[] = implode(',', $headerRow);

        // DETAILS
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

        // Placeholder nama pemilik (kolom 5-7 header)
        $ownerLast = '';
        $ownerFirst = '';
        $ownerMiddle = '';

        foreach ($rows as $r) {
            $a = (string)($r['A'] ?? '');
            if ($a === '') continue;

            if (Str::startsWith($a, 'TIN')) {
                $digits = preg_replace('/[^0-9]/', '', $a);
                $tin = substr($digits, 0, 9);
            } elseif (Str::startsWith($a, "OWNER'S NAME")) {
                // ambil full registered name utk kolom 4 header
                $registeredName = trim(str_replace("OWNER'S NAME:", '', $a));
                // JANGAN nebak last/first/middle; biarkan kosong
            } elseif (Str::startsWith($a, "OWNER'S TRADE NAME")) {
                $tradeName = trim(str_replace("OWNER'S TRADE NAME :", '', $a));
            } elseif (Str::startsWith($a, "OWNER'S ADDRESS")) {
                $addr1 = str_replace("OWNER'S ADDRESS:", '', $a); // jaga spasi apa adanya
                $addr1 = ltrim($addr1); // jangan trim kanan; sample punya "  " di addr2
            }
        }

        $details = [];
        $firstPeriodEnd = null;
        $started = false;

        foreach ($rows as $r) {
            $a = trim((string)($r['A'] ?? '')); // taxable month
            $b = trim((string)($r['B'] ?? '')); // Supplier TIN
            $c = trim((string)($r['C'] ?? '')); // Registered Name
            $d = trim((string)($r['D'] ?? '')); // Supplier Name
            $e = (string)($r['E'] ?? '');       // Address (biarkan spasi apa adanya)
            $f = (string)($r['F'] ?? '');       // Gross
            $g = (string)($r['G'] ?? '');       // Exempt
            $h = (string)($r['H'] ?? '');       // Zero-rated
            $i = (string)($r['I'] ?? '');       // Taxable
            $j = (string)($r['J'] ?? '');       // Purchase Services
            $k = (string)($r['K'] ?? '');       // Purchase Capital Goods
            $l = (string)($r['L'] ?? '');       // Purchase Other Goods
            $m = (string)($r['M'] ?? '');       // Input Tax
            $n = (string)($r['N'] ?? '');       // Gross Taxable

            if (!$started) {
                if ($this->isDateToken($a) && $this->isTinLike($b)) {
                    $started = true;
                } else {
                    continue;
                }
            }

            if (!$this->isDateToken($a)) {
                $upperA = strtoupper($a);
                if (str_contains($upperA, 'END OF REPORT')) break;
                continue;
            }

            if ($firstPeriodEnd === null) {
                $firstPeriodEnd = $this->excelDateToUS($a); // akan dipaksa mm/dd/YYYY saat build
            }

            // Nama pemasok: prioritas C lalu D
            $supplierName = ($c !== '') ? $c : $d;

            // Address E -> bagi dua dengan logika ringan, tapi jangan hapus spasi di edges
            [$addr1D, $addr2D] = $this->splitAddressFromE($e);

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

        return [
            'header' => [
                'tin'             => $tin ?: '000000000',
                'registered_name' => $registeredName ?: '',
                'owner_last'      => $ownerLast,
                'owner_first'     => $ownerFirst,
                'owner_middle'    => $ownerMiddle,
                'addr1'           => $addr1 ?? '',
                'addr2'           => $addr2 ?? '  ', // contoh di source ada dua spasi
                // Totals (kalau belum dipakai di header kolom 11..18, boleh 0 dulu)
                'c11' => array_sum(array_column($details, 'gross')),        // sesuaikan bila perlu
                'c12' => array_sum(array_column($details, 'exempt')),
                'c13' => array_sum(array_column($details, 'taxable')),
                'c14' => 0,
                'c15' => array_sum(array_column($details, 'services')),
                'c16' => array_sum(array_column($details, 'input_tax')),
                'c17' => array_sum(array_column($details, 'input_tax')),    // di sample sama
                'c18' => 0,
                'code'       => '000', // persis sample
                'period_end' => $firstPeriodEnd ?: '01/01/1970',
                'col17'      => '12',
            ],
            'details' => $details,
        ];
    }


    private function buildDatContentFormat2(array $parsed): string
    {
        $h = $parsed['header'];
        $lines = [];

        // HEADER: prefix H,P
        $headerRow = [
            'H','P',
            $this->q($h['tin']),
            $this->q($h['registered_name']),
            $this->q(''),$this->q(''),$this->q(''),
            $this->q($h['trade_name']),
            $this->q($h['addr1']),
            $this->q($h['addr2']),
            $this->n($h['exempt_total']),
            $this->n($h['zero_total']),
            $this->n($h['taxable_total']),
            $this->n($h['input_tax_total']),
            $h['col15'],
            $h['period_end'],
            $h['col17'],
        ];
        $lines[] = implode(',', $headerRow);

        // DETAIL: prefix D,P
        foreach ($parsed['details'] as $d) {
            $detailRow = [
                'D','P',
                $this->q($d['supplier_tin']),
                $this->q($d['supplier_name']),
                '', '', '', // reserved
                $this->q($d['addr1']),
                $this->q($d['addr2']),
                $this->n($d['exempt']),
                $this->n($d['zero_rated']),
                $this->n($d['taxable']),
                $this->n($d['input_tax']),
                preg_replace('/[^0-9]/','',$d['owner_tin']),
                $d['period_end'],
            ];
            $lines[] = implode(',', $detailRow);
        }

        return implode("\r\n", $lines)."\r\n";
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
        $v = preg_replace('/[^\d\.\-]/', '', (string)$v);
        if ($v === '' || $v === '-' || $v === '.') return 0.0;
        return (float)$v;
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
            return date('m/d/Y', $ts); // leading zero ensured
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
}
