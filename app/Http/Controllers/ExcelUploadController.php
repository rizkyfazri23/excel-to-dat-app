<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

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

        $path = $request->file('excel_file')->store('uploads');
        $absolutePath = Storage::path($path);

        try {
            if (!file_exists($absolutePath)) {
                Storage::delete($path);
                return back()->with('error', "Uploaded file not found at: {$absolutePath}.")->withInput();
            }

            $spreadsheet = IOFactory::load($absolutePath);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = $sheet->toArray(null, true, true, true);

            $format = (int) $request->input('format_type');
            $parsed = $this->parseExcelToStructure($rows, $format);

            if (empty($parsed['header']) || empty($parsed['details'])) {
                Storage::delete($path);
                return back()->with('error', 'Parsed data is empty. Please check your Excel template/mapping.')
                             ->withInput();
            }

            $datContent = $this->buildDatContent($parsed);

            // Nama file: {TIN}S{MM}{YYYY}.DAT
            $ownerTin = preg_replace('/[^0-9]/', '', ($parsed['header']['tin'] ?? 'TIN'));
            $period   = $parsed['header']['period_end'] ?? '01/01/1970'; // mm/dd/yyyy
            $mm       = date('m', strtotime($period));
            $yyyy     = date('Y', strtotime($period));
            $fileName = sprintf('%sS%s%s.DAT', $ownerTin, $mm, $yyyy);

            $savePath = "exports/{$fileName}";
            
            ActivityLog::create([
                'user'   => Auth::check() ? Auth::user()->username : 'guest',
                'action' => 'Generate DAT',
                'details'=> 'Generated file: '.$fileName.' from '.$request->file('excel_file')->getClientOriginalName()
            ]);

            Storage::delete($path);

            return response()->download(Storage::path($savePath))->deleteFileAfterSend(true);

        } catch (\Throwable $e) {
            Storage::delete($path);
            report($e);
            return back()->with('error', 'Failed converting Excel to .DAT: '.$e->getMessage())->withInput();
        }
    }

    /**
     * Parser yang meniru persis struktur "Source".
     * - Ambil header: tin, registered_name, trade_name, addr1, addr2
     * - Ambil detail: hanya baris valid (tanggal + TIN + angka)
     * - Header col11/col12 di-set 0.00 (belum pasti sumbernya)
     * - period_end = tanggal dari baris data pertama
     */
    private function parseExcelToStructure(array $rows, int $format): array
    {
        $tin = null;
        $registeredName = null;
        $tradeName = null;
        $addr1 = null;
        $addr2 = null;

        // ── Ambil data header dari kolom A sesuai sample
        $rowCount = count($rows);
        for ($idx = 1; $idx <= $rowCount; $idx++) {
            $cellA = trim((string)($rows[$idx]['A'] ?? ''));
            if ($cellA === '') continue;

            if (Str::startsWith($cellA, 'TIN')) {
                // "TIN : 646656861-000" → ambil 9 digit pertama
                $digits = preg_replace('/[^0-9]/', '', $cellA);
                $tin = substr($digits, 0, 9);
            } elseif (Str::startsWith($cellA, "OWNER'S NAME")) {
                $registeredName = trim(str_replace("OWNER'S NAME:", '', $cellA));
            } elseif (Str::startsWith($cellA, "OWNER'S TRADE NAME")) {
                $tradeName = trim(str_replace("OWNER'S TRADE NAME :", '', $cellA));
            } elseif (Str::startsWith($cellA, "OWNER'S ADDRESS")) {
                $addr1 = trim(str_replace("OWNER'S ADDRESS:", '', $cellA));

                // coba ambil addr2 dari baris berikutnya jika ada (masih bagian header)
                $nextA = trim((string)($rows[$idx+1]['A'] ?? ''));
                if ($nextA !== '' && !$this->looksLikeHeaderLabel($nextA)) {
                    $addr2 = $nextA;
                }
            }
        }

        // ── Ambil baris data (detail)
        $details = [];
        $firstPeriodEnd = null;
        $started = false;

        foreach ($rows as $r) {
            $a = trim((string)($r['A'] ?? '')); // taxable month
            $b = trim((string)($r['B'] ?? '')); // TIN customer
            $c = trim((string)($r['C'] ?? '')); // Registered Name
            $d = trim((string)($r['D'] ?? '')); // Name of Customer
            $e = trim((string)($r['E'] ?? '')); // Customer Address (gabung)
            $f = trim((string)($r['F'] ?? '')); //gross
            $g = (string)($r['G'] ?? '');       // Exempt
            $h = (string)($r['H'] ?? '');       // Zero
            $i = (string)($r['I'] ?? '');       // Taxable
            $j = (string)($r['J'] ?? '');       // Output Tax

            // Mulai ketika ketemu baris data pertama: tanggal valid + b berisi angka (TIN)
            if (!$started) {
                if ($this->isDateToken($a) && $this->isTinLike($b)) {
                    $started = true;
                } else {
                    continue;
                }
            }

            // Stop di footer / baris non-data (mis: "END OF REPORT")
            if (!$this->isDateToken($a)) {
                $upperA = strtoupper($a);
                if (strpos($upperA, 'END OF REPORT') !== false) {
                    break;
                }
                // skip baris aneh lain
                continue;
            }

            // Filter baris kosong/total yang tidak punya angka sama sekali
            $hasAnyAmount = ($this->hasNumber($g) || $this->hasNumber($h) || $this->hasNumber($i) || $this->hasNumber($j));
            if (!$hasAnyAmount && $c === '' && $d === '' && $e === '') {
                continue;
            }

            // Ambil period dari baris data pertama saja (biar stabil)
            if ($firstPeriodEnd === null) {
                $firstPeriodEnd = $this->excelDateToUS($a); // langsung pakai apa adanya (ex: "8/31/2025")
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

        // Header totals persis seperti “Source” (col11/12 = 0.00 dulu)
        $sumgross   = array_sum(array_column($details, 'gross_sales'));
        $sumOutputTax = array_sum(array_column($details, 'output_tax'));
        $sumExempt = array_sum(array_column($details, 'exempt'));
        $sumZeroRated = array_sum(array_column($details, 'zero_rated'));
        $sumTaxable = array_sum(array_column($details, 'taxable'));

        return [
            'header' => [
                'tin'               => $tin ?: '000000000',
                'registered_name'   => $registeredName ?: 'REGISTERED NAME',
                'trade_name'        => $tradeName ?: ($registeredName ?: 'TRADE NAME'),
                'addr1'             => $addr1 ?: '',
                'addr2'             => $addr2 ?: '',
                'exempt_total'      => round($sumExempt,2),                         
                'zero_rated_total'  => round($sumZeroRated,2),  
                'taxable_total'     => round($sumTaxable,2),                        
                'gross_total'       => round($sumgross, 2),
                'output_tax_total'  => round($sumOutputTax, 2),
                'col15'             => '051',                         // placeholder
                'period_end'        => $firstPeriodEnd ?: '01/01/1970',
                'col17'             => '12',                          // placeholder
            ],
            'details' => $details,
        ];
    }

    private function buildDatContent(array $parsed): string
    {
        $h = $parsed['header'];
        $lines = [];

        // HEADER: pastikan field 5–7 = "" (quoted empty)
        $headerRow = [
            'H','S',
            $this->q($h['tin']),
            $this->q($h['registered_name']),
            $this->q(''), $this->q(''), $this->q(''), // ← pos 5–7
            $this->q($h['trade_name']),
            $this->q($h['addr1']),
            $this->q($h['addr2']),
            $this->n($h['exempt_total']),
            $this->n($h['zero_rated_total']),
            $this->n($h['taxable_total']),
            $this->n($h['output_tax_total']),
            $h['col15'],
            $h['period_end'],   // mm/dd/yyyy (string dari excel)
            $h['col17'],
        ];
        $lines[] = implode(',', $headerRow);

        // DETAILS (tetap sama)
        foreach ($parsed['details'] as $d) {
            $detailRow = [
                'D','S',
                $this->q($d['customer_tin']),
                $this->q($d['customer_name']),
                '', '', '', // 3 empty reserved fields
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

    // ==== Helpers ====
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
        // Terima "m/d/Y" atau dengan jam, atau serial numeric
        if (is_numeric($s)) return true;
        if (preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}(?:\s+\d{1,2}:\d{2}:\d{2})?$#', $s)) return true;
        if (preg_match('#^\d{4}-\d{2}-\d{2}#', $s)) return true;
        return false;
    }
    private function excelDateToUS($value): string
    {
        // Jika numeric → serial date Excel
        if (is_numeric($value)) {
            $unix = ((int)$value - 25569) * 86400;
            return gmdate('m/d/Y', $unix); // ini sudah kasih leading zero
        }
        // Kalau sudah string tanggal → parse ulang supaya format mm/dd/YYYY dengan leading zero
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('m/d/Y', $ts); // → contoh: 08/31/2025
        }
        return trim((string)$value);
    }

    private function looksLikeHeaderLabel(string $text): bool
    {
        // Normalisasi
        $s = strtoupper(trim($text));

        // Baris judul/label umum di header Excel-mu
        $keywords = [
            'RECONCILIATION OF LISTING FOR ENFORCEMENT',
            'TIN', "OWNER'S NAME", "OWNER'S TRADE NAME", "OWNER'S ADDRESS",
            'TAXABLE', 'TAXPAYER', 'IDENTIFICATION', 'NUMBER',
            'REGISTERED NAME', 'ADDRESS', 'MONTH',
            'END OF REPORT',
        ];

        foreach ($keywords as $kw) {
            if (str_starts_with($s, $kw)) {
                return true;
            }
        }

        // Pola label kolom (1), (2), dst
        if (preg_match('/^\(\d+\)$/', $s)) {
            return true;
        }

        return false;
    }

    private function splitAddressFromE(string $e): array
    {
        $e = trim($e);
        if ($e === '') return ['', ''];

        // 1) ada koma → bagi di koma pertama
        $partsComma = preg_split('/\s*,\s*/', $e);
        if (count($partsComma) >= 2) {
            return [trim($partsComma[0]), trim(implode(', ', array_slice($partsComma, 1)))];
        }

        // 2) duplikat persis "X X" → keduanya sama
        if (preg_match('/^(.+?)\s+\1$/u', $e, $m)) {
            $p = trim($m[1]);
            return [$p, $p];
        }

        // 3) ada spasi ganda → anggap pemisah
        $partsWs = preg_split('/\s{2,}/', $e);
        if (count($partsWs) >= 2) {
            return [trim($partsWs[0]), trim(implode(' ', array_slice($partsWs, 1)))];
        }

        // 4) belah kira-kira di tengah kata (kalau kata >=4)
        $words = preg_split('/\s+/', $e);
        if (count($words) >= 4) {
            $mid = intdiv(count($words), 2);
            $left  = implode(' ', array_slice($words, 0,  $mid));
            $right = implode(' ', array_slice($words, $mid));
            return [trim($left), trim($right)];
        }

        // 5) fallback: gandakan
        return [$e, $e];
    }
}
