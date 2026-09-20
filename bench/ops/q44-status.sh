#!/bin/sh
# 打开/关闭 FPM 状态页（pm.status_path）。q44-downstream.sh、q44-pool.sh、
# q45-proc-top.sh 这三个脚本依赖它，跑之前先执行本脚本。
#
# FPM 会拦截这个 URI 自己处理，不需要真的存在 /fpm-status.php 这个文件。
# 打开状态页会重启 php-fpm（这个镜像里 nginx 也跟着重启一次），跑完记得关。
#
# 用法: sh bench/ops/q44-status.sh on|off
#       （off 等价于 sh bench/ops/q45-restore-fpm.sh：整份配置回到动手之前）

C=learn-php
CONF=/usr/local/etc/php-fpm.d/application.conf

case "$1" in
    on)
        docker exec $C sh -c "
            sed -i 's|^;*pm.status_path = .*|pm.status_path = /fpm-status.php|' $CONF
            supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
        sleep 3
        i=0
        while [ $i -lt 40 ]; do
            code=$(curl -s -o /dev/null -m 2 -w '%{http_code}' 'http://localhost:9311/fpm-status.php')
            if [ "$code" = "200" ]; then
                echo "状态页已打开： http://localhost:9311/fpm-status.php （?full 看每个 worker 在跑什么）"
                exit 0
            fi
            sleep 0.5
            i=$((i + 1))
        done
        echo "（警告：状态页没起来，最后 HTTP=$code）"
        ;;
    off)
        sh /opt/learn/bench/ops/q45-restore-fpm.sh
        ;;
    *)
        echo "用法: sh bench/ops/q44-status.sh on|off"
        exit 1
        ;;
esac
