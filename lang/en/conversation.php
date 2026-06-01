<?php

declare(strict_types=1);

return [
    'statuses' => [
        'open' => 'Active',
        'closed' => 'Closed',
    ],
    'inbox_statuses' => [
        'ai_handling' => 'AI handling',
        'teammate_pending' => 'Awaiting human',
        'teammate_handling' => 'Human handling',
    ],
    'visitor_reply_statuses' => [
        'waiting' => 'Awaiting visitor reply',
        'not_waiting' => 'Not awaiting visitor reply',
    ],
    'reply_assistant_modes' => [
        'reply' => 'Help me reply',
        'rewrite' => 'Rewrite',
    ],
    'reply_polish_tones' => [
        'keep' => 'Keep tone',
        'professional' => 'Professional',
        'friendly' => 'Friendly',
        'concise' => 'Concise',
    ],
    'sources' => [
        'manual' => 'Manual',
        'channel' => 'Channel',
    ],
    'entry_modes' => [
        'widget' => 'Widget',
        'standalone' => 'Standalone',
        'telegram' => 'Telegram',
    ],
    'message_roles' => [
        'visitor' => 'Visitor',
        'ai' => 'AI',
        'teammate' => 'Teammate',
        'tool' => 'Tool',
    ],
    'message_kinds' => [
        'text' => 'Text',
        'image' => 'Image',
        'file' => 'File',
        'summary' => 'Summary',
        'tool_call' => 'Tool Call',
        'tool_result' => 'Tool Result',
    ],
    'message_delivery_statuses' => [
        'sending' => 'Sending',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ],
    'auto_message_triggers' => [
        'ai_welcome' => 'AI reception greeting',
        'teammate_joined' => 'Teammate joined',
        'teammate_transferred' => 'Teammate transferred',
    ],
    'event_types' => [
        'created' => 'Created',
        'assignment_changed' => 'Assignment Changed',
        'handoff_requested' => 'Handoff Requested',
        'status_changed' => 'Status Changed',
        'reception_turn_started' => 'Reception Turn Started',
        'reception_tool_called' => 'Reception Tool Called',
        'reception_turn_ended' => 'Reception Turn Ended',
        'auto_message_translation_failed' => 'Automatic Message Translation Failed',
    ],
    'event_displays' => [
        'actors' => [
            'system' => 'System',
        ],
        'facts' => [
            'auto_message' => 'Automatic message',
        ],
        'created' => [
            'reception' => 'The visitor started this conversation from the web widget',
            'manual' => ':actor manually created this conversation',
        ],
        'handoff_requested' => [
            'user_requested' => 'Visitor requested a human',
            'ai_requested' => 'AI decided this conversation needs a human',
            'low_confidence' => 'AI was not sure how to answer, so it handed off',
            'tool_failure' => 'AI hit an issue while handling this, so it handed off',
            'policy_required' => 'Business rules require human handling',
            'ai_unavailable' => 'AI is temporarily unavailable, handed off to human',
        ],
        'assignment_changed' => [
            'claim' => ':actor claimed this conversation',
            'reply' => ':actor replied and claimed this conversation',
            'transfer_to_human' => ':actor took over the conversation AI was handling',
            'takeover' => ':actor took over this conversation from :previous_user',
            'transfer_to_teammate' => ':actor transferred this conversation to :target',
            'release_to_ai' => ':actor handed this conversation to AI',
        ],
        'status_changed' => [
            'closed' => ':actor closed the conversation',
            'open' => ':actor reopened the conversation',
        ],
        'reception_tool_called' => [
            'dispatch_task' => [
                'summary' => 'AI started a background task',
            ],
            'dispatch_task_limit' => [
                'summary' => 'AI cannot start more background tasks',
            ],
            'cancel_task' => [
                'summary' => 'AI cancelled a background task',
            ],
            'handoff_unavailable' => [
                'no_online_teammate' => 'No teammate is online, so AI is continuing the conversation',
                'outside_business_hours' => 'It is outside service hours, so AI is continuing the conversation',
            ],
        ],
        'reception_turn_ended' => [
            'timeout' => 'AI response timed out, and the visitor message was not answered',
            'error' => 'AI hit an issue during reception and stopped',
            'max_iterations' => 'AI tried multiple steps but could not resolve this and stopped',
        ],
        'auto_message_translation_failed' => [
            'skip' => 'Automatic message failed: translation is unavailable, message not sent',
            'send_original' => 'Automatic message sent as original text: translation is unavailable',
        ],
    ],
    'errors' => [
        'invalid_role_kind_combination' => 'The message role and kind combination is invalid.',
        'empty_message' => 'Message content cannot be empty.',
        'message_too_long' => 'Message is too long, please split it.',
        'ai_reply_not_allowed' => 'The conversation was handed off to a teammate; AI cannot continue to reply.',
        'transfer_to_human_required_before_reply' => 'Transfer this AI-handled conversation to a human before replying.',
        'reply_not_allowed_for_assignee' => 'This conversation is assigned to another teammate.',
        'reply_translation_stale' => 'The visitor language changed. Confirm the translated content again before sending.',
        'reply_polish_failed' => 'AI reply assistant failed. Please try again later.',
        'close_not_allowed_for_assignee' => 'This conversation is assigned to another teammate and cannot be closed by you.',
        'already_ai_handling' => 'This conversation is already handled by AI.',
        'release_to_ai_not_allowed' => 'You can only release conversations assigned to you back to AI.',
        'already_closed' => 'Conversation is closed; no further actions allowed.',
        'already_open' => 'Conversation is already open.',
        'reopen_conflicts_with_open_conversation' => 'This contact already has an open conversation in this channel.',
        'claim_failed' => 'Failed to claim this conversation; it may already be taken.',
        'transfer_to_teammate_not_allowed' => 'You can only transfer conversations currently assigned to you.',
        'transfer_target_must_be_teammate' => 'Choose another teammate as the transfer target.',
        'transfer_target_not_found' => 'Choose a teammate in the current workspace.',
        'recall_not_owner' => 'You can only recall messages you sent.',
        'recall_already_recalled' => 'This message has already been recalled.',
        'recall_kind_not_allowed' => 'This kind of message cannot be recalled.',
        'recall_window_expired' => 'Messages older than :minutes minutes cannot be recalled.',
        'message_not_found' => 'The message does not exist or has been deleted.',
    ],
    'empty_content' => 'No content',
    'message_recalled_placeholder' => '[Message recalled]',
];
