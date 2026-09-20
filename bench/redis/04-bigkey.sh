#!/bin/sh
# Q31 大 Key / 热 Key 的发现与处理
# 用法: docker exec -i learn-redis sh /app/bench/redis/04-bigkey.sh
#
# 全部用 q31: 前缀，不用 FLUSHALL —— 这台 Redis 上还有别的会话在用。

R="redis-cli"
P=q31

clean() { $R --scan --pattern "$P:*" 2>/dev/null | tr -d '\r' | xargs -r $R DEL >/dev/null 2>&1; }

T0=$(date +%s)
mark() { printf "\n-------- [%ds] %s --------\n" $(( $(date +%s) - T0 )) "$1"; }

echo "===== 版本 ====="
$R INFO server | grep -E 'redis_version|os:' | tr -d '\r'

# ---------------------------------------------------------------- 1
clean
echo
mark "1. 造大 Key"
head -c 5242880 /dev/zero | tr '\0' 'x' | $R -x SET "$P:str5mb" >/dev/null
seq 1 500000  | awk -v p="$P" '{print "RPUSH "p":list500k v"$1}' | $R --pipe >/dev/null
seq 1 300000  | awk -v p="$P" '{print "HSET "p":hash300k f"$1" v"$1}' | $R --pipe >/dev/null
seq 1 300000  | awk -v p="$P" '{print "SADD "p":set300k v"$1}' | $R --pipe >/dev/null
seq 1 300000  | awk -v p="$P" '{print "ZADD "p":zset300k "$1" v"$1}' | $R --pipe >/dev/null

printf "  %-16s %14s %12s %-8s %s\n" 'key' 'MEMORY USAGE' '元素数' '编码' '类型'
printf "  %-16s %14s %12s %-8s %s\n" "$P:str5mb"   "$($R MEMORY USAGE "$P:str5mb")"   "$($R STRLEN "$P:str5mb")" "-" "$($R TYPE "$P:str5mb")"
printf "  %-16s %14s %12s %-8s %s\n" "$P:list500k" "$($R MEMORY USAGE "$P:list500k")" "$($R LLEN "$P:list500k")" "$($R OBJECT ENCODING "$P:list500k")" list
printf "  %-16s %14s %12s %-8s %s\n" "$P:hash300k" "$($R MEMORY USAGE "$P:hash300k")" "$($R HLEN "$P:hash300k")" "$($R OBJECT ENCODING "$P:hash300k")" hash
printf "  %-16s %14s %12s %-8s %s\n" "$P:set300k"  "$($R MEMORY USAGE "$P:set300k")"  "$($R SCARD "$P:set300k")" "$($R OBJECT ENCODING "$P:set300k")" set
printf "  %-16s %14s %12s %-8s %s\n" "$P:zset300k" "$($R MEMORY USAGE "$P:zset300k")" "$($R ZCARD "$P:zset300k")" "$($R OBJECT ENCODING "$P:zset300k")" zset

echo
echo "  --- redis-cli --bigkeys（采样扫描，线上可用；只统计元素最多的 key）---"
$R --bigkeys 2>&1 | tr -d '\r' | grep -vE '^$' | sed 's/^/  /'

echo
echo "  --- 单元素内存：大 Key 不是「元素多」而是「整体太大」---"
awk -v a="$($R MEMORY USAGE "$P:list500k")" -v b="$($R MEMORY USAGE "$P:hash300k")" \
    -v c="$($R MEMORY USAGE "$P:set300k")" -v d="$($R MEMORY USAGE "$P:zset300k")" \
  'BEGIN{printf "    list  %.1f B/元素   hash %.1f B/字段   set %.1f B/成员   zset %.1f B/成员\n", a/500000, b/300000, c/300000, d/300000}'

# ---------------------------------------------------------------- 2
echo
mark "2. DEL vs UNLINK"
# 不要用「date +%s%N 包住 redis-cli」来量耗时：redis-cli 自己启动就要 4~5ms，
# 会把真正的服务端耗时整个盖掉（上一版就是这么翻车的，DEL 和 UNLINK 量出来一样）。
# 改读 SLOWLOG —— 那是 Redis 自己记的「命令在服务端执行了多少微秒」。
# 再顺手看 INFO memory 的 lazyfree_pending_objects：UNLINK 把释放甩给后台线程，
# 这个数会先变成 1，等后台线程干完才回 0。
$R CONFIG SET slowlog-log-slower-than 1000 >/dev/null
printf "  %-10s %-8s %15s %16s %14s %s\n" '元素数' '命令' '客户端看到耗时' '服务端 usec' 'lazyfree 残留' 'victim 大小'
for n in 200000 1000000 5000000; do
    for cmd in DEL UNLINK; do
        clean
        seq 1 $n | awk -v p="$P" '{print "RPUSH "p":victim v"$1}' | $R --pipe >/dev/null
        sz=$($R MEMORY USAGE "$P:victim")
        $R SLOWLOG RESET >/dev/null
        t0=$(date +%s%N); $R $cmd "$P:victim" >/dev/null; t1=$(date +%s%N)
        u=$($R SLOWLOG GET 1 | tr -d '\r' | sed -n 3p | tr -dc '0-9')
        [ -z "$u" ] && u='<1000(未达阈值)'
        pend=$($R INFO memory | tr -d '\r' | awk -F: '/lazyfree_pending_objects/{print $2}')
        printf "  %-10s %-8s %12d ms %16s %14s %s B\n" "$n" "$cmd" $(( (t1-t0)/1000000 )) "$u" "$pend" "$sz"
        # 等后台线程释放完，免得下一轮 clean 时基线不干净
        for i in 1 2 3 4 5 6 7 8 9 10; do
            [ "$($R INFO memory | tr -d '\r' | awk -F: '/lazyfree_pending_objects/{print $2}')" = "0" ] && break
            sleep 0.2
        done
    done
done
$R CONFIG SET slowlog-log-slower-than 10000 >/dev/null 2>&1
echo "  ↑ 两者都是 O(N) 释放，区别只在「在哪个线程释放」：DEL 在主线程，UNLINK 交给后台线程"
echo "    客户端看到的那 5ms 里有 4~5ms 是 redis-cli 自己的进程启动，看「服务端 usec」那一列才是真的"

# ---------------------------------------------------------------- 3
echo
mark "3. 带宽成本"
clean
head -c 1048576 /dev/zero | tr '\0' 'x' | $R -x SET "$P:one_mb" >/dev/null
$R SET "$P:tiny" hello >/dev/null
printf "  %-14s %12s %10s %s\n" 'key' '值大小(B)' 'GET 耗时' '说明'
for k in "$P:tiny" "$P:one_mb"; do
    $R GET "$k" >/dev/null                # 预热连接
    t0=$(date +%s%N); $R GET "$k" >/dev/null; t1=$(date +%s%N)
    printf "  %-14s %12s %8d ms\n" "$k" "$($R STRLEN "$k")" $(( (t1-t0)/1000000 ))
done
echo "  ↑ 1MB 的 value，一次 GET 就要把它整个搬过网络（这里还只是本机 loopback，没算真实网卡）"
echo "    1000 QPS 命中这个 key = 1 GB/s 出口带宽，绝大多数业务机器的网卡先于 Redis 崩"

# ---------------------------------------------------------------- 4
echo
mark "4. 热 Key"
clean
$R CONFIG SET maxmemory-policy allkeys-lfu >/dev/null
seq 1 2000 | awk -v p="$P" '{print "SET "p":key:"$1" v"}' | $R --pipe >/dev/null
printf "  %-10s %-28s %s\n" 'lfu-log-factor' 'key（刚 SET，还没人读）' "freq=$($R OBJECT FREQ "$P:key:1" | tr -d '\r')"
for f in 10 1; do
    $R CONFIG SET lfu-log-factor $f >/dev/null
    $R DEL "$P:hot$f" >/dev/null
    $R SET "$P:hot$f" v >/dev/null
    $R -r 200 GET "$P:hot$f" >/dev/null      # 连续读 200 次，一次连接
    printf "  %-10s %-28s freq=%s\n" "$f" "读 200 次的 key" "$($R OBJECT FREQ "$P:hot$f" | tr -d '\r')"
done
echo "  ↑ 同样读 200 次，lfu-log-factor 不同读出来的 freq 完全不同 —— freq 只能用来排序，不能当访问量"
echo "  对比：整批 key 各被读 1 次，freq=$($R OBJECT FREQ "$P:key:7" | tr -d '\r')"

echo
echo "  --- redis-cli --hotkeys ---"
$R --hotkeys 2>&1 | tr -d '\r' | grep -vE '^$' | tail -20 | sed 's/^/  /'

$R CONFIG SET lfu-log-factor 10 >/dev/null 2>&1
$R CONFIG SET maxmemory-policy noeviction >/dev/null

# ---------------------------------------------------------------- 5
echo
mark "5. 清理"
clean
$R CONFIG SET maxmemory-policy noeviction >/dev/null
$R CONFIG SET lfu-log-factor 10 >/dev/null
echo "  maxmemory-policy=$($R CONFIG GET maxmemory-policy | tail -1)  lfu-log-factor=$($R CONFIG GET lfu-log-factor | tail -1)"
echo "  剩余 key 数=$($R DBSIZE)"
