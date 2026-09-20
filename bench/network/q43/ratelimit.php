<?php
// Q43 五种限流算法的 Redis 实现。
//
// 全部用 Lua 脚本执行：判定 + 更新状态必须原子，
// 否则并发下「读-判断-写」会互相覆盖，限流就漏了。
//
//   Redis 7.4.11 / PHP 8.4.25 (phpredis)
declare(strict_types=1);

abstract class RateLimiter
{
    protected Redis $r;

    public function __construct(?Redis $r = null)
    {
        $this->r = $r ?? self::conn();
    }

    public static function conn(): Redis
    {
        $r = new Redis();
        $r->connect('learn-redis', 6379, 1.0);
        return $r;
    }

    /** @return array{allow:bool, remaining:int, retry_after_ms:float, info:string} */
    abstract public function allow(string $key, int $now_ms): array;

    abstract public function name(): string;

    public function redis(): Redis
    {
        return $this->r;
    }

    /** 人类可读的参数描述，用于输出 */
    abstract public function spec(): string;

    /** 清掉本算法用的 key（只删 q43: 前缀，不动别人的数据） */
    public function reset(string $key): void
    {
        $keys = $this->r->keys("q43:{$this->name()}:{$key}*");
        if ($keys) {
            $this->r->del($keys);
        }
    }
}

// ---------------------------------------------------------------------------
// 1. 固定窗口：每个窗口一个计数器。窗口按绝对时间对齐（floor(now/window)）。
//    简单、省内存；代价是窗口边界可以打出 2 倍流量。
// ---------------------------------------------------------------------------
final class FixedWindow extends RateLimiter
{
    private string $lua = <<<'LUA'
local limit  = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local cur = redis.call('INCR', KEYS[1])
if cur == 1 then redis.call('PEXPIRE', KEYS[1], window) end
local ttl = redis.call('PTTL', KEYS[1])
if cur > limit then
  return {0, 0, ttl}
end
return {1, limit - cur, ttl}
LUA;

    public function __construct(private int $limit, private int $window_ms, ?Redis $r = null)
    {
        parent::__construct($r);
    }

    public function name(): string
    {
        return 'fixed_window';
    }

    public function spec(): string
    {
        return "{$this->limit}/{$this->window_ms}ms";
    }

    public function allow(string $key, int $now_ms): array
    {
        // 关键：key 里带上窗口序号，窗口边界是【绝对时间】的整数倍
        $bucket = intdiv($now_ms, $this->window_ms);
        $k = "q43:{$this->name()}:$key:$bucket";
        [$allow, $remain, $ttl] = $this->r->eval($this->lua, [$k, $this->limit, $this->window_ms], 1);
        return [
            'allow' => (bool)$allow, 'remaining' => (int)$remain,
            'retry_after_ms' => (float)$ttl, 'info' => "窗口#{$bucket}",
        ];
    }
}

// ---------------------------------------------------------------------------
// 2. 滑动窗口·日志：把每次请求的时间戳记进 zset，数「最近 window 内有多少个」。
//    精确，没有边界问题；代价是每个请求存一条，高 QPS 下内存吃不消。
// ---------------------------------------------------------------------------
final class SlidingWindowLog extends RateLimiter
{
    private string $lua = <<<'LUA'
local limit  = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local now    = tonumber(ARGV[3])
redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, now - window)
local count = redis.call('ZCARD', KEYS[1])
if count >= limit then
  local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
  local retry = 0
  if oldest[2] then retry = (tonumber(oldest[2]) + window) - now end
  return {0, 0, retry, count}
end
redis.call('ZADD', KEYS[1], now, ARGV[4])
redis.call('PEXPIRE', KEYS[1], window)
return {1, limit - count - 1, 0, count + 1}
LUA;

    public function __construct(private int $limit, private int $window_ms, ?Redis $r = null)
    {
        parent::__construct($r);
    }

    public function name(): string
    {
        return 'sliding_log';
    }

    public function spec(): string
    {
        return "{$this->limit}/{$this->window_ms}ms";
    }

    public function allow(string $key, int $now_ms): array
    {
        $k = "q43:{$this->name()}:$key";
        // 成员必须唯一：同一毫秒内的多个请求不能用同一个 member
        $member = $now_ms . '-' . bin2hex(random_bytes(6));
        [$allow, $remain, $retry, $count] = $this->r->eval(
            $this->lua, [$k, $this->limit, $this->window_ms, $now_ms, $member], 1
        );
        return [
            'allow' => (bool)$allow, 'remaining' => (int)$remain,
            'retry_after_ms' => (float)$retry, 'info' => "窗口内{$count}个",
        ];
    }
}

// ---------------------------------------------------------------------------
// 3. 滑动窗口·计数：只存「上一个窗口」和「当前窗口」两个计数，
//    用当前窗口已过去的比例给上一个窗口加权。内存 O(1)，结果是近似值。
// ---------------------------------------------------------------------------
final class SlidingWindowCounter extends RateLimiter
{
    private string $lua = <<<'LUA'
local limit  = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local now    = tonumber(ARGV[3])
local bucket = math.floor(now / window)
local elapsed = (now % window) / window          -- 当前窗口已过去的比例
local cur_key  = KEYS[1] .. ':' .. bucket
local prev_key = KEYS[1] .. ':' .. (bucket - 1)
local cur  = tonumber(redis.call('GET', cur_key)  or 0)
local prev = tonumber(redis.call('GET', prev_key) or 0)
-- 上一个窗口按「还剩多少」折算：刚进新窗口时几乎全算，快出窗口时几乎不算
local estimate = prev * (1 - elapsed) + cur
if estimate + 1 > limit then
  return {0, math.max(0, limit - math.floor(estimate)), (1 - elapsed) * window}
end
redis.call('INCR', cur_key)
redis.call('PEXPIRE', cur_key, window * 2)
return {1, math.floor(limit - estimate - 1), 0}
LUA;

    public function __construct(private int $limit, private int $window_ms, ?Redis $r = null)
    {
        parent::__construct($r);
    }

    public function name(): string
    {
        return 'sliding_counter';
    }

    public function spec(): string
    {
        return "{$this->limit}/{$this->window_ms}ms";
    }

    public function allow(string $key, int $now_ms): array
    {
        $k = "q43:{$this->name()}:$key";
        [$allow, $remain, $retry] = $this->r->eval(
            $this->lua, [$k, $this->limit, $this->window_ms, $now_ms], 1
        );
        return [
            'allow' => (bool)$allow, 'remaining' => (int)$remain,
            'retry_after_ms' => (float)$retry,
            'info' => '近似',
        ];
    }
}

// ---------------------------------------------------------------------------
// 4. 令牌桶：桶里最多 cap 个令牌，按 rate 个/秒匀速补充，来一个请求取一个。
//    允许 up to cap 的突发，是网关最常用的算法。
// ---------------------------------------------------------------------------
final class TokenBucket extends RateLimiter
{
    private string $lua = <<<'LUA'
local rate = tonumber(ARGV[1])      -- 每秒补多少令牌
local cap  = tonumber(ARGV[2])
local now  = tonumber(ARGV[3])      -- 毫秒
local need = tonumber(ARGV[4])
local d = redis.call('HMGET', KEYS[1], 'tokens', 'ts')
local tokens = tonumber(d[1])
local ts     = tonumber(d[2])
if tokens == nil then tokens = cap; ts = now end
tokens = math.min(cap, tokens + (now - ts) / 1000 * rate)
local allow = 0
if tokens >= need then tokens = tokens - need; allow = 1 end
redis.call('HSET', KEYS[1], 'tokens', tokens, 'ts', now)
redis.call('PEXPIRE', KEYS[1], math.ceil(cap / rate * 1000) + 2000)
local retry = 0
if allow == 0 then retry = (need - tokens) / rate * 1000 end
return {allow, math.floor(tokens), retry}
LUA;

    public function __construct(private float $rate, private int $cap, ?Redis $r = null)
    {
        parent::__construct($r);
    }

    public function name(): string
    {
        return 'token_bucket';
    }

    public function spec(): string
    {
        return "rate={$this->rate}/s cap={$this->cap}";
    }

    public function allow(string $key, int $now_ms): array
    {
        $k = "q43:{$this->name()}:$key";
        [$allow, $tokens, $retry] = $this->r->eval(
            $this->lua, [$k, $this->rate, $this->cap, $now_ms, 1], 1
        );
        return [
            'allow' => (bool)$allow, 'remaining' => (int)$tokens,
            'retry_after_ms' => (float)$retry, 'info' => "桶内{$tokens}个令牌",
        ];
    }
}

// ---------------------------------------------------------------------------
// 5. 漏桶：请求进来先落进桶里排队，桶底按【恒定速率】漏出。
//    它不拒绝请求，只把请求摊平 —— 出口速率永远是恒定值。
//    这里返回的是「这个请求被安排在第几毫秒发出」，由调用方 sleep 到那个时刻。
// ---------------------------------------------------------------------------
final class LeakyBucket extends RateLimiter
{
    private string $lua = <<<'LUA'
local rate = tonumber(ARGV[1])     -- 每秒漏出多少
local cap  = tonumber(ARGV[2])
local now  = tonumber(ARGV[3])
local interval = 1000 / rate
local d = redis.call('HMGET', KEYS[1], 'next_free', 'ts')
local next_free = tonumber(d[1])
if next_free == nil or next_free < now then next_free = now end
-- 桶里当前的积压深度 = 还有多久才能轮到我 / 每两个请求的间隔。
-- 【不要】维护一个 queued 计数器：它只会加不会减，桶满之后就永远拒绝。
-- depth 由 next_free 和 now 推导，天然是自愈的。
local depth = math.max(0, (next_free - now) / interval)
if depth + 1 > cap then
  return {0, 0, 0, math.floor(depth)}            -- 桶满，只能拒绝
end
local depart = next_free
next_free = next_free + interval
redis.call('HSET', KEYS[1], 'next_free', next_free, 'ts', now)
redis.call('PEXPIRE', KEYS[1], math.ceil(cap / rate * 1000) + 2000)
return {1, 1, depart - now, math.floor(depth) + 1}
LUA;

    public function __construct(private float $rate, private int $cap, ?Redis $r = null)
    {
        parent::__construct($r);
    }

    public function name(): string
    {
        return 'leaky_bucket';
    }

    public function spec(): string
    {
        return "rate={$this->rate}/s cap={$this->cap}";
    }

    public function allow(string $key, int $now_ms): array
    {
        $k = "q43:{$this->name()}:$key";
        [$allow, , $wait, $depth] = $this->r->eval(
            $this->lua, [$k, $this->rate, $this->cap, $now_ms], 1
        );
        return [
            'allow' => (bool)$allow,
            'remaining' => $allow ? 1 : 0,
            // 排队算法的「重试等待」= 距离轮到它发出的时间
            'retry_after_ms' => (float)$wait,
            'info' => $allow
                ? sprintf('排队深度 %d，%.1f ms 后发出', $depth, (float)$wait)
                : sprintf('桶满拒绝（排队深度 %d）', $depth),
        ];
    }
}
