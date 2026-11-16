<?php

namespace App\Modules\Bank\Support;

/**
 * Reads a bank statement CSV with the columns fecha, importe and concepto.
 *
 * Accepted: comma or semicolon delimiter (taken from the header), dates as dd/mm/yyyy or yyyy-mm-dd,
 * amounts with an optional sign, up to six integer digits and two decimals (comma or dot, no thousands
 * separator), UTF-8 or Windows-1252 text. A concept cannot contain line breaks.
 */
class StatementParser
{
    public const MAX_CONCEPT = 255;

    private const COLUMNS = ['fecha', 'importe', 'concepto'];

    /**
     * @return array{rows: list<array{line: int, date: string, cents: int, concept: string}>, rejected: list<array{line: int, reason: string}>}
     *
     * @throws InvalidStatement
     */
    public function parse(string $content): array
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = (string) preg_replace('/^\x{FEFF}/u', '', $content);

        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $headerLine = $lines[0] ?? '';
        if (trim($headerLine) === '') {
            throw new InvalidStatement('El archivo está vacío o no tiene la fila de cabecera.');
        }

        $delimiter = str_contains($headerLine, ';') ? ';' : ',';
        $header = array_map(fn (?string $name) => mb_strtolower(trim((string) $name)), str_getcsv($headerLine, $delimiter, '"', ''));
        $position = [];
        foreach (self::COLUMNS as $column) {
            $index = array_search($column, $header, true);
            if ($index === false) {
                throw new InvalidStatement('El archivo debe tener las columnas fecha, importe y concepto.');
            }
            $position[$column] = $index;
        }

        $rows = [];
        $rejected = [];
        $seenData = false;
        foreach (array_slice($lines, 1, null, true) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $seenData = true;
            $number = $index + 1;
            $fields = str_getcsv($line, $delimiter, '"', '');

            if (count($fields) !== count($header)) {
                $rejected[] = ['line' => $number, 'reason' => 'Número de columnas incorrecto.'];

                continue;
            }

            $date = $this->date((string) $fields[$position['fecha']]);
            $cents = $this->cents((string) $fields[$position['importe']]);
            $concept = trim((string) $fields[$position['concepto']]);

            $reason = match (true) {
                $date === null => 'Fecha no válida; usa dd/mm/aaaa o aaaa-mm-dd.',
                $cents === null => 'Importe no válido; usa hasta dos decimales y sin separador de miles.',
                $cents === 0 => 'El importe no puede ser cero.',
                $concept === '' => 'Falta el concepto.',
                mb_strlen($concept) > self::MAX_CONCEPT => 'El concepto supera los '.self::MAX_CONCEPT.' caracteres.',
                default => null,
            };

            if ($reason !== null) {
                $rejected[] = ['line' => $number, 'reason' => $reason];

                continue;
            }

            $rows[] = ['line' => $number, 'date' => (string) $date, 'cents' => (int) $cents, 'concept' => $concept];
        }

        if (! $seenData) {
            throw new InvalidStatement('El archivo no contiene movimientos.');
        }

        return ['rows' => $rows, 'rejected' => $rejected];
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private function cents(string $value): ?int
    {
        if (preg_match('/^(-?)(\d{1,6})(?:[.,](\d{1,2}))?$/', trim($value), $m) !== 1) {
            return null;
        }

        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }
}
