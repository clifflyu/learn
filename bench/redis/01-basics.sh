#!/bin/sh
# Q25 Redis 为什么快   Q26 数据结构与内存   Q31 大 Key
# 用法: docker exec learn-redis sh /app/bench/redis/01-basics.sh

R="redis-cli"
N=100000

mem() { $R INFO memory | grep '^used_memory:' | tr -d '\r' | cut -d: -f2; }
per() { awk -v a="$1" -v b="$2" -v n="$N" 'BEGIN{printf "%.1f", (b-a)/n}'; }
mb()  { awk -v a="$1" -v b="$2" 'BEGIN{printf "%.2f", (b-a)/1048576}'; }

echo "===== 版本 ====="
$R INFO server | grep -E 'redis_version|os:'

echo
echo "===== Q25: 单线程被慢命令阻塞 ====="
# DEBUG 命令默认被禁用，用一个跑 3 秒的 Lua 脚本代替
$R DEL blockdemo >/dev/null
($R EVAL "local t=redis.call('TIME')[1]+3; while tonumber(redis.call('TIME')[1])<t do end return 1" 0 >/dev/null 2>&1 &)
sleep 0.3
t0=$(date +%s%N)
$R PING >/dev/null
t1=$(date +%s%N)
awk -v a="$t0" -v b="$t1" 'BEGIN{printf "  一条 PING 等了 %.0f ms —— 被一个 3 秒的 Lua 脚本整个堵住\n", (b-a)/1000000}'
$R SCRIPT KILL >/dev/null 2>&1
wait 2>/dev/null

echo
echo "===== Q25: 吞吐（redis-benchmark）====="
for it in 1 4; do
    $R CONFIG SET io-threads $it >/dev/null
    printf "  io-threads=%s  " "$it"
    redis-benchmark -q -n 200000 -c 50 -t set,get 2>&1 | grep 'requests per second' | tr '\n' ' '
    echo
done
$R CONFIG SET io-threads 1 >/dev/null

echo
echo "===== Q26: 10 万元素的内存开销 ====="
printf "%-10s %10s %12s\n" "类型" "占用" "B/元素"
for t in string hash list set zset; do
    $R FLUSHALL >/dev/null
    $R CONFIG SET io-threads 1 >/dev/null
    before=$(mem)
    case $t in
      string) seq 1 $N | awk '{print "SET k:"$1" v"$1}'   | $R --pipe >/dev/null ;;
      hash)   seq 1 $N | awk '{print "HSET h f"$1" v"$1}' | $R --pipe >/dev/null ;;
      list)   seq 1 $N | awk '{print "RPUSH l v"$1}'      | $R --pipe >/dev/null ;;
      set)    seq 1 $N | awk '{print "SADD s v"$1}'       | $R --pipe >/dev/null ;;
      zset)   seq 1 $N | awk '{print "ZADD z "$1" v"$1}'  | $R --pipe >/dev/null ;;
    esac
    after=$(mem)
    printf "%-10s %8s MB %10s\n" "$t" "$(mb $before $after)" "$(per $before $after)"
done

echo
echo "===== Q26: 编码选择（listpack vs hashtable）====="
$R FLUSHALL >/dev/null
TH=$($R CONFIG GET hash-max-listpack-entries | tail -1)
printf "  hash-max-listpack-entries = %s（Redis 7.4 默认值，老版本是 128）\n" "$TH"
$R HSET h_small f1 v >/dev/null
for i in $(seq 1 600); do $R HSET h_big "f$i" v >/dev/null; done
$R RPUSH l_small a b c >/dev/null
$R ZADD z_small 1 a 2 b >/dev/null
printf "  1 字段 hash   : %s\n" "$($R OBJECT ENCODING h_small)"
printf "  600 字段 hash : %s   ← 超过 512，转哈希表\n" "$($R OBJECT ENCODING h_big)"
printf "  3 元素 list   : %s\n" "$($R OBJECT ENCODING l_small)"
printf "  2 元素 zset   : %s\n" "$($R OBJECT ENCODING z_small)"
echo "  ↑ 小对象用 listpack（连续内存），超阈值才转 hashtable/skiplist"

echo
echo "===== Q31: 大 Key 扫描 ====="
$R FLUSHALL >/dev/null
head -c 2000000 /dev/zero | tr '\0' 'x' | $R -x SET hugekey >/dev/null
seq 1 50000 | awk '{print "RPUSH biglist v"$1}' | $R --pipe >/dev/null
seq 1 20000 | awk '{print "HSET bighash f"$1" v"$1}' | $R --pipe >/dev/null
$R --bigkeys 2>&1 | grep -E 'Biggest|biggest'
echo "--- MEMORY USAGE 单键占用 ---"
printf "  hugekey  %s bytes\n" "$($R MEMORY USAGE hugekey)"
printf "  biglist  %s bytes\n" "$($R MEMORY USAGE biglist)"
printf "  bighash  %s bytes\n" "$($R MEMORY USAGE bighash)"

$R FLUSHALL >/dev/null
