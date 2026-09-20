# PHP 高级工程师面试题 · 答案整理

> 配套 [`php-senior-interview-top50.md`](php-senior-interview-top50.md)。
>
> **所有数字都来自容器实测**，脚本在 [`bench/`](bench/)，可逐条复现（见 [`CLAUDE.md`](CLAUDE.md)）。
> 测不出来的会明确标注「未实测」，不编数字。

## 按面试轮次分册

| 分册 | 覆盖 | 内容 |
| --- | --- | --- |
| [01 · PHP 语言与基础](answers/01-php-basics.md) | Q1–Q8 | 数组底层、引用计数/COW/GC、OPcache 与 JIT、PHP-FPM、`==` 陷阱、OOP、IoC、PHP 8 |
| [02 · 框架与代码设计](answers/02-framework.md) | Q9–Q12 | Laravel 生命周期、N+1、SOLID、分层 |
| [03 · MySQL](answers/03-mysql.md) | Q13–Q24 | B+Tree、索引、事务与 MVCC、锁与死锁、慢 SQL、深分页、主从、分库分表 |
| [04 · Redis 与缓存](answers/04-redis.md) | Q25–Q31 | 为什么快、数据结构、RDB/AOF、穿透击穿雪崩、一致性、分布式锁、热 Key |
| [05 · 消息队列与分布式](answers/05-mq.md) | Q32–Q37 | 为什么用 MQ、消息不丢、重复消费、顺序与积压、分布式事务、幂等 |
| [06 · 网络与安全](answers/06-network.md) | Q38–Q43 | URL 到页面、HTTPS、Cookie/Session/JWT、注入与 XSS/CSRF、502/504、限流熔断降级 |
| [07 · 性能与线上故障](answers/07-ops.md) | Q44–Q45 | 接口变慢的排查顺序、CPU 高与内存增长的定位 |
| [08 · 系统设计与项目经验](answers/08-design.md) | Q46–Q50 | 秒杀、订单超时关闭、项目/故障/优化三个故事 |

## 环境

```bash
docker compose -f docker-compose.dev.yml up -d    # php:9311  mysql:9312  redis:9313
```

php 容器把仓库挂在 `/app`，所以实测脚本可以直接 `docker exec learn-php php /app/bench/...` 跑。
