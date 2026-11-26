<?php
/**
 * UI Component: Status Badge
 * Standardized status badges in clean English.
 */

if (!function_exists('render_status_badge')) {
    function render_status_badge(string $status): string
    {
        $status = strtolower(trim($status));
        $config = [
            'active' => [
                'label' => 'Active',
                'class' => 'badge-active badge-green',
            ],
            'extended' => [
                'label' => 'Extended',
                'class' => 'badge-extended badge-amber',
            ],
            'paused' => [
                'label' => 'On Hold',
                'class' => 'badge-paused badge-amber',
            ],
            'course_completed' => [
                'label' => 'Completed',
                'class' => 'badge-completed badge-blue',
            ],
            'waiting_ready' => [
                'label' => 'Waiting: Ready',
                'class' => 'badge-waiting-ready badge-green',
            ],
            'waiting_future_date' => [
                'label' => 'Waiting: Future Date',
                'class' => 'badge-waiting badge-amber',
            ],
            'waiting_date_not_confirmed' => [
                'label' => 'Waiting: Date Pending',
                'class' => 'badge-waiting badge-gray',
            ],
            'joining_confirmed' => [
                'label' => 'Joining Confirmed',
                'class' => 'badge-joining badge-green',
            ],
            'cancelled' => [
                'label' => 'Cancelled',
                'class' => 'badge-cancelled badge-red',
            ],
            'inactive' => [
                'label' => 'Inactive',
                'class' => 'badge-inactive badge-gray',
            ],
        ];

        $badge = $config[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'class' => 'badge-default badge-gray',
        ];

        return sprintf(
            '<span class="badge %s">%s</span>',
            htmlspecialchars($badge['class']),
            htmlspecialchars($badge['label'])
        );
    }
}
