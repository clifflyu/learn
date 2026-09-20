#!/usr/bin/env python3
"""Q39 用的「可控 RTT + 明文嗅探」TCP 中继。

监听 --listen，转发到 --target，并且：
  1) --delay 给【每个包】加固定单向延迟（读线程打时间戳，写线程按
     recv_time+delay 发送）。这比「按 chunk 串行 sleep」更接近真实链路：
     服务端连发两个包时，两个包是并行在线上跑的，各自只吃一次延迟。
  2) 把两个方向的字节 + 到达时间落盘到 --out.<方向>.bin 与 .log，
     于是握手过程可以逐条消息还原，也能直接 grep 明文。

用法：
  python3 03-proxy.py --listen 127.0.0.1:9443 --target 172.24.0.4:8443 \
      --delay 25 --out /tmp/q39local &
  curl -tls1_3 --resolve q39.local:9443:127.0.0.1 https://q39.local:9443/...
"""
import argparse
import select
import socket
import threading
import time

ap = argparse.ArgumentParser()
ap.add_argument("--listen", default="127.0.0.1:9443")
ap.add_argument("--target", default="127.0.0.1:8443")
ap.add_argument("--delay", type=float, default=0.0, help="单向延迟，毫秒")
ap.add_argument("--out", default="/tmp/q39cap")
ap.add_argument("--max", type=int, default=400000, help="每个方向最多抓多少字节")
args = ap.parse_args()

lh, lp = args.listen.rsplit(":", 1)
th, tp = args.target.rsplit(":", 1)

srv = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind((lh, int(lp)))
srv.listen(16)
print(f"[proxy] {args.listen} -> {args.target}  delay={args.delay}ms/包/方向", flush=True)


class Link:
    """单向链路：读者打时间戳入队，写者按 recv+delay 出队发送。"""

    def __init__(self, src, dst, tag, delay):
        self.src, self.dst, self.tag = src, dst, tag
        self.delay = delay / 1000.0
        self.q = []
        self.lock = threading.Lock()
        self.cv = threading.Condition(self.lock)
        self.total = 0
        self.buf = bytearray()
        self.log = []
        self.closed = False
        self.t0 = time.perf_counter()
        threading.Thread(target=self._read, daemon=True).start()
        threading.Thread(target=self._write, daemon=True).start()

    def _read(self):
        try:
            while True:
                r, _, _ = select.select([self.src], [], [], 30)
                if not r:
                    break
                data = self.src.recv(65536)
                if not data:
                    break
                now = time.perf_counter()
                if len(self.buf) < args.max:
                    chunk = data[: args.max - len(self.buf)]
                    self.buf += chunk
                    self.log.append((now - self.t0, len(chunk)))
                with self.cv:
                    self.q.append((now + self.delay, data))
                    self.cv.notify()
        except OSError:
            pass
        finally:
            self.closed = True
            with self.cv:
                self.cv.notify_all()

    def _write(self):
        while True:
            with self.cv:
                while not self.q and not self.closed:
                    self.cv.wait(0.5)
                if not self.q:
                    if self.closed:
                        break
                    continue
                at, data = self.q.pop(0)
            wait = at - time.perf_counter()
            if wait > 0:
                time.sleep(wait)
            try:
                self.dst.sendall(data)
                self.total += len(data)
            except OSError:
                break
        with open(f"{args.out}.{self.tag}.bin", "wb") as f:
            f.write(bytes(self.buf))
        with open(f"{args.out}.{self.tag}.log", "w") as f:
            for t, n in self.log:
                f.write(f"{t*1000:.3f}ms {n}B\n")
        try:
            self.dst.shutdown(socket.SHUT_WR)
        except OSError:
            pass
        print(f"[proxy] {self.tag}: {self.total} B / {len(self.log)} 个包", flush=True)


n = 0
while True:
    cli, _ = srv.accept()
    n += 1
    try:
        up = socket.create_connection((th, int(tp)))
    except OSError as e:
        print(f"[proxy] 连接后端失败: {e}", flush=True)
        cli.close()
        continue
    Link(cli, up, f"{n}.c2s", args.delay)
    Link(up, cli, f"{n}.s2c", args.delay)

