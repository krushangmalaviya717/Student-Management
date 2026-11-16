<?php
/**
 * UI Component: KPI Summary Card
 * Reusable KPI stat card with clean, professional, human-designed aesthetics.
 */

if (!function_exists('render_kpi_card')) {
    function render_kpi_card(string $label, string|int|float $value, string $subtext = '', string $icon = '', string $theme = 'default'): string
    {
        $html = '<div class="kpi-card" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;box-shadow:0 1px 2px 0 rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">';
        $html .= '<div>';
        $html .= '<div style="font-size:12.5px;font-weight:500;color:#64748b;">' . htmlspecialchars($label) . '</div>';
        $html .= '<div style="font-size:26px;font-weight:700;color:#111827;line-height:1.2;margin-top:4px;letter-spacing:-0.02em;">' . htmlspecialchars((string)$value) . '</div>';
        if ($subtext !== '') {
            $html .= '<div style="font-size:12px;color:#64748b;margin-top:2px;">' . htmlspecialchars($subtext) . '</div>';
        }
        $html .= '</div>';
        if ($icon !== '') {
            $html .= '<div style="width:32px;height:32px;border-radius:6px;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:14px;">' . $icon . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
