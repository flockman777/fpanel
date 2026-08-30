#!/usr/bin/env python3
"""Sync FPanel mail JSON (vhosts/*.mail.json) into Postfix + Dovecot.

Reads the mail provisioning files written by the panel and generates:
  - /etc/postfix/vmailbox          (virtual_mailbox_maps)
  - /etc/postfix/virtual_aliases   (virtual_alias_maps)
  - /etc/dovecot/passwd            (passwd-file, bcrypt scheme)

Then reloads postfix + dovecot.
Run as root. Safe to call repeatedly / via cron.
"""

import json
import os
import subprocess

VHOSTS = os.environ.get("FPANEL_VHOSTS", "/opt/fpanel/panel/vhosts")
MAILROOT = "/var/mail/vhosts"
UID = GID = "5000"


def add_scheme(h: str) -> str:
    if h.startswith("{"):
        return h
    return "{BLF-CRYPT}" + h


def main() -> None:
    mailboxes: list[str] = []
    aliases: list[str] = []
    passwd: list[str] = []

    for fn in sorted(os.listdir(VHOSTS)):
        if not fn.endswith(".mail.json"):
            continue
        path = os.path.join(VHOSTS, fn)
        try:
            with open(path, encoding="utf-8") as f:
                p = json.load(f)
        except Exception as e:  # noqa: BLE001
            print(f"skip {fn}: {e}")
            continue

        dom = p.get("domain", fn[: -len(".mail.json")])
        for a in p.get("accounts", []):
            local = a.get("local", "")
            if not local:
                continue
            addr = f"{local}@{dom}"
            mailboxes.append(f"{addr} {dom}/{local}")
            quota = int(a.get("quota_mb") or 256)
            passwd.append(
                f"{addr}:{add_scheme(a['password_hash'])}:{UID}:{GID}::"
                f"{MAILROOT}/{dom}/{local}::userdb_quota_rule=*:storage={quota}M"
            )
            os.makedirs(f"{MAILROOT}/{dom}/{local}", exist_ok=True)
        for r in p.get("forwarders", []):
            to = r.get("to", [])
            if not to or r.get("status", "active") != "active":
                continue
            aliases.append(f"{r['from']} {','.join(to)}")

    def write(path: str, lines: list[str]) -> None:
        with open(path, "w", encoding="utf-8") as f:
            f.write("\n".join(lines) + ("\n" if lines else ""))

    write("/etc/postfix/vmailbox", mailboxes)
    write("/etc/postfix/virtual_aliases", aliases)
    write("/etc/dovecot/passwd", passwd)

    os.system(f"chown -R {UID}:{GID} {MAILROOT}")
    subprocess.run(["postmap", "/etc/postfix/vmailbox"], check=False)
    subprocess.run(["postmap", "/etc/postfix/virtual_aliases"], check=False)
    subprocess.run(["postfix", "reload"], check=False)
    subprocess.run(["doveadm", "reload"], check=False)
    print(f"synced {len(mailboxes)} mailboxes, {len(aliases)} aliases")


if __name__ == "__main__":
    main()