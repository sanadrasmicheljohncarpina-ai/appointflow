#!/usr/bin/env python3
"""Fake SMTP server for failure-injection tests. Usage: fake_smtp.py MODE PORT
Modes: ok, auth535, rcpt550, rcpt450, data554, hang (accept, say nothing), hang_after_banner,
       drop (close immediately), drip (banner one byte every 3s)."""
import socket, sys, threading, time

mode, port = sys.argv[1], int(sys.argv[2])

def handle(c):
    f = c.makefile('rwb', buffering=0)
    def send(s): f.write((s + "\r\n").encode())
    try:
        if mode == 'hang': time.sleep(120); return
        if mode == 'drop': return
        if mode == 'drip':
            for ch in "220 drip.test ESMTP ready\r\n":
                c.sendall(ch.encode()); time.sleep(3)
            return
        send("220 fake.test ESMTP")
        if mode == 'hang_after_banner': time.sleep(120); return
        while True:
            line = f.readline()
            if not line: return
            cmd = line.decode(errors='replace').strip().upper()
            if cmd.startswith('EHLO'): send("250-fake.test"); send("250 AUTH LOGIN")
            elif cmd.startswith('AUTH LOGIN'):
                send("334 VXNlcm5hbWU6")
                f.readline(); send("334 UGFzc3dvcmQ6")
                f.readline()
                if mode == 'auth535': send("535 5.7.8 Authentication credentials invalid")
                else: send("235 ok")
            elif cmd.startswith('MAIL FROM'): send("250 ok")
            elif cmd.startswith('RCPT TO'):
                if mode == 'rcpt550': send("550 5.1.1 Mailbox unavailable")
                elif mode == 'rcpt450': send("450 4.2.0 Greylisted, try later")
                else: send("250 ok")
            elif cmd == 'DATA':
                if mode == 'data554': send("554 5.6.0 Message rejected"); continue
                send("354 go")
                while True:
                    l = f.readline()
                    if not l or l == b".\r\n": break
                send("250 queued")
            elif cmd == 'QUIT': send("221 bye"); return
            else: send("250 ok")
    except Exception: pass
    finally:
        try: c.close()
        except Exception: pass

s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(('127.0.0.1', port)); s.listen(20)
while True:
    c, _ = s.accept()
    threading.Thread(target=handle, args=(c,), daemon=True).start()
