<?php
/**
 * UI Component: Status Badge
 * Standardized status badges with bilingual English + Gujarati labels.
 */

if (!function_exists('render_status_badge')) {
    function render_status_badge(string $status): string
    {
        $status = strtolower(trim($status));
        $config = [
            'active' => [
                'label' => 'Active (ચાલુ)',
                'class' => 'badge-active',
                'bg' => '#ecfdf5',
                'text' => '#065f46',
                'border' => '#a7f3d0'
            ],
            'extended' => [
                'label' => 'Extended (મુદત લંબાવેલ)',
                'class' => 'badge-extended',
                'bg' => '#fffbeb',
                'text' => '#92400e',
                'border' => '#fde68a'
            ],
            'paused' => [
                'label' => 'On Hold (સ્ટોપ / મોકૂફ)',
                'class' => 'badge-paused',
                'bg' => '#fef3c7',
                'text' => '#b45309',
                'border' => '#fcd34d'
            ],
            'course_completed' => [
                'label' => 'Completed (કોર્સ પૂર્ણ)',
                'class' => 'badge-completed',
                'bg' => '#eff6ff',
                'text' => '#1e40af',
                'border' => '#bfdbfe'
            ],
            'waiting_ready' => [
                'label' => 'Waiting: Ready (તૈયાર)',
                'class' => 'badge-waiting-ready',
                'bg' => '#f0fdf4',
                'text' => '#166534',
                'border' => '#bbf7d0'
            ],
            'waiting_future_date' => [
                'label' => 'Waiting: Future Date',
                'class' => 'badge-waiting',
                'bg' => '#fef3c7',
                'text' => '#78350f',
                'border' => '#fde68a'
            ],
            'waiting_date_not_confirmed' => [
                'label' => 'Waiting: Date Pending',
                'class' => 'badge-waiting',
                'bg' => '#f3f4f6',
                'text' => '#374151',
                'border' => '#e5e7eb'
            ],
            'joining_confirmed' => [
                'label' => 'Joining Confirmed',
                'class' => 'badge-joining',
                'bg' => '#ecfdf5',
                'text' => '#047857',
                'border' => '#a7f3d0'
            ],
            'cancelled' => [
                'label' => 'Cancelled (રદ કરેલ)',
                'class' => 'badge-cancelled',
                'bg' => '#fef2f2',
                'text' => '#991b1b',
                'border' => '#fecaca'
            ],
            'inactive' => [
                'label' => 'Inactive (નિષ્ક્રિય)',
                'class' => 'badge-inactive',
                'bg' => '#f3f4f6',
                'text' => '#4b5563',
                'border' => '#e5e7eb'
            ]
        ];

        $badge = $config[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'class' => 'badge-default',
            'bg' => '#f3f4f6',
            'text' => '#374151',
            'border' => '#d1d5db'
        ];

        return sprintf(
            '<span class="badge %s" style="background:%s;color:%s;border:1px solid %s;padding:3px 8px;border-radius:12px;font-size:12px;font-weight:600;display:inline-block;">%s</span>',
            htmlspecialchars($badge['class']),
            $badge['bg'],
            $badge['text'],
            $badge['border'],
            htmlspecialchars($badge['label'])
        );
    }
}
