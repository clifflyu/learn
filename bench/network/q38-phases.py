#!/usr/bin/env python3
"""Q38 分段计时：把 curl 的 time_* 累加量拆成每阶段增量，取中位数。

用法：python3 /opt/learn/bench/network/q38-phases.py
在【宿主机】跑。curl 8.14.1（宿主）。

curl 的 time_* 全是「从请求开始到该事件的累计秒数」，所以：
    DNS      = namelookup
    TCP      = connect   - namelookup
    TLS      = appconnect - connect          （HTTP 明文恒为 0）
    请求+首字节 = starttransfer - appconnect   （含服务端处理时间）
    收包      = total - starttransfer
"""
import statistics
import subprocess

TIMES = "namelookup connect appconnect starttransfer total".split()
FMT = "\\n".join("%{time_" + t + "}" for t in TIMES) + "\\n%{http_code}\\n"

# --resolve 的 IP 必须【当场解析】出来，不能写死：
#   写死过一次，结果那台机器早就不是 DNS 现在解析到的那台了，
#   两行的 TLS 时间差了 2 倍（89.8ms vs 34.8ms），比的其实是两台不同的边缘节点。
def resolve(host):
    out = subprocess.run(["getent", "ahostsv4", host], capture_output=True, text=True).stdout
    return out.split()[0] if out.strip() else None


BAIDU_IP = resolve("www.baidu.com")

TARGETS = [
    ("本机 http://localhost:9311  nginx+PHP", "http://localhost:9311/bench/network/hello.php", [], []),
    ("本机 http://localhost:9311  静态文件", "http://localhost:9311/bench/network/static.txt", [], []),
    ("容器内 http://127.0.0.1:80  nginx+PHP", "http://127.0.0.1/bench/network/hello.php", ["docker", "exec", "learn-php"], []),
    ("外部 https://www.baidu.com  (含 DNS)", "https://www.baidu.com/", [], []),
    (f"外部 https://www.baidu.com  (--resolve {BAIDU_IP} 跳过 DNS)",
     "https://www.baidu.com/", [], ["--resolve", f"www.baidu.com:443:{BAIDU_IP}"]),
]

N = 15


def run(url, prefix, extra):
    cmd = prefix + ["curl", "-s", "-o", "/dev/null", "-w", FMT] + extra + [url]
    out = subprocess.run(cmd, capture_output=True, text=True, timeout=20).stdout.strip().split("\n")
    vals = [float(x) for x in out[:5]]
    return vals, out[5] if len(out) > 5 else "?"


def median(vals_list):
    return [statistics.median(col) for col in zip(*vals_list)]


def ms(x):
    return x * 1000


print(f"每档跑 {N} 次，下方是该阶段耗时的【中位数】（ms）")
hdr = f"{'目标':<46}{'DNS':>7}{'TCP':>7}{'TLS':>7}{'请求+首字节':>12}{'收包':>7}{'总计':>8}  码"
print(hdr)
print("-" * len(hdr))

for label, url, prefix, extra in TARGETS:
    runs, codes = [], {}
    for _ in range(N):
        v, c = run(url, prefix, extra)
        runs.append(v)
        codes[c] = codes.get(c, 0) + 1
    dns, tcp, tls, ttfb, tot = median(runs)
    row = (
        f"{label:<46}"
        f"{ms(dns):>7.3f}"
        f"{ms(tcp - dns):>7.3f}"
        f"{ms(tls - tcp):>7.3f}"
        f"{ms(ttfb - tls):>12.3f}"
        f"{ms(tot - ttfb):>7.3f}"
        f"{ms(tot):>8.3f}"
        f"  {sorted(codes)[0]}"
    )
    print(row)
