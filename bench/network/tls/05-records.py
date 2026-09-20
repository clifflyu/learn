#!/usr/bin/env python3
"""把 03-proxy.py 抓下来的字节解析成 TLS 记录时间线。

这样握手的每条消息、方向、长度、到达时刻都是实测的，不用猜。
用法：python3 05-records.py /tmp/q39local 1
"""
import sys

RT = {20: "ChangeCipherSpec", 21: "Alert", 22: "Handshake", 23: "ApplicationData", 24: "Heartbeat"}
HT = {
    1: "ClientHello", 2: "ServerHello", 4: "NewSessionTicket", 5: "EndOfEarlyData",
    8: "EncryptedExtensions", 11: "Certificate", 12: "ServerKeyExchange",
    13: "CertificateRequest", 14: "ServerHelloDone", 15: "CertificateVerify",
    16: "ClientKeyExchange", 20: "Finished", 24: "KeyUpdate",
}


def arr_times(logpath):
    """返回 [(字节偏移上界, 到达时刻ms)]，用来把字节位置映射回到达时间。"""
    times, acc = [], 0
    for line in open(logpath):
        t, n = line.split()
        acc += int(n[:-1])
        times.append((acc, float(t[:-2])))
    return times


def at(off, times):
    for limit, t in times:
        if off < limit:
            return t
    return times[-1][1] if times else 0.0


def parse(path, times, label):
    data = open(path, "rb").read()
    off = 0
    print(f"  ── {label}（{len(data)} B）")
    ccs_seen = False  # 本方向一旦出现过 CCS，后面的记录就是密文，内部结构不可解析
    while off + 5 <= len(data):
        rtype = data[off]
        ver = data[off + 1:off + 3].hex()
        rlen = int.from_bytes(data[off + 3:off + 5], "big")
        body = data[off + 5: off + 5 + rlen]
        t = at(off, times)
        name = RT.get(rtype, f"type{rtype}")
        if rtype == 22 and not ccs_seen:
            msgs, o = [], 0
            while o + 4 <= len(body):
                mt = body[o]
                ml = int.from_bytes(body[o + 1:o + 4], "big")
                msgs.append(f"{HT.get(mt, mt)}({ml}B)")
                o += 4 + ml
            name += ": " + ", ".join(msgs)
        elif rtype == 22 and ccs_seen:
            name = "Handshake(已加密，内部不可解析)"
        elif rtype == 21 and body:
            name += f": level={body[0]} desc={body[1]}"
        print(f"     t+{t:8.3f}ms  ver=0x{ver}  {name:<52} {rlen} B")
        if rtype == 20:
            ccs_seen = True
        off += 5 + rlen
    if off != len(data):
        print(f"     （尾部剩 {len(data)-off} B 无法解析，多为抓取截断）")


name = sys.argv[1]
n = sys.argv[2]
for d in ("c2s", "s2c"):
    try:
        times = arr_times(f"{name}.{n}.{d}.log")
        parse(f"{name}.{n}.{d}.bin", times, f"{'客户端 -> 服务端' if d=='c2s' else '服务端 -> 客户端'}")
    except FileNotFoundError:
        pass
