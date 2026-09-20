#!/usr/bin/env python3
"""Q42 用：一个「坏上游」，制造 nginx 侧的 upstream 错误。

在【容器内】跑：
  docker exec -d learn-php python3 /app/bench/network/q42/02-bad-upstream.py --mode close

模式：
  close    收到请求后直接关连接，一个字节都不回
           -> nginx: "upstream prematurely closed connection while reading response header"
  garbage  回一段不是合法 HTTP 状态行的字节
           -> nginx 记 "upstream sent no valid HTTP/1.0 header"，
              但它【不会】因此返回 502，而是当 HTTP/0.9 透传给客户端
"""
import argparse
import socket

ap = argparse.ArgumentParser()
ap.add_argument("--mode", choices=["close", "garbage"], default="close")
ap.add_argument("--port", type=int, default=9099)
args = ap.parse_args()

srv = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(("127.0.0.1", args.port))
srv.listen(8)
print(f"[bad-upstream] mode={args.mode} listening on 127.0.0.1:{args.port}", flush=True)

while True:
    c, _ = srv.accept()
    try:
        c.recv(65536)
        if args.mode == "garbage":
            c.sendall(b"NOT-HTTP/9.9 GARBAGE\r\n\r\nhello\r\n")
        # 半关 + 把对端剩余数据读干净，避免 close() 发 RST；
        # 干净 FIN 才能得到 "prematurely closed"，发 RST 得到的是 "reset by peer"。
        c.shutdown(socket.SHUT_WR)
        c.settimeout(1)
        while c.recv(65536):
            pass
    except OSError:
        pass
    finally:
        c.close()
