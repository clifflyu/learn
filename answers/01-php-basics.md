# 01 · PHP 语言与基础（Q1–Q8）

> 配套 `../php-senior-interview-top50.md`。数字均来自容器实测，脚本在 `../bench/`。

## Q1. PHP 数组的底层结构是什么？为什么说它内存占用大？

### 结论

PHP 数组不是数组，是**有序哈希表**。每个元素最少占一个 `zval`（16 B），是 C `int32` 数组的 **4 倍**；一旦退化成真正的哈希表就是 40 B，**10 倍**。

### 一、底层结构

`emalloc` 一次性分配一整块连续内存，`arData` 指向索引区与 Bucket 数组的交界处，`arHash` 用负偏移访问索引区：

```
                     ┌────────────────────────────┐
  ht->arHash ───────►│ HT_HASH(nTableMask)        │ ─┐
                     │ ...                        │  │ 哈希索引区（负偏移访问）
                     │ HT_HASH(-1)                │ ─┘ 8 B / 槽位
                     ├────────────────────────────┤
  ht->arData ───────►│ Bucket[0]   zval│h│key     │ ─┐
                     │ Bucket[1]   zval│h│key     │  │ Bucket 数组
                     │ ...                        │  │ 按插入顺序连续存放
                     │ Bucket[nTableSize-1]       │ ─┘ 32 B / 个
                     └────────────────────────────┘
                       nTableSize 必须是 2 的幂
```

**Bucket（32 B）** = `zval` 16 + 哈希值 8 + 键指针 8。整数键时 `key` 为 `NULL`；哈希冲突用**拉链法**，`next` 复用 `zval.u2.next`，不额外占字段。

数组有序的原因：Bucket 按插入顺序连续存放，删除只打 `IS_UNDEF` 墓碑、不搬移其他元素。

> 索引区是 **8 B/槽位，不是 4**：源码 `HT_SIZE_TO_MASK` = `-(nTableSize + nTableSize)`，长度是表长的 **2 倍** × `sizeof(uint32_t)`。很多资料写 4 B 是把 mask 记成了 `-nTableSize`。

**packed 数组**（PHP 8.0+）没有索引区和 Bucket 头，`arData` 退化成纯 `zval` 数组。

### 二、实测（PHP 8.4.25）

测法：容量 `nTableSize` 取 2 的幂、扩容正好翻倍，所以在相邻两档规模上各测一次，**差值 ÷ 元素数差 = 精确的每槽位开销**，不受取整和分配器噪声干扰。

| 形态 | N=2¹⁸ | N=2¹⁹ | B/元素 |
| --- | ---: | ---: | ---: |
| packed（递增整数键） | 4,198,480 | 8,392,784 | **16.0** |
| 稀疏整数键 | 10,485,840 | 20,971,600 | **40.0** |
| 短字符串键（2~7 字符） | 18,874,448 | 37,748,816 | **72.0** |
| 长字符串键（21~26 字符） | 25,157,960 | 50,323,784 | **96.0** |
| SplFixedArray | 4,194,424 | 8,388,728 | **16.0** |

换成 2¹⁹ → 2²⁰ 复测，仍是 16.0 / 40.0，确认测量稳定。

**拆解**：`16 B` = 1 个 `zval`；`40 B` = Bucket 32 + 索引区 8；`72 / 96 B` = 40 + 键的 `zend_string`（24 B 头 + 内容，按 8 对齐）。

### 三、四个反直觉的点

**1. SplFixedArray 并不省内存。** packed 数组已经是 16 B/元素，和它一模一样。「用 SplFixedArray 省内存」对顺序数组已经过期，它只在**非 packed 场景**有优势（40 → 16）。

**2. packed 的条件不是「键从 0 开始」，而是「键连续递增写入」。** 这一点最容易记错，也最容易踩：

| 建数组的方式（N=10 万） | B/元素 | 是否 packed |
| --- | ---: | --- |
| 键 0..N-1 顺序 | 21.0 | ✅ |
| 键 **1**..N 顺序 | 21.0 | ✅ 起始键不是 0 也行 |
| 有间隔（0,2,4,…） | 52.4 | ❌ |
| 倒序插入 | 52.4 | ❌ |
| 随机顺序插入 | 52.4 | ❌ |
| 顺序 + 插入一个字符串键 | 52.4 | ❌ 整块退化 |
| 顺序 + 挖洞后继续追加 | 21.0 | ✅ 空洞不破坏 packed |

`for ($i = 1; …)` 照样是 packed，但**乱序 / 有间隔 / 混入字符串键**会让整块立刻退化成哈希表，内存 2.5 倍。批量构建数组时先排好序再写入，是有实际收益的。

**3. `unset` 单元素不还内存。** 1M 元素分配 16,781,392 B，全部 `unset` 后仍占 16,781,392 B，槽位一个不回收。只有 `unset` 整个数组（引用计数归零）才释放。

**4. `nTableSize` 必须是 2 的幂。** 10 万元素实际按 131072 槽分配，白浪费 31%。所以拿元素个数直接乘 16/40 会低估——上面表格里 10 万元素摊出 21.0 而非 16.0，就是这个原因。

### 四、实战结论

| 场景 | 选择 |
| --- | --- |
| 海量数值、长度固定 | `SplFixedArray`、字符串 `pack()` |
| 只判断存在性 | 位图 |
| 大文件 / 大结果集 | 生成器 `yield` 流式处理，**不要**一次性塞进数组 |
| 批量构建大数组 | 让键连续递增写入，避免中途混入字符串键 |
| 删除大量元素后想回收内存 | 只能重建数组，或 `unset` 整个数组 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 为什么数组有序？ | Bucket 按插入顺序连续存放，删除只打墓碑不搬移 |
| 冲突怎么解决？ | 拉链法，`next` 复用 `zval.u2.next` |
| 扩容机制？ | 元素数达 `nTableSize` 时翻倍（保持 2 的幂），重建索引区 |
| key 为什么只能是 int / string？ | 其他类型隐式转换：`float` 截断（8.1 起弃用）、`bool`→int、`null`→`""` |
| `count()` 复杂度？ | O(1)，直接返回 `nNumOfElements` |
| 为什么 `foreach` 比 `for` 快？ | `foreach` 直接遍历连续内存，`for` 每次都要走一遍哈希查找 |
| 遍历用引用 `&$v` 的坑？ | 结束后 `$v` 仍指向最后一个元素，复用该变量会污染数组 |
| PHP 7 / 8 的优化？ | PHP 7：`zval` 24→16 B，Bucket 从分散 `emalloc` 改连续数组，内存约减半（本机无 PHP 5 镜像，**未实测**）；PHP 8.0 packed array：顺序整数键 **33.6 → 16.8 B/元素**（实测见 Q8），**非 packed 数组完全没变** |

---

## Q2. 引用计数、写时复制（COW）、垃圾回收分别解决什么问题？

### 结论

三者解决三个不同的问题，混在一起讲就说不清了：

| 机制 | 解决的问题 |
| --- | --- |
| **引用计数** | **什么时候能释放** —— 记住一块内存还有几个变量在用 |
| **写时复制** | **什么时候要复制** —— 让赋值保持值语义，成本却是 O(1) |
| **垃圾回收** | **引用计数的补丁** —— 回收互相引用、计数永远归不了零的环 |

### 一、三者怎么串起来

```
$a = range(1, 1000000);     分配 16.78 MB，refcount = 1
      │
$b = $a;                    refcount = 2，内存增长 0 B      ← 引用计数 + COW
      │
$b[0] = 999;                refcount 归 1，给 $b 复制一整份 16.78 MB   ← COW 触发
      │
unset($b);                  那 16.78 MB 释放                ← 引用计数归零
      │
──────── 以上机制都成立，直到出现环 ────────
$x->next = $y;  $y->next = $x;
unset($x, $y);              两者 refcount 都停在 1（互指），永远不归零
                            → 引用计数失效，只能靠 GC 扫根缓冲区
```

### 二、实测（PHP 8.4.25）

**引用计数**（`debug_zval_dump` 的显示值比真实值大 1，看变化量）：

```
数组，1 个变量        refcount(3)
赋值给 $b 后          refcount(4)     ← +1
unset $b 后           refcount(3)     ← -1，归零即释放
```

**写时复制**（N = 100 万个整数，只列增量）：

| 操作 | 内存增量 | 说明 |
| --- | ---: | --- |
| `$b = $a` | **0 B** | 只加引用计数，不复制 |
| `count($a)` | 0 B | 只读 |
| `foreach ($a as $v)` | 0 B | 按值遍历也不复制 |
| 只读传参进函数 | 0 B | 函数签名 `array $x` 不写就不复制 |
| **函数内写参数** | **16,781,392 B** | 一写立刻复制一整份 |
| 函数内再写一次 | 376 B | 已分离过，不再复制 |
| `$b[0] = 999` | **16,781,392 B** | 同上，复制一整份 |
| `unset($b)` | −16,781,392 B | 引用计数归零释放 |
| 对象属性赋值 | 0 B | 对象按句柄传递，**不走 COW** |

**垃圾回收**（10 万次循环引用）：

| 配置 | GC 轮次 | 回收根数 | 净增内存 |
| --- | ---: | ---: | ---: |
| `gc_enable()` | 20 | 199,998 | **123,024 B** |
| `gc_disable()` | 0 | 0 | **13,166,136 B** ← 泄漏 |

无循环引用时不需要 GC：10 万次创建/销毁对象，净增内存 **0 B**（引用计数归零就释放了）。

攒下 20 万对循环引用后手动 `gc_collect_cycles()`：回收 400,000 个根，耗时 **0.0245 s**，释放 22,400,000 B。

### 三、四个反直觉的点

**1. 「PHP 数组赋值是值语义」是免费的——直到你写它。** `$b = $a` 复制一个百万级数组的成本是 0 B，因为底层只是 refcount +1。但**函数内对参数的任何一次写操作都会导致完整复制**（实测 16.78 MB）。大数组只读时放心传，要改就在调用方改完再传，或者显式用引用 `&$x`。

**2. 对象不走 COW。** 对象是按句柄传递的，`$obj2 = $obj` 后改 `$obj2` 的属性会**影响** `$obj`。想要独立副本必须 `clone`。这是从"数组行为"类推过来最容易踩的坑。

**3. GC 只处理循环引用，不是通用的"内存回收器"。** 无环的结构靠引用计数就够了（实测净增 0 B）。`gc_disable()` 之后普通对象照样正常释放，只有环会泄漏（实测 13 MB 泄漏全部来自环）。

**4. GC 是有成本的，但很小。** 根缓冲区攒够 10000 个根（`gc_status()['threshold']` 实测 10001）才触发一轮；20 万根的收集耗时 0.0245 s，`collector_time` 累计 0.02 s。**长驻进程（Swoole / 队列消费者）跑批处理时**，可以在批处理前 `gc_disable()`、批处理后手动 `gc_collect_cycles()`，避免 GC 在关键路径上抖动。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 大数组只读传递 | 正常传值，不用担心复制 |
| 大数组需要修改 | 在调用方改，或明确用引用 `&$x`（但引用会阻止一些优化，别滥用） |
| 需要对象的独立副本 | `clone`，`=` 只是加了个句柄 |
| 长驻进程跑批 | 批次间 `gc_collect_cycles()`，控制内存曲线 |
| 内存持续增长排查 | 先看有没有环（`gc_status()['roots']` 是否持续高位） |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 引用计数什么时候归零？ | `unset` 或变量离开作用域时 −1，减到 0 立即释放，不用等 GC |
| `&` 引用和 COW 什么关系？ | 用 `&$b = $a` 后 `is_ref=1`，写入不复制，两者共享同一份；但引用会阻止部分编译优化，现代 PHP 里通常更慢 |
| 什么算一个 GC root？ | 可能成环的：数组、对象。标量（int/string）不会成环，不进根缓冲区 |
| 为什么 PHP 7 内存比 5 省一半？ | `zval` 从 24 B 降到 16 B；`zend_string` / 数组 Bucket 也从"分散 emalloc + 指针链表"改成连续内存（本机无 PHP 5 镜像，**未实测**；7.3→8.4 的实测数据见 Q8） |
| 循环引用只能靠 GC 吗？ | 也可以手动打破环（`$x->next = null`），或析构函数里断开。异步框架里常见这种写法 |
| `gc_disable()` 能提升性能吗？ | 短生命周期请求没必要；只有长驻进程的批处理场景才有意义，且要配合手动收集，否则就是拿内存换 CPU |

---

## Q3. OPcache 和 JIT 分别是什么？为什么 JIT 在 Web 场景下收益有限？

### 结论

两者作用在编译链路的不同层：

- **OPcache** 缓存**编译产物（opcode）**，省掉「每次请求重新编译整个代码库」。收益与**代码库规模**成正比。
- **JIT** 在运行时把**热点 opcode 再编译成机器码**，省掉「逐条解释执行」。收益只体现在**纯计算**上。

JIT 在 Web 场景收益有限，有两个独立原因：**一是 Web 请求的耗时大头不在 PHP 计算上**（实测纯计算负载 1.34 倍，模拟 Web 负载 0 倍）；**二是 JIT 经常被第三方扩展静默禁用**，装了不等于用上了。

### 一、两者在编译链路上的位置

```
  PHP 源码
     │  ① 词法/语法分析 + 编译
     ▼
  opcode（字节码）  ◄──── OPcache 缓存这一层
     │                     命中就跳过 ①，直接进入 ②
     │  ② 逐条解释执行（ZEND_VM）
     ▼
  机器码            ◄──── JIT 在这一层
                           把反复执行的热点 opcode 直接编译成机器码
```

OPcache 是**缓存**，JIT 是**再编译**。所以 OPcache 的效果取决于「编译要花多久」，JIT 的效果取决于「执行要花多久，且这段代码是否热」。

### 二、实测（PHP 8.4.25）

#### OPcache：走 nginx + FPM 的真实路径

样本：500 个类文件 × 60 个方法（单文件 485 行，合计约 24 万行），体量接近一个中型框架。脚本 `bench/web-load.php` + `bench/_fixtures.php`。

| 配置 | 6 次请求耗时（秒） | 中位 |
| --- | --- | ---: |
| OPcache 开 | 0.0185 0.0136 0.0180 0.0136 0.0153 0.0143 | **0.0148** |
| OPcache 关 | 0.1837 0.1573 0.1698 0.1547 0.1655 0.1536 | **0.1614** |

**约 10.9 倍**。另外重启 FPM 后的第一个请求（冷缓存）耗时 **0.326 s**——这就是每次部署后第一波请求会变慢的原因。

#### JIT：分负载类型差异极大

样本：`bench/q3-opcache-jit.php`，`php:8.4-cli` 干净镜像，5 次取最小/中位。

| 负载 | 无 OPcache | OPcache 无 JIT | OPcache + JIT |
| --- | ---: | ---: | ---: |
| **cpu**（2000 万次算术循环） | 0.345 | 0.390 | **0.292** |
| **web**（模拟请求：json 编解码 + 数组操作） | 0.021 | 0.021 | **0.024** |

- 纯计算：`0.390 → 0.292`，**约 1.34 倍**
- 模拟 Web 请求：**没有差异**（都在 0.021 s 上下浮动）

#### 第三方扩展会静默禁用 JIT

在本项目的 webdevops 镜像里开启 JIT，得到的是：

```
PHP Warning:  JIT is incompatible with third party extensions that setup
user opcode handlers. JIT disabled. in Unknown on line 0
```

镜像里加载了 `ionCube Loader`（还有 opentelemetry、excimer 等），它注册了自己的 opcode handler，JIT 直接放弃。**PHP 不报错，只在 stderr 打一行 warning**，`opcache_get_status()['jit']['on']` 会安静地是 `false`。

### 三、四个反直觉的点

**1. 「装了 JIT」和「JIT 生效了」是两回事。** 必须主动核对 `opcache_get_status()['jit']['on']`。PHP 8.4 里 `opcache.jit` 的默认值是 **`disable`**——JIT 默认就是关的，要显式设成 `tracing` 或 `function` 才开（实测只给 `jit_buffer_size` 不给 `jit`，`jit.on` 仍是 `false`；给 `jit=tracing` 不给 buffer 反而是 `true`，因为 buffer 默认已有 64M）。

**2. JIT 的收益只在纯计算上，而 Web 请求里几乎没有纯计算。** 一次请求的耗时构成是：网络往返、DB 查询、Redis、文件 IO、模板渲染，PHP 自身的算术和逻辑占比很小。把 2000 万次算术循环加速 1.34 倍很可观，但真实请求里没有这样的循环。

**3. OPcache 的收益与代码库规模成正比，不在单文件上体现。** 我的模拟 Web 微基准（单文件、纯内存操作）开不开 OPcache 都是 0.021 s —— 编译那一个文件的开销淹没在噪声里。必须用几百个文件的量级才测得出 10 倍差距。

**4. `opcache.file_update_protection` 默认 2 秒，刚写入的文件不会被缓存。** 这个默认值是为了防止「文件写了一半就被缓存」，但在**发布瞬间**会导致新代码不被缓存。我第一次测 OPcache 时脚本现场生成 fixture 再 require，得到「OPcache 完全无效」的假结论，就是这个原因。发布流程里用原子替换（先写临时文件再 `rename`）能绕开。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 生产环境 | **OPcache 必开**，`opcache.memory_consumption` 按代码库规模给（128~512M），`max_accelerated_files` 要大于文件总数 |
| JIT | 计算密集型（图像处理、加解密、复杂规则引擎）值得开；典型 CRUD/API 服务收益接近 0，还要多占内存，可以不开 |
| 上线后 | 用 `opcache_reset()` 或重载 FPM 让新代码生效，别依赖文件时间戳 |
| 排查「优化没生效」 | 先看 `opcache_get_status()`：`opcache_enabled`、`num_cached_scripts`、`oom_restarts`、`jit.on` |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| OPcache 缓存的是什么？ | 编译后的 opcode（`zend_op_array`）以及类/函数表，存在共享内存里，所有 FPM worker 共用 |
| 改了代码怎么生效？ | `opcache.validate_timestamps=1` + `revalidate_freq` 定时检查；生产常用 `validate_timestamps=0` + 发布时 `opcache_reset()`，省掉每次 stat 的开销 |
| JIT 有哪几种模式？ | `tracing`（默认推荐，按热路径追踪编译）、`function`（按函数编译）、`opcache.jit=1205` 这类数字是 CRTO 四位配置 |
| 为什么 JIT 要 buffer？ | 生成的机器码存在 `opcache.jit_buffer_size` 指定的共享内存里，实测 PHP 8.4 默认 64M；buffer 用完就不再编译新代码，只是退回解释执行，不报错 |
| OPcache 内存满了会怎样？ | `oom_restarts` 增长，缓存被整体清空重建，性能反而下降——`max_accelerated_files` 要给够 |
| PHP 8.4 有什么变化？ | JIT 的 IR 框架持续改进；`opcache.jit` 默认仍是 `disable`，需要显式开启 |

---

## Q4. PHP-FPM 的进程管理模式有哪些？`pm.max_children` 怎么估算？

### 结论

三种模式的区别只有一个：**worker 进程什么时候创建、什么时候回收**。

`pm.max_children` 的估算公式很简单：

```
pm.max_children = (可用内存 − 系统与其他服务预留) ÷ 单 worker 峰值内存
```

真正会出错的是**分母取哪个内存数**——同一个 worker，`memory_get_usage()` 说 2 MB，`VmRSS` 说 27.8 MB，差了 13 倍。取错分母，算出的容量能差一个数量级。

### 一、三种模式的行为（实测，`pm.max_children = 6`）

| 模式 | 空闲时 | 并发 6 个请求期间 | 请求结束 2s 后 |
| --- | ---: | --- | ---: |
| `static` | 6 | 6 → 6 → 6 | 6 |
| `dynamic` | 2 | 2 → 3 → 4 | 3 |
| `ondemand` | **0** | 2 → 2 → 2 | 2 |

- **static**：启动即建满 `max_children` 个，永不回收。内存占用恒定，响应最稳。
- **dynamic**：启动建 `start_servers` 个，按负载在 `min_spare_servers` ~ `max_spare_servers` 之间增减。**注意这两个 spare 参数管的是「空闲时保留多少」，不是「最多能有多少」**——上限始终是 `max_children`。
- **ondemand**：一个都不预先建，来请求才 fork。实测空闲后按 `pm.process_idle_timeout`（默认 10s）回收：请求刚结束 1 个 → 6 秒后 1 个 → 14 秒后 **0 个**。

### 二、实测

脚本：`bench/fpm/01-modes.sh`、`bench/fpm/02-capacity.sh`、`bench/fpm/mem.php`。

#### 1. `max_children` 打满时：排队，而不是失败

`pm = static`，同时发 10 个耗时 2 秒的请求：

| `pm.max_children` | 10 个请求的返回耗时 |
| --- | --- |
| **2** | 2.00s ×2、4.00s ×2、6.00s ×2、8.00s ×2、10.00s ×2 |
| **10** | 全部 2.00s |

**全部返回 HTTP 200**，没有一个 502。`max_children = 2` 时请求被排成 5 批，第 10 个等了 10 秒。这是最关键的一条：**进程池不够表现为「响应时间线性劣化」，不是「报错」**，所以很容易被忽略到雪崩。

#### 2. 同一个 worker，四个不同的内存数字

全新 worker，只服务一次请求：

| 口径 | 空载请求 | 加载 500 个类文件（约 24 万行） |
| --- | ---: | ---: |
| `memory_get_usage(true)` | 2.0 MB | 10.5 MB |
| `VmRSS` | 27.8 MB | 72.3 MB |
| `Pss` | 11.6 MB | 54.4 MB |
| `Private_Dirty` | **1.0 MB** | **42.2 MB** |
| `Shared_Clean` | 10.6 MB | 10.9 MB |

四个数字都有道理，但用途不同：

- `memory_get_usage()` 只是 **Zend 分配器**的视角，看不到 PHP 二进制本身、扩展、mmap 的开销 → **估算容量时不能用**
- `VmRSS` 含共享内存，**多个 worker 会把同一块 opcache 重复计入** → 直接乘以 `max_children` 会严重高估
- `Pss` 把共享内存按共享进程数均摊 → 可用于整体容量核算
- `Private_Dirty` 是每个 worker **真正独占**的部分 → **估算 `max_children` 的分母用这个**

#### 3. ondemand 的 fork 开销：可以忽略

空闲 12 秒（让 ondemand 回收掉 worker）后的首个请求：

| 模式 | 空闲后首次请求 |
| --- | ---: |
| `static` | 3.8 ms |
| `ondemand` | 4.9 ms |

fork 本身只差约 1 ms。**ondemand 的真实代价不在 fork，而在应用层的冷启动**——`autoload`、数据库连接、本地缓存都要在新 worker 里重建。这在空应用里测不出来。

### 三、四个反直觉的点

**1. 进程池不够不会 502，只会变慢。** 上面的实测里 `max_children=2` 扛 10 个并发，全部成功返回，代价是最慢的请求等了 10 秒。这意味着**容量不足在监控上不是错误率上升，而是 P99 变长**——如果你的告警只看 5xx，会完全看不到。

**2. `dynamic` 的 `min_spare_servers` / `max_spare_servers` 不决定并发上限。** 它们管的是「空闲时留几个备用」，上限永远是 `max_children`。把它们当成并发配置来调是很常见的误解。

**3. 估算分母用 `memory_get_usage()` 会高估一个数量级。** 空载 worker 的 `memory_get_usage` 是 2 MB，实际 `VmRSS` 27.8 MB。按前者算容量，8 GB 内存敢开 4000 个 worker。

**4. `RSS × max_children` 不能超过总内存——这个算法本身是错的。** 因为 RSS 含 opcache 共享段（实测 10.6 MB），N 个 worker 共享同一份，重复计算 N 次。要乘的是 `Private_Dirty`。

### 四、估算方法

```
1. 量出单 worker 峰值 Private_Dirty
   cat /proc/<worker_pid>/smaps_rollup | grep Private_Dirty
   —— 一定要在「最重的那个接口」跑过之后量，空载值没有意义

2. 算：
   pm.max_children = (可用内存 − 系统预留 − 其他服务占用) ÷ 单 worker 峰值私有内存

3. 用 max_requests 兜底内存泄漏：
   pm.max_requests = 500 ~ 1000，worker 处理够 N 个请求就重启
```

以实测数据举例：机器 8 GB，留给 PHP 6 GB，单 worker 峰值私有 42 MB → `max_children ≈ 146`。若误用 `memory_get_usage` 的 10.5 MB，会算出 585，实际会 OOM。

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 三种模式怎么选？ | 内存充足、追求稳定延迟选 `static`；通用场景 `dynamic`；低频/内存紧张的服务（定时任务、内部后台）选 `ondemand` |
| `pm.max_requests` 干什么的？ | worker 处理够 N 个请求后自杀重建，用来兜住第三方库的内存泄漏。代价是反复 fork + 冷启动 |
| 什么时候真的会 502？ | FPM 进程全部卡死、`listen.backlog`（默认 511）也排满、FPM master 挂了、或 `request_terminate_timeout` 超时被 kill |
| 怎么监控进程池够不够？ | `pm.status_path` 暴露 `max children reached` 计数、`listen queue` 长度、`active processes`；这个指标比 5xx 更早预警 |
| `dynamic` 的参数怎么配？ | `start_servers` ≈ `max_children` 的 10%~20%，`min_spare` 保证突发流量，`max_spare` 别设太高否则空闲也吃内存 |
| 常驻内存框架（Swoole/Swoole）还需要 FPM 吗？ | 不需要，协程模型下并发能力由 `worker_num` + 协程调度决定，但内存泄漏是致命的，必须靠 `max_request` 和定期重启 |

---

## Q5. `==` 和 `===` 有什么区别？举几个 `==` 的经典坑。

### 结论

`===` 要求**类型相同、值相同**（对象还要求是同一个实例）；`==` 走一套「类型杂耍」的隐式转换规则。

但面试里真正的考点不是规则表，而是：**这套规则在 PHP 8.0 被改过**。同一行 `0 == "a"`，PHP 7.3 是 `true`，PHP 8.4 是 `false`。一次改动顺着 `in_array()`、`array_search()`、`switch`、`sort()` 四处扩散，而且**不报错、不提示、静默改结果**——升级时这是最难发现的一类 bug。

### 一、`==` 的判定顺序

```
两边都是 int / float ──────────────► 转 float 比数值        100 == 100.0   → true
        │
两边都是「数字字符串」─────────────► 比数值                "10" == "1e1"  → true
        │                                                  "1"  == "01"   → true
        │
任一边是 null / bool ──────────────► 两边都转 bool         null == ""     → true
        │                                                  "0"  == false  → true
        │
两边都是 array ────────────────────► 键值对逐一比较         [] == null     → true（null 先转 bool）
        │                            （键和顺序都要一致）
两边都是 object ───────────────────► 同类则比属性；=== 比实例
        │
其余：数字 vs 非数字字符串 ────────► ┌ PHP 8：把数字转成字符串，按字符串比   0 == "a"     → false
                                     └ PHP 7：把字符串转成数字，按数字比     0 == "a"     → true
                                       ↑↑ 8.0 的分水岭，叫「Saner string to number comparisons」
```

`===` 没有这张表：类型不同直接 `false`，没有任何转换。**唯一的例外是对象**——`===` 对同类对象比的是「是不是同一个实例」（`spl_object_id`），不是属性。

### 二、实测（PHP 7.3.33 vs PHP 8.4.25）

脚本：`bench/php/q5-compare-73-vs-84.php`（7.3 兼容语法，同一份代码跑两个版本）。
另外 `bench/q5-loose-compare.php` 是 8.4 单版本版（用了箭头函数，7.3 跑不了）。

#### 1. 有版本差异的（就是升级会炸的）

| 表达式 | PHP 7.3.33 | PHP 8.4.25 |
| --- | --- | --- |
| `0 == "a"` | **true** | **false** |
| `"abc" == 0` | **true** | **false** |
| `100 == "100abc"` | **true** | **false** |
| `0 == ""` | **true** | **false** |
| `INF == "INF"` | **false** | **true** |

#### 2. 没有版本差异的（以为会变、其实没变）

| 表达式 | 两个版本都是 | 说明 |
| --- | --- | --- |
| `"1" == "01"` | `true` | 都是数字字符串 → 比数值 |
| `"10" == "1e1"` | `true` | `1e1` 是合法数字字符串 |
| `100 == "1e2"` | `true` | 同上 |
| `"1 " == 1` / `" 1" == 1` | `true` | 前后空格都允许（8.0 的 numeric string 规则也允许尾空格） |
| `"1_0" == 10` | `false` | 下划线不是数字分隔符 |
| `null == false` / `null == ""` | `true` | |
| `"0" == false` / `"" == false` | `true` | 非空字符串里只有 `"0"` 是 falsy |
| `[] == false` / `[] == null` | `true` | 空数组转 bool 是 false |
| `100 == 100.0` | `true` | `==` 不做类型检查 |
| `(0.1+0.2) == 0.3` | `false` | 浮点误差，与版本无关 |
| `"0e1" == "0e2"` | `true` | 魔法哈希，见下 |

#### 3. 传染面：一处 `==` 语义，四个函数跟着变

| 用法 | PHP 7.3.33 | PHP 8.4.25 |
| --- | --- | --- |
| `in_array(0, ["a","b","1","01"])` | **true** | **false** |
| `array_search(0, ["a","b"])` | **0**（误命中下标 0） | **false** |
| `switch (0) { case "a": ... }` | 命中 `"a"` | 落到 `default` |
| `sort([10,"9","abc",2,"2"])` | `["9","abc",2,"2",10]` | `[2,"2","9",10,"abc"]` |
| `array_unique(["1","01",1,true])` | `["1","01"]` | `["1","01"]`（默认 SORT_STRING，无关） |
| `array_unique(..., SORT_REGULAR)` | `["1"]` | `["1"]` |

`switch` 用的是 `==`，所以 PHP 8 升级后 `switch (0)` / `switch ("")` 这类写法可能整段走进 `default`。7.3 那个 `sort()` 结果本身就不自洽（`"abc"` 排在 `2` 前面），8.4 才是「数字按数字比、数字与 `"abc"` 按字符串比」。

#### 4. 魔法哈希：PHP 8 没有修

| 表达式 | PHP 7.3.33 | PHP 8.4.25 |
| --- | --- | --- |
| `'0e12345' == '0e67890'` | **true** | **true** |
| `md5('240610708') == md5('QNKCDZO')` | **true** | **true** |
| `sha1('aaroZmOk') == sha1('aaK1STfY')` | **true** | **true** |
| `hash_equals('0e12345', '0e67890')` | `false` ✅ | `false` ✅ |

两个真实 md5 值（测出来的，不是我编的）：

```
md5('240610708') = 0e462097431906509019562988736854
md5('QNKCDZO')   = 0e830400451993494058024219903391
```

两边都被当成科学计数法 `0 × 10ⁿ = 0`，所以相等。**"PHP 8 修好了 `==`" 是错的**：PHP 8 修的是「数字 vs 非数字字符串」，两边**都是数字字符串**时照样比数值，魔法哈希活得好好的。密码 / 签名 / token 比较必须 `hash_equals()`（它还是恒定时间的，防时序攻击）。

#### 5. `strpos` 的坑和版本无关

```
strpos('abc', 'a') == false   → true    ← 两个版本都一样，误判为「没找到」
strpos('abc', 'a') === false  → false   ← 正确姿势
```

根因是 `0 == false` 恒为 `true`（bool 比较规则，PHP 8 没改）。

#### 6. `===` 自己的边界

| 表达式 | 两个版本都是 | 说明 |
| --- | --- | --- |
| `1 === 1.0` | `false` | 类型不同 |
| `0 === -0.0` | `false` | 类型不同 |
| `0.0 === -0.0` | `true` | 同类型同值（IEEE 754 里 `-0.0 == 0.0`） |
| `NAN === NAN` | `false` | NaN 不等于自己 |
| `[] === []` | `true` | 空数组类型和内容都相同 |
| `[1,2] === [2=>1,1=>2]` | `false` | 键不同 |
| 两个同值对象 `==` | `true` | 比属性 |
| 两个同值对象 `===` | `false` | 不是同一实例 |
| 同一实例 `===` | `true` | |

### 三、四个反直觉的点

**1. 「PHP 8 修好了 `==`」只对了一半。** 修的是「数字 vs 非数字字符串」这一格（`0 == "a"` → `false`），**数字字符串之间的比较没动**，所以 `"0e1" == "0e2"`、`md5(...) == md5(...)` 在 8.4 上照样是 `true`。把「升级到 PHP 8 就安全了」当成结论，是安全审计里最贵的一个误判。

**2. 一次语义变更会顺着四个函数扩散，而且没有任何报错。** `in_array` / `array_search` / `switch` / `sort` 内部都用 `==`。升级后 `in_array(0, $strArray)` 从「几乎永远 true」变成「false」，代码行为反转但日志里一个字都没有。这也是为什么 `in_array` **第三个参数 `strict` 应该默认写上**——它和版本无关，永远安全。

**3. 7.x 的 `sort()` 结果自身就不自洽。** 实测 `sort([10,"9","abc",2,"2"])` 在 7.3 得到 `["9","abc",2,"2",10]`——`"abc"` 排在了 `2` 前面。因为 7.x 里「int vs 非数字字符串」会按字符串比（`"10" < "abc"`），而「int vs 数字字符串」按数字比（`9 < 10`），两套规则混在一起，排序结果不满足传递性。PHP 8 统一成「数字与非数字字符串一律按字符串比」之后才自洽。

**4. `==` 在浮点上不可靠，`===` 也不行。** `(0.1+0.2) == 0.3` 是 `false`，`1 === 1.0` 也是 `false`，而 `NAN === NAN` 同样是 `false`。浮点比较必须用误差窗口（`abs($a - $b) < 1e-9`），这不是 `==` 还是 `===` 的选择题。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 日常比较 | **无脑用 `===`**。只有明确要类型杂耍时才用 `==`（如 `$x == null` 同时接住 `null` 和未定义，但更推荐 `??`） |
| `in_array` / `array_search` | 永远传第三个参数 `true` |
| 密码 / 签名 / token / 哈希比较 | `hash_equals()`，不要用 `==`，也不要用 `===`（时序攻击） |
| `strpos` / `strstr` 的返回值 | 一律 `!== false`；PHP 8 起可直接 `str_contains()` |
| `switch` 匹配数字 | 注意 `switch` 用的是 `==`；PHP 8 起可换 `match`（`match` 用 `===`） |
| 7.x → 8.x 升级 | 全局 grep `==`、`!=`、`in_array(`、`array_search(`、`switch`，逐个确认；重点看拿「函数返回值」和 `false`/`0`/`""` 比的地方 |
| 浮点比较 | 用误差窗口，别用 `==` / `===` |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| `match` 和 `switch` 的区别？ | `match` 用 `===` 严格比较、必须有返回值、无 `default` 时抛 `UnhandledMatchError`；`switch` 用 `==`、会穿透（忘写 `break`）、不返回值 |
| 为什么 `0 == "a"` 在 PHP 8 变成 `false`？ | RFC "Saner string to number comparisons"：数字与**非数字**字符串比较时，改为把数字转成字符串按字符串比 |
| `"1" == "01"` 为什么还是 `true`？ | 两边都是合法数字字符串，走数值比较分支，不是字符串比较 |
| `null == 0` 呢？ | `true`（都转 bool），所以 `if ($x == 0)` 在 `$x` 为 `null` 时也会进 |
| `==` 相比 `===` 有性能差异吗？ | 有但可忽略（`==` 要走类型转换分支）。选 `===` 是为了正确性，不是为了性能 |
| 数组比较的操作符有哪些差异？ | `==` 只比键值对（顺序无关、类型可松）；`===` 还要求顺序和类型一致；`+` 是并集（左边优先，不覆盖） |
| 对象比 `==` 会递归吗？ | 会，属性逐个比（嵌套对象递归比），循环引用有防护；这也是 `==` 比对象慢的原因 |

---

## Q6. 抽象类、接口、Trait 三者的区别与选择依据？

### 结论

三者**不在同一个维度上**，所以「哪个更好」是个错问题：

| | 回答的问题 | 本质 |
| --- | --- | --- |
| **抽象类** | 「**是什么**」+ 共享实现 | 一个不完整的类，**单继承**，能存状态 |
| **接口** | 「**能做什么**」 | 一份契约，**多实现**，默认不能存状态 |
| **Trait** | 「**这段代码借你用**」 | 编译期的**复制粘贴**，多引入、能存状态，**但它不是类型** |

一句话记：抽象类是**纵向**的（继承链上的「是一个」），接口是**横向**的（能力上的「能当」），Trait 是**代码复用**的（既不是纵向也不是横向，`instanceof` 都不认）。

### 一、三者的能力边界

```
         ┌────────────────────────┬──────────────┬───────────┬──────────┐
         │                        │  abstract    │ interface │  trait   │
         ├────────────────────────┼──────────────┼───────────┼──────────┤
  状态   │ 实例属性 / 静态属性     │     ✅       │  ❌(8.4*)  │    ✅    │
         │ 构造函数               │     ✅       │     ❌     │    ✅    │
         ├────────────────────────┼──────────────┼───────────┼──────────┤
  契约   │ 常量                   │     ✅       │     ✅     │  ✅(8.2+)│
         │ 抽象方法               │     ✅       │  ✅(隐式)   │    ✅    │
         │ 带方法体的方法          │     ✅       │     ❌     │    ✅    │
         │ 静态方法               │     ✅       │  ✅(只声明) │    ✅    │
         ├────────────────────────┼──────────────┼───────────┼──────────┤
  组合   │ 能继承几个             │  1（extends） │ 多个       │ 多个     │
         │ 能否被实例化            │     ❌       │     ❌     │    ❌    │
         │ 是不是「类型」          │     ✅       │     ✅     │  ❌(**） │
         └────────────────────────┴──────────────┴───────────┴──────────┘

  * interface 直到 8.3 都不能有属性；PHP 8.4 起可以声明「带 hook 的属性」（见下）
  ** `$obj instanceof SomeTrait` 恒为 false；trait 也不能当类型声明用
```

### 二、实测（PHP 8.4.25 / 8.2.33 / 7.3.33）

脚本：`bench/php/q6-oop-boundaries.php`（8.4 主测，含合法能力与非法能力的 PHP 原文报错）、
`bench/php/q6-oop-73-vs-84.php`（7.3 兼容语法，跑 7.3.33 / 8.2.33 / 8.4.25 三档）。
非法写法会触发 Fatal error（不可 catch），每条都丢进 `php -r` 子进程，抓 PHP 自己的报错原文。

#### 1. 合法能力：真的声明出来，再用反射验证

一个类同时吃到父类 + 接口 + trait 后，反射看到的东西：

```
Impl extends Abs implements Iface { use T; }

get_parent_class()            → Abs
getInterfaceNames()           → Iface
getTraitNames()               → T
getConstants()                → TYPED, TC, IVERSION, ITYPED      ← 父类常量 + trait 常量 + 接口常量，全在
getProperties()               → prop, staticProp, name, tp, tsp  ← 父类属性 + 提升属性 + trait 属性
(new Impl)->hi()              → "trait body"
```

`ReflectionMethod` 眼里 trait 方法的归属（这条最容易记错）：

| 反射调用 | 值 |
| --- | --- |
| `(new ReflectionMethod(Impl::class,'hi'))->getDeclaringClass()->getName()` | **`Impl`**（不是 `T`） |
| `->getFileName()` | `q6-oop-boundaries.php`（指向 trait 定义所在文件） |
| `->getStartLine()` | `47`（trait 里那一行） |
| `(new ReflectionMethod(Impl::class,'body'))->getDeclaringClass()->getName()` | `Abs`（父类方法才报父类） |

组合上限实测：`1 个父类 + 3 个接口 + 3 个 trait + 自己的方法 = 6 个方法、3 个常量`，`method_exists('Combiner','ta')` 为 `true`——trait 的方法就是「长在类上的方法」，没有任何运行时痕迹。

#### 2. 非法能力：PHP 报错原文（PHP 8.4.25）

| 写法 | PHP 原文报错 |
| --- | --- |
| `new` 抽象类 | `Fatal error: Uncaught Error: Cannot instantiate abstract class A` |
| `new` 接口 | `Fatal error: Uncaught Error: Cannot instantiate interface I` |
| `new` trait | `Fatal error: Uncaught Error: Cannot instantiate trait T` |
| 接口声明实例属性 | `Fatal error: Interfaces may only include hooked properties` |
| 接口声明静态属性 | `Fatal error: Interfaces may only include hooked properties` |
| 抽象类里 `abstract private function` | `Fatal error: Abstract function A::f() cannot be declared private` |
| 接口方法带方法体 | `Fatal error: Interface function I::f() cannot contain body` |
| 接口里 `protected function` | `Fatal error: Access type for interface method I::f() must be public` |
| `class C extends A, B` | `Parse error: syntax error, unexpected token ",", expecting "{"` |
| `instanceof` 一个 trait | `bool(false)`（不报错，但永远是 false） |
| trait 当类型声明 | `TypeError: g(): Argument #1 ($x) must be of type T, C given` |

#### 3. `use T1, T2` 的冲突规则：什么会炸、什么静默

| 冲突形态 | 结果 |
| --- | --- |
| 方法同名（T1/T2 都有 `f()`） | `Fatal error: Trait method T2::f has not been applied as C::f, because of collision with T1::f` |
| 静态方法同名 | 同上，报的一模一样 |
| **属性**同名，定义完全一致（同可见性同类型同默认值） | ✅ 合法，不报错 |
| **属性**同名，默认值不同 | `Fatal error: T1 and T2 define the same property ($x) in the composition of C. However, the definition differs and is considered incompatible.` |
| **属性**同名，可见性不同（`public` vs `protected`） | 同上（同一个报错文案） |
| **属性**同名，类型不同（`int` vs `string`） | 同上 |
| **常量**同名同值 | ✅ 合法 |
| **常量**同名不同值 | `Fatal error: T1 and T2 define the same constant (C) in the composition of C. ...` |
| trait 常量 vs **类自身**常量同名不同值 | `Fatal error: C and T1 define the same constant (C) in the composition of C. ...` |
| trait 常量 vs **父类**常量同名不同值 | `Fatal error: A and T define the same constant (V) in the composition of C. ...` |
| trait 常量 vs **接口**常量同名不同值 | ✅ **合法，trait 的值生效**（接口 `V=9`、trait `V=2` → `C::V == 2`） |
| **类自身**方法与 trait 方法同名 | ✅ 合法，**类自己的方法赢**（返回 `2` 而不是 trait 的 `1`） |
| trait 声明抽象方法、使用类不实现 | `Fatal error: Class C contains 1 abstract method and must therefore be declared abstract or implement the remaining methods (C::f)` |

冲突解决（`insteadof` / `as`）实测：

```php
use T1, T2 {
    T1::hello insteadof T2;        // 显式选 T1，冲突消失
    T2::hello as hello2;           // 别名：T2 的实现留下来，换个名字
    T1::only1 as protected hidden; // as 还能改可见性
}
```

```
->hello()   = "T1::hello"
->hello2()  = "T2::hello"
->hidden()  → ReflectionMethod::isProtected() = true
只 as 不 insteadof → 仍然 Fatal（别名不解决冲突，只多给一个名字）
单个 trait 也能 as 改名：a() 和 b() 都是同一个实现
```

#### 4. PHP 8.4 的新变化：接口能有属性了

| 写法 | PHP 8.4.25 |
| --- | --- |
| `interface I { public string $name { get; } }` | ✅ `接口可以有属性了` |
| `interface I { public string $name; }`（裸属性） | ❌ `Fatal error: Interfaces may only include hooked properties` |
| `abstract class A { abstract public string $name { get; } }` | ✅ 合法 |
| 实现类用**普通属性**满足接口的 `{ get; }` | ✅ `PlainUser->name = 'tom'` 正常读写 |
| 实现类用 hook 属性（`get => strtoupper(...)`） | ✅ 合法；读未初始化属性抛 `Error: Typed property HookedUser::$name must not be accessed before initialization` |

#### 5. 三个版本的报错文案差异（7.3.33 / 8.2.33 / 8.4.25）

| 能力 | PHP 7.3.33 | PHP 8.2.33 | PHP 8.4.25 |
| --- | --- | --- | --- |
| 接口声明属性 | `Interfaces may not include member variables` | `Interfaces may not include properties` | `Interfaces may only include hooked properties`（**合法**，仅限带 hook） |
| 接口方法 `protected` | `Access type for interface method I::f() must be omitted` | `...must be public` | `...must be public` |
| `class C implements I { const V = 2; }`（覆盖接口常量） | ❌ `Fatal error: Cannot inherit previously-inherited or override constant V from interface I` | ✅ `C::V == 2` | ✅ `C::V == 2` |
| trait 里定义常量 | ❌ `Fatal error: Traits cannot have constants` | ✅ 支持 | ✅ 支持 |
| trait 方法冲突报错 | `Trait method f has not been applied, because there are collisions with other trait methods on C` | `Trait method T2::f has not been applied as C::f, because of collision with T1::f` | 同 8.2 |
| trait 抽象方法 / 静态方法 / 属性 / 构造函数 | ✅ 都支持（实测） | ✅ | ✅ |

### 三、四个反直觉的点

**1. Trait 不是类型，`instanceof` 永远不认它。** 实测 `(new C) instanceof T` → `bool(false)`，`function g(T $x)` 直接 `TypeError`。所以「用 Trait 替代接口做类型约束」是行不通的：Trait 只解决代码复用，**契约能力一点都没有**。要约束就得接口 + Trait 一起上（接口给类型，Trait 给实现）。

**2. Trait 的冲突规则里，「同名」不是问题，「定义不一致」才是。** 两个 trait 定义完全相同的属性/常量是**合法**的（实测 ✅，不报错）——PHP 把它们当成同一个声明。所以两个 trait 各自 `public int $id = 0;` 可以共存，但只要一个写 `= 1`、或者一个 `protected`、或者一个 `int` 一个 `string`，立刻 Fatal，而且报错文案一模一样（`...the definition differs and is considered incompatible`），看不出到底哪不一样。方法冲突反而是无条件的：同名就 Fatal，必须 `insteadof`。

**3. 同一个常量名，来自「接口」和来自「父类」，待遇完全相反。** 实测：trait 常量与接口常量同名不同值 → **合法，trait 赢**（`C::V == 2`）；trait 常量与父类常量同名不同值 → **Fatal**。而 `class C extends A implements I` 里父类常量和接口常量同名 → 也是 **Fatal**（`Class C inherits both A::V and I::V, which is ambiguous`）。接口常量在继承体系里是最「弱」的一档，弱到可以被静默覆盖;父类常量则一步不让。

**4. 「实现类不能覆盖接口常量」已经过期了。** 很多资料还写着这是铁律。实测：7.3.33 上是 Fatal，8.2.33 和 8.4.25 上**合法**且覆盖生效（`C::V == 2`）。所以看到老资料里的这句话，先确认版本。

### 四、实战结论

| 场景 | 选择 |
| --- | --- |
| 多个类共享同一套「是什么」+ 有状态、有构造逻辑 | **抽象类**（单继承，别浪费在纯契约上） |
| 定义「能做什么」，要被无关的类实现（`Logger`、`Cache`、`Queue`） | **接口**（多实现，能当类型用，能写进类型声明） |
| 纯粹的代码复用，不需要类型约束（`HasTimestamps`、`SoftDeletes`） | **Trait**（注意：不能当类型，冲突要显式 `insteadof`） |
| 既要类型约束又要共享实现 | **接口 + Trait 组合**：接口定义契约，Trait 提供默认实现，类 `implements` + `use` |
| 想让子类必须实现某方法，但方法本身有通用逻辑 | 抽象类：模板方法模式（`abstract` 声明 + 具体方法里调用） |
| 需要横向组合多个能力（`Countable` + `JsonSerializable` + `ArrayAccess`） | **接口**，类可以 `implements` 任意多个 |
| 一个能力要在**多个继承链**上复用 | **Trait**（抽象类做不到：横向跨继承链只能靠 Trait） |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| Trait 底层是怎么实现的？ | 编译期把方法/属性**复制**进使用它的类（`zend_do_bind_traits`），运行时没有任何额外层级；反射 `getDeclaringClass()` 返回的是**使用类**（实测） |
| Trait 能访问类的 `private` 成员吗？ | 能，因为它就是类的一部分（同一作用域） |
| 抽象类能实现接口吗？ | 能。常见套路：抽象类 `implements` 接口只是声明契约，把具体实现留给子类 |
| 接口能继承接口吗？ | 能，而且**可以多继承**（实测通过） |
| 抽象类的方法可以是 `private abstract` 吗？ | 不能，`Abstract function A::f() cannot be declared private`（私有方法子类访问不到，抽象就没意义了） |
| Trait 方法的优先级？ | 类自身方法 > Trait 方法 > 父类方法（实测：类自己的 `f()` 赢过 trait 的） |
| `use T1, T2 { ... }` 里 `as` 会新增还是覆盖？ | `as` 只**新增别名**，原名仍然存在；要消冲突必须 `insteadof`（实测：只 `as` 仍然 Fatal） |
| Trait 和「组合（composition）」的关系？ | Trait 是**水平复用**，不是组合。真组合是持有对象引用、走接口转发；Trait 是把代码织进来，耦合更紧、更"隐式" |
| PHP 8.4 的 property hooks 让接口能声明属性了，是否意味着接口能存状态？ | 不能。接口只能声明**带 hook 的属性**（实测裸属性仍然 Fatal），且实现类可以用普通属性满足它；接口本身依然不存储任何状态 |

---

## Q7. 依赖注入和 IoC 容器解决了什么问题？容器如何实现自动装配？

### 结论

这两个词经常被混着说，其实是两件事：

| 概念 | 解决的问题 | 一句话 |
| --- | --- | --- |
| **依赖注入（DI）** | 依赖**从哪来** | 需要什么，就让别人从构造函数塞进来，而不是自己 `new` |
| **IoC 容器** | **谁来 new** | 一个能按类型递归解析依赖图的工厂，把 `new` 集中到一处 |

DI 是**写法**（构造函数注入），容器是**工具**。没有容器也能 DI（手工在入口文件里装配），没有 DI 也能用容器（当服务定位器用，反模式）。

**自动装配（autowiring）= 用 Reflection 读构造函数的参数类型，按类型递归地把依赖构造出来。** 核心就三件事：`ReflectionClass::getConstructor()` → `getParameters()` → `getType()` 拿到类名，然后递归 `get()`。

### 一、从硬编码到容器

```
① 硬编码：依赖方向是「向外抓」                    ② DI：依赖方向是「向内要」
   ┌──────────────────┐                            ┌──────────────────┐
   │ UserController   │                            │ UserController   │
   │  __construct() { │                            │  __construct(    │
   │    $this->svc =  │                            │    UserService   │
   │      new User-   │                            │      $service)   │  ← 只声明「我要什么」
   │        Service(  │                            └────────┬─────────┘
   │          new Con-│                                     │ 谁给？→ 容器
   │            nection(...)) │                             ▼
   │  }               │                            ┌──────────────────────────┐
   └──────────────────┘                            │ Container::get(User-     │
     ↑ 想换一个 mock 只能改源码                       │   Controller::class)     │
                                                     │  ├ 反射构造函数           │
                                                     │  ├ 见到 UserService       │
                                                     │  │   └ 递归 get(UserService)
                                                     │  │       └ 见到 UserRepository → Connection → Logger
                                                     │  └ new UserController(…)  │
                                                     └──────────────────────────┘
```

真正的收益：**可测试**（注入 mock，不用改源码）、**可替换**（接口绑定实现，配置里换一行）、**集中管控**（生命周期、单例、装饰器都在容器一处）。

### 二、实测（PHP 8.4.25）

脚本：`bench/php/q7-container.php`（手写最小容器，约 70 行，含全部测量）；`bench/php/q7-naive-recursion.php`（环依赖的对照组）。

#### 1. 自动装配：一条 4 层依赖链，全程没有一处 `new`

```
容器里只注册了两条绑定：
    singleton(Logger::class, FileLogger::class)     // 接口 → 实现
    bind(Connection::class, Connection::class)      // 具体类 → 自己（可省）

get(UserController::class)
  → UserController → UserService → UserRepository → Connection → FileLogger
  接口自动换成了绑定的实现：UserService::$logger 是 FileLogger
```

`Connection::__construct(string $dsn = 'sqlite::memory:', ?Logger $logger = null)` 这种混合签名也能装：**标量走默认值，接口走绑定，可空且没绑定的回退到默认值 `null`**。

#### 2. 装配不了的时候，报什么错（四种情况）

| 情况 | 实测结果 |
| --- | --- |
| 标量参数、没有默认值 | `无法自动装配: NeedsScalar::$host 是标量/无类型参数且没有默认值，容器不知道注入什么` |
| 依赖的接口没有绑定实现 | `无法自动装配: MailService::$mailer 依赖接口 Mailer，容器里没有绑定实现，且没有默认值` |
| **union type 参数**（`FileLogger\|UserService $dep`） | 被当成「标量/无类型」，报同样的错（`ReflectionUnionType` 不是 `ReflectionNamedType`，朴素容器直接瞎了） |
| **可变参数**（`FileLogger ...$loggers`） | **不报错**，但只注入了 **1 个**实例（可变参数被当普通参数处理）——最危险的一种 |
| 类名不存在 | `无法自动装配: 类 NoSuchClass 不存在`（若不显式检查，`new ReflectionClass` 会抛 `ReflectionException`） |

#### 3. singleton vs 每次新造

| 容器配置 | `get(UserController)` 两次之后 |
| --- | --- |
| `Logger` 注册为 singleton、其余不注册 | `UserService` 造了 **2** 次（每次 get 都重建图），但 `FileLogger` 复用 |
| 全部 `bind`（都不共享） | `UserService` 造了 **2** 次、`FileLogger` 造了 **4** 次（每次解析图中 2 处需要 Logger），两个 `UserController` **不是**同一实例 |

#### 4. 循环依赖

```
带检测的容器：  循环依赖: A → B → A          ← 显式抛 RuntimeException
```

不带检测的容器（对照组，`bench/php/q7-naive-recursion.php`）：

| 版本 | 结果 |
| --- | --- |
| PHP 8.4.25 | `Fatal error: Uncaught Error: Maximum call stack size of 8339456 bytes (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?`，崩之前递归到第 **22,600** 层 |
| PHP 7.3.33（`memory_limit=64M`） | 没有栈检查，先 `Allowed memory size of 67108864 bytes exhausted`，递归到第 **43,170** 层 |

PHP 8.3 起新增的 `zend.max_allowed_stack_size`（默认 `0`＝自动，实测 8.4.25 下上限 8,339,456 B ≈ 8 MB）把「无限递归」变成了一条明确报错；7.3 上同样的代码只会闷头把内存吃光（`zend.max_allowed_stack_size` 在该版本不存在，实测 `ini_get()` 返回 `false`）。

#### 5. 开销：容器到底慢多少

解析 `UserController` 一次＝构造 5 个对象，N = 100,000 次。宿主机上有其他容器抢 CPU，所以 5 轮**交错轮转**跑、各配置取最小值（顺序跑会让某一组独吞一段抖动）：

| 配置 | 最小耗时 (ms) | µs/次 | 相对直接 new |
| --- | ---: | ---: | ---: |
| 直接 `new`（人工装配） | 112.90 | 1.13 | 1.0× |
| 容器，每次现算 Reflection | 1,900.50 | 19.01 | **11.4 ~ 16.8×** |
| 容器，缓存解析计划 | 627.94 | 6.28 | **5.6 ~ 6.7×** |
| 容器，全注册 singleton | 24.93 | 0.25 | **0.20 ~ 0.22×** |
| 纯 `ReflectionClass` 一次 | 45.48 | 0.45 | — |

同一次运行里 `ReflectionClass` 被构造的次数：不缓存 **3,000,000** 次（10 万次解析 × 6 个类 × 5 轮），缓存后 **5** 次。

三次独立运行（`q7-container.php` 连跑三遍）的比值分别是 `11.9/5.9/0.20`、`11.4/6.7/0.22`、`16.8/5.6/0.22`——**比值比绝对值稳**，绝对 µs 在共享机器上能飘 2 倍（直接 new 一列实测 1.13 ~ 2.46 µs/次）。

### 三、四个反直觉的点

**1. 容器开销的大头不是 Reflection。** 纯 `ReflectionClass` 一次只要 **0.45 µs**，而不缓存反射的容器一次解析要 **19 µs**——差了 40 倍。也就是说容器慢的原因**不是「反射很慢」**，而是「每解析一次就把整张对象图重新走一遍、重新 new 一遍」。把解析计划缓存下来（实测 19.0 → 6.3 µs）也只解决了一小半；**真正的大头是「重复建对象」**，靠的是 singleton（实测再降到 0.25 µs，比手工 `new` 还快，因为它只是查表返回）。

**2. 全 singleton 之后，容器比直接 `new` 还快。** 实测 0.25 µs/次 vs 1.13 µs/次（约 **0.2 倍**）。原因很简单：`new` 一次要分配对象、跑构造函数，容器只是数组查表。**但这是有代价的**——singleton 意味着对象跨请求存活，任何可变状态（当前用户、事务句柄、缓冲区）都会串味。常驻进程里「容器 + 全单例 + 有状态服务」是内存泄漏和脏数据的第一大来源。

**3. 自动装配的边界比想象中窄。** 实测四类装不了：标量参数（没有默认值）、未绑定的接口、union type（朴素实现直接看不见）、可变参数（**不报错，但只塞一个**）。所以「零配置自动装配」在生产里必然需要补一份显式绑定：标量用配置、接口用实现、`union` 和 `variadic` 只能写工厂闭包。**框架里的 `bind()`/`singleton()`/`when()->needs()` 都是给这些边界打的补丁**，不是多余的抽象。

**4. 循环依赖必须容器自己查，PHP 不会帮你兜底。** 实测不带检测的容器会一路递归到栈上限（8.4 上 22,600 层）或内存耗尽（7.3 上 43,170 层）才崩，报错信息是「Maximum call stack size ... Infinite recursion?」，**完全看不出是哪个类绕成了环**。容器在解析时维护一个「正在构造」的集合（实测报错 `循环依赖: A → B → A`，直接点出环路的完整路径），这是容器必备而不是可选的功能。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 业务代码 | 构造函数注入 + 依赖接口/抽象类型声明，**永远不要**在构造函数里 `new` 协作者 |
| 无状态服务（Logger、HTTP Client、Config、Repository） | 注册成 **singleton**，省掉重复建对象 |
| 有状态对象（当前用户、请求上下文、DB 事务、状态机） | 必须每次新造；常驻进程里尤其要注意，别把请求级状态单例化 |
| 标量配置（dsn、超时、密钥） | 显式绑定（配置数组 / 工厂闭包），不要指望自动装配 |
| 接口 → 实现 | 显式 `bind`。自动装配能递归，但**猜不出你想用哪个实现** |
| 需要 AOP / 装饰器 / 代理 | 在容器里包一层（装饰器模式），业务类无感知 |
| 容器自身的性能 | 缓存解析计划（用 `ReflectionClass` 的 `getConstructor()->getParameters()` 结果），并对无状态服务用单例。**在 FPM 下这些开销可以不管**（一次请求只解析几次），**在 Swoole/常驻进程里必须管** |
| 排查「容器装不上」 | 先看构造函数有没有标量参数或 union type；再看接口有没有绑定；最后看有没有环 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| DI 和「服务定位器」的区别？ | 注入是依赖**显式出现在构造函数**上，定位器是类内部主动去容器里 `get()`。前者依赖关系一眼可见、可测试；后者把依赖藏进实现里，是反模式 |
| 容器是单例模式吗？ | 容器自己通常全局唯一，但它**管理的对象**未必；「singleton」在容器里指的是「共享同一实例」，与设计模式的单例（`getInstance()`）不是一回事 |
| 自动装配怎么处理接口？ | 装不了——接口没有实现。必须显式绑定（`bind(Logger::class, FileLogger::class)`），或者用「约定优先」的框架规则（Laravel 会尝试 `Logger` → 无实现时报错） |
| 构造器注入 vs Setter 注入 vs 属性注入？ | 构造器注入是唯一能保证「对象创建完就是完整可用的」，其余都是可选依赖的补充。属性注入（反射直接写属性）会绕过类型和不变式，不推荐 |
| 容器怎么支持「同一个接口在不同类里注入不同实现」？ | 上下文绑定（Laravel 的 `when(Controller::class)->needs(Logger::class)->give(FileLogger::class)`）。本质是给「解析键 = 消费方 + 类型」再加一维 |
| 用容器会不会影响性能？ | 实测 FPM 场景可以忽略（一次请求解析几次，µs 级）；常驻进程里要么缓存解析计划 + 单例（实测可低于手工 `new`），要么干脆在启动时把对象图预热好 |
| 什么是「编译期容器」？ | PHP-DI / Symfony 的 `ContainerBuilder` 会把整个依赖图在构建阶段生成成一个静态 PHP 类（`var/cache/.../Container.php`），运行时不再反射，直接 `new`。这是把「运行时反射」的成本挪到「部署时」 |
| 循环依赖一定是坏设计吗？ | 通常是。A 依赖 B、B 依赖 A 说明职责边界没划清。少数（如事件总线、中间件链）确实需要，解法是**延迟注入**（注入闭包 / `Closure` 或容器本身），把「构造时机」与「调用时机」分开 |

---

## Q8. PHP 8 带来了哪些重要变化？升级要注意什么？

### 结论

PHP 8 不是一个版本，是**从 8.0 到 8.4 的五个版本**，变化可以归成三件事：

| 方向 | 内容 | 升级时的性质 |
| --- | --- | --- |
| **类型系统补全** | union → intersection → DNF、`readonly`、枚举、类型化常量、property hooks | 新代码的红利，旧代码不受影响 |
| **错误模型换代** | 一堆 Warning/Notice 变成 `Error`/`TypeError`，字符串↔数字比较语义翻转 | **真正会炸的就是这一层** |
| **性能与内存** | packed array（顺序整数键内存减半）、JIT、栈上限检查 | 白拿的收益，但别信「普适加速」 |

升级要记住的一句话：**语法是加法（旧代码照跑），错误模型是减法（旧代码会炸）**。所以升级的准备工作不是学新语法，而是把「以前只是 Warning、现在会抛异常」的地方找出来——重点是未定义常量/变量、除零、内部函数参数、松比较。

### 一、变化地图（按起始版本）

```
7.3.33 ───► 7.4.33 ───► 8.0.30 ───► 8.1.34 ───► 8.2.33 ───► 8.3.33 ───► 8.4.25
   │           │            │            │            │            │            │
   │           │            │            │            │            │            └ property hooks
   │           │            │            │            │            │              非对称可见性 private(set)
   │           │            │            │            │            │              new 不带括号调用
   │           │            │            │            │            │              array_find / array_any / array_all
   │           │            │            │            │            │              #[\Deprecated] / mb_ucfirst
   │           │            │            │            │            └ 类型化类常量 const string S = 's'
   │           │            │            │            │              json_validate / mb_str_pad / #[Override]
   │           │            │            │            │              zend.max_allowed_stack_size（栈上限检查）
   │           │            │            │            └ trait 里能有常量 / readonly class
   │           │            │            │              DNF 类型 (A&B)/C / 独立 null·false·true 类型
   │           │            │            │              Random\Randomizer / #[AllowDynamicProperties]
   │           │            │            │              ⚠ 弃用最多的一档：null 传内部函数、浮点数组键、
   │           │            │            │                ${var} 插值、Serializable、strftime、utf8_encode
   │           │            │            └ readonly 属性 / enum / never / new 出现在参数默认值
   │           │            │              纯交集类型 A&B / 一等公民可调用 strlen(...)
   │           │            │              array_is_list / enum_exists
   │           │            └ nullsafe ?-> / match / 命名参数 / 构造器属性提升 / Stringable
   │           │              str_contains / str_starts_with / get_debug_type / fdiv
   │           │              ⚠ 错误模型换代：未定义常量→Error、除零→DivisionByZeroError、
   │           │                内部函数参数严格化、字符串↔数字比较语义翻转
   │           │              packed array：顺序整数键内存 33.6 → 16.8 B/元素
   │           └ 箭头函数 fn()（7.3 上是 ParseError）
   └ 起点
```

错误模型的差别，画成流程就是：

```
                      PHP 7.3                              PHP 8.4
未定义常量     $x = UNDEFINED_CONST;                 Error: Undefined constant
                    ↓ 结果                            （不可继续）
               $x = 'UNDEFINED_CONST'  ← 字符串！
               程序带着错值继续跑 —— 最难查                  ↓
                                                     当场炸，栈里就能定位

1/0             INF + Warning                        DivisionByZeroError
count(1)        1   + Warning                        TypeError
strlen([])      null + Warning                       TypeError
"abc" + 1       1   + Warning                        TypeError
0 == "a"        true   ← 松比较往数字拐                false  ← 改成按字符串比
```

### 二、实测

脚本（都在 `bench/php/` 下）：`q8-feature-matrix.php` + `.sh`、`q8-upgrade-traps.php` + `.sh`、`q8-behavior-73-vs-84.php`、`q8-mem-perf-73-vs-84.php`、`q8-throughput-interleaved.php` + `.sh`、`q8-const-fold.php`；另外复用了题目给的 `bench/q8-php7-vs-8.php`。跑测的版本：**7.3.33 / 7.4.33 / 8.0.30 / 8.1.34 / 8.2.33 / 8.3.33 / 8.4.25**（7 个版本都是真跑的，不是查文档）。

#### 1. 特性起始版本（每个特性在 7 个版本上各探测一次）

| 起始版本 | 实测从这一版开始可用的东西 |
| --- | --- |
| **7.4** | 箭头函数 `fn()`（7.3 上是 ParseError） |
| **8.0** | `?->`、`match`、命名参数、构造器属性提升、`str_contains`、`str_starts_with`、`get_debug_type`、`fdiv`、`preg_last_error_msg`、`Stringable` |
| **8.1** | `readonly` 属性、`enum`、`never`、`new` 出现在参数默认值、纯交集类型 `A&B`、一等公民可调用 `strlen(...)`、`array_is_list`、`enum_exists` |
| **8.2** | trait 里能有常量、`readonly class`、独立 `null`/`false`/`true` 类型、**DNF 类型 `(A&B)\|C`**、`Random\Randomizer`、`#[AllowDynamicProperties]`、`#[SensitiveParameter]` |
| **8.3** | 类型化类常量 `const string S = 's'`、`json_validate`、`mb_str_pad`、`#[Override]`、`zend.max_allowed_stack_size` |
| **8.4** | property hooks、非对称可见性 `private(set)`、`new` 不带括号调用、`array_find`/`array_any`/`array_all`、`#[\Deprecated]`、`mb_ucfirst`（另外 `E_STRICT` 常量在这一版开始报 Deprecated——它在 7.3~8.3 上都还好好的） |
| **移除** | `$s{0}`（7.4 弃用 → 8.0 移除）、`create_function`/`each`/`get_magic_quotes_gpc`/`money_format`（8.0 移除）、`image2wbmp`（7.4 移除） |

**一句提醒：别背这张表。** 我原以为 DNF 类型是 8.3 才有的，实测 **8.2 就完整支持**，而且语义是对的：

```
PHP 8.2.33  function t((IA&IB)|KC $x)
  传实现 IA+IB 的类  → accepted
  传只实现 IA 的类    → TypeError: ... must be of type (IA&IB)|KC, KA given
  传 KC              → accepted
```

版本边界靠记忆写，迟早错一小格；靠脚本探，永远是对的。

#### 2. 错误模型：7.3.33 vs 8.4.25 逐条对照（同一段代码，两个版本各跑一遍）

| 表达式 | 7.3.33 | 8.4.25 |
| --- | --- | --- |
| `1 / 0` | `INF` + `E_WARNING: Division by zero` | `DivisionByZeroError` |
| `0 / 0` | `NAN` + `E_WARNING` | `DivisionByZeroError` |
| `1 % 0` | `DivisionByZeroError` | `DivisionByZeroError`（**7.0 起就是异常，不是 8 的新变化**） |
| `"abc" + 1` | `1` + `E_WARNING: A non-numeric value encountered` | `TypeError` |
| `"5 apples" + 1` | `6` + `E_NOTICE`（注意是 Notice） | `6` + `E_WARNING`（升了一级） |
| `strlen([])` | `null` + `E_WARNING` | `TypeError` |
| `strlen(null)` | `0` | `0` + `E_DEPRECATED` |
| `strlen("a","b")` | `null` + `E_WARNING` | `ArgumentCountError` |
| `count(null)` | `0` + `E_WARNING` | `TypeError` |
| `count(1)` | `1` + `E_WARNING` | `TypeError` |
| `array_key_exists($k, $obj)` | `true`（**连警告都没有**） | `TypeError` |
| `implode($arr, '-')`（旧参数序） | `'a-b'` | `TypeError` |
| 未定义常量 | `'UNDEFINED_CONST'`（字符串！）+ `E_WARNING` | `Error: Undefined constant` |
| 未定义变量 | `null` + `E_NOTICE` | `null` + **`E_WARNING`**（升一级） |
| 未定义数组键 | `null` + `E_NOTICE: Undefined index` | `null` + `E_WARNING: Undefined array key` |
| `null` 上取属性 | `null` + `E_NOTICE` | `null` + `E_WARNING` |
| `"abc"[5]` | `''` + `E_NOTICE` | `''` + `E_WARNING` |
| `"abc"{0}` | `'a'` | `ParseError`（8.0 移除） |
| `sort()` 稳定性 | `'aisqomkgechjblfnpdrt'`（**不稳定**） | `'acegikmoqsbdfhjlnprt'`（稳定） |
| `create_function` | 能用 + `E_DEPRECATED` | `Error: Call to undefined function` |
| `each()` | 能用 + `E_DEPRECATED` | `Error: Call to undefined function` |
| `get_magic_quotes_gpc()` | `false` | `Error: Call to undefined function` |
| `money_format()` | 存在 | 不存在 |
| 对象当数组用 / 数组 + 整数 / 字符串 + 数组 | Notice/Warning，还能算出结果 | `Error` / `TypeError`（都不可 catch 之外，直接终止） |

`sort()` 那一行值得单独记住：**PHP 8.0 起排序是稳定排序**（实测 7.3 打乱成 `aisqomkgechjblfnpdrt`，8.4 保持原有相对次序 `acegikmoqsbdfhjlnprt`）。依赖「排序后同值元素的次序」的代码在 7.x 上是赌运气，8.x 上才第一次有了确定行为。

#### 3. 升级陷阱：哪个版本开始报、报的是弃用还是致命

| 操作 | 7.3.33 | 7.4.33 | 8.0.30 | 8.1.34 | 8.2.33 | 8.3.33 | 8.4.25 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 给未声明属性赋值 | OK | OK | OK | OK | **Deprecated** | Deprecated | Deprecated |
| 字符串 `"${foo}"` 插值 | OK | OK | OK | OK | **Deprecated** | Deprecated | Deprecated |
| 字符串 `"${$name}"` 动态插值 | OK | OK | OK | OK | **Deprecated** | Deprecated | Deprecated |
| `strlen(null)` 传 null 给内部函数 | OK | OK | OK | **Deprecated** | Deprecated | Deprecated | Deprecated |
| 数组键写成浮点 `$a[1.7]` | OK | OK | OK | **Deprecated** | Deprecated | Deprecated | Deprecated |
| 实现 `Serializable` | OK | OK | OK | **Deprecated** | Deprecated | Deprecated | Deprecated |
| `strftime()` | OK | OK | OK | **Deprecated** | Deprecated | Deprecated | Deprecated |
| `utf8_encode()` | OK | OK | OK | OK | **Deprecated** | Deprecated | Deprecated |
| 可选参数写在必填参数前 | OK | OK | **Deprecated** | Deprecated | Deprecated | Deprecated | Deprecated |
| 隐式可空参数 `int $x = null` | OK | OK | OK | OK | OK | OK | **Deprecated** |
| `#[\Attr]` 与 `class X` 写同一行 | **Error** | **Error** | OK | OK | OK | OK | OK |
| `assert("字符串")` | Deprecated | Deprecated | OK（**静默失效**） | OK | OK | OK | OK |
| `end()` 传非变量 | Notice | Notice | Notice | Notice | Notice | Notice | Notice |

**这张表最该看的两行是最后几行**：

- **弃用高峰在 7.4 和 8.1，不在 8.0。** 8.0 是把 Warning 变异常的一版（打碎），8.1/8.2 是加弃用的一版（漏水）。所以升级路线应该是 `7.3 → 7.4（清弃用）→ 8.x`，而不是一步跳到 8.x。
- **`end()` 传非变量那一行，我原以为 8.0 起是 Deprecated，实测 7.3–8.4 全程都只是 `Notice: Only variables should be passed by reference`**，而且返回值照样正确——它根本不在 8.0 的弃用清单里。又一次说明：清单要靠脚本核。
- `assert("字符串")` 更阴：7.3/7.4 上它**是生效的**（`assert("false")` 实测报 `E_WARNING: assert(): Assertion "false" failed`），8.0 起字符串断言不再被求值，`assert("$a == 1")` 变成「非空字符串 = 真」**恒真**，从此静默失效、再也不会失败。实测（两边 `zend.assertions` 都是 `1`）：

```
PHP 7.3.33  assert("false")  → E_WARNING: assert(): Assertion "false" failed
            assert(false)    → E_WARNING: assert(): assert(false) failed
PHP 8.4.25  assert("false")  → 什么都不报（字符串不求值，恒真）
            assert(false)    → AssertionError（assert.exception 默认值 0 → 1）
```

- `#[\Attr]` 与 `class X` 写同一行在 7.3/7.4 上是 Error，原因很朴素：**7.x 里 `#` 是行注释**，`#[AllowDynamicProperties] class C4b {}` 整行被当成注释，类根本没声明（第一版探测就是这么写的，7.3 上表现为「没输出、没报错、退出码 0」）。属性单独占一行就没问题（实测 7 个版本全 OK）。
- 动态属性的正解实测有效：把 `#[AllowDynamicProperties]` 加在类上，8.2.33/8.3.33/8.4.25 上都不再报 Deprecated。

#### 4. 内存：packed array 只在「顺序整数键」上减半

N = 100 万个元素，`memory_get_usage()` 差值（同时用差分法复核，两档规模 50 万 → 100 万，斜率一致）：

| 数组形态 | 7.3.33 | 8.4.25 | 结论 |
| --- | ---: | ---: | --- |
| 顺序整数键 `$a[] = $i` | 33.6 B/元素 | **16.8 B/元素** | 精确减半 |
| 追加写入 `$a[$i] = $i` | 33.6 | **16.8** | 同上 |
| 有间隔整数键 `$a[$i*2]` | 41.9 | 41.9 | **完全没变**（退化成哈希） |
| 短字符串键 `$a['k'.$i]` | 73.9 | 73.9 | 完全没变 |
| 32 字符串、互不相同 | 97.6 | 80.8 | 只降 17% |
| 32 字符串、字面量（共享串） | 33.6 | 16.8 | 降的是「数组桶」那部分 |
| 2 元素小数组的数组 | 409.6 | 392.8 | 几乎没变 |
| 3 属性对象 | 137.9 | 121.2 | 降 12% |

（`sprintf('%032d', $i)` 造字符串那一行实测 353.6 → 336.8 B/元素，比 `md5` / `str_repeat` 造的同样长度字符串贵约 4 倍——这是本机的一个反常现象，和版本无关，记在这里免得下次重新踩。）

**「内存减半」只对连续整数键成立**：41.9 和 73.9 这两行两个版本一模一样。哈希结构（`HashTable` + `Bucket`）该占多少还是多少，packed array 省的只是「有序表 + 哈希表」双份结构的其中一份。

#### 5. 吞吐：纯 PHP 循环快 1.3 倍左右，C 扩展函数没有可测差异

同一台机器（8 核，loadavg 5~8，还有别的容器在跑）两个版本交替跑 5 轮，取中位数：

| 项目 | 8.4.25 中位 [min~max] | 7.3.33 中位 [min~max] | 7.3/8.4 |
| --- | ---: | ---: | ---: |
| 取模累加 2000 万次 | 0.6452 s [0.5763~0.7368] | 0.8550 s [0.7610~1.2362] | **1.33×** |
| 数组写入 + 遍历 100 万 | 0.0682 s [0.0564~0.0729] | 0.0901 s [0.0834~0.1221] | **1.32×** |
| 字符串拼接 20 万次 | 0.0241 s [0.0183~0.0365] | 0.0312 s [0.0238~0.0366] | **1.29×** |
| `json_encode` 1000 键 × 200 | 0.0715 s [0.0547~0.0864] | 0.0664 s [0.0413~0.0905] | 0.93×（噪声内） |
| `json_decode` 同上 | 0.2597 s [0.2049~0.3121] | 0.2617 s [0.2217~0.3415] | 1.01×（噪声内） |
| `md5` × 20 万 | 0.0754 s [0.0628~0.0906] | 0.0758 s [0.0537~0.0795] | 1.01×（噪声内） |

**读法：纯 PHP 解释执行的循环快约三成，而 `json_*` / `md5` 这些 C 实现的函数在噪声范围内无法区分。** 这里必须说清方法：两个版本没法在同一进程里跑，宿主机又一直有负载，所以我把两个容器**交替**跑 5 轮、每格报中位数——第一版是「每行取 5 轮最小值」，结果 `json_decode` 那一行 7.3 的 5 个样本是 `0.1662 / 0.2947 / 0.3092 / 0.3139 / 0.3491`，最小值恰好是个离群点，直接得出「7.3 快 1.19 倍」的错误结论。**取最小值只适合排除「被拖慢」的样本，不适合被离群点决定的对比。**

#### 6. 常量折叠：同一个错误，7.3 死在编译期，8.4 死在运行期

`bench/php/q8-const-fold.php`（同一个文件，两个版本各跑一次）：

```
PHP 8.4.25   A: 这行能打出来，说明文件编译过了
             B: 函数定义完了，还没调用它
             Fatal error: Uncaught TypeError: Unsupported operand types: array + int

PHP 7.3.33   （什么输出都没有）
             Fatal error: Unsupported operand types in .../q8-const-fold.php on line 19
```

7.3 的编译器把 `array() + 1` 当常量表达式在**编译期**就折叠求值了，于是错误发生在「整个文件还没开始执行」的时候——连第一行 `echo` 都没跑。8.4 不再折叠，改成调用时才抛 `TypeError`。**这对升级的意义是：报错的时机层级会变**，有些原本「部署时立刻挂」的问题会推后到「某个请求里才挂」。

#### 7. 栈上限：8.3 起有了明确报错

`zend.max_allowed_stack_size` 这个 ini 实测在 **8.1.34 / 8.2.33 上不存在**（`ini_get()` 返回 `false`），**8.3.33 / 8.4.25 上是 `'0'`（自动）**。效果（`q7-naive-recursion.php`，无限递归的容器）：

| 版本 | 结果 |
| --- | --- |
| 8.4.25 | `Fatal error: Maximum call stack size of 8339456 bytes (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?`，崩之前递归到第 **22,600** 层 |
| 7.3.33（`memory_limit=64M`） | 先 `Allowed memory size of 67108864 bytes exhausted`，递归到第 **43,170** 层 |

#### 8. JIT / OPcache 的配置面差

| ini | 7.3.33 | 8.4.25 |
| --- | --- | --- |
| `opcache.jit` | `false`（不存在） | `'disable'` |
| `opcache.jit_buffer_size` | `false`（不存在） | `'64M'` |
| `opcache.enable` | `'1'` | `'1'` |
| CLI 下 `opcache_get_status()` 是否启用 | no | no |

（CLI 下 OPcache 都没启用，所以**这个环境里测不出 JIT 的加速比**，JIT 为什么在 Web 场景收益有限见 Q3。）

### 三、六个反直觉的点

**1. 同一个表达式，升级后真假翻转，而且不报任何错。** 实测 `0 == "a"` 在 7.3 是 `true`、8.4 是 `false`；`100 == "100abc"` 从 `true` 变 `false`；连带 `in_array(0, ["a","b"])` 从 `true` 变 `false`、`array_search(0, [...])` 从 `0` 变 `false`、`switch(0) case "a"` 从命中变成走 default（详见 Q5）。升级清单里其它项都是「会抛异常」，只有这一项是**静默改语义**——所以它最该被静态扫描（PHPStan 的 `strictComparison` / Rector 的相关规则）盯住。

**2. 「内存减半」只对顺序整数键成立。** 16.8 vs 33.6 B/元素是精确 2 倍，但同一台机器上「有间隔的整数键」两个版本都是 41.9、「短字符串键」都是 73.9，一个字节没省。所以别把「PHP 8 数组省一半内存」当成普适结论去申请降配——真正省下来的只发生在 `$a[] = ...` / `0..n-1` 连续键这种形态上（也就是绝大多数列表型数据，所以体感明显，但缓存类的大哈希表不会变）。

**3. 判据是「报错发生在哪一层」，不只是「报不报」。** 常量折叠那一例：7.3 上整个文件编译不过（连 `echo` 都不执行），8.4 上是运行时 `TypeError`。升级后一些原本「一部署就发现」的问题会变成「跑到那行才炸」，反过来也有些原本「静默算错」的问题变成「立刻抛异常」。这两种方向都要在灰度里盯。

**4. `assert("...")` 从 8.0 起静默恒真。** 7.3/7.4 上它是真在断言（字符串会被求值，`assert("false")` 实测报 warning），8.0 起字符串断言不再求值，`assert("$a == 1")` 变成非空字符串=真。**代码里所有字符串形式的 assert 从此都是摆设，且不会有任何提示**——这是升级里最容易全员漏掉的一条。

**5. 弃用高峰在 7.4 和 8.1，不在 8.0。** 实测表里 8.1 一栏出现 `Deprecated` 的有 5 项（null 传内部函数、浮点数组键、`Serializable`、`strftime`、以及 7.4 就已经开始的 `$s{0}`）；8.2 又加了 4 项（动态属性、`${var}`、`${expr}`、`utf8_encode`）。而 8.0 那一栏几乎全是「直接变 Error」。所以「先升 7.4 把弃用清干净，再升 8.x」不是官僚流程，是在把「漏水」和「打碎」拆成两步。

**6. 7.3/7.4 上 `#` 还是行注释，所以属性写法在同一行会静默吃掉类声明。** 实测 `#[AllowDynamicProperties] class C4b {}` 写在 7.3 上：无输出、无报错、退出码 0（类压根没声明）；属性单独占一行则 7 个版本全部正常。双版本兼容期出现「同一个文件在两个版本上行为不同且不报错」，就是这种语法级差异造成的。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 升级路线 | `7.3 → 7.4 → 8.0 → … → 8.4` 逐版走。**7.4 那一站专门用来清弃用**（实测弃用高峰就在 7.4/8.1/8.2），8.0 那一站专门用来处理错误模型 |
| 找「会炸」的点 | 先扫四类：未定义常量/变量、除零、内部函数参数（null/类型/参数个数）、松比较（`==` / `in_array` / `switch` / `array_search`）。前四类实测在 8.4 全部变异常或改写语义 |
| 找「会漏水」的点 | 开 `error_reporting=E_ALL` 跑全量测试，把 `Deprecated` 当失败处理；弃用清单按本页实测表逐项核，别背文档 |
| 静态扫描 | PHPStan / Rector（`php74` → `php80`/`php81`/`php82` 规则集）能覆盖绝大部分错误模型与弃用改写；**这一条我没有实测加速比或命中率，属于经验做法** |
| 兼容期 CI | 同一套测试在两个 PHP 版本上跑（本环境可以用 `php:7.4-cli` / `php:8.2-cli` / 8.4 三个镜像），断言结果**逐字节相同**，别只断言「不报错」 |
| 排序 | 8.0 起排序稳定（实测 7.3 `aisqomkgechjblfnpdrt` vs 8.4 `acegikmoqsbdfhjlnprt`）。升级后同值元素的次序会变，分页/去重逻辑要重新验证 |
| 序列化 | `Serializable` 从 8.1 起弃用，改 `__serialize()` / `__unserialize()`。缓存里存过的老数据要保留反序列化兼容分支 |
| `assert` | 8.0 起字符串断言恒真（实测无告警）。测试代码里的 `assert("...")` 必须改成布尔表达式或 PHPUnit 断言，否则等于没测 |
| 动态属性 | 8.2 起弃用。要么给类加 `#[\AllowDynamicProperties]`（实测有效），要么用 `#[\\AllowDynamicProperties]` 之外的正当做法：声明属性、或改用 `ArrayObject`/`stdClass` 承载动态字段 |
| 内存 | 只有顺序整数键的列表省一半（实测 33.6 → 16.8 B/元素）；哈希形态的数组（41.9/73.9 B/元素）不变。降配前先按真实形态测 |
| 常驻进程 / Swoole | 优先升级到 8.3+，`zend.max_allowed_stack_size` 让无限递归从「吃光内存/段错误」变成一条能定位的 fatal（实测 8.4 报栈上限 8,339,456 B，7.3 只是内存耗尽） |
| 阈值/超时类配置 | 纯 PHP 循环快约 1.3 倍（实测取模/数组/拼接 1.29~1.33×），`json_*`、`md5` 这类 C 函数无可测差异。**不要按「PHP 8 快 2 倍」去下调超时**（那是 JIT 宣传口径，本环境 CLI 下 opcache 未启用，JIT 加速比未实测） |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 8.0 最重要的一个变化是什么？ | 错误模型换代。「以前只是 Warning、现在抛异常」决定了升级工作量，也决定了灰度时会不会出事故；新语法反而是最容易适应的部分 |
| 8.1 有什么？ | `readonly` 属性、`enum`、`never`、`new` 出现在参数默认值、纯交集类型 `A&B`、一等公民可调用 `f(...)`、`array_is_list`/`enum_exists`——全部实测 8.1 起可用、8.0 上不可用 |
| 8.2 有什么？ | `readonly class`、trait 常量、独立 `null`/`false`/`true` 类型、**DNF 类型 `(A&B)\|C`**、`Random\Randomizer`、`#[AllowDynamicProperties]`、`#[SensitiveParameter]`；同时是弃用大版（动态属性、`${var}`、`utf8_encode`） |
| 8.3 有什么？ | 类型化类常量、`json_validate`、`mb_str_pad`、`#[Override]`、`zend.max_allowed_stack_size`。都是「小但顺手」的改动，升级风险最低 |
| 8.4 有什么？ | property hooks、非对称可见性 `private(set)`、`new` 不带括号、`array_find`/`array_any`/`array_all`、`#[\Deprecated]`、`mb_ucfirst`；同时弃用隐式可空参数 `int $x = null`（实测 8.4 才报） |
| 枚举到底解决了什么？ | 把「一组常量」变成类型：参数/返回值可以声明 `Suit`，传错立刻 `TypeError`，还能挂方法实现（`match($this)`）。对比 `const` 数组是「有类型检查」和「没有」的区别 |
| `readonly` 和不可变是什么关系？ | `readonly` 保证「初始化一次后不可改」，不是深不可变：`readonly` 属性里装的对象，其内部状态照样能改（`readonly class` 也只是逐属性 readonly）。真要不可变得靠值对象 + 不暴露可变引用 |
| property hooks 能替代 getter/setter 吗？ | 能替代大部分「只为加一层转换/校验」的 getter/setter（接口也能声明 hooked property，实测 8.4 支持；裸属性仍 Fatal），但不适合放重逻辑或 I/O——它在属性访问语法里执行，可读性上「看不出来会干活」 |
| 升级要改多少代码？ | 没有普适数字，得靠静态扫描+测试跑出来。**本环境未实测真实项目的改动量**；能说清的只有「哪一类会炸」（见上表：错误模型 4 类 + 弃用 10 项） |
| 松比较翻转会不会影响我？ | 只要代码里有 `==` / `!=` / `in_array` / `array_search` / `switch` / `sort` 与非字符串、非数字混用，就有可能。Q5 里列了完整的实测对照；`in_array($x, $arr, true)` 是最便宜的修法 |
| 怎么保证升级不出事？ | 双版本 CI（同一套断言在 7.4/8.2/8.4 上逐字节一致）+ `E_ALL` 下把 Deprecated 当失败 + 灰度期间盯「异常类型分布」的变化（`TypeError`/`DivisionByZeroError`/`Error` 的数量）。`assert` 和松比较这两类静默问题，只能靠代码扫描，不会在日志里露头 |
| PHP 8 的收益到底有多少？ | 实测纯 PHP 循环约 1.3×；顺序整数键数组内存精确减半（16.8 vs 33.6 B/元素）；`json_*`/`md5` 无差异。**JIT 的收益未实测**（CLI 下 opcache 未启用），Web 场景的讨论见 Q3 |
