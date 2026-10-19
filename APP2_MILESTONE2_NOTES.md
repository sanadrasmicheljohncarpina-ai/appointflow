# AppointFlow — Milestone 2 Notes

## Workspace Setup completed

Milestone 2 implements the approved workspace/business configuration layer without changing the database schema.

### Workspace settings

Owner can now manage:

- Business name
- Business email
- Phone
- Timezone

Timezone values are validated against PHP's supported timezone identifiers. The workspace timezone is persisted in the existing `workspaces.timezone` column and is now available to workspace-aware date formatting.

The booking slug remains read-only because public booking URL behavior belongs to a later milestone.

### Team management

Owner can now:

- View workspace members
- Add consultants
- Edit consultant name/email
- Optionally reset a consultant password during edit
- Activate consultants
- Deactivate consultants

Owners cannot be edited or deactivated from the consultant management flow.

### Architecture

Business/team mutations are routed through:

- `app/services/WorkspaceService.php`
- `app/services/TeamService.php`

Pages remain responsible for request handling, authorization, validation, and presentation.

### Security

- Owner-only access is preserved.
- All state-changing forms use CSRF protection.
- Consultant operations are scoped to the authenticated workspace.
- Consultant email uniqueness is enforced in the application before update/create.
- Passwords continue to use `password_hash()`.

## Database rule

No manual database change was made for Milestone 2.

The existing `workspaces` and `users` schema already contains the fields needed for the milestone.
