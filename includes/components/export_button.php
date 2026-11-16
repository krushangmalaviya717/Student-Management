<?php
/**
 * UI Component: Excel / CSV Export Button
 * Standardized download button with professional SVG icon.
 */

if (!function_exists('render_export_button')) {
    function render_export_button(string $url = '', string $label = 'Export to Excel (CSV)'): string
    {
        if ($url === '') {
            $params = $_GET;
            $params['export'] = 'excel';
            $url = '?' . http_build_query($params);
        }

        return sprintf(
            '<a href="%s" class="btn" style="background:#16a34a;color:#ffffff;border:none;height:34px;padding:0 14px;border-radius:6px;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;cursor:pointer;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span>%s</span>
            </a>',
            htmlspecialchars($url),
            htmlspecialchars($label)
        );
    }
}
