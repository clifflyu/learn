#!/bin/sh
# 把 FPM 配置恢复成动手之前的样子（原文件备份在 _fpm-application.conf.orig）
# 改过 pm.* / pm.status_path / request_slowlog_timeout 的脚本跑完都要执行这个。
#
# 用法: sh bench/ops/q45-restore-fpm.sh

C=learn-php
CONF=/usr/local/etc/php-fpm.d/application.conf

docker exec $C sh -c "cp /app/bench/ops/_fpm-application.conf.orig $CONF &&
    supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
sleep 2

echo "已恢复。当前配置："
docker exec $C sh -c "grep -n '^pm\|^;pm.max_requests' $CONF"
echo "md5（原始 = a72026f2a3d766ebccfb9098bf125f50）:"
docker exec $C sh -c "md5sum $CONF"
