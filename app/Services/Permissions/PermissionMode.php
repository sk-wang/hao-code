<?php

namespace App\Services\Permissions;

enum PermissionMode: string
{
    case Default = 'default';
    case Plan = 'plan';
    case AcceptEdits = 'accept_edits';
    case BypassPermissions = 'bypass_permissions';
}
