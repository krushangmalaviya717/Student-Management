<?php
/**
 * UI Component: Excel / CSV Export Button
 * Standardized download button with clean SVG icon.
 */

if (!function_exists('render_export_button')) {
    function render_export_button(string $url = '', string $label = 'Export to Excel (CSV)', string $size = 'sm'): string
    {
        if ($url === '') {
            $params = $_GET;
            $params['export'] = 'excel';
            $url = '?' . http_build_query($params);
        }

        $sizeClass = $size === 'md' ? '' : ' btn-sm';

        return sprintf(
            '<a href="%s" class="btn btn-secondary btn-export%s">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span>%s</span>
            </a>',
            htmlspecialchars($url),
            $sizeClass,
            htmlspecialchars($label)
        );
    }
}
