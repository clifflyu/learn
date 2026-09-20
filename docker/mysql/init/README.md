# MySQL 初始化脚本

放在这个目录里的 `.sql` / `.sh` 文件，**只在数据目录为空时（首次启动）** 按文件名顺序执行一次。

也就是说：`./data/mysql` 一旦有数据，这里加文件、改文件都不会再跑。想重新初始化：

```bash
docker compose -f docker-compose.dev.yml down
rm -rf ./data/mysql
docker compose -f docker-compose.dev.yml up -d learn-mysql
```

面试题的实测表结构不放在这里——那些是 `bench/mysql/*.sql`，需要时手动执行，方便反复重建：

```bash
docker exec -i learn-mysql mysql -uroot -proot \
  --default-character-set=utf8mb4 < bench/mysql/01-setup.sql
```
