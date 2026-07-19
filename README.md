# Shop Infrastructure

Infrastructure for the online electronics store.

## Environment

- Hypervisor: Proxmox VE
- VM: shop-infra
- OS: Debian 13
- IP: 10.10.10.20

## Directories

- `compose/` — service definitions
- `configs/` — non-secret configuration
- `docs/` — documentation
- `scripts/` — maintenance scripts
- `templates/` — configuration templates

Persistent application data is stored outside this repository:

- `/opt/data`
- `/opt/backups`
- `/opt/logs`

## Planned services

- reverse proxy
- store website
- database
- CRM
- Telegram integrations
- monitoring
- local AI agents
