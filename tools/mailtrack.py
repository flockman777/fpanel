#!/usr/bin/env python3
import asyncio
import base64
import email
import email.utils
import hashlib
import html
import json
import os
import re
import sqlite3
import sys
import urllib.parse
from email.message import EmailMessage

SMTP_IN_PORT = 10025
SMTP_OUT_HOST = "127.0.0.1"
SMTP_OUT_PORT = 10026
HTTP_PORT = 8090
BASE = "https://mail.fpanel.my.id"
DB = "/var/log/mailtrack.db"

HEADERS = re.compile(rb"\r\n\r\n", re.S)
LTAG = re.compile(r"<\s*/?\s*(body|div|table|td|tr|p|span|html)[^>]*>", re.I)
LINK = re.compile(r"(?is)(href|src)=([\"'])(https?://[^\"']+?)\2")
GIF = base64.b64decode(
    "R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
)

# keep settings stable across calls
_pixel_cache = {}


def db():
    con = sqlite3.connect(DB, timeout=5)
    con.execute(
        """CREATE TABLE IF NOT EXISTS deliveries (
            token TEXT PRIMARY KEY, msgid TEXT, from_addr TEXT, to_addr TEXT,
            subject TEXT, ts TEXT, html INTEGER)
        """
    )
    con.execute(
        """CREATE TABLE IF NOT EXISTS opens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT, ts TEXT, ip TEXT, ua TEXT)
        """
    )
    con.execute(
        """CREATE TABLE IF NOT EXISTS clicks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT, url TEXT, ts TEXT, ip TEXT, ua TEXT)
        """
    )
    con.commit()
    return con


def new_token(msg):
    mid = msg.get("Message-ID", "")
    lbl = mid.strip("<>").split("@", 1)[0]
    hx = "".join(c for c in lbl if c in "0123456789abcdefABCDEF")
    if len(hx) >= 8:
        t = hx[-12:]
    else:
        t = hashlib.md5(mid.encode()).hexdigest()[:12]
    return t


def rewrite_html(body, token):
    u = f"{BASE}/t/o/{token}.png"
    pixel = f'<img src="{u}" width="1" height="1" alt="" style="display:none!important;width:1px!important;height:1px!important;max-width:1px!important;min-width:1px!important;max-height:1px!important;min-height:1px!important;border:0!important;outline:0!important" aria-hidden="true"/>'
    if re.search(r"(?i)</body", body):
        body = re.sub(r"(?is)(</body[^>]*>)", lambda m: pixel + m.group(1), body, count=1)
    else:
        body = pixel + body

    def wrap(m):
        attr, quote, url = m.group(1), m.group(2), m.group(3)
        if url.startswith(BASE):
            return m.group(0)
        return f'{attr}={quote}{BASE}/t/c/{token}?u={urllib.parse.quote(url, safe="")}{quote}'
    return token, LINK.sub(wrap, body)


def rewrite_msg(data: bytes, msg):
    """Inject pixel + wrap links in HTML parts. Return (token, saw_html, new_data)."""
    token = new_token(msg)
    saw_html = False
    m = email.message_from_bytes(data)

    def rec(part):
        nonlocal saw_html
        if part.is_multipart():
            for sub in part.get_payload():
                if isinstance(sub, email.message.Message):
                    rec(sub)
            return
        if part.get_content_type() == "text/html":
            ch = part.get_content_charset() or "utf-8"
            try:
                payload = part.get_payload(decode=True).decode(ch, errors="replace")
            except Exception:
                return
            _, new_body = rewrite_html(payload, token)
            part.set_payload(new_body)
            part.set_charset("utf-8")
            part.replace_header("Content-Transfer-Encoding", "base64")
            saw_html = True

    rec(m)
    if saw_html:
        return token, True, m.as_bytes()
    return token, False, data


class SMTPIn(asyncio.Protocol):
    def __init__(self, loop):
        self.loop = loop
        self.buf = b""
        self.state = "banner"
        self.from_addr = None
        self.rcpt = []
        self.data_mode = False

    def connection_made(self, transport):
        self.transport = transport
        transport.write(b"220 mail.fpanel.my.id ESMTP mailtrack\r\n")

    def data_received(self, data):
        self.buf += data
        if self.data_mode:
            if b"\r\n.\r\n" in self.buf:
                data, _, rest = self.buf.partition(b"\r\n.\r\n")
                self.buf = rest
                self.data_mode = False
                self.loop.create_task(self.handle_data(data))
            return
        while b"\r\n" in self.buf or b"\n" in self.buf:
            line, sep, rest = self.buf.partition(b"\r\n" if b"\r\n" in self.buf else b"\n")
            self.buf = rest
            line = line.strip()
            if not line:
                continue
            cmd = line.decode("utf-8", "replace")
            up = cmd.upper()
            if up.startswith("EHLO") or up.startswith("HELO"):
                self.transport.write(b"250-mail.fpanel.my.id\r\n250-8BITMIME\r\n250 SIZE 52428800\r\n")
            elif up.startswith("MAIL FROM:"):
                s = cmd[len("MAIL FROM:"):].strip()
                if s.startswith("<"):
                    s = s[1:].split(">")[0]
                self.from_addr = s
                self.transport.write(b"250 Ok\r\n")
            elif up.startswith("RCPT TO:"):
                s = cmd[len("RCPT TO:"):].strip()
                if s.startswith("<"):
                    s = s[1:].split(">")[0]
                self.rcpt.append(s)
                self.transport.write(b"250 Ok\r\n")
            elif up == "DATA":
                self.data_mode = True
                self.transport.write(b"354 End data with <CR><LF>.<CR><LF>\r\n")
            elif up == "RSET":
                self.from_addr = None
                self.rcpt = []
                self.transport.write(b"250 Ok\r\n")
            elif up == "QUIT":
                self.transport.write(b"221 Bye\r\n")
                self.transport.close()
                return
            else:
                self.transport.write(b"250 Ok\r\n")

    async def handle_data(self, data):
        try:
            msg = email.message_from_bytes(data)
            token, saw_html, out = rewrite_msg(data, msg)
            # peek headers
            m = email.message_from_bytes(data)
            subject = m.get("Subject", "")[:255]
            text = {}
            for line in data.split(b"\r\n")[:30]:
                lk = line.decode("utf-8", "replace")
                if lk.lower().startswith("to:"):
                    text["to"] = lk[3:].strip()[:255]
                if lk.lower().startswith("from:"):
                    text["from"] = lk[5:].strip()[:255]
            con = db()
            con.execute(
                "INSERT OR IGNORE INTO deliveries(token,msgid,from_addr,to_addr,subject,ts,html) "
                "VALUES(?,?,?,?,?,?,?)",
                (token, m.get("Message-ID", "")[:255], text.get("from", ""), text.get("to", ""),
                 subject, email.utils.formatdate(localtime=True), 1 if saw_html else 0),
            )
            con.commit()
            con.close()
            ok = await self.send_out(out, self.rcpt)
            self.transport.write(b"250 OK, queued as " + token.encode() + b"\r\n" if ok else b"550 Rejected\r\n")
        except Exception as e:
            print(f"ERR {e}", file=sys.stderr)
            self.transport.write(b"451 Temporary failure\r\n")

    async def send_out(self, data, rcpts):
        loop = asyncio.get_running_loop()
        try:
            ok = await loop.run_in_executor(None, self._send_out_sync, data, list(rcpts))
            return ok
        except Exception as e:
            print(f"OUTERR {e}", file=sys.stderr)
            return False

    def _send_out_sync(self, data, rcpts):
        import smtplib

        s = smtplib.SMTP(SMTP_OUT_HOST, SMTP_OUT_PORT, timeout=20)
        s.ehlo("mail.fpanel.my.id")
        try:
            s.sendmail(self.from_addr, rcpts, data)
        except Exception as e:
            import traceback

            traceback.print_exc()
            raise
        finally:
            try:
                s.quit()
            except Exception:
                pass
        return True


class EmailClient:
    def __init__(self, r, w):
        self.r, self.w = r, w

    async def _read(self):
        line = await self.r.readline()
        return line.decode("utf-8", "replace").strip()

    async def send_banner_helo_mail(self, from_addr, rcpts, data):
        await self._read()  # banner
        self.w.write(b"EHLO mail.fpanel.my.id\r\n")
        await self.w.drain()
        while True:
            line = await self.r.readline()
            if not line:
                break
            if line[3:4] == b" ":
                break
        self.w.write(f"MAIL FROM:<{from_addr}>\r\n".encode())
        await self.w.drain()
        await self._read()
        for r in rcpts:
            self.w.write(f"RCPT TO:<{r}>\r\n".encode())
            await self.w.drain()
            await self._read()
        self.w.write(b"DATA\r\n")
        await self.w.drain()
        await self._read()
        self.w.write(data + b"\r\n.\r\n")
        await self.w.drain()
        resp = await self._read()
        self.w.write(b"QUIT\r\n")
        await self.w.drain()
        self.w.close()
        return resp.startswith("250")


async def handle_http(reader, writer):
    try:
        req_line = await reader.readline()
        if not req_line:
            writer.close()
            return
        parts = req_line.decode().split()
        if len(parts) < 2:
            writer.close()
            return
        method, path = parts[0], parts[1]
        headers = {}
        while True:
            line = await reader.readline()
            if line in (b"\r\n", b"\n", b""):
                break
            k, _, v = line.decode().partition(":")
            headers[k.strip().lower()] = v.strip()
        ip = headers.get("x-forwarded-for", "").split(",")[0].strip() or (writer.get_extra_info("peername") or ("",))[0]
        ua = headers.get("user-agent", "")[:255]
        con = db()
        now = email.utils.formatdate(localtime=True)
        if path.startswith("/t/o/"):
            token = path[5:].rsplit(".", 1)[0]
            con.execute("INSERT INTO opens(token,ts,ip,ua) VALUES(?,?,?,?)", (token, now, ip, ua))
            con.commit()
            body = GIF
            writer.write(b"HTTP/1.1 200 OK\r\nContent-Type: image/gif\r\nContent-Length: %d\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n" % len(body))
            writer.write(body)
        elif path.startswith("/t/c/"):
            token = path[5:].split("?", 1)[0]
            q = urllib.parse.parse_qs(urllib.parse.urlsplit(path).query)
            url = q.get("u", [""])[0]
            if not url.startswith(("https://", "http://")):
                url = "https://" + url if url else BASE
            con.execute("INSERT INTO clicks(token,url,ts,ip,ua) VALUES(?,?,?,?,?)", (token, url, now, ip, ua))
            con.commit()
            writer.write(b"HTTP/1.1 302 Found\r\nLocation: " + url.encode() + b"\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n")
        else:
            writer.write(b"HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
        con.close()
    except Exception as e:
        import traceback

        traceback.print_exc()
        print(f"HTTPERR {e}", file=sys.stderr)
    await writer.drain()
    writer.close()


async def main():
    db().close()
    loop = asyncio.get_running_loop()
    srv_smtp = await loop.create_server(lambda: SMTPIn(loop), host="127.0.0.1", port=SMTP_IN_PORT)
    srv_http = await asyncio.start_server(handle_http, host="127.0.0.1", port=HTTP_PORT)
    print(f"mailtrack: smtp :{SMTP_IN_PORT} -> :{SMTP_OUT_PORT}, http :{HTTP_PORT}", flush=True)
    await asyncio.gather(srv_smtp.serve_forever(), srv_http.serve_forever())


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        sys.exit(0)