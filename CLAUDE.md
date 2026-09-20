# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 仓库定位

PHP 高级工程师面试备考仓库。产物是 Markdown 答案，不是应用；Docker 环境只用来验证答案。

| 位置 | 作用 |
| --- | --- |
| `php-senior-interview-top50.md` | 50 道题，★ 标必问 |
| `php-senior-interview-answers.md` | 答案。当前只到 Q1，**Q1 是格式模板** |
| `bench/` | 产出答案里那些数字的实测脚本 |
| `docker-compose.dev.yml` + `docker/` | php 8.4 / mysql 8.4 / redis 7 |

## 硬约束

1. **不写没跑过的数字。** 每个数字标版本（`PHP 8.4.25` / `MySQL 8.4.11` / `Redis 7.4.11`）并附 `bench/` 里的脚本路径。
2. **脚本进 `bench/`，不内联进文档。** 文档只写结论 + 脚本路径。
3. **原理结论对照源码**，不靠记忆。
4. **测不出来就写「未实测」**，有噪声就说明噪声，不要编一个好看的数字。
5. `bench/` 脚本是**一次性证据**，不复用即弃，不要参数化封装或改造成通用工具。
6. 中文写作，术语保留英文（`packed`、`listpack`、`Covered index`）。

## 命令

```bash
docker compose -f docker-compose.dev.yml up -d     # php:9311  mysql:9312  redis:9313

docker exec learn-php php /app/bench/q1-array.php

docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 < bench/mysql/01-setup.sql
docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 -t < bench/mysql/02-index.sql
docker exec learn-php php /app/bench/mysql/04-mvcc.php

docker exec learn-redis sh /app/bench/redis/01-basics.sh

sh bench/mysql/06-deadlock.sh        # 这两个必须在宿主机跑，脚本内部调 docker kill
sh bench/redis/02-persistence.sh
```

`./data/` 是 MySQL 数据目录和 Redis 的 rdb/aof（已 gitignore）。重建栈后 `users` 表会空，需重灌 `01-setup.sql`。

## 答案格式

照 Q1 的骨架：结论 / 结构图 / 实测（标版本）/ 反直觉的点 / 实战结论 / 可能的追问。

图用 ASCII 画在代码块里。实测优先**差分法**：两档规模各测一次，差值 ÷ 规模差，抵消常数项与分配器噪声。

## 实测踩过的坑

- **OPcache**：`file_update_protection` 默认 2 秒，刚生成的文件不缓存 → 加 `-d opcache.file_update_protection=0`；CLI 下数字不可信（测出过比不开还慢），走 nginx+FPM 的 `bench/web-load.php`
- **JIT**：webdevops 镜像的 ionCube 会静默禁掉它，要测就用干净的 `php:8.4-cli`
- **MySQL 客户端**：必须 `--default-character-set=utf8mb4`，否则中文（含中文别名）被截断；隔离级别用空格写 `READ COMMITTED`
- **死锁要两个进程**：PHP 单线程里 `exec()` 会阻塞，只能得到 1205 锁等待超时而非 1213 死锁
- **测回表**别用 `COUNT(*)` 包 `SELECT *`，投影会被优化器裁掉；用 `SUM(LENGTH(col))`
- **造测试数据**时列之间要独立，同一取模基数会让某些组合 0 行，聚合出假结论
- **Redis**：`DEBUG` 默认禁用（用 Lua busy loop 替代）；`hash-max-listpack-entries` 默认 512、zset 是 128
- **PHP 双引号串** `"$var：中文"` 会把中文标点当变量名 → 写 `"{$var}：中文"`
- 容器没挂 `./:/app` 时，用 `docker exec -i <c> sh -s < script.sh` 从 stdin 喂
