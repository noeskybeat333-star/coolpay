# Architecture

## Proxmox host

- Management IP: 10.10.10.10
- Application services must not run directly on the Proxmox host

## Virtual machines

### shop-infra

- IP: 10.10.10.20
- Purpose: infrastructure and store services

### VPN gateway

- Purpose: secure external access
- Configured separately

## Isolation rules

- Proxmox management: LAN and VPN only
- SSH: LAN and VPN only
- Databases: internal access only
- Public access: only through reverse proxy
- Secrets: never stored in Git
