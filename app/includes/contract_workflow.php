<?php
/** Shared contract workflow transition rules. */
declare(strict_types=1);

if (!function_exists('t8_contract_workflow_transition_target')) {
    function t8_contract_workflow_transition_target(string $workflow, string $status, bool $isAdmin): ?string
    {
        $transitions = [
            'submit' => [['draft'], 'under_review'],
            'revert_to_draft' => [['under_review'], 'draft'],
            'approve' => [['under_review'], 'pending_approval'],
            'request_changes' => [['under_review'], 'negotiation'],
            'return_to_review' => [['negotiation'], 'under_review'],
            'sign' => [['pending_approval'], 'pending_signature'],
            'fully_execute' => [['pending_signature', 'partially_signed'], 'fully_executed'],
            'activate' => [['pending_signature', 'partially_signed', 'fully_executed'], 'active'],
            'reopen_for_revision' => [['pending_signature', 'partially_signed', 'fully_executed'], 'negotiation'],
            'renewal' => [['active'], 'renewal'],
            'cancel' => [['draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature', 'partially_signed', 'fully_executed'], 'cancelled'],
            'supersede' => [['active'], 'superseded'],
            'terminate' => [['active'], 'termination'],
            'expire' => [['active'], 'expiration'],
            'reject' => [['under_review'], 'archived'],
        ];
        $adminOnly = ['sign', 'fully_execute', 'activate', 'reopen_for_revision', 'cancel', 'supersede', 'terminate', 'expire'];

        if (!isset($transitions[$workflow])
            || !in_array($status, $transitions[$workflow][0], true)
            || (in_array($workflow, $adminOnly, true) && !$isAdmin)) {
            return null;
        }

        return $transitions[$workflow][1];
    }
}

if (!function_exists('t8_contract_activation_audit')) {
    function t8_contract_activation_audit(string $status, string $comment): ?array
    {
        if ($status === 'fully_executed') {
            return [
                'event_type' => 'ACTIVATED',
                'description' => 'Fully executed contract activated.',
                'metadata' => ['from_status' => 'fully_executed', 'to_status' => 'active'],
            ];
        }

        if (in_array($status, ['pending_signature', 'partially_signed'], true) && trim($comment) !== '') {
            return [
                'event_type' => 'ACTIVATED_WITHOUT_EXECUTION',
                'description' => 'Contract activated without recorded execution.',
                'metadata' => [
                    'from_status' => $status,
                    'to_status' => 'active',
                    'reason' => trim($comment),
                ],
            ];
        }

        return null;
    }
}
