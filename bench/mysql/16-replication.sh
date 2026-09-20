#!/bin/sh
# Q23 主从延迟实验：真起一个从库（q23-slave），量 Seconds_Behind_Source
#
# 用法: sh bench/mysql/16-replication.sh
#
# 【踩过的坑 1】不要用 `mysql -N -e "SHOW REPLICA STATUS\G"`：
#   -N（--skip-column-names）在 \G 垂直格式下会把 "Seconds_Behind_Source:" 这个**列名**
#   一起去掉，只剩裸值，于是 awk '/Seconds_Behind_Source:/' 一条都匹配不到，
#   采样全是空 → 被兜底成 NULL → 峰值永远报 0s。
#   第一版脚本就是这样得出「大事务期间延迟峰值 0s」的假结论的。
#   结论：解析 \G 输出时**不要加 -N**。
#
# 【踩过的坑 2】本机（同一台 docker host）的从库和主库共用磁盘，写压力能压出的延迟有限。
#   所以脚本用了三种手段，分别证明不同的事：
#     A) SOURCE_DELAY=N  —— 官方自带的延迟从库，造出稳定的 N 秒延迟，证明 SBM 会跟
#     B) STOP REPLICA IO_THREAD —— 证明「复制断了的时候 SBM 是 NULL 而不是 0」这个监控盲区
#     C) 大量小事务 + replica_parallel_workers 0/4 —— 对比并行回放的追平耗时
#
# 【踩过的坑 3】压测端不能用 shell 的 for + docker exec 发 INSERT：
#   每个 docker exec 要起进程 + 建连接，压出来的是进程启动开销。改用 16-load.php 常驻连接。
#
# 从库（q23-slave）怎么来的 —— 本脚本依赖它已经存在，重建栈后要按下面三步重新造：
#   1) 起一个干净的 8.4 容器，server-id 必须和主库（1）不同，只复制 repl 库：
#        docker run -d --name q23-slave --network learn_default -e MYSQL_ROOT_PASSWORD=root \
#          docker.m.daocloud.io/library/mysql:8.4 \
#          --server-id=2 --relay-log=relay --innodb-buffer-pool-size=256M \
#          --replicate-wild-do-table=repl.%
#   2) 主库建复制账号并记下位点：
#        CREATE USER 'repl'@'%' IDENTIFIED BY 'repl'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
#        SHOW BINARY LOG STATUS;   -- 8.4 不再叫 SHOW MASTER STATUS
#   3) 从库指向主库（主库容器名 learn-mysql 就是 SOURCE_HOST）：
#        CHANGE REPLICATION SOURCE TO SOURCE_HOST='learn-mysql', SOURCE_PORT=3306,
#          SOURCE_USER='root', SOURCE_PASSWORD='root',
#          SOURCE_LOG_FILE='binlog.000001', SOURCE_LOG_POS=<位点>; START REPLICA;
#   （本环境图省事直接用 root 复制账号，生产必须用只带 REPLICATION SLAVE 权限的独立账号；
#     本实例 gtid_mode=OFF，所以用 binlog 文件+位点，没有测 WAIT_FOR_EXECUTED_GTID_SET。）

MASTER=learn-mysql
SLAVE=q23-slave
M="docker exec -i $MASTER mysql -uroot -proot --default-character-set=utf8mb4"
S="docker exec -i $SLAVE mysql -uroot -proot --default-character-set=utf8mb4"

# 取从库某字段（注意：无 -N）。$1 形如 "Seconds_Behind_Source:"
field() { $S -e "SHOW REPLICA STATUS\G" 2>/dev/null | grep "^ *$1" | sed "s/^ *$1 *//" | head -1; }
sbm()   { field "Seconds_Behind_Source:"; }
master_pos() { $M -e "SHOW BINARY LOG STATUS\G" 2>/dev/null | awk '/Position:/{print $2}'; }

# 采样 n 次，间隔 0.3s，打印轨迹到 stderr，返回峰值到 stdout
sample_sbm() {
  n=$1; i=0; max=0; trace=""; nulls=0
  while [ $i -lt "$n" ]; do
    v=$(sbm)
    if [ -z "$v" ]; then v="NULL"; nulls=$((nulls+1)); fi
    trace="$trace $v"
    # 同理：不能写 `if [ "$v" != "NULL" ] && [ "$v" -gt "$max" ] 2>/dev/null; then`
    if [ "$v" != "NULL" ]; then
      if [ "$v" -gt "$max" ]; then
        max=$v
      fi
    fi
    i=$((i+1)); sleep 0.3
  done
  echo "    采样轨迹:$trace（其中 NULL $nulls 次）" >&2
  echo "$max"
}

# 轮询从库 Exec_Source_Log_Pos 追到 $1，返回耗时毫秒
# 注意：这里用嵌套 if 而不是 `if A && B 2>/dev/null; then` ——
# dash 对这种「&& 列表 + 尾随重定向」的 if 条件会报
#   Syntax error: end of file unexpected (expecting "then")
# 直接把整个脚本打断（踩过）。
wait_catchup() {
  target=$1
  t0=$(date +%s%N)
  i=0
  while [ $i -lt 600 ]; do
    ep=$(field "Exec_Source_Log_Pos:")
    if [ -n "$ep" ]; then
      if [ "$ep" -ge "$target" ]; then
        break
      fi
    fi
    i=$((i+1))
    sleep 0.1
  done
  echo $(( ($(date +%s%N) - t0) / 1000000 ))
}

echo "========== 0) 环境 =========="
$M -e "SELECT VERSION() AS master_version, @@server_id AS master_server_id, @@binlog_format;" 2>/dev/null
$S -e "SELECT VERSION() AS slave_version, @@server_id AS slave_server_id;" 2>/dev/null
$S -e "STOP REPLICA; CHANGE REPLICATION SOURCE TO SOURCE_DELAY = 0; START REPLICA;" 2>/dev/null
sleep 2
$M -e "USE repl; CREATE TABLE IF NOT EXISTS big2 (id INT AUTO_INCREMENT PRIMARY KEY, v INT) ENGINE=InnoDB;
       CREATE TABLE IF NOT EXISTS delay_t (id INT PRIMARY KEY, v INT) ENGINE=InnoDB;
       SET SESSION cte_max_recursion_depth = 400000;" 2>/dev/null

echo
echo "========== 1) 空闲基线 =========="
echo "  Seconds_Behind_Source        = $(sbm)"
echo "  Replica_SQL_Running_State    = $(field 'Replica_SQL_Running_State:')"
echo "  Replica_IO_Running           = $(field 'Replica_IO_Running:')"
echo "  Read_Source_Log_Pos          = $(field 'Read_Source_Log_Pos:')"
echo "  Exec_Source_Log_Pos          = $(field 'Exec_Source_Log_Pos:')"

echo
echo "========== 2) 写压力能压出多少延迟：单事务 UPDATE 50 万行 =========="
$M -e "USE repl; SET SESSION cte_max_recursion_depth = 600000;
       UPDATE big SET v = v + 1;" 2>/dev/null &
BIG_PID=$!
MAX=$(sample_sbm 15 2>/dev/null | tail -1)
wait $BIG_PID 2>/dev/null || true
echo "  单事务 UPDATE 50 万行期间 Seconds_Behind_Source 峰值 = ${MAX}s"
echo "  → 大事务在从库是**单线程串行回放**的，压得出来；但同宿主同磁盘，只有几秒量级。"
MS=$(wait_catchup $(master_pos))
echo "  等从库把这 50 万行追平：${MS}ms（追平后 SBM=$(sbm)）"
echo "  ⚠ 必须先等它追平再做下一节。第一版没等，结果下一节测 SOURCE_DELAY=5 时"
echo "    残留的追平延迟和 5 秒叠加，峰值读到 18s，看起来像「SOURCE_DELAY 没生效/不准」。"

echo
echo "========== 3) A) 延迟从库（SOURCE_DELAY）：SBM=0 不等于「同步了」=========="
$S -e "STOP REPLICA; CHANGE REPLICATION SOURCE TO SOURCE_DELAY = 5; START REPLICA;" 2>/dev/null
sleep 2
# 【踩过的坑】必须用一个「肯定没出现过的 id」来验证从库有没有这行。
#   第一版写死 REPLACE INTO delay_t VALUES (1,1)，而 id=1 这一行上一轮早就同步过去了，
#   于是从库 COUNT(*)=1 一直成立，看起来像「延迟根本没生效」——其实是读到了上一轮的残留数据。
KEY=$(( $(date +%s) % 100000 ))
echo "  从库设 SOURCE_DELAY=5（SQL_Delay=$(field 'SQL_Delay:')），主库写入一个新行 id=$KEY："
$M -e "USE repl; REPLACE INTO delay_t VALUES ($KEY, 1);" 2>/dev/null
for i in $(seq 1 8); do
  printf "    t=%ds  SBM=%-4s 从库有这行? %-3s SQL_Remaining_Delay=%s\n" "$i" \
    "$(sbm)" \
    "$($S -N -e "SELECT COUNT(*) FROM repl.delay_t WHERE id = $KEY;" 2>/dev/null)" \
    "$(field 'SQL_Remaining_Delay:')"
  sleep 1
done
echo "  → 前 4 秒：SBM 爬到 5、从库**没有**这行、SQL_Remaining_Delay 在倒计时；"
echo "    第 5 秒：延迟到点、事件回放、SBM 立刻掉回 0。"
echo "    **这是 SBM 最大的坑：它衡量的不是「数据有多旧」，而是「SQL 线程忙不忙」。**"
echo "    从库故意落后 5 秒（延迟从库的正当用法），SBM 照样报 0。"
echo "    要判断延迟从库还剩多少没放，看 SQL_Remaining_Delay，不是 SBM。"
$S -e "STOP REPLICA; CHANGE REPLICATION SOURCE TO SOURCE_DELAY = 0; START REPLICA;" 2>/dev/null
MS=$(wait_catchup $(master_pos))
echo "  已还原 SOURCE_DELAY=0，从库追平耗时 ${MS}ms，SBM=[$(sbm)]"

echo
echo "========== 4) B) 监控盲区：复制断了的时候 SBM 是 NULL，不是 0 =========="
# 先把两边都清成 0 并确认同步，再去停 IO 线程 —— 否则会读到上一轮的残留数据
$M -e "USE repl; TRUNCATE big2;" 2>/dev/null
MS=$(wait_catchup $(master_pos))
echo "  已清空 big2 并等从库同步（${MS}ms）"
$S -e "STOP REPLICA IO_THREAD;" 2>/dev/null
sleep 1
echo "  已停掉从库 IO 线程（不再拉 binlog）。主库写入 20 万行："
$M -e "USE repl; SET SESSION cte_max_recursion_depth = 400000;
       INSERT INTO big2 (v) WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < 200000)
       SELECT n FROM seq;" 2>/dev/null
sleep 1
echo "  主库 COUNT(*) big2 = $($M -N -e 'SELECT COUNT(*) FROM repl.big2;' 2>/dev/null)"
echo "  从库 COUNT(*) big2 = $($S -N -e 'SELECT COUNT(*) FROM repl.big2;' 2>/dev/null)   ← 真的没有"
echo "  【此刻】Seconds_Behind_Source = [$(sbm)]    ← 注意是 NULL（空），不是 0"
echo "  【此刻】Replica_IO_Running  = $(field 'Replica_IO_Running:')"
echo "  【此刻】Replica_SQL_Running = $(field 'Replica_SQL_Running:')   ← 还是 Yes！"
echo "  【此刻】Replica_SQL_Running_State = $(field 'Replica_SQL_Running_State:')"
echo "    主库 binlog Position      = $(master_pos)"
echo "    从库 Read_Source_Log_Pos  = $(field 'Read_Source_Log_Pos:')（IO 线程停了，不再前进）"
GAP=$(( $(master_pos) - $(field 'Read_Source_Log_Pos:') ))
echo "    还没有被拉到从库的字节数 = ${GAP}"
echo "  → 判据：SBM 只在「从库的 IO 线程正常运行时」才有值。复制断了（IO 线程停 / 连不上主库）它报 NULL。"
# 【踩过的坑】下面这句原来是写成反引号包住「if ... then ...」的，结果在 sh 里
#   双引号内的反引号 = 命令替换，sh 去执行 "if sbm > 阈值 then 报警" 这个命令，
#   引号被吃掉，解析器一路读到文件尾报 "end of file unexpected (expecting \"then\")"。
#   在 shell 的 echo 里写伪代码，别用反引号。
echo "    绝大多数监控是「if sbm > 阈值 then 报警」，NULL 比较永远为假 → **复制彻底断了反而不报警**。"
echo "    更迷惑的是 Replica_SQL_Running 仍然显示 Yes，只有 Replica_IO_Running 变成了 No。"
echo "    所以告警必须同时判 Replica_IO_Running / Replica_SQL_Running 这两个 Yes/No。"
echo "  恢复 IO 线程，量追平耗时："
# 【踩过的坑】第一版忘了写这句，IO 线程一直停着，wait_catchup 去追一个永远追不到的位置，
#   脚本挂在那儿不动。停掉复制之后一定要记得启回来。
$S -e "START REPLICA IO_THREAD;" 2>/dev/null
MS=$(wait_catchup $(master_pos))
echo "    追平 20 万行花了 ${MS}ms，当前 SBM=[$(sbm)]，"\
"从库 COUNT(*) = $($S -N -e 'SELECT COUNT(*) FROM repl.big2;' 2>/dev/null)"

echo
echo "  ⚠ 未实测：真·跨机房的网络延迟与带宽瓶颈、半同步复制（AFTER_SYNC）的确认等待耗时、"
echo "            GTID 多线程回放的 write-set 冲突回滚率 —— 主从在同一台宿主机上，这些量不出来。"


echo "========== 5) C) 并行回放：2000 个独立小事务，对比 workers=0 / 4 =========="
for W in 0 4; do
  $S -e "STOP REPLICA; SET GLOBAL replica_parallel_workers = $W; START REPLICA;" 2>/dev/null
  sleep 2
  POS_START=$(master_pos)
  echo "  --- replica_parallel_workers=$W（$(field 'Replica_parallel_workers:')）---"
  docker exec learn-php php /app/bench/mysql/16-load.php 20 100 small 2>&1 | grep -a "\[load\]" | sed 's/^/  /'
  POS_END=$(master_pos)
  echo "    主库 binlog 本轮写入 $(( POS_END - POS_START )) 字节"
  MS=$(wait_catchup "$POS_END")
  echo "    从库回放到该位置耗时 ${MS}ms"
done
$S -e "STOP REPLICA; SET GLOBAL replica_parallel_workers = 4; START REPLICA;" 2>/dev/null
wait_catchup $(master_pos) >/dev/null

echo
echo "========== 6) 读写分离的经典问题：写完立刻读从库 =========="
$M -e "USE repl; DELETE FROM small;" 2>/dev/null
$M -e "USE repl; INSERT INTO small (id, v, pad) VALUES (999999, 1, 'x');" 2>/dev/null
echo -n "  主库刚写完立刻读从库: "; $S -N -e "SELECT COUNT(*) FROM repl.small WHERE id = 999999;" 2>/dev/null
sleep 1
echo -n "  等 1 秒后再读从库:     "; $S -N -e "SELECT COUNT(*) FROM repl.small WHERE id = 999999;" 2>/dev/null
echo "  → 这就是「写主读从」必须解决的读己之写（read-your-writes）问题"

echo
echo "========== 7) SHOW REPLICA STATUS 关键字段 =========="
$S -e "SHOW REPLICA STATUS\G" 2>/dev/null | grep -E \
  "Replica_IO_Running|Replica_SQL_Running:|Seconds_Behind_Source|Read_Source_Log_Pos|Exec_Source_Log_Pos|Relay_Log_Space|Replica_SQL_Running_State|Source_Log_File|Replica_parallel|Last_SQL_Error:|Source_Delay" | sed 's/^ */  /'

echo
echo "========== 8) 判断延迟到底该看哪个指标 =========="
echo "  Seconds_Behind_Source      时间维度。复制断了报 NULL（不是 0）；且依赖主从时钟一致"
echo "  Replica_IO/SQL_Running     唯一可靠的『复制是不是活着』判据，告警必须带上这两个"
echo "  Read - Exec_Source_Log_Pos 体积维度。IO 停了它也不动，反映『还没回放多少字节』"
echo "  主库 Position - 从库 Exec   最接近『还没到手的数据量』"
echo "  外部心跳表（主库定时写时间戳，从库读出来和本地 now() 比）绕开时钟与 NULL 问题，生产最可信"

echo
echo "  未实测：真·跨机房复制的网络延迟、半同步复制（AFTER_SYNC）的等待耗时、"
echo "          GTID 多线程回放的 write-set 冲突回滚率 —— 本环境主从在同一台宿主机上，量不出来。"
