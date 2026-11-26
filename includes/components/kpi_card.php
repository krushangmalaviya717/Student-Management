<?php
/**
 * UI Component: KPI Summary Card
 * Reusable KPI stat card aligned with the enterprise design system.
 */

if (!function_exists('render_kpi_card')) {
    function render_kpi_card(string $label, string|int|float $value, string $subtext = '', string $icon = '', string $theme = 'default'): string
    {
        $themeClass = match ($theme) {
            'primary', 'success', 'warning', 'danger' => ' kpi-' . $theme,
            default => '',
        };
        $valClass = (is_string($value) && strlen($value) > 14 && !str_starts_with($value, '₹')) ? 'kpi-value-text' : 'kpi-value';

        $html = '<div class="kpi-card' . $themeClass . '">';
        $html .= '<div class="kpi-body">';
        $html .= '<div class="kpi-label">' . htmlspecialchars($label) . '</div>';
        $html .= '<div class="' . $valClass . '">' . htmlspecialchars((string)$value) . '</div>';
        if ($subtext !== '') {
            $html .= '<div class="kpi-subtext">' . htmlspecialchars($subtext) . '</div>';
        }
        $html .= '</div>';
        if ($icon !== '') {
            $html .= '<div class="kpi-icon">' . $icon . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
