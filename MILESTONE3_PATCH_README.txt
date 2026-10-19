AppointFlow Milestone 3 Patch
================================

This patch fixes a local installation that is still running the Milestone 2
file set. The 404 at /appointflow/public/services.php occurs because
public/services.php was not present in the Milestone 2 package.

Extract this ZIP directly into:
C:\xampp\htdocs\

Allow Windows to replace existing files when prompted.

The patch adds:
- public/services.php
- public/availability.php
- app/services/ServiceCatalogService.php

and updates:
- app/services/AvailabilityService.php
- app/validation.php
- app/views/layouts/header.php
- assets/css/app.css

Database/migration files are intentionally NOT included or changed.

Do NOT remove storage/install.lock and do NOT rerun the installer.

After extraction, test:
http://localhost/appointflow/public/services.php
http://localhost/appointflow/public/availability.php
