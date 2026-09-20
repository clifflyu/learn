#!/bin/sh
# Q31 大 Key / 热 Key：怎么发现、怎么量、代价在哪
# 用法: docker exec -i learn-redis sh /app/bench/redis/04-bigkey.sh
#
# 全部用 q31: 前缀，不用 FLUSHALL —— 这台 Redis 上还有别的会话在用。
#
# 「删一个大 Key 要多久、会把别人堵多久」不在这里，在另外两个 PHP 脚本里：
#   q31-delcost.php   按数据结构拆开量删除成本（SLOWLOG + 持久连接双线印证）
#   q31-block.php     一个独立进程紧循环 PING，量旁人被堵出来的延迟尖峰
# 这两个必须用持久连接：redis-cli 每次 fork+exec 固定 4~5ms，会把真正的耗时全盖掉。

R="redis-cli"
P=q31

clean() { $R --scan --pattern "$P:*" 2>/dev/null | tr -d '\r' | xargs -r $R DEL >/dev/null 2>&1; }
# 默认 MEMORY USAGE 只采样 5 个元素再外推，对 skiplist 这种每节点大小不一的会估歪：
# 同一个 300k 成员的 zset，默认采样连跑 3 次都是 28,852,256（一模一样 —— 它总是取开头
# 几个元素外推，所以误差是系统性偏差，不会靠多跑几次消掉），而 SAMPLES 0 连建 4 次是
# 31,242,464 ~ 31,251,176（差 0.028%）—— 采样版稳定偏低 7.7%。
MU() { $R MEMORY USAGE "$1" SAMPLES 0 | tr -d '\r'; }

T0=$(date +%s)
mark() { printf "\n-------- [%ds] %s --------\n" $(( $(date +%s) - T0 )) "$1"; }

echo "===== 版本 ====="
$R INFO server | grep -E 'redis_version|os:' | tr -d '\r'

# ---------------------------------------------------------------- 1
clean
mark "1. 造几个典型的大 Key，量它们有多大"
head -c 5242880 /dev/zero | tr '\0' 'x' | $R -x SET "$P:str5mb" >/dev/null
seq 1 500000  | awk -v p="$P" '{print "RPUSH "p":list500k v"$1}' | $R --pipe >/dev/null
seq 1 300000  | awk -v p="$P" '{print "HSET "p":hash300k f"$1" v"$1}' | $R --pipe >/dev/null
seq 1 300000  | awk -v p="$P" '{print "SADD "p":set300k v"$1}' | $R --pipe >/dev/null
seq 1 300000  | awk -v p="$P" '{print "ZADD "p":zset300k "$1" v"$1}' | $R --pipe >/dev/null

printf "  %-16s %14s %12s %-10s %-7s %s\n" 'key' 'MEMORY USAGE' 'STRLEN/元素' '编码' '类型' 'B/元素'
printf "  %-16s %14s %12s %-10s %-7s %s\n" "$P:str5mb" "$(MU "$P:str5mb")" "$($R STRLEN "$P:str5mb")" '-' 'string' '-'
for spec in "list500k:list:LLEN:500000" "hash300k:hash:HLEN:300000" "set300k:set:SCARD:300000" "zset300k:zset:ZCARD:300000"; do
    k=${spec%%:*}; rest=${spec#*:}; ty=${rest%%:*}; rest=${rest#*:}; cntcmd=${rest%%:*}
    m=$(MU "$P:$k"); n=$($R "$cntcmd" "$P:$k")
    awk -v k="$P:$k" -v m="$m" -v n="$n" -v e="$($R OBJECT ENCODING "$P:$k")" -v t="$ty" \
        'BEGIN{printf "  %-16s %14d %12d %-10s %-7s %.1f\n", k, m, n, e, t, m/n}'
done

echo
echo "  --- redis-cli --bigkeys（采样扫描，线上可用；只统计元素最多的 key）---"
$R --bigkeys 2>&1 | tr -d '\r' | grep -E '^\[|^Biggest|^Sampled|^Total|with [0-9]+ (bytes|items|fields|members)|SUMMARY|--------' | sed 's/^/  /'

echo
echo "  --- 5 MB 的 string：MEMORY USAGE 比 STRLEN 大 20% ---"
awk -v m="$(MU "$P:str5mb")" -v s="$($R STRLEN "$P:str5mb")" \
    'BEGIN{printf "    STRLEN=%d B，MEMORY USAGE=%d B，放大 %.2f 倍\n", s, m, m/s;
           printf "    差在 sds 头 + robj + jemalloc 的 size class：请求 5MB+17B，落到 6MB 那一档\n"}'

# ---------------------------------------------------------------- 2
mark "2. 取一个大 Key 的带宽成本"
clean
head -c 1048576 /dev/zero | tr '\0' 'x' | $R -x SET "$P:one_mb" >/dev/null
$R SET "$P:tiny" hello >/dev/null
REP=200
printf "  %-14s %12s %14s %16s\n" 'key' '值大小(B)' '单次 GET(ms)' '往返带宽(MB/s)'
for k in "$P:tiny" "$P:one_mb"; do
    $R GET "$k" >/dev/null                                     # 预热
    t0=$(date +%s%N)
    $R -r $REP GET "$k" >/dev/null                             # 一次进程内重复 REP 次，摊掉启动开销
    t1=$(date +%s%N)
    sz=$($R STRLEN "$k")
    awk -v k="$k" -v sz="$sz" -v rep="$REP" -v ns="$((t1-t0))" 'BEGIN{
        ms = ns/1000000/rep;
        printf "  %-14s %12d %14.4f %16.1f\n", k, sz, ms, (sz/1048576)/(ms/1000)}'
done
echo "  ↑ 差值就是真实的搬运成本。1MB 的 value，一次 GET 要把它整个搬过网络。"
echo "    1000 QPS 命中这个 key = 1 GB/s 出口带宽，绝大多数业务机器的网卡先于 Redis 崩。"

# ---------------------------------------------------------------- 3
mark "3. 热 Key：OBJECT FREQ 是「对数计数」不是「访问次数」"
clean
$R CONFIG SET maxmemory-policy allkeys-lfu >/dev/null
seq 1 2000 | awk -v p="$P" '{print "SET "p":key:"$1" v"}' | $R --pipe >/dev/null
printf "  %-16s %-30s %s\n" 'lfu-log-factor' 'key' 'OBJECT FREQ'
printf "  %-16s %-30s %s\n" '-' '刚 SET，一次都没读过' "$($R OBJECT FREQ "$P:key:1" | tr -d '\r')"
for f in 10 1; do
    $R CONFIG SET lfu-log-factor $f >/dev/null
    $R DEL "$P:hot$f" >/dev/null
    $R SET "$P:hot$f" v >/dev/null
    $R -r 200 GET "$P:hot$f" >/dev/null           # 连续读 200 次，一次连接
    printf "  %-16s %-30s %s\n" "$f" "读 200 次的同一个 key" "$($R OBJECT FREQ "$P:hot$f" | tr -d '\r')"
done
printf "  %-16s %-30s %s\n" '-' '整批 key 各被读 1 次' "$($R OBJECT FREQ "$P:key:7" | tr -d '\r')"
echo "  ↑ 同样读 200 次，lfu-log-factor 不同读出来的 freq 差一倍（见上表）。而且每次跑的数"
echo "    还会小幅抖动 —— 递增是概率性的（计数越高、越难再加一），不是「读一次加一」。"
echo "    freq 只能用来排大小，不能当访问量读；它还会随时间衰减（默认 lfu-decay-time 1 分钟）。"
echo "    另外「一次没读过」的 key freq=5 而不是 0，因为新 key 一律以 LFU_INIT_VAL(5) 起步，"
echo "    免得刚写进来还没被读就被淘汰。"

echo
echo "  --- redis-cli --hotkeys（同样要求 LFU 策略）---"
$R --hotkeys 2>&1 | tr -d '\r' | grep -E 'hot key found with counter|Sampled' | head -6 | sed 's/^/  /'
echo "  （只列前 6 条。freq=5 的那些就是「从没被读过」的普通 key，被一起列出来了 —— "
echo "    --hotkeys 没有绝对阈值，只有相对排序，得自己看计数分布判断谁是真热）"

$R CONFIG SET lfu-log-factor 10 >/dev/null 2>&1
$R CONFIG SET maxmemory-policy noeviction >/dev/null

# ---------------------------------------------------------------- 4
mark "4. 清理"
clean
$R CONFIG SET maxmemory-policy noeviction >/dev/null
$R CONFIG SET lfu-log-factor 10 >/dev/null
echo "  maxmemory-policy=$($R CONFIG GET maxmemory-policy | tail -1)  lfu-log-factor=$($R CONFIG GET lfu-log-factor | tail -1)"
echo "  剩余 key 数=$($R DBSIZE)"
