# 06 · 网络与安全（Q38–Q43）

> 配套 `../php-senior-interview-top50.md`。数字均来自容器实测，脚本在 `../bench/network/`。
> 环境：PHP 8.4.25 / nginx 1.30.5 / MySQL 8.4.11 / Redis 7.4.11 / curl 8.14.1（宿主）。

## Q38. 从输入 URL 到页面返回，发生了什么？

### 结论

一次请求被拆成 **8 个阶段**。本机 `curl` 实测（N=15 取中位数）：DNS **0.031 ms** + TCP **0.163 ms** + 服务端处理 **2.052 ms** + 收包 **0.056 ms** = **2.108 ms**。

而打一个外部 HTTPS 站点：DNS **6.164 ms** + TCP **1.929 ms** + TLS **40.308 ms** + 服务端处理 **7.677 ms** + 收包 **0.511 ms** = **56.589 ms**。**TLS 握手一项就占了 71%**——裸 HTTP 换成 HTTPS，多出来的不是"加密数据"的钱，是"握手"的钱。

### 一、八个阶段

```
浏览器输入 https://example.com/index.php
   │
   │ ① DNS 解析        example.com -> 93.184.216.34        time_namelookup
   │                   （先查 hosts → 本地 DNS 缓存 → 递归查询）
   ▼
   │ ② ARP / 路由      拿到 IP 还不够，还得知道下一跳的 MAC
   ▼
   │ ③ TCP 三次握手    SYN → SYN+ACK → ACK                 time_connect
   │                   connect() 返回 = 握手完成
   ▼
   │ ④ TLS 握手        ClientHello ⇄ ServerHello/Cert/…/Finished
   │                   ★ 2 个 RTT（TLS1.2）/ 1 个 RTT（TLS1.3）
   │                   time_appconnect = 握手完成
   ▼
   │ ⑤ 发 HTTP 请求    GET /index.php HTTP/1.1 + 请求头
   ▼
   │ ⑥ 服务端处理      nginx 收 → 分流 → fastcgi_pass → php-fpm
   │                   → PHP 执行（可能查 MySQL / Redis）
   ▼
   │ ⑦ 首字节返回      time_starttransfer = 收到响应第一个字节
   │                   ★ 这一步包含了 ⑥ 的全部耗时
   ▼
   │ ⑧ 收完响应体      time_total = 最后一个字节
   ▼
  浏览器渲染（解析 HTML → 构建 DOM → 遇到 <link>/<script> 再发起上面一整轮）
```

**`curl -w` 的关键：`time_*` 全是「从请求开始算起」的累计值，不是单阶段耗时。**

| 阶段 | 怎么算 |
| --- | --- |
| DNS | `time_namelookup` |
| TCP | `time_connect` − `time_namelookup` |
| TLS | `time_appconnect` − `time_connect`（明文 HTTP 恒为 0） |
| 请求 + 首字节 | `time_starttransfer` − `time_appconnect` |
| 收包 | `time_total` − `time_starttransfer` |

### 二、实测（curl 8.14.1，N=15 取中位数，ms）

脚本 `../bench/network/q38-phases.py`：

| 目标 | DNS | TCP | TLS | 请求+首字节 | 收包 | 总计 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 本机 `localhost:9311` nginx+PHP | 0.031 | 0.163 | −0.194 | **2.052** | 0.056 | 2.108 |
| 本机 `localhost:9311` 静态文件 | 0.034 | 0.172 | −0.206 | **1.119** | 0.054 | 1.173 |
| 容器内 `127.0.0.1:80` nginx+PHP | 0.031 | 0.174 | −0.205 | **1.404** | 0.104 | 1.508 |
| 外部 `https://www.baidu.com`（含 DNS） | **6.164** | 1.929 | **40.308** | 7.677 | 0.511 | 56.589 |
| 外部同上（`--resolve` 跳过 DNS） | **0.024** | 1.740 | 38.871 | 9.745 | 0.113 | 50.493 |

> 外部两行的差（56.589 − 50.493 = 6.096 ms）正好等于含 DNS 那行的 DNS 耗时（6.164 ms），互相印证。
> **但外部网络噪声必须说明**：同一脚本连跑两次，第一次的 `--resolve` 行 TLS 是 92.267 ms；单独复跑 6 次还出现过一次 1042.6 ms 的总耗时（CDN 调度到不同边缘节点 + 一次秒级抖动）。外部数字只看量级，精确结论请只看本机行。

**连接复用**（`q38-timing.sh` 第 6 节，一次 curl 打两个相同 URL，`%{num_connects}` 区分）：

| 目标 | 第几次 | `num_connects` | TTFB |
| --- | --- | ---: | ---: |
| `https://www.baidu.com/` | 1 | 1 | 0.149950 s |
| `https://www.baidu.com/` | 2 | **0** | **0.033155 s** |
| 本机静态文件 | 1 | 1 | 0.001134 s |
| 本机静态文件 | 2 | **0** | **0.000451 s** |

**后端慢 1 秒，位移全在 TTFB**（`q38-timing.sh` 第 8 节，`?mode=sleep`）：

```
  code=200  dns=0.000047  tcp=0.000198  tls=0.000000  ttfb=1.002009  total=1.002071  size=6
                    ↑ 0.2ms       ↑ 0.2ms              ↑ 1002ms          ↑ 只多 0.06ms
```

### 三、六个反直觉的点

**1. `time_appconnect − time_connect` 在明文 HTTP 上是负数。** 表里四行本机/容器目标的 TLS 都是 −0.2 ms 左右。原因：curl 记 `time_connect` 和 `time_appconnect` 是两个独立的 `gettimeofday`，非 TLS 连接两者几乎同时发生，差值就退化成**测量噪声**。看到负数不要慌，它就是在告诉你"这个连接没有 TLS"。

**2. 外部请求里 DNS 只占 11%，TLS 占 71%。** 56.589 ms 里有 40.308 ms 是 TLS 握手，DNS 只有 6.164 ms。所以"网站慢"要做 HTTPS 优化时，先看 TLS：会话复用（session ticket）、TLS 1.3、OCSP stapling、证书链精简，收益远大于折腾 DNS 预解析。

**3. 连接复省掉的是整个握手，不只是"建连"。** 上表第二次请求 `num_connects=0`，baidu 的 TTFB 从 149.950 ms 掉到 33.155 ms——省掉的是 **TCP + TLS 两个 RTT 加上证书传输**（那个站点的证书链有 5 KB+）。**这是 HTTPS 优化里性价比最高的一条，且不需要改任何服务端配置。**

**4. TTFB 里装着整个后端。** 第 8 节里 PHP 睡 1 秒，`ttfb` 就从 2 ms 变成 1002 ms，而 DNS/TCP/TLS/收包四栏纹丝不动。**排查"接口慢"时，只要 TTFB 大而收包小，问题一定在后端，不在网络。**

**5. 静态文件比 PHP 快不到一倍。** 1.119 ms vs 2.052 ms。PHP 这一趟只多花了 **0.933 ms**——这就是 nginx + fastcgi + php-fpm 一整条链路的固定开销。它意味着：**在 PHP 上做微优化（少一次函数调用）几乎没有意义，除非你的接口本来就只有 1 ms 的量级。**

**6. 走 `localhost:9311` 比容器内直连多 0.6 ms。** 2.108 ms（宿主机 → docker-proxy → 容器）vs 1.508 ms（容器内 127.0.0.1:80）。这 0.6 ms 是 **docker-proxy 的用户态 NAT 转发**。压测时如果客户端在容器外，这段开销会被算进"服务端处理时间"里，读数据前要想清楚。

### 四、实战结论

| 现象 | 判断 |
| --- | --- |
| DNS 大（> 20 ms） | DNS 服务器远或没缓存；上本地 DNS 缓存 / `--resolve` / 长 TTL |
| TCP 大但 DNS 小 | 网络 RTT 高或丢包重传；换机房、上 CDN |
| TLS 大（占比最高） | 开 session ticket / TLS 1.3 / 精简证书链；优先做连接池 |
| TTFB 大，其他都小 | **后端问题**，去查慢 SQL、外部 RPC、锁等待 |
| 收包大 | 响应体太大或带宽不够；开 gzip / 分页 / 流式输出 |
| 总慢但每一段都不大 | 阶段太多（重定向链、串行资源）；合并请求、减少跳转 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| `time_total` 和 `time_starttransfer` 的区别？ | 前者是最后一个字节，后者是第一个字节；差值是收包时间 |
| 为什么 `time_appconnect` 会小于 `time_connect`？ | 明文连接下两者几乎同时，差值退化为噪声（实测 −0.2 ms） |
| TCP 握手为什么是 3 次不是 2 次？ | 要让双方都确认"自己的发送 + 对方的接收"都正常；2 次无法让服务端确认自己的发送能力 |
| HTTP/1.1 的 keep-alive 和 HTTP/2 的多路复用差在哪？ | keep-alive 是串行复用一条连接（队头阻塞）；HTTP/2 是同一条连接上并行多个 stream |
| 一次 HTTPS 请求要几个 RTT？ | TLS1.2 + TCP = 1+2 = 3 个；TLS1.3 + TCP = 1+1 = 2 个；复用连接后只剩 1 个 |
| 浏览器渲染算不算在这 8 步里？ | 不算。`curl` 只测到收完 HTML；DOM 构建、CSS/JS 加载、布局绘制都在后面 |
| 301 和 302 对时间的影响？ | 都是多一整个 RTT 起步（`q38-timing.sh` 第 7 节实测 301：ttfb 1.149 ms，和一个完整请求同级） |

---

## Q39. HTTPS 如何保证安全？握手过程？

### 结论

HTTPS = HTTP + TLS。它解决 **三件事**，由 **三套不同机制** 提供，混着讲就说不清了：

| 保证 | 机制 | 靠什么 |
| --- | --- | --- |
| **机密性** | 对称加密（AES-GCM / ChaCha20） | 握手协商出的会话密钥 |
| **完整性** | AEAD / MAC | 每条记录带认证标签 |
| **身份认证** | 非对称加密 + 证书链 | 从 Root CA 到 leaf 的签名链 |

握手做的事就一件：**在不安全的信道上，安全地协商出一个双方都知道、别人不知道的对称密钥**。非对称加密只用来做这件事和验身份，因为对称加密快 3 个数量级。

轮次实测（链路模拟器，可控 RTT）：**TLS 1.2 = 2.12 个 RTT，TLS 1.3 = 1.16 个 RTT**。

### 一、握手时序（从真实抓包里解析出来的）

```
TLS 1.2（客户端视角，链路单向延迟 25 ms）
t+  0.8ms ── ClientHello ──────────────────────────────►   ← SNI 在这里，明文
                                                              │ 25 ms
t+ 51.2ms ◄────────── ServerHello + Certificate(1827B)
                          + ServerKeyExchange + ServerHelloDone   ← 证书明文
                                                              │ 25 ms
t+ 53.2ms ── ClientKeyExchange + CCS + Finished ────────►   ← 客户端算出会话密钥
                                                              │ 25 ms
t+ 77.9ms ◄────────── CCS + Finished                       ← 握手完成，共 2 个 RTT
                                                              │ 25 ms
t+104.5ms ── ApplicationData(GET /) ────────────────────►   ← 数据才开始发

TLS 1.3（同一个站点，同一个链路）
t+  5.0ms ── ClientHello(1487B，含 key_share) ───────────►   ← SNI 仍是明文
                                                              │ 25 ms
t+ 30.9ms ◄────────── ServerHello + {已加密: Certificate,
                          CertificateVerify, Finished}      ← ★ 证书被加密了
                                                              │ 25 ms
t+ 57.6ms ── CCS + {已加密: Finished} + ApplicationData ─►   ← 握手完成，1 个 RTT
```

**TLS 1.3 省的那 1 个 RTT 是怎么来的**：客户端在 ClientHello 里就把密钥交换的公开值（`key_share`）发过去了，服务端可以直接算密钥。TLS 1.2 要等 ServerHello 回来才知道用什么参数（ServerKeyExchange），再发一次——所以多一个来回。

### 二、实测（nginx 1.30.5，自签三级证书链）

脚本 `../bench/network/tls/04-q39-run.sh`，证书生成在 `tls/01-make-chain.sh`。

**证书链（`openssl s_client -showcerts`）**——服务端发了 **2 张**：

```
 0 s:C=CN, O=Learn Bench, CN=q39.local                ← leaf，1322 B PEM / 936 B DER
   i:C=CN, O=Learn Bench, CN=Q39 Intermediate CA
   a:PKEY: rsaEncryption, 2048 (bit); sigalg: RSA-SHA256
 1 s:C=CN, O=Learn Bench, CN=Q39 Intermediate CA      ← 中间证书，1249 B PEM / 882 B DER
   i:C=CN, O=Learn Bench, CN=Q39 Root CA
Server Temp Key: X25519, 253 bits                     ← 密钥交换用的临时公钥
```

**校验结果**：

| 检查方式 | 结果 |
| --- | --- |
| `openssl verify -CAfile rootCA.crt -untrusted intermediate.crt leaf.crt` | `Verify return code: 0 (ok)` |
| 不带 `-CAfile`（用容器默认信任库） | `Verify return code: 20 (unable to get local issuer certificate)` |
| `curl --cacert rootCA.crt` | `http=200 tls=0.008126s` |
| `curl` 不给 `--cacert` | **退出码 60**（证书链验证失败） |
| `curl -k` 跳过校验 | `http=200` |

**协议版本与套件**：

| 参数 | 协商结果 |
| --- | --- |
| 默认 | `TLSv1.3` / `TLS_AES_256_GCM_SHA384` |
| `-tls1_3` | `TLSv1.3` / `TLS_AES_256_GCM_SHA384` |
| `-tls1_2` | `TLSv1.2` / `ECDHE-RSA-AES256-GCM-SHA384`（**非 AEAD 时代的 ECDHE**） |
| `-tls1_1` | **`tlsv1 alert protocol version` / SSL alert number 70** —— 直接被拒 |

**握手轮次（可控 RTT 链路模拟器 `tls/03-proxy.py`）**：

| 版本 | 链路 RTT | `connect` | `appconnect` | 握手 = appconnect − connect | 折合 RTT |
| --- | ---: | ---: | ---: | ---: | ---: |
| TLS 1.2 | 50 ms | 0.000160 s | 0.106126 s | **106.0 ms** | **2.12** |
| TLS 1.3 | 50 ms | 0.000202 s | 0.058238 s | **58.0 ms** | **1.16** |
| TLS 1.2 | 200 ms | 0.000139 s | 0.431495 s | **431.5 ms** | **2.16** |
| TLS 1.3 | 200 ms | 0.000141 s | 0.218966 s | **219.0 ms** | **1.09** |

**明文嗅探（在链路上抓字节，搜 SNI 和证书主体字符串）**：

| 抓到的包 | SNI `q39.local` | 证书主体 `Q39 Intermediate CA` |
| --- | --- | --- |
| 连接 1（TLS 1.2）客户端→服务端 477 B | ★ 命中 | 未见 |
| 连接 1（TLS 1.2）服务端→客户端 2547 B | ★ 命中 | **★ 命中** |
| 连接 2（TLS 1.3）客户端→服务端 1723 B | ★ 命中 | 未见 |
| 连接 2（TLS 1.3）服务端→客户端 3194 B | 未见 | **未见（已加密）** |

### 三、六个反直觉的点

**1. 证书链"发全"和"发对"是两件事，而且漏发中间证书的表现很隐蔽。** 我自己的实验里，如果只 `cat leaf.crt` 不拼 `intermediate.crt`，`Verify return code` 就是 **20 (unable to get local issuer certificate)**——**浏览器会给警告，但很多 HTTP 客户端（curl、部分 SDK）默认只警告不中断**，于是"我们线上能跑"和"用户浏览器报红"同时成立。nginx 配置里必须写 `ssl_certificate fullchain.crt`（leaf + intermediate 拼一起），而不是 `server.crt`。Root CA 不用发，客户端本地就有。

**2. TLS 1.3 把证书加密了，但 SNI 还是明文。** 表里连接 2 的服务端→客户端方向搜不到任何证书字符串（★ 这个是 TLS 1.3 的真实改进），但**两个版本的客户端→服务端方向都命中 SNI**——因为 SNI 必须在服务端选证书之前送到，只能明文。所以"TLS 1.3 之后中间人就看不到你访问哪个站点了"是**错的**，中间人依然知道域名（只是不知道路径和内容）。真要藏域名得上 ECH（Encrypted Client Hello）。

**3. `curl` 没有 `-tls1_2` 这个参数。** 我第一版脚本写的是 `-tls1_2`，curl **静默忽略了它**，于是"TLS 1.2"和"TLS 1.3"两次跑的都是默认的 1.3，两行数字几乎一样，而我没看出来。正确写法是 `--tlsv1.2`，**并且要再加 `--tls-max 1.2`**——只写 `--tlsv1.2` 是"最低 1.2"，它照样会协商到 1.3。

**4. 不是所有大站都支持 TLS 1.3。** 脚本原本拿 `www.baidu.com` 做远端对比，`--tlsv1.3 --tls-max 1.3` 时被直接拒掉：`tlsv1 alert protocol version`（alert 70）。换成 `www.taobao.com` 才两端都能跑。**这类"以为某个特性全网都有"的假设，必须实测。**

**5. TLS 1.3 里那个 `ChangeCipherSpec` 是个假动作。** 抓包时间线里 TLS 1.3 连接也有一条 `ChangeCipherSpec(1B)`，看起来像 1.2 的密钥切换——其实是 **middlebox compatibility mode**，专门发给路径上那些"看不懂就丢包"的老中间盒看的，协议上没有任何作用。解析记录时如果按 1.2 的逻辑去理解它，就会把 1.3 的握手算错。

**6. 非对称算法在握手里的分工要看清楚。** 抓包里 `Server Temp Key: X25519, 253 bits` 是**密钥交换**用的（ECDHE，每次连接都换，提供前向保密）；证书里的 `rsaEncryption, 2048 bit` + `sigalg: RSA-SHA256` 是**签名**用的（证明身份）。ECDHE-RSA 这个名字就是"用 ECDHE 换密钥 + 用 RSA 签身份"。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| nginx 配证书 | `ssl_certificate` 指向 **fullchain**（leaf+intermediate），`ssl_certificate_key` 指向私钥 |
| 想省握手时间 | 上 **TLS 1.3**（2 RTT → 1 RTT，本页实测）+ **session ticket**（官方称复用时 0 RTT，**本条未实测**） |
| 证书要续期 | Let's Encrypt 走 ACME，`certbot renew` 后 reload nginx，**别重启** |
| 客户端报证书错 | 先 `openssl s_client -connect host:443 -showcerts` 看链全不全 |
| 抓包看不到内容 | 说明加密生效；但**域名（SNI）依然可见**，别在 SNI 里放敏感信息 |
| 排查"某 SDK 连不上" | 常见是它不信任中间证书 / 还是 TLS 1.0，抓 `openssl s_client` 的 `Verify return code` |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 为什么要"非对称换密钥 + 对称传数据"？ | 非对称慢 3 个数量级，只用在握手；数据量大必须用对称 |
| 前向保密（PFS）是什么？ | 会话私钥用完即弃（ECDHE），私钥泄漏也解不开历史流量。RSA 密钥交换不具备 |
| 证书链为什么要有中间 CA？ | Root CA 私钥必须离线保管，不能天天用来签 leaf；中间 CA 是"可吊销的缓冲层" |
| `Verify return code: 20` 是什么意思？ | 本地信任库里找不到签发者，通常是**服务端漏发中间证书** |
| 自签证书浏览器为什么拦？ | 不在信任库里，链的终点不是已知 Root CA |
| TLS 1.3 为什么删掉了 RSA 密钥交换和一堆老套件？ | 那些套件有已知弱点（如 CBC padding oracle、RC4），且都能被 ECDHE+AEAD 替代 |
| 0-RTT 有什么代价？ | 可被重放攻击，只能用于幂等请求 |

---

## Q40. Cookie、Session、JWT 的特点与选型

### 结论

**这三者不是同一个维度的东西**：Cookie 是**传输方式**，Session 和 JWT 是**状态放在哪**。用一句话概括实测结果：

> **退出登录时，Session 服务端删一条记录，旧 cookie 下一次就是 401；JWT 服务端什么都做不了，同一个 token 拿去用依然是 200。**

这就是全部的选型答案：**只要"能否主动踢人"是需求，JWT 就必须加状态，加了状态就不是 JWT 了。**

### 一、三者的位置

```
Cookie：只是浏览器自动携带的一段 key=value，本身不承载任何语义
        ┌─────────────────────────────────────────┐
        │  Set-Cookie: Q40SESS=<32字节十六进制>    │
        │  请求时浏览器自动带上，跨站时也自动带    │  ← CSRF 的物理基础
        └─────────────────────────────────────────┘
                          │
        ┌─────────────────┴─────────────────┐
        ▼                                   ▼
  Session：状态在服务端                JWT：状态在 token 里
  ┌────────────────────────┐        ┌────────────────────────────┐
  │ 存的东西：user=alice   │        │ base64(header).          │
  │ 存在哪：文件/Redis/DB  │        │ base64(payload).         │
  │ 存多大：随便           │        │ HMAC(前两段)             │
  │ 客户端只有：一个 id    │        │ 客户端持有【全部内容】   │
  │                        │        │                          │
  │ 登出 = 服务端删记录 ✓  │        │ 登出 = 客户端删掉 ✗      │
  │ 踢人 = 删记录 ✓        │        │ 踢人 = 做不到 ✗          │
  │ 每请求查一次存储       │        │ 每请求只算一次签名 ✓     │
  └────────────────────────┘        └────────────────────────────┘
```

### 二、实测（PHP 8.4.25 / phpredis 6.3.0 / Redis 7.4.11）

**1. 同一个 session 的两个并发请求会不会互相阻塞**（`q40/01-session-concurrency.sh`，两个请求各持锁 1 秒）：

| 配置 | 两个请求总耗时 | counter 结果 | 结论 |
| --- | ---: | --- | --- |
| **A** files handler，持锁到脚本结束（PHP 默认） | **2.021 s** | 1, 2 | **完全串行** |
| **B** files handler，读完立刻 `session_write_close()` | **1.046 s** | 1, 2 | 真并发，无丢失 |
| **C** redis handler，不加锁（phpredis 默认） | **1.026 s** | **1, 1** | 并发，**丢更新** |
| **D** redis handler，`save_path` 里写 `&lock=1` | 1.019 s | **1, 1** | **锁没生效**，仍丢更新 |
| **D2** redis handler，`ini_set('redis.session.locking_enabled','1')` | **2.046 s** | 1, 2 | 串行，无丢失 |

PHP 的文件 session handler 在 `session_start()` 时对 `sess_xxx` 文件加**排他 flock，一直持有到请求结束**。两个并发 1 秒请求 → 2.021 秒。**这是 PHP 里最容易被误诊为"接口慢"的现象**：一个慢请求会把它后面所有同 session 的请求全堵住。

**2. JWT 的六个实验**（`q40/02-jwt.php`，自实现 HS256）：

| # | 实验 | 结果 |
| --- | --- | --- |
| 1 | 签发 | 165 字节，三段 36 / 84 / 43 |
| 2 | **payload 不用密钥就能读** | base64 解出 `{"sub":"alice","role":"user","iat":…,"exp":…}` |
| 3 | 改 `role` 为 `admin` | 严格验签 `false` —— **篡改被抓** |
| 4 | `alg=none` + 空签名 | 天真校验器 **`true`，role=admin**；严格校验器 `false` |
| 5 | 登出后同一个 token | 验签 `true`，`exp − now = 3600s` —— **依然有效** |
| 6 | 体积对比 | `Cookie: PHPSESSID=…` 50 B vs `Authorization: Bearer …` 179 B，**差 129 B** |

**3. 退出登录的完整对比**（`q40/03-logout-compare.sh`，真跑 HTTP）：

```
===== Session 方案 =====
1) 登录           → 登录成功，sess_44322b31… 已写入存储
2) 访问受保护资源  → 200 已认证 / user=alice
3) 退出登录        → 服务端做了什么：把 session 文件/记录删掉了
4) 用同一个 cookie → 401 未认证  ← 依据：服务端存储里已经没有这个 session 了

===== JWT 方案 =====
1) 签发           → token 前 40 字节 eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ…
2) 访问受保护资源  → 200 已认证 / user=alice
3) 退出登录        → 服务端做了什么：什么都没做
4) 用同一个 token  → 200 已认证  ← 依然是通过
```

**4. 存储位置**：

```
files 默认 save_path = ''  =>  /tmp/sess_*
redis  handler       =>  PHPREDIS_SESSION:<sid>     （phpredis 自动加前缀）
redis 里一个 session 的值：counter|i:1;handler|s:5:"redis";
```

**5. cookie 一行多大**：`Set-Cookie: Q40SESS=8cba6c5db0a4c4ac7db07c809b5a8da3; path=/; HttpOnly` = 70 字节。

### 三、六个反直觉的点

**1. JWT 的 payload 是 base64，不是加密。** 实验中不需要任何密钥，`base64_decode` 就把 `sub`/`role`/`exp` 全读出来了。**JWT 只保证"内容没被改过"，不保证"内容别人看不到"。** 往 payload 里塞手机号、身份证、内部用户 ID 是常见的真实事故。

**2. `alg=none` 能直接绕过天真的校验器。** 实验 4 里，只要校验代码从 header 里读 `alg` 来决定用什么算法，攻击者把 header 改成 `{"alg":"none"}`、签名段留空，就直接 `true` 且 `role=admin`。**防御只有一条：算法白名单**——校验前先断言 `header.alg === 'HS256'`，绝不用 header 里的值去选算法。

**3. 文件 session 的锁是"整个请求"级别的，不是"读写那一刻"。** 表里 A 行的 2.021 s 说明锁从 `session_start()` 一直握到脚本结束。**默认配置下，同一个用户开两个标签页请求同一个接口，第二个要等第一个跑完。** 解法：能提前放锁就 `session_write_close()`（B 行，1.046 s），或者换成 Redis handler。**但别急着换**——看第 4 条。

**4. `save_path` 里的 `lock=1` 在 phpredis 6.3.0 上已经失效了。** 这是我踩到的真坑：D 行明明传了 `&lock=1`，两个请求仍然并发（1.019 s）、仍然丢更新（counter 都是 1）。查 `php -i` 才发现现在的开关是 INI：

```
redis.session.locking_enabled => 0     ← 默认关，save_path 参数已不生效
redis.session.lock_retries    => 100
redis.session.lock_wait_time  => 20000   （微秒，100 次 ≈ 最长等 2 秒）
```

改成 `ini_set('redis.session.locking_enabled','1')` 后才真正串行（D2 行，2.046 s）。**"我配了锁"和"锁真的在起作用"是两回事，必须用并发实测验证——只看配置项名字看不出来。**

**5. PHP 的 session cookie 默认全是"不安全"属性**（`q41` 环境实测）：

```
session.cookie_httponly = '0'      ← 默认关，JS 能读
session.cookie_samesite = ''       ← 默认空，跨站会带上
session.cookie_secure   = '0'      ← 默认关，HTTP 也发
session.use_strict_mode = '0'      ← 默认关，接受未初始化的 sid
```

`use_strict_mode = 0` 意味着**攻击者可以自己造一个 session id 让受害者用**（session fixation）。这四个全都要在 `php.ini` 里手动打开。

**6. "JWT 是无状态的所以更快"要打问号。** 实验 6 实测 JWT 比 session id 大 129 字节，每个请求都要带。而"省掉查存储"省下的那一次 Redis 往返（本机 ~0.1 ms），很容易被多出来的 129 字节流量和更长的 header 解析吃掉。**JWT 的优势在跨服务、跨域、无共享存储的场景，不在"单体的性能"。**

### 四、实战结论

| 场景 | 选择 |
| --- | --- |
| 传统单体 Web、要能踢人 | **Session**（存 Redis），并打开 `httponly` / `samesite=Lax` / `strict_mode` |
| 多标签页并发多、有慢接口 | Session + **能提前 `session_write_close()` 就提前** |
| 纯 API、多服务共享身份、不需要即时吊销 | **JWT**，但也设短 `exp`（15 分钟）+ refresh token |
| 需要即时吊销又要无状态 | **做不到**。要么上黑名单（=有状态），要么把 `exp` 压到很短 |
| 跨域 / 给第三方 | JWT（放 `Authorization` 头，**不要**放 cookie） |
| 会话数据大（权限列表、菜单树） | Session（JWT 会让每个请求多带几 KB） |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| Cookie 和 Session 的区别？ | Cookie 是传输载体（客户端），Session 是服务端状态，两者不是对立的 |
| `HttpOnly` 和 `Secure` 分别防什么？ | `HttpOnly` 防 JS 读 cookie（挡 XSS 偷 session）；`Secure` 防明文传输被嗅探 |
| `SameSite=Lax/Strict/None` 怎么选？ | Lax 允许顶级导航带 cookie（够用且防 CSRF）；Strict 最严但点外链会掉登录；None 必须配 Secure |
| Session 存 Redis 好还是文件好？ | Redis：多机共享、无 flock 串行、可设 TTL；文件：零依赖但**锁会串行** |
| JWT 的签名和加密什么区别？ | 签名（HS256/RS256）= 防篡改，内容公开；加密（JWE）= 防偷看。**JWT 默认只签不加密** |
| HS256 和 RS256 怎么选？ | HS256 对称，签发和验证是同一把密钥，**多服务都得拿到它**；RS256 私钥签、公钥验，更适合多方 |
| `exp` 到期前用户还在操作怎么办？ | refresh token（本身又是有状态的）或滑动过期 |
| Session 固定攻击（fixation）怎么防？ | `session.use_strict_mode=1` + 登录成功后 `session_regenerate_id(true)` |

---

## Q41. SQL 注入、XSS、CSRF 的原理与防护

### 结论

三种漏洞的**共同点**：**把数据当成了代码。** 区别只在"哪一层解析器"：

| 漏洞 | 数据跑进了哪一层 | 一句话原理 | 防护 |
| --- | --- | --- | --- |
| **SQL 注入** | SQL 解析器 | 用户输入拼进 SQL，被当语法执行 | **参数化查询**（预处理 + 绑定） |
| **XSS** | HTML / JS 解析器 | 用户输入拼进页面，被当标签执行 | **输出转义**（`htmlspecialchars`） |
| **CSRF** | HTTP 会话 | 浏览器**自动**带上 cookie 发了请求 | **Token / Origin 校验** |

实测最狠的一条：`'\'; DROP TABLE users; -- '` 这一个 payload，把 `q41_sqli.users` 表**真的删掉了**。

### 一、三个原理

```
① SQL 注入：数据改变了【语法】
   $sql = "SELECT * FROM users WHERE username='$u' AND password='$p'";
   $p = "' OR '1'='1' -- "
                    │
                    ▼
   SELECT * FROM users WHERE username='admin' AND password='' OR '1'='1' -- '
                                                              ▲
                                        整个 WHERE 恒真 ──────┘  -- 注释掉后半句

② XSS：数据变成了【标签】
   echo "<div>你搜索的是：{$kw}</div>";
   $kw = "<script>alert(document.cookie)</script>"
                    │
                    ▼
   <div>你搜索的是：<script>alert(document.cookie)</script></div>
                     ▲ 浏览器执行它，不是显示它

③ CSRF：数据（请求）本身是【合法】的，合法在它带着用户的 cookie
   ┌──────────┐  1. 用户登录 bank.com，浏览器存下 cookie
   │  浏览器  │
   └────┬─────┘
        │ 2. 用户访问 evil.com（没登出 bank）
        ▼
   ┌──────────┐  3. evil.com 返回一个自动提交的隐藏表单
   │ evil.com │     <form action="bank.com/transfer" method=post>
   └────┬─────┘       <input name=to value=attacker>
        │             <input name=amount value=9999>
        │ 4. 表单【自动提交】—— 浏览器按"同站规则"带上 bank.com 的 cookie
        ▼
   ┌──────────┐  5. bank.com 看到请求带着合法 cookie，
   │ bank.com │     以为用户本人操作 → 转账成功
   └──────────┘
```

### 二、实测（MySQL 8.4.11 / PHP 8.4.25）

**1. SQL 注入**（`q41/01-injection.php`，`q41_sqli` 库，同一个拼接查询 vs PDO 预处理）：

| payload | 拼接查询结果 | PDO 预处理 |
| --- | --- | --- |
| 正常登录 `alice` / `alice123` | 1 行 | 1 行 |
| 密码错（对照组） | 0 行 | 0 行 |
| 密码位 `' OR '1'='1' -- ` | **3 行**（admin+alice+bob） | 0 行 |
| 用户名位 `' OR 1=1 -- ` | **3 行** | 0 行 |
| 注释 `admin'-- ` | **1 行**（admin，密码随便填） | 0 行 |
| UNION 拖信用卡表 | **3 行**：`6222-0000-0000-0001 / ICBC` … | 0 行 |
| UNION 拖密码 | **3 行**：`admin / S3cr3t!Pass`、`alice / alice123`… | 0 行 |
| **堆叠查询 `'; DROP TABLE users; -- `** | 执行成功，**表被删** | 报错（表已不存在） |

**2. 宽字节绕过 `addslashes`**（`q41/02-charset-bypass.php`）：

```
payload 原始字节 : bf 27 20 4f 52 20 31 3d 31 20 2d 2d 20     (\xbf' OR 1=1 -- )
addslashes 之后  : bf 5c 27 20 4f 52 ...                     ↑ 多了一个 5c

  0xBF5C 在 GBK 里是一个合法汉字，紧跟其后的 0x27 恢复成【真正的单引号】

连接字符集 gbk      → 3 行 ⇒ 转义被绕过，注入成功
同一个 payload 走 PDO 预处理 → 0 行 ⇒ 字节原样当值比较
同一 payload，utf8mb4 连接   → 0 行 ⇒ 0xBF 是非法字节，绕不过去
```

**3. XSS**（`q41/xss-demo.php`，可访问 `http://localhost:9311/bench/network/q41/xss-demo.php`）：

```
不安全模式  <div id="reflect">你搜索的是：<script>alert(document.cookie)</script></div>
安全模式    <div id="reflect">你搜索的是：&lt;script&gt;alert(document.cookie)&lt;/script&gt;</div>

存储型：写进去再读出来 —— 不安全模式原样输出 <script>…，安全模式输出 &lt;script&gt;
Set-Cookie: q41_session=SECRET-SESSION-ID-abc123; path=/; HttpOnly; SameSite=Lax
```

**4. CSRF**（`q41/csrf-bank.php` + `q41/csrf-evil.php`，完整浏览器链路复现）：

| 模式 | 转账前余额 | 服务端响应 | 转账后余额 |
| --- | ---: | --- | ---: |
| `mode=none` | 10000 | 转账 9999 给 attacker 成功（无任何校验） | **1** |
| `mode=origin` | 10000 | 拒绝：Origin/Referer 不是本站 | **10000** |
| `mode=token` | 10000 | 拒绝：token 不匹配 | **10000** |

服务端流水原文（`/tmp/q41-bank-log.txt`）：

```
13:59:25 mode=none   to=attacker amount=9999 Origin=http://evil.example Referer=http://evil.example/prize token=(无) => 已转账（无任何校验）
13:59:26 mode=origin to=attacker amount=9999 Origin=http://evil.example Referer=http://evil.example/prize token=(无) => 已拒绝（Origin/Referer 不是本站 → 拒绝）
13:59:27 mode=token  to=attacker amount=9999 Origin=http://evil.example Referer=http://evil.example/prize token=(无) => 已拒绝（token 不匹配 → 拒绝）
```

**5. PDO 的默认配置**（`q41/db.php`）：

```
PDO 默认 EMULATE_PREPARES = true   （db_vuln 用默认，db_safe 显式关掉）
```

### 三、六个反直觉的点

**1. 用了 PDO 不等于安全，`EMULATE_PREPARES` 默认是 `true`。** 这是最容易误判的一条：**PHP 8.4 + mysqlnd 下，PDO 默认不开真预处理**——它只是把参数转义后拼成完整 SQL 再发给 MySQL。语法注入被挡住了（引号确实被正确转义），但**服务端拿到的仍然是一条拼接好的字符串**。要真预处理得显式写 `PDO::ATTR_EMULATE_PREPARES => false`。**注意区分：防注入两类都能防住，但"真预处理"还额外带来执行计划复用和更严格的类型语义。**

**2. 堆叠查询能直接 `DROP TABLE`，而且它完全依赖驱动。** 实验里 `'; DROP TABLE users; -- ` 在 `PDO::query()` 上执行成功，表真的没了（后续所有查询报 `Table 'q41_sqli.users' doesn't exist`）。**两层原因**：`query()` 允许一次发多条语句；而 `PDO::prepare()` 在 MySQL 协议下默认发不出多语句。所以——**只要业务里有任何一处把用户输入拼进 `query()`/`exec()`，攻击者拿到的是整个库的写权限，不只是读数据。**

**3. `addslashes` 的防护在 GBK 下会失效。** `0xBF` + 被转义产生的 `0x5C` 拼成一个合法汉字，后面的引号就"逃"出来了。实验里同样一个 payload：`gbk` 连接 **3 行（注入成功）**，`utf8mb4` 连接 **0 行**。**这条的现实意义**：`addslashes` / `mysql_real_escape_string` 的安全性依赖连接字符集，而**转义函数和数据库连接的字符集可能不一致**。参数化查询不依赖字符集，这是它更根本的优势。

**4. `htmlspecialchars` 不写参数等于没写。** 默认是 `ENT_QUOTES | ENT_HTML401` **但 charset 用的是 `default_charset`**，且**默认不转义单引号**（旧默认）——属性里用单引号包值时就会被打穿。业务里统一写成：

```php
htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
```

`ENT_QUOTES` 同时转义单双引号，`ENT_SUBSTITUTE` 让非法 UTF-8 序列变成 `�` 而不是返回空串（返回空串会让"过滤"变成"静默清空"）。

**5. `HttpOnly` 挡不住 XSS，它只挡"偷 cookie"。** 实验里 `HttpOnly` 加上了，但 XSS 依然成立——攻击者可以在页面里**直接发请求**（`fetch('/transfer', {method:'POST', …})`），浏览器照样带 cookie，**根本不需要读它**。`HttpOnly` 的正确表述是"提高 XSS 后的止损下限"，不是"防 XSS"。

**6. 三种漏洞的防护层不能互换。** 常见错误：

| 错误做法 | 为什么没用 |
| --- | --- |
| 靠"过滤 `'` 和 `--`"防注入 | 编码绕过（见 GBK）、注释符变形、数字型注入不需要引号 |
| 入库前 `htmlspecialchars` 防 XSS | **存进去的是转义后的脏数据**，导出 Excel / 发 API / 发短信全是 `&lt;` |
| 靠 `Referer` 判空防 CSRF | 浏览器可以不发 Referer（`Referrer-Policy: no-referrer`），很多场景判空等于放行 |
| 用 `SameSite` 就不需要 Token | `SameSite=Lax` 挡不住顶级导航的 GET，也挡不住同站子域攻击 |

**XSS 的转义必须发生在「输出到 HTML」这一刻，不是「入库」这一刻**——因为同一条数据可能要输出到 HTML、JSON、CSV 三种地方，转义方式完全不同。

### 四、实战结论

| 漏洞 | 必须做的 | 别做的 |
| --- | --- | --- |
| SQL 注入 | 全部走 `prepare()` + 绑定参数；`EMULATE_PREPARES=false`；DB 账号按库授权、禁用 `DROP` | 拼接（含 `IN (…)`、`ORDER BY`、表名——这些**不能**用占位符，要用白名单映射） |
| XSS | 输出时按上下文转义；富文本用 HTML 白名单库；上 CSP | 存的时候转义；用正则"过滤 `<script>`" |
| CSRF | 敏感操作 POST + **同步器 Token**（`hash_equals` 比对）+ `SameSite=Lax` + 校验 Origin | 只靠 Referer；只靠验证码；GET 就能改数据 |

**共同原则**：**输入校验是为了业务正确性，输出编码是为了安全。** 把这两个目的混在一个函数里，两边都做不好。

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 预处理为什么能防注入？ | SQL 的结构先发给数据库解析并编译，参数**后发**且只作为数据填充，无法改变语法 |
| 为什么 `EMULATE_PREPARES=true` 也"能防住"注入？ | 因为 PDO 会正确转义参数值再拼接；但它仍然在拼 SQL，安全性依赖转义实现 |
| `IN (?)` 怎么参数化？ | 占位符个数要按数组长度动态生成 `?,?,?`，或用 `IN (SELECT …)` |
| `ORDER BY ?` 为什么不能绑定？ | 标识符（表名/列名/排序方向）不是值，协议上不能当参数传；**必须白名单映射** |
| 存储型 XSS 和反射型 XSS 的区别？ | 存储型落库、影响所有访问者且持久；反射型在 URL 里、需要诱导点击 |
| DOM 型 XSS 和它们差在哪？ | 数据从头到尾没经过服务端（如 `innerHTML = location.hash`），服务端转义无效，只能靠前端转义 + CSP |
| CSRF Token 为什么要 `hash_equals`？ | 普通 `==` 是短路比较，可通过响应时间差逐字节爆破 |
| CSRF Token 存 session 还是 cookie？ | 存 session（服务端），放表单里；**双提交 cookie**方案也可以，但要防子域写 cookie |
| 为什么 CSRF 需要 cookie 而 XSS 不需要？ | CSRF 利用的是浏览器**自动带凭证**；XSS 已经能执行 JS，直接读页面同源数据就行 |

---

## Q42. 502 和 504 的区别与排查

### 结论

一句话区分：

> **502 = 上游"说了话，但说的不是人话"（或者根本没连上）；504 = 上游"压根没说话"。**

- **502 Bad Gateway** —— nginx **连不上**上游，或者上游**在回完整响应头之前就断了/回了垃圾**。属于**连接层、协议层**问题。
- **504 Gateway Timeout** —— nginx **连上了、也发出了请求**，但在 `fastcgi_read_timeout` 内**没等到响应头**。属于**时间**问题。

实测：504 在 `fastcgi_read_timeout 3s` 下耗时 **3.004851 s**（刚好卡在超时值上）；502 中最快的一种（后端没监听）只要 **0.001723 s**。**看到 502 先看耗时——毫秒级返回的 502 和后端进程没关系，是连接层的。**

### 一、两者的分界

```
nginx -> 上游
   │
   ├─ 连 TCP ──── 连不上 ────────────────────► 502
   │              log: connect() failed (111: Connection refused)
   │
   ├─ 发请求
   │
   ├─ 等响应头 ─┬─ 上游断开 ─────────────────► 502
   │            │  log: upstream prematurely closed connection
   │            │  log: recv() failed (104: Connection reset by peer)
   │            │
   │            ├─ 上游回了不是 HTTP 的东西 ──► 502（但见反直觉第 2 条）
   │            │  log: upstream sent no valid HTTP/1.0 header
   │            │
   │            └─ 等到 fastcgi_read_timeout ─► 504
   │               log: upstream timed out (110: Connection timed out)
   │               ★ 注意：nginx 只是【放弃等待】，后端 PHP 还在跑
   │
   └─ 收响应体
```

### 二、实测（nginx 1.30.5 / PHP 8.4.25，脚本 `q42/01-reproduce.sh`）

| 复现方式 | 状态码 | 耗时 | nginx error log 原文 |
| --- | ---: | ---: | --- |
| `?mode=ok`（对照组） | 200 | 0.005822 s | （无） |
| **504**：PHP `sleep 10` > `fastcgi_read_timeout 3s` | **504** | **3.004851 s** | `upstream timed out (110: Connection timed out) while reading response header from upstream, … upstream: "fastcgi://127.0.0.1:9000"` |
| **502①**：停掉 php-fpm（后端没监听） | **502** | **0.001723 s** | `connect() failed (111: Connection refused) while connecting to upstream` |
| **502②**：给 FPM 池加 `request_terminate_timeout=2s`，PHP 睡 10s，worker 被 master SIGKILL | **502** | **2.518720 s** | `recv() failed (104: Connection reset by peer) while reading response header from upstream` |
| **502③**：上游收下请求后一个字节不回就 FIN（模拟 OOM kill） | **502** | **0.004794 s** | `upstream prematurely closed connection while reading response header from upstream, … upstream: "http://127.0.0.1:9099/"` |
| 反向：PHP 里直接 `exit`（无输出） | **200** | 0.011425 s | （无 error 级日志） |

502② 的 php-fpm 侧原文：

```
WARNING: [pool www] child 45298, script '/app/bench/network/q42/502-504.php' execution timed out (2.504118 sec), terminating
WARNING: [pool www] child 45298 exited on signal 15 (SIGTERM) after 4.681705 seconds from start
```

**error log 关键词对照表**——排查时直接搜这几句，比看状态码准：

| 关键词 | 含义 |
| --- | --- |
| `connect() failed (111: Connection refused)` | 上游**没在监听**：进程挂了 / 端口写错 / 只监听了 127.0.0.1 |
| `recv() failed (104: Connection reset by peer)` | 上游**进程被杀**（OOM、`request_terminate_timeout`、kill -9） |
| `upstream prematurely closed connection` | 上游**自己 close 了**（正常退出但没回响应、被优雅停掉） |
| `upstream timed out (110: …)` | **504**：超时，上游还活着 |
| `upstream sent no valid HTTP/1.0 header` | 上游回了非 HTTP 字节（见下） |

### 三、六个反直觉的点

**1. PHP 里 `exit` 掉不是 502，是 200。** 实测 `?mode=exit` → **HTTP 200，size=0B**。因为 php-fpm 会正常回一个"空的 FastCGI 响应"给 nginx，协议上是完整的。**"页面白屏"绝大多数情况下状态码是 200，不是 5xx**——排查白屏别去看 nginx 日志，去看 PHP 错误日志。

**2. 上游回垃圾字节，nginx 记了 error 却【不返回 502】。** 我让坏上游回一段非 HTTP 的字节，nginx 日志里明确写了 `upstream sent no valid HTTP/1.0 header`，但客户端收到的是：

```
* Received HTTP/0.9 when not allowed
* Closing connection 0
```

nginx 把这段垃圾**当成 HTTP/0.9 透传**了。**"日志里有 error 就等于客户端收到 502"是个错误假设**——排查时必须同时看 error log 和客户端实际收到的状态码。

**3. 504 是 nginx 单方面放弃，后端还在跑。** 实测里 `?mode=slow` 超时返回 504 之后，那个 PHP 进程还在把 10 秒睡完、还在写它的数据库。**这意味着超时会导致重复写入**：客户端拿到 504 就重试，两次请求都在写同一份数据。业务侧必须让这类写操作**幂等**，不能只依赖超时时间。

**4. `request_terminate_timeout` 会把"504"变成"502"。** 同样的 `sleep 10`：

- 只配 nginx `fastcgi_read_timeout 3s` → **504**（nginx 先放弃）
- 再配上 FPM `request_terminate_timeout=2s` → **502**（FPM 先把 worker SIGKILL 了，nginx 收到 RST）

**超时谁更短，决定了你看到哪个状态码。** 调这两个值时要知道它们在互相竞争。

**5. 502 也可能是 DNS 问题，而且日志长得不一样。** 这里没实测（本地没有 DNS 故障环境），**标为未实测**。但要知道这个形态：`proxy_pass http://backend.example.com` 时，如果域名解析不了，日志是 `no resolver defined to resolve …` 或 `host not found in upstream`。**排查 502 的第一件事是确认 `proxy_pass`/`fastcgi_pass` 写的是 IP 还是域名**——是域名就要考虑解析失败这条路径。

**6. `upstream prematurely closed connection` 和 `reset by peer` 的区别是"优雅"与"暴力"。** 前者是上游主动 `close()`（FIN），后者是进程被杀导致内核回 RST。我的坏上游脚本第一版用 `close()` 直接关，结果是 `reset by peer`（因为还有未读数据，内核发 RST）；改成 `shutdown(SHUT_WR)` + 把剩余数据读完，才得到 `prematurely closed connection`。**这两个日志对应的修复动作不同**：一个查上游代码为什么提前返回，一个查为什么被杀（OOM / 超时）。

### 四、实战结论

**排查顺序（按这个顺序走，能省一半时间）：**

| 步骤 | 看什么 | 判断 |
| --- | --- | --- |
| 1 | **耗时** | 毫秒级 502 → 连接层（进程没了 / 端口错）；秒级 502/504 → 超时或进程被杀 |
| 2 | `nginx error log` | 按上面的关键词表对号入座 |
| 3 | 上游进程在不在 | `ps aux \| grep php-fpm`、`supervisorctl status` |
| 4 | 上游端口通不通 | `curl -v http://127.0.0.1:9000`、`ss -lntp` |
| 5 | 是不是超时 | 对比 `fastcgi_read_timeout` / `request_terminate_timeout` / PHP `max_execution_time` **谁最小** |
| 6 | 上游自己的日志 | php-fpm 的 `WARNING: execution timed out, terminating`；OOM 看 `dmesg \| grep -i kill` |
| 7 | 502 且用了域名 | 查 DNS 解析 |

**超时参数的大小关系（必须满足）：**

```
max_execution_time  <  request_terminate_timeout  <  fastcgi_read_timeout
   PHP 自己先放弃         FPM 杀 worker                nginx 才放弃
```

反过来配的话，PHP 还没到自己的限制就被 FPM 杀了，日志里就只有 FPM 的 terminating，看不到 PHP 的 fatal error，**排查时会丢失最有用的那行错误**。

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 502 和 504 哪个更严重？ | 都严重，但 504 通常意味着**慢查询/慢依赖**，502 通常意味着**进程崩了**，后者更紧急 |
| nginx 返回 502 时会不会重试？ | `proxy_next_upstream` 可配，默认对 `error`/`timeout` 会试下一台上游；**非幂等请求要小心重复提交** |
| `fastcgi_intercept_errors` 的作用？ | 默认 off，上游返回的 4xx/5xx 原样透传；开了之后 nginx 会用自己的错误页替换 |
| 502 会不会是 nginx 自己挂了？ | 会，但概率低。nginx 挂了通常是连不上（curl 报 000），不是 502 |
| `upstream sent too big header` 怎么处理？ | 调 `fastcgi_buffer_size` / `fastcgi_buffers`（大 cookie 或大响应头时常见） |
| 怎么主动发现 502/504？ | 在 access log 里记 `$upstream_status` 和 `$upstream_response_time`，按这两个字段告警 |

---

## Q43. 什么是限流、熔断、降级？常见限流算法

### 结论

三者是**服务自保的三层**，作用位置完全不同：

| 手段 | 位置 | 一句话 | 判断依据 |
| --- | --- | --- | --- |
| **限流** | **入口** | 超过阈值就不让进来 | 当前 QPS / 并发数 |
| **熔断** | **出口** | 下游一直错就不再调它 | 下游的**错误率** |
| **降级** | **兜底** | 出问题时返回一个"凑合的答案" | 业务优先级 |

限流算法的实测结论是：**它们的差别不在"平均每秒放多少"，而在"窗口边界"和"突发容忍度"。** 同样限 10 次/秒，在窗口边界打两波突发，**固定窗口放过了 20 个，滑动窗口只放过 10 个**。

### 一、四种限流算法

```
限值：10 次 / 1000ms        请求到达时刻（垂直刻度 = 时间，横线 = 放行）

① 固定窗口（fixed window）—— 窗口按【绝对时间】对齐
   |======== 窗口 N ========|======== 窗口 N+1 ========|
        10 个全部放行 ✓              10 个全部放行 ✓
        └──────── 只隔 300ms，却过了 20 个 ────────┘      ★ 边界漏洞

② 滑动窗口·日志（sliding window log）—— 存每次请求的时间戳，数最近 1 秒
   [────────── 最近 1000ms ──────────]
        10 个已经在窗口里了 → 后面来的全拒 ✗             ★ 精确，但内存 O(请求数)

③ 令牌桶（token bucket）—— 桶里最多 cap 个令牌，按 rate 个/秒补
   空闲时：桶里攒满 10 个
   ┌──────────┐  来一个请求取一个
   │ ●●●●●●●●●● │  取空 → 拒绝（或等待）
   └──────────┘  ← 每秒匀速补 10 个
              ★ 允许突发（最多 cap 个），出口速率长期 = rate

④ 漏桶（leaky bucket）—— 请求先排队进桶，桶底按【恒定速率】漏出
   ┌──────────┐
   │ 请求 请求 │  到达可以任意快
   │ 请求 请求 │
   └────┬─────┘  ← 恒定 10 个/秒漏出，出口永远匀速
        ▼
   ★ 不拒绝，而是【摊平】：10 个瞬间到达 → 被拉成每 200ms 发一个
```

### 二、实测（PHP 8.4.25 / Redis 7.4.11）

全部用 **Lua 脚本**执行，判定和状态更新在一个原子操作里完成。脚本在 `../bench/network/q43/`：
`ratelimit.php`（五种实现）、`01-scenarios.php`（场景）、`02-loadtest.php`（并发压测）、`03-atomicity.php`（原子性）、`04-nginx-limit-req.sh`（生产实现）。

**场景一：窗口边界处的两波突发**（边界前 150 ms 打 10 个，边界后 150 ms 再打 10 个）：

| 算法 | 第一波通过 | 第二波通过 | **合计** | 全部打完耗时 |
| --- | ---: | ---: | ---: | ---: |
| **fixed_window** | 10 / 10 | 10 / 10 | **20 / 20** | 304.4 ms |
| sliding_log | 10 / 10 | 0 / 10 | 10 / 20 | 302.3 ms |
| sliding_counter | 10 / 10 | 1 / 10 | 11 / 20 | 301.9 ms |
| token_bucket | 10 / 10 | 3 / 10 | 13 / 20 | 302.4 ms |
| leaky_bucket | 10 / 10 | 3 / 10 | 13 / 20 | 301.9 ms |

**固定窗口在 304 ms 内放行了 20 个，而它承诺的是「每 1000 ms 最多 10 个」——整整 2 倍。**

**场景二：静默 2 秒后一次连打 20 个**（先清空状态）：

| 算法（参数） | 通过 | 第几个开始被拒 |
| --- | ---: | --- |
| fixed_window (10/1000ms) | 10 / 20 | 第 11 个 |
| sliding_log (10/1000ms) | 10 / 20 | 第 11 个 |
| sliding_counter (10/1000ms) | 10 / 20 | 第 11 个 |
| token_bucket (rate=10/s **cap=10**) | 10 / 20 | 第 11 个 |
| **token_bucket (rate=10/s cap=20)** | **20 / 20** | **没有拒绝** |
| leaky_bucket (rate=10/s cap=10) | 10 / 20 | 第 11 个 |

**两行 token_bucket 只差了 cap，结果就从"拒一半"变成"全放"。** 桶容量才是突发容忍度的旋钮。

**场景三：漏桶的排队效果**（rate=5 个/秒，桶容量 20，瞬间来 10 个，脚本记录的是"计划发出时刻"）：

```
  #1          0.2 ms         #6       1000.3 ms
  #2        200.5 ms         #7       1199.5 ms
  #3        399.7 ms         #8       1399.7 ms
  #4        599.8 ms         #9       1599.9 ms
  #5        800.0 ms         #10      1800.1 ms
  10 个请求的【到达跨度】: 0.2 ms      计划【发出跨度】: 1799.9 ms
```

**到达时挤在 0.2 ms 里，出口被拉成严格等距的 200 ms 一个。**

**并发压测：8 进程 × 400 次/秒 打 3 秒，限值 200 次/秒**（每格 = 100 ms 内通过数）：

```
  fixed_window      40 40 40 40 40 40 40 40  0  0  0  0  0 40 40 40 40 40  0  0  0  0  0 40 40 40 40 40  0  0
  sliding_log       40 40 40 40 40  0  0  0  0  0 39 40 40 40 39  2  0  0  0  0 37 40 40 38 40  5  0  0  0  0
  sliding_counter   40 40 40 40 40  0  0  7 20 20 20 20 20 20 20 20 20 20 19 20 20 20 21 19 20 20 20 19 20 20
  token_bucket      40 40 40 40 40 40 40 40 40 36 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20
  leaky_bucket      40 40 40 40 40 40 40 40 40 36 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20 20
                    s · · · · · · · · ·  s · · · · · · · · ·  s · · · · · · · · ·   (s = 整秒边界)
```

| 算法 | 实际压力 | 通过 | 拒绝 | 实际放行速率 | 曲线形态 |
| --- | ---: | ---: | ---: | ---: | --- |
| fixed_window | 400 次/秒 | 720 | 480 | **240 次/秒** | 成簇：40×5 然后 0×5，峰值/均值 = **1.67** |
| sliding_log | 400 次/秒 | 600 | 600 | 200 次/秒 | 严格 200/秒，但形状跟随到达 |
| sliding_counter | 400 次/秒 | **645** | 555 | **215 次/秒** | 前期成簇，后期被近似抹平成 20/格 |
| token_bucket | 400 次/秒 | 796 | 404 | **265 次/秒** | 前 1 秒吃掉 cap+rate=400，之后严格 20/格 |
| leaky_bucket | 400 次/秒 | 796 | 404 | **265 次/秒** | 同上（"通过"= 收进队列） |

> **固定窗口这一行的绝对数字会随测试起点而变**（同样脚本三次跑出 720 / 616 / 800），因为它的窗口边界由**绝对时间**决定，而测压起点落在窗口的哪个位置是随机的。**这本身就是固定窗口的问题：它的行为不可预测。** 滑动窗口和令牌桶的 200/秒则是稳定的。

**原子性对比**（`03-atomicity.php`，限值 50 次/秒，8 个并发进程猛打 1 秒）：

| 版本 | 放行 | 计数器最终值 | 放行 / 限值 |
| --- | ---: | ---: | --- |
| A 非原子（`GET` → 判断 → `INCR`） | **54** | 54 | **1.08×** ← 漏了 |
| B 非原子 + 临界区多花 200 µs | **53** | 53 | **1.06×** ← 漏了 |
| C 原子（整段 Lua） | **50** | 50 | **1.00×**（精确） |

**生产实现：nginx 的 `limit_req`**（`04-nginx-limit-req.sh`，nginx 1.30.5，`rate=10r/s burst=5 nodelay`）：

```
28 个请求瞬间打出：
  200 200 200 200 200 200 429 429 200 429 429 429 429 429 429 429 429 200 429 429 429 429 429 429 429 200 429 429
  └──── 6 个 ────┘                                                        ↑ 零星几个 200 是这 0.5 秒里按 10r/s 补出来的
  对照组（同一个文件不加 limit_req）：28 个全部 200

停 1 秒再打 12 个：200 200 200 200 200 200 429 429 429 200 429 429    ← 攒回的额度被一次性兑现
被拒时的响应：HTTP/1.1 429 Too Many Requests
```

### 三、六个反直觉的点

**1. 固定窗口最坏能放过 2 倍流量，而且这是设计缺陷不是 bug。** 场景一里它 304 ms 放了 20 个。原因是它只数"当前窗口内有多少个"，完全不看**上一个窗口刚过去多少**。任何按秒/分钟对齐的计数器都有这个问题。**唯一的例外是：如果你的窗口边界是错开的（比如按 key 哈希成 10 个不同边界），最坏情况会摊薄到 ~1.1 倍。**

**2. 令牌桶的 `rate` 决定长期速率，`cap` 决定突发上限——而 `cap` 常被忽略。** 场景二里 `rate=10/s` 不变，只把 `cap` 从 10 改成 20，就从"拒 10 个"变成"全放"。**调参时只看 rate 是不够的**：`cap` 太大 = 限流形同虚设（一个空闲期后能打出巨大突发）；`cap` 太小 = 正常用户会被误杀（页面一次发 20 个静态资源就爆了）。

**3. 滑动窗口·计数是近似算法，实测会「多放」。** 压测里它放行 645 个，比精确的滑动窗口日志（600 个）多 **7.5%**，速率 215 次/秒 > 限值 200 次/秒。因为它用「上一个窗口的计数 × 剩余时间比例」来估算，而**上一个窗口内的请求不是均匀分布的**。换来的是内存从 O(请求数) 降到 O(1)。**能接受 5–10% 的超发就用它，不能就老老实实上 zset。**

**4. 漏桶（带队列）和令牌桶在"收不收"这个决策上几乎一样，区别只在延迟。** 压测里两者放行数分别 796 / 796，曲线几乎重合。它们的真正差别是：**令牌桶让突发过去（快），漏桶把突发摊平（慢但稳）**。所以选型问题不是"哪个限得更准"，而是"**我愿不愿意用延迟换平滑**"。漏桶还有个隐藏成本：**排队要占内存和连接**，`cap` 就是"最多积压多少个请求"。

**5. 限流的判定和状态更新必须原子，否则限流就是个摆设。** 原子性实验里，非原子的 `GET` → 判断 → `INCR` 在 8 并发下多放了 6–8%。注意这只是**一次 Redis 往返**那么小的窗口。**如果读写之间还有查库、鉴权、写日志，窗口会大得多，漏得也更多。** PHP 里必须用 Lua（`$redis->eval()`）把整段逻辑包起来，不能用多条命令拼。

**6. nginx 的 `limit_req` 就是漏桶，`burst` 是队列长度。** `burst=5` 意味着"桶里能积压 5 个"，`nodelay` 决定这 5 个是**立刻放行**（`nodelay`）还是**排队慢慢放**（不加 `nodelay`，客户端会看到延迟）。实测前 6 个（burst+1）通过、其余 429，和理论完全对上。**注意 `limit_req` 是单机内存限流**——多台 nginx 之间不共享状态，总放行量是"每台 × 台数"；要全局精确限流还是得回 Redis。

### 四、实战结论

| 需求 | 选择 |
| --- | --- |
| 简单、省内存、能容忍边界 2 倍 | 固定窗口 |
| 精确、QPS 不高（几千以内） | 滑动窗口·日志（zset） |
| 精确、QPS 很高、能接受 5–10% 超发 | 滑动窗口·计数 |
| **接口限流（最常用）** | **令牌桶**，`cap` 设成"业务能承受的瞬时突发" |
| 要严格平滑出口（保护下游脆弱系统） | 漏桶（带队列），并给**队列设上限** |
| 单机、想少写代码 | nginx `limit_req`（漏桶）+ `limit_conn`（并发数） |
| 多机、要全局一致 | Redis + Lua（状态必须放共享存储） |
| 想留证据 / 差异化收费 | 固定窗口或滑动窗口·计数（能直接读出"本分钟用了多少"） |

**熔断和降级的落点：**

> **本节只给结论和形态，未实测。** 任务要求实测的是四种限流算法（上面已完成）；熔断需要在 PHP 侧真正跑一个"下游持续报错 → 打开开关 → 半开试探 → 恢复"的状态机才能出数字，本项目**没有做**，所以下面这张表里的阈值都是**示例值，不是实测值**。

| 手段 | 触发条件（示例，非实测） | 动作 |
| --- | --- | --- |
| 熔断 | 10 秒内错误率 > 50% 且请求数 > 20 | 打开 30 秒，期间直接失败不调用下游 |
| 熔断·半开 | 30 秒后 | 放 1 个请求试探，成功就关闭熔断 |
| 降级 | 熔断打开 / 限流拒绝 / 依赖超时 | 返回缓存旧值、默认值、精简版页面 |

**三者要一起设计**：限流挡住了入口的洪峰，但挡不住"下游自己挂了"——那要靠熔断；熔断之后用户体验会断崖式下跌——那要靠降级给一个有损但可用的结果。

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 限流和熔断的区别？ | 限流看**自己**的入口流量，熔断看**下游**的健康度；前者是"我不让你进来"，后者是"我不去找它" |
| 熔断和降级的区别？ | 熔断是**机制**（自动开关），降级是**策略**（返回什么）；熔断打开后通常执行降级逻辑 |
| 为什么令牌桶比漏桶常用？ | 令牌桶允许突发（更贴近真实流量），且实现只需存两个字段（tokens + ts） |
| 滑动窗口日志的内存问题怎么缓解？ | 缩短窗口 + 组合（1 分钟 = 60 个 1 秒计数器）、或用漏斗/令牌桶替代 |
| 分布式限流怎么做？ | Redis + Lua 是最常用的；再往上分"中心化"（精确但 Redis 是瓶颈）和"分片配额"（每台分 N/M 个，本地限） |
| 限流键（key）怎么选？ | 用户 ID > 设备 ID > IP。**纯 IP 限流会误伤 NAT 出口后的整个公司** |
| 被限流时应该返回什么？ | HTTP **429 Too Many Requests** + `Retry-After` 头；不要返回 503（语义是"服务不可用"） |
| 压测时怎么验证限流真的生效？ | 固定并发打满，看**放行数是否显著超过阈值**（本项目实测：非原子实现放出 54/50，原子实现 50/50） |
