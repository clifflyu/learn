# 07 · 性能与线上故障（Q44–Q45）

> 配套 `../php-senior-interview-top50.md`。数字均来自容器实测（**PHP 8.4.25 / MySQL 8.4.11 / nginx + php-fpm 8.4.25**，宿主机 WSL2），脚本在 `../bench/ops/`。
>
> 前置：Q4 已经实测过进程池的三种模式、`pm.max_children` 怎么估算、以及「池子不够不会 502，只会变慢」这条结论，本节**不重复**，只补它的下半场：**怎么在 5 分钟内把「变慢」归因到四类成因之一**，以及队列造成的延迟阶梯长什么样。
>
> 环境注记（影响可复现性，如实说明）：
> 1. 这台机器上同时有别的实测在跑（同一容器、同一 MySQL），日志和耗时里混着它们的请求。本节的耗时都是**多次测量的中位数**，日志聚合里能看到别的路径的请求。
> 2. 容器里**没有 `ps` / `top` / `strace`**，也没有 `CAP_SYS_PTRACE`（`CapEff = 00000000a80425fb`，第 19 位为 0）。所有进程观测都靠读 `/proc`（`q45-proc-top.sh` 是自己写的容器内 top），`/proc/<pid>/syscall`、`/proc/<pid>/io`、`/proc/<pid>/wchan`（读出恒为 `0`）在这个容器里都读不到。

---

## Q44. 接口突然变慢，你的排查思路和顺序是什么？

### 结论

排查顺序是**从外到内、每一步只回答一个问题**，而且每一步都有「一票否决」的指标：

```
时间轴：0. 划范围 → 1. 夹逼(客户端/服务端) → 2. 分段(排队/自身/DB/下游) → 3. 定位到行
```

四类成因在实测里表现完全不同，一分钟内就能分开：

| 成因 | 特征指标 | 实测数字 |
| --- | --- | --- |
| 慢 SQL | `Rows_examined ÷ Rows_sent` 极大 | 扫 100 万行只返回 20 行（**50,001 : 1**），单条 1.081s |
| 外部依赖变慢 | 应用自身/DB 时间不变，下游时间占满 | 下游 1502ms / 自身 3.8ms / DB 2.1ms |
| FPM 进程池打满 | 请求耗时成阶梯、`listen queue` > 0 | P50 从 205ms → **980ms**，状态页本身排队 **9.01s** |
| 代码本身 | SQL 条数被放大，或单行开销 × N | 31 条 SQL / **10,460ms** → 2 条 SQL / 491ms |

**最重要的一句话**：`curl -w` 只能告诉你「服务端慢」，分不出是等 DB、等下游、还是排队——**必须进服务端做分段计时**，否则后面全是猜。

### 一、排查决策树

```
接口变慢
  │
  ├─ 0. 范围：所有接口慢，还是 1 个接口慢？
  │     全部慢 ────► 共用资源：DB / 下游 / FPM 池 / 机器负载
  │     个别慢 ────► 这个接口自己的 SQL、循环、外部调用
  │     命令: sh bench/ops/q44-access-digest.sh        ← 按 URI 聚合耗时，一屏定性
  │           （数据源就是 FPM 自带的 access log，见下面「二、5」）
  │
  ├─ 1. 时限：突然（分钟级）还是渐变（天/周级）？
  │     突然 → 变更类：发布、配置、DDL、下游改版、流量突增、缓存失效
  │     渐变 → 累积类：数据量涨、索引选择性退化、内存泄漏、连接泄漏
  │     命令: 把 access log 按分钟聚合，找拐点；再对齐发布/变更时间
  │
  ├─ 2. 夹逼：慢在「网络」还是「服务端」
  │     curl -w '%{time_connect} %{time_pretransfer} %{time_starttransfer} %{time_total}'
  │     connect ≈ 0 → 网络没问题，继续往下；connect 大 → 先查网络/DNS/带宽
  │
  ├─ 3. 服务端分段：耗时到底花在哪一段
  │     ├─ 排队：FPM 状态页 ?full 的 request duration；active/idle/total processes；listen queue
  │     ├─ 自身：应用里 self 计时（埋点回写响应头/日志）
  │     ├─ DB：慢查询日志的 Query_time、Rows_examined/Rows_sent；EXPLAIN ANALYZE
  │     └─ 下游：直连下游的耗时 vs 接口整条耗时，差值就是接口自己的开销
  │
  └─ 4. 定位到行：先看是不是代码问题
        代码问题有两种形态，修法正交、别混着说：
        ① 条数被放大（N+1）→ 改写 SQL，减少往返次数
        ② 单条本来就重（全表扫、大数组、正则回溯）→ 加索引 / 换算法
        命令: 采样 profiler（excimer/php-spx）；或 FPM 的 request_slowlog_timeout
```

耗时预算摊开看是这样的（括号里是本次实测值）：

```
客户端看到的总耗时（curl -w %{time_total}）
├── connect / TLS          网络 RTT            实测 0.0002 ~ 0.0003 s
├── 排队等 worker          FPM 进程池          实测 P50 980 ms（池子=2、20 并发、单请求 200ms）
├── 应用自身计算           PHP 代码            实测 3.8 ms
├── DB                     慢查询/连接池       实测 2.1 ~ 2.7 ms
├── 下游 HTTP              第三方/内部服务     实测 1502 ms
└── 传输 / 序列化          响应体大小          本例可忽略（nginx 日志里 body_bytes_sent = 245 B）
```

### 二、实测（PHP 8.4.25 / MySQL 8.4.11）

测前准备：`bench/ops/q44-sql-setup.sql` 建库 `q44_slow`（**不碰 `learn` 库**），`orders` 100 万行（`status = n % 5`、`created_at` 与 `status` 相互独立），**故意只留主键、不加二级索引**。建表 36s，数据 58MB。

#### 1. 成因一：慢 SQL —— 先看 `Rows_examined ÷ Rows_sent`，不是 `Query_time`

脚本：`bench/ops/q44-sql-setup.sql` + `bench/ops/q44-sql-slow.sh`（脚本自己临时把 `long_query_time` 调到 0.05s，跑完还原 0.1s；用 `/*q44:slow*/` 标记 + 记录日志字节偏移，避免误读日志里别人的慢查询）。

查询（典型的「按状态查最近订单」，正是最容易上线的写法）：

```sql
SELECT SQL_NO_CACHE id, customer_id, amount, created_at FROM orders
 WHERE status = 0 AND created_at >= NOW() - INTERVAL 110 DAY
 ORDER BY created_at DESC LIMIT 20
```

慢查询日志里新增的条目（**这一行才是排障起点**）：

```
# Query_time: 1.081225  Lock_time: 0.000004  Rows_sent: 20  Rows_examined: 1000020
```

`Rows_sent = 20` 而 `Rows_examined = 1,000,020`：为了吐出 20 行，翻遍了整张表，**比值 50,001 : 1**。看到这种量级，不用看 SQL 全文就知道是缺索引 + 排序没走索引。

`EXPLAIN` / `EXPLAIN ANALYZE`（8.0.18+ 才有 ANALYZE，能给出**真实**行数与真实耗时）：

| 阶段 | 输出 |
| --- | --- |
| `EXPLAIN`（加索引前） | `type=ALL`、`key=NULL`、`rows=996360`、`filtered=3.33`、`Extra=Using where; Using filesort` |
| `EXPLAIN ANALYZE`（加索引前） | Table scan `actual time=5.87..939 rows=1e+6`；Filter 后 `rows=60279  actual time=5.9..1204`；Sort `actual time=1243..1243 rows=20` |
| `EXPLAIN`（加索引后） | `type=range`、`key=idx_status_created`、`rows=123446`、`Extra=Using index condition; Backward index scan` |

加 `idx_status_created (status, created_at)` 前后各连跑 5 次（表已预热，取中位数）：

| 版本 | 5 次耗时（s） | 中位数 |
| --- | --- | ---: |
| 无索引 | 1.6254 / 1.0087 / 1.2382 / 1.0490 / 0.8391 | 1.0490 |
| 有索引 | 0.0427 / 0.0445 / 0.0438 / 0.0429 / 0.0455 | **0.0438** |

**24 倍**。注意 `Extra` 里的 `Backward index scan`：`ORDER BY created_at DESC LIMIT 20` 因为复合索引的第二列就是 `created_at`，倒着扫索引就够了，**`Using filesort` 也一起消失了**——这是联合索引列顺序「等值在前、范围/排序在后」的直接收益。测完脚本会把索引删掉，让这个库保持「有问题」的状态。

#### 2. 成因二：外部依赖变慢 —— 应用和 DB 都没变，时间全在等

脚本：`bench/ops/q44-downstream.php`（假下游，`?ms=` 控制耗时）、`bench/ops/q44-api.php`（被测接口，分段计时后回写响应头）、`bench/ops/q44-downstream.sh`。

**第一步：客户端分段计时只能到此为止。**

| 请求 | connect | pretransfer | starttransfer(TTFB) | total |
| --- | ---: | ---: | ---: | ---: |
| 无下游 | 0.0003 | 0.0003 | 0.0125 | 0.0126 |
| `?down=1500` | 0.0002 | 0.0003 | 1.6444 | 1.6448 |

`connect` 稳定在 0.3ms：网络、TLS、端口都没问题。**慢在服务端处理**，客户端的信息用尽了。

**第二步：服务端自己分段（把时间回写到响应头，生产上写日志/APM 也一样）。**

| 请求 | self | DB | downstream | total |
| --- | ---: | ---: | ---: | ---: |
| 无下游 | 3.8 ms | 2.7 ms | 0 ms | 6.6 ms |
| `?down=1500` | 3.8 ms | 2.1 ms | **1502 ms** | 1507.8 ms |

一眼定性：`self` 和 `db` 一动不动，多出来的 1500ms 全是下游。这一步的价值是**把「服务端慢」再切成三段**，否则只能看着 TTFB 猜。

**第三步：证明这个 worker 在「等」而不是在「算」**（FPM 状态页找到 worker → 读 `/proc`）：

```
worker = 38244 Running /bench/ops/q44-api.php?down=8000
State:    S (sleeping)          ← 不是在跑
VmRSS:    30172 kB   Threads: 1
wchan:    0                     ← 本容器无 CAP_SYS_PTRACE，读不出内核等待点
syscall:  （读不到，Operation not permitted）
utime=0 stime=1  →  2 秒后再看：utime=0 stime=1     ← CPU 时间一动不动
```

`State = S` + 2 秒内 `utime`/`stime` 完全不动 = **它在等**（等 socket、等锁、等 sleep），不是 CPU 问题。

**第四步：量化责任。**

| 请求 | 耗时 |
| --- | ---: |
| 直连下游 | 0.802092 s |
| 接口整条 | 0.809852 s |

差值 7.8ms = 接口自己的全部开销。**瓶颈在下游**，把下游的监控/负责人拉进来就完了，不需要再看 PHP 代码。

#### 3. 成因三：FPM 进程池打满 —— 唯一「应用代码完全没问题」的成因

脚本：`bench/ops/q44-pool.sh`（自己改 `pm.max_children` 并等 worker 数稳定，跑完还原 `dynamic / 6`）。

20 个并发请求，每个接口固定占住 worker 200ms：

| `pm.max_children` | worker 数 | P50 | P99 | max | 总墙钟 |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 2 | 2 | **980 ms** | 1957 ms | 1960 ms | 2.05 s |
| 5 | 5 | 384 ms | 729 ms | 734 ms | 0.87 s |
| 20 | 20 | 205 ms | 210 ms | 217 ms | 0.29 s |

阶梯公式对得上：`排队延迟 = ceil(并发数 ÷ 池子大小) × 单请求耗时`（`ceil(20/2) × 200ms = 2.0s`，`ceil(20/5) × 200ms = 800ms`）。**池子越小，分布越平地被拉长——P50 是排队时间，不是代码变慢**。

同一份证据在 FPM 自带的 access log 里也能独立看到（`/bench/fpm/slow.php` 301 次请求）：**P50 = 202ms，P99 = 2502ms，max = 2503ms**——P50 正常、P99 爆掉，正是排队型劣化的指纹。

**一个容易被忽略的副作用：池子满的时候，连监控都跟着排队。**

| 取状态页的时机 | 耗时 |
| --- | ---: |
| 池子被 10 个 2000ms 请求占满 | **9.012992 s** |
| 池子空闲 | 0.002583 s |

差 3400 倍。状态页本身也占一个 worker，**最需要监控的时刻，监控最钝**。所以容量告警不能只靠「实时拉状态页」，要用 `max listen queue` 这种累计计数器：

```
listen queue:         0
max listen queue:     9        ← 历史最高 9 个请求在排队，这就是证据
max active processes: 2
max children reached: 0
```

（本节只补队列造成的延迟阶梯和监控钝化；三种模式的行为、`memory_get_usage` vs `VmRSS` vs `Private_Dirty` 的口径差异、`max_children` 的估算公式见 **Q4**，不重复。）

#### 4. 成因四：代码本身 —— N+1 与「单行开销 × N」

脚本：`bench/ops/q44-nplus1.php`（三种写法：`n1` 循环发 SQL / `in` 一条批量 / `join` 一条 GROUP BY，响应里报 SQL 条数）+ `bench/ops/q44-nplus1.sh`。

同一张表、同一份数据、30 个客户，只换写法：

| 写法 | SQL 条数 | 无索引 | 加 `idx_cust_status_created` 后 |
| --- | ---: | ---: | ---: |
| `n1`（循环发） | **31** | 10,460.1 ms | 63.1 ms |
| `in`（批量捞回） | 2 | 490.7 ms | 5.5 ms |
| `join`（GROUP BY） | 2 | 537.6 ms | 5.2 ms |

这张表要横竖两个方向读，**两个修法是正交的**：

- 横向：加索引把 N+1 从 10.46s 压到 63.1ms（166 倍）——但 **SQL 条数还是 31 条**；
- 竖向：改写法把 31 条压到 2 条——但**每条还是要扫表**。

所以「加完索引就没事了」和「改写 SQL 就没事了」都是半对。加索引后 `n1` 仍是 63.1ms、而 `in` 是 5.5ms，**11 倍的差距就是往返次数**。真实系统里这 31 条要走网络、走连接池、走鉴权，放大得更多；本次实测的 SQL 条数是用 `SHOW SESSION STATUS LIKE 'Com_select'` 前后差值数出来的（不是估的）。

第二种代码问题形态：**单行开销 × N**。脚本 `bench/ops/q44-slowreq.php`（Controller → Service → Repository → Query 四层，循环里排序 4000 元素 × N 次）：

| `?rows=` | 100 | 400 | 1000 |
| --- | ---: | ---: | ---: |
| 耗时 | 0.102 s | 0.374 s | 0.943 s |

线性斜率为 **0.94 ms/行**（截距约 8ms 是框架+启动的固定开销）。这种曲线在日志上表现为「耗时和入参规模成正比」，而不是「和某张表的数据量成正比」——这是和慢 SQL 区分的关键。

#### 5. 从「哪个接口」到「哪一行」：两条现实可用的路

**路一：FPM 自带的 access log——不用装 APM，每个请求的耗时/内存/CPU 就已经在日志里了。**

镜像里池配置第 354 行（原始配置）：

```
access.format = "[php-fpm:access] %R - %u %t \"%m %r%Q%q\" %s %f %{mili}d %{kilo}M %C%%"
                                        耗时(ms)↑    峰值内存(KB)↑  CPU↑
```

`access.log = /docker.stdout`，所以从 `docker logs` 里捞。脚本 `bench/ops/q44-access-digest.sh` 按 URI 聚合出 avg/P50/P99/max，并按分钟找拐点，实测输出（含同机器上其他实测的请求）：

```
URI                                      次数        avg        P50        P99        max
/bench/fpm/slow.php                       301      710.5        202       2502       2503
/bench/ops/q44-nplus1.php                  24     2178.0        341      16124      16124
/bench/ops/q44-api.php                     32     4087.2       1510      19500      19500
/bench/ops/q44-downstream.php              28     7412.7       6000      35002      35002
```

**路二：拿调用栈。** `request_slowlog_timeout` + `slowlog` 是 FPM 自带的「慢请求 dump 调用栈」功能，但**在这个容器里是哑的**——脚本 `bench/ops/q44-slowlog.sh` 实测：

```
slowlog = /var/log/php-fpm.slow.log
request_slowlog_timeout = 1s
→ 发一个实测 1.026652 s 的请求
→ slowlog 文件大小: 0 字节
→ docker logs 里唯一的线索：
  ERROR: failed to ptrace(ATTACH) child 39679: Operation not permitted (1)
```

原因：FPM 打调用栈靠 `ptrace(PTRACE_ATTACH)` 挂到 worker 上读栈，而容器默认没有 `CAP_SYS_PTRACE`（本环境 `CapEff=00000000a80425fb`，第 19 位为 0），于是它**静默放弃**，只在 error log 留一行。不看 error log 会误判成「我的请求还不够慢」。

**未实测**：给容器加 `--cap-add SYS_PTRACE` 后 slowlog 打出的真实栈长什么样（本环境改不了，只能改 `docker-compose.dev.yml` 或重建容器，均不允许）。所以本仓库的栈定位统一用**采样 profiler**，见 Q45 的 `bench/ops/q45-excimer.php`（实测能给出函数级占比和行号）。

### 三、五个反直觉的点

**1. 慢查询日志里最该看的不是 `Query_time`，是 `Rows_examined ÷ Rows_sent`。** 实测这条查询 1.08s 扫 100 万行只返回 20 行，比值 50,001:1。`Query_time` 会随机器负载波动（同一查询 5 次从 0.839s 到 1.625s，差近 1 倍），**比值不会**——它直接指向「扫描效率」，是稳定的信号。反过来，`Rows_examined` 很大但比值接近 1 的查询（全表聚合、报表），加索引也没用，该走汇总表/离线。

**2. `EXPLAIN` 的 `rows` 是估算，可能差一倍；要真实行数和耗时得 `EXPLAIN ANALYZE`。** 实测加索引前 `EXPLAIN` 估 `rows=996360`，`EXPLAIN ANALYZE` 实际扫了 `1e+6` 行；加索引后 `EXPLAIN` 估 `rows=123446`，实际过滤后只剩 `60279` 行。**但注意 `EXPLAIN ANALYZE` 会真的执行查询**，线上别对写语句或重查询随手跑。

**3. 「接口慢」不等于「接口的代码慢」。** TTFB 从 0.0126s 涨到 1.6444s，看起来是「接口变慢了 130 倍」；分段埋点显示 self 一直是 3.8ms、DB 2.1ms，**只有下游从 0 变成 1502ms**。这个案例里去看 PHP 代码、去看 OPcache、去加机器都是白费。**先分段，再优化**。

**4. 池子打满时监控会一起变钝。** 实测池子满的时候取 FPM 状态页要 **9.01s**，空闲时 0.0026s。为什么？状态页也是一个请求，也要占一个 worker，也要排队。所以：容量类告警要用**累计计数器**（`max listen queue`、`max children reached`）而不是「实时拉一个状态页看瞬时值」，否则告警链路自己就被雪崩淹了。同理，池子满的时候 `curl` 探活也会超时，**不要把「探活超时」直接等同于「进程挂了」**。

**5. 索引和改写是两件正交的事，别用「加了索引变快了」掩盖 N+1。** 实测：加索引后 N+1 从 10,460ms 降到 63.1ms，但还是 31 条 SQL；而同一时刻 2 条 SQL 的写法只要 5.5ms。**加索引让每条更快，改写让条数更少**，前者救不了后者的量级差（这里 11 倍），后者也救不了单条全表扫（这里 166 倍）。面试时说「我加了索引优化了 N+1」是会被追问的。

### 四、实战结论

**现象 → 第一步看什么：**

| 现象 | 第一步看什么 | 具体命令 / 位置 |
| --- | --- | --- |
| 所有接口一起慢 | 共因（DB / 池子 / 机器 / 下游） | `sh bench/ops/q44-access-digest.sh`（按 URI 聚合，全慢就是共因）|
| 只有某个接口慢 | 这个接口的 SQL 与外部调用 | access log 里单 URI 的 P99；应用埋点分段 |
| 只有高峰期慢、P50 正常 P99 差 | 排队 | FPM 状态页 `active processes` / `listen queue` / `max listen queue` |
| 慢，但 CPU/内存/DB 指标都正常 | 在等 | worker `State=S` + `utime` 不动；直连下游 vs 接口耗时对比 |
| 慢，且和入参规模成正比 | 代码里的循环/条数放大 | 数 SQL 条数（`Com_select` 差值）、单行耗时 × N |
| 慢，且和某张表的数据量成正比 | 慢 SQL / 缺索引 | 慢查询日志的 `Rows_examined/Rows_sent` + `EXPLAIN ANALYZE` |
| 想直接知道慢在哪一行 | 采样 profiler | `docker exec learn-php php /app/bench/ops/q45-excimer.php`（Q45 详述）|

**改动前后必须留下的证据**（面试最爱问「你怎么证明优化有效」）：同一条查询/同一并发下，**改前 5 次 + 改后 5 次**，报中位数和 P99，报 SQL 条数，报 `Rows_examined`。本节的 `1.0490s → 0.0438s`、`31 条 → 2 条`、`1,000,020 → 20` 就是按这个格式留的。

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 没有 APM / 不能改代码埋点，怎么分段？ | FPM 自带能力就够：① `access.format` 加 `%{mili}d` 拿到每请求耗时、`%{kilo}M` 拿峰值内存、`%C%%` 拿 CPU；② `pm.status_path` 拿每个 worker 正在跑什么、跑了多久；③ 慢查询日志；④ 直连下游做差值。四样都是配置级的 |
| 怎么区分「DB 慢」和「连接池/连接数打满」？ | DB 侧看 `SHOW FULL PROCESSLIST`（有没有大量 `Sleep` 连接）、`Threads_connected` vs `max_connections`、慢日志里有没有对应条目。**慢日志里没有但接口在等 DB，就是连接池/网络层**——注意本次环境未实测连接池打满（未实测） |
| `EXPLAIN` 里 `filtered` 很小说明什么？ | 说明驱动表选出来的行大部分会被丢掉。实测 `filtered=3.33` 配合 `type=ALL`，就是「全表扫一遍只留下 3%」，典型缺索引 |
| 为什么 `ORDER BY ... DESC LIMIT 20` 有时候不用 filesort？ | 排序键能接在等值条件后面形成复合索引时，优化器可以倒序扫索引直接取 20 行。实测 `Extra=Backward index scan`，`Using filesort` 消失。**索引列顺序必须是「等值 = 前缀、范围/排序 = 后缀」**，反过来（`created_at` 在前）就用不上 |
| 慢查询日志该配多少？ | 阈值要按业务定：核心接口 0.1s 量级就值得录，报表接口 1s 起。本次环境 `long_query_time=0.1`；脚本里临时降到 0.05s 是为了拿到证据。生产上更该看的是**日志条数按接口聚合**，而不是单条 Query_time |
| N+1 除了 `in`/`join` 还能怎么修？ | ① 批量预取（`whereIn`）；② 用 `join`/子查询在 DB 侧聚合；③ ORM 预加载（`with()`）；④ 在 Service 层做 DataLoader 式的批量合并。选哪个看聚合逻辑在不在 DB 侧更好做 |
| 池子该开多大？`max_children` 怎么算？ | 见 Q4：分母用单 worker 的**峰值 `Private_Dirty`**（不是 `memory_get_usage`，也不是 `VmRSS`），并且要按「最重的接口」量。另外别忘了每个 worker 只是一个并发单位，**下游耗时增加时池子的等效容量会下降** |
| 「接口变慢」和 502/504 什么关系？ | 池子满在 FPM 层是排队（不报错）；请求时间超过 `request_terminate_timeout` 会被 kill → 502；nginx 等后端超过 `fastcgi_read_timeout` → 504。详见 Q42 |
| 压测怎么复现「线上慢」？ | 固定并发 + 固定耗时/固定资源占用，逐档放大并发看 P50/P99 的拐点在哪（本次的阶梯测试就是最小版本）；线上复现优先用流量回放而不是拍脑袋造并发 |

---

## Q45. CPU 使用率很高、或内存持续增长，分别怎么定位？

### 结论

两条链路的终点都是「哪一行代码 / 哪一次请求把资源吃掉了」，但**中途的判定逻辑完全相反**：

- **CPU 高**：先找「谁在烧」，**用增量不用累计值**。链条是 容器 → 进程 → 在算还是在等 → 函数 → 行号。实测从 `docker stats` 到 `q45-excimer.php:24` 全链路可在 1 分钟内走完。
- **内存持续增长**：先分清**三种曲线**，因为只有一种是真泄漏：
  - **高水位后走平**（分配器不还内存）→ 不是泄漏，实测 FPM worker RSS 31.7MB → 71.7MB 后稳定；
  - **锯齿**（`pm.max_requests` 到期重建）→ 不是修复，是止损，实测 28.1MB ↔ 67.1MB；
  - **单调上升**（真泄漏）→ 才要去找代码，实测循环引用 + `gc_disable()` 每轮 +17.33MB。

**最关键的一条**：在 FPM 里「函数内 static / 全局变量跨请求累积」是**行不通的**——实测 `static_n` 恒为 1、RSS 完全不动（每个请求结束都被清掉）。真泄漏只发生在**常驻进程**（CLI、队列 worker、Swoole）里。

### 一、两条链路

**CPU：**

```
机器 CPU 高（告警：load / CPU 使用率）
  │  docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}'
  ▼
容器 learn-php  115.08%          ← 粒度是容器：里面还有 cron/nginx/队列/多个接口
  │  top -bn1 -o %CPU             ← 宿主上所有容器的进程混在一个 pid 空间里
  ▼
宿主 pid 229286  php ...          ← top 看不出它属于哪个容器
  │  sed 's/.*docker-\(.\{12\}\).*/\1/' /proc/229286/cgroup
  ▼
容器 id b37e772507fc → docker ps --no-trunc | grep b37e772507fc → learn-php
  │  容器内遍历 /proc/*/cmdline    ← pid namespace：同一个进程，宿主和容器里两个编号
  ▼
容器内 pid 40269
  │  两次采样 /proc/<pid>/stat 的 utime/stime（累计值，必须取增量）
  ▼
Δutime=311 jiffies / 3s ≈ 104%    ← 真在烧 CPU
  （对照：等待中的 worker Δ=0、State=S，累计 1932 jiffies 一个都不涨）
  │  excimer / php-spx 采样 profiler（1ms 一次）
  ▼
hot_json 39.4%（第 48 行）/ hot_sort 35.5%（第 37 行）/ hot_regex 25.1%（第 24 行）
```

**内存：三种曲线，只有第三种要改代码**

```
VmRSS
  ▲
  │                    ┌──────────────┐   ① 高水位后走平：不是泄漏
  │               ┌────┘              │      「偶尔来一个重请求」把分配器的
  │          ┌────┘                   │      chunk 顶上去，之后请求复用这块内存
  │     ┌────┘                        │      实测 FPM worker 31.7 → 71.7 MB 后稳定
  │ ┌───┘                             │
  │─┘                                 │
  └───────────────────────────────────┴──────────► 请求数

  ▲
  │   ╱╲    ╱╲    ╱╲    ╱╲             ② 锯齿：pm.max_requests 止损，不是修复
  │  ╱  ╲  ╱  ╲  ╱  ╲  ╱  ╲               实测 max_requests=5：
  │ ╱    ╲╱    ╲╱    ╲╱    ╲              pid 每 5 个请求换一次，28.1 ↔ 67.1 MB
  └───────────────────────────────────────► 请求数

  ▲
  │                              ╱      ③ 单调上升：真泄漏
  │                          ╱            实测「循环引用 + gc_disable()」：
  │                      ╱                每轮（2 万次循环）稳定 +17.33 MB
  │                  ╱                    → 常驻进程只能重启/修代码
  │              ╱
  └─────────────────────────────────────► 轮数
```

### 二、实测（PHP 8.4.25）

#### A. CPU 高：从容器到行号，四步

脚本：`bench/ops/q45-cpu-burn.php`（烧 CPU 的接口/脚本）、`bench/ops/q45-cpu-locate.sh`（宿主侧四步链路）、`bench/ops/q45-proc-top.sh`（容器里的 top，本环境没有 `ps`）、`bench/ops/q45-cpu-calib.php`（记账校准）、`bench/ops/q45-excimer.php`（采样 profiler）。

**第 1 步，容器级：**

```
learn-php	115.08%	257.5MiB / 15.55GiB
musing_kapitsa	95.47%	11.15MiB / 15.55GiB
learn-redis	10.77%	6.93MiB / 15.55GiB
learn-mysql	7.62%	956.7MiB / 15.55GiB
```

`docker stats` 直接给出容器级 CPU，但**一个容器里通常同时跑着 nginx、cron、队列 worker、若干接口**，所以它只能回答「是哪个容器」。

**第 2 步，进程级（宿主 top）：**

```
    PID USER      PR  NI    VIRT    RES    SHR S  %CPU  %MEM     TIME+ COMMAND
 214888 999       20   0 2975616 591980  33196 S 116.7   3.6   0:27.29 mysqld
 229584 root      20   0 3109852  61068  17368 S 108.3   0.4   0:00.70 beam.smp
 229449 root      20   0  211956  51852  41744 R  83.3   0.3   0:01.83 php
 229286 root      20   0  250856  63068  48604 R  75.0   0.4   0:06.48 php
```

**注意 `top` 看不到容器归属**——所有容器的进程混在同一个宿主 pid 空间里，两个 `php` 分属不同容器也看不出来，只能靠 `COMMAND` 猜。

**第 3 步，宿主 pid → 容器：**

```
$ pgrep -f "q45-cpu-burn.php"
229286
$ cat /proc/229286/cgroup
0::/system.slice/docker-b37e772507fc3de72e319c0c16982c30eb646c277f11380c72116d681ca3d20d.scope
$ sed 's/.*docker-\(.\{12\}\).*/\1/' /proc/229286/cgroup
b37e772507fc
$ docker ps --no-trunc | grep b37e772507fc
b37e772507fc...  learn-php  docker.m.daocloud.io/webd...
```

这一步是「容器里没有 `ps`、`docker top` 报 `exec: "ps": executable file not found`」时的标准替代方案：**`/proc/<pid>/cgroup` 里那串 `docker-<64位 id>` 就是容器身份**。

**第 4 步，容器内的 pid 是另一套编号：**

```
$ docker exec learn-php sh -c '遍历 /proc/*/cmdline 找那个 burner'
40269 -> php /app/bench/ops/q45-cpu-burn.php 40
```

宿主上是 229286，容器里是 40269。**pid namespace 隔离，两套编号，靠 cmdline 对上**——这就是为什么「在宿主上 kill 容器内的进程」必须用宿主的 pid。

**容器内没有 `ps`，自己写一个「top」**：对 `/proc/*/stat` 采两次样求差（`utime`/`stime` 在 stat 的第 14/15 字段，`pid (comm)` 前缀要先砍掉；单位是 jiffies，`getconf CLK_TCK` = 100，即 1 jiffy = 10ms）：

```
PID      State  Δutime  Δstime    CPU% Δvolctx Δnonvol  CMD
41406        R      311        2  104.2%        0      381  php-fpm: pool www
41318        S       71       16   29.0%     1342      521  php /app/bench/mysql/07-btree.php
41407        S        1        3    1.3%      189       20  sh /app/bench/ops/q45-proc-top.sh
```

`State=R` + `Δutime` 大 = 真在烧；`Δnonvoluntary` 涨 = 真的在被调度器抢占（是在跑的）；`Δvoluntary` 涨 = 主动让出（等 IO/锁/sleep）。

**同一时刻的对照组（拿 FPM 状态页找出正在跑慢请求的 worker）——这张对照是本节最值钱的证据：**

```
worker pid = 41406  正在跑烧 CPU 的请求
worker pid = 40860  正在跑 down= 的请求
  State            = S (sleeping)
  第一次 utime/stime = 1932 13
  3s 后            = 1932 13   ← 一动不动
  CPU%（精确夹这个 pid）= 0.0%
  Δnonvoluntary    = 0
```

注意 `40860` 的**累计** CPU 时间是 1932 jiffies（19.32 秒 CPU）——它是个「烧过 CPU 的老 worker」。**累计值大 ≠ 现在在烧**：判定必须看增量。

**口径校准（很重要，见「三、2」）**：`bench/ops/q45-cpu-calib.php` 让进程自报 `/proc/self/stat`：

| 对象 | 窗口 | Δutime+Δstime | 比值 |
| --- | ---: | ---: | ---: |
| CLI 单线程纯计算 | 6.005 s | 598 + 2 jiffies | **100.0%** |
| FPM worker 烧 CPU | 4.031 s（精确夹时间戳）| 374 jiffies | **92.8%** |

结论：**一个 worker 最多烧满 1 个核**，单进程 CPU% 到 100% 就是满了；这台 WSL2 机器上的 jiffy 记账本身是准的（单线程纯计算正好 1.00）。

**第 5 步，函数与行号：采样 profiler（excimer 1.2.6，1ms 采样一次，开销约 1%）**

`bench/ops/q45-excimer.php` 把三种「看起来都很正常」的热点混在一起跑：

```
总耗时 = 1.608 s，采样周期 1 ms，实际抓到 1608 个样本

函数         定义位置                  self  占比  inclusive
hot_json      q45-excimer.php:48             633   39.4%        633
hot_sort      q45-excimer.php:37             571   35.5%        571
hot_regex     q45-excimer.php:24             404   25.1%        404
cold_helper   （没有出现）                      0    0.0%          0

hot_regex 里被采样到的行号（样本最多的那几行就是要改的地方）：
  q45-excimer.php:24               347 样本      ← 循环里的 preg_match
  q45-excimer.php:25                17 样本
```

三个热点分别是「循环里做正则」「循环里排序」「循环里 json 编解码」——**都是真实生产里最常见的 CPU 黑洞，而且单看代码一眼看不出问题**。`cold_helper()` 一个样本都没抓到，说明 1% 开销的采样不会污染结论，可以短暂开在生产上。

同一份 profile 走 FPM 再跑一次，结果一致（`hot_json 44.8% / hot_sort 31.3% / hot_regex 23.8%`，1405 个样本），并且能在容器内的 top 里同时看到那个 worker 在烧（`Δutime=157 / 2s`）。

**一个细节**：CLI 跑报 `hot_json` 在第 **48** 行，走 FPM 跑报第 **49** 行。这一列是**采样落点所在的行**（相邻两句 `json_encode` / `json_decode`，样本会在两行之间分配），不是函数定义行——别拿它当「定义位置」用，它回答的是「这个函数里最费的是哪一句」。想看定义位置就 `ReflectionFunction`。

#### B. 内存持续增长：五种曲线 + FPM 里到底能不能靠 static 泄漏

脚本：`bench/ops/q45-mem-leak.php` + `q45-mem-leak.sh`（CLI，一个 case 一个独立进程）、`bench/ops/q45-leak-web.php` + `q45-fpm-rss.sh`（FPM worker 的 RSS 曲线，池子锁成 `static` + `max_children=1` 保证落在同一个 worker 上）。

**CLI 侧：五种成因/对照，8 轮 × 2 万次迭代**

| case | 第 1 轮 ΔVmRSS | 第 8 轮 ΔVmRSS | 曲线 |
| --- | ---: | ---: | --- |
| 循环引用 + `gc_disable()` | 17,928,192 (17.9MB) | **139,227,136 (139.2MB)** | 单调上升，**每轮约 +17.33MB** |
| 全局数组只增不减 | 10,022,912 | **78,708,736 (78.7MB)** | 单调上升 |
| 函数内 `static` 数组 | 2,494,464 | **18,411,520 (18.4MB)** | 单调上升（CLI 里 static 是同一个进程） |
| 对照：同样的循环引用，GC 开着 | 4,542,464 | 4,542,464 | **完全平**（GC 跑了 32 轮、回收 320000 个根）|
| 对照：分配 1MB 后释放 | 1,277,952 | 1,277,952 | **完全平** |

`gc_disable()` 那个 case 每轮泄漏 2 万个循环引用 ≈ **每个循环引用对约 866 B**（2 个 `stdClass` + 2 个属性表）。手动回收一次：

```
手动 gc_collect_cycles() 后: VmRSS = 203,952,128（比基线高 139,227,136），GC 跑了 1 轮、回收 320000 个根
```

**GC 把 32 万个根全回收了，`VmRSS` 一个字节都没降**。这就是「内存持续增长」的第一大误判来源：`VmRSS` 不降不代表还在泄漏——Zend MM 从 OS 拿到的 chunk 不会还回去，释放的内存留在自己的空闲链表里。**判定泄漏要看「有没有继续涨」，而不是「有没有降下来」**。

**FPM 侧：三段实验的 RSS 曲线（每格 2MB，池子锁成 `static` + `max_children=1`）**

A. 重请求顶高水位，`pm.max_requests` 关闭（`?mode=peak` 分配 40MB 再释放）：

```
请求      pid    RSS(MB) 曲线（每格 2MB）
    1    33183       31.7  ###############
    2    33183       50.9  #########################
    3    33183       60.9  ##############################
    4    33183       66.9  #################################
    5    33183       68.9  ##################################
    6    33183       70.9  ###################################
    ...
   12    33183       71.7  ###################################
```

**同一条「偶尔来一个重请求」的业务路径，前 5 个请求把 RSS 从 31.7MB 顶到 71.7MB，然后就不动了**——不是泄漏，是分配器的高水位。

B. 同样的请求，`pm.max_requests = 5`：

```
请求      pid    RSS(MB) 曲线
    1    33452       31.6  ###############
    2    33452       50.8  #########################
    3    33638       28.1  ##############       ← 换 pid 了，RSS 被砍回基线
    4    33638       49.1  #########################
    ...
    7    33638       67.1  #####################################
    8    33639       28.1  ##############       ← 又换
```

**pid 每 5 个请求换一次，RSS 被砍回基线再爬回去**（第一代只服务了 2 个被记录的请求：脚本的就绪探针也占用 `max_requests` 的额度，这本身也是个小坑——探针/健康检查会真实消耗 worker 的请求配额）。`pm.max_requests` 的作用是**定期把内存高水位归零**，不是修好泄漏——所以它是止损手段，代价是反复 fork + 冷启动（Q4 已测 fork 本身约 1ms，真正的代价在 autoload / 连接 / 本地缓存重建）。

C. FPM 里用「函数内 static 数组」泄漏（每次追加 4MB）：

```
请求      pid    RSS(MB) static_n
    1    34158       32.3        1
    ...
   12    34158       32.3        1
```

`static_n` **恒为 1**、RSS 完全不动：**FPM 每个请求结束时会把 static / 全局变量全部清掉**。所以「代码看起来每次请求都是新的」不是错觉——在 FPM 里这确实不泄漏；但把同一份代码搬进 CLI 队列 worker / Swoole 常驻进程，上面 CLI 表格里 `static` 那一行（8 轮 +18.4MB）就是它的真实曲线。**这也是「同一段代码在 FPM 里没事、上了常驻进程就 OOM」的原因**。

### 三、六个反直觉的点

**1. `/proc/<pid>/stat` 的 `utime` 是累计值，判定必须取增量。** 实测那个「卡在下游」的 worker 累计烧过 1932 jiffies（19.32 秒 CPU），但窗口内增量是 0。用累计值排序会得出「它在烧 CPU」的相反结论——**线上看 `top` 的 `TIME+` 列也有同样的问题**（本环境实测那台机器上 `mysqld` 的 `TIME+` 是 23 分钟，但它此刻的 CPU 只有 58%）。

**2. 两次采样求差的「窗口」不是你写的那个秒数。** `sleep 2` 只是窗口的一部分：**快照自己也要遍历上百个 `/proc` 并多次 fork，实测一次采样耗时 2.7~3.9 秒**，而且进程列表一直在变（短命进程生灭会让同一个 pid 在两次快照里的位置前后漂移），单 pid 的真实窗口会漂 ±1 秒。**所以这张表的 CPU% 只能用来排序，不能当绝对值**——实测出现 `104.2%`、`115.5%`、`116.8%` 这种「单进程超过一个核」的数字，全是窗口误差，不是多线程（同一个 worker 精确夹时间戳测出来是 92.8%）。要绝对值就**只夹一个 pid、自己打纳秒时间戳**。

**3. 一个 FPM worker 顶多烧满 1 个核，但这个核经常被误当成「整个容器」的瓶颈。** 实测 CLI 单线程 100.0%、FPM worker 92.8%。真正的含义是：**并发能力 = worker 数 × 每 worker 1 核**，而容器看到 400% 是 4 个 worker 各自烧满，不是某个 worker 用了 4 核。反过来，「只有 8 核的机器上 PHP 烧到 800%」这种告警，对应的就是 8 个 worker 在跑同一段热点代码。

**4. `CAP_SYS_PTRACE` 缺失会让一整类工具静默失效。** 本环境实测：`/proc/<pid>/wchan` 读出恒为 `0`、`/proc/<pid>/syscall` 报 `Operation not permitted`、`/proc/<pid>/io` 读不到，**FPM 的 `request_slowlog_timeout` 只有在 error log 留一行 `failed to ptrace(ATTACH) ... Operation not permitted (1)`**（Q44 有完整证据）。这三个工具是「看进程在等什么」的常用手段，容器里**必须先验证一次**再依赖它，否则排查时会得到「一切正常」的假象。

**5. `VmRSS` 涨 ≠ 泄漏，`VmRSS` 不降 ≠ 还在泄漏。** 两个方向都要小心：FPM worker 从 31.7MB 涨到 71.7MB 后走平是**高水位**；`gc_collect_cycles()` 回收 32 万个根之后 RSS 原地不动是**分配器不还内存**。判定标准只有一条：**在相同的负载下，曲线还在不在涨**。所以生产上监控内存要用「同一类请求跑够 N 次后的稳态值」，而不是「绝对阈值」。

**6. 在 FPM 里用 `static`/全局变量做缓存，不会泄漏——但会误导你。** 实测 `static_n` 恒为 1、RSS 不动。这条有两个后果：① 想复现「内存持续增长」必须用 CLI/常驻进程，在 FPM 里复现不出来；② 反过来，**在 FPM 里有效的「static 缓存」一旦搬进 Swoole/队列 worker 就成了泄漏源**（同一份代码，CLI 侧 8 轮 +18.4MB）。这也是「常驻内存框架要重写所有全局状态」的根本原因。

### 四、实战结论

**CPU 高的定位顺序：**

| 步骤 | 看什么指标 | 命令 |
| --- | --- | --- |
| 1. 哪个容器 | 容器 CPU% | `docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}'` |
| 2. 哪个进程（宿主） | `%CPU`、`COMMAND` | `top -bn1 -o %CPU \| head -20`；`pgrep -f <关键字>` |
| 3. 属于哪个容器 | `docker-<id>` | `sed 's/.*docker-\(.\{12\}\).*/\1/' /proc/<pid>/cgroup` + `docker ps --no-trunc` |
| 4. 容器内定位 | `State`、`Δutime/Δstime`、`Δvoluntary/nonvoluntary` | `docker exec learn-php sh /app/bench/ops/q45-proc-top.sh 3` |
| 5. 在算还是在等 | `State=S` + Δutime≈0 = 在等（不是 CPU 问题） | 同上；对照法：只夹一个 pid 打时间戳 |
| 6. 哪个函数 / 哪一行 | self 占比、file:line | `docker exec learn-php php /app/bench/ops/q45-excimer.php`（或 php-spx / xhprof） |

**内存持续增长的定位顺序：**

| 步骤 | 看什么指标 | 命令 / 判据 |
| --- | --- | --- |
| 1. 是哪个进程在涨 | 逐 pid 的 `VmRSS` | `grep VmRSS /proc/<pid>/status`；FPM 用状态页 `?full` + 逐 worker 读 |
| 2. 曲线形态 | 高水位 / 锯齿 / 单调 | 同一负载跑 N 次看稳态值（本仓库脚本 `q45-fpm-rss.sh`）|
| 3. 是 FPM 还是常驻进程 | 请求结束后 RSS 会不会回落 | FPM 每个请求清 static/全局（实测）；常驻进程不会 |
| 4. 真泄漏的成因 | `gc_status()['roots']`、对象数、`memory_get_usage` 曲线 | 循环引用 + `gc_disable()`（本环境实测每轮 +17.33MB）；全局/static 数组只增不减 |
| 5. 止损 | — | `pm.max_requests = 500~1000`（实测锯齿）；常驻进程定时重启 + 分批处理（批次间 `gc_collect_cycles()`）|
| 6. 根治 | — | 找到只增不减的容器（数组/缓存/连接/闭包引用），加容量上限（LRU）或改成流式处理 |

**CPU 高 vs 内存在涨：一句话对照**

| 现象 | 大概率原因 | 立刻做 |
| --- | --- | --- |
| CPU 100% × N 个 worker，请求量没涨 | 热点代码上量了（循环里正则/排序/序列化）或死循环 | 上采样 profiler，看 self 前 3 的函数 |
| CPU 高但 QPS 很低 | 单请求计算量爆炸（大数组、回溯、加解密） | 看入参规模与耗时的关系（实测 0.94ms/行）|
| RSS 阶梯后走平 | 分配器高水位 | 不用动；把「稳态值」写进容量估算 |
| RSS 单调上升且不回落 | 真泄漏（常驻进程 / 全局只增不减） | 先 `max_requests`/重启止损，再按上表定位 |
| RSS 涨 + CPU 也涨 | 通常是「数据量变大→都变慢」 | 先看是不是本来该分批的任务变成了一次性全量 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 容器里没有 `ps`/`top`/`strace`，还能做什么？ | 全靠 `/proc`：`stat`(utime/stime/state)、`status`(State/VmRSS/Threads/上下文切换)、`cmdline`(认进程)、`cgroup`(认容器)、`smaps_rollup`(Private_Dirty/Pss)。本仓库的 `q45-proc-top.sh`、`q45-cpu-locate.sh` 就是完整的替代实现 |
| `wchan` 读出 0、`syscall` 读不到怎么办？ | 说明缺 `CAP_SYS_PTRACE`（本环境实测）。替代：`State` + `utime` 增量区分「在算/在等」，`voluntary_ctxt_switches` 增量区分「主动让出（IO/锁/sleep）/被抢占」。要么给容器加 `SYS_PTRACE`，要么接受这层信息缺失 |
| 为什么 `pm.max_requests` 能「解决」内存问题？ | 它不解决，它是**止损**：worker 处理够 N 个请求就自杀重建，把高水位归零（实测锯齿 28.1↔67.1MB）。代价是 fork + 冷启动，且**泄漏速度超过重建速度时仍然会 OOM** |
| `memory_limit` 和容器内存限制的关系？ | `memory_limit` 只约束 Zend MM 的分配，**看不到 PHP 二进制、扩展、opcache 共享段、mmap**——所以「`memory_limit=128M` 但 worker 的 `VmRSS` 是 300MB」是正常的。做容量估算用 `Private_Dirty`（详见 Q4）|
| OPcache / JIT 会不会让 CPU 变高？ | 本环境**未实测**。原理上 JIT 首次编译有开销、常驻后应该降低 CPU；但 JIT 的 trace 退出（side exit）在分支极多的代码里可能反而不如纯解释执行。要下结论必须在自己的代码上 A/B |
| FPM 的 `request_slowlog_timeout` 生产上能用吗？ | 能用，但**必须验证**：它依赖 `ptrace`，容器默认没有 `CAP_SYS_PTRACE`（本环境实测静默失败）。能开就开，它给的是完整调用栈；不能开就用采样 profiler（excimer/php-spx），两者的取舍是「全栈 vs 占比」|
| Swoole / 常驻框架的内存怎么防？ | ① 一切全局/`static` 状态都要有上限（LRU/定期清）；② 每个请求/任务结束 `gc_collect_cycles()`；③ 监控 `memory_get_usage` 的稳态值并设置重启阈值；④ 用 `max_request` 类似机制定期重建 worker。**注意：本环境未实测 Swoole 场景**，上面的 FPM/CLI 实测数据不能直接当 Swoole 的数字用 |
| 内存泄漏能不能用 `valgrind` / `gdb`？ | 能用但要谨慎：`valgrind` 会带来 10 倍以上开销，只能在预发环境按请求数采样跑；容器里同样受 `CAP_SYS_PTRACE` 限制。日常定位用「曲线 + 二分代码路径」更快 |
| 容器被 OOM Kill 怎么确认？ | 看 `dmesg` / cgroup 的 `memory.events` / `docker inspect` 的 `OOMKilled`，以及容器内 `/sys/fs/cgroup/memory.peak`。**本环境未实测**（没制造 OOM），只列了方向 |
| 为什么「接口变慢」那题要放到这里一起问？ | CPU/内存是「机器视角」，接口变慢是「用户视角」，同一个故障两个视角的**第一步动作不同**：机器视角先找进程（`docker stats`/`top`），用户视角先找接口（access log 按 URI 聚合）。面试里能把两个视角串起来（接口 → 容器 → 进程 → 行号，或者反过来）就是加分的 |
