# Shared core

This directory will contain CMS-neutral inspection, redaction, report-schema, and export code shared by the platform applications.

Extraction will be incremental. Platform APIs, permissions, storage, translations, database access, and administration interfaces remain in their respective applications. Every release ZIP must bundle the runtime code it needs and must not depend on a monorepo checkout.
