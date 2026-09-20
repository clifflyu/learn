#!/bin/sh
# Q45 实测：五种内存曲线的对照。每个 case 一个独立进程，互不污染。
#
# 用法: sh bench/ops/q45-mem-leak.sh

for c in cycles_gc_off static_array global_array gc_on bigalloc_freed; do
    docker exec learn-php php -d memory_limit=1G /app/bench/ops/q45-mem-leak.php "$c" 8
    echo
done
