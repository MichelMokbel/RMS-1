# Layla order label print agent

This outbound-only agent pulls `order_label_pdf` jobs from the existing RMS POS print queue and submits each PDF to a locally allowlisted operating-system printer queue. It does not accept commands, paths, URLs, or document types from RMS.

## Windows production setup

Do not use the Python files in this directory for a normal Windows printer workstation. In RMS, open **Settings → Order Label Printers**, save the printer profile, and select **Download Windows setup**. Run that generated PowerShell setup as an administrator on the Windows PC connected to the printer. RMS creates the print device and restricted credentials automatically; the setup installs the native Windows agent and starts it at boot.

The Python implementation below remains only as a developer and non-Windows fallback.

## Manual fallback provisioning

1. Install the printer's official driver and print a test page from the operating system.
2. Register this computer as an active RMS POS terminal with a unique `device_id`.
3. Use a dedicated active RMS user with POS access and only the required branch. Log in through `/api/pos/login` to obtain a device-bound Sanctum token.
4. Copy `config.example.json` outside the repository, restrict it to the service account, and insert the RMS URL, token, device ID, state database path, and exact local queue names.
5. For macOS/Linux, the default `lp` command is supported. On Windows, configure an installed silent PDF printer command as a local argument array using `{printer}` and `{file}` placeholders.
6. Run `python3 agent.py --config /protected/path/config.json`. Install it as a launchd, systemd, or Windows service only after the one-shot test succeeds.

The SQLite state file must be persistent. It records a job before OS submission so replayed claims cannot silently produce a second physical label. A crash during the OS handoff is marked ambiguous for manual review instead of guessing and risking a duplicate.

## Security and logs

The token and state database are local secrets and must not be committed. The agent validates PDF magic bytes, a 10 MB payload limit, media bounds, document type, target, and local queue allowlist. Logs contain job IDs and error codes only; customer names, addresses, tokens, label PDFs, and provider responses are excluded.
