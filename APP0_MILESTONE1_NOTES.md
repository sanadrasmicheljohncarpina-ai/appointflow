# AppointFlow Foundation — Milestone 1 Notes

This package was created from the supplied `appointment-booker` starter repository. The starter's legacy customer/admin appointment model was not carried forward as the active application model because the approved AppointFlow Technical Implementation Plan replaces it with the Workspace → Client → Engagement → Appointment architecture.

## What was established

- New `appointflow` database schema with 15 ordered migrations.
- New application helpers for PDO, sessions, CSRF, auth, permissions, validation, and security.
- Service-layer skeleton for engagements, appointments, booking conflicts, availability, notifications, and follow-ups.
- Workspace/owner setup through a localhost-only installer.
- Owner/consultant login and protected dashboard.
- Owner team management and basic workspace settings.
- Clean AppointFlow branding and navigation.

## Deliberately not implemented yet

The rest of the product is left for later milestones so the project does not bypass the approved dependency order. In particular, the booking engine, customer inquiry flow, qualification transitions, secure client tokens, meeting workflow, reminders, and full operational UI are not being guessed into Milestone 1.

## Important schema note

The supplied Technical Implementation Plan does not include a `price` field in `services`, even though the earlier product sketch mentioned prices. This build follows the exact technical schema supplied. Pricing can be added in a deliberate schema/spec revision later rather than silently changing the source of truth.
