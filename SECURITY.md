# IPDAMS security policy

IPDAMS is a private, access-controlled school and Division application development repository.

Never commit production credentials, database dumps, live school/personnel records, official signed documents, banking/check information, private backups, tokens, or unredacted logs. Use synthetic fixtures for all tests.

Do not add deploy credentials or workflows that connect to production. Report security issues privately to authorized project maintainers.

Each security-sensitive change must test authenticated school scoping, roles and individually assigned Special Functions, revocation, server-side API and print authorization, and append-only audit behavior. Division MOOE viewer permissions must stay read-only. Existing ICS/PAR, document numbering, inventory movements and financial-posting safeguards must not be bypassed.
