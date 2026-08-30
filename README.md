# Phantom C2 – Red Team Tool

Educational C2 framework for authorized penetration testing and red team exercises.

## Version 4.1 Enhancements

- **Instant essential data collection**: Hardware info, WiFi passwords, and browser password databases are collected immediately upon agent start and sent to the server.
- **Permanent storage**: Collected data is never automatically deleted.
- **Security**: All V4 security improvements retained (POST auth, hashed tokens, allow-list, TTL).

## Components

- `c2.php` – C2 server
- `agents/phantom.bat` – Windows agent (with instant collection)
- `agents/phantom.sh` – Linux agent (with instant collection)
- `phantom_master.sh` – Master control dashboard
- `config.php` – Fail-closed configuration
- `docker-compose.yml` – Isolated environment

## Security Model

- Master auth: POST body
- Agent auth: Hashed tokens (SHA-256)
- Upload validation: Strict allow-listing
- Path safety: Canonical path validation
- Fail-closed: Missing secrets stop startup

## Setup

1. Create `.env` with required secrets
2. `docker-compose up -d`
3. Deploy agents on **authorized test systems only**
4. Use `phantom_master.sh` to control

## ⚠️ Legal Warning

**For authorized use only. Unauthorized deployment is illegal.**