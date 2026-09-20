#!/bin/sh
# Q3 实测驱动：三种配置各跑一次 cpu / web 负载
# 用法: docker exec learn-php sh /app/bench/q3-opcache-jit.sh

PHP="php -d opcache.enable_cli=1"
S=/app/bench/q3-opcache-jit.php

echo "===== 1) 关 OPcache ====="
php -d opcache.enable_cli=0 $S cpu
php -d opcache.enable_cli=0 $S web

echo
echo "===== 2) 开 OPcache，关 JIT ====="
$PHP -d opcache.jit=disable $S cpu
$PHP -d opcache.jit=disable $S web

echo
echo "===== 3) 开 OPcache + JIT(tracing) ====="
$PHP -d opcache.jit=tracing -d opcache.jit_buffer_size=64M $S cpu
$PHP -d opcache.jit=tracing -d opcache.jit_buffer_size=64M $S web
