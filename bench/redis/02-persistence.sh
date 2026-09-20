#!/bin/sh
# Q27 RDB vs AOF：断电（kill -9）之后到底丢多少数据
#
# 必须在宿主机上跑：脚本内部用 docker run / kill / start。
# 用法: sh bench/redis/02-persistence.sh
#
# 为什么不直接 kill learn-redis：
#   learn-redis 的 redis.conf 是 :ro 挂载，CONFIG REWRITE 写不进去；容器重启后
#   appendonly 又回到配置文件里的 no，AOF 场景会把「配置没生效」误读成「AOF 丢了
#   数据」。所以这里用同一个镜像起一个一次性容器，启动参数 = 重启后的配置，完全由
#   本脚本控制。跑完 docker rm -f。
#
# 坑：docker exec 不加 -i 时不接 stdin，redis-cli --pipe 会静默写入 0 条。

C=learn-redis-persist
IMG=docker.m.daocloud.io/library/redis:7
DATA=/opt/learn/data/redis-persist
R="docker exec -i $C redis-cli"
N=2000

start() {
    docker rm -f $C >/dev/null 2>&1
    rm -rf $DATA; mkdir -p $DATA
    docker run -d --name $C -v $DATA:/data $IMG \
        redis-server --dir /data --save 3600 1 "$@" >/dev/null
    i=0
    while [ $i -lt 100 ]; do
        if $R PING >/dev/null 2>&1; then sleep 0.4; return 0; fi
        sleep 0.2; i=$((i+1))
    done
    echo "  !! redis 未就绪"; return 1
}

crash()  { docker kill $C >/dev/null 2>&1; }              # 相当于 kill -9 / 断电
revive() {
    docker start $C >/dev/null
    i=0
    while [ $i -lt 150 ]; do
        if $R PING >/dev/null 2>&1; then sleep 0.4; return 0; fi
        sleep 0.2; i=$((i+1))
    done
    echo "  !! 重启后未就绪"; return 1
}

# --pipe 一次灌完：2000 条约 15ms（shell 循环起 2000 个 redis-cli 要 20 秒，而且
# 写得太慢会让 AOF 的 everysec 窗口"自然"闭合，测不出想测的东西）
write_n() {
    i=1
    while [ $i -le $1 ]; do printf 'SET k%d v%d\n' $i $i; i=$((i+1)); done | $R --pipe 2>&1 | tail -1
}
lostline() { S=$($R DBSIZE); echo "  重启后 DBSIZE=$S   ← 丢 $(($1 - S)) / $1 条"; }

echo "===== 0. 基线：先确认 --pipe 真的写进去了 ====="
start --appendonly no
write_n $N
echo "  DBSIZE=$($R DBSIZE)"

echo
echo "===== A. RDB，save 拉长到 1 小时，写 $N 条后 kill -9 ====="
crash; revive; lostline $N

echo
echo "===== B. RDB，默认 save 规则 60/10000，只写 $N 条 —— 够不到阈值 ====="
start --save 60 10000
write_n $N
echo "  写入后 DBSIZE=$($R DBSIZE)  rdb_changes_since_last_save=$($R INFO persistence | grep rdb_changes_since_last_save | tr -d '\r' | cut -d: -f2)"
crash; revive; lostline $N

echo
echo "===== C. RDB，默认 60/10000，写 12000 条（写次数够了），1 秒后 kill -9 ====="
start --save 60 10000
write_n 12000
echo "  写入后 DBSIZE=$($R DBSIZE)  rdb_changes_since_last_save=$($R INFO persistence | grep rdb_changes_since_last_save | tr -d '\r' | cut -d: -f2)"
sleep 1
echo "  1 秒后 rdb_changes_since_last_save=$($R INFO persistence | grep rdb_changes_since_last_save | tr -d '\r' | cut -d: -f2)  ← 没被清零，说明 BGSAVE 压根没触发"
echo "  save 的两个条件是「且」：距上次存盘 >= 60 秒 且 累计写 >= 10000 次。这里只满足第二个"
crash; revive; lostline 12000

echo
echo "===== C2. RDB，把 save 调密到 1 秒 1 次写，同样 12000 条 ====="
start --save 1 1
write_n 12000
sleep 1.5
echo "  写入后 DBSIZE=$($R DBSIZE)  rdb_changes_since_last_save=$($R INFO persistence | grep rdb_changes_since_last_save | tr -d '\r' | cut -d: -f2)（被 BGSAVE 清零了）"
crash; revive; lostline 12000

echo
echo "===== D. RDB，同一个场景改成优雅停止（docker stop = SIGTERM）====="
start --save 3600 1
write_n $N
echo "  写入后 DBSIZE=$($R DBSIZE) → docker stop"
docker stop $C >/dev/null; revive; lostline $N
echo "  ↑ 对照 A：只差一个停法，2000 条全在。丢失窗口只在「崩」的时候存在"

echo
echo "===== E. AOF appendfsync everysec，写 $N 条后立刻 kill -9 ====="
start --appendonly yes --appendfsync everysec
write_n $N
echo "  写入后 DBSIZE=$($R DBSIZE)"
$R INFO persistence | tr -d '\r' | grep -E 'aof_current_size|aof_fsync_offset|aof_pending_bio_fsync|aof_delayed_fsync|aof_last_write_status' | sed 's/^/    /'
echo "  --- 崩溃瞬间磁盘上的 AOF 文件里有没有最后那条 k2000 ---"
echo "      grep 命中行数: $(grep -c 'k2000' $DATA/appendonlydir/*.aof 2>/dev/null | tr '\n' ' ')"
echo "      AOF 文件大小:  $(ls -l $DATA/appendonlydir/ 2>/dev/null | awk '/aof$/{printf "%s(%s B) ", $9, $5}')"
echo "      ↑ everysec 的 fsync 每秒才跑一次，这一刻它还没跑；但数据已经在文件里了："
echo "        write() 早就把数据交给内核 page cache，kill -9 只杀进程，杀不掉内核里的页"
crash; revive; lostline $N

echo
echo "===== F. AOF appendfsync always，同样写 $N 条后 kill -9 ====="
start --appendonly yes --appendfsync always
write_n $N
crash; revive; lostline $N

echo
echo "===== G. 三种 appendfsync 的写吞吐 + 单次 SET 客户端延迟 ====="
printf "  %-10s %-14s %-14s %s\n" 'appendfsync' 'SET rps' 'p50' 'aof_delayed_fsync'
for fs in everysec always no; do
    start --appendonly yes --appendfsync $fs
    out=$(docker exec -i $C redis-benchmark -q -n 20000 -c 50 -t set 2>&1 | tr '\r' '\n' | grep 'requests per second' | tail -1)
    rps=$(echo "$out" | awk '{print $2}')
    p50=$(echo "$out" | sed 's/.*p50=//' | awk '{print $1}')
    dly=$($R INFO persistence | grep aof_delayed_fsync | tr -d '\r' | cut -d: -f2)
    printf "  %-10s %-14s %-14s %s\n" "$fs" "$rps" "$p50" "$dly"
done

echo
echo "===== H. appendfsync=no：最「不安全」的模式，进程被 kill -9 一样一条不丢 ====="
start --appendonly yes --appendfsync no
write_n 30000
echo "  写入后 DBSIZE=$($R DBSIZE)"
echo "  AOF 文件大小: $(ls -l $DATA/appendonlydir/ 2>/dev/null | awk '{s+=$5} END{print s" B"}')"
crash; revive; lostline 30000

docker rm -f $C >/dev/null 2>&1
rm -rf $DATA
echo
echo "  清理完毕。learn-redis 全程未被触碰。"
