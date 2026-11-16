<?php
/**
 * ExportService
 * Centralized service for Excel/CSV exports with proper UTF-8 BOM encoding for Gujarati script.
 */

class ExportService
{
    /**
     * Stream a CSV file directly to the browser for download.
     *
     * @param string $filename Download filename (e.g. 'Students_2026-10-15.csv')
     * @param array $headers List of column header titles
     * @param array $rows Array of row arrays matching the headers
     */
    public static function downloadCsv(string $filename, array $headers, array $rows): void
    {
        // Clean output buffer if open to avoid corrupted CSV
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');

        // UTF-8 Byte Order Mark (BOM) so Excel renders Gujarati fonts accurately
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Write header row
        fputcsv($out, $headers);

        // Write data rows
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }
}
